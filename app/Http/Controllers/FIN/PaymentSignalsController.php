<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\PaymentSignal;
use App\Services\Payments\Signals\PaymentProofStatusService;
use Illuminate\Http\Request;

/**
 * Read-only JSON endpoints that feed the payment-proof detail panels on the
 * Online Approvals page (and anywhere else that wants to show the screenshot /
 * parsed email behind a badge). Never mutates finance data.
 */
class PaymentSignalsController extends Controller
{
    /** GET /admin/payments/order/{orderId}/signals */
    public function forOrder(Request $request, int $orderId)
    {
        // Include signals tied to this order DIRECTLY and via a combined (bulk)
        // payment link, so an older invoice in a bundle still shows the proof.
        $linkedSignalIds = \DB::table('t_fin_payment_signal_order')
            ->where('order_id', $orderId)
            ->pluck('signal_id')
            ->all();

        $signals = PaymentSignal::query()
            ->where(function ($q) use ($orderId, $linkedSignalIds) {
                $q->where('matched_order_id', $orderId);
                if (!empty($linkedSignalIds)) {
                    $q->orWhereIn('id', $linkedSignalIds);
                }
            })
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->orderByDesc('id')
            ->get();

        // Pull the order's expected amount + balance for the agreement check.
        $order = \DB::table('t_crm_prod_order')->where('id', $orderId)
            ->first(['total_price', 'total_paid', 'order_number']);
        $expected = $order ? round((float) $order->total_price - (float) ($order->total_paid ?? 0), 2) : null;
        $tolerance = PaymentProofStatusService::amountTolerance();

        // For a combined (bulk) signal the agreement must compare the transfer
        // against the GROUP total (sum of every covered invoice's balance), not
        // this single invoice — otherwise an older invoice would look mismatched.
        $combinedInfo = collect();
        $signalIds = $signals->pluck('id')->all();
        if (!empty($signalIds)) {
            $combinedInfo = \DB::table('t_fin_payment_signal_order')
                ->whereIn('signal_id', $signalIds)
                ->selectRaw('signal_id, COUNT(*) AS c, SUM(balance_at_match) AS total')
                ->groupBy('signal_id')
                ->get()
                ->keyBy('signal_id');
        }

        // Resolve the receiving bank at READ time from the proof's last-4 against
        // a CURRENT active chip (only when exactly one bank carries that last-4),
        // so "To (our bank)" shows even for proofs read before that chip's last-4
        // was configured.
        $last4ToShort = collect();
        $last4List = $signals->pluck('extracted_to_account_last4')->filter()->unique()->values();
        if ($last4List->isNotEmpty()) {
            $rows = \DB::table('t_fin_online_receiving_accounts')
                ->where('is_active', 1)
                ->whereIn('account_last4', $last4List->all())
                ->get(['short_code', 'account_last4']);
            $counts = $rows->groupBy('account_last4')->map->count();
            $last4ToShort = $rows->filter(fn ($r) => ($counts[$r->account_last4] ?? 0) === 1)
                ->keyBy('account_last4')->map(fn ($r) => $r->short_code);
        }

        $payload = $signals->map(function (PaymentSignal $s) use ($expected, $tolerance, $combinedInfo, $last4ToShort) {
            $combo       = $combinedInfo->get($s->id);
            $isCombined  = $combo && (int) $combo->c > 1;
            $compareBase = $isCombined ? (float) $combo->total : $expected;

            $amountMatch = ($compareBase !== null && $s->extracted_amount !== null)
                ? abs((float) $s->extracted_amount - $compareBase) <= $tolerance
                : null;
            // Signed gap shown to the approver: + = paid MORE than the invoice(s), − = short.
            $difference = ($compareBase !== null && $s->extracted_amount !== null)
                ? round((float) $s->extracted_amount - $compareBase, 2)
                : null;

            return [
                'id'             => $s->id,
                'source'         => $s->source,
                'status'         => $s->status,
                'match_reason'   => $s->match_reason,
                'match_confidence' => $s->match_confidence,
                'amount'         => $s->extracted_amount,
                'reference'      => $s->extracted_ref,
                'sender_name'    => $s->extracted_sender_name,
                'sender_account' => $s->extracted_sender_account_masked,
                'sender_bank'    => $s->extracted_sender_bank,
                'to_account'     => (!empty($s->extracted_to_account_last4) && $last4ToShort->has($s->extracted_to_account_last4))
                                    ? $last4ToShort->get($s->extracted_to_account_last4)
                                    : $s->extracted_to_account_short,
                'txn_datetime'   => optional($s->extracted_txn_datetime)->format('Y-m-d H:i'),
                'received_at'    => $s->source === PaymentSignal::SOURCE_EMAIL
                                    ? optional($s->email_received_at)->format('Y-m-d H:i')
                                    : optional($s->created_at)->format('Y-m-d H:i'),
                'image_url'      => $s->source === PaymentSignal::SOURCE_WHATSAPP ? $s->image_public_url : null,
                'email_subject'  => $s->email_subject,
                'email_from'     => $s->email_from,
                'email_body'     => $s->source === PaymentSignal::SOURCE_EMAIL
                                    ? mb_substr((string) $s->extraction_raw_text, 0, 4000)
                                    : null,
                'paired'         => (bool) $s->paired_signal_id,
                'is_combined'    => $isCombined,
                'combined_count' => $isCombined ? (int) $combo->c : null,
                'combined_total' => $isCombined ? (float) $combo->total : null,
                'agreement'      => [
                    'amount_match'  => $amountMatch,
                    'expected'      => $compareBase,
                    'difference'    => $difference,
                ],
            ];
        });

        return response()->json([
            'success'      => true,
            'order_id'     => $orderId,
            'order_number' => $order->order_number ?? null,
            'proof'        => app(PaymentProofStatusService::class)->forOrder($orderId),
            'signals'      => $payload,
            'combined'     => $this->combinedPaymentHint($signals),
            'balance_adjustment' => $this->balanceAdjustmentInfo($signals, $orderId),
        ]);
    }

