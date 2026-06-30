<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FIN\ConfigModel;
use App\Models\WhatsApp\AutomationModel;
use App\Models\WhatsApp\AutomationLogModel;
use App\Services\WhatsApp\Automation\AutomationRegistry;
use App\Services\WhatsApp\Automation\WhatsAppAutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Settings API for the event-triggered WhatsApp automations (Tabs 2 & 3).
 *
 * Read-and-configure only — this controller never sends a message. The
 * dispatcher (WhatsAppAutomationService) does the sending, from the order /
 * invoice seams wired in Phases 2 & 3. Gated by the same manage_wa_auto_reply
 * permission as the auto-reply modal.
 *
 * Off-by-default is enforced here too: saveRule refuses to enable a rule type
 * that isn't `available` yet, and a brand-new rule row is created disabled.
 */
class WhatsAppAutomationController extends Controller
{
    protected function gate(): void
    {
        $user = auth()->user();
        if (!$user || !method_exists($user, 'hasMobilePermission')
            || !$user->hasMobilePermission('manage_wa_auto_reply')) {
            abort(403, 'You do not have permission to manage WhatsApp automations.');
        }
    }

    /** Validate + normalize an "HH:MM" time string, or null if malformed. */
    protected function normalizeHm(string $hm): ?string
    {
        $hm = trim($hm);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hm, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $mm = (int) $m[2];
        if ($h < 0 || $h > 23 || $mm < 0 || $mm > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $mm);
    }

    /**
     * GET /messages/automations
     * Returns the master switch, the test phone, and the full rule catalog
     * (code registry) merged with each rule's saved state.
     */
    public function index()
    {
        $this->gate();

        if (!Schema::hasTable('t_wa_automations')) {
            return response()->json([
                'success' => false,
                'message' => 'Automations migration has not been applied. Run database/migrations/create_wa_automations_jun2026.sql.',
            ], 503);
        }

        $service = app(WhatsAppAutomationService::class);
        $saved = AutomationModel::get()->keyBy('rule_key');

        $rules = array_map(function ($desc) use ($saved) {
            $row = $saved->get($desc['key']);

            // Default state lives in t_wa_automations (off when no row).
            $enabled = $row ? (bool) $row->enabled : false;
            $templateName = $row->template_name ?? null;
            $config = $row ? $row->config() : [];

            // Config-proxy rules (e.g. location) read their state from the
            // app's existing t_fin_config keys, not t_wa_automations, so the
            // card mirrors the live location auto-send setting.
            if (!empty($desc['config_proxy'])) {
                $ek = $desc['config_proxy']['enabled'];
                $tk = $desc['config_proxy']['template'] ?? null;
                $ev = ConfigModel::get($ek, '0');
                $enabled = !empty($ev) && $ev !== '0' && strtolower((string) $ev) !== 'false';
                $templateName = $tk ? (ConfigModel::get($tk, '') ?: null) : null;
            }

            // Window-proxy rules (location) surface their daily send window so it
            // can be edited from this one screen (HH:MM, Asia/Karachi).
            if (!empty($desc['window_proxy'])) {
                $config = array_merge(is_array($config) ? $config : [], [
                    'window_start' => ConfigModel::get($desc['window_proxy']['start'], '09:00') ?: '09:00',
                    'window_end'   => ConfigModel::get($desc['window_proxy']['end'], '18:00') ?: '18:00',
                ]);
            }

            // Invoice rule: the FOUR invoice template names live in t_fin_config
            // (online/cash × with-ETA/no-ETA), defaulting to the current invoice
            // template until the owner sets distinct ones. Surfaced in `config`
            // so the 4-picker card reads one shape.
            if (!empty($desc['invoice_proxy'])) {
                $ip  = $desc['invoice_proxy'];
                $def = $ip['default'];
                $config = [
                    'online_eta_template'   => ConfigModel::get($ip['online_eta'], '') ?: $def,
                    'online_noeta_template' => ConfigModel::get($ip['online_noeta'], '') ?: $def,
                    'cash_eta_template'     => ConfigModel::get($ip['cash_eta'], '') ?: $def,
                    'cash_noeta_template'   => ConfigModel::get($ip['cash_noeta'], '') ?: $def,
                ];
            }

            return [
                'key'             => $desc['key'],
                'tab'             => $desc['tab'],
                'label'           => $desc['label'],
                'description'     => $desc['description'],
                'trigger_summary' => $desc['trigger_summary'],
                'editable'        => $desc['editable'],
                'template'        => $desc['template'],
                'available'       => (bool) $desc['available'],
                'config_proxy'    => !empty($desc['config_proxy']) || !empty($desc['invoice_proxy']),
                'enabled'         => $enabled,
                'template_name'   => $templateName,
                'config'          => $config,
            ];
        }, AutomationRegistry::all());

        return response()->json([
            'success'    => true,
            'enabled'    => $service->masterEnabled(),
            'test_phone' => $service->testPhone(),
            'rules'      => $rules,
        ]);
    }

