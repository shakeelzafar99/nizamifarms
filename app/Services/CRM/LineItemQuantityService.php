<?php

namespace App\Services\CRM;

use App\Models\CRM\OrderLineItemModel;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Updates a single order line item's quantity IN PLACE (stable line-item id),
 * mirroring the relevant side effects of OrderController::update for one line:
 *   - recompute that line's line_subtotal / line_total
 *   - recompute the order subtotal_price / total_price (same formula)
 *   - adjust inventory if the line was already deducted (restore old, deduct new)
 *   - stamp who / when / source (+ raw barcode) for the "barcode vs manual" badge
 *
 * Deliberately refuses to run on non-open or already-invoiced (ledger-posted)
 * orders — at weighing time open orders carry no ledger entry, so the delicate
 * ledger-adjustment path in OrderController::update is intentionally never
 * touched here (owner rule: invoiced/delivered orders are edited manually).
 *
 * Aug-2026: also owns setFree() — the same in-place, same-guardrails treatment
 * for a line's "free" flag, so every mid-flight money edit to a live order's
 * line items goes through ONE place with ONE set of refusals.
 */
class LineItemQuantityService
{
    /**
     * Statuses where the order is CLOSED and qty must be edited manually.
     * Mirrors the store open-orders list (getStoreOpenOrders / *Light), which shows
     * any order whose status is NOT in this set — so the scanner accepts exactly the
     * orders that appear in that list, regardless of which active status they're in
     * (new, pending, confirmed, preparing, out_for_delivery, on_hold, …).
     */
    public const CLOSED_STATUSES = ['delivered', 'completed', 'cancelled', 'refunded'];

