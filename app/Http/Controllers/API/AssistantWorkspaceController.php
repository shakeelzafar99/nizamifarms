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

        $this->revertOrphanedSms((int) $user->id);
        $this->closeCorroboratedSms((int) $user->id);
        $done = $this->doneToday((int) $user->id);

        return response()->json([
            'success'    => true,
            'to_sort'    => ['count' => $this->toSortCount((int) $user->id)],
            'done_today' => [
                'count'  => count($done),
                'amount' => round(array_sum(array_column($done, 'amount')), 0),
            ],
            'matched'       => ['count' => $this->matchedCount((int) $user->id)],
            'pending_cards' => ['count' => count($this->pendingCards((int) $user->id))],
        ]);
    }

    /** GET /api/assistant/workspace/inbox — the full inbox: to-sort, matched, done-today. */
    public function inbox(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $this->revertOrphanedSms((int) $user->id);
        $this->closeCorroboratedSms((int) $user->id);
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
            ->get(['s.id', 's.sender_id', 's.amount', 's.counterparty', 's.counterparty_account', 's.status', 's.sms_at', 'b.name as bank_name'])
            ->map(function ($s) {
                // Counterparty memory: a saved vendor/expense rule pre-fills the
                // card ("Looks like: Karachi Feeds") — one tap instead of a search.
                // Account hits are strong; name-only hits are suggestions anyway
                // because nothing here auto-posts (Confirm still required).
                $rule = app(\App\Services\Assistant\SmsCounterpartyMap::class)->forSms($s);
                $suggestion = null;
                if ($rule && in_array($rule->entity_type, ['vendor', 'expense'], true)) {
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
                    'amount'       => round((float) $s->amount, 0),
                    'counterparty' => $s->counterparty,
                    'needs_sender' => $s->status === 'needs_sender',
                    'time'         => $s->sms_at ? substr((string) $s->sms_at, 11, 5) : null,
                    'suggestion'   => $suggestion,
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
            ->get(['id', 'sender_id', 'amount', 'counterparty', 'counterparty_account', 'status', 'auto_reason', 'sms_at'])
            ->map(function ($s) {
                // Counterparty memory FIRST: a mapped account → that customer,
                // pre-filled with certainty. Falls back to the name-based
                // suggestion (unique customer with something in approvals).
                $suggested = null;
                $suggestedOrder = null;
                if ($s->status === 'new') {
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
                return [
                    'id'           => $s->id,
                    'sender_id'    => $s->sender_id,
                    'amount'       => round((float) $s->amount, 0),
                    'counterparty' => $s->counterparty,
                    'matched'      => $s->status === 'matched',
                    'needs_sender' => $s->status === 'needs_sender',
                    // 'mapped_customer' / 'proof_pair' → this credit was handled
                    // with NO tap (auto-verified / auto-attached). Shown as ⚡.
                    'auto'         => $s->auto_reason,
                    'time'         => $s->sms_at ? substr((string) $s->sms_at, 11, 5) : null,
                    'suggested_customer' => $suggested,
                    // A single amount-matching approvals order (low confidence).
                    'suggested_order'    => $suggestedOrder,
                ];
            })->all();
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
}
