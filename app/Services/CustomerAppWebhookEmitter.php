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
    /** Status transitions (Phase 1). */
    public const EVENT_STATUS_CHANGED = 'order.status_changed';

    /**
     * Phase 2 — a pure ETA/delivery-window refresh that must NOT touch the
     * customer app's status timeline. Only emitted when
     * config('customer_app.emit_eta_updates') is true.
     */
    public const EVENT_ETA_UPDATED = 'order.eta_updated';

    /**
     * Per-request guard so we register the post-response drain only once even
     * when many rows are queued in one request (e.g. a bulk status update).
     */
    private static bool $drainScheduled = false;

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

            // Deliver right after the response is flushed (no cron needed).
            // Pass this order so its rows jump ahead of any older backlog.
            $this->scheduleDrain($order->order_number);
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
                // Phase 2 (additive, optional): a coarse human delivery
                // window when we already know the ETA at status-change time.
                // Usually null on the out_for_delivery transition itself
                // (the route ETA is computed shortly after) and arrives via
                // an order.eta_updated event instead. The customer app may
                // ignore this field until it adopts the ETA feature.
                'eta_window'      => $this->buildEtaWindowForOrder($order),
            ],
        ];
    }

    /**
     * Phase 2 — queue an ETA/delivery-window refresh for the customer app.
     *
     * Called after RiderController::calculateDeliveryEtas writes a fresh
     * estimated_delivery_at. Carries the precise ISO ETA (for the live map
     * feed) and the coarse human window (for the order card). Does NOT carry
     * a status, so a correctly-implemented consumer never mutates the
     * timeline from this event.
     *
     * Hard-gated by config('customer_app.emit_eta_updates') — OFF by default
     * — so it cannot disturb a customer-app backend that only knows about
     * status events. Same non-fatal contract as emitStatusChange().
     *
     * @param int         $orderId
     * @param string      $orderNumber          NF order_number (e.g. SH-1234).
     * @param string|null $orderStatus          Current NF order_status.
     * @param mixed       $estimatedDeliveryAt  Datetime/string/Carbon, or null.
     */
    public function emitEtaUpdate(
        int $orderId,
        string $orderNumber,
        ?string $orderStatus,
        $estimatedDeliveryAt
    ): void {
        try {
            if (!config('customer_app.enabled', true)) {
                return;
            }
            if (!config('customer_app.emit_eta_updates', false)) {
                return; // default OFF — see config note.
            }
            if (!$this->orderNumberInScope($orderNumber)) {
                return;
            }
            if (empty($estimatedDeliveryAt)) {
                return; // nothing meaningful to send yet
            }

            $etaIso    = $this->toIso($estimatedDeliveryAt);
            $etaWindow = $this->buildEtaWindow($estimatedDeliveryAt);

            $stripPrefix = (string) config('customer_app.strip_prefix_for_payload', 'SH-');
            $bareNumber  = $stripPrefix !== '' && Str::startsWith($orderNumber, $stripPrefix)
                ? substr($orderNumber, strlen($stripPrefix))
                : $orderNumber;

            $payload = [
                'event_uuid'  => (string) Str::uuid(),
                'event_type'  => self::EVENT_ETA_UPDATED,
                'occurred_at' => now()->toIso8601String(),
                'data' => [
                    'order_number'    => $bareNumber,
                    'nf_order_number' => $orderNumber,
                    'status'          => $orderStatus,
                    'eta'             => $etaIso,        // precise ISO timestamp
                    'eta_window'      => $etaWindow,     // coarse human string
                    'changed_at'      => now()->toIso8601String(),
                ],
            ];

            DB::table('t_app_webhook_events')->insert([
                'event_uuid'      => $payload['event_uuid'],
                'event_type'      => $payload['event_type'],
                'order_id'        => $orderId,
                'order_number'    => $orderNumber,
                'payload'         => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'target'          => 'customer_app',
                'status'          => 'pending',
                'attempts'        => 0,
                'next_attempt_at' => null,
                'created_at'      => now(),
            ]);

            $this->scheduleDrain($orderNumber);
        } catch (\Throwable $e) {
            Log::error('CustomerAppWebhookEmitter::emitEtaUpdate failed (non-fatal)', [
                'order_id'     => $orderId,
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the coarse window for an order, but only when it makes sense to
     * the customer (i.e. the order is out for delivery and we have an ETA).
     */
    private function buildEtaWindowForOrder(OrderModel $order): ?string
    {
        if (!config('customer_app.eta_window_enabled', true)) {
            return null;
        }
        if (($order->order_status ?? '') !== 'out_for_delivery') {
            return null;
        }
        return $this->buildEtaWindow($order->estimated_delivery_at ?? null);
    }

    /**
     * Turn a point ETA into a short, already-formatted display window such as
     * "Today, 4-6 PM" / "Tomorrow, 10 AM-12 PM" / "Jun 17, 4-6 PM".
     *
     * The window is [floor(eta to the hour) .. + band_hours]. Uses a plain
     * hyphen (no em dash) per the customer-app spec. Returns null on any
     * parse failure so we never send a malformed/placeholder string.
     */
    private function buildEtaWindow($estimatedDeliveryAt): ?string
    {
        if (empty($estimatedDeliveryAt)) {
            return null;
        }

        try {
            $band  = (int) config('customer_app.eta_window_band_hours', 2);
            $band  = $band > 0 ? $band : 2;

            $start = \Carbon\Carbon::parse($estimatedDeliveryAt)->copy()->minute(0)->second(0);
            $end   = $start->copy()->addHours($band);

            $dayQualifier = '';
            if ($start->isToday()) {
                $dayQualifier = 'Today, ';
            } elseif ($start->isTomorrow()) {
                $dayQualifier = 'Tomorrow, ';
            } else {
                $dayQualifier = $start->format('M j') . ', ';
            }

            // Collapse a shared AM/PM meridiem: "4-6 PM" rather than
            // "4 PM-6 PM"; keep both when they differ: "11 AM-1 PM".
            $startMer = $start->format('A');
            $endMer   = $end->format('A');
            $startHr  = $start->format('g');
            $endHr    = $end->format('g');

            $window = $startMer === $endMer
                ? "{$startHr}-{$endHr} {$endMer}"
                : "{$startHr} {$startMer}-{$endHr} {$endMer}";

            return $dayQualifier . $window;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** ISO-8601 of a datetime-ish value, or null on failure. */
    private function toIso($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Prefix-scope check that works on a bare order_number string. */
    private function orderNumberInScope(?string $orderNumber): bool
    {
        if (empty($orderNumber)) {
            return false;
        }
        $prefixes = (array) config('customer_app.order_prefixes', ['SH-']);
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && Str::startsWith($orderNumber, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Register a one-shot, fire-and-forget drain of the outbox that runs on
     * app()->terminating() — i.e. AFTER the HTTP response has been flushed to
     * the user. This is the primary delivery path in production, where we
     * cannot rely on a `* * * * * schedule:run` cron existing. The user's
     * status-change request returns instantly; the webhook POST happens in the
     * tail of the same process.
     *
     * Mirrors the proven QurbaniWaAutoSender terminating() pattern. Guarded by
     * a per-request static flag (register once even on bulk updates) and by the
     * dispatcher's own Cache lock (no double-send across concurrent requests).
     * Fully non-fatal — a failure here can never affect the request.
     */
    private function scheduleDrain(?string $priorityOrder = null): void
    {
        if (self::$drainScheduled) {
            return;
        }
        self::$drainScheduled = true;

        try {
            app()->terminating(function () use ($priorityOrder) {
                try {
                    app(\App\Services\CustomerAppWebhookDispatcher::class)
                        ->drainPendingSafely(null, $priorityOrder);
                } catch (\Throwable $e) {
                    Log::warning('CustomerApp drain (terminating) failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Some environments (e.g. certain test harnesses) can't register
            // terminating callbacks — the scheduler fallback still covers us.
            Log::debug('CustomerApp drain hook skipped', ['error' => $e->getMessage()]);
        }
    }
}