    /**
     * @return array{success:bool, code?:string, message:string}
     */
    public function setQuantity(
        OrderModel $order,
        OrderLineItemModel $lineItem,
        float $newQuantity,
        string $source,
        ?int $userId,
        ?string $scannedBarcode = null
    ): array {
        if ((int) $lineItem->order_id !== (int) $order->id) {
            return ['success' => false, 'code' => 'mismatch', 'message' => 'Line item does not belong to this order.'];
        }
        if (in_array($order->order_status, self::CLOSED_STATUSES, true)) {
            return ['success' => false, 'code' => 'not_open', 'message' => 'This order is already ' . $order->order_status . ' — please edit the quantity manually.'];
        }
        if (!empty($order->ledger_transaction_id)) {
            // Already invoiced — avoid touching the ledger from the scan path.
            return ['success' => false, 'code' => 'invoiced', 'message' => 'This order is already invoiced. Please edit the quantity manually.'];
        }
        if ($newQuantity <= 0) {
            return ['success' => false, 'code' => 'bad_qty', 'message' => 'Quantity must be greater than zero.'];
        }

        $oldQuantity = (float) ($lineItem->quantity ?? 0);
        $previousSource = $lineItem->quantity_source;

        try {
            DB::transaction(function () use ($order, $lineItem, $newQuantity, $source, $userId, $scannedBarcode) {
                // Mutate this one line, then recompute the order totals once.
                $this->applyLineQuantity($lineItem, $newQuantity, $source, $userId, $scannedBarcode);
                $this->recomputeOrderTotals($order);
            });
        } catch (\Throwable $e) {
            Log::error('LineItemQuantityService::setQuantity failed', [
                'order_id' => $order->id,
                'line_item_id' => $lineItem->id,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'code' => 'error', 'message' => 'Failed to update quantity. Please try again.'];
        }

        $unchanged = abs($oldQuantity - $newQuantity) < 0.0005;

        return [
            'success' => true,
            'message' => $unchanged ? 'Quantity already matches.' : 'Quantity updated.',
            'unchanged' => $unchanged,
            'line_item_id' => (int) $lineItem->id,
            'previous_quantity' => $oldQuantity,
            'previous_source' => $previousSource,
            'new_quantity' => (float) $lineItem->quantity,
            'quantity_source' => $source,
            'line_total' => (float) $lineItem->line_total,
            'order_subtotal' => (float) $order->subtotal_price,
            'order_total' => (float) $order->total_price,
        ];
    }

    /**
     * Update SEVERAL line items' quantities in ONE transaction, recomputing the
     * order totals once at the end. Used by the mobile multi-row manual entry
     * ("Save all"). All-or-nothing: every item is validated before anything is
     * mutated, so a single bad row saves nothing.
     *
     * @param array $items list of [
     *     'line_item_id' => int,
     *     'quantity'     => float,   // already weight-factor-adjusted by the caller
     *     'source'       => string,  // 'manual' | 'barcode'
     *     'scanned_barcode' => ?string,
     * ]
     * @return array{success:bool, code?:string, message:string, updated?:int, items?:array, order_subtotal?:float, order_total?:float}
     */
    public function setQuantitiesBatch(OrderModel $order, array $items, ?int $userId): array
    {
        if (in_array($order->order_status, self::CLOSED_STATUSES, true)) {
            return ['success' => false, 'code' => 'not_open', 'message' => 'This order is already ' . $order->order_status . ' — please edit the quantity manually.'];
        }
        if (!empty($order->ledger_transaction_id)) {
            return ['success' => false, 'code' => 'invoiced', 'message' => 'This order is already invoiced. Please edit the quantity manually.'];
        }
        if (empty($items)) {
            return ['success' => false, 'code' => 'empty', 'message' => 'No quantities to update.'];
        }

        // Load the target line items (belonging to THIS order) keyed by id.
        $ids = array_values(array_unique(array_map(fn($i) => (int) ($i['line_item_id'] ?? 0), $items)));
        $lineItems = OrderLineItemModel::where('order_id', $order->id)
            ->whereIn('id', $ids)->get()->keyBy('id');

        // Validate EVERY requested line before mutating anything (all-or-nothing).
        $plan = [];
        foreach ($items as $i) {
            $lid = (int) ($i['line_item_id'] ?? 0);
            $qty = (float) ($i['quantity'] ?? 0);
            $li = $lineItems->get($lid);
            if (!$li) {
                return ['success' => false, 'code' => 'mismatch', 'message' => 'One of the items does not belong to this order. Nothing was saved.'];
            }
            if ($qty <= 0) {
                return ['success' => false, 'code' => 'bad_qty', 'message' => 'Each quantity must be greater than zero. Nothing was saved.'];
            }
            $plan[] = [
                'li' => $li,
                'qty' => $qty,
                'source' => (($i['source'] ?? 'manual') === 'barcode') ? 'barcode' : 'manual',
                'barcode' => $i['scanned_barcode'] ?? null,
                'previous_quantity' => (float) ($li->quantity ?? 0),
            ];
        }

        try {
            DB::transaction(function () use ($order, $plan, $userId) {
                foreach ($plan as $p) {
                    $this->applyLineQuantity($p['li'], $p['qty'], $p['source'], $userId, $p['barcode']);
                }
                // Recompute the order totals ONCE after all lines are applied.
                $this->recomputeOrderTotals($order);
            });
        } catch (\Throwable $e) {
            Log::error('LineItemQuantityService::setQuantitiesBatch failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'code' => 'error', 'message' => 'Failed to update quantities. Please try again.'];
        }

        $results = [];
        foreach ($plan as $p) {
            $li = $p['li'];
            $results[] = [
                'line_item_id' => (int) $li->id,
                'previous_quantity' => $p['previous_quantity'],
                'new_quantity' => (float) $li->quantity,
                'quantity_source' => $p['source'],
                'line_total' => (float) $li->line_total,
            ];
        }

        return [
            'success' => true,
            'message' => count($results) === 1 ? 'Quantity updated.' : (count($results) . ' quantities updated.'),
            'updated' => count($results),
            'items' => $results,
            'order_subtotal' => (float) $order->subtotal_price,
            'order_total' => (float) $order->total_price,
        ];
    }

    /**
     * Toggle ONE line item's "free" flag in place (the mobile Make Free control,
     * mirroring the web Edit Invoice toggle). Free stores line_total = 0 while
     * KEEPING unit_price, so un-freeing restores the real price exactly.
     *
     * Same guardrails as setQuantity, for the same reason: this moves the order
     * total, so it is refused on closed and on already-invoiced orders rather
     * than reaching into the delicate ledger-adjustment path in
     * OrderController::update (owner rule: invoiced orders are edited manually).
     *
     * Deliberately does NOT touch inventory — making a line free changes its
     * PRICE, never its quantity, so nothing left or returned to stock. Likewise
     * quantity_source / quantity_updated_* are left alone: they drive the
     * "barcode vs manual" quantity badge and say nothing about price.
     *
     * @return array{success:bool, code?:string, message:string}
     */
    public function setFree(
        OrderModel $order,
        OrderLineItemModel $lineItem,
        bool $isFree,
        ?int $userId
    ): array {
        if ((int) $lineItem->order_id !== (int) $order->id) {
            return ['success' => false, 'code' => 'mismatch', 'message' => 'Line item does not belong to this order.'];
        }
        if (in_array($order->order_status, self::CLOSED_STATUSES, true)) {
            return ['success' => false, 'code' => 'not_open', 'message' => 'This order is already ' . $order->order_status . ' — please change this on the web.'];
        }
        if (!empty($order->ledger_transaction_id)) {
            return ['success' => false, 'code' => 'invoiced', 'message' => 'This order is already invoiced. Please change this on the web.'];
        }

        $wasFree = (int) ($lineItem->is_free ?? 0) === 1;

        // Idempotent: a double-tap (or a retry after a dropped response) must
        // not be reported as a failure, and must not re-run the write.
        if ($wasFree === $isFree) {
            return [
                'success' => true,
                'unchanged' => true,
                'message' => $isFree ? 'Item is already free.' : 'Item is already charged.',
                'line_item_id' => (int) $lineItem->id,
                'is_free' => $isFree,
                'line_total' => (float) $lineItem->line_total,
                'order_subtotal' => (float) $order->subtotal_price,
                'order_total' => (float) $order->total_price,
            ];
        }

        try {
            DB::transaction(function () use ($order, $lineItem, $isFree, $userId) {
                $lineItem->is_free = $isFree ? 1 : 0;

                if ($isFree) {
                    $lineItem->line_subtotal = 0;
                    $lineItem->line_total = 0;
                } else {
                    $unit = (float) ($lineItem->unit_price ?? 0);
                    $qty = (float) ($lineItem->quantity ?? 0);
                    $lineItem->line_subtotal = round($unit * $qty, 2);
                    // Mirror getCalculatedTotal(): subtotal - discount + tax.
                    $lineItem->line_total = round(
                        $lineItem->line_subtotal
                        - (float) ($lineItem->discount_amount ?? 0)
                        + (float) ($lineItem->tax_amount ?? 0),
                        2
                    );
                }

                if ($userId) {
                    $lineItem->updated_by = $userId;
                }
                $lineItem->save();

                $this->recomputeOrderTotals($order);
            });
        } catch (\Throwable $e) {
            Log::error('LineItemQuantityService::setFree failed', [
                'order_id' => $order->id,
                'line_item_id' => $lineItem->id,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'code' => 'error', 'message' => 'Failed to update the item. Please try again.'];
        }

        return [
            'success' => true,
            'unchanged' => false,
            'message' => $isFree ? 'Item marked FREE.' : 'Item is now charged.',
            'line_item_id' => (int) $lineItem->id,
            'is_free' => $isFree,
            'previous_is_free' => $wasFree,
            'line_total' => (float) $lineItem->line_total,
            'order_subtotal' => (float) $order->subtotal_price,
            'order_total' => (float) $order->total_price,
        ];
    }

    /**
     * Apply a new quantity to ONE line item in place: inventory restore/deduct,
     * line totals, and the audit stamp (who/when/source + raw barcode). Does NOT
     * open a transaction and does NOT recompute order totals — the caller owns both,
     * so a batch can wrap many calls in one transaction and recompute totals once.
     */
    private function applyLineQuantity(
        OrderLineItemModel $lineItem,
        float $newQuantity,
        string $source,
        ?int $userId,
        ?string $scannedBarcode
    ): void {
        $isFree = (int) $lineItem->is_free === 1;

        // 1) Inventory: if this line was already deducted, restore the OLD qty
        //    (restoreInventory reads $lineItem->quantity, still OLD here).
        $wasDeducted = (bool) $lineItem->inventory_deducted;
        if ($wasDeducted) {
            $lineItem->restoreInventory(); // sets inventory_deducted = 0, saves
        }

        // 2) Apply the new quantity + recompute this line's totals.
        $lineItem->quantity = $newQuantity;
        if ($isFree) {
            $lineItem->line_subtotal = 0;
            $lineItem->line_total = 0;
        } else {
            $unit = (float) ($lineItem->unit_price ?? 0);
            $lineItem->line_subtotal = round($unit * $newQuantity, 2);
            // Mirror getCalculatedTotal(): subtotal - discount + tax.
            $lineItem->line_total = round(
                $lineItem->line_subtotal
                - (float) ($lineItem->discount_amount ?? 0)
                + (float) ($lineItem->tax_amount ?? 0),
                2
            );
        }

        // 3) Stamp source / audit.
        $lineItem->quantity_source = $source;
        $lineItem->quantity_updated_by = $userId;
        $lineItem->quantity_updated_at = now();
        if ($scannedBarcode !== null && $scannedBarcode !== '') {
            $lineItem->quantity_scanned_barcode = substr($scannedBarcode, 0, 20);
        }
        if ($userId) {
            $lineItem->updated_by = $userId;
        }
        $lineItem->save();

        // 4) Re-deduct inventory at the NEW qty if it was deducted before.
        if ($wasDeducted) {
            $lineItem->deductInventory(); // sets inventory_deducted = 1, saves
        }
    }

    /**
     * Recompute + persist the order subtotal_price / total_price from its line
     * items, using the SAME formula as OrderController::update:
     *   subtotal = sum(line_total)  (free items already store line_total = 0)
     *   total    = subtotal - discount_total + shipping_total + tip_amount
     */
    public function recomputeOrderTotals(OrderModel $order): void
    {
        $subtotal = (float) OrderLineItemModel::where('order_id', $order->id)->sum('line_total');
        $discount = (float) ($order->discount_total ?? 0);
        $shipping = (float) ($order->shipping_total ?? 0);
        $tip = (float) ($order->tip_amount ?? 0);

        $order->subtotal_price = round($subtotal, 2);
        $order->total_price = round($subtotal - $discount + $shipping + $tip, 2);
        $order->save();
    }
}
