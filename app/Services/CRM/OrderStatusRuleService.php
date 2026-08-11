<?php

namespace App\Services\CRM;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Order Status Hub — central rule engine.
 *
 * Single source of truth for status-driven behaviour that used to be hardcoded in many
 * controllers. Phase 1 wires ONLY the two Quantities-tab concepts below; later phases add
 * closed-order, staff-app and customer-app rules on top of the same cached read.
 *
 * DESIGN GUARANTEES
 *  - Deploy-order safe: if the Phase-0 columns aren't present yet (code uploaded before the
 *    SQL was run), every method returns the exact historical literal, so nothing breaks.
 *  - Perf safe: results are memoised for 60s, so the huge Quantities queries pay at most a
 *    few tiny lookups once per minute — the aggregation SQL itself is unchanged.
 *  - Equivalence: on day 1 the returned arrays reproduce today's behaviour byte-for-byte
 *    (see ORDER-STATUS-HUB-PLAN-JUL2026.md §4, Phase 1).
 */
class OrderStatusRuleService
{
    // v2 (Aug-2026): the cached array gained `hold_map`. Bumping the key means a
    // running server never reads a v1 array that is missing it.
    private const CACHE_KEY = 'order_status_rules_v2';
    private const CACHE_TTL = 60; // seconds

    /** Historical hardcoded fallbacks — used verbatim when the new schema/config is absent. */
    private const FALLBACK_EXCLUDED = ['delivered', 'completed', 'cancelled', 'refunded'];
    private const FALLBACK_OUT_DOOR = ['out_for_delivery', 'delivered', 'completed'];

    /**
     * Statuses excluded from the Open-Order Quantities NORMAL view.
     * Phase 1: identical to the legacy read (this environment's excluded_statuses setting,
     * with the same literal fallback) — a pure refactor, no behaviour change.
     */
    public function quantitiesExcluded(): array
    {
        return $this->rules()['quantities_excluded'];
    }

    /**
     * Statuses whose orders have gone "out the door": items are auto-marked prepared and the
     * order drops out of BOTH quantities views. Replaces the hardcoded
     * ['out_for_delivery','delivered','completed'] in the mobile Prepared-view queries.
     */
    public function outTheDoor(): array
    {
        return $this->rules()['out_the_door'];
    }

    /**
     * Does entering this status auto-mark items as prepared (and hide the order from both
     * quantities views)? True for the "out the door" set. Used by OrderModel::changeStatus.
     */
    public function autoPreparesOn(string $statusCode): bool
    {
        return in_array($statusCode, $this->outTheDoor(), true);
    }

    /**
     * Should a change INTO this status be pushed to the customer app?
     * Default true (today's behaviour) when the column/config is absent.
     */
    public function sendToCustomerApp(string $statusCode): bool
    {
        $map = $this->rules()['send_map'];
        return array_key_exists($statusCode, $map) ? (bool) $map[$statusCode] : true;
    }

    /**
     * The name the customer app should show for this status instead of the raw code,
     * or null to send it as-is.
     */
    public function customerAlias(string $statusCode): ?string
    {
        $map = $this->rules()['alias_map'];
        return $map[$statusCode] ?? null;
    }

    /**
     * Does this status stay hidden from the customer until the order is actually
     * dispatched? Default false when the column (or the row) is absent, so nothing
     * changes for a status nobody has flagged in the Hub.
     */
    public function holdsUntilDispatch(string $statusCode): bool
    {
        $map = $this->rules()['hold_map'] ?? [];
        return array_key_exists($statusCode, $map) ? (bool) $map[$statusCode] : false;
    }

    /**
     * Is THIS order's status being held back from the customer right now? Three
     * things must all be true: the status carries the hold flag, the order has not
     * been dispatched yet, and the order is one the customer app knows about.
     *
     * $isDispatched is the caller's own reading of eta_calculated_at — the same test
     * the "Dispatch Next" wave uses. Every path that un-dispatches an order already
     * clears that stamp, so this needs no bookkeeping of its own.
     */
    public function masksUntilDispatch(?string $orderNumber, string $statusCode, bool $isDispatched): bool
    {
        if ($isDispatched) {
            return false;
        }
        if (!$this->holdsUntilDispatch($statusCode)) {
            return false;
        }
        return $this->inCustomerAppScope($orderNumber);
    }

    /**
     * THE customer-facing status for one order.
     *
     * Every customer-app surface — the outbound webhook and all pull endpoints —
     * goes through this one method, so push and pull can never disagree about what
     * the customer is being told. Order matters: hold-substitution first, then the
     * alias map (so aliasing the substitute status keeps working).
     */
    public function customerFacingStatus(?string $orderNumber, string $statusCode, bool $isDispatched): string
    {
        if ($this->masksUntilDispatch($orderNumber, $statusCode, $isDispatched)) {
            $statusCode = (string) config('customer_app.hold_until_dispatch_status', 'processing');
        }

        return $this->customerAlias($statusCode) ?? $statusCode;
    }

