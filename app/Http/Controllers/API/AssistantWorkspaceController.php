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
                if ($s->status === 'new') {
                    $rule = app(\App\Services\Assistant\SmsCounterpartyMap::class)->byAccount($s->counterparty_account);
                    if ($rule && $rule->entity_type === 'customer' && $rule->entity_id) {
                        $suggested = [
                            'id'   => (int) $rule->entity_id,
                            'name' => app(\App\Services\Assistant\SmsCounterpartyMap::class)->entityName($rule),
                        ];
                    }
                    $suggested = $suggested ?: $this->suggestCustomer($s->counterparty);
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
     * Close credit SMS whose HELD bank signal got paired by a screenshot that
     * arrived AFTER the SMS (the common timeline). The pairing happens inside
     * the WhatsApp matcher, which knows nothing about t_ai_bank_sms — this
     * sweep is the bridge: held-signal paired + on an order → the SMS is done.
     */
    private function closeCorroboratedSms(int $userId): void
    {
        $rows = DB::table('t_ai_bank_sms as s')
            ->join('t_fin_payment_signal as p', 'p.id', '=', 's.linked_signal_id')
            ->where('s.user_id', $userId)
            ->where('s.status', 'new')
            ->where('s.direction', 'credit')
            ->whereNotNull('p.paired_signal_id')
            ->whereNotNull('p.matched_order_id')
            ->pluck('s.id');

        if ($rows->isNotEmpty()) {
            DB::table('t_ai_bank_sms')->whereIn('id', $rows)->update([
                'status'      => 'matched',
                'auto_reason' => 'proof_pair',
                'updated_at'  => now(),
            ]);
        }
    }

    private function allowed($user): bool
    {
        return $user && $user->hasMobilePermission('use_ai_assistant');
    }
}