    /**
     * If a signal was flagged as a possible COMBINED payment (one transfer that
     * equals the SUM of several open invoices), return the customer's open
     * invoices so the manager can see what it likely covers and split manually.
     * Additive/read-only; null when not a bulk case.
     */
    private function combinedPaymentHint($signals): ?array
    {
        // Prefer the authoritative combined links if any of these signals carry
        // them — list exactly the invoices the bundle was tied to.
        $signalIds = $signals->pluck('id')->all();
        if (!empty($signalIds)) {
            $rows = \DB::table('t_fin_payment_signal_order as l')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->whereIn('l.signal_id', $signalIds)
                ->get(['l.order_id', 'o.order_number', 'o.total_price', 'o.total_paid', 'l.balance_at_match']);

            $byOrder = $rows->groupBy('order_id');
            if ($byOrder->count() >= 2) {
                $sum  = 0.0;
                $list = [];
                foreach ($byOrder as $orderId => $grp) {
                    $inv = $grp->first();
                    $bal = $inv->balance_at_match !== null
                        ? (float) $inv->balance_at_match
                        : round((float) $inv->total_price - (float) ($inv->total_paid ?? 0), 2);
                    $sum += $bal;
                    $list[] = [
                        'order_id'     => (int) $orderId,
                        'order_number' => $inv->order_number,
                        'balance'      => $bal,
                    ];
                }
                $amount = (float) optional(
                    $signals->first(fn (PaymentSignal $s) => $s->extracted_amount !== null)
                )->extracted_amount;

                return [
                    'amount'     => $amount,
                    'open_total' => round($sum, 2),
                    'invoices'   => $list,
                    'confirmed'  => true,
                ];
            }
        }

        // Fallback: legacy heuristic for a bulk-flagged-but-unlinked signal
        // (e.g. an ambiguous bundle) — show the customer's open invoices.
        $bulk = $signals->first(fn (PaymentSignal $s) => str_contains((string) $s->match_reason, 'bulk'));
        if (!$bulk || !$bulk->matched_customer_id || !$bulk->extracted_amount) {
            return null;
        }

        $windowDays   = (int) config('payment_signals.match_window_days', 30);
        $graceDays    = (int) config('payment_signals.match_future_grace_days', 1);
        $openStatuses = config('payment_signals.open_payment_statuses', ['unpaid', 'partial']);

        $anchor = \Carbon\Carbon::parse(
            $bulk->extracted_txn_datetime ?? $bulk->email_received_at ?? $bulk->created_at ?? now()
        );

        $invoices = \DB::table('t_crm_prod_order')
            ->where('customer_id', $bulk->matched_customer_id)
            ->whereIn('payment_status', $openStatuses)
            ->where('order_status', '!=', 'cancelled')
            ->where('order_date', '>=', $anchor->copy()->subDays($windowDays)->startOfDay())
            ->where('order_date', '<=', $anchor->copy()->addDays($graceDays)->endOfDay())
            ->orderByDesc('order_date')
            ->get(['id', 'order_number', 'total_price', 'total_paid']);

        $sum = 0.0;
        $list = [];
        foreach ($invoices as $inv) {
            $bal = round((float) $inv->total_price - (float) ($inv->total_paid ?? 0), 2);
            $sum += $bal;
            $list[] = [
                'order_id'     => (int) $inv->id,
                'order_number' => $inv->order_number,
                'balance'      => $bal,
            ];
        }

        if (count($list) < 2) {
            return null;
        }

        return [
            'amount'     => (float) $bulk->extracted_amount,
            'open_total' => round($sum, 2),
            'invoices'   => $list,
        ];
    }

