<?php

namespace App\Services\WhatsApp\Automation;

use App\Models\WhatsApp\AutomationModel;

/**
 * Contract every event-automation rule type implements (Phase 2 / 3).
 *
 * The dispatcher (WhatsAppAutomationService) owns the generic flow —
 * master-switch + per-rule on/off, send-once dedup, test-phone redirect,
 * logging. Each handler supplies only the rule-SPECIFIC, code-fixed parts:
 * who to message, which template, what fills the variables, and when to skip.
 * These are exactly the parts the operator CANNOT edit from the UI.
 *
 * $context is the event payload (e.g. ['order' => OrderModel] for order.created).
 * $rule is the operator's saved settings row for this rule type.
 *
 * Phase 1 ships NO handlers — the registry marks the order/invoice rules
 * `available = false`, so the dispatcher never resolves a handler yet.
 */
interface AutomationHandler
{
    /**
     * A stable per-entity key, e.g. "order:SH-123". Combined with the rule key
     * it guarantees one send per entity per rule. Must NOT be a raw auto-id
     * (the Shopify staging + prod tables share ids).
     */
    public function dedupKey(array $context): string;

    /**
     * Return null to proceed, or a short human reason to SKIP (logged, shown in
     * the activity tracker) — e.g. "location already saved", "no phone".
     */
    public function eligibility(array $context): ?string;

    /**
     * The WhatsApp template name to send. Lets a rule route by time-of-day or
     * payment method. Return null to fall back to $rule->template_name.
     */
    public function pickTemplate(array $context, AutomationModel $rule): ?string;

    /** Positional body variables ({{1}}, {{2}}, ...) for the template. */
    public function bodyParams(array $context, AutomationModel $rule): array;

    /** Header params (e.g. an invoice image) or [] when the template has none. */
    public function headerParams(array $context, AutomationModel $rule): array;

    /** E.164-ish recipient phone, or null to skip (no contactable number). */
    public function recipientPhone(array $context): ?string;
}
