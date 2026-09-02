<?php

namespace App\Services\WhatsApp\Automation\Handlers;

use App\Models\CRM\OrderModel;
use App\Models\WhatsApp\AutomationModel;
use App\Services\WhatsApp\Automation\AutomationHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Delivered → payment confirmation" (rule_key: order_delivered_payment_confirmation).
 *
 * Automates the message Shabib has been sending BY HAND from Daily Closing
 * (`delivery_confirmation_online`, day-1 button). Fires on `order.delivered`,
 * which OrderModel::changeStatus() emits post-commit for EVERY delivery path —
 * rider app, mobile store mode, web edit form, Status Hub, bulk CSV import and
 * the Qurbani parent auto-promote. Registered AFTER order_delivered_storage_tips
 * in AutomationRegistry, so the customer gets the seasonal storage message first
 * and this one second (rules for one event run in registry order).
 *
 * TWO VARIANTS, chosen from the order's LIVE payment method at send time:
 *   - ONLINE → the confirmation + a "Get bank details" quick-reply button. The
 *     bank accounts are deliberately NOT in the template body any more; tapping
 *     the button gets them as a free-form reply (see
 *     WhatsAppService::maybeAnswerBankDetailsRequest + BankDetailsProvider).
 *   - CASH → the same confirmation, stating cash, no bank details, no button.
 *
 * ⭐ WHY WE RE-READ THE ORDER (owner's explicit requirement): the web Edit Order
 * form can change payment_method AFTER delivery (OrderController::
 * handlePaymentMethodChange reverses and re-posts the ledger entry), so a value
 * captured earlier can be stale by the time we send. resolveOrder() therefore
 * re-fetches the row from the database and every decision below reads THAT — we
 * never trust the model instance handed to us by the event.
 *
 * Variables (both templates, same 4 as the manual send they replace):
 *   {{1}} customer first name  {{2}} order number
 *   {{3}} "Aug 31, 2026 at 03:34 PM"  {{4}} rider name
 *
 * SKIPS: no longer delivered, back-fills (placed > MAX_ORDER_AGE_DAYS before
 * delivery), shop/wholesale customers, KS- warehouse transfers, zero-value
 * orders, no phone, no rider name, an unconfigured template for the variant we
 * need, and — for ONLINE only — an invoice that already has a payment proof or
 * an approved online payment. That last one is the owner's rule: someone who has
 * already paid must not be asked to pay again (a "payment received" thank-you is
 * a separate future rule).
 */
class DeliveredPaymentConfirmationHandler implements AutomationHandler
{
    /**
     * Back-fill guard, same reasoning (and value) as DeliveredStorageTipsHandler:
     * a bulk CSV status import can flip a batch of month-old orders to delivered
     * at once, and those customers must not receive "your order was delivered
     * today, please pay" for deliveries that already happened.
     */
    const MAX_ORDER_AGE_DAYS = 14;

    /** config_json keys written by the rule card (two template pickers). */
    const CFG_ONLINE = 'online_template';
    const CFG_CASH   = 'cash_template';

    /** Memoised fresh order, keyed by id so one instance can't serve another order. */
    protected ?OrderModel $freshOrder = null;
    protected ?int $freshOrderId = null;

    /**
     * One message per ORDER. A delivered order is always a PROD order (a Shopify
     * staging row must be converted before it can be delivered), so there is no
     * cross-table ambiguity here; the `pr:` prefix keeps the key honest anyway.
     */
    public function dedupKey(array $context): string
    {
        $order = $context['order'] ?? null;
        $ref = $order?->order_number ?: ('pr:' . ($order?->id ?? '0'));
        return 'delivered_payconf:' . $ref;
    }

    public function eligibility(array $context): ?string
    {
        $order = $this->resolveOrder($context);
        if (!$order) {
            return 'no order';
        }

        // Re-check the CURRENT status: we run post-commit / out-of-band, so an
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

        // SHOP / wholesale: they run a rolling balance settled FIFO in the Shop
        // tab, so a per-order payment prompt contradicts how they are billed.
        // Same predicate as ApprovalController::excludeShopCustomers and the
        // Daily Closing follow-up panel.
        if ($order->customer?->isShop()) {
            return 'shop customer (billed on a rolling balance)';
        }

        // KS- = Khaas Warehouse internal stock transfers (WarehouseController
        // mints these). They are customer_type='regular' so the shop filter
        // misses them — chasing our own warehouse for payment is meaningless.
        // Flagged as pollution in the Daily Closing panel; filtered here.
        if (str_starts_with(strtoupper(trim((string) $order->order_number)), 'KS-')) {
            return 'internal warehouse transfer (KS-)';
        }

        // Nothing to confirm or collect on a zero-value order.
        if ((float) ($order->total_price ?? 0) <= 0) {
            return 'zero-value order';
        }

        if (!$this->recipientPhone($context)) {
            return 'no phone number';
        }

        // The message names the rider ("by our rider {{4}}"), so a blank name
        // would render a broken sentence to the customer. Better to skip and
        // leave it to the manual Daily Closing button than to send that.
        if ($this->riderName($order) === null) {
            return 'no rider name on the order';
        }

        $isOnline = $this->isOnlineOrder($order);

        // Refuse to send the WRONG variant: without this the dispatcher's
        // `pickTemplate() ?: $rule->template_name` fallback could send the
        // online (bank-details) message to a cash customer.
        if ($this->templateFor($context['rule'] ?? null, $isOnline) === null) {
            return $isOnline ? 'no online template configured' : 'no cash template configured';
        }

        // ONLINE only: don't ask someone to pay who already has.
        if ($isOnline) {
            $paidSkip = $this->alreadyPaidOrProofReceived($order);
            if ($paidSkip !== null) {
                return $paidSkip;
            }
        }

        return null;
    }

    /**
     * Skip reason if this invoice already has a payment proof / is settled, else
     * null.
     *
     * FAIL-OPEN (owner's ruling Aug-31, and identical to
     * PaymentChangeInvoiceHandler): if the proof lookup errors we send anyway.
     * The worst case is one ignorable nudge to someone who already paid — the
     * message itself says "please ignore this if you have already transferred".
     * Silently suppressing a legitimate payment request is the worse failure.
     */
    protected function alreadyPaidOrProofReceived(OrderModel $order): ?string
    {
        try {
            $orderId = (int) ($order->id ?? 0);
            if ($orderId <= 0) {
                return null;
            }

            $svc = app(\App\Services\Payments\Signals\PaymentProofStatusService::class);
            $status = $svc->forOrder($orderId)['status']
                ?? \App\Services\Payments\Signals\PaymentProofStatusService::NONE;
            if ($status !== \App\Services\Payments\Signals\PaymentProofStatusService::NONE) {
                return 'payment proof already received (' . $status . ')';
            }

            // forOrder() reports 'none' once the online payment is APPROVED (the
            // badge is intentionally dropped post-approval), so check settled too.
            if (!empty(\App\Services\Payments\Signals\PaymentProofStatusService::settledOrderIds([$orderId]))) {
                return 'invoice already settled';
            }

            return null;
        } catch (\Throwable $e) {
            Log::debug('DeliveredPaymentConfirmation: proof check skipped (fail-open)', [
                'order_id' => $order->id ?? null,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** Online → the online template; cash → the cash template. Never crosses. */
    public function pickTemplate(array $context, AutomationModel $rule): ?string
    {
        $order = $this->resolveOrder($context);
        if (!$order) {
            return null;
        }
        return $this->templateFor($rule, $this->isOnlineOrder($order));
    }

    public function bodyParams(array $context, AutomationModel $rule): array
    {
        $order = $this->resolveOrder($context);

        $name = $order?->customer?->first_name
            ?: ($order?->address_first_name ?: 'there');
        $number = $order?->order_number ?: ('#' . ($order?->id ?? ''));

        return [
            (string) $name,
            (string) $number,
            (string) $this->deliveredAtLabel($order),
            (string) ($this->riderName($order) ?: 'our rider'),
        ];
    }

    /** Text templates — no media header on either variant. */
    public function headerParams(array $context, AutomationModel $rule): array
    {
        return [];
    }

    public function recipientPhone(array $context): ?string
    {
        $order = $this->resolveOrder($context);
        if (!$order) {
            return null;
        }
        $phone = $order->customer?->phone_normalized
            ?: ($order->customer?->phone_original ?: $order->address_phone);
        return $phone ? (string) $phone : null;
    }

    /**
     * Post-send hook (called by WhatsAppAutomationService via method_exists —
     * NOT part of the AutomationHandler interface, so other handlers are
     * unaffected).
     *
     * Stamps the same `online_message_sent_at` / `_by` columns the MANUAL Daily
     * Closing send stamps, so the two flows share one history:
     *   - the row shows "reminded today" and its Send button is disabled on
     *     delivery day (no double-chasing what we already messaged);
     *   - on day 2 the button re-enables and offers `payment_reminder_single`,
     *     and again on day 3 — because the chase tier is driven by proof /
     *     settlement, never by whether a reminder was sent.
     *
     * ONLINE ONLY. A cash order has nothing outstanding, and both writers of
     * these columns (web + mobile) explicitly reject cash payment methods —
     * stamping one would be inventing a state the rest of the app treats as
     * impossible.
     *
     * SKIPPED IN TEST MODE: when the automations test-phone redirect is active
     * the message went to the operator, not the customer, so marking the real
     * order "reminded" would be a lie that suppresses a genuine chase.
     */
    public function afterSent(array $context, AutomationModel $rule, array $meta = []): void
    {
        if (!empty($meta['test_phone'])) {
            return;
        }

        $order = $this->resolveOrder($context);
        if (!$order || !$this->isOnlineOrder($order)) {
            return;
        }

        try {
            DB::table('t_crm_prod_order')
                ->where('id', (int) $order->id)
                ->update([
                    'online_message_sent_at' => now(),
                    // NULL = sent by the system, not a user. The column is
                    // nullable; the UI renders "reminded <time>" either way.
                    'online_message_sent_by' => null,
                ]);
        } catch (\Throwable $e) {
            // Non-fatal: the message DID go out. Losing the stamp only means
            // Daily Closing offers a (harmless) manual reminder on the same day.
            Log::warning('DeliveredPaymentConfirmation: could not stamp online_message_sent_at', [
                'order_id' => $order->id ?? null,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Re-read the order FRESH from the database (see the class doc-block: the
     * payment method can change after delivery). Memoised per order id so the
     * six interface methods share one query.
     */
    protected function resolveOrder(array $context): ?OrderModel
    {
        $ctxOrder = $context['order'] ?? null;
        $id = (int) ($context['order_id'] ?? ($ctxOrder->id ?? 0));
        if ($id <= 0) {
            return null;
        }

        if ($this->freshOrder && $this->freshOrderId === $id) {
            return $this->freshOrder;
        }

        try {
            $order = OrderModel::with(['customer', 'assignedRider'])->find($id);
        } catch (\Throwable $e) {
            Log::warning('DeliveredPaymentConfirmation: order re-read failed', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
            ]);
            $order = null;
        }

        // Fall back to the event's instance ONLY if the re-read failed outright,
        // so a transient DB hiccup doesn't silently drop the message.
        if (!$order && $ctxOrder instanceof OrderModel) {
            $order = $ctxOrder;
        }

        $this->freshOrder = $order;
        $this->freshOrderId = $id;
        return $order;
    }

    /**
     * Cash or online? Uses the app's CANONICAL classifier
     * (PaymentChangeInvoiceHandler::isOnline — the one CustomerModel::
     * normalizePaymentMethod also delegates to) rather than the Daily Closing
     * panel's closed whereIn list, which would treat an unusual-but-online
     * method (easypaisa, jazzcash) as cash.
     */
    protected function isOnlineOrder(OrderModel $order): bool
    {
        return PaymentChangeInvoiceHandler::isOnline((string) ($order->payment_method ?? ''));
    }

    /** The configured template for this variant, or null when unset. */
    protected function templateFor(?AutomationModel $rule, bool $isOnline): ?string
    {
        if (!$rule instanceof AutomationModel) {
            return null;
        }
        $cfg = $rule->config();
        $name = trim((string) ($cfg[$isOnline ? self::CFG_ONLINE : self::CFG_CASH] ?? ''));
        return $name !== '' ? $name : null;
    }

    /**
     * "Aug 31, 2026 at 03:34 PM" — the moment the order was marked delivered.
     *
     * Read from t_crm_order_status_history exactly as the manual Daily Closing
     * send reads it (OnlineFollowUpService::deliveryTimestamps): the FIRST
     * 'delivered' row, because an order can carry several (re-delivery, status
     * corrections) and the first is when the customer actually received it.
     * There is no delivered_at column on the order — `delivery_date` is an
     * accessor and is date-only, so it cannot supply the time.
     */
    protected function deliveredAtLabel(?OrderModel $order): string
    {
        $fallback = now();
        if (!$order) {
            return $fallback->format('M d, Y') . ' at ' . $fallback->format('h:i A');
        }

        try {
            $changedAt = DB::table('t_crm_order_status_history')
                ->where('order_id', (int) $order->id)
                ->where('status_code', 'delivered')
                ->orderBy('changed_at')
                ->value('changed_at');

            $at = $changedAt ? \Carbon\Carbon::parse($changedAt) : $fallback;
        } catch (\Throwable $e) {
            $at = $fallback;
        }

        return $at->format('M d, Y') . ' at ' . $at->format('h:i A');
    }

    /**
     * The rider to name in the message. `assigned_rider_user_id` is the right
     * field — NOT the status-history `changed_by`, which is the office user who
     * pressed the button when a manager or a CSV import marked the delivery.
     * Returns null when there is no usable name (the caller skips the send).
     */
    protected function riderName(?OrderModel $order): ?string
    {
        if (!$order) {
            return null;
        }
        $name = trim((string) ($order->assignedRider->fullname ?? ''));
        if ($name === '' || strcasecmp($name, 'Unassigned') === 0) {
            return null;
        }
        return $name;
    }
}
