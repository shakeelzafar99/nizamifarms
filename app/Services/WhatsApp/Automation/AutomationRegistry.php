<?php

namespace App\Services\WhatsApp\Automation;

/**
 * The code-defined CATALOG of event-triggered WhatsApp automations.
 *
 * This is the single source of truth for "what automations exist and which of
 * their knobs the operator may edit". The DB (t_wa_automations) only stores the
 * operator's per-rule choices; this class defines the rule TYPES.
 *
 * Each descriptor:
 *   key            - stable id, also the t_wa_automations.rule_key.
 *   tab            - which UI tab it appears under ('order_actions' | 'invoice').
 *   label          - short title shown on the card.
 *   description    - plain-English "how this rule works", shown to the operator
 *                    so they always know what a code-built rule is doing.
 *   trigger_summary- one line describing when it fires.
 *   event          - the internal event key the dispatcher listens for.
 *   editable       - the ONLY knobs the front end may expose for this rule.
 *                    Everything else (trigger, recipient, variable mapping,
 *                    skip rules) is fixed in code.
 *   template       - what shape of template this rule needs (so the picker can
 *                    offer only compatible templates) + a suggested default name.
 *   available      - false until the trigger seam + handler ship (Phase 2/3).
 *                    The UI shows unavailable rules as read-only "coming soon",
 *                    and the save endpoint refuses to enable them.
 *   handler        - the AutomationHandler class (null until available).
 *
 * SAFETY: there is intentionally no concept of "default on" here. A rule is
 * only ever active when the operator explicitly enables it AND the master
 * switch is on AND `available` is true.
 */
