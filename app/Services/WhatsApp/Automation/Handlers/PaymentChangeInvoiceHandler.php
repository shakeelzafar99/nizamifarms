<?php

namespace App\Services\WhatsApp\Automation\Handlers;

use App\Models\WhatsApp\AutomationModel;
use App\Services\WhatsApp\Automation\AutomationHandler;

/**
 * "Bank details when payment switches to online" (rule_key:
 * invoice_on_payment_change). Fires on `order.payment_changed` — emitted by the
 * OrderModel observer when an OUT-FOR-DELIVERY order's payment method is changed
 * TO an online method (by a rider before delivery, or a manager on the web).
 *
 * Sends a TEXT bank-details template (no image — there's no browser at change
 * time). Variables: {{1}} customer name, {{2}} order number. Once per order.
 *
 * eligibility() RE-VERIFIES against the order's CURRENT state (the observer
 * captured the change, but we send post-commit / out-of-band, so re-check that
 * the order is still out_for_delivery + online + has a phone — defends against a
 * rolled-back save or a subsequent flip back to cash).
 */
class PaymentChangeInvoiceHandler implements AutomationHandler
{
    public function dedupKey(array $context): string
    {
        $order = $context['order'] ?? null;
        $ref = $order?->order_number ?: ('id:' . ($order?->id ?? '0'));
        return 'paychange:' . $ref;
    }

    public function eligibility(array $context): ?string
    {
        $order = $context['order'] ?? null;
        if (!$order) {
            return 'no order';
        }
        if (($order->order_status ?? null) !== 'out_for_delivery') {
            return 'order is not out for delivery';
        }
        if (!self::isOnline($order->payment_method ?? '')) {
            return 'payment method is not online';
        }
        if (!$this->recipientPhone($context)) {
            return 'no phone number';
        }
        return null;
    }

    /** Use the operator-chosen template (rule->template_name). */
    public function pickTemplate(array $context, AutomationModel $rule): ?string
    {
        return null;
    }

    public function bodyParams(array $context, AutomationModel $rule): array
    {
        $order = $context['order'] ?? null;
        $name = $order?->customer?->first_name
            ?: ($order?->address_first_name ?: 'there');
        $number = $order?->order_number ?: ('#' . ($order?->id ?? ''));
        return [(string) $name, (string) $number];
    }

    /** Text template — no media header. */
    public function headerParams(array $context, AutomationModel $rule): array
    {
        return [];
    }

    public function recipientPhone(array $context): ?string
    {
        $order = $context['order'] ?? null;
        if (!$order) {
            return null;
        }
        $phone = $order->customer?->phone_normalized
            ?: ($order->customer?->phone_original ?: $order->address_phone);
        return $phone ? (string) $phone : null;
    }

    /** Does a payment_method value mean "online" (vs cash/COD)? */
    public static function isOnline(?string $pm): bool
    {
        $pm = strtolower(trim((string) $pm));
        if ($pm === '') {
            return false;
        }
        if (str_contains($pm, 'cash') || str_contains($pm, 'cod')) {
            return false;
        }
        return true; // online / online_payment / bank_transfer / card / ...
    }
}