    /**
     * POST /admin/payments/order/{orderId}/uncombine
     *
     * Reversibility for an auto-detected combined payment. Drops the bulk links
     * for the signal(s) covering this order (and their paired opposite-source
     * signal) and reverts those signals to `amount_mismatch`, so the "Combined"
     * badges clear. Read-feature only — touches no ledger / payment / balance.
     */
    public function uncombine(Request $request, int $orderId)
    {
        $signalIds = \DB::table('t_fin_payment_signal_order')
            ->where('order_id', $orderId)
            ->pluck('signal_id')
            ->unique()
            ->values()
            ->all();

        if (empty($signalIds)) {
            return response()->json(['success' => true, 'changed' => 0]);
        }

        // Revert the whole WhatsApp+email group together.
        $pairedIds = PaymentSignal::query()
            ->whereIn('id', $signalIds)
            ->pluck('paired_signal_id')
            ->filter()
            ->all();
        $allIds = array_values(array_unique(array_merge($signalIds, $pairedIds)));

        \DB::table('t_fin_payment_signal_order')->whereIn('signal_id', $allIds)->delete();

        PaymentSignal::query()
            ->whereIn('id', $allIds)
            ->where('status', PaymentSignal::STATUS_MATCHED)
            ->update([
                'status'       => PaymentSignal::STATUS_AMOUNT_MISMATCH,
                'match_reason' => 'combined_dismissed',
            ]);

        return response()->json(['success' => true, 'changed' => count($allIds)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 2 — one-time "balancing discount" (Jun-2026)
    // When a matched/combined proof is SHORT by a small (within-tolerance) amount,
    // an approver can apply that exact difference as a fixed discount to the
    // largest covered invoice that ISN'T posted to the ledger yet, so the payment
    // settles clean. One-time, reversible, permission-gated. The endpoints are
    // shared by BOTH web and mobile so the two stay in sync.
    // ─────────────────────────────────────────────────────────────────────────

    /** Load the matched/mismatch signals covering an order (direct + bulk-linked). */
    private function orderSignals(int $orderId)
    {
        $linkedSignalIds = \DB::table('t_fin_payment_signal_order')
            ->where('order_id', $orderId)->pluck('signal_id')->all();

        return PaymentSignal::query()
            ->where(function ($q) use ($orderId, $linkedSignalIds) {
                $q->where('matched_order_id', $orderId);
                if (!empty($linkedSignalIds)) {
                    $q->orWhereIn('id', $linkedSignalIds);
                }
            })
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->orderByDesc('id')
            ->get();
    }

    /** The set of order ids a proof covers (bulk links, or the single order). */
    private function coveredOrderIds($signals, int $orderId): array
    {
        $ids = \DB::table('t_fin_payment_signal_order')
            ->whereIn('signal_id', $signals->pluck('id')->all())
            ->pluck('order_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        return empty($ids) ? [$orderId] : $ids;
    }

    /**
     * Whether a one-time balancing discount can be offered for this proof, how
     * much (short amount), and which invoice would receive it. Read-only.
     */
    private function balanceAdjustmentInfo($signals, int $orderId): array
    {
        $off = ['eligible' => false, 'already_applied' => false, 'short_amount' => 0];

        // Any signal covering this order with a read amount — matched OR still
        // "amount differs". Eligibility is computed from the LIVE gap vs the
        // tolerance (not the stored status), so raising the tolerance immediately
        // reveals the button.
        $signal = $signals->first(fn (PaymentSignal $s) =>
            in_array($s->status, [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH], true)
            && $s->extracted_amount !== null);
        if (!$signal) {
            return $off;
        }
        $transfer = (float) $signal->extracted_amount;

        $orderIds = $this->coveredOrderIds($signals, $orderId);
        // Join the invoice's ledger entry: we only touch invoices whose amount has
        // NOT posted to balances yet (pending / pending_l1 = pre-L1).
        $preL1 = [\App\Models\FIN\LedgerModel::STATUS_PENDING, \App\Models\FIN\LedgerModel::STATUS_PENDING_L1];
        $orders = \DB::table('t_crm_prod_order as o')
            ->leftJoin('t_fin_ledger as l', 'l.id', '=', 'o.ledger_transaction_id')
            ->whereIn('o.id', $orderIds)
            ->get(['o.id', 'o.order_number', 'o.total_price', 'o.total_paid', 'o.ledger_transaction_id', 'l.approval_status as ledger_status']);
        if ($orders->isEmpty()) {
            return $off;
        }

        $groupTotal = 0.0;
        foreach ($orders as $o) {
            $groupTotal += round((float) $o->total_price - (float) ($o->total_paid ?? 0), 2);
        }
        $short = round($groupTotal - $transfer, 2); // >0 => customer paid LESS than the invoices

        // Already balanced? (our marker discount on any covered invoice.)
        $existing = \DB::table('t_crm_order_discounts')
            ->whereIn('order_id', $orderIds)->where('coupon_code', 'AUTO_BALANCE')
            ->get(['order_id', 'discount_amount']);
        if ($existing->isNotEmpty()) {
            return ['eligible' => false, 'already_applied' => true,
                    'applied_amount' => round((float) $existing->sum('discount_amount'), 2), 'short_amount' => 0];
        }

        // Only offer for a SHORT gap within the tolerance (which is also the cap
        // on how large a one-click discount can be).
        $tolerance = PaymentProofStatusService::amountTolerance();
        if ($short < 0.5 || $short > $tolerance) {
            return ['eligible' => false, 'already_applied' => false, 'short_amount' => max(0.0, $short)];
        }

        // Target = largest-balance covered invoice that is still pre-L1 (or has no
        // ledger entry) and can absorb the discount.
        $target = null; $targetBal = -1.0;
        foreach ($orders as $o) {
            $ledgerOk = empty($o->ledger_transaction_id) || in_array($o->ledger_status, $preL1, true);
            $bal = round((float) $o->total_price - (float) ($o->total_paid ?? 0), 2);
            if ($ledgerOk && $bal >= $short && $bal > $targetBal) {
                $target = $o; $targetBal = $bal;
            }
        }
        if (!$target) {
            return ['eligible' => false, 'already_applied' => false, 'short_amount' => $short,
                    'reason' => 'covered invoices are already approved, or too small'];
        }

        return [
            'eligible'            => true,
            'already_applied'     => false,
            'short_amount'        => $short,
            'target_order_id'     => (int) $target->id,
            'target_order_number' => $target->order_number,
        ];
    }

    /** 403 JsonResponse unless the caller has L1 or L2 approval rights, else null. */
    private function ensureApprover(Request $request)
    {
        $user = $request->user();
        $ok = $user && (\App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1)
                     || \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2));
        return $ok ? null : response()->json(['success' => false, 'message' => 'You do not have approval rights.'], 403);
    }

    /** Re-run the matcher on the matched signals covering this order. */
    private function rematchOrderSignals($signals, \App\Services\Payments\Signals\PaymentSignalMatcher $matcher): void
    {
        foreach ($signals as $s) {
            if (in_array($s->status, [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH], true)) {
                try {
                    $matcher->rematch($s->fresh());
                } catch (\Throwable $e) {
                    \Log::warning('Balance-discount rematch failed', ['signal_id' => $s->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * POST /admin/payments/order/{orderId}/balance-discount (also mobile /rider/...)
     * Apply the short difference as a one-time fixed discount to the largest
     * covered (un-posted) invoice, then re-match so the proof settles clean.
     */
    public function applyBalanceDiscount(Request $request, int $orderId, \App\Services\Payments\Signals\PaymentSignalMatcher $matcher)
    {
        if ($deny = $this->ensureApprover($request)) {
            return $deny;
        }

        $signals = $this->orderSignals($orderId);
        $info = $this->balanceAdjustmentInfo($signals, $orderId);

        if (!empty($info['already_applied'])) {
            return response()->json(['success' => false, 'message' => 'A balancing discount is already applied.'], 422);
        }
        if (empty($info['eligible'])) {
            return response()->json(['success' => false, 'message' => 'This proof is not eligible for a balancing discount.'], 422);
        }

        $D = round((float) $info['short_amount'], 2);
        $targetId = (int) $info['target_order_id'];
        $userId = $request->user()->id;

        \DB::transaction(function () use ($targetId, $D, $userId) {
            $order = \App\Models\CRM\OrderModel::find($targetId);
            if (!$order) {
                throw new \RuntimeException('Target invoice not found.');
            }
            // Re-check the invoice ledger entry is still pre-L1 (not yet posted to
            // balances) before touching money.
            if ($order->ledger_transaction_id) {
                $st = \DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)->value('approval_status');
                if (!in_array($st, [\App\Models\FIN\LedgerModel::STATUS_PENDING, \App\Models\FIN\LedgerModel::STATUS_PENDING_L1], true)) {
                    throw new \RuntimeException('Invoice already approved — cannot auto-discount.');
                }
            }
            \App\Models\CRM\OrderDiscountModel::create([
                'order_id'        => $order->id,
                'discount_title'  => 'Payment balancing adjustment',
                'discount_amount' => $D,
                'discount_type'   => 'fixed',
                'coupon_code'     => 'AUTO_BALANCE',
                'display_order'   => 999,
                'notes'           => 'Auto-applied to match the received payment (short by Rs ' . number_format($D, 2) . ').',
                'created_by'      => $userId,
            ]);
            $order->discount_total = round((float) $order->discount_total + $D, 2);
            $order->total_price    = round((float) $order->total_price - $D, 2);
            $order->save();
            $order->recalculatePaymentStatus();
            // Mirror the reduction onto the (pre-L1, unposted) invoice ledger entry
            // so the ledger — and the eventual L1 posting — reflect the discounted total.
            if ($order->ledger_transaction_id) {
                \DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)
                    ->decrement('amount', $D, ['updated_at' => now()]);
            }
        });

        $this->rematchOrderSignals($signals, $matcher);

        \Log::info('Payment balancing discount applied', ['order_id' => $targetId, 'amount' => $D, 'by' => $userId]);

        return response()->json([
            'success'             => true,
            'applied_amount'      => $D,
            'target_order_id'     => $targetId,
            'target_order_number' => $info['target_order_number'] ?? null,
        ]);
    }

    /**
     * POST /admin/payments/order/{orderId}/balance-discount/remove (also mobile)
     * Reverse the one-time balancing discount(s) on this proof's invoices.
     */
    public function removeBalanceDiscount(Request $request, int $orderId, \App\Services\Payments\Signals\PaymentSignalMatcher $matcher)
    {
        if ($deny = $this->ensureApprover($request)) {
            return $deny;
        }

        $signals = $this->orderSignals($orderId);
        $orderIds = $this->coveredOrderIds($signals, $orderId);

        $discounts = \App\Models\CRM\OrderDiscountModel::whereIn('order_id', $orderIds)
            ->where('coupon_code', 'AUTO_BALANCE')->get();
        if ($discounts->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No balancing discount to remove.'], 422);
        }

        // Refuse if any affected invoice has since been approved (its amount is now
        // posted to balances — undoing here would drift the ledger).
        $preL1 = [\App\Models\FIN\LedgerModel::STATUS_PENDING, \App\Models\FIN\LedgerModel::STATUS_PENDING_L1];
        foreach ($discounts as $d) {
            $order = \App\Models\CRM\OrderModel::find($d->order_id);
            if ($order && $order->ledger_transaction_id) {
                $st = \DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)->value('approval_status');
                if (!in_array($st, $preL1, true)) {
                    return response()->json(['success' => false, 'message' => 'That invoice was already approved; the discount can no longer be auto-removed.'], 422);
                }
            }
        }

        \DB::transaction(function () use ($discounts) {
            foreach ($discounts as $d) {
                $order = \App\Models\CRM\OrderModel::find($d->order_id);
                if ($order) {
                    $order->discount_total = round(max(0.0, (float) $order->discount_total - (float) $d->discount_amount), 2);
                    $order->total_price    = round((float) $order->total_price + (float) $d->discount_amount, 2);
                    $order->save();
                    $order->recalculatePaymentStatus();
                    if ($order->ledger_transaction_id) {
                        \DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)
                            ->increment('amount', (float) $d->discount_amount, ['updated_at' => now()]);
                    }
                }
                $d->delete();
            }
        });

        $this->rematchOrderSignals($signals, $matcher);

        \Log::info('Payment balancing discount removed', ['order_ids' => $orderIds, 'count' => $discounts->count()]);

        return response()->json(['success' => true, 'removed' => $discounts->count()]);
    }
}