    /** POST /messages/automations/toggle — flip the master switch. */
    public function toggle(Request $request)
    {
        $this->gate();
        $request->validate(['enabled' => 'required|boolean']);

        ConfigModel::set(
            WhatsAppAutomationService::MASTER_KEY,
            $request->boolean('enabled') ? '1' : '0',
            'Master switch for event-triggered WhatsApp automations.'
        );

        return response()->json(['success' => true, 'enabled' => $request->boolean('enabled')]);
    }

    /** POST /messages/automations/test-phone — set or clear the test redirect. */
    public function saveTestPhone(Request $request)
    {
        $this->gate();
        $request->validate(['phone' => 'nullable|string|max:30']);

        $phone = trim((string) $request->input('phone', ''));
        ConfigModel::set(
            WhatsAppAutomationService::TEST_PHONE_KEY,
            $phone,
            'Test-phone redirect for WhatsApp automations (blank = send to real customers).'
        );

        return response()->json(['success' => true, 'test_phone' => $phone !== '' ? $phone : null]);
    }

    /**
     * POST /messages/automations/rules/{key}
     * Upsert a rule's operator settings. enabled is forced to false when the
     * rule type isn't wired/available yet (Phase 1 = all of them).
     */
    public function saveRule(Request $request, string $key)
    {
        $this->gate();

        $desc = AutomationRegistry::get($key);
        if (!$desc) {
            return response()->json(['success' => false, 'message' => 'Unknown automation rule.'], 404);
        }

        $request->validate([
            'enabled'       => 'sometimes|boolean',
            'template_name' => 'nullable|string|max:255',
            'config'        => 'nullable|array',
        ]);

        // Config-proxy rules (location) write the app's existing config keys
        // instead of t_wa_automations, so there's a single source of truth and
        // no duplicate send pipeline.
        if (!empty($desc['config_proxy'])) {
            $enabled = $request->boolean('enabled', false);
            ConfigModel::set($desc['config_proxy']['enabled'], $enabled ? '1' : '0',
                'Toggled via WhatsApp automations — ' . $desc['label'] . '.');
            if (!empty($desc['config_proxy']['template']) && $request->filled('template_name')) {
                ConfigModel::set($desc['config_proxy']['template'], $request->input('template_name'),
                    'Template for ' . $desc['label'] . ' (set via WhatsApp automations).');
            }
            // Window-proxy (location): persist the daily send window (HH:MM).
            if (!empty($desc['window_proxy'])) {
                $cfg = $request->input('config', []);
                if (is_array($cfg)) {
                    foreach (['start' => 'window_start', 'end' => 'window_end'] as $proxyKey => $field) {
                        if (array_key_exists($field, $cfg)) {
                            $val = $this->normalizeHm((string) ($cfg[$field] ?? ''));
                            if ($val !== null) {
                                ConfigModel::set($desc['window_proxy'][$proxyKey], $val,
                                    'Auto location-request send window (set via WhatsApp automations).');
                            }
                        }
                    }
                }
            }
            return response()->json(['success' => true, 'enabled' => $enabled, 'available' => true, 'blocked' => false]);
        }

        // Invoice rule: write the FOUR invoice template names to t_fin_config
        // (shared with the manual Send-Invoice flow). No auto-send enable here —
        // the Send Invoice button is manual; these are just the templates it uses.
        if (!empty($desc['invoice_proxy'])) {
            $ip  = $desc['invoice_proxy'];
            $cfg = $request->input('config', []);
            if (is_array($cfg)) {
                $map = [
                    'online_eta_template'   => $ip['online_eta'],
                    'online_noeta_template' => $ip['online_noeta'],
                    'cash_eta_template'     => $ip['cash_eta'],
                    'cash_noeta_template'   => $ip['cash_noeta'],
                ];
                foreach ($map as $field => $configKey) {
                    if (array_key_exists($field, $cfg)) {
                        ConfigModel::set($configKey, (string) ($cfg[$field] ?? ''),
                            'Invoice template ' . $field . ' (WhatsApp automations).');
                    }
                }
            }
            return response()->json(['success' => true, 'enabled' => false, 'available' => true, 'blocked' => false]);
        }

        // Off-by-default guard: can only enable a rule that's actually wired.
        $enabled = $request->boolean('enabled', false) && !empty($desc['available']);

        $config = $request->input('config');
        AutomationModel::updateOrCreate(
            ['rule_key' => $key],
            [
                'enabled'       => $enabled ? 1 : 0,
                'template_name' => $request->filled('template_name') ? $request->input('template_name') : null,
                'config_json'   => is_array($config) ? json_encode($config) : null,
                'updated_by'    => auth()->id(),
            ]
        );

        return response()->json([
            'success'   => true,
            'enabled'   => $enabled,
            'available' => (bool) $desc['available'],
            // Tell the UI if it asked to enable something not yet available.
            'blocked'   => $request->boolean('enabled', false) && empty($desc['available']),
        ]);
    }

