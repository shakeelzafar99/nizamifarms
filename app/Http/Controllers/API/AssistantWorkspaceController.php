<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * NF Assistant — the money workspace (Phase 2 of
 * NF-MESSAGES-ENHANCEMENTS-PLAN-JUL2026): the status strip + the money inbox.
 *
 * Everything here is PLAIN DB — no LLM call — so the strip is free to refresh
 * on every tab open (owner's standing "don't waste credits" rule). Three
 * numbers Taimur actually cares about:
 *   • to_sort    — bank debits waiting to be recorded (t_ai_bank_sms; 0 until Phase 4)
 *   • done_today — what he confirmed today, count + rupees (t_ai_drafts)
 *   • matched    — customer credits matched to approvals (0 until Phase 5)
 *
 * The inbox sections light up as later phases feed their tables; today only
 * "done today" has data, which is why the screen still reads as complete.
 */
class AssistantWorkspaceController extends Controller
{
    /** GET /api/assistant/workspace/summary — the three strip counts. */
    public function summary(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $this->reclassifyUnknownDirections((int) $user->id);
        $this->revertOrphanedSms((int) $user->id);
        $this->closeCorroboratedSms((int) $user->id);
        $this->reconcileRecordedDebits((int) $user->id);
        // Give held bank credits another look at the invoice that had not yet
        // been raised when they arrived (invoices appear at DELIVERY, ~21h
        // after the order on average, while customers pay at delivery). Runs
        // AFTER this response is sent and is throttled site-wide, so opening
        // the inbox never waits on it.
        \App\Services\Payments\Signals\HeldCreditResweeper::scheduleAfterResponse();
        $done = $this->doneToday((int) $user->id);

        // The strip now mirrors the two inbox boxes exactly: how much money OUT
        // and how much money IN is still waiting on a human, plus one
        // backward-looking "handled" tile. The old trio counted "to sort"
        // (both directions mashed into one number), "done" (money out recorded)
        // and "matched" (money in) — three tiles that answered no single
        // question. Legacy keys stay for the live APK.
        $cards   = $this->pendingCards((int) $user->id);
        $isOut   = fn($t) => in_array($t, ['expense', 'vendor_payment', 'vendor_purchase', 'account_transfer'], true);
        $outWait = count($this->toSortList((int) $user->id))
                 + count(array_filter($cards, fn($c) => $isOut($c['type'])));
        $inWait  = count(array_filter($this->matchedList((int) $user->id), fn($m) => !$m['matched']))
                 + count(array_filter($cards, fn($c) => !$isOut($c['type'])));

        return response()->json([
            'success'    => true,
            // ── new shape ──
            'money_out'  => ['count' => $outWait],
            'money_in'   => ['count' => $inWait],
            'handled'    => [
                'count'  => count($done)
                          + count($this->autoHandledToday((int) $user->id, 'out'))
                          + count($this->autoHandledToday((int) $user->id, 'in')),
                'amount' => round(array_sum(array_column($done, 'amount')), 0),
            ],
            // ── legacy keys: the SHIPPED APK reads these, so they must survive
            //    until it is rebuilt. Same numbers as before. ──
            'to_sort'    => ['count' => $this->toSortCount((int) $user->id)],
            'done_today' => [
                'count'  => count($done),
                'amount' => round(array_sum(array_column($done, 'amount')), 0),
            ],
            'matched'       => ['count' => $this->matchedCount((int) $user->id)],
            'pending_cards' => ['count' => count($cards)],
        ]);
    }

    /** GET /api/assistant/workspace/inbox — the full inbox: to-sort, matched, done-today. */
    public function inbox(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $this->reclassifyUnknownDirections((int) $user->id);
        $this->revertOrphanedSms((int) $user->id);
        $this->closeCorroboratedSms((int) $user->id);
        $this->reconcileRecordedDebits((int) $user->id);
        // Give held bank credits another look at the invoice that had not yet
        // been raised when they arrived (invoices appear at DELIVERY, ~21h
        // after the order on average, while customers pay at delivery). Runs
        // AFTER this response is sent and is throttled site-wide, so opening
        // the inbox never waits on it.
        \App\Services\Payments\Signals\HeldCreditResweeper::scheduleAfterResponse();
        $done = $this->doneToday((int) $user->id);

        return response()->json([
            'success'     => true,
            'today_total' => round(array_sum(array_column($done, 'amount')), 0),
            'to_sort'     => $this->toSortList((int) $user->id),
            'matched'     => $this->matchedList((int) $user->id),  // empty until Phase 5
            'done_today'  => $done,
            // Cards awaiting his Confirm — the "review later" surface (owner
            // ask): SMS/proof/chat cards he didn't confirm in the moment.
            'pending_cards' => $this->pendingCards((int) $user->id),
            // Pickers the inbox action buttons need, so tapping [Record expense]
            // / teaching a bank needs no extra round-trip.
            'banks'              => $this->banks(),
            'expense_categories' => $this->recentCategories(),
            // Remembered personal merchants auto-ignored today (audit row).
            'auto_ignored'       => $this->autoIgnoredToday((int) $user->id),
            // What the sweeps closed on their own today, per direction — shown
            // inside each box's "Handled today" strip, each row with a Restore.
            'auto_out'           => $this->autoHandledToday((int) $user->id, 'out'),
            'auto_in'            => $this->autoHandledToday((int) $user->id, 'in'),
        ]);
    }

    /**
     * GET /api/assistant/workspace/vendor-search?q= — for the inbox's
     * [Record payment] vendor picker. Space-insensitive, active vendors only.
     */
    public function vendorSearch(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'vendors' => []]);
        }
        $norm = mb_strtolower(str_replace(' ', '', $q));

        $vendors = DB::table('t_fin_vendors as v')
            ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'v.account_id')
            ->where('v.is_active', 1)
            ->where(function ($w) use ($q, $norm) {
                $w->where('v.vendor_name', 'like', '%' . $q . '%')
                  ->orWhereRaw("LOWER(REPLACE(v.vendor_name, ' ', '')) LIKE ?", ['%' . $norm . '%']);
            })
            ->orderBy('v.vendor_name')
            ->limit(12)
            ->get(['v.id', 'v.vendor_name', 'a.current_balance'])
            ->map(fn($v) => [
                'id'      => $v->id,
                'name'    => $v->vendor_name,
                'owed'    => round((float) ($v->current_balance ?? 0), 0),
            ])->all();

        return response()->json(['success' => true, 'vendors' => $vendors]);
    }

    /**
     * GET /api/assistant/workspace/account-search?q= — for a money-out SMS that
     * was neither an expense nor a vendor payment but a MOVE of our own money
     * (a rider/staff cash float, NF food, another till).
     *
     * Same three groups the Ledger Hub's transfer picker offers, minus BANK
     * accounts: the money is already leaving our bank, so a bank destination
     * would be a bank-to-bank move — that has its own ⇄ tool in the Hub and
     * must not be modelled as an SMS-driven transfer.
     */
    public function accountSearch(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $q = trim((string) $request->input('q', ''));
        $groups = [
            \App\Models\FIN\AccountModel::CATEGORY_CASH           => 'Company cash',
            \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH  => 'Rider & staff cash',
        ];

        $rows = \App\Models\FIN\AccountModel::where('is_active', 1)
            ->whereIn('account_category', array_keys($groups))
            ->visibleTo($user)
            ->when($q !== '', function ($w) use ($q) {
                $norm = mb_strtolower(str_replace(' ', '', $q));
                $w->where(function ($x) use ($q, $norm) {
                    $x->where('account_name', 'like', '%' . $q . '%')
                      ->orWhereRaw("LOWER(REPLACE(account_name, ' ', '')) LIKE ?", ['%' . $norm . '%'])
                      ->orWhere('account_code', 'like', '%' . $q . '%');
                });
            })
            ->orderBy('account_name')
            ->limit(20)
            ->get(['id', 'account_name', 'account_code', 'account_category']);

        return response()->json([
            'success'  => true,
            'accounts' => $rows->map(fn($a) => [
                'id'    => (int) $a->id,
                'name'  => $a->account_name,
                'group' => $groups[$a->account_category] ?? 'Other',
            ])->all(),
        ]);
    }

    /**
     * GET /api/assistant/workspace/customer-search?q= — for matching a bank
     * CREDIT SMS to the customer who paid. Only customers with something in the
     * Online Approvals queue are useful, so each row carries that count.
     */
    public function customerSearch(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'customers' => []]);
        }
        $norm = mb_strtolower(str_replace(' ', '', $q));

        $rows = DB::table('t_crm_prod_customer')
            ->where(function ($w) use ($q, $norm) {
                $w->whereRaw("LOWER(REPLACE(CONCAT(COALESCE(first_name,''),COALESCE(last_name,'')),' ','')) LIKE ?", ['%' . $norm . '%'])
                  ->orWhere('first_name', 'like', '%' . $q . '%')
                  ->orWhere('last_name', 'like', '%' . $q . '%')
                  ->orWhere('phone_original', 'like', '%' . $q . '%');
            })
            ->limit(12)
            ->get(['id', 'first_name', 'last_name']);

        return response()->json([
            'success' => true,
            'customers' => $rows->map(fn($c) => [
                'id' => $c->id,
                'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                'open_orders' => $this->approvalsQueueCount((int) $c->id),
            ])->all(),
        ]);
    }

    /**
     * GET /assistant/money-moves — the movement log: every payment signal that
     * changed hands (attached, re-pointed, released), newest first. This is the
     * owner's window into self-healing: a system allowed to correct itself must
     * show its corrections. Read-only.
     */
    public function moneyMoves(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('t_fin_payment_signal_moves')) {
            return response()->json(['success' => true, 'moves' => [],
                'note' => 'Movement log not installed yet (run the signal-moves SQL).']);
        }

        $days = max(1, min(90, (int) $request->input('days', 14)));
        $raw = DB::table('t_fin_payment_signal_moves')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->limit(2000)
            ->get();

        // ⭐ COLLAPSE TO NET MOVEMENT. A single heal is written as its honest
        // steps (released from B → placed on A), and a legitimate re-match of a
        // settled pair (the balance-discount flow re-runs MATCHED signals)
        // writes a detach + reattach that nets to NOTHING. The raw table keeps
        // every step as the forensic truth; this view answers the owner's
        // actual question — "what changed hands" — so steps within one burst
        // (same signal, ≤2 min apart) merge, and bursts that end exactly where
        // they began are dropped. Without this, routine re-matching would read
        // as payments constantly changing hands when nothing moved at all.
        $chains = [];
        foreach ($raw->groupBy('signal_id') as $signalId => $rows) {
            $chain = [];
            foreach ($rows as $r) {
                if ($chain && strtotime($r->created_at) - strtotime(end($chain)->created_at) > 120) {
                    $chains[] = $chain;
                    $chain = [];
                }
                $chain[] = $r;
            }
            if ($chain) {
                $chains[] = $chain;
            }
        }

        $net = [];
        foreach ($chains as $chain) {
            $first = $chain[0];
            $last  = end($chain);
            if ((int) $first->from_order_id === (int) $last->to_order_id
                && (int) $first->from_customer_id === (int) $last->to_customer_id) {
                continue; // round trip — nothing actually moved
            }
            $by = collect($chain)->pluck('moved_by')->filter()->first();
            $net[] = (object) [
                'id' => $last->id, 'signal_id' => $first->signal_id,
                'source' => $first->source, 'amount' => $first->amount,
                'payer_name' => $first->payer_name,
                'from_customer_id' => $first->from_customer_id, 'from_order_id' => $first->from_order_id,
                'to_customer_id' => $last->to_customer_id, 'to_order_id' => $last->to_order_id,
                'from_reason' => $first->from_reason, 'to_reason' => $last->to_reason,
                'moved_by' => $by, 'created_at' => $last->created_at,
            ];
        }
        $net = collect($net)->sortByDesc('id')->take(100)->values();

        // Resolve names in bulk for what survived the collapse.
        $custIds  = $net->flatMap(fn($m) => [$m->from_customer_id, $m->to_customer_id])->filter()->unique();
        $orderIds = $net->flatMap(fn($m) => [$m->from_order_id, $m->to_order_id])->filter()->unique();
        $custs  = DB::table('t_crm_prod_customer')->whereIn('id', $custIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id')->map(fn($c) => trim($c->first_name . ' ' . ($c->last_name ?? '')));
        $orders = DB::table('t_crm_prod_order')->whereIn('id', $orderIds)
            ->pluck('order_number', 'id');
        $users  = DB::table('t_sys_user')->whereIn('id', $net->pluck('moved_by')->filter()->unique())
            ->pluck('fullname', 'id');

        return response()->json([
            'success' => true,
            'moves' => $net->map(fn($m) => [
                'id'          => (int) $m->id,
                'signal_id'   => (int) $m->signal_id,
                'source'      => $m->source,
                'amount'      => $m->amount !== null ? round((float) $m->amount, 0) : null,
                'payer'       => $m->payer_name,
                // From → to, in words a human can act on. Null side = held.
                'from'        => $orders[$m->from_order_id] ?? ($custs[$m->from_customer_id] ?? null),
                'to'          => $orders[$m->to_order_id] ?? ($custs[$m->to_customer_id] ?? null),
                'from_reason' => $m->from_reason,
                'to_reason'   => $m->to_reason,
                // A named human = a manual re-point; 'system' = a self-heal.
                'by'          => $users[$m->moved_by] ?? 'system',
                'at'          => (string) $m->created_at,
            ])->all(),
        ]);
    }

    /** How many of this customer's invoices are still awaiting approval. */
    private function approvalsQueueCount(int $customerId): int
    {
        return (int) DB::table('t_fin_ledger as l')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
            ->where('o.customer_id', $customerId)
            ->where('l.transaction_type', 'invoice')
            ->whereIn('l.approval_status', ['pending', 'pending_l1', 'pending_l2'])
            ->whereRaw('(o.total_price - COALESCE(o.total_paid,0)) > 0.01')
            ->distinct()->count('l.order_id');
    }

    private function banks(): array
    {
        return DB::table('t_fin_online_receiving_accounts')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($b) => ['id' => (int) $b->id, 'name' => $b->name])
            ->all();
    }

    /** Recent/most-used expense categories — quick chips for the expense picker. */
    private function recentCategories(): array
    {
        return DB::table('t_req_master')
            ->whereNotNull('expense_category')
            ->where('expense_category', '!=', '')
            ->select('expense_category', DB::raw('COUNT(*) c'))
            ->groupBy('expense_category')
            ->orderByDesc('c')
            ->limit(14)
            ->pluck('expense_category')
            ->all();
    }

    // ── done today (from confirmed drafts) ─────────────────────────────────────

    /**
     * What was actually recorded today: confirmed drafts, newest first, with the
     * rupee amount pulled from the stored payload. This is the ONE surface that
     * has real data in Phase 2.
     */
    private function doneToday(int $userId): array
    {
        $rows = DB::table('t_ai_drafts')
            ->where('user_id', $userId)
            ->where('status', 'confirmed')
            ->whereDate('confirmed_at', now()->toDateString())
            ->orderByDesc('confirmed_at')
            ->get(['type', 'summary', 'payload_json', 'confirmed_at']);

        return $rows->map(function ($d) {
            $payload = json_decode($d->payload_json, true) ?: [];
            return [
                'type'   => $d->type,                                   // expense | vendor_payment | vendor_purchase
                'label'  => $d->summary,
                'amount' => round((float) ($payload['amount'] ?? 0), 0),
                'time'   => $d->confirmed_at ? substr((string) $d->confirmed_at, 11, 5) : null,
            ];
        })->all();
    }

    // ── to-sort (bank debits; Phase 4 fills the table) ─────────────────────────

    private function toSortCount(int $userId): int
    {
        // EVERY unhandled SMS needs the human — a debit/unknown to record or
        // dismiss, and (since 5b) a credit to match or dismiss. So the strip's
        // "to sort" = unhandled SMS across BOTH inbox sections (OUTGOING debits
        // + INCOMING unhandled credits). Counting only debits left waiting
        // credits at a lying 0.
        // ('needs_sender' is legacy: since the registered-only gate, unknown
        //  senders are never stored, so only 'new' remains — but we still
        //  exclude any pre-gate needs_sender rows so old clutter doesn't count.)
        return (int) DB::table('t_ai_bank_sms')
            ->where('user_id', $userId)
            ->where('status', 'new')
            ->count();
    }

    private function toSortList(int $userId): array
    {
        return DB::table('t_ai_bank_sms as s')
            ->leftJoin('t_fin_online_receiving_accounts as b', 'b.id', '=', 's.receiving_account_id')
            ->where('s.user_id', $userId)
            ->whereIn('s.direction', ['debit', 'unknown'])
            ->where('s.status', 'new')
            ->orderByDesc('s.sms_at')
            ->get(['s.id', 's.sender_id', 's.direction', 's.amount', 's.counterparty', 's.counterparty_account',
                   's.reference', 's.status', 's.sms_at', 'b.name as bank_name'])
            ->map(function ($s) {
                // Counterparty memory: a saved vendor/expense rule pre-fills the
                // card ("Looks like: Karachi Feeds") — one tap instead of a search.
                // Account hits are strong; name-only hits are suggestions anyway
                // because nothing here auto-posts (Confirm still required).
                $rule = app(\App\Services\Assistant\SmsCounterpartyMap::class)->forSms($s);
                $suggestion = null;
                if ($rule && in_array($rule->entity_type, ['vendor', 'expense', 'account'], true)) {
                    $suggestion = [
                        'type'      => $rule->entity_type,
                        'entity_id' => $rule->entity_id ? (int) $rule->entity_id : null,
                        'label'     => app(\App\Services\Assistant\SmsCounterpartyMap::class)->entityName($rule),
                    ];
                }
                return [
                    'id'           => $s->id,
                    'bank_name'    => $s->bank_name,                 // null → status 'needs_sender' (teach the sender)
                    'sender_id'    => $s->sender_id,
                    // The UI splits "recognized" from "needs tagging" and labels
                    // an unclear direction — both were reading s.direction, which
                    // this list never actually sent (so the label never showed).
                    'direction'    => $s->direction,
                    'amount'       => round((float) $s->amount, 0),
                    'counterparty' => $s->counterparty,
                    // The identity key the map remembers. Present ⇒ this row can
                    // be tagged to a vendor/expense for good; absent (e.g. an HBL
                    // debit with no account in the text) ⇒ one-off only, so the
                    // UI must not offer a "remember" that could never fire.
                    'account_key'  => $s->counterparty_account,
                    'needs_sender' => $s->status === 'needs_sender',
                    'time'         => $s->sms_at ? substr((string) $s->sms_at, 11, 5) : null,
                    // ⚠ The DATE is not decoration. Two of Taimur's rows read
                    // "I.SAEED · Rs 100,000" and differed ONLY by day (Jul-25 vs
                    // Jul-27); with just a time on the card they looked like one
                    // duplicate, which is how the same payment gets recorded twice.
                    'date'         => $s->sms_at ? $this->shortDate((string) $s->sms_at) : null,
                    'reference'    => $s->reference,
                    'suggestion'   => $suggestion,
                    // See matchedList: stale rows fold into a collapsed "Older"
                    // strip rather than crowding today's decisions.
                    'age_days'     => $s->sms_at
                        ? \Illuminate\Support\Carbon::parse($s->sms_at)->startOfDay()->diffInDays(now()->startOfDay())
                        : 0,
                ];
            })->all();
    }

    // ── matched (customer credits; Phase 5 fills this) ─────────────────────────

    private function matchedCount(int $userId): int
    {
        return (int) DB::table('t_ai_bank_sms')
            ->where('user_id', $userId)
            ->where('status', 'matched')
            ->whereDate('updated_at', now()->toDateString())
            ->count();
    }

    private function matchedList(int $userId): array
    {
        // Unhandled credits ('new') show regardless of age; already-matched
        // ones only for TODAY, so the section doesn't accumulate stale history.
        // (Pre-gate 'needs_sender' rows are intentionally excluded now that
        //  unregistered senders are never stored — see the registered-only gate
        //  in AssistantSmsController::ingest.)
        return DB::table('t_ai_bank_sms')
            ->where('user_id', $userId)
            ->where('direction', 'credit')
            ->where(function ($w) {
                $w->where('status', 'new')
                  ->orWhere(function ($w2) {
                      $w2->where('status', 'matched')
                         ->whereDate('updated_at', now()->toDateString());
                  });
            })
            ->orderByDesc('sms_at')
            ->get(['id', 'sender_id', 'amount', 'counterparty', 'counterparty_account', 'status',
                   'auto_reason', 'sms_at', 'linked_signal_id'])
            ->map(function ($s) {
                // ⭐ AN SMS WHOSE OWN SIGNAL IS ALREADY ATTACHED TO AN ORDER is
                // not a question either. The amount-unique attach at ingest put
                // a blue "Bank confirmed — confirm the payer" on that order in
                // Online Approvals; asking "who paid?" here as well is the SAME
                // ask on two screens, and whichever side answers first resolves
                // both (approval closes this SMS via the sweep). Shown as
                // settled with basis 'amount' — labelled honestly from the
                // proof service ("Bank confirmed", never "Verified").
                $attached = $s->status === 'new' ? $this->attachedMatch($s) : null;
                // ⭐ FIRST: has this money ALREADY been dealt with by the proof
                // pipeline? A credit whose one amount-matching order already
                // carries a screenshot/bank proof is not a question — it is a
                // FACT awaiting approval. Asking "who paid?" there (which is
                // what this list used to do) makes the assistant look like it
                // knows less than it does, and invites a duplicate answer.
                // Note the asymmetry this repairs: the AUTO-attach deliberately
                // refuses an amount-only attach onto a proofed order, but the
                // suggestion chip never checked proof status — so the row asked
                // a question the system had already answered.
                $settled = $attached ?: ($s->status === 'new' ? $this->proofBackedMatch($s) : null);
                // Counterparty memory FIRST: a mapped account → that customer,
                // pre-filled with certainty. Falls back to the name-based
                // suggestion (unique customer with something in approvals).
                $suggested = null;
                $suggestedOrder = null;
                if ($s->status === 'new' && !$settled) {
                    $rule = app(\App\Services\Assistant\SmsCounterpartyMap::class)->byAccount($s->counterparty_account);
                    if ($rule && $rule->entity_type === 'customer' && $rule->entity_id) {
                        $suggested = [
                            'id'   => (int) $rule->entity_id,
                            'name' => app(\App\Services\Assistant\SmsCounterpartyMap::class)->entityName($rule),
                        ];
                    }
                    $suggested = $suggested ?: $this->suggestCustomer($s->counterparty);

                    // LAST resort for an identity-less SMS (e.g. an HBL credit
                    // with no sender account, and a name that doesn't uniquely
                    // resolve): if EXACTLY ONE order in the whole approvals queue
                    // has a balance equal to this amount, offer it as a LOW-
                    // confidence one-tap. Never auto — Taimur confirms. Nothing
                    // is offered if zero or several orders share the amount (that
                    // ambiguity is exactly where a wrong guess would hurt).
                    if (!$suggested) {
                        $suggestedOrder = $this->suggestOrderByAmount((float) $s->amount);
                    }
                }
                // A truly open row with NO lead at all still deserves whatever
                // context exists: the payer NAME may resolve to one customer who
                // simply has nothing pending (their order already approved, or
                // the amounts differ). Stated as information, never as a chip —
                // e.g. Warda paid 1,800 against an approved 1,712 order: only a
                // human can say if that is the same money plus a tip, or new.
                $hint = (!$settled && !$suggested && !$suggestedOrder && $s->status === 'new')
                    ? $this->nameHint($s->counterparty)
                    : null;
                return [
                    'id'           => $s->id,
                    'sender_id'    => $s->sender_id,
                    'amount'       => round((float) $s->amount, 0),
                    'counterparty' => $s->counterparty,
                    'date'         => $s->sms_at ? $this->shortDate((string) $s->sms_at) : null,
                    'matched'      => $s->status === 'matched',
                    'needs_sender' => $s->status === 'needs_sender',
                    // 'mapped_customer' / 'proof_pair' → this credit was handled
                    // with NO tap (auto-verified / auto-attached). Shown as ⚡.
                    'auto'         => $s->auto_reason,
                    'time'         => $s->sms_at ? substr((string) $s->sms_at, 11, 5) : null,
                    'suggested_customer' => $suggested,
                    // A single amount-matching approvals order (low confidence).
                    'suggested_order'    => $suggestedOrder,
                    // Which of the three money-in states this row is in:
                    //   done     — already closed (shown under Handled)
                    //   settled  — proof-backed or already-attached, nothing to decide
                    //   action   — genuinely needs a human
                    'state'        => $s->status === 'matched' ? 'done' : ($settled ? 'settled' : 'action'),
                    // Informational context for an otherwise lead-less row.
                    'name_hint'    => $hint,
                    // The other half of the story for a settled row: which order,
                    // whose, and how it was proven. Answering "matched with what?"
                    // is the whole point of showing it.
                    'settled_with' => $settled,
                    // Days since the SMS landed — the UI folds anything stale into
                    // a collapsed "Older" strip so a week-old row cannot dominate
                    // today's work while still never being deleted.
                    'age_days'     => $s->sms_at
                        ? \Illuminate\Support\Carbon::parse($s->sms_at)->startOfDay()->diffInDays(now()->startOfDay())
                        : 0,
                ];
            })->all();
    }

    /**
     * For a credit amount: the ONE approvals-queue order it matches, but only
     * when that order already carries a payment proof (screenshot / bank email
     * / bank SMS). Returns the order + the proof status so the row can state
     * the match instead of asking about it — or null, meaning this really is
     * unidentified money and belongs in the action list.
     *
     * Proof status comes from PaymentProofStatusService — the SAME service that
     * paints the badge on Online Approvals and the orders panel — so the
     * assistant and those screens can never disagree about a payment.
     * `suppressSettled: false` because we want the true proof state as a FACT,
     * not the "should I still nag?" view that blanks once approved.
     */
    /**
     * The SMS's OWN signal is already attached to an approvals-queue order (the
     * amount-unique attach at ingest). That attach is what the approver sees as
     * the blue "Bank confirmed" chip, so the assistant reports it instead of
     * re-asking. Distinct from proofBackedMatch: no independence requirement,
     * because nothing is being *claimed* — the attach already happened and is
     * already on a human's screen. basis:'amount' keeps the UI honest about
     * how the match was made.
     */
    private function attachedMatch(object $sms): ?array
    {
        if (empty($sms->linked_signal_id)) {
            return null;
        }
        try {
            $sig = \App\Models\FIN\PaymentSignal::find($sms->linked_signal_id);
            if (!$sig || !$sig->matched_order_id
                || !in_array($sig->status, [
                    \App\Models\FIN\PaymentSignal::STATUS_MATCHED,
                    \App\Models\FIN\PaymentSignal::STATUS_AMOUNT_MISMATCH,
                ], true)) {
                return null; // held/unattached signal — not a decision yet
            }
            $o = DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.id', $sig->matched_order_id)
                ->first(['o.id', 'o.order_number', 'o.total_price', 'o.customer_id', 'c.first_name', 'c.last_name']);
            if (!$o) {
                return null;
            }
            $st = app(\App\Services\Payments\Signals\PaymentProofStatusService::class)
                ->forOrder((int) $o->id, false);

            return [
                'order_id'      => (int) $o->id,
                'order_number'  => $o->order_number,
                'customer_id'   => (int) $o->customer_id,
                'customer_name' => trim(($o->first_name ?? '') . ' ' . ($o->last_name ?? '')),
                'order_total'   => round((float) $o->total_price, 0),
                'proof_status'  => $st['status'] ?? null,
                'proof_label'   => $st['label'] ?? 'Bank confirmed',
                'proof_color'   => $st['color'] ?? '#2563EB',
                'basis'         => 'amount',
            ];
        } catch (\Throwable $e) {
            return null; // fail toward the action list — never hide money on error
        }
    }

    /**
     * Context for a lead-less credit: the payer name resolves to exactly ONE
     * customer, but they have nothing in the approvals queue — usually because
     * their order was ALREADY approved (possibly with a different amount, like
     * Warda's Rs 1,800 against an approved Rs 1,712). Reported as a sentence,
     * never as a one-tap: only a human can say whether it is the same money.
     */
    private function nameHint(?string $counterparty): ?array
    {
        try {
            $name = trim((string) $counterparty);
            if (mb_strlen($name) < 3) {
                return null;
            }
            $norm = mb_strtolower(str_replace(' ', '', $name));
            $hits = DB::table('t_crm_prod_customer')
                ->whereRaw("LOWER(REPLACE(CONCAT(COALESCE(first_name,''),COALESCE(last_name,'')),' ','')) LIKE ?", ['%' . $norm . '%'])
                ->limit(2)
                ->get(['id', 'first_name', 'last_name']);
            if ($hits->count() !== 1) {
                return null;
            }
            $c = $hits->first();
            if ($this->approvalsQueueCount((int) $c->id) > 0) {
                return null; // then suggestCustomer already offered the real chip
            }

            // Their most recent APPROVED invoice within two weeks — the likely
            // explanation for why nothing is pending.
            $last = DB::table('t_fin_ledger as l')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->where('o.customer_id', $c->id)
                ->where('l.transaction_type', 'invoice')
                ->where('l.approval_status', 'approved')
                ->where('l.approval_date', '>=', now()->subDays(14))
                ->orderByDesc('l.approval_date')
                ->first(['o.order_number', 'o.total_price', 'l.approval_date']);

            return [
                'customer_id'   => (int) $c->id,
                'customer_name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                'last_order'    => $last ? [
                    'order_number' => $last->order_number,
                    'amount'       => round((float) $last->total_price, 0),
                    'approved_on'  => $this->shortDate((string) $last->approval_date),
                ] : null,
            ];
        } catch (\Throwable $e) {
            return null; // decoration only
        }
    }

    private function proofBackedMatch(object $sms): ?array
    {
        $u = $this->suggestOrderByAmount((float) $sms->amount);
        if (!$u) {
            return null; // zero or several orders at this amount — still a question
        }

        // ⚠ The evidence must be INDEPENDENT of this SMS. An unidentified credit
        // gets attached to its lone amount-match by `amount_unique_sms`, which
        // then makes the order read "Bank confirmed" — so trusting the badge
        // blindly would let the SMS cite ITSELF as proof it is settled, and a
        // pure amount guess would be dressed up as a fact. Require a signal that
        // is neither this SMS's own nor another amount-only attach.
        try {
            $independent = \App\Models\FIN\PaymentSignal::query()
                ->where('matched_order_id', (int) $u['order_id'])
                ->whereIn('status', [
                    \App\Models\FIN\PaymentSignal::STATUS_MATCHED,
                    \App\Models\FIN\PaymentSignal::STATUS_AMOUNT_MISMATCH,
                ])
                ->when($sms->linked_signal_id, fn($q) => $q->where('id', '<>', $sms->linked_signal_id))
                // Independent means NOT inferred — every guess reason is
                // excluded here, not just the original amount-only one. (A NULL
                // reason still counts as independent, as it always has.)
                ->where(fn($q) => $q->whereNull('match_reason')
                    ->orWhereNotIn('match_reason', \App\Models\FIN\PaymentSignal::GUESS_REASONS))
                ->exists();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$independent) {
            return null; // only our own guess backs it — that stays a question
        }

        try {
            $st = app(\App\Services\Payments\Signals\PaymentProofStatusService::class)
                ->forOrder((int) $u['order_id'], false);
        } catch (\Throwable $e) {
            return null; // never let a decoration lookup cost the row its actions
        }
        $status = $st['status'] ?? \App\Services\Payments\Signals\PaymentProofStatusService::NONE;
        if ($status === \App\Services\Payments\Signals\PaymentProofStatusService::NONE) {
            return null; // an amount match with no proof behind it stays a question
        }

        return [
            'order_id'      => $u['order_id'],
            'order_number'  => $u['order_number'],
            'customer_id'   => $u['customer_id'],
            'customer_name' => $u['customer_name'],
            'order_total'   => round((float) (DB::table('t_crm_prod_order')
                                ->where('id', $u['order_id'])->value('total_price') ?? 0), 0),
            'proof_status'  => $status,
            'proof_label'   => $st['label'] ?? null,
            'proof_color'   => $st['color'] ?? null,
            'basis'         => 'proof',
        ];
    }

    /**
     * Today's auto-ignored SMS (remembered personal merchants) — the collapsed
     * audit row: nothing vanishes silently, one tap restores + can drop the rule.
     */
    private function autoIgnoredToday(int $userId): array
    {
        return DB::table('t_ai_bank_sms')
            ->where('user_id', $userId)
            ->where('status', 'ignored')
            ->where('auto_reason', 'remembered_merchant')
            ->whereDate('updated_at', now()->toDateString())
            ->orderByDesc('sms_at')
            ->get(['id', 'amount', 'counterparty', 'sms_at'])
            ->map(fn($s) => [
                'id'           => $s->id,
                'amount'       => round((float) $s->amount, 0),
                'counterparty' => $s->counterparty,
                'time'         => $s->sms_at ? substr((string) $s->sms_at, 11, 5) : null,
            ])->all();
    }

    /**
     * What the SWEEPS closed by themselves today, per direction. This is the
     * accountability surface for automation: an SMS the system decided you did
     * not need to see must still be findable, say why it closed, and be one tap
     * from coming back. Without this, "the box got quieter" and "the box started
     * hiding money" look identical.
     */
    private function autoHandledToday(int $userId, string $direction): array
    {
        $reasons = $direction === 'out'
            // 'entry_tagged' = a human answered "yes, that's it" when they
            // recorded the payment by hand. It belongs here beside the swept
            // closes: same outcome, stronger evidence, same Restore.
            ? ['already_recorded', 'already_in_ledger', 'entry_tagged']
            : ['order_approved', 'approved_in_queue', 'already_verified', 'proof_pair', 'mapped_customer'];

        $rows = DB::table('t_ai_bank_sms as s')
            ->leftJoin('t_fin_ledger as l', 'l.id', '=', 's.linked_ledger_id')
            ->where('s.user_id', $userId)
            ->whereIn('s.auto_reason', $reasons)
            ->whereDate('s.updated_at', now()->toDateString())
            ->orderByDesc('s.sms_at')
            ->limit(40)
            ->get(['s.id', 's.amount', 's.counterparty', 's.sms_at', 's.auto_reason',
                   's.counterparty_account', 'l.transaction_type', 'l.description',
                   'l.id as ledger_id', 'l.amount as ledger_amount', 'l.transaction_date']);

        $map = app(\App\Services\Assistant\SmsCounterpartyMap::class);

        return $rows->map(function ($s) use ($map) {
            // A reconciled VENDOR payment is a teaching moment: we now know this
            // account paid that vendor, but nobody was ever asked to remember it.
            $teach = null;
            if ($s->auto_reason === 'already_recorded'
                && $s->transaction_type === 'vendor_payment'
                && $s->counterparty_account
                && !$map->byAccount($s->counterparty_account)) {
                $teach = ['account' => $s->counterparty_account];
            }
            return [
                'id'           => $s->id,
                'amount'       => round((float) $s->amount, 0),
                'counterparty' => $s->counterparty,
                'time'         => $s->sms_at ? substr((string) $s->sms_at, 11, 5) : null,
                'reason'       => $s->auto_reason,
                'note'         => $this->reasonNote($s->auto_reason, $s->description),
                'teach'        => $teach,
                // ⭐ WHAT it was filed against — the owner's "so later I know
                // what was tagged to what". A reason alone ("Already recorded")
                // still leaves you asking "recorded as what?"; this answers it
                // on the row, with the id so a client can link straight to it.
                'tagged_to'    => $s->ledger_id ? [
                    'ledger_id' => (int) $s->ledger_id,
                    'label'     => trim(\Illuminate\Support\Str::limit(trim((string) $s->description), 48)
                                   ?: ucfirst(str_replace('_', ' ', (string) $s->transaction_type))),
                    'amount'    => round((float) $s->ledger_amount, 0),
                    'date'      => $s->transaction_date ? substr((string) $s->transaction_date, 0, 10) : null,
                ] : null,
            ];
        })->all();
    }

    /** Plain-English "why did this close by itself?". */
    private function reasonNote(?string $reason, ?string $ledgerDescription): string
    {
        return match ($reason) {
            'already_recorded'  => 'Already recorded' . ($ledgerDescription
                                    ? ' — ' . \Illuminate\Support\Str::limit(trim($ledgerDescription), 60)
                                    : ' in the ledger'),
            'already_in_ledger' => 'Already recorded in the ledger',
            'entry_tagged'      => 'You confirmed this when you recorded the payment',
            'order_approved'    => 'The matching order was approved',
            'approved_in_queue' => 'Approved in Online Approvals',
            'already_verified'  => 'Already verified by the bank email',
            'proof_pair'        => 'Verified against the customer’s screenshot',
            'mapped_customer'   => 'Matched to a known customer account',
            default             => 'Handled automatically',
        };
    }

    /**
     * The payer name on a credit SMS → a customer, but ONLY when it is
     * unambiguous AND that customer has an invoice awaiting approval. Anything
     * less returns null and the user picks — we never guess at someone's money.
     */
    private function suggestCustomer(?string $counterparty): ?array
    {
        $name = trim((string) $counterparty);
        if (mb_strlen($name) < 3) return null;
        $norm = mb_strtolower(str_replace(' ', '', $name));

        $hits = DB::table('t_crm_prod_customer')
            ->whereRaw("LOWER(REPLACE(CONCAT(COALESCE(first_name,''),COALESCE(last_name,'')),' ','')) LIKE ?", ['%' . $norm . '%'])
            ->limit(3)
            ->get(['id', 'first_name', 'last_name']);

        if ($hits->count() !== 1) return null;

        $c = $hits->first();
        if ($this->approvalsQueueCount((int) $c->id) < 1) return null;

        return [
            'id' => $c->id,
            'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
        ];
    }

    /**
     * A single online-approvals-queue order whose OUTSTANDING balance equals a
     * credit amount — the last-resort suggestion for an SMS the system can't
     * identify by account or name (e.g. HBL credits carry no sender account).
     *
     * Returns {order_id, order_number, customer_id, customer_name} only when
     * EXACTLY ONE such order exists (within the pairing amount tolerance).
     * Zero or several → null, because a wrong guess on someone's money is worse
     * than making Taimur search. This is a suggestion he confirms, never auto.
     */
    private function suggestOrderByAmount(float $amount): ?array
    {
        if ($amount <= 0) return null;
        $tol = \App\Services\Payments\Signals\PaymentProofStatusService::amountTolerance();

        // Orders in the approvals queue (invoice still pending) with a balance
        // equal to this amount. Cap at 2 — we only care "exactly one vs not".
        $hits = DB::table('t_fin_ledger as l')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->where('l.transaction_type', 'invoice')
            ->whereIn('l.approval_status', ['pending', 'pending_l1', 'pending_l2'])
            ->whereRaw('ABS((o.total_price - COALESCE(o.total_paid,0)) - ?) <= ?', [$amount, $tol])
            ->whereRaw('(o.total_price - COALESCE(o.total_paid,0)) > 0.01')
            ->distinct()
            ->limit(2)
            ->get(['o.id as order_id', 'o.order_number', 'o.customer_id', 'c.first_name', 'c.last_name']);

        if ($hits->count() !== 1) return null;

        $h = $hits->first();
        return [
            'order_id'      => (int) $h->order_id,
            'order_number'  => $h->order_number,
            'customer_id'   => (int) $h->customer_id,
            'customer_name' => trim(($h->first_name ?? '') . ' ' . ($h->last_name ?? '')),
        ];
    }

    /**
     * Cards still waiting for his Confirm (pending + not expired), newest
     * first. Payment-proof cards live 24 h, so this is the "review all the
     * proofs I sent" surface; short-TTL money cards appear while alive.
     */
    private function pendingCards(int $userId): array
    {
        return DB::table('t_ai_drafts')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->where(function ($w) {
                $w->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'type', 'summary', 'expires_at', 'created_at'])
            ->map(fn($d) => [
                'id'         => $d->id,
                'type'       => $d->type,
                'summary'    => $d->summary,
                'time'       => $d->created_at ? substr((string) $d->created_at, 11, 5) : null,
                'expires_at' => $d->expires_at,
            ])->all();
    }

    /**
     * Self-heal: an SMS whose linked card died (expired / cancelled / failed)
     * must return to the to-sort pile instead of vanishing. Runs on every
     * summary/inbox load — idempotent, cheap, no cron needed on shared hosting.
     */
    private function revertOrphanedSms(int $userId): void
    {
        $ids = DB::table('t_ai_bank_sms as s')
            ->join('t_ai_drafts as d', 'd.id', '=', 's.linked_draft_id')
            ->where('s.user_id', $userId)
            ->where('s.status', 'recorded')
            ->where(function ($w) {
                $w->whereIn('d.status', ['cancelled', 'expired', 'failed'])
                  ->orWhere(function ($q) {
                      $q->where('d.status', 'pending')
                        ->whereNotNull('d.expires_at')
                        ->where('d.expires_at', '<', now());
                  });
            })
            ->pluck('s.id');

        if ($ids->isNotEmpty()) {
            DB::table('t_ai_bank_sms')->whereIn('id', $ids)
                ->update(['status' => 'new', 'linked_draft_id' => null, 'updated_at' => now()]);
        }
    }

    /**
     * Reconcile credit SMS whose payment is settled but whose inbox row is still
     * open. The pairing/verification happens inside the WhatsApp matcher, which
     * knows nothing about t_ai_bank_sms — this sweep is the bridge, run on every
     * inbox + summary load (so it's near-immediate, not "when he opens it").
     *
     * (a) HELD-SIGNAL PAIRED — the common timeline: the SMS arrived first, its
     *     held bank signal later got paired by the customer's screenshot. Close
     *     as proof_pair (the SMS itself did the verifying).
     * (b) ALREADY VERIFIED BY ANOTHER SOURCE — the bank EMAIL beat the SMS and
     *     paired with the screenshot first, so the SMS's own held signal is an
     *     orphan. Identify the SAME payment by a SHARED bank REFERENCE against a
     *     matched proof that is already verified (paired), retract the orphan
     *     signal so it can never mis-corroborate later, and close the SMS as
     *     already_verified. Reference match = same bank txn = certain; if the
     *     SMS has no ref or none matches, it simply stays in the inbox (no
     *     false close — money is never auto-closed on a guess).
     */
    private function closeCorroboratedSms(int $userId): void
    {
        // (a) held signal got paired + landed on an order.
        $paired = DB::table('t_ai_bank_sms as s')
            ->join('t_fin_payment_signal as p', 'p.id', '=', 's.linked_signal_id')
            ->where('s.user_id', $userId)
            ->where('s.status', 'new')
            ->where('s.direction', 'credit')
            ->whereNotNull('p.paired_signal_id')
            ->whereNotNull('p.matched_order_id')
            ->get(['s.id', 's.counterparty', 's.counterparty_account', 's.reference', 's.user_id', 'p.paired_signal_id']);

        foreach ($paired as $sms) {
            DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
                'status'      => 'matched',
                'auto_reason' => 'proof_pair',
                'updated_at'  => now(),
            ]);
            // The mate is the customer's screenshot — learn the account if the
            // pairing was reference-certain (see maybeLearnAccount).
            $this->maybeLearnAccount($sms, \App\Models\FIN\PaymentSignal::find($sms->paired_signal_id));
        }

        // (b) redundant SMS whose payment a bank EMAIL already verified.
        $orphans = DB::table('t_ai_bank_sms')
            ->where('user_id', $userId)
            ->where('status', 'new')
            ->where('direction', 'credit')
            ->whereNotNull('reference')
            ->where('reference', '<>', '')
            ->get(['id', 'counterparty', 'counterparty_account', 'reference', 'user_id', 'linked_signal_id']);

        foreach ($orphans as $sms) {
            // A verified proof (matched to an order AND paired) sharing this
            // SMS's bank reference = the very same payment. Prefer the WhatsApp
            // side (the customer's own screenshot) so we can also learn from it.
            $proof = \App\Models\FIN\PaymentSignal::where('extracted_ref', $sms->reference)
                ->whereNotNull('matched_order_id')
                ->whereNotNull('paired_signal_id')
                ->where('status', 'matched')
                ->orderByRaw("CASE WHEN source = 'whatsapp' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->first();
            if (!$proof) {
                continue;
            }

            // Retract this SMS's own orphan held signal (unpaired, no order) so
            // it can never later attach to an unrelated proof — mirrors ignore().
            if ($sms->linked_signal_id) {
                $held = \App\Models\FIN\PaymentSignal::find($sms->linked_signal_id);
                if ($held && $held->source === \App\Models\FIN\PaymentSignal::SOURCE_BANK_SMS
                    && !$held->paired_signal_id && !$held->matched_order_id) {
                    DB::table('t_fin_payment_signal_order')->where('signal_id', $held->id)->delete();
                    $held->delete();
                }
            }

            DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
                'status'           => 'matched',
                'auto_reason'      => 'already_verified',
                'linked_signal_id' => $proof->id, // trace to the verified payment
                'updated_at'       => now(),
            ]);

            // Learn the account (ref-certain by construction — we matched on ref).
            $this->maybeLearnAccount($sms, $proof);
        }

        // (c) APPROVED IN THE QUEUE without the assistant. An amount-unique
        //     attach puts a blue "Bank confirmed" on exactly one approvals order
        //     and waits for Taimur's chip. But the approver often just approves
        //     that order in Online Approvals — a human looked at the bank
        //     confirmation and accepted it, which IS the confirmation the chip
        //     was asking for. Without this the SMS sat 'new' forever, so the
        //     inbox kept showing money that was long since dealt with.
        //     Deliberately NOT taught to the counterparty map: the attach was by
        //     AMOUNT alone, and an amount coincidence must never mint an
        //     account→customer rule (the Rs-36 cross-pairing lesson).
        $attached = DB::table('t_ai_bank_sms as s')
            ->join('t_fin_payment_signal as p', 'p.id', '=', 's.linked_signal_id')
            ->join('t_fin_ledger as l', 'l.order_id', '=', 'p.matched_order_id')
            ->where('s.user_id', $userId)
            ->where('s.status', 'new')
            ->where('s.direction', 'credit')
            ->where('p.source', \App\Models\FIN\PaymentSignal::SOURCE_BANK_SMS)
            ->whereIn('p.match_reason', \App\Models\FIN\PaymentSignal::GUESS_REASONS)
            ->whereNotNull('p.matched_order_id')
            ->where('l.transaction_type', 'invoice')
            ->whereIn('l.approval_status', ['pending_l2', 'approved'])
            ->distinct()
            ->pluck('s.id');

        if ($attached->isNotEmpty()) {
            DB::table('t_ai_bank_sms')->whereIn('id', $attached)->update([
                'status'      => 'matched',
                'auto_reason' => 'approved_in_queue',
                'updated_at'  => now(),
            ]);
        }

        // (d) THE ORDER WAS APPROVED ANYWAY. A credit that never got attached to
        //     anything, but whose amount matches EXACTLY ONE recently-approved
        //     invoice — the payment was accepted (by screenshot, bank email, or
        //     the approver's own knowledge) and this SMS is the same money
        //     arriving. Seven such rows sat open for a week in prod.
        //
        //     This is the most permissive rule here, so it is fenced hardest:
        //       • exactly ONE approved invoice matches the amount (±5, ±3 days),
        //       • and NO OTHER open credit matches that same invoice — otherwise
        //         two payers of the same amount could both claim one order,
        //       • it only CLOSES an inbox row: it never marks anything Verified,
        //         never touches an order, and NEVER teaches the counterparty map
        //         (an amount coincidence must not mint an account rule — the
        //         Rs-36 lesson),
        //       • and it lands in "Handled today" with Restore.
        // "Never attached to anything" means EITHER no signal at all, OR the
        // held `bank_credit_unidentified` signal every unmatched credit gets at
        // ingest (holdOrDrop). Filtering on linked_signal_id IS NULL alone
        // matched nothing in practice — all 7 real cases carried a held signal.
        $loose = DB::table('t_ai_bank_sms as s')
            ->leftJoin('t_fin_payment_signal as p', 'p.id', '=', 's.linked_signal_id')
            ->where('s.user_id', $userId)
            ->where('s.status', 'new')
            ->where('s.direction', 'credit')
            ->where('s.amount', '>', 0)
            ->where(function ($q) {
                $q->whereNull('s.linked_signal_id')
                  ->orWhere(fn($w) => $w->whereNull('p.matched_order_id')->whereNull('p.paired_signal_id'));
            })
            // Same rule as the debit sweep: never re-close what a human restored.
            ->where(fn($q) => $q->whereNull('s.auto_reason')->orWhere('s.auto_reason', '<>', 'reconcile_rejected'))
            ->get(['s.id', 's.amount', 's.sms_at', 's.linked_signal_id', 's.counterparty_account', 's.reference']);

        foreach ($loose as $sms) {
            $day = substr((string) $sms->sms_at, 0, 10);

            $orders = DB::table('t_fin_ledger as l')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->where('l.transaction_type', 'invoice')
                ->whereIn('l.approval_status', ['pending_l2', 'approved'])
                ->whereRaw('ABS(o.total_price - ?) <= 5', [$sms->amount])
                ->whereRaw('ABS(DATEDIFF(l.transaction_date, ?)) <= 3', [$day])
                ->distinct()
                ->limit(2)
                ->pluck('o.id');

            if ($orders->count() !== 1) {
                continue;
            }
            $orderId = (int) $orders->first();

            // A rival open credit of the same size means we cannot tell WHICH
            // payment settled that order — so normally leave both for the human.
            //
            // EXCEPTION: this SMS carries identity evidence tying it to THAT
            // order's customer — its counterparty account is mapped to them, or
            // its bank reference is the one on that order's proof. Then it is
            // not a same-amount coincidence and the rival is irrelevant.
            // Deliberately NOT relaxed merely because the order has a proof:
            // a proof shows the ORDER was paid, not which of two look-alike
            // credits was the payment.
            $rivals = DB::table('t_ai_bank_sms')
                ->where('user_id', $userId)
                ->where('status', 'new')
                ->where('direction', 'credit')
                ->where('id', '<>', $sms->id)
                ->whereRaw('ABS(amount - ?) <= 5', [$sms->amount])
                ->exists();
            if ($rivals && !$this->tiedToOrder($sms, $orderId)) {
                continue;
            }

            // Retract the orphan held signal, exactly as ignore() does. Leaving
            // it alive would let a later screenshot pair with a credit we have
            // already declared settled, quietly verifying the wrong order.
            if ($sms->linked_signal_id) {
                $held = \App\Models\FIN\PaymentSignal::find($sms->linked_signal_id);
                if ($held && $held->source === \App\Models\FIN\PaymentSignal::SOURCE_BANK_SMS
                    && !$held->paired_signal_id && !$held->matched_order_id) {
                    DB::table('t_fin_payment_signal_order')->where('signal_id', $held->id)->delete();
                    $held->delete();
                }
            }

            DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
                'status'           => 'matched',
                'auto_reason'      => 'order_approved',
                'linked_signal_id' => null,
                'updated_at'       => now(),
            ]);
        }
    }

    /**
     * MONEY OUT — close a bank debit whose payment is ALREADY in the ledger.
     *
     * WHY A SWEEP AND NOT A HOOK: a payment can be recorded from the web vendor
     * screen, the Requests workflow, mobile, or the assistant. Hooking each of
     * those means editing four money-critical files and re-opening the leak the
     * day a fifth appears. This one query catches every path — including entries
     * made before the feature existed, and by other users. (Proven in prod data:
     * the Rs 3,706 and Rs 15,000 debits sat unsorted for a week because their
     * expenses were entered through Requests, which the assistant never sees.)
     *
     * SAFETY. Closing an SMS moves NO money — it only clears a to-do. The real
     * risk is the opposite: hiding a debit that was NEVER recorded, so an
     * expense silently goes missing. Hence:
     *   • the paying BANK must match, not just the amount (every online money-out
     *     row carries receiving_account_id — verified 17/17 on both types),
     *   • amount within Rs 1 and date within 1 day,
     *   • rejected / reversed entries don't count as recorded,
     *   • WHO WAS PAID must match whenever we can know it (see below),
     *   • EXACTLY ONE unclaimed candidate when identity is unknown,
     *   • the claimed ledger row is REMEMBERED (linked_ledger_id) so it can never
     *     also close a second look-alike SMS,
     *   • the closed row stays visible under "Handled today" with Restore.
     *
     * ⚠⚠ IDENTITY (Aug-2026 fix). Bank + amount + date alone is NOT an identity:
     * it closed a T.ALI debit against a payment to a DIFFERENT vendor (Aug-10 —
     * four Rs 150,000 RAAST transfers to Jilani in two minutes, one recorded;
     * the sweep matched the second SMS to Imran Qureshi's own Rs 150,000 of the
     * next day). Two lies for the price of one: Jilani's khata stayed short while
     * the row claiming to be recorded went quiet, and Imran's genuine SMS was
     * left with its only candidate stolen. So when the SMS's counterparty account
     * is MAPPED (a deliberate human teaching), the ledger row must have paid THAT
     * counterparty — `to_account_id` = the vendor's account (or our own account
     * for a transfer). Measured on the whole history: 1 cross-vendor close, this
     * one; every other close is unaffected.
     *
     * ⭐ And once identity is certain, MULTIPLE candidates stop being ambiguous:
     * same vendor, same bank, same amount, same day, all equally valid — so N
     * SMS claim the N oldest unclaimed rows and any extra SMS stay open. That is
     * what a split transfer needs (RAAST caps force one payment into several),
     * and today's exactly-one rule strands every one of them. Identity UNKNOWN
     * keeps the old exactly-one rule — ambiguity there is still a real risk.
     */
    private function reconcileRecordedDebits(int $userId): void
    {
        $open = DB::table('t_ai_bank_sms')
            ->where('user_id', $userId)
            ->where('status', 'new')
            ->whereIn('direction', ['debit', 'unknown'])
            ->whereNotNull('receiving_account_id')
            ->where('amount', '>', 0)
            // A row the human RESTORED after an auto-close is off-limits: the
            // sweep must not overrule a person who looked at it and said no.
            ->where(fn($q) => $q->whereNull('auto_reason')->orWhere('auto_reason', '<>', 'reconcile_rejected'))
            ->get(['id', 'amount', 'receiving_account_id', 'sms_at', 'counterparty_account']);

        if ($open->isEmpty()) {
            return;
        }

        // Ledger rows already spoken for: claimed by another SMS directly, or
        // produced BY the assistant for an SMS (vendor payments post a ledger
        // row; expenses post a REQUEST which the ledger references).
        $claimed = DB::table('t_ai_bank_sms')->whereNotNull('linked_ledger_id')
            ->pluck('linked_ledger_id')->all();
        $viaDrafts = DB::table('t_ai_drafts as d')
            ->join('t_ai_bank_sms as s', 's.linked_draft_id', '=', 'd.id')
            ->whereNotNull('d.result_id')
            ->get(['d.result_type', 'd.result_id']);
        $ledgerIds = $viaDrafts->where('result_type', 'ledger')->pluck('result_id')->all();
        $requestIds = $viaDrafts->where('result_type', 'request')->pluck('result_id')->all();
        if ($requestIds) {
            $ledgerIds = array_merge($ledgerIds, DB::table('t_fin_ledger')
                ->whereIn('request_id', $requestIds)->pluck('id')->all());
        }
        $claimed = array_unique(array_merge($claimed, $ledgerIds));

        // Which ledger destination each taught account key owns, and the reverse
        // (destination → the keys that claim it). Built once for the whole sweep.
        $spokenFor = $this->mappedLedgerDestinations();

        foreach ($open as $sms) {
            $day = substr((string) $sms->sms_at, 0, 10);

            $base = DB::table('t_fin_ledger')
                ->where('mode', 'online')
                ->whereIn('transaction_type', ['vendor_payment', 'expense', 'transfer'])
                ->whereNotIn('approval_status', ['rejected', 'reversed'])
                ->where('receiving_account_id', $sms->receiving_account_id)
                ->whereRaw('ABS(amount - ?) <= 1', [$sms->amount])
                ->whereRaw('ABS(DATEDIFF(transaction_date, ?)) <= 1', [$day])
                ->when($claimed, fn($q) => $q->whereNotIn('id', $claimed));

            $target = $this->debitLedgerTarget($sms);

            if ($target !== null) {
                // Identity established — only this counterparty's rows qualify,
                // and any of them will do (see the split-transfer note above).
                $hit = (clone $base)->where('to_account_id', $target)
                    ->orderBy('transaction_date')->orderBy('id')
                    ->first(['id']);
                if (!$hit) {
                    continue;
                }
            } else {
                // Identity unknown. Old rule — exactly one candidate — PLUS a
                // veto: a row paying a counterparty whose account we know, and
                // which is not this SMS's account, cannot be this SMS. (An SMS
                // carrying no account key at all — e.g. every Alfalah debit —
                // can prove nothing, so it matches exactly as before.)
                $key = (string) ($sms->counterparty_account ?? '');
                if ($key !== '' && $spokenFor) {
                    $foreign = [];
                    foreach ($spokenFor as $accountId => $keys) {
                        if (!in_array($key, $keys, true)) {
                            $foreign[] = $accountId;
                        }
                    }
                    if ($foreign) {
                        $base->whereNotIn('to_account_id', $foreign);
                    }
                }

                $hits = $base->limit(2)->get(['id']);
                if ($hits->count() !== 1) {
                    continue; // zero = genuinely unrecorded; several = ambiguous
                }
                $hit = $hits->first();
            }

            DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
                'status'           => 'recorded',
                'auto_reason'      => 'already_recorded',
                'linked_ledger_id' => $hit->id,
                'updated_at'       => now(),
            ]);
            $claimed[] = $hit->id; // never let the same entry close another SMS
        }
    }

    /**
     * Re-read the still-unsorted SMS whose direction the parser could not call
     * at the time, and let a SMARTER parser have another go.
     *
     * WHY THIS EXISTS: parsing happens once, at ingest, so a parser improvement
     * only ever helps future messages — the rows already sitting in the inbox
     * keep the old verdict forever. Alfalah's incoming-payment wording
     * ("received from X in your A/C … via Fund Transfer") was read as 'unknown'
     * for weeks, and an unknown is drawn in the MONEY OUT box with a minus
     * sign: four real customer payments were on the wrong side of the screen,
     * looking like unrecorded expenses, invisible to every credit rule we have.
     *
     * A sweep rather than a one-off script, for the same reason every other
     * reconciliation here is one: prod runs no scheduler, and a page load is
     * the only reliable clock. It is idempotent (a row that gets a verdict is
     * no longer 'unknown'; one that doesn't costs a regex) and it is cheap —
     * unsorted unknowns are a handful of rows.
     *
     * SAFETY:
     *   • ONLY rows still awaiting a human ('new'). A recorded/matched/ignored
     *     row may already have had decisions built on its direction; those are
     *     never rewritten.
     *   • The parser must be CONFIDENT — a still-'unknown' verdict changes
     *     nothing.
     *   • Names and account keys are only ever FILLED IN, never overwritten:
     *     an account key is what counterparty rules are keyed on, and a human
     *     may already have taught this one.
     *   • A row that becomes a CREDIT is then run through the ordinary no-tap
     *     pipeline, exactly as if it had just arrived — so it can pair with a
     *     screenshot, or find its payer by name, without anyone re-sending it.
     */
    private function reclassifyUnknownDirections(int $userId): void
    {
        try {
            // Bounded so the FIRST run after a parser improvement can never turn
            // a page load into a batch job: unsorted unknowns are naturally few
            // (they are the inbox), but a backlog is exactly when this fires.
            // Anything left over is picked up by the next load — self-healing.
            $rows = DB::table('t_ai_bank_sms')
                ->where('user_id', $userId)
                ->where('status', 'new')
                ->where('direction', 'unknown')
                ->orderByDesc('sms_at')
                ->limit(25)
                ->get(['id', 'raw_body', 'counterparty', 'counterparty_account']);

            if ($rows->isEmpty()) {
                return;
            }
            $parser = app(\App\Services\Assistant\BankSmsParser::class);

            foreach ($rows as $row) {
                $parsed = $parser->parse((string) $row->raw_body);
                if (($parsed['direction'] ?? 'unknown') === 'unknown') {
                    continue; // still not certain — leave it for the human
                }

                $update = ['direction' => $parsed['direction'], 'updated_at' => now()];
                if (empty($row->counterparty) && !empty($parsed['counterparty'])) {
                    $update['counterparty'] = $parsed['counterparty'];
                }
                if (empty($row->counterparty_account) && !empty($parsed['counterparty_account'])) {
                    $update['counterparty_account'] = $parsed['counterparty_account'];
                }
                DB::table('t_ai_bank_sms')->where('id', $row->id)->update($update);

                // Either way it just gained a real direction — run it through
                // the ordinary no-tap pipeline as if it had just arrived: a
                // credit can pair or find its payer, a debit can auto-ignore or
                // raise its card. (Unlike restore(), no human said "no" here —
                // the row was simply unreadable until the parser got smarter.)
                app(\App\Services\Assistant\BankSmsAutoActionService::class)
                    ->handle(DB::table('t_ai_bank_sms')->where('id', $row->id)->first());
            }
        } catch (\Throwable $e) {
            // Never break the inbox over a re-read: the rows stay exactly as
            // they are and the human path is untouched.
            \Log::warning('[reclassifyUnknownDirections] ' . $e->getMessage(), ['user' => $userId]);
        }
    }

    /**
     * The ledger `to_account_id` a debit SMS must have paid, or null when we
     * cannot know. Only an ACCOUNT-KEY rule counts: a key is a deliberate human
     * teaching, while a name is a guess that collides (T.ALI / T. ALI / TAHIR
     * ALI), and this drives an automatic close that HIDES a row.
     *
     *   vendor rule  → that vendor's ledger account (t_fin_vendors.account_id).
     *   account rule → one of our own accounts, the destination of a transfer.
     *   expense / customer / ignore → null: an expense category is not a ledger
     *   destination (expenses post to whichever account the category resolves
     *   to), so those keep the conservative exactly-one rule.
     */
    private function debitLedgerTarget(object $sms): ?int
    {
        if (empty($sms->counterparty_account)) {
            return null;
        }
        $rule = app(\App\Services\Assistant\SmsCounterpartyMap::class)
            ->byAccount($sms->counterparty_account);
        if (!$rule || empty($rule->entity_id)) {
            return null;
        }
        if ($rule->entity_type === 'vendor') {
            $accountId = DB::table('t_fin_vendors')->where('id', $rule->entity_id)->value('account_id');
            return $accountId ? (int) $accountId : null;
        }
        if ($rule->entity_type === 'account') {
            return (int) $rule->entity_id;
        }
        return null;
    }

    /**
     * [ledger to_account_id => [account keys taught for it]] for every active
     * vendor/account rule. Used as the identity veto for unmapped SMS: a
     * destination somebody else's key owns is not a candidate for this one.
     */
    private function mappedLedgerDestinations(): array
    {
        $out = [];
        try {
            $rows = DB::table('t_ai_counterparty_map')
                ->where('is_active', 1)
                ->whereNotNull('account_key')
                ->whereIn('entity_type', ['vendor', 'account'])
                ->whereNotNull('entity_id')
                // ⚠ WAGROUP:* rules are vendor-typed but they map a WhatsApp
                // GROUP, not a bank account — they say nothing about which
                // account keys this vendor's debits carry. Counting them here
                // would mark the vendor's ledger account "owned" the moment his
                // first purchase log is confirmed, and the exactly-one sweep
                // would then refuse to close that vendor's rows forever.
                // (WAPROD:* product aliases are already outside this whereIn.)
                ->where('account_key', 'not like', 'WAGROUP:%')
                ->get(['account_key', 'entity_type', 'entity_id']);

            $vendorAccounts = DB::table('t_fin_vendors')->whereNotNull('account_id')
                ->pluck('account_id', 'id');

            foreach ($rows as $r) {
                $accountId = $r->entity_type === 'vendor'
                    ? ($vendorAccounts[$r->entity_id] ?? null)
                    : (int) $r->entity_id;
                if (!$accountId) {
                    continue;
                }
                $out[(int) $accountId][] = (string) $r->account_key;
            }
        } catch (\Throwable $e) {
            // A veto we cannot build must not block the sweep — fall back to the
            // pre-existing behaviour rather than closing nothing at all.
            \Log::warning('[reconcileRecordedDebits] veto build failed: ' . $e->getMessage());
            return [];
        }
        return $out;
    }

    /**
     * Is this SMS tied to THIS order by identity rather than by amount?
     * Either its counterparty account is mapped to the order's customer, or its
     * bank reference is the reference on one of that order's proofs (same bank
     * transaction). Used only to lift the rival guard in sweep case (d).
     */
    private function tiedToOrder(object $sms, int $orderId): bool
    {
        try {
            if (!empty($sms->counterparty_account)) {
                $rule = app(\App\Services\Assistant\SmsCounterpartyMap::class)
                    ->byAccount($sms->counterparty_account);
                if ($rule && $rule->entity_type === 'customer' && $rule->entity_id) {
                    $customerId = DB::table('t_crm_prod_order')->where('id', $orderId)->value('customer_id');
                    if ($customerId && (int) $customerId === (int) $rule->entity_id) {
                        return true;
                    }
                }
            }
            if (!empty($sms->reference)) {
                return \App\Models\FIN\PaymentSignal::where('matched_order_id', $orderId)
                    ->where('extracted_ref', $sms->reference)
                    ->exists();
            }
        } catch (\Throwable $e) {
            // A guard that cannot prove identity must fail CLOSED (keep the
            // rival guard), never open.
            return false;
        }
        return false;
    }

    /**
     * Silently learn a counterparty account → customer mapping from a
     * REFERENCE-CERTAIN pair (owner ruling Jul-2026). When a bank SMS and the
     * customer's screenshot share the SAME bank reference, it is provably the
     * same transaction, and the screenshot's customer comes from that
     * customer's own WhatsApp conversation — so the account→customer link is
     * certain. Learn it once, so this customer's FUTURE payments auto-attach
     * with no screenshot at all (the Elina flow the owner asked for).
     *
     * Deliberately NOT learned on amount/time-only pairs: a same-amount
     * coincidence could teach the wrong account, and a wrong mapping would
     * silently misroute every future payment. Also never overwrites an existing
     * mapping. All guards must hold or it no-ops.
     */
    private function maybeLearnAccount(object $sms, $proof): void
    {
        try {
            if (empty($sms->counterparty_account) || empty($sms->reference)) {
                return; // nothing to key on, or not reference-bearing
            }
            if (!$proof
                || $proof->source !== \App\Models\FIN\PaymentSignal::SOURCE_WHATSAPP
                || empty($proof->matched_customer_id)) {
                return; // customer identity must come from the screenshot side
            }
            // The references must genuinely match (ref-certain), not just be present.
            if ((string) $proof->extracted_ref !== (string) $sms->reference) {
                return;
            }
            $map = app(\App\Services\Assistant\SmsCounterpartyMap::class);
            if ($map->byAccount($sms->counterparty_account)) {
                return; // already mapped — never silently overwrite
            }
            $map->save(
                $sms->counterparty_account,
                $sms->counterparty,
                'customer',
                (int) $proof->matched_customer_id,
                null,
                (int) $sms->user_id,
            );
        } catch (\Throwable $e) {
            // Learning is a nicety — never break the inbox load over it.
            \Log::warning('[maybeLearnAccount] ' . $e->getMessage(), ['sms_id' => $sms->id ?? null]);
        }
    }

    private function allowed($user): bool
    {
        return $user && $user->hasMobilePermission('use_ai_assistant');
    }

    /** "2026-07-27 02:05:18" → "Jul 27", or "Today" / "Yesterday". */
    private function shortDate(string $smsAt): ?string
    {
        try {
            $d = \Illuminate\Support\Carbon::parse($smsAt);
            if ($d->isToday())     return 'Today';
            if ($d->isYesterday()) return 'Yesterday';
            return $d->format('M j');
        } catch (\Throwable) {
            return null;
        }
    }
}
