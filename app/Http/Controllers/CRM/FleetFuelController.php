<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\FleetFuelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Fleet & Fuel (Jul-2026) — read API for the riders-map "⛽ Fleet" tab.
 *
 * ACCESS: this is money, so unlike Day Review's base layer it is gated. A user
 * qualifies through EITHER of the two roles that already legitimately see this
 * data today — rider-operations managers (`view_rider_reports`, who review
 * rider accountability) or finance (`web_menu_finance_hub`, who already see
 * every petrol/maintenance request on the Daily Closing screen). No new
 * permission to seed, and nobody sees anything they could not already reach.
 */
class FleetFuelController extends Controller
{
    // `view_bike_costs` is the standalone key — someone can be given Bikes alone
    // (a Khaas-mode user reviewing running costs) with no rider-ops or finance
    // access attached. Mirrors OrderController::ridersMap(); keep them in sync.
    const PERMISSIONS = ['view_rider_reports', 'web_menu_finance_hub', 'view_bike_costs'];
    const CACHE_SECS = 120;     // a month's numbers barely move within 2 minutes

    /** Month summary — one row per rider. */
    public function month(Request $request, FleetFuelService $svc)
    {
        if (!$this->allowed()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            $month = $this->safeMonth($request->query('month'));
            if ($month === null) {
                return response()->json(['success' => false, 'message' => 'Invalid month'], 422);
            }

            $data = Cache::remember("fleet_fuel_month_{$month}", self::CACHE_SECS,
                fn () => $svc->monthSummary($month));

            return response()->json(array_merge(['success' => true], $data));
        } catch (\Throwable $e) {
            \Log::error('FleetFuel month failed', ['error' => $e->getMessage(), 'month' => $request->query('month')]);
            return response()->json(['success' => false, 'message' => 'Failed to load the month'], 500);
        }
    }

    /** One rider's month, day by day, with claims and photos. */
    public function rider(Request $request, FleetFuelService $svc)
    {
        if (!$this->allowed()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            $month = $this->safeMonth($request->query('month'));
            $uid   = (int) $request->query('rider_id');
            if ($month === null) {
                return response()->json(['success' => false, 'message' => 'Invalid month'], 422);
            }
            if ($uid <= 0) {
                return response()->json(['success' => false, 'message' => 'rider_id required'], 422);
            }

            // fresh=1 → recompute now. Used by the Daily Closing approval popup:
            // a manager reviewing a request that arrived seconds ago must see it,
            // not a 2-minute-old cache. The recompute also refreshes the cache.
            $key = "fleet_fuel_rider_{$uid}_{$month}";
            if ($request->boolean('fresh')) {
                $data = $svc->riderMonth($uid, $month);
                Cache::put($key, $data, self::CACHE_SECS);
            } else {
                $data = Cache::remember($key, self::CACHE_SECS,
                    fn () => $svc->riderMonth($uid, $month));
            }

            // Approval controls are shown only to users who can actually approve.
            // Seeing the Fleet numbers (rider-ops / finance) is a different right
            // from approving money, so the two are resolved separately and the
            // buttons never appear for someone the server would reject anyway.
            return response()->json([
                'success' => true,
                'rider' => $data,
                'approval' => $this->approvalContext(),
                // Drives whether the service controls render at all.
                'can_manage_service' => $this->canManageService(),
                'default_interval_km' => (int) $this->cfgValue('BIKE_SERVICE_INTERVAL_KM', 3000),
            ]);
        } catch (\Throwable $e) {
            \Log::error('FleetFuel rider failed', [
                'error' => $e->getMessage(), 'rider' => $request->query('rider_id'),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to load this rider'], 500);
        }
    }

    /**
     * Record a service (oil change) against a bike — the manual reset that sits
     * alongside the automatic one from approving an oil-change request.
     */
    public function markServiced(Request $request)
    {
        // Writing a schedule is a separate right from reading the costs — see
        // canManageService(). Viewing Bikes never implies changing when a bike
        // is due.
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change service schedules'], 403);
        }

        // ⭐ Two DIFFERENT actions share this endpoint and must not bleed into
        //    each other:
        //      • meter given       → a service HAPPENED: reset the due clock.
        //      • interval_km given → the SCHEDULE changed: how often it is due.
        //    `meter` used to be required, so changing a bike's interval silently
        //    stamped last_service_meter and marked the bike as just-serviced —
        //    which made "Record service" and "This bike" behave identically.
        //    Each is now optional; at least one must be present.
        $data = $request->validate([
            'rider_id' => 'required|integer',
            'meter'    => 'nullable|integer|min:0',
            'date'     => 'nullable|date',
            // 0 / null means "follow the company default" (BIKE_SERVICE_INTERVAL_KM).
            'interval_km' => 'nullable|integer|min:0|max:100000',
        ]);

        if (!$request->filled('meter') && !$request->filled('interval_km')) {
            return response()->json([
                'success' => false,
                'message' => 'Give either the odometer at the service, or a new schedule.',
            ], 422);
        }

        try {
            $exists = \DB::table('t_ops_rider_profile')->where('user_id', $data['rider_id'])->exists();
            if (!$exists) {
                return response()->json(['success' => false, 'message' => 'No rider profile for that user'], 404);
            }

            $update = ['updated_at' => now()];

            // A service actually happened → reset the due clock.
            if ($request->filled('meter')) {
                $update['last_service_meter'] = (int) $data['meter'];
                $update['last_service_at']    = $data['date'] ?? Carbon::today()->format('Y-m-d');
            }
            // The schedule changed → how often it falls due. Never touches when
            // it was last serviced.
            if ($request->filled('interval_km')) {
                $update['service_interval_km'] = ((int) $data['interval_km']) > 0
                    ? (int) $data['interval_km'] : null;
            }

            \DB::table('t_ops_rider_profile')
                ->where('user_id', $data['rider_id'])
                ->update($update);

            // Targeted invalidation only — a global Cache::flush() here would also
            // wipe unrelated caches (rider_reports_live, tz offset, ...). Month
            // summaries embed the service state, so drop the keys the UI is
            // realistically looking at; anything else expires within CACHE_SECS.
            $thisMonth = Carbon::today()->format('Y-m');
            $prevMonth = Carbon::today()->subMonthNoOverflow()->format('Y-m');
            foreach ([$thisMonth, $prevMonth] as $m) {
                Cache::forget("fleet_fuel_month_{$m}");
                Cache::forget("fleet_fuel_rider_{$data['rider_id']}_{$m}");
            }

            // Say back what actually changed. "Service recorded" on a schedule
            // change is what made the two buttons look interchangeable.
            $said = [];
            if ($request->filled('meter')) {
                $said[] = 'Service recorded at ' . number_format((int) $data['meter']) . ' km';
            }
            if ($request->filled('interval_km')) {
                $said[] = ((int) $data['interval_km']) > 0
                    ? 'Now due every ' . number_format((int) $data['interval_km']) . ' km'
                    : 'Now follows the company default';
            }

            return response()->json(['success' => true, 'message' => implode('. ', $said)]);
        } catch (\Throwable $e) {
            \Log::error('FleetFuel markServiced failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the change'], 500);
        }
    }

