<?php

namespace App\Services\WhatsApp;

use App\Models\CRM\ShopifyOrderModel;
use App\Models\FIN\ConfigModel;
use App\Models\WhatsApp\AutomationLogModel;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ "WHY was this Shopify order ignored, and does the customer get told?" — ONE
 *    implementation, every surface (Sep-3 2026).
 *
 * WHY IT EXISTS
 * Ignoring a Shopify approval used to be silent: the row was stamped `converted = 2`
 * and the customer heard nothing. Two of those refusals happen often enough to deserve
 * a sentence — an address outside the delivery area, and a customer who rang up and
 * asked to cancel — and in both cases the person is left waiting for food that is never
 * coming. This class turns the reason into a WhatsApp template send.
 *
 * ⭐ IT LIVES BEHIND THE ENDPOINT, NOT IN A SCREEN. `OrderController::ignoreOrder` is
 *   the single door the web drawer, the web table AND both mobile store screens already
 *   post to, so putting the rules here means every surface gets the same three choices
 *   with no per-client logic and no new APK required for the server half.
 *
 * ⚠⚠ THE MESSAGE MUST NEVER BE ABLE TO BLOCK THE IGNORE. Marking the order ignored is
 *    the operator's actual intent; the WhatsApp send is a courtesy on top. A missing
 *    template, a dead number, a Meta outage — every one of them returns a `skipped` or
 *    `failed` verdict that the caller REPORTS, and the order stays ignored either way.
 *    Nothing in here throws.
 *
 * ⚠ TEMPLATE NAMES ARE CONFIG, NOT CONSTANTS. Meta template names are created by hand in
 *   WhatsApp Manager and can be renamed or versioned (`..._v2`) without warning; a hard
 *   -coded name would mean a deploy every time. The defaults below are what the doc
 *   `WHATSAPP-ORDER-CANCELLATION-TEMPLATES.md` tells the owner to create, and either can
 *   be repointed from `t_fin_config` with no code change.
 */
class OrderCancellationNotifier
{
    /** Ignore quietly — the behaviour that existed before this class. */
    public const REASON_NONE = 'none';
    /** The address is outside any area we deliver to. */
    public const REASON_OUT_OF_AREA = 'out_of_area';
    /** The customer themselves asked for it to be cancelled. */
    public const REASON_CUSTOMER_REQUEST = 'customer_request';

    /** Namespaced so these rows sit beside the rule-driven ones in the activity log. */
    private const RULE_KEY = 'shopify_order_ignored';
    private const TRIGGER  = 'shopify.order.ignored';

    /**
     * The catalogue. `label` is what both UIs show, so the wording lives here and the
     * clients stay dumb — a change of copy is a server change, not two client changes.
     */
    private const MAP = [
        self::REASON_OUT_OF_AREA => [
            'config'   => 'shopify_ignore_template_out_of_area',
            'template' => 'order_cancelled_out_of_area',
            'label'    => 'Outside our delivery area',
            'hint'     => 'Tells the customer we do not deliver to their address yet.',
        ],
        self::REASON_CUSTOMER_REQUEST => [
            'config'   => 'shopify_ignore_template_customer_request',
            'template' => 'order_cancelled_customer_request',
            'label'    => 'Customer asked to cancel',
            'hint'     => 'Confirms to the customer that we cancelled it on their request.',
        ],
    ];

    public function __construct(protected WhatsAppService $wa)
    {
    }

    /** Every reason a caller may pass, including the silent one. */
    public static function reasonKeys(): array
    {
        return array_merge([self::REASON_NONE], array_keys(self::MAP));
    }

    /**
     * An unknown or empty reason is treated as "ignore quietly" rather than refused.
     * ⚠ Deliberate: an older APK posts no reason at all, and it must keep working
     *   exactly as it does today.
     */
    public static function normalize($reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';
        return isset(self::MAP[$reason]) ? $reason : self::REASON_NONE;
    }

    /** The Meta template this reason sends, after any config override. */
    public function templateFor(string $reason): ?string
    {
        if (!isset(self::MAP[$reason])) {
            return null;
        }
        $configured = trim((string) ConfigModel::get(self::MAP[$reason]['config'], ''));
        return $configured !== '' ? $configured : self::MAP[$reason]['template'];
    }

