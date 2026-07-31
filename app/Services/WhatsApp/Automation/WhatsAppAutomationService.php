<?php

namespace App\Services\WhatsApp\Automation;

use App\Models\FIN\ConfigModel;
use App\Models\WhatsApp\AutomationModel;
use App\Models\WhatsApp\AutomationLogModel;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The engine behind the order/invoice WhatsApp automations (Tabs 2 & 3).
 *
 * It owns the GENERIC flow so each rule type only supplies its specifics
 * (see AutomationHandler): master-switch gate, per-rule on/off, send-once
 * dedup, optional test-phone redirect, the actual template send, and logging.
 *
 * Phase 1: fully built but DORMANT. No registry rule is `available`, and no
 * trigger seam calls dispatch(), so nothing can fire. Phases 2 & 3 implement
 * the handlers, flip `available`, and add the one-line dispatch() calls at the
 * order/invoice seams (always AFTER commit, never inside a DB transaction).
 */
class WhatsAppAutomationService
{
    const MASTER_KEY = 'wa_automations_enabled';
    const TEST_PHONE_KEY = 'wa_automations_test_phone';

    /** Master switch for ALL event automations. Off unless explicitly '1'. */
    public function masterEnabled(): bool
    {
        $v = ConfigModel::get(self::MASTER_KEY, '0');
        return !empty($v) && $v !== '0' && strtolower((string) $v) !== 'false';
    }

    /** Optional test-phone: when set, every send is redirected here. */
    public function testPhone(): ?string
    {
        $v = trim((string) ConfigModel::get(self::TEST_PHONE_KEY, ''));
        return $v !== '' ? $v : null;
    }

    /** The operator's saved settings row for a rule, or null if untouched. */
    public function ruleFor(string $key): ?AutomationModel
    {
        if (!Schema::hasTable('t_wa_automations')) {
            return null;
        }
        return AutomationModel::where('rule_key', $key)->first();
    }

    /**
     * Fully-resolved "is this rule live": the rule type must be available
     * (wired), the operator must have enabled it, AND the master switch on.
     */
    public function isRuleLive(string $key): bool
    {
        if (!$this->masterEnabled() || !AutomationRegistry::isAvailable($key)) {
            return false;
        }
        $rule = $this->ruleFor($key);
        return $rule !== null && (bool) $rule->enabled;
    }

    /**
     * Has this (rule, entity) already been HANDLED? Drives send-once dedup.
     *
     * Counts both 'sent' AND 'failed' as handled (Jun-2026 review fix H2): once
     * we've made a real send attempt for an order, we never auto-retry it — a
     * genuine Meta rejection (paused/mis-named template) must not turn into a
     * spam loop when the order re-triggers (webhook re-deliveries). Failures
     * stay visible in the activity log for manual follow-up. Skips do NOT count
     * (they're not attempts), so eligibility can change a "skip" into a later
     * legitimate send only if the order itself re-fires — which order.created
     * does not do for the same order.
     */
    public function alreadyHandled(string $ruleKey, string $dedupKey): bool
    {
        if (!Schema::hasTable('t_wa_automation_log')) {
            return false;
        }
        return AutomationLogModel::where('rule_key', $ruleKey)
            ->where('dedup_key', $dedupKey)
            ->whereIn('status', [AutomationLogModel::STATUS_SENT, AutomationLogModel::STATUS_FAILED])
            ->exists();
    }