    /**
     * GET /messages/automations/invoice-template-map
     * Tiny, NON-gated lookup the Send-Invoice dialogs call to pre-fill the
     * online vs cash template by the order's payment method. Returns only
     * template names (not sensitive); both default to the current invoice
     * template (`invoice_dispatch`) until distinct ones are configured. No
     * manage_wa_auto_reply gate so ordinary invoice senders can use it.
     */
    public function invoiceTemplateMap()
    {
        // Returns the NO-ETA online/cash templates (the simple pre-select for the
        // current Send-Invoice dialog). The richer ETA-aware pick lives in
        // invoiceSendPlan(); this stays for the existing lightweight auto-fill.
        $map = \App\Services\WhatsApp\InvoiceSendPlanService::noEtaMap();
        return response()->json([
            'success' => true,
            'online'  => $map['online'],
            'cash'    => $map['cash'],
        ]);
    }

    /**
     * GET /messages/automations/invoice-send-plan/{orderId}?source=shopify|prod
     *
     * Resolves the full Send-Invoice plan for one order so the dialog can
     * pre-fill everything: which template (online vs cash by payment method,
     * with-ETA vs no-ETA by whether the delivery window exists yet) and the body
     * variables [name, order#, (ETA)]. Reads the four invoice_template_* config
     * keys (default `invoice_dispatch`). NON-gated (auth only) — invoice senders
     * aren't necessarily automation managers, like invoiceTemplateMap.
     *
     * Source-aware (the Shopify-staging vs prod id collision): ETA is computed
     * ONLY for prod orders via EtaWindowService::forOrder (which queries
     * t_crm_prod_order) — a Shopify-staging id is NEVER looked up there.
     */
    public function invoiceSendPlan(Request $request, $orderId)
    {
        $isShopify = $request->input('source') === 'shopify';
        $plan = \App\Services\WhatsApp\InvoiceSendPlanService::for($orderId, $isShopify);
        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        return response()->json([
            'success'      => true,
            'template'     => $plan['template'],
            'body_params'  => $plan['body_params'],
            'eta'          => $plan['eta'],
            'payment_kind' => $plan['payment_kind'],
        ]);
    }

    /**
     * GET /messages/automations/log — recent activity for the tracker.
     * Optional ?rule_key= and ?status= filters; capped at 200 rows.
     */
    public function log(Request $request)
    {
        $this->gate();

        if (!Schema::hasTable('t_wa_automation_log')) {
            return response()->json(['success' => true, 'log' => [], 'counts' => []]);
        }

        $q = AutomationLogModel::query()->orderByDesc('id');
        if ($request->filled('rule_key')) {
            $q->where('rule_key', $request->input('rule_key'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        $rows = $q->limit(200)->get()->map(function ($r) {
            return [
                'id'            => (int) $r->id,
                'rule_key'      => $r->rule_key,
                'trigger_event' => $r->trigger_event,
                'order_id'      => $r->order_id,
                'template_name' => $r->template_name,
                'status'        => $r->status,
                'skip_reason'   => $r->skip_reason,
                'error_message' => $r->error_message,
                'created_at'    => $r->created_at?->toIso8601String(),
            ];
        });

        // Lightweight sent/failed/skipped tallies for the last 7 days.
        $counts = AutomationLogModel::where('created_at', '>=', now()->subDays(7))
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return response()->json([
            'success' => true,
            'log'     => $rows,
            'counts'  => $counts,
        ]);
    }
}
