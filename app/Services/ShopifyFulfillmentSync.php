<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Delivered SH- order → mark the origin Shopify order Fulfilled + Paid.
 *
 * HOW THE MAPPING WORKS (important):
 * convertOrder() deliberately NULLs external_id on the live t_crm_prod_order
 * row, so a live "SH-12345" order does NOT carry the Shopify order id the
 * API needs. The link survives via the staging table:
 *
 *   live "SH-12345"  → strip "SH-" → t_crm_shopify_order
 *   WHERE order_number = '12345' AND external_source = 'shopify'
 *   AND converted = 1  → its external_id = the real Shopify order id.
 *
 * Orders renumbered at conversion (QUR..., khaas_storage) never get the SH-
 * prefix and are intentionally out of scope.
 *
 * CALLING CONTRACT: fire-and-forget. Invoked from app()->terminating() in
 * OrderModel::changeStatus so the delivery response is never held up by
 * Shopify HTTP. Every failure here only logs — it must never throw into the
 * delivery flow, and a Shopify outage must never affect NF operations.
 *
 * Idempotent: already-fulfilled fulfillment orders come back "closed" and are
 * skipped; marking an already-paid order paid is logged as a benign no-op.
 */
class ShopifyFulfillmentSync
{
    public function __construct(protected ShopifyService $shopify)
    {
    }

    public function syncDelivered(int $orderId, string $orderNumber): void
    {
        if (!str_starts_with($orderNumber, 'SH-')) {
            return; // not a Shopify-origin order — silent no-op
        }

        $bareNumber = substr($orderNumber, 3);

        // Resolve the origin Shopify order via the staging table (see class doc).
        // converted = 1 guards against manually-typed SH- numbers matching an
        // unrelated staging row.
        $staging = \App\Models\CRM\ShopifyOrderModel::where('external_source', 'shopify')
            ->where('order_number', $bareNumber)
            ->where('converted', 1)
            ->orderByDesc('id')
            ->first();

        if (!$staging || empty($staging->external_id)) {
            Log::info('ShopifySync: no Shopify origin found for delivered order — skipped', [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'bare_number' => $bareNumber,
            ]);
            return;
        }

        $shopifyOrderId = (string) $staging->external_id;

        // 1) Fulfill every open fulfillment order (marks the order "Fulfilled").
        $fulfillmentOrders = $this->shopify->getFulfillmentOrders($shopifyOrderId);
        $fulfilled = 0;
        $skipped = 0;

        foreach ($fulfillmentOrders as $fo) {
            $status = $fo['status'] ?? '';
            if (in_array($status, ['open', 'in_progress'], true)) {
                if ($this->shopify->createFulfillment((int) $fo['id'], false)) {
                    $fulfilled++;
                }
            } else {
                // closed = already fulfilled, cancelled/incomplete/on_hold = not fulfillable
                $skipped++;
                if ($status === 'on_hold') {
                    Log::warning('ShopifySync: fulfillment order is on hold — needs manual release in Shopify', [
                        'order_number' => $orderNumber,
                        'shopify_order_id' => $shopifyOrderId,
                        'fulfillment_order_id' => $fo['id'] ?? null,
                    ]);
                }
            }
        }

        // 2) Mark paid (COD orders otherwise stay "Payment pending" forever).
        //    Runs even when nothing was left to fulfill — the order may have
        //    been fulfilled manually in Shopify but never marked paid.
        $paidResult = $this->shopify->markOrderPaid($shopifyOrderId);

        Log::info('ShopifySync: delivered sync finished', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'shopify_order_id' => $shopifyOrderId,
            'fulfillment_orders_total' => count($fulfillmentOrders),
            'fulfilled' => $fulfilled,
            'skipped' => $skipped,
            'marked_paid' => $paidResult['success'],
            'already_paid' => $paidResult['already_paid'],
            'paid_error' => $paidResult['error'],
        ]);
    }
}
