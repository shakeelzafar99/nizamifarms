<?php

namespace App\Observers;

use App\Models\CRM\OrderModel;
use App\Services\WhatsApp\Automation\Handlers\PaymentChangeInvoiceHandler;
use App\Services\WhatsApp\Automation\WhatsAppAutomationService;
use Illuminate\Support\Facades\Log;

/**
 * Single hook for the "bank details when payment switches to online" automation.
 *
 * All four payment-method change paths (rider mobile, store mobile, web
 * quick-change, web full edit) go through OrderModel::save() on an EXISTING
 * order, so one `updated` observer catches them all. When the change is a
 * NON-online -> online switch on an out_for_delivery order, it emits
 * `order.payment_changed` to the automation dispatcher (which is off by default
 * and gated on the rule being enabled).
 *
 * SAFETY: this runs on EVERY order update, so it is (a) fully wrapped in
 * try/catch — an observer that throws would break the order save — and (b)
 * does only cheap in-memory checks synchronously, deferring the actual WhatsApp
 * work to app()->terminating() (post-response, post-commit). The dispatcher +
 * the handler's eligibility re-verify everything from the order's fresh state.
 */
class OrderPaymentChangeObserver
{
    public function updated(OrderModel $order): void
    {
        try {
            // Only a real payment_method change, on an out-for-delivery order,
            // switching FROM non-online TO online.
            if (!$order->wasChanged('payment_method')) {
                return;
            }
            if (($order->order_status ?? null) !== 'out_for_delivery') {
                return;
            }
            $new = $order->payment_method;
            $old = $order->getOriginal('payment_method');
            if (!PaymentChangeInvoiceHandler::isOnline($new) || PaymentChangeInvoiceHandler::isOnline($old)) {
                return;
            }

            // Cheap early-out: nothing to do while the master switch is off
            // (avoids registering a terminating callback on every such change).
            if (!app(WhatsAppAutomationService::class)->masterEnabled()) {
                return;
            }

            $orderId = $order->id;
            app()->terminating(function () use ($orderId) {
                try {
                    $fresh = OrderModel::with('customer')->find($orderId);
                    if (!$fresh) {
                        return;
                    }
                    app(WhatsAppAutomationService::class)->dispatch('order.payment_changed', [
                        'order'    => $fresh,
                        'order_id' => $orderId,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('WA payment-change automation failed', ['error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
            // NEVER let this break an order save.
            Log::debug('OrderPaymentChangeObserver skipped', ['error' => $e->getMessage()]);
        }
    }
}
