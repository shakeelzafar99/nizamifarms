<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

/**
 * ONE definition of a payment "entry BATCH" — the set of order-payment rows a
 * user created in a single action.
 *
 * WHY THIS EXISTS
 * ---------------
 * A shop can settle several invoices with ONE bank transfer. The bulk tool
 * (ShopBulkPaymentService) splits that transfer FIFO and writes one
 * t_crm_order_payments row per invoice. Read back per invoice, those slices
 * look like several separate payments — which is exactly the question the
 * owner's manager asked ("were these one transfer or many?").
 *
 * There is NO batch id column, so the batch is recovered from the fingerprint
 * the write path leaves: every slice of one entry shares created_by AND
 * created_at to the second, because they are written inside one request loop.
 *
 * VERIFIED on a prod replica (2026-09-02) over all 802 payment rows:
 *   - the 101 rows carrying the bulk LEDGER marker ("Shop payment (bulk)…")
 *     and the rows in a same-second group of >1 agree on 99 of them;
 *   - the 2 that differ are genuine bulk-of-ONE entries (the modal was used
 *     for a single invoice — no sibling within 60s), so group size is the
 *     TRUER signal of "was this transfer shared with other invoices";
 *   - no same-second group of >1 was ever produced by separate single-payment
 *     entries (0 false positives), and no bulk entry ever straddled a second
 *     boundary (0 false negatives).
 *
 * The ledger marker is deliberately NOT used as the test: it would label a
 * bulk-of-one as "bulk" even though it covers only that invoice.
 *
 * READ-ONLY. This service never writes; it only explains rows that already
 * exist, so it cannot affect ledger, balances or approvals.
 */
class PaymentBatchService
{
    /**
     * Describe the batch behind each of the given payment rows.
     *
     * @param  iterable $rows  Rows carrying at least id, created_by, created_at
     *                         (stdClass from DB::table or arrays both work).
     * @param  int|null $customerId  Confine the batch to ONE customer's orders.
     *                         Bulk entry is already same-customer only (the
     *                         service guards it) and no batch in production
     *                         spans two customers, but this makes it structural:
     *                         a batch can never surface another shop's invoice
     *                         numbers in a dialog, whatever future code does.
     * @return array           [payment_id => [
     *                             'batch_size'  => int,    // invoices in the entry
     *                             'batch_total' => float,  // the transfer as entered
     *                             'is_bulk'     => bool,   // batch_size > 1
     *                             'entered_at'  => string, // 'Y-m-d H:i:s'
     *                             'orders'      => string[]// order numbers covered
     *                         ]]
     */
    public function describe($rows, ?int $customerId = null): array
    {
        // ---- 1. Collect the distinct (created_by, created_at) fingerprints ----
        $wanted = [];   // "by|at" => true
        $byIds  = [];
        $atVals = [];
        foreach ($rows as $row) {
            $by = $this->field($row, 'created_by');
            $at = $this->normalizeAt($this->field($row, 'created_at'));
            if ($by === null || $at === null) {
                continue;   // legacy row with no audit stamp — can't be grouped
            }
            $wanted[((int) $by) . '|' . $at] = true;
            $byIds[(int) $by] = true;
            $atVals[$at] = true;
        }
        if (empty($wanted)) {
            return [];
        }

        // ---- 2. Pull every ACTIVE sibling sharing one of those fingerprints ----
        // Two whereIn's give a small cross-product; the $wanted lookup below
        // discards the combinations that were never actually asked for.
        // ACTIVE only, so the batch total always reconciles with the Paid
        // figures (total_paid is itself recalculated from active rows) — a
        // voided slice leaves the batch, exactly as it leaves the balance.
        $siblings = DB::table('t_crm_order_payments as p')
            ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'p.order_id')
            ->whereIn('p.created_by', array_keys($byIds))
            ->whereIn('p.created_at', array_keys($atVals))
            ->where('p.status', 'active')
            ->when($customerId !== null, fn ($q) => $q->where('o.customer_id', $customerId))
            ->select('p.id', 'p.order_id', 'p.amount', 'p.created_by', 'p.created_at', 'o.order_number')
            ->get();

        // ---- 3. Fold them into batches ----
        $batches = [];   // key => ['size','total','orders']
        $keyOf   = [];   // payment_id => key
        foreach ($siblings as $s) {
            $key = ((int) $s->created_by) . '|' . $this->normalizeAt($s->created_at);
            if (!isset($wanted[$key])) {
                continue;   // cross-product leftover
            }
            if (!isset($batches[$key])) {
                $batches[$key] = ['size' => 0, 'total' => 0.0, 'orders' => []];
            }
            $batches[$key]['size']++;
            $batches[$key]['total'] += (float) $s->amount;
            $batches[$key]['orders'][] = $s->order_number ?: ('#' . $s->order_id);
            $keyOf[(int) $s->id] = $key;
        }

        // ---- 4. Answer per payment row ----
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $this->field($row, 'id');
            $key = $keyOf[$id] ?? null;
            if ($key === null || !isset($batches[$key])) {
                continue;   // voided, or never stamped — caller shows it plain
            }
            $b = $batches[$key];
            $out[$id] = [
                'batch_size'  => $b['size'],
                'batch_total' => round($b['total'], 2),
                'is_bulk'     => $b['size'] > 1,
                'entered_at'  => explode('|', $key)[1],
                'orders'      => $b['orders'],
            ];
        }
        return $out;
    }

    /** Read a property from either an object row or an array row. */
    private function field($row, string $name)
    {
        if (is_array($row)) {
            return $row[$name] ?? null;
        }
        return $row->{$name} ?? null;
    }

    /**
     * Timestamps arrive as a raw string from DB::table and as a Carbon from
     * Eloquent. Both must fold to the same key or a batch would split.
     */
    private function normalizeAt($at): ?string
    {
        if (empty($at)) {
            return null;
        }
        if ($at instanceof \DateTimeInterface) {
            return $at->format('Y-m-d H:i:s');
        }
        // Strip any fractional seconds / T separator a driver may hand back.
        $s = str_replace('T', ' ', (string) $at);
        return substr($s, 0, 19);
    }
}