    // ---- mobile entries (Sanctum) -------------------------------------
    //
    // Gated on the MOBILE `view_bike_costs` key — Bikes' OWN permission, so it
    // can be given and taken away on its own from Roles → Mobile Permissions.
    // It used to accept `view_rider_reports` OR `view_expenses`, which meant
    // Bikes could not be revoked without also removing Day Review / Daily Issues
    // (or the expense screens). Both of those roles were granted the new key by
    // batch-7 §7, so the switch changed nobody's access on the day it shipped.
    // Approval rights are role-based and shared with the web, so
    // approvalContext() needs no mobile variant.

    public function apiMonth(Request $request, FleetFuelService $svc)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $this->mobileContext = true;
        return $this->month($request, $svc);
    }

    public function apiRider(Request $request, FleetFuelService $svc)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $this->mobileContext = true;
        return $this->rider($request, $svc);
    }

    /** True while serving a mobile call — makes allowed() read the mobile grants. */
    private bool $mobileContext = false;

    private function mobileAllowed(Request $request): bool
    {
        $u = $request->user();
        if (!$u || !method_exists($u, 'hasMobilePermission')) return false;
        // Bikes' own key. Authoritative on purpose — an additive gate
        // (this OR view_rider_reports OR view_expenses) would mean unticking it
        // changes nothing, which is exactly the problem it exists to fix.
        return $u->hasMobilePermission('view_bike_costs');
    }

    /**
     * Set the COMPANY-WIDE default service interval — the figure every bike
     * inherits unless it has its own override. Separate endpoint because it is
     * a fleet-level setting, not a per-bike one, and one careless edit moves
     * every bike's due date at once.
     */
    public function setDefaultInterval(Request $request)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change service schedules'], 403);
        }

        $data = $request->validate([
            'interval_km' => 'required|integer|min:100|max:100000',
        ]);

        try {
            \DB::table('t_fin_config')->updateOrInsert(
                ['config_key' => 'BIKE_SERVICE_INTERVAL_KM'],
                [
                    'config_value' => (string) $data['interval_km'],
                    'description'  => 'Default km between bike services (oil change + general). Per-bike override lives on t_ops_rider_profile.service_interval_km.',
                    'updated_at'   => now(),
                ]
            );

            // Targeted invalidation only — the same rule markServiced follows above.
            // A global Cache::flush() here also wiped rider_reports_live, the tz
            // offset and the Google ETA cache (a burst of paid Maps calls on a live
            // board) for a setting that changes maybe twice a year. The default
            // interval touches EVERY bike, so drop the month summaries plus every
            // rider's detail for this + previous month; older months a manager may
            // be viewing self-expire within CACHE_SECS (120 s).
            $thisMonth = Carbon::today()->format('Y-m');
            $prevMonth = Carbon::today()->subMonthNoOverflow()->format('Y-m');
            $riderIds  = \DB::table('t_ops_rider_profile')->pluck('user_id');
            foreach ([$thisMonth, $prevMonth] as $m) {
                Cache::forget("fleet_fuel_month_{$m}");
                foreach ($riderIds as $rid) {
                    Cache::forget("fleet_fuel_rider_{$rid}_{$m}");
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Every bike without its own schedule is now serviced every '
                             . number_format($data['interval_km']) . ' km',
                'interval_km' => (int) $data['interval_km'],
            ]);
        } catch (\Throwable $e) {
            \Log::error('FleetFuel setDefaultInterval failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the default'], 500);
        }
    }

    /** Mobile entry for the same setting. */
    public function apiSetDefaultInterval(Request $request)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $this->mobileContext = true;
        return $this->setDefaultInterval($request);
    }

    /** Mobile entry for recording a service / setting a bike's own interval. */
    public function apiMarkServiced(Request $request)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $this->mobileContext = true;
        return $this->markServiced($request);
    }

    /**
     * May this user CHANGE service schedules (record a service, set a bike's
     * interval, set the company default)?
     *
     * Deliberately its own permission: someone can be given Bikes to read the
     * running costs without also being able to move when a bike falls due.
     * View-only accounts are refused outright. Mobile calls check the MOBILE
     * grant, web calls the WEB one — the two tables are separate.
     */
    /** One config row, with a fallback — mirrors FleetFuelService::cfg(). */
    private function cfgValue(string $key, $default)
    {
        try {
            $v = \DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function canManageService(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;

        if ($this->mobileContext) {
            return method_exists($u, 'hasMobilePermission')
                && $u->hasMobilePermission('manage_bike_service');
        }
        return (bool) $u->hasPermission('manage_bike_service');
    }

    /**
     * What this user may approve, and the payment sources they can charge it to.
     *
     * Levels come from the same RoleApprovalLevelModel the approval endpoint
     * checks, so the UI can never offer a button that will 403. The account list
     * is identical to the Daily Closing screen's (same codes, same order, NF_CASH
     * first) — approving from Fleet must not silently book money differently.
     */
    private function approvalContext(): array
    {
        $u = auth()->user();
        $levels = [];
        $readOnly = $u && method_exists($u, 'isReadOnly') && $u->isReadOnly();

        if ($u && !$readOnly) {
            foreach ([1, 2] as $lvl) {
                try {
                    if (\App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($u->id, $lvl)) {
                        $levels[] = $lvl;
                    }
                } catch (\Throwable $e) {
                    // If approval config can't be read, offer nothing rather than
                    // showing a button that would fail.
                }
            }
        }

        $accounts = [];
        if ($levels) {
            try {
                $accounts = \App\Models\FIN\AccountModel::whereIn('account_code', ['NF_CASH', 'EXP_FUND', 'ONLINE', 'PETTY_CASH'])
                    ->where('is_active', 1)
                    ->orderByRaw("CASE WHEN account_code = 'NF_CASH' THEN 1 WHEN account_code = 'EXP_FUND' THEN 2 WHEN account_code = 'ONLINE' THEN 3 ELSE 4 END")
                    ->get(['id', 'account_code', 'account_name'])
                    ->toArray();
            } catch (\Throwable $e) {
                $accounts = [];
            }
        }

        return [
            'can_approve' => !empty($levels),
            'levels'      => $levels,
            'read_only'   => (bool) $readOnly,
            'accounts'    => $accounts,
        ];
    }

    // ---- helpers ------------------------------------------------------

    private function allowed(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        // A mobile call already passed mobileAllowed() against the mobile grants;
        // re-checking the WEB grants here would refuse store users who legitimately
        // hold only the mobile permission.
        if ($this->mobileContext) return true;
        foreach (self::PERMISSIONS as $p) {
            if ($u->hasPermission($p)) return true;
        }
        return false;
    }

    /** Clamp to a real YYYY-MM, never the future. */
    private function safeMonth($raw): ?string
    {
        try {
            $c = $raw ? Carbon::parse($raw . '-01') : Carbon::today()->startOfMonth();
        } catch (\Throwable $e) {
            return null;
        }
        if ($c->gt(Carbon::today()->startOfMonth())) $c = Carbon::today()->startOfMonth();
        return $c->format('Y-m');
    }
}