    /**
     * The picker's options, each saying whether it can actually send today. Lets a
     * surface grey out a choice whose template has not been created in Meta yet
     * instead of offering a door that quietly does nothing.
     */
    public function options(): array
    {
        $out = [];
        foreach (self::MAP as $key => $meta) {
            $template = $this->templateFor($key);
            $out[] = [
                'key'      => $key,
                'label'    => $meta['label'],
                'hint'     => $meta['hint'],
                'template' => $template,
                'ready'    => $this->templateIsUsable($template),
            ];
        }
        return $out;
    }

    /**
     * Mark-and-tell. Called AFTER the order has already been stamped ignored.
     *
     * @return array{messaged:bool, status:string, detail:string, template:?string}
     *         status ∈ sent | skipped | failed. `detail` is written to be shown to the
     *         operator verbatim — they are the one who has to decide whether to ring the
     *         customer instead.
     */
    public function notify(ShopifyOrderModel $order, string $reason, ?int $userId = null): array
    {
        $reason = self::normalize($reason);
        if ($reason === self::REASON_NONE) {
            return $this->verdict(false, 'skipped', 'No message was requested.', null);
        }

        $template = $this->templateFor($reason);
        $orderNumber = (string) ($order->order_number ?: $order->name ?: ('#' . $order->id));

        try {
            if (!$this->templateIsUsable($template)) {
                return $this->skip($template, $orderNumber,
                    'WhatsApp template "' . $template . '" is not set up yet, so no message was sent.');
            }

            $rawPhone = $this->phoneFor($order);
            if ($rawPhone === '') {
                return $this->skip($template, $orderNumber,
                    'This order has no phone number, so no message was sent.');
            }
            if ($this->wa->isNumberUndeliverable($rawPhone)) {
                return $this->skip($template, $orderNumber,
                    'This number does not receive WhatsApp, so no message was sent.');
            }

            // Known-number override; a no-op for ordinary PK numbers.
            $phone = $this->wa->resolveDialPhone($rawPhone);

            // ⚠ Build the parameters from the template's OWN variable count, not from
            //   what this class assumes. Meta rejects the send outright when the count
            //   disagrees, and the owner may well publish a one-variable version.
            $varCount = (int) (DB::table('t_wa_templates')->where('name', $template)->value('variable_count') ?? 0);
            $language = (string) (DB::table('t_wa_templates')->where('name', $template)->value('language') ?: 'en');
            $firstName = $this->firstNameFor($order);
            $bodyParams = [];
            if ($varCount >= 1) { $bodyParams[] = $firstName; }
            if ($varCount >= 2) { $bodyParams[] = $orderNumber; }

            $resp = $this->wa->sendTemplateMessage($phone, $template, $language, $bodyParams);

            if (empty($resp['success'])) {
                $err = (string) ($resp['error'] ?? 'WhatsApp refused the message');
                $this->logRow($orderNumber, $template, AutomationLogModel::STATUS_FAILED, null, null, $err);
                return $this->verdict(false, 'failed',
                    'The order was ignored, but WhatsApp did not accept the message: ' . $err, $template);
            }

            // Persist to the conversation timeline so the message appears in the inbox
            // and the order's own history, exactly like a manual or automated send.
            $conversationId = null;
            try {
                $conv = $this->wa->findOrCreateConversation($phone);
                $conversationId = $conv->id;
                $this->wa->saveOutboundMessage(
                    $conv->id,
                    $resp,
                    'template',
                    $template,
                    $userId,
                    $template,
                    $this->paramMap($bodyParams),
                    false,
                    $orderNumber
                );
            } catch (\Throwable $e) {
                Log::debug('OrderCancellationNotifier: outbound persist skipped (non-fatal)', [
                    'order_number' => $orderNumber, 'error' => $e->getMessage(),
                ]);
            }

            $this->logRow($orderNumber, $template, AutomationLogModel::STATUS_SENT, $conversationId,
                $resp['messages'][0]['id'] ?? null);

            return $this->verdict(true, 'sent', 'The customer has been messaged on WhatsApp.', $template);

        } catch (\Throwable $e) {
            Log::warning('OrderCancellationNotifier failed (non-fatal)', [
                'order_number' => $orderNumber, 'reason' => $reason, 'error' => $e->getMessage(),
            ]);
            try {
                $this->logRow($orderNumber, $template, AutomationLogModel::STATUS_FAILED, null, null, $e->getMessage());
            } catch (\Throwable $ignored) {
                // logging must never be the thing that breaks an ignore
            }
            return $this->verdict(false, 'failed',
                'The order was ignored, but the WhatsApp message could not be sent.', $template);
        }
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    /** A template we can actually send: on file, and not switched off. */
    private function templateIsUsable(?string $template): bool
    {
        if (!$template) {
            return false;
        }
        try {
            $q = DB::table('t_wa_templates')->where('name', $template);
            if (Schema::hasColumn('t_wa_templates', 'is_active')) {
                $q->where('is_active', 1);
            }
            return $q->exists();
        } catch (\Throwable $e) {
            return false;      // cannot verify → do not pretend we sent anything
        }
    }

    /**
     * ⚠ The staging row's own address phone is the fallback, not the first choice: a
     *   linked customer record is the number ops actually converse with, and it is the
     *   one the conversation timeline is keyed on.
     */
    private function phoneFor(ShopifyOrderModel $order): string
    {
        $phone = '';
        try {
            if ($order->customer_id) {
                $phone = (string) (DB::table('t_crm_prod_customer')
                    ->where('id', $order->customer_id)
                    ->value('phone') ?? '');
            }
        } catch (\Throwable $e) {
            $phone = '';
        }
        if (trim($phone) === '') {
            $phone = (string) ($order->address_phone ?? '');
        }
        return trim($phone);
    }

    /** Same precedence the order-received handlers use, so one customer sees one name. */
    private function firstNameFor(ShopifyOrderModel $order): string
    {
        $name = '';
        try {
            if ($order->customer_id) {
                $name = (string) (DB::table('t_crm_prod_customer')
                    ->where('id', $order->customer_id)
                    ->value('first_name') ?? '');
            }
        } catch (\Throwable $e) {
            $name = '';
        }
        if (trim($name) === '') {
            $name = (string) ($order->address_first_name ?? '');
        }
        if (trim($name) === '') {
            // Last resort: the first word of whatever name the order carries.
            $full = trim((string) ($order->name ?? ''));
            $name = $full !== '' ? explode(' ', $full)[0] : '';
        }
        return trim($name) !== '' ? trim($name) : 'there';
    }

    private function paramMap(array $bodyParams): ?array
    {
        if (!$bodyParams) {
            return null;
        }
        $out = [];
        foreach ($bodyParams as $i => $v) {
            $out[(string) ($i + 1)] = $v;
        }
        return $out;
    }

    /**
     * ⚠⚠ `order_id` IS DELIBERATELY LEFT NULL. This id belongs to
     *    `t_crm_shopify_order`, whose auto-increment overlaps `t_crm_prod_order` — the
     *    same number is routinely a different, unrelated order in the live table. The
     *    order NUMBER inside `dedup_key` is the identifier that survives that collision,
     *    which is exactly what AutomationLogModel's own note prescribes.
     */
    private function logRow(string $orderNumber, ?string $template, string $status,
                            ?int $conversationId = null, ?string $waMessageId = null,
                            ?string $error = null): void
    {
        if (!Schema::hasTable('t_wa_automation_log')) {
            return;
        }
        try {
            AutomationLogModel::create([
                'rule_key'        => self::RULE_KEY,
                'trigger_event'   => self::TRIGGER,
                'dedup_key'       => 'shopify:' . $orderNumber,
                'order_id'        => null,
                'conversation_id' => $conversationId,
                'template_name'   => $template,
                'status'          => $status,
                'skip_reason'     => $status === AutomationLogModel::STATUS_SKIPPED ? $error : null,
                'wa_message_id'   => $waMessageId,
                'error_message'   => $status === AutomationLogModel::STATUS_FAILED ? $error : null,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderCancellationNotifier: log row failed', ['error' => $e->getMessage()]);
        }
    }

    private function skip(?string $template, string $orderNumber, string $detail): array
    {
        $this->logRow($orderNumber, $template, AutomationLogModel::STATUS_SKIPPED, null, null, $detail);
        return $this->verdict(false, 'skipped', $detail, $template);
    }

    private function verdict(bool $messaged, string $status, string $detail, ?string $template): array
    {
        return ['messaged' => $messaged, 'status' => $status, 'detail' => $detail, 'template' => $template];
    }
}
