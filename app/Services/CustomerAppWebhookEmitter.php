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
 * 5. Held until dispatched (Aug-2026) — a status the Status Hub
 *    flags with `customer_app_hold_until_dispatch` is masked as
 *    `processing` while the order has no calculated ETA, and the
 *    real status is released by emitDispatchStatus() when Dispatch
 *    is pressed. Applies to customer-app-scope (SH-) orders only.
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

            $isFirstEvent  = $this->isFirstEventForOrder((int) $order->id);
            $rawNfStatus   = $order->order_status;
            $rules         = app(\App\Services\CRM\OrderStatusRuleService::class);

            // Status Hub: a status flagged "don't send to customer app" emits nothing — the
            // customer simply keeps seeing the previous step. The first-ever event is exempt:
            // it is always the 'accepted' acknowledgement so the app learns the order exists.
            if (!$isFirstEvent && !$rules->sendToCustomerApp($rawNfStatus)) {
                return;
            }

            // Status Hub (Aug-2026) — hold until dispatched. `out_for_delivery` is set early,
            // while the order is still being loaded, so a status flagged in the Hub is masked
            // (customer keeps seeing `processing`) until an ETA exists. The real status is
            // pushed by emitDispatchStatus() the moment Dispatch is pressed.
            $isDispatched = $this->orderIsDispatched($order);
            $isHeldBack   = !$isFirstEvent
                && $rules->masksUntilDispatch($order->order_number, $rawNfStatus, $isDispatched);

            // Otherwise send the alias if one is configured, else the raw code (as before).
            $customerStatus = $isFirstEvent
                ? config('customer_app.first_event_status_override', 'accepted')
                : $rules->customerFacingStatus($order->order_number, $rawNfStatus, $isDispatched);

            // previous_status must not leak internal steps either: alias it, and if the
            // previous status was itself hidden from the customer app, send null (from the
            // customer's point of view that step never happened).
            $customerPrevious = null;
            if (!$isFirstEvent && $previousNfStatus !== null && $previousNfStatus !== '') {
                $customerPrevious = $rules->sendToCustomerApp($previousNfStatus)
                    ? ($rules->customerAlias($previousNfStatus) ?? $previousNfStatus)
                    : null;
            }

            // A masked step that lands on the status the customer is ALREADY seeing tells
            // them nothing (e.g. on_van -> out_for_delivery, both shown as `processing`), so
            // it is dropped rather than queued as a no-change event. Deliberately scoped to
            // the masked path only: unmasked flows keep emitting exactly what they do today.
            if ($isHeldBack && $customerStatus === $customerPrevious) {
                return;
            }

            $payload = $this->buildPayload($order, $customerStatus, $customerPrevious, $isFirstEvent);

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
    private function isFirstEventForOrder(int $orderId): bool
    {
        return !DB::table('t_app_webhook_events')
            ->where('order_id', $orderId)
            ->where('target', 'customer_app')
            ->exists();
    }

    /**
     * Has this order actually been dispatched — i.e. has a route ETA been calculated
     * for it? Read fresh from the row rather than the in-memory model, because
     * changeStatus() may have just rewritten the stamp and a stale attribute would
     * mis-classify the order.
     *
     * Fails OPEN (true = "dispatched" = no masking): if we cannot tell, the customer
     * app gets exactly what it got before this feature existed.
     */
    private function orderIsDispatched(OrderModel $order): bool
    {
        try {
            return !empty(
                DB::table('t_crm_prod_order')->where('id', $order->id)->value('eta_calculated_at')
            );
        } catch (\Throwable $e) {
            return true;
        }
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
     * The order has just been DISPATCHED — release the status the customer app has
     * been held back from (Aug-2026).
     *
     * Called from RiderController::calculateDeliveryEtas once per stop, right after
     * `estimated_delivery_at` is written and ONLY on that stop's first dispatch.
     * This is the moment `out_for_delivery` becomes true for the customer: ops set
     * the status early (order still being loaded), the emitter masked it as
     * `processing`, and now there is a real rider, a real route and a real ETA.
     *
     * Emitted BEFORE the eta_updated event for the same order so the outbox — drained
     * in id order — delivers status-then-ETA.
     *
     * Unlike emitEtaUpdate() this is a STATUS event, so it is NOT gated by
     * `emit_eta_updates`; it is gated by the Hub's hold flag instead. If the flag is
     * off, the real status already went out at status-change time and emitting here
     * would duplicate it — so this becomes a no-op. Same non-fatal contract as
     * everything else in this class.
     *
     * @param int    $orderId
     * @param string $orderNumber          NF order_number (e.g. SH-1234).
     * @param string $rawNfStatus          The order's current raw NF status.
     * @param mixed  $estimatedDeliveryAt  The ETA just calculated for this stop.
     */
    public function emitDispatchStatus(
        int $orderId,
        string $orderNumber,
        string $rawNfStatus,
        $estimatedDeliveryAt
    ): void {
        try {
            if (!config('customer_app.enabled', true)) {
                return;
            }
            if (!$this->orderNumberInScope($orderNumber)) {
                return;
            }

            $rules = app(\App\Services\CRM\OrderStatusRuleService::class);

            // Was this status being held from the customer (i.e. pre-dispatch)? If not,
            // they already have the real status and there is nothing to release.
            if (!$rules->masksUntilDispatch($orderNumber, $rawNfStatus, false)) {
                return;
            }
            if (!$rules->sendToCustomerApp($rawNfStatus)) {
                return;
            }

            // Preserve the contract's "the first thing you ever hear about an order is
            // 'accepted'" invariant, even in the pathological case where no earlier event
            // was ever queued for this order.
            $isFirstEvent = $this->isFirstEventForOrder($orderId);

            $customerStatus = $isFirstEvent
                ? (string) config('customer_app.first_event_status_override', 'accepted')
                : ($rules->customerAlias($rawNfStatus) ?? $rawNfStatus);

            // What they have been seeing all this time (the masked value).
            $customerPrevious = $isFirstEvent
                ? null
                : $rules->customerFacingStatus($orderNumber, $rawNfStatus, false);

            if ($customerPrevious === $customerStatus) {
                return; // nothing would change on their timeline
            }

            // Idempotence, independent of the caller. The dispatch loop already skips
            // stops it is merely re-timing, but "cancel dispatch" CLEARS eta_calculated_at
            // — so pressing Dispatch again looks like a first dispatch and would announce
            // out_for_delivery a second time, duplicating a row on the customer's
            // timeline. If the last thing we told them was already this status, stay quiet.
            $lastStatusPayload = DB::table('t_app_webhook_events')
                ->where('order_id', $orderId)
                ->where('target', 'customer_app')
                ->where('event_type', self::EVENT_STATUS_CHANGED)
                ->orderByDesc('id')
                ->value('payload');

            if (!empty($lastStatusPayload)) {
                $lastStatus = json_decode($lastStatusPayload, true)['data']['status'] ?? null;
                if ($lastStatus === $customerStatus) {
                    return;
                }
            }

            $stripPrefix = (string) config('customer_app.strip_prefix_for_payload', 'SH-');
            $bareNumber  = $stripPrefix !== '' && Str::startsWith($orderNumber, $stripPrefix)
                ? substr($orderNumber, strlen($stripPrefix))
                : $orderNumber;

            $payload = [
                'event_uuid'  => (string) Str::uuid(),
                'event_type'  => self::EVENT_STATUS_CHANGED,
                'occurred_at' => now()->toIso8601String(),
                'data' => [
                    'order_number'    => $bareNumber,
                    'nf_order_number' => $orderNumber,
                    'status'          => $customerStatus,
                    'previous_status' => $customerPrevious,
                    'changed_at'      => now()->toIso8601String(),
                    // Unlike the early status event, the ETA EXISTS by now — so this
                    // event carries the delivery window the customer actually wants.
                    'eta_window'      => config('customer_app.eta_window_enabled', true)
                        ? $this->buildEtaWindow($estimatedDeliveryAt)
                        : null,
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
            Log::error('CustomerAppWebhookEmitter::emitDispatchStatus failed (non-fatal)', [
                'order_id'     => $orderId,
                'order_number' => $orderNumber,
                'raw_status'   => $rawNfStatus,
                'error'        => $e->getMessage(),
            ]);
        }
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
     * "Today, 4:10-4:40 PM" / "Tomorrow, 11:50 AM-12:20 PM" / "Jun 17, 4:10-4:40 PM".
     *
     * The start is floored to the nearest `round_to_minutes` (default 10), then
     * the window is `[start .. start + band_minutes]` (default 30). So an ETA of
     * 11:57 -> "11:50 AM-12:20 PM". Uses a plain hyphen (no em dash) per the
     * customer-app spec. Returns null on any parse failure so we never send a
     * malformed/placeholder string.
     */
    private function buildEtaWindow($estimatedDeliveryAt): ?string
    {
        if (empty($estimatedDeliveryAt)) {
            return null;
        }

        try {
            $band = (int) config('customer_app.eta_window_band_minutes', 30);
            $band = $band > 0 ? $band : 30;

            $round = (int) config('customer_app.eta_window_round_to_minutes', 10);
            $round = $round > 0 ? $round : 10;

            $eta           = \Carbon\Carbon::parse($estimatedDeliveryAt)->copy()->second(0);
            $flooredMinute = intdiv($eta->minute, $round) * $round;
            $start         = $eta->copy()->minute($flooredMinute)->second(0);
            $end           = $start->copy()->addMinutes($band);

            $dayQualifier = '';
            if ($start->isToday()) {
                $dayQualifier = 'Today, ';
            } elseif ($start->isTomorrow()) {
                $dayQualifier = 'Tomorrow, ';
            } else {
                $dayQualifier = $start->format('M j') . ', ';
            }

            // Collapse a shared AM/PM meridiem: "4:00-4:30 PM" rather than
            // "4:00 PM-4:30 PM"; keep both when they differ: "11:30 AM-12:00 PM".
            $startMer = $start->format('A');
            $endMer   = $end->format('A');
            $startT   = $start->format('g:i');
            $endT     = $end->format('g:i');

            $window = $startMer === $endMer
                ? "{$startT}-{$endT} {$endMer}"
                : "{$startT} {$startMer}-{$endT} {$endMer}";

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
