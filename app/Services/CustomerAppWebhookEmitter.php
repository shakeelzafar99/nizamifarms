<?php

namespace App\Services;

use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Customer App Webhook Emitter (Phase 1 — May 2026)
 * =================================================
 *
 * Single responsibility: when an SH- prefixed order changes status,
 * write one row to the t_app_webhook_events outbox. The actual HTTP
 * delivery is handled out-of-band by the
 * `app:dispatch-customer-webhooks` scheduler command.
 *
 * Contract & guarantees
 * ---------------------
 * 1. Non-fatal — every public method swallows its own exceptions
 *    after logging, so a misbehaving emitter cannot break order
 *    status changes anywhere in the codebase. Callers can call
 *    emitStatusChange() and ignore the return.
 *
 * 2. Filtered — only orders whose order_number starts with one of
 *    config('customer_app.order_prefixes') (default: SH-) produce
 *    events. NF- and QUR orders are silently skipped.
 *
 * 3. First-event override — the first row ever inserted for a given
 *    order_id rewrites the customer-facing `status` field to
 *    `accepted`. Every subsequent emit passes the raw NF order_status
 *    verbatim.
 *
 * 4. Master switch — when config('customer_app.enabled') is false the
 *    emitter is still callable but does nothing. (We deliberately
 *    DON'T early-return on missing URL/secret here — those are
 *    enforced by the dispatcher when it tries to send. This way you
 *    can safely deploy this code with the master switch off and
 *    inspect what would be queued via t_app_webhook_events.)
 *
 * Hook point: call emitStatusChange() at the very end of
 * OrderModel::changeStatus(), AFTER the DB transaction has
 * committed. See OrderModel::changeStatus() for the wiring.
 */
class CustomerAppWebhookEmitter
{
    /** Phase 1 emits only this one event type. Future phases will add more. */
    public const EVENT_STATUS_CHANGED = 'order.status_changed';

    /**
     * Queue a status-changed event for delivery to the customer app.
     *
     * @param OrderModel  $order              The order *after* its status was changed.
     * @param string|null $previousNfStatus   The raw NF status the order was in just
     *                                        before this change (null on first ever event
     *                                        for the order).
     */
    public function emitStatusChange(OrderModel $order, ?string $previousNfStatus): void
    {
        try {
            if (!$this->shouldEmit($order)) {
                return;
            }

            $isFirstEvent  = $this->isFirstEventForOrder($order);
            $rawNfStatus   = $order->order_status;
            $customerStatus = $isFirstEvent
                ? config('customer_app.first_event_status_override', 'accepted')
                : $rawNfStatus;

            $payload = $this->buildPayload($order, $customerStatus, $previousNfStatus, $isFirstEvent);

            DB::table('t_app_webhook_events')->insert([
                'event_uuid'      => $payload['event_uuid'],
                'event_type'      => $payload['event_type'],
                'order_id'        => $order->id,
                'order_number'    => $order->order_number,
                'payload'         => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'target'          => 'customer_app',
                'status'          => 'pending',
                'attempts'        => 0,
                'next_attempt_at' => null,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // NEVER propagate. The whole point of the outbox is that
            // status-change callers don't have to worry about delivery.
            Log::error('CustomerAppWebhookEmitter::emitStatusChange failed (non-fatal)', [
                'order_id'       => $order->id ?? null,
                'order_number'   => $order->order_number ?? null,
                'new_nf_status'  => $order->order_status ?? null,
                'previous_nf'    => $previousNfStatus,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decide whether this order is in scope for the customer-app webhook.
     *
     * Returns false (silently) for:
     *   - non-SH orders (NF- manual, QUR Qurbani, raw Shopify staging),
     *   - orders missing an id (defensive — shouldn't happen post-save),
     *   - orders missing an order_status (defensive),
     *   - the entire feature being disabled via config.
     */
    private function shouldEmit(OrderModel $order): bool
    {
        if (!config('customer_app.enabled', true)) {
            return false;
        }

        if (empty($order->id) || empty($order->order_number) || empty($order->order_status)) {
            return false;
        }

        $prefixes = (array) config('customer_app.order_prefixes', ['SH-']);
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && Str::startsWith($order->order_number, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when no prior outbox row exists for this order_id, regardless
     * of delivery status. We deliberately count `failed` and `dead` rows
     * so a temporarily-down customer app doesn't cause us to misclassify
     * the next emit as "first" once it recovers.
     */
    private function isFirstEventForOrder(OrderModel $order): bool
    {
        return !DB::table('t_app_webhook_events')
            ->where('order_id', $order->id)
            ->where('target', 'customer_app')
            ->exists();
    }

    /**
     * Build the canonical JSON payload that will be sent to the customer
     * app. Spec lives in CUSTOMER_APP_INTEGRATION.md — keep both in sync.
     */
    private function buildPayload(
        OrderModel $order,
        string $customerStatus,
        ?string $previousNfStatus,
        bool $isFirstEvent
    ): array {
        $stripPrefix = (string) config('customer_app.strip_prefix_for_payload', 'SH-');
        $bareNumber  = $stripPrefix !== '' && Str::startsWith($order->order_number, $stripPrefix)
            ? substr($order->order_number, strlen($stripPrefix))
            : $order->order_number;

        return [
            'event_uuid'  => (string) Str::uuid(),
            'event_type'  => self::EVENT_STATUS_CHANGED,
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_number'    => $bareNumber,
                'nf_order_number' => $order->order_number,
                'status'          => $customerStatus,
                // On the first event, previous_status is always null —
                // the customer app didn't know about this order yet.
                'previous_status' => $isFirstEvent ? null : $previousNfStatus,
                'changed_at'      => now()->toIso8601String(),
            ],
        ];
    }
}
