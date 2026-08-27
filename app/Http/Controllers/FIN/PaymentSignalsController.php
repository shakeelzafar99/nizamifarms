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

        // Provenance for proofs recorded through the NF Assistant (they are
        // whatsapp-source signals but were NOT sent by the customer). One cheap
        // lookup of the confirming user via the draft that produced each signal.
        $assistantIds = $signals->filter(fn ($s) => str_starts_with((string) $s->extractor_version, 'assistant'))->pluck('id');
        $assistantBy = collect();
        if ($assistantIds->isNotEmpty()) {
            $assistantBy = \DB::table('t_ai_drafts as d')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'd.user_id')
                ->where('d.result_type', 'payment_signal')
                ->whereIn('d.result_id', $assistantIds->all())
                ->get(['d.result_id', 'u.fullname'])
                ->keyBy('result_id');
        }

        $payload = $signals->map(function (PaymentSignal $s) use ($expected, $tolerance, $combinedInfo, $last4ToShort, $assistantBy) {
            $combo       = $combinedInfo->get($s->id);
            $isCombined  = $combo && (int) $combo->c > 1;
            $compareBase = $isCombined ? (float) $combo->total : $expected;

            // Build the friendly provenance object (null for real customer proofs).
            $assistant = null;
            if (str_starts_with((string) $s->extractor_version, 'assistant')) {
                $method = match (true) {
                    str_contains($s->extractor_version, 'credit_sms') => 'a bank credit SMS',
                    str_contains($s->extractor_version, 'screenshot')  => 'a forwarded screenshot',
                    default                                            => 'a typed confirmation',
                };
                $assistant = [
                    'method' => $method,
                    'by'     => optional($assistantBy->get($s->id))->fullname ?: 'a manager',
                ];
            }

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
                // Aug-2026 — a hand-entered claim must be readable AS one, and
                // must name the person who vouched for it. The order screen's
                // payment strip and the manual badge both key off these.
                'extractor_version' => $s->extractor_version,
                'is_manual'      => str_starts_with((string) $s->extractor_version, 'manual_'),
                'recorded_by_name' => $s->created_by
                    ? (\DB::table('t_sys_user')->where('id', $s->created_by)->value('fullname') ?: null)
                    : null,
                'when_short'     => optional($s->extracted_txn_datetime ?: $s->created_at)->format('j M, g:i A'),
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
                'assistant'      => $assistant,
                'email_subject'  => $s->email_subject,
                'email_from'     => $s->email_from,
                // Raw text behind the confirmation: the bank email body, or the
                // bank SMS text captured by NF Messages. Same display slot.
                'email_body'     => in_array($s->source, PaymentSignal::BANK_SIDE_SOURCES, true)
                                    ? mb_substr((string) $s->extraction_raw_text, 0, 4000)
                                    : null,
                // Provenance for auto-captured bank SMS ("verified without a tap").
                'bank_sms'       => $s->source === PaymentSignal::SOURCE_BANK_SMS
                                    ? ['auto' => str_starts_with((string) $s->extractor_version, 'bank_sms_auto')]
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
            // ⚠ suppressSettled: false — this panel only opens because someone
            // asked to SEE the proof, so it is a record surface by definition.
            // With the default it headed an approved order's own screenshot and
            // bank SMS with "No proof yet", which is simply false.
            'proof'        => app(PaymentProofStatusService::class)->forOrder($orderId, suppressSettled: false),
            'signals'      => $payload,
            'combined'     => $this->combinedPaymentHint($signals),
            'balance_adjustment' => $this->balanceAdjustmentInfo($signals, $orderId),
            // ⭐ Did this proof ever change hands? On a settled order the panel
            // is the record of WHY it was approved, and "this credit was first
            // read as someone else's, then the payer's own screenshot moved it
            // here" is part of that record. Read-only; empty for the ordinary
            // case where nothing ever moved.
            'moves'        => $this->signalMoveHistory($signals),
            // ⭐ Aug-2026 — the panel already SHOWED "Rs 10,000 (differs from
            // balance Rs 9,120)" but gave no way to act on it, so a manager who
            // deferred the question at entry time had nowhere to answer it
            // later. These two let the panel offer the extra and the record
            // button without a second round trip.
            'overpay'      => $this->overpayForOrder($orderId),
            'can_record'   => $this->canRecordManualPayment(),
        ]);
    }

    /**
     * POST /admin/payments/overpay-batch — overpay for MANY orders at once.
     *
     * A bulk approval settles a whole customer's invoices in one go; asking the
     * server once per invoice would be dozens of round trips for the rare case
     * where one of them was overpaid. Returns ONLY the eligible ones.
     */
    public function overpayBatch(Request $request)
    {
        $validated = $request->validate([
            'order_ids'   => 'required|array|max:200',
            'order_ids.*' => 'integer',
        ]);

        $out = [];
        foreach (array_unique($validated['order_ids']) as $orderId) {
            try {
                $info = $this->overpayForOrder((int) $orderId);
            } catch (\Throwable $e) {
                continue; // one bad order must not sink the whole batch
            }
            // Included when the extra has ANY home left — balance or tip.
            if ((!empty($info['eligible']) || !empty($info['tip_eligible'])) && $info['amount'] > 0) {
                $info['order_id'] = (int) $orderId;
                $info['order_number'] = \DB::table('t_crm_prod_order')
                    ->where('id', $orderId)->value('order_number');
                $out[] = $info;
            }
        }

        return response()->json(['success' => true, 'overpays' => $out]);
    }

    /**
     * If a signal was flagged as a possible COMBINED payment (one transfer that
     * equals the SUM of several open invoices), return the customer's open
     * invoices so the manager can see what it likely covers and split manually.
     * Additive/read-only; null when not a bulk case.
     */
    /**
     * Where these signals have been. Only moves that actually landed on or left
     * an ORDER are shown — the intermediate "held" step of a single heal is
     * plumbing, not history. Silent (empty) until the movement-log SQL is run.
     *
     * @return array<int, array{at:string, from:?string, to:?string, why:?string, by:string}>
     */
    private function signalMoveHistory($signals): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_fin_payment_signal_moves')) {
                return [];
            }
            $ids = collect($signals)->pluck('id')->filter()->all();
            if (empty($ids)) {
                return [];
            }

            $rows = \DB::table('t_fin_payment_signal_moves')
                ->whereIn('signal_id', $ids)
                ->orderBy('id')
                ->limit(50)
                ->get();
            if ($rows->isEmpty()) {
                return [];
            }

            $orderIds = $rows->flatMap(fn ($r) => [$r->from_order_id, $r->to_order_id])->filter()->unique();
            $orders = \DB::table('t_crm_prod_order')->whereIn('id', $orderIds)->pluck('order_number', 'id');
            $users = \DB::table('t_sys_user')->whereIn('id', $rows->pluck('moved_by')->filter()->unique())
                ->pluck('fullname', 'id');

            return $rows
                // A move between two orders, or off/onto one — not a pure
                // held→held bookkeeping step.
                ->filter(fn ($r) => $r->from_order_id || $r->to_order_id)
                ->map(fn ($r) => [
                    'at'   => (string) $r->created_at,
                    'from' => $r->from_order_id ? ($orders[$r->from_order_id] ?? ('#' . $r->from_order_id)) : null,
                    'to'   => $r->to_order_id ? ($orders[$r->to_order_id] ?? ('#' . $r->to_order_id)) : null,
                    'why'  => $r->to_reason ?: $r->from_reason,
                    'by'   => $users[$r->moved_by] ?? 'system',
                ])->values()->all();
        } catch (\Throwable $e) {
            return []; // history is a nicety; never break the proof panel
        }
    }

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

    /**
     * POST /admin/payments/signal/{signalId}/unmark
     *
     * "This isn't this customer's payment." The approver's escape hatch for a
     * match the SYSTEM inferred — an amount coincidence, a resolved payer name,
     * an AI reading. The credit is detached, the money returns to the assistant
     * money inbox as an open question, and the invoice goes back to showing no
     * proof (so it can be approved on its own merits, or wait for the real one).
     *
     * Three deliberate properties:
     *  • ONLY guesses can be unmarked. A pair-verified proof (the customer's own
     *    screenshot corroborated by the bank) is evidence, not an opinion — the
     *    button is not offered for those and the server refuses them too.
     *  • It UNLEARNS. Whatever this tag taught about the payer is deleted, so
     *    the correction sticks instead of being re-made next time (see
     *    CustomerBankAliasService::unlearn).
     *  • It is FINAL for the automation: the reason is recorded as
     *    `guess_dismissed`, which the resweeper is built to skip. Without that,
     *    the very next page load would helpfully put the wrong tag straight
     *    back and the approver would be arguing with a machine.
     */
    public function unmark(Request $request, int $signalId)
    {
        $signal = PaymentSignal::find($signalId);
        if (!$signal) {
            return response()->json(['success' => false, 'message' => 'That payment record no longer exists.'], 404);
        }
        if (!$signal->matched_order_id) {
            return response()->json(['success' => false, 'message' => 'That payment is not attached to any order.'], 422);
        }

        $wasGuess = $signal->isGuess();

        // ⭐⭐ A VERIFIED PAIR IS REMOVABLE TOO. Pairing proves the customer's
        // screenshot and the bank's alert describe ONE REAL TRANSFER — it says
        // nothing about WHOSE INVOICE that transfer settles. A proof can be
        // genuinely verified and still sit on the wrong order: the screenshot
        // may have matched the wrong invoice of the right customer, a manager
        // may have recorded it against the wrong name, or two same-amount
        // payments minutes apart may have been welded to each other. Refusing
        // to remove those left the approver staring at a green badge they knew
        // was wrong with no way to act — so the escape hatch covers every
        // attached signal, and the wording downstream carries the weight.
        //
        // Both sides of a pair are detached together (removing one would leave
        // the other still painting the badge), but the PAIR BOND IS KEPT: they
        // really are the same transaction, so re-pointing one later can carry
        // the verification to the correct order.
        $partner = $signal->paired_signal_id ? PaymentSignal::find($signal->paired_signal_id) : null;

        try {
            \DB::transaction(function () use ($signal, $partner, $wasGuess) {
                $aliases = app(\App\Services\Payments\Signals\CustomerBankAliasService::class);
                $reason  = $wasGuess
                    ? PaymentSignal::REASON_GUESS_DISMISSED
                    : PaymentSignal::REASON_PROOF_DETACHED;

                foreach (array_filter([$signal, $partner]) as $s) {
                    // Whatever this tag taught about the payer rested on it
                    // being right — see CustomerBankAliasService::unlearn.
                    $aliases->unlearnFromSignal($s);

                    \DB::table('t_fin_payment_signal_order')->where('signal_id', $s->id)->delete();

                    $s->matched_order_id    = null;
                    $s->matched_customer_id = null;
                    $s->status              = PaymentSignal::STATUS_UNMATCHED;
                    $s->match_reason        = $reason;
                    $s->match_confidence    = null;
                    $s->save();

                    // Any bank SMS behind this returns to the money inbox, so
                    // the credit is visibly unresolved rather than quietly
                    // closed — that inbox is where it gets re-pointed.
                    \DB::table('t_ai_bank_sms')
                        ->where('linked_signal_id', $s->id)
                        ->update(['status' => 'new', 'auto_reason' => null, 'updated_at' => now()]);
                }
            });
        } catch (\Throwable $e) {
            \Log::error('Payment proof unmark failed', ['signal_id' => $signalId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not remove that payment.'], 500);
        }

        \Log::info('Payment proof detached by an approver', [
            'signal_id' => $signalId,
            'partner_id' => $partner?->id,
            'was_guess' => $wasGuess,
            'by'        => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $partner
                ? 'Removed from this order — both the screenshot and the bank confirmation were detached. The payment is back in the NF Assistant money inbox to be pointed at the right order.'
                : 'Removed. The payment is back in the NF Assistant money inbox to be matched properly.',
        ]);
    }

    /**
     * POST /admin/payments/approval-check   { order_ids: [...] }
     *
     * "Before you approve these — are any of them attached to a payment we're
     * not sure about?" Returns ONLY the rows worth a second of the approver's
     * attention, so the approvals screen can ask a short, rare question instead
     * of a long, constant one.
     *
     * ⭐ WHAT COUNTS AS UNSURE (deliberately narrow):
     *   • the match was INFERRED by the system (a guess reason), AND
     *   • no screenshot/bank pair has since confirmed it, AND
     *   • the payer is a stranger to this customer — no learned alias ties them
     *     together and the two names share nothing.
     *
     * Everything else stays silent. A verified pair, a payment from a name we
     * recognise, a payer already confirmed once — those are not questions, and
     * asking about them is what makes people stop reading the questions. In
     * practice this fires on a handful of credits a week, and each confirmation
     * teaches the payer permanently, so it gets quieter over time.
     *
     * ⚠ Name mismatch alone is NEVER treated as wrong — 16% of payments here
     * come from a relative or colleague. It only means "worth a glance".
     */
    public function approvalCheck(Request $request)
    {
        $ids = collect($request->input('order_ids', []))
            ->map(fn ($i) => (int) $i)->filter()->unique()->take(200)->values();
        if ($ids->isEmpty()) {
            return response()->json(['success' => true, 'items' => []]);
        }

        $resolver = app(\App\Services\Payments\Signals\PayerNameResolver::class);

        $signals = PaymentSignal::query()
            ->whereIn('matched_order_id', $ids)
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->whereIn('match_reason', PaymentSignal::GUESS_REASONS)
            ->whereNull('paired_signal_id')
            ->get();

        if ($signals->isEmpty()) {
            return response()->json(['success' => true, 'items' => []]);
        }

        $orders = \DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.id', $signals->pluck('matched_order_id')->unique())
            ->get(['o.id', 'o.order_number', 'o.total_price', 'o.total_paid', 'c.first_name', 'c.last_name'])
            ->keyBy('id');

        $items = [];
        foreach ($signals as $s) {
            $o = $orders->get($s->matched_order_id);
            if (!$o) {
                continue;
            }
            $customerName = trim(($o->first_name ?? '') . ' ' . ($o->last_name ?? ''));
            $payer = (string) ($s->extracted_sender_name ?? '');

            // Already known to belong together, or plainly the same person —
            // not a question.
            if ($payer !== '' && $s->matched_customer_id) {
                if ($resolver->aliasExists($payer, (int) $s->matched_customer_id)
                    || $resolver->namesResemble($payer, $customerName)) {
                    continue;
                }
            }

            $balance = round((float) $o->total_price - (float) ($o->total_paid ?? 0), 2);
            $items[] = [
                'signal_id'     => (int) $s->id,
                'order_id'      => (int) $o->id,
                'order_number'  => $o->order_number,
                'customer_id'   => (int) ($s->matched_customer_id ?? 0),
                'customer_name' => $customerName,
                'payer_name'    => $payer !== '' ? $payer : null,
                'amount'        => (float) $s->extracted_amount,
                'balance'       => $balance,
                'how'           => match ($s->match_reason) {
                    PaymentSignal::REASON_NAME_AI     => 'Payer name read by AI',
                    PaymentSignal::REASON_NAME_AMOUNT => 'Matched by payer name',
                    default                           => 'Matched by amount only',
                },
            ];
        }

        return response()->json(['success' => true, 'items' => $items]);
    }

    /**
     * POST /admin/payments/signal/{signalId}/confirm-payer
     *
     * "Yes, this really is their payment." Promotes an inferred match to a
     * human-confirmed one and teaches the payer name permanently, so this
     * person is never asked about again — the mechanism by which the approval
     * check gets quieter the more it is used.
     */
    public function confirmPayer(Request $request, int $signalId)
    {
        $signal = PaymentSignal::find($signalId);
        if (!$signal || !$signal->matched_customer_id) {
            return response()->json(['success' => false, 'message' => 'That payment record no longer exists.'], 404);
        }

        $signal->match_reason     = PaymentSignal::REASON_MANUAL_CONFIRMED;
        $signal->match_confidence = 1.00;
        $signal->status           = PaymentSignal::STATUS_MATCHED;
        $signal->save();

        if ($signal->extracted_sender_name) {
            app(\App\Services\Payments\Signals\CustomerBankAliasService::class)->learnFromConfirmation(
                (int) $signal->matched_customer_id,
                $signal->extracted_sender_name,
                $signal->extracted_sender_account_masked,
                auth()->id() ? (int) auth()->id() : null
            );
        }

        // The credit is answered — close its inbox row so the same question is
        // not waiting on the assistant screen too.
        \DB::table('t_ai_bank_sms')->where('linked_signal_id', $signal->id)
            ->update(['status' => 'matched', 'auto_reason' => 'payer_confirmed', 'updated_at' => now()]);

        return response()->json(['success' => true]);
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

    // =================================================================
    //  MANUAL PAYMENT ENTRY  (Aug-2026)
    // =================================================================
    //
    // Recording a payment by hand ASSERTS that money arrived when no proof did.
    // That is a stronger claim than approving a proof someone else produced, so
    // it is deliberately gated tighter than the rest of this controller: the
    // owner's ruling is Shabib and Taimur only.
    //
    // The row itself is EVIDENCE, never money: it goes into t_fin_payment_signal
    // exactly like a WhatsApp screenshot would, and the invoice remains the only
    // thing that moves the ledger. That is what makes this safe to attach to an
    // undelivered order too (the prepayment case) — see hasPreReceivedPayments(),
    // which reads t_crm_order_payments only and therefore cannot be tripped by
    // anything written here.

    /**
     * Public wrapper so a screen can ask BEFORE drawing the button — better to
     * not show an action than to show it and 403 on click. The endpoint still
     * enforces the rule itself; this only decides what is rendered.
     */
    public function userMayRecordManualPayment($user = null): bool
    {
        return $this->canRecordManualPayment($user);
    }

    /** Who is allowed to type a payment in by hand. */
    private function canRecordManualPayment($user = null): bool
    {
        $user = $user ?: auth()->user();
        if (!$user) {
            return false;
        }

        // Config list first — it is the one that can name exactly two people
        // without a deploy (Shabib shares a role with the admin login, so no
        // role can express the pair on its own).
        $allowed = array_filter(array_map(
            fn ($e) => strtolower(trim($e)),
            explode(',', (string) config('payment_signals.manual_entry_emails', ''))
        ));
        if ($allowed && in_array(strtolower((string) $user->email), $allowed, true)) {
            return true;
        }

        // Role fallback, matching how the rest of the app identifies Taimur.
        return $user->roles()
            ->whereRaw('LOWER(urole_name) IN (?, ?)', ['taimur', 'shabib'])
            ->exists();
    }

    /** 403 unless the caller may record a manual payment. */
    private function denyUnlessManualEntry()
    {
        return $this->canRecordManualPayment()
            ? null
            : response()->json([
                'success' => false,
                'message' => 'Only Shabib and Taimur can record a payment by hand.',
            ], 403);
    }

    /**
     * POST /admin/payments/order/{orderId}/manual-proof
     *
     * Record "this customer paid, we just have no screenshot". Writes ONE
     * customer-side signal, pinned to this order and confirmed by a human.
     *
     * ⚠ source stays 'whatsapp' on purpose. It means "the customer's side of
     * the story" and is what lets the existing cross-pairing machinery validate
     * this claim automatically when the bank SMS/email arrives — at which point
     * the badge turns green by itself. A new enum value would silently opt the
     * row out of all of that.
     */
    public function recordManualProof(Request $request, int $orderId)
    {
        if ($deny = $this->denyUnlessManualEntry()) {
            return $deny;
        }

        $validated = $request->validate([
            'amount'               => 'required|numeric|min:1',
            'receiving_account_id' => 'nullable|integer',
            'reference'            => 'nullable|string|max:100',
            'paid_at'              => 'nullable|date',
            'note'                 => 'nullable|string|max:255',
        ]);

        $order = \DB::table('t_crm_prod_order')->where('id', $orderId)
            ->first(['id', 'order_number', 'customer_id', 'total_price', 'total_paid', 'order_status', 'payment_method']);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }
        if ($order->order_status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This order is cancelled — record the money against the order it actually paid for, '
                    . 'or add it to the customer\'s balance from their page.',
            ], 422);
        }

        // ⚠⚠ For an ONLINE order the receiving bank is NOT optional, because the
        // approval itself refuses without one ("Select which bank received this
        // online payment"). Recording the bank here is what populates the proof's
        // suggested bank, which is what lets the invoice be approved — and be
        // included in a BULK approve instead of silently skipped as "no bank".
        // Leaving it blank produced a claim that quietly blocked its own approval.
        $onlineMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];
        if (in_array($order->payment_method, $onlineMethods, true) && empty($validated['receiving_account_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'This is an online order — choose which bank the money arrived in. '
                    . 'Without it the invoice cannot be approved later.',
                'needs_bank' => true,
            ], 422);
        }

        $userId = (int) auth()->id();
        $amount = round((float) $validated['amount'], 2);
        $paidAt = !empty($validated['paid_at']) ? \Carbon\Carbon::parse($validated['paid_at']) : now();

        // Resolve the customer through any merge, so the claim lands on the
        // record that actually answers for this person.
        $customerId = (new \App\Services\CustomerCreditService())->resolveCustomerId($order->customer_id);

        // Same 8-second guard the payment forms use: a double-click must not
        // become two claims for the same money.
        $dup = PaymentSignal::where('matched_order_id', $orderId)
            ->where('extracted_amount', $amount)
            ->where('created_at', '>=', now()->subSeconds(8))
            ->exists();
        if ($dup) {
            return response()->json(['success' => false, 'message' => 'That payment was just recorded — check the proof panel.'], 422);
        }

        // The receiving bank matters beyond display: it feeds the same-bank gate
        // the pairing uses, so a claim tagged with the right bank pairs cleanly.
        $bankShort = null;
        $bankLast4 = null;
        if (!empty($validated['receiving_account_id'])) {
            $bank = \DB::table('t_fin_online_receiving_accounts')
                ->where('id', $validated['receiving_account_id'])
                ->first(['short_code', 'account_last4']);
            $bankShort = $bank->short_code ?? null;
            $bankLast4 = $bank->account_last4 ?? null;
        }

        $signal = PaymentSignal::create([
            'source'                     => PaymentSignal::SOURCE_WHATSAPP,
            'extracted_amount'           => $amount,
            'extracted_ref'              => $validated['reference'] ?? null,
            'extracted_txn_datetime'     => $paidAt,
            'extracted_to_account_short' => $bankShort,
            'extracted_to_account_last4' => $bankLast4,
            'extraction_raw_text'        => $validated['note'] ?? null,
            'extractor_version'          => 'manual_web@v1',
            'created_by'                 => $userId,
            'matched_customer_id'        => $customerId,
            'matched_order_id'           => $orderId,
            // A human pinned this to this order, so it is final: 'manual_confirmed'
            // is a TERMINAL reason that every re-matcher and the resweeper skip.
            'status'                     => PaymentSignal::STATUS_MATCHED,
            'match_reason'               => 'manual_confirmed',
            'match_confidence'           => 1.00,
        ]);

        \Log::info('Manual payment proof recorded', [
            'signal_id' => $signal->id,
            'order_id'  => $orderId,
            'amount'    => $amount,
            'by'        => $userId,
        ]);

        // Tell the caller straight away whether this claim overshoots what the
        // order still owes, so the UI can ask about the extra in the same breath.
        $overpay = $this->overpayForOrder($orderId);

        return response()->json([
            'success'   => true,
            'message'   => 'Recorded Rs ' . number_format($amount, 2) . ' as received.',
            'signal_id' => $signal->id,
            'overpay'   => $overpay,
        ]);
    }

    /**
     * How much MORE than the order still owed has been claimed, counted once.
     *
     * ⚠⚠ THE COUNT-ONCE RULE. The proof panel computes its difference PER
     * SIGNAL, so once a manual claim is validated by its bank SMS there are two
     * rows each reporting the same "+Rs X over". Summing them would double the
     * customer's credit out of thin air. Paired rows are therefore collapsed to
     * one proof (the bank side wins — it is the truth anchor), and only then is
     * the excess measured.
     *
     * @return array{amount:float,signal_id:?int,customer_id:?int,eligible:bool,reason:?string}
     */
    private function overpayForOrder(int $orderId): array
    {
        $none = ['amount' => 0.0, 'signal_id' => null, 'customer_id' => null,
                 'eligible' => false, 'reason' => null,
                 'tip_eligible' => false, 'tip_reason' => null,
                 'claimed' => 0.0, 'owed' => 0.0, 'banked' => 0.0, 'signal_count' => 0];

        $order = \DB::table('t_crm_prod_order')->where('id', $orderId)
            ->first(['id', 'customer_id', 'total_price', 'total_paid', 'order_status', 'ledger_transaction_id']);
        if (!$order) {
            return $none;
        }

        $signals = $this->orderSignals($orderId);
        if ($signals->isEmpty()) {
            return $none;
        }

        // ⚠⚠ COMBINED transfers are EXCLUDED. A bulk payment's amount covers
        // SEVERAL invoices, so comparing it against THIS order's balance alone
        // fabricates a huge "extra" that is really the other invoices' money
        // (proven in testing: a transfer covering two orders exactly reported
        // the whole second invoice as overpay). Their surplus is judged by the
        // combined machinery against the GROUP total, never here.
        $bulkLinked = \DB::table('t_fin_payment_signal_order')
            ->whereIn('signal_id', $signals->pluck('id')->all())
            ->pluck('signal_id')->flip();
        // GUESSED matches are excluded too: their IDENTITY is uncertain, and
        // banking a guessed sender's money to this customer's balance is how
        // someone else's payment becomes the wrong person's credit.
        $signals = $signals->reject(function ($s) use ($bulkLinked) {
            return $bulkLinked->has($s->id)
                || (method_exists($s, 'isGuess') && $s->isGuess());
        })->values();
        if ($signals->isEmpty()) {
            return $none;
        }

        // Collapse cross-source pairs: keep the bank-side row when a pair is
        // present, else the row itself. Keyed by the pair so each real payment
        // is represented exactly once.
        $byId = $signals->keyBy('id');
        $kept = [];
        foreach ($signals as $s) {
            $mateId = $s->paired_signal_id ? (int) $s->paired_signal_id : null;
            $key    = $mateId ? min((int) $s->id, $mateId) . '-' . max((int) $s->id, $mateId) : 'solo-' . $s->id;

            if (!isset($kept[$key])) {
                $kept[$key] = $s;
                continue;
            }
            // Prefer the bank-side reading of the same payment.
            $existing = $kept[$key];
            $sIsBank  = in_array($s->source, PaymentSignal::BANK_SIDE_SOURCES ?? ['email', 'bank_sms'], true);
            $eIsBank  = in_array($existing->source, PaymentSignal::BANK_SIDE_SOURCES ?? ['email', 'bank_sms'], true);
            if ($sIsBank && !$eIsBank) {
                $kept[$key] = $s;
            }
        }

        $claimed = 0.0;
        $latest  = null;
        foreach ($kept as $s) {
            $claimed += (float) $s->extracted_amount;
            if (!$latest || $s->id > $latest->id) {
                $latest = $s;
            }
        }

        $owed = round((float) $order->total_price - (float) ($order->total_paid ?? 0), 2);

        // ⚠⚠ Extra already CONVERTED for this order must come off, or the same
        // rupees are offered again every time new evidence lands. Bank Rs 880
        // of an extra, let another proof arrive, and without this the untouched
        // 880 is counted a second time.
        //
        // The tip route needs no equivalent: a tip raises total_price, so it
        // raises `owed` and shrinks the extra by exactly itself. One formula —
        //     extra = claimed − owed − banked
        // — and both conversions subtract themselves from it.
        $banked = 0.0;
        if (\Schema::hasTable('t_crm_customer_credit')) {
            $banked = round((float) \DB::table('t_crm_customer_credit')
                ->where('order_id', $orderId)
                ->where('entry_type', \App\Models\CRM\CustomerCreditModel::TYPE_GRANT)
                ->where('source', \App\Models\CRM\CustomerCreditModel::SOURCE_OVERPAYMENT)
                ->where('status', '!=', \App\Models\CRM\CustomerCreditModel::STATUS_VOIDED)
                ->sum('amount'), 2);
        }

        $extra = round($claimed - $owed - $banked, 2);

        if ($extra < \App\Services\CustomerCreditService::MIN_GRANT) {
            return $banked > 0
                ? array_merge($none, ['reason' => 'already_added', 'claimed' => $claimed,
                                      'owed' => $owed, 'banked' => $banked, 'signal_count' => count($kept)])
                : $none;
        }

        $credit     = new \App\Services\CustomerCreditService();
        $customerId = $credit->resolveCustomerId($order->customer_id);

        // ⚠ "Already banked" is decided by the ARITHMETIC above (extra =
        // claimed − owed − banked), not by asking whether any signal on this
        // order once produced a grant. The older signal-keyed test refused the
        // whole order the moment ONE proof had been banked — so a genuinely new
        // payment arriving afterwards could never be banked at all. Reaching
        // here means real, unconverted extra remains.
        //
        // Double-banking the SAME proof is still blocked, one layer down:
        // CustomerCreditService::requestGrant refuses a signal (or its pair)
        // that already carries a grant.
        $eligible = $customerId && $credit->isEligibleId($customerId);
        $reason   = !$customerId ? 'no_customer'
                  : (!$credit->isEligibleId($customerId) ? 'not_eligible' : null);

        // 🤍 TIP eligibility — the OTHER thing an extra can become. A tip
        // raises the invoice total, so it is only possible while the invoice
        // can still change: before the ledger entry passes L1 (the same pre-L1
        // rule the balancing discount uses for the short direction). The
        // balance option needs none of this — it never touches the invoice.
        $tipReason = null;
        if ($order->order_status === 'cancelled') {
            $tipReason = 'cancelled';
        } elseif ($order->ledger_transaction_id) {
            $st = \DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)->value('approval_status');
            if (!in_array($st, [\App\Models\FIN\LedgerModel::STATUS_PENDING, \App\Models\FIN\LedgerModel::STATUS_PENDING_L1], true)) {
                $tipReason = 'invoice_approved';
            }
        }

        return [
            'amount'       => $extra,
            'signal_id'    => $latest?->id,
            'customer_id'  => $customerId,
            'eligible'     => (bool) $eligible,
            'reason'       => $reason,
            'tip_eligible' => $tipReason === null,
            'tip_reason'   => $tipReason,
            // The working, so a surprising number can be READ rather than
            // guessed at. `signal_count` > 1 means several payments are being
            // counted together — the usual cause of an extra nobody expects.
            'claimed'      => $claimed,
            'owed'         => $owed,
            'banked'       => $banked,
            'signal_count' => count($kept),
        ];
    }

    /** GET /admin/payments/order/{orderId}/overpay — what extra is on this order. */
    public function overpayInfo(Request $request, int $orderId)
    {
        // Whether a manual entry on THIS order must name the receiving bank.
        // An online invoice cannot be approved without one, so the form asks for
        // it up front rather than letting the claim block its own approval.
        $method = \DB::table('t_crm_prod_order')->where('id', $orderId)->value('payment_method');
        $bankRequired = in_array($method, ['online', 'Online', 'bank_transfer', 'card', 'online_payment'], true);

        // Payments ALREADY recorded by hand on this order. Recording a second
        // one adds to the first (right for a genuine two-part payment, wrong
        // for a re-typed correction), so the dialog must say so BEFORE the
        // amount is typed rather than leaving a surprising total to be
        // discovered in the overpay prompt afterwards.
        $existing = $this->orderSignals($orderId)
            ->filter(fn ($s) => $s->match_reason === 'manual_confirmed')
            ->map(fn ($s) => [
                'id'     => (int) $s->id,
                'amount' => (float) $s->extracted_amount,
                'when'   => (string) $s->created_at,
                'by'     => $s->created_by
                    ? (\DB::table('t_sys_user')->where('id', $s->created_by)->value('fullname') ?: 'someone')
                    : 'someone',
            ])->values()->all();

        return response()->json([
            'success'         => true,
            'overpay'         => $this->overpayForOrder($orderId),
            'can_record'      => $this->canRecordManualPayment(),
            'bank_required'   => $bankRequired,
            'payment_method'  => $method,
            'existing_manual' => $existing,
        ]);
    }

    /**
     * POST /admin/payments/order/{orderId}/overpay-to-balance
     *
     * Move the confirmed extra into the customer's account balance. Creates a
     * PENDING grant — it becomes spendable only when an L2 approver signs it
     * off, exactly like every other way money enters the bucket.
     */
    public function overpayToBalance(Request $request, int $orderId)
    {
        if ($deny = $this->ensureApprover($request)) {
            return $deny;
        }

        $info = $this->overpayForOrder($orderId);
        if (!$info['eligible'] || $info['amount'] <= 0) {
            $msg = match ($info['reason']) {
                'already_added' => 'That extra has already been added to the balance.',
                'not_eligible'  => 'Shop customers do not use account balance.',
                'no_customer'   => 'This order has no customer record.',
                default         => 'There is no extra amount to add.',
            };
            return response()->json(['success' => false, 'message' => $msg], 422);
        }

        try {
            $order = \DB::table('t_crm_prod_order')->where('id', $orderId)->first(['order_number']);
            $credit = (new \App\Services\CustomerCreditService())->requestGrant(
                (int) $info['customer_id'],
                (float) $info['amount'],
                (int) auth()->id(),
                [
                    'order_id'  => $orderId,
                    'signal_id' => $info['signal_id'],
                    'source'    => \App\Models\CRM\CustomerCreditModel::SOURCE_OVERPAYMENT,
                    'reason'    => 'Paid more than order ' . ($order->order_number ?? $orderId),
                ]
            );

            // Auto-approved for Shabib/Taimur (already ACTIVE); pending for others.
            $message = $credit->status === \App\Models\CRM\CustomerCreditModel::STATUS_ACTIVE
                ? 'Rs ' . number_format((float) $info['amount'], 2) . ' added to the customer\'s balance.'
                : 'Rs ' . number_format((float) $info['amount'], 2)
                    . ' sent for approval — it becomes usable balance once approved.';

            return response()->json([
                'success'   => true,
                'message'   => $message,
                'credit_id' => $credit->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/payments/order/{orderId}/overpay-to-tip  (also mobile)
     *
     * The customer's extra becomes a TIP on this invoice: `tip_amount` and
     * `total_price` both rise by the extra, so the payment now matches the
     * invoice exactly and the overpay disappears (it is computed against the
     * order total, which just grew).
     *
     * ⚠ Mirror of applyBalanceDiscount, opposite direction, SAME rule: only
     * while the invoice ledger entry is still pre-L1 (or absent). Once posted
     * to balances the invoice amount is money the ledger already counted —
     * raising it here would drift the books. Past that point the balance
     * option is the right home for the extra, and it stays available.
     */
    public function overpayToTip(Request $request, int $orderId, \App\Services\Payments\Signals\PaymentSignalMatcher $matcher)
    {
        if ($deny = $this->ensureApprover($request)) {
            return $deny;
        }

        $info = $this->overpayForOrder($orderId);
        if (($info['amount'] ?? 0) <= 0) {
            return response()->json(['success' => false, 'message' => 'There is no extra amount on this order.'], 422);
        }
        if (!$info['tip_eligible']) {
            $msg = $info['tip_reason'] === 'invoice_approved'
                ? 'The invoice is already approved, so its total can no longer change — add the extra to the customer\'s balance instead.'
                : 'This order is cancelled — a cancelled invoice cannot take a tip.';
            return response()->json(['success' => false, 'message' => $msg], 422);
        }

        $extra  = round((float) $info['amount'], 2);
        $userId = (int) $request->user()->id;

        try {
            \DB::transaction(function () use ($orderId, $extra, $userId) {
                $order = \App\Models\CRM\OrderModel::lockForUpdate()->find($orderId);
                if (!$order) {
                    throw new \RuntimeException('Order not found.');
                }
                // Re-check under the lock — an approval could have landed since.
                if ($order->ledger_transaction_id) {
                    $st = \DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)->value('approval_status');
                    if (!in_array($st, [\App\Models\FIN\LedgerModel::STATUS_PENDING, \App\Models\FIN\LedgerModel::STATUS_PENDING_L1], true)) {
                        throw new \RuntimeException('The invoice was approved just now — add the extra to the customer\'s balance instead.');
                    }
                }

                $order->tip_amount  = round((float) ($order->tip_amount ?? 0) + $extra, 2);
                $order->total_price = round((float) $order->total_price + $extra, 2);
                $order->save();
                $order->recalculatePaymentStatus();

                // Keep the unposted invoice ledger row at the order's total, so
                // the eventual L1 posting carries the tip too.
                if ($order->ledger_transaction_id) {
                    \DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)
                        ->increment('amount', $extra, ['updated_at' => now()]);
                }

                \Log::info('Overpayment recorded as tip', [
                    'order_id' => $orderId, 'amount' => $extra, 'by' => $userId,
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // The proof badge should turn green on its own now that amounts agree.
        $this->rematchOrderSignals($this->orderSignals($orderId), $matcher);

        $newTotal = \DB::table('t_crm_prod_order')->where('id', $orderId)->value('total_price');

        return response()->json([
            'success' => true,
            'message' => 'Rs ' . number_format($extra, 2) . ' recorded as a tip — the invoice total is now Rs '
                . number_format((float) $newTotal, 2) . '.',
        ]);
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