    /**
     * Only orders the customer app actually knows about can be masked — the same
     * prefix set the webhook emitter filters on (SH-).
     *
     * ⚠ This is deliberate, not an oversight: QURBANI dispatches through its own
     * qurbani_eta_calculated_at columns and never stamps the order-level one, so a
     * genuinely-dispatched QUR order would look "never dispatched" and be masked to
     * processing on the history/snapshot endpoints. Scoping to the push prefixes
     * keeps NF- and QUR orders byte-identical to today.
     */
    private function inCustomerAppScope(?string $orderNumber): bool
    {
        if (empty($orderNumber)) {
            return false;
        }

        foreach ((array) config('customer_app.order_prefixes', ['SH-']) as $prefix) {
            if ($prefix !== '' && str_starts_with($orderNumber, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Call after the Hub saves changes so the next read is fresh. */
    public function bustCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // cache backend unavailable — the 60s TTL will refresh it anyway
        }
    }

    // ---------------------------------------------------------------------

    private function rules(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                $customer = $this->customerMapsFromColumn();
                return [
                    'quantities_excluded' => $this->excludedFromSetting(),
                    'out_the_door'        => $this->outDoorFromColumn(),
                    'send_map'            => $customer['send'],
                    'alias_map'           => $customer['alias'],
                    'hold_map'            => $customer['hold'],
                ];
            });
        } catch (\Throwable $e) {
            // Never let a config/cache hiccup break a live endpoint — fall back to literals.
            Log::warning('OrderStatusRuleService: falling back to literals', ['error' => $e->getMessage()]);
            return [
                'quantities_excluded' => self::FALLBACK_EXCLUDED,
                'out_the_door'        => self::FALLBACK_OUT_DOOR,
                'send_map'            => [],   // empty => sendToCustomerApp() defaults to true
                'alias_map'           => [],   // empty => customerAlias() defaults to null
                'hold_map'            => [],   // empty => holdsUntilDispatch() defaults to false
            ];
        }
    }

    /**
     * Reads the same excluded_statuses setting the endpoints read today. Kept as the source
     * for the normal view in Phase 1 so output is unchanged per environment; the Hub makes
     * the per-status column authoritative in a later phase.
     */
    private function excludedFromSetting(): array
    {
        $row = DB::table('t_crm_open_quantities_settings')
            ->where('setting_key', 'excluded_statuses')
            ->first();

        $value = $row ? json_decode($row->setting_value, true) : null;

        if (!is_array($value) || empty($value)) {
            return self::FALLBACK_EXCLUDED;
        }

        return array_values($value);
    }

    /**
     * Reads the auto_prepares column (Phase 0). If the column isn't there yet, or is empty,
     * returns the historical literal — so the mobile Prepared view behaves exactly as before.
     */
    private function outDoorFromColumn(): array
    {
        if (Schema::hasColumn('t_crm_order_status_master', 'auto_prepares')) {
            $codes = DB::table('t_crm_order_status_master')
                ->where('auto_prepares', 1)
                ->pluck('status_code')
                ->all();

            if (!empty($codes)) {
                return array_values($codes);
            }
        }

        return self::FALLBACK_OUT_DOOR;
    }

    /**
     * Builds the per-status customer-app maps (send flag + alias + hold) from the Hub
     * columns. Absent columns => empty maps => callers default to "send raw, never
     * hold" (today's behaviour).
     */
    private function customerMapsFromColumn(): array
    {
        $send = [];
        $alias = [];
        $hold = [];

        if (Schema::hasColumn('t_crm_order_status_master', 'send_to_customer_app')) {
            // The hold column (Aug-2026) ships later than send/alias, so it is only
            // selected when present — the file stays uploadable before its SQL is run.
            $hasHold = Schema::hasColumn('t_crm_order_status_master', 'customer_app_hold_until_dispatch');

            $columns = ['status_code', 'send_to_customer_app', 'customer_app_alias'];
            if ($hasHold) {
                $columns[] = 'customer_app_hold_until_dispatch';
            }

            $rows = DB::table('t_crm_order_status_master')->get($columns);

            foreach ($rows as $row) {
                $send[$row->status_code] = (bool) $row->send_to_customer_app;
                $trimmed = is_string($row->customer_app_alias) ? trim($row->customer_app_alias) : '';
                $alias[$row->status_code] = $trimmed !== '' ? $trimmed : null;
                $hold[$row->status_code] = $hasHold
                    ? (bool) $row->customer_app_hold_until_dispatch
                    : false;
            }
        }

        return ['send' => $send, 'alias' => $alias, 'hold' => $hold];
    }
}