class AutomationRegistry
{
    public static function all(): array
    {
        return [
            [
                'key'   => 'order_received_new',
                'tab'   => 'order_actions',
                'label' => 'Order received — new customer',
                'description' =>
                    "SHOPIFY-RECEIVED ORDERS ONLY (not orders you create in open-orders). Sends a WhatsApp "
                    . "template to a customer placing their FIRST-EVER order with us, the moment it lands in "
                    . "the Shopify approval queue. The exact template is chosen from a small schedule by WHEN "
                    . "the order arrived — working hours, outside hours, the Tuesday day-off, etc. The "
                    . "customer's name and order number fill in automatically. Skips silently if the order "
                    . "has no phone number or was already acknowledged. Sends once per order.",
                'trigger_summary' => "When a new customer's first Shopify order arrives",
                'event'    => 'order.created',
                'editable' => ['enabled', 'schedule'],
                'template' => ['body_vars' => 2, 'header' => 'none', 'default' => 'order_received_offhours_new'],
                'available' => true,
                'handler'  => \App\Services\WhatsApp\Automation\Handlers\NewCustomerOrderReceivedHandler::class,
            ],
            [
                'key'   => 'order_received_existing',
                'tab'   => 'order_actions',
                'label' => 'Order received — existing customer',
                'description' =>
                    "SHOPIFY-RECEIVED ORDERS ONLY. Same as the new-customer rule but for a RETURNING "
                    . "customer (one who already has an order history with us). Lets you send a different, "
                    . "more familiar acknowledgement. Template chosen from its own time-of-day schedule; "
                    . "name + order number filled automatically. Sends once per order.",
                'trigger_summary' => "When a returning customer's Shopify order arrives",
                'event'    => 'order.created',
                'editable' => ['enabled', 'schedule'],
                'template' => ['body_vars' => 2, 'header' => 'none', 'default' => 'order_received_workinghours'],
                'available' => true,
                'handler'  => \App\Services\WhatsApp\Automation\Handlers\ExistingCustomerOrderReceivedHandler::class,
            ],
            [
                'key'   => 'order_accepted_location',
                'tab'   => 'order_actions',
                'label' => 'Order accepted → send location request',
                'description' =>
                    "When a Shopify order is ACCEPTED (converted into a live order), automatically asks the "
                    . "customer for their delivery location using the location-request template. This simply "
                    . "switches on the app's EXISTING location flow (it already fires on accept and will NOT "
                    . "message a customer whose location is already saved) — so there's no duplicate message. "
                    . "It uses its own switch, independent of the master automations toggle above.",
                'trigger_summary' => "When a Shopify order is accepted",
                'event'    => 'order.accepted',
                'editable' => ['enabled', 'template', 'location_window'],
                'template' => ['body_vars' => 1, 'header' => 'none', 'default' => 'location_request'],
                'available' => true,
                'handler'  => null,
                // Proxies the EXISTING location auto-send config instead of the
                // dispatcher — toggling on/off writes these t_fin_config keys.
                'config_proxy' => [
                    'enabled'  => 'location_auto_send_enabled',
                    'template' => 'open_order_location_default_template',
                ],
                // The daily send window (HH:MM, Asia/Karachi) — surfaced here so
                // the whole location automation is configured from ONE screen
                // (was split with the Get Locations modal). Same t_fin_config
                // keys the drain + that modal already read.
                'window_proxy' => [
                    'start' => 'location_auto_window_start',
                    'end'   => 'location_auto_window_end',
                ],
            ],
            [
                'key'   => 'invoice_auto_dispatch',
                'tab'   => 'invoice',
                'label' => 'Invoice templates (online / cash)',
                'description' =>
                    "Set which WhatsApp template the Send Invoice action uses for ONLINE / bank-transfer "
                    . "invoices vs CASH invoices. The Send Invoice button (orders page + chat) then pre-fills "
                    . "the matching template by the order's payment method — you can still change it before "
                    . "sending. Both default to your current invoice template until you create separate ones. "
                    . "Fully AUTOMATIC sending (on dispatch / status / ETA) is the next step — it needs the "
                    . "invoice image, which today is produced by the browser when you preview an invoice.",
                'trigger_summary' => "Pre-fills Send Invoice by payment method",
                'event'    => 'invoice.trigger',
                'editable' => ['templates_online_cash'],
                'template' => ['body_vars' => null, 'header' => 'image', 'default' => null],
                'available' => true,
                'handler'  => null,
                // The four invoice template names live in t_fin_config (shared by
                // the manual Send-Invoice flow, which is NOT permission-gated to
                // automation managers). The Send-Invoice button picks online vs
                // cash by payment method, and the with-ETA (3 vars) vs no-ETA
                // (2 vars) version by whether the delivery ETA exists yet. All
                // default to the current invoice template until configured.
                'invoice_proxy' => [
                    'online_eta'   => 'invoice_template_online_eta',
                    'online_noeta' => 'invoice_template_online_noeta',
                    'cash_eta'     => 'invoice_template_cash_eta',
                    'cash_noeta'   => 'invoice_template_cash_noeta',
                    'default'      => 'invoice_dispatch',
                ],
            ],
            [
                'key'   => 'invoice_on_payment_change',
                'tab'   => 'invoice',
                'label' => 'Bank details when payment switches to online',
                'description' =>
                    "When an OUT-FOR-DELIVERY order's payment method is changed TO online — by a rider "
                    . "before delivery, or by a manager on the web — automatically WhatsApp the customer "
                    . "your bank details so they can pay online. Uses a TEXT template (no invoice image, "
                    . "since there's no browser at the moment of change). Variables: {{1}} customer name, "
                    . "{{2}} order number. Sends once per order; skips if the order is no longer "
                    . "out-for-delivery / online or has no phone. Off by default.",
                'trigger_summary' => "When an out-for-delivery order switches to online payment",
                'event'    => 'order.payment_changed',
                'editable' => ['enabled', 'template'],
                'template' => ['body_vars' => 2, 'header' => 'none', 'default' => null],
                'available' => true,
                'handler'  => \App\Services\WhatsApp\Automation\Handlers\PaymentChangeInvoiceHandler::class,
            ],
        ];
    }

    /** One descriptor by key, or null. */
    public static function get(string $key): ?array
    {
        foreach (self::all() as $desc) {
            if ($desc['key'] === $key) {
                return $desc;
            }
        }
        return null;
    }

    /** All descriptors that listen for $event (only used once they're available). */
    public static function forEvent(string $event): array
    {
        return array_values(array_filter(self::all(), fn($d) => ($d['event'] ?? null) === $event));
    }

    /** Is this rule wired up and allowed to be enabled yet? */
    public static function isAvailable(string $key): bool
    {
        $desc = self::get($key);
        return $desc !== null && !empty($desc['available']);
    }
}
