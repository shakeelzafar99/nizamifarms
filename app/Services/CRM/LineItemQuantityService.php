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
        $isFree = (int) $lineItem->is_free === 1;

        try {
            $result = DB::transaction(function () use ($order, $lineItem, $newQuantity, $oldQuantity, $source, $userId, $scannedBarcode, $isFree) {
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

                // 5) Recompute + persist order totals from ALL line items (fresh from DB).
                $this->recomputeOrderTotals($order);

                return true;
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
