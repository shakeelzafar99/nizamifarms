<?php

namespace App\Services\WhatsApp\Automation\Handlers;

use App\Models\WhatsApp\AutomationModel;
use App\Services\WhatsApp\Automation\AutomationHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Delivered → storage guidelines" (rule_key: order_delivered_storage_tips).
 *
 * Fires on `order.delivered`, emitted from OrderModel::changeStatus() AFTER the
 * transaction commits (inside app()->terminating(), so the rider's tap / the web
 * save is never held up by a WhatsApp call). Built for the monsoon meat-storage
 * message but deliberately generic: the operator picks WHICH template on the
 * card, so the same rule can carry a summer / winter / any seasonal follow-up.
 *
 * Variables: {{1}} customer first name, {{2}} order number.
 *
 * TWO independent send limits:
 *
 *  1. Send-once PER ORDER — the standard dedup_key contract. A re-save of an
 *     already-delivered order (status set to delivered twice, a bulk re-run)
 *     cannot produce a second message for the same order.
 *
 *  2. Cooldown PER CUSTOMER (config `cooldown_days`, default 30, 0 = off) —
 *     a weekly customer would otherwise get the identical guidelines on every
 *     single delivery. Within the cooldown the rule logs a `skipped` row with
 *     the reason, so the Activity log still shows it was considered.
 *
 * SKIPS: shop / wholesale customers (businesses with their own cold storage —
 * this is consumer home-storage advice), orders with no phone, and orders
 * PLACED more than MAX_ORDER_AGE_DAYS ago (the back-fill guard — a bulk CSV
 * status import can flip a batch of old orders to delivered at once, and that
 * must not blast messages about deliveries that already happened weeks ago).
 */
class DeliveredStorageTipsHandler implements AutomationHandler
{
    /** Default per-customer cooldown when the operator hasn't set one. */
    const DEFAULT_COOLDOWN_DAYS = 30;

    /**
     * Back-fill guard: skip an order PLACED more than this many days before the
     * delivery is recorded. A real order is delivered within days of being
     * placed; a CSV back-fill marks month-old orders delivered NOW. Generous
     * enough for orders booked well in advance.
     */
    const MAX_ORDER_AGE_DAYS = 14;

    /**
     * One message per ORDER. Delivered orders are always PROD orders (a Shopify
     * staging row can't be delivered — it has to be converted first), so an
     * id-based fallback is unambiguous here. Prefixed with `pr:` anyway to keep
     * the key honest about which table it came from.
     */
    public function dedupKey(array $context): string
    {
        $order = $context['order'] ?? null;
        $ref = $order?->order_number ?: ('pr:' . ($order?->id ?? '0'));
        return 'delivered_tips:' . $ref;
    }

    public function eligibility(array $context): ?string
    {
        $order = $context['order'] ?? null;
        if (!$order) {
            return 'no order';
        }

        // Re-read the CURRENT status: we run post-commit / out-of-band, so an
        // order that was un-delivered again in the meantime must not be messaged.
        if (($order->order_status ?? null) !== 'delivered') {
            return 'order is no longer delivered';
        }

        // Back-fill guard — see MAX_ORDER_AGE_DAYS.
        $placedAt = $order->order_date ?: ($order->created_at ?? null);
        if ($placedAt) {
            try {
                if (\Carbon\Carbon::parse($placedAt)->lt(now()->subDays(self::MAX_ORDER_AGE_DAYS))) {
                    return 'order too old (likely a back-fill / bulk import)';
                }
            } catch (\Throwable $e) {
                // Unparseable date — don't block on it.
            }
        }

        // SHOP customers: wholesale buyers with their own cold storage. This is
        // consumer home-freezer advice, so it isn't for them.
        if ($order->customer?->isShop()) {
            return 'shop customer (wholesale)';
        }

        if (!$this->recipientPhone($context)) {
            return 'no phone number';
        }

        // Per-customer cooldown. The dispatcher puts the operator's saved rule
        // into $context['rule'] before calling eligibility(); if it's somehow
        // absent, fall back to the default window rather than sending freely.
        $rule = $context['rule'] ?? null;
        $days = ($rule instanceof AutomationModel)
            ? self::cooldownDays($rule)
            : self::DEFAULT_COOLDOWN_DAYS;

        return $this->cooldownSkip($order, $days);
    }

    /**
     * Cooldown check, split out of eligibility() so the reason string can carry
     * the configured window. Returns a skip reason, or null to proceed.
     */
    protected function cooldownSkip($order, int $days): ?string
    {
        if ($days <= 0) {
            return null; // 0 = send on every delivery
        }
        $customerId = (int) ($order->customer_id ?? 0);
        if ($customerId <= 0) {
            return null; // can't scope a cooldown without a customer — let it through
        }
        if (!Schema::hasTable('t_wa_automation_log')) {
            return null;
        }

        try {
            // Join the log back to PROD orders to find earlier sends for THIS
            // customer. Safe against the Shopify-staging/prod id collision
            // because the rule_key filter restricts rows to this rule, which
            // only ever logs prod order ids (delivered ⇒ prod).
            $recent = DB::table('t_wa_automation_log as l')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->where('l.rule_key', 'order_delivered_storage_tips')
                ->where('l.status', 'sent')
                ->where('l.created_at', '>=', now()->subDays($days))
                ->where('o.customer_id', $customerId)
                ->exists();

            if ($recent) {
                return 'already sent to this customer in the last ' . $days . ' days';
            }
        } catch (\Throwable $e) {
            // FAIL-CLOSED on the cooldown: if we can't prove the customer is
            // outside the window, don't send. The whole point of the cooldown is
            // to prevent repeat spam, so a broken check must not open the gate.
            Log::warning('DeliveredStorageTips: cooldown check failed, skipping send', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return 'cooldown check unavailable';
        }

        return null;
    }

    /**
     * Use the operator-chosen template (rule->template_name) — the dispatcher
     * falls back to it when this returns null. There is no time-of-day or
     * composition routing here: one seasonal message, picked on the card.
     */
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

    /** The configured per-customer cooldown in days (0 = every delivery). */
    public static function cooldownDays(AutomationModel $rule): int
    {
        $cfg = $rule->config();
        if (!array_key_exists('cooldown_days', $cfg)) {
            return self::DEFAULT_COOLDOWN_DAYS;
        }
        return max(0, min(365, (int) $cfg['cooldown_days']));
    }
}