    /** Write one log row (sent / failed / skipped). Never throws. */
    public function recordLog(array $data): void
    {
        if (!Schema::hasTable('t_wa_automation_log')) {
            return;
        }
        try {
            $data['created_at'] = $data['created_at'] ?? now();
            AutomationLogModel::create($data);
        } catch (\Throwable $e) {
            Log::warning('WA automation: failed to write log row', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Fire all live rules listening for $event. Safe to call from any seam —
     * it self-guards on the master switch and per-rule availability, and each
     * rule is isolated in its own try/catch so one failure can't affect the
     * others or the caller. Best called after the DB transaction commits.
     *
     * Phase 1: returns immediately for every event (no rule is `available`).
     */
    public function dispatch(string $event, array $context): void
    {
        if (!$this->masterEnabled() || !Schema::hasTable('t_wa_automations')) {
            return;
        }

        foreach (AutomationRegistry::forEvent($event) as $desc) {
            // Not wired yet (Phase 1 = all of them) → skip.
            if (empty($desc['available']) || empty($desc['handler'])) {
                continue;
            }
            $key = $desc['key'];
            $lock = null;
            try {
                $rule = $this->ruleFor($key);
                if (!$rule || !$rule->enabled) {
                    continue;
                }

                /** @var AutomationHandler $handler */
                $handler = app($desc['handler']);

                $dedupKey = $handler->dedupKey($context);
                if ($this->alreadyHandled($key, $dedupKey)) {
                    continue; // Already handled — stay quiet, no duplicate log noise.
                }

                // Serialize concurrent triggers for the SAME (rule, order) so two
                // near-simultaneous webhook deliveries can't both pass the dedup
                // check and double-send. Non-blocking — if another worker holds
                // it, bail. Auto-expires (sends ~1-2s; 30s ample). Released in
                // finally. M2 review fix: if the cache lock is UNAVAILABLE (e.g.
                // the `cache_locks` table isn't migrated on the DB cache driver),
                // don't silently die — fall back to dedup-only and log it, so the
                // feature still works (the re-check below + the send-once log
                // still guard; only a rare concurrent burst could slip a dup).
                try {
                    $lock = Cache::lock('wa_autom:' . $key . ':' . md5($dedupKey), 30);
                    if (!$lock->get()) {
                        $lock = null; // another worker holds it — bail
                        continue;
                    }
                } catch (\Throwable $e) {
                    $lock = null; // locks unavailable → proceed dedup-only
                    Log::warning('WA automation: cache lock unavailable, proceeding dedup-only', [
                        'rule' => $key, 'error' => $e->getMessage(),
                    ]);
                }
                // Authoritative re-check (inside the lock when we have one): the
                // other worker may have finished and logged between our pre-check
                // and here.
                if ($this->alreadyHandled($key, $dedupKey)) {
                    continue;
                }

                // Expose the operator's saved rule to eligibility() so a handler
                // can apply a CONFIGURED skip (e.g. the delivered-storage-tips
                // per-customer cooldown) without changing the interface. Purely
                // additive — handlers that don't read it are unaffected.
                $context['rule'] = $rule;

                $skip = $handler->eligibility($context);
                if ($skip !== null) {
                    $this->recordLog([
                        'rule_key' => $key, 'trigger_event' => $event, 'dedup_key' => $dedupKey,
                        'order_id' => $context['order_id'] ?? null,
                        'status' => AutomationLogModel::STATUS_SKIPPED, 'skip_reason' => $skip,
                    ]);
                    continue;
                }

                $template = $handler->pickTemplate($context, $rule) ?: $rule->template_name;
                $phone = $handler->recipientPhone($context);
                if (empty($template) || empty($phone)) {
                    $this->recordLog([
                        'rule_key' => $key, 'trigger_event' => $event, 'dedup_key' => $dedupKey,
                        'order_id' => $context['order_id'] ?? null,
                        'status' => AutomationLogModel::STATUS_SKIPPED,
                        'skip_reason' => empty($template) ? 'no template configured' : 'no phone number',
                    ]);
                    continue;
                }

                $this->send($event, $desc, $rule, $handler, $context, $dedupKey, $template, $phone);
            } catch (\Throwable $e) {
                Log::warning('WA automation: rule failed', ['rule' => $key, 'error' => $e->getMessage()]);
            } finally {
                if ($lock) {
                    try { $lock->release(); } catch (\Throwable $e) {}
                }
            }
        }
    }

    /**
     * Resolve the conversation + send the template, applying the test-phone
     * redirect, then log the outcome. Pulled out so dispatch() stays readable.
     */
    protected function send(
        string $event,
        array $desc,
        AutomationModel $rule,
        AutomationHandler $handler,
        array $context,
        string $dedupKey,
        string $template,
        string $phone
    ): void {
        $wa = app(WhatsAppService::class);

        // Test-phone redirect: send to the operator's number instead of the
        // customer, for safe live testing. The real recipient is still logged.
        $realPhone = $phone;
        $testPhone = $this->testPhone();
        // Customer sends are dial-resolved (known-number override; no-op for
        // PK); the operator's test phone keeps plain PK formatting.
        $sendTo = $testPhone
            ? $wa->formatPhone($testPhone)
            : $wa->resolveDialPhone((string) $phone);

        $body = $handler->bodyParams($context, $rule);
        $header = $handler->headerParams($context, $rule);

        $result = $wa->sendTemplateMessage($sendTo, $template, 'en', $body, $header);

        $base = [
            'rule_key' => $desc['key'],
            'trigger_event' => $event,
            'dedup_key' => $dedupKey,
            'order_id' => $context['order_id'] ?? null,
            'template_name' => $template,
        ];

        if (!($result['success'] ?? false)) {
            $this->recordLog($base + [
                'status' => AutomationLogModel::STATUS_FAILED,
                'error_message' => $result['error'] ?? 'send failed',
            ]);
            return;
        }

        // Persist the outbound row to the chat log (under the REAL recipient's
        // conversation when not in test mode).
        $conversationId = null;
        try {
            $conv = $wa->findOrCreateConversation($sendTo);
            if ($conv) {
                $conversationId = $conv->id;
                $wa->saveOutboundMessage(
                    $conv->id,
                    $result,
                    'template',
                    'Automation: ' . $desc['key'] . ' (' . $template . ')'
                        . ($testPhone ? ' [TEST → ' . $realPhone . ']' : ''),
                    null,
                    $template,
                    $body,
                    false
                );
            }
        } catch (\Throwable $e) {
            Log::debug('WA automation: outbound persist skipped', ['error' => $e->getMessage()]);
        }

        $this->recordLog($base + [
            'conversation_id' => $conversationId,
            'status' => AutomationLogModel::STATUS_SENT,
            'wa_message_id' => $result['messages'][0]['id'] ?? null,
        ]);
    }
}
