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

            // fresh=1 → recompute now. Used right after a claim is created from the
            // Bikes screen itself: a 2-minute-old cache would show the manager his
            // own new request missing. Same contract as rider().
            $key = "fleet_fuel_month_{$month}";
            if ($request->boolean('fresh')) {
                $data = $svc->monthSummary($month);
                Cache::put($key, $data, self::CACHE_SECS);
            } else {
                $data = Cache::remember($key, self::CACHE_SECS, fn () => $svc->monthSummary($month));
            }

            // ⚠ Merged OUTSIDE the cache on purpose: the cache key is the month
            // alone, with no user in it, while the pay-from list is per user
            // (permission + business unit). Caching it would serve one manager's
            // accounts to another.
            return response()->json(array_merge(
                ['success' => true],
                $data,
                $this->paySourceContext()
            ));
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

    // ── Maintenance types (Aug-2026) ──────────────────────────────────────────
    //
    // A named, manager-editable list on top of the two buckets. The manager runs
    // the workshop schedule (oil 1,200 km, oil+tuning 2,500, brake shoe 10,000,
    // chain set and misc as conditions) and adds repair types as he meets them,
    // so the list has to be data he owns rather than a hardcoded picker.
    // Gate: `manage_bike_service` — the same right that already covers changing a
    // bike's service schedule, which Qasim holds. Reading is open to anyone who
    // can open Bikes, because the pickers need it.

    /** JSON: the full list, including retired types, for the manage screen. */
    public function maintenanceTypes(Request $request)
    {
        if (!$this->allowed()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $svc = app(\App\Services\Riders\MaintenanceTypeService::class);

        return response()->json([
            'success'    => true,
            'available'  => $svc->available(),
            'can_manage' => $this->canManageService(),
            'types'      => $svc->options(true),
        ]);
    }

    /** Create or update one type. */
    public function saveMaintenanceType(Request $request, $id = null)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change maintenance types'], 403);
        }
        $svc = app(\App\Services\Riders\MaintenanceTypeService::class);
        if (!$svc->available()) {
            return response()->json(['success' => false, 'message' => 'Maintenance types are not set up yet (SQL batch 12).'], 422);
        }

        $data = $request->validate([
            'type_name'   => 'required|string|max:80',
            'bucket'      => 'required|in:regular,repair',
            // NULL / 0 = "as conditions" — Chain Set and Misc have no schedule and
            // must never nag.
            'interval_km' => 'nullable|integer|min:0|max:200000',
            'resets_service_clock' => 'nullable|boolean',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0|max:9999',
        ]);

        try {
            $model = $id
                ? \App\Models\Riders\MaintenanceTypeModel::find((int) $id)
                : new \App\Models\Riders\MaintenanceTypeModel();
            if ($id && !$model) {
                return response()->json(['success' => false, 'message' => 'That maintenance type no longer exists.'], 404);
            }

            // The name is the natural key and history reads it — a duplicate would
            // make two rows indistinguishable on every past claim.
            $clash = \App\Models\Riders\MaintenanceTypeModel::where('type_name', $data['type_name'])
                ->when($id, fn ($q) => $q->where('id', '!=', (int) $id))->exists();
            if ($clash) {
                return response()->json(['success' => false, 'message' => 'There is already a type with that name.'], 422);
            }

            $model->type_name   = $data['type_name'];
            $model->bucket      = $data['bucket'];
            $model->interval_km = ((int) ($data['interval_km'] ?? 0)) > 0 ? (int) $data['interval_km'] : null;
            // Only a REGULAR service can reset the service clock; a repair never
            // does, whatever the form sends.
            $model->resets_service_clock = $data['bucket'] === 'regular'
                ? (bool) ($data['resets_service_clock'] ?? false) : false;
            $model->is_active  = (bool) ($data['is_active'] ?? true);
            $model->sort_order = (int) ($data['sort_order'] ?? 0);
            if (!$model->exists) { $model->created_by = auth()->id(); }
            $model->updated_by = auth()->id();
            $model->save();

            $this->forgetFleetCaches();

            return $this->maintenanceTypes($request);
        } catch (\Throwable $e) {
            \Log::error('saveMaintenanceType failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that type.'], 500);
        }
    }

    /**
     * Retire a type. ⭐ Never a hard delete once anything references it — a past
     * claim must keep showing the name it was filed under, and the service clock
     * reads the type's flag when re-evaluating history. An unused type is removed
     * outright so a typo does not linger.
     */
    public function deleteMaintenanceType(Request $request, $id)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change maintenance types'], 403);
        }
        try {
            $model = \App\Models\Riders\MaintenanceTypeModel::find((int) $id);
            if (!$model) {
                return $this->maintenanceTypes($request);
            }
            $inUse = \DB::table('t_req_master')->where('maintenance_type_id', $model->id)->exists();
            if ($inUse) {
                $model->is_active  = false;
                $model->updated_by = auth()->id();
                $model->save();
            } else {
                $model->delete();
            }
            $this->forgetFleetCaches();
            return $this->maintenanceTypes($request);
        } catch (\Throwable $e) {
            \Log::error('deleteMaintenanceType failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not remove that type.'], 500);
        }
    }

    /**
     * ⭐ Correct a claim a rider already filed (owner, Aug-3): "once the rider
     * sends it, Qasim or Shabib should be able to edit it if the wrong category
     * was picked, or any other such issue."
     *
     * PENDING ONLY, deliberately. Once a claim is approved its money is in the
     * ledger and its service may have reset the bike's clock — silently editing
     * that row would leave the ledger and the clock disagreeing with the record.
     * An approved claim is corrected the way it always was: reverse it and file
     * it again.
     *
     * Re-runs FuelClaimRules on the EDITED values, so an edit cannot land a claim
     * in a state the same claim could not have been filed in — e.g. switching a
     * repair to a regular service on a company bike without a meter reading.
     */
    public function editClaim(Request $request, $id)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to edit bike claims'], 403);
        }

        $data = $request->validate([
            'maintenance_type_id' => 'nullable|integer',
            'amount'        => 'nullable|numeric|min:1|max:9999999',
            'meter_at_fill' => 'nullable|integer|min:0|max:9999999',
            'expense_date'  => 'nullable|date',
            'description'   => 'nullable|string|max:1000',
        ]);

        try {
            $req = \App\Models\Request\RequestModel::with('category')->find((int) $id);
            if (!$req) {
                return response()->json(['success' => false, 'message' => 'That request no longer exists.'], 404);
            }
            if (!in_array($req->expense_category, ['Petrol', 'Maintenance'], true)) {
                return response()->json(['success' => false, 'message' => 'This screen only edits fuel and maintenance claims.'], 422);
            }
            if ($req->status !== \App\Models\Request\RequestModel::STATUS_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'This claim is already ' . $req->status . '. An approved claim has money in the ledger — reverse it and file it again instead.',
                ], 422);
            }

            $svcTypes = app(\App\Services\Riders\MaintenanceTypeService::class);
            $isMaint  = $req->expense_category === 'Maintenance';

            // Only resolve a type for maintenance; a petrol claim never carries one.
            [$serviceType, $typeId] = $isMaint
                ? $svcTypes->resolve(
                    array_key_exists('maintenance_type_id', $data) ? $data['maintenance_type_id'] : $req->maintenance_type_id,
                    $req->service_type
                  )
                : [null, null];

            $amount = array_key_exists('amount', $data) && $data['amount'] !== null
                ? (float) $data['amount'] : (float) $req->amount;
            $meter = array_key_exists('meter_at_fill', $data)
                ? ($data['meter_at_fill'] !== null ? (int) $data['meter_at_fill'] : null)
                : ($req->meter_at_fill !== null ? (int) $req->meter_at_fill : null);
            $date = $data['expense_date'] ?? ($req->expense_date
                ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d') : null);

            // The edited claim must satisfy the same rules as a fresh one. Keyed to
            // the RIDER the claim belongs to (his bike, his odometer), with the
            // editor as actor so the own-bike petrol block steps aside exactly as
            // it does when a manager files on his behalf.
            // ⚠ `ignore_request_id` keeps the duplicate-claim guard from seeing THIS
            // row as its own duplicate — without it, saving an edit unchanged would
            // reject itself.
            $rules = (new \App\Services\Riders\FuelClaimRules())->check(
                (int) $req->requester_user_id,
                $req->expense_category,
                [
                    'amount'        => $amount,
                    'expense_date'  => $date,
                    'meter_at_fill' => $meter,
                    'service_type'  => $serviceType,
                    'attendance_id' => $req->attendance_id,
                    'ignore_request_id' => $req->id,
                ],
                (int) auth()->id()
            );
            if (!$rules['ok']) {
                return response()->json(['success' => false, 'message' => $rules['message']], 422);
            }

            $before = [
                'maintenance_type_id' => $req->maintenance_type_id,
                'service_type' => $req->service_type,
                'amount' => (float) $req->amount,
                'meter_at_fill' => $req->meter_at_fill,
                'expense_date' => $req->expense_date ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d') : null,
            ];

            if ($isMaint) {
                $req->service_type = $serviceType;
                $req->maintenance_type_id = $typeId;
            }
            $req->amount = $amount;
            $req->meter_at_fill = $meter;
            if ($date) { $req->expense_date = $date; }
            if (array_key_exists('description', $data) && $data['description'] !== null) {
                $req->description = $data['description'];
            }
            $req->updated_by = auth()->id();
            $req->save();

            // The rider is told his claim was corrected, in the record itself —
            // an edit that leaves no trace is indistinguishable from him having
            // filed it that way.
            \Log::info('Bike claim edited before approval', [
                'request_id' => $req->id, 'editor' => auth()->id(),
                'before' => $before,
                'after'  => [
                    'maintenance_type_id' => $req->maintenance_type_id,
                    'service_type' => $req->service_type,
                    'amount' => (float) $req->amount,
                    'meter_at_fill' => $req->meter_at_fill,
                    'expense_date' => $req->expense_date ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d') : null,
                ],
            ]);

            $this->forgetFleetCaches();

            return response()->json([
                'success' => true,
                'message' => 'Claim updated.',
                'claim'   => [
                    'id' => $req->id,
                    'amount' => (float) $req->amount,
                    'meter_at_fill' => $req->meter_at_fill,
                    'expense_date' => $req->expense_date ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d') : null,
                    'maintenance_type_id' => $req->maintenance_type_id,
                    'maintenance_type' => $svcTypes->labelFor($req->maintenance_type_id, $req->service_type),
                    'service_type' => $req->service_type,
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::error('editClaim failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that change.'], 500);
        }
    }

    /** Drop the month/rider caches the Bikes screens read. */
    private function forgetFleetCaches(): void
    {
        try {
            foreach ([Carbon::today()->format('Y-m'), Carbon::today()->subMonthNoOverflow()->format('Y-m')] as $m) {
                Cache::forget("fleet_fuel_month_{$m}");
                foreach (\DB::table('t_ops_rider_profile')->pluck('user_id') as $rid) {
                    Cache::forget("fleet_fuel_rider_{$rid}_{$m}");
                }
            }
        } catch (\Throwable $e) {
            // Caches expire within CACHE_SECS anyway — never fail a write over this.
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
            // ⭐ WHICH service was done. Optional (an old APK sends none), but when
            // given it must be one that actually resets the clock — see below.
            'maintenance_type_id' => 'nullable|integer',
        ]);

        // ⚠ Any type WITH A SCHEDULE can be recorded here — those are exactly the
        // ones the service-schedule panel counts down (oil 1,200 · oil+tuning
        // 2,500 · brake shoe 10,000). An earlier cut allowed only clock-resetting
        // types, which left Brake Shoe with a visible countdown and no way on
        // earth to reset it. What differs per type is the EFFECT, below: only a
        // clock-resetting one moves the bike's overall service-due clock, so a
        // brake-shoe job still cannot make an overdue oil change look done.
        // "As conditions" types (Chain Set, Misc) are refused: they have no
        // countdown, so there is nothing here to record against.
        $recordType = null;
        if ($request->filled('maintenance_type_id') && $request->filled('meter')) {
            $recordType = app(\App\Services\Riders\MaintenanceTypeService::class)
                ->find($data['maintenance_type_id']);
            if (!$recordType) {
                return response()->json(['success' => false, 'message' => 'That maintenance type no longer exists.'], 422);
            }
            if ((int) $recordType->interval_km <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => '"' . $recordType->type_name . '" is done as conditions require, so it has no due date to reset. '
                        . 'File it as a maintenance request instead — that keeps the bill and the photo with it.',
                ], 422);
            }
        }

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

            // A service actually happened.
            if ($request->filled('meter')) {
                $serviceDate = $data['date'] ?? Carbon::today()->format('Y-m-d');

                // Every scheduled type gets a log row, so the per-type countdown on
                // the Bikes drawer resets. Deliberately NOT a zero-amount expense
                // request: a service record is not a money movement, and faking one
                // would put Rs 0 rows into the expense reports and the ledger.
                if ($recordType && \Illuminate\Support\Facades\Schema::hasTable('t_fleet_service_log')) {
                    \DB::table('t_fleet_service_log')->insert([
                        'user_id'             => (int) $data['rider_id'],
                        'maintenance_type_id' => (int) $recordType->id,
                        'meter'               => (int) $data['meter'],
                        'service_date'        => $serviceDate,
                        'note'                => 'Recorded on the Bikes screen (no bill filed)',
                        'created_by'          => auth()->id(),
                        'created_at'          => now(),
                    ]);
                }

                // ⭐ ONLY a clock-resetting type moves the bike's overall service-due
                // clock. A brake-shoe job is real work on its own 10,000 km cycle,
                // but it must never make an overdue oil change look done — the same
                // rule the approval path enforces via BikeServiceClock.
                if (!$recordType || $recordType->resets_service_clock) {
                    $update['last_service_meter'] = (int) $data['meter'];
                    $update['last_service_at']    = $serviceDate;

                    // And the schedule follows the work done: an Oil + Tuning means
                    // due again in 2,500 km, not whatever the bike's old override
                    // said. (A claim approved the normal way derives this from its
                    // own request row; a hand-recorded service has none, so the
                    // per-bike override is where it lands.)
                    if ($recordType && (int) $recordType->interval_km > 0) {
                        $update['service_interval_km'] = (int) $recordType->interval_km;
                    }
                }
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
                // Name the job and its next due, and be explicit when it did NOT
                // move the bike's overall clock — otherwise recording brake shoes
                // reads as "the bike is serviced", which is the whole confusion
                // the per-type schedule exists to remove.
                $said[] = ($recordType ? $recordType->type_name : 'Service')
                    . ' recorded at ' . number_format((int) $data['meter']) . ' km'
                    . ($recordType && (int) $recordType->interval_km > 0
                        ? ' — next due at ' . number_format((int) $data['meter'] + (int) $recordType->interval_km) . ' km'
                        : '');
                if ($recordType && !$recordType->resets_service_clock) {
                    $said[] = 'The bike\'s overall service-due clock is unchanged (only an oil service moves that)';
                }
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

        // ⭐ Aug-2026: this used to be four hardcoded account codes, NF Cash first —
        // a fifth independent copy of "which accounts may this person pay from",
        // and one that disagreed with the create side (which defaults to the
        // Expense Fund). Both now come from PaymentSourceService, so the approver
        // is offered exactly what he is tagged for and nothing the server would
        // reject. Bikes is Nizami Farms operations, hence business unit 1.
        $accounts = [];
        if ($levels) {
            try {
                $accounts = app(\App\Services\FIN\PaymentSourceService::class)
                    ->sourcesFor($u, 1, \App\Services\FIN\PaymentSourceService::PURPOSE_EXPENSE);
            } catch (\Throwable $e) {
                \Log::warning('FleetFuel approval accounts failed', ['error' => $e->getMessage()]);
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

    /**
     * The pay-from list for the inline "new petrol / new maintenance" form.
     *
     * Deliberately served from the Bikes endpoint rather than the mobile
     * expense endpoint (`/rider/expenses/payment-sources`): that one is gated on
     * `view_expenses`, which a Bikes-only user (Khaas running costs, rider-ops)
     * does not necessarily hold — they would have got a 403 and no picker at
     * all. Anyone who can reach Bikes can already file the claim, so the list
     * rides along with the data they already have.
     *
     * The rules themselves come from PaymentSourceService, which is the same
     * source of truth the submit-side check uses — so the form can never offer
     * an account the server will reject.
     */
    private function paySourceContext(): array
    {
        try {
            $svc = app(\App\Services\FIN\PaymentSourceService::class);
            $u   = auth()->user();

            return [
                // Bikes is Nizami Farms operations — always business unit 1, never
                // Khaas. Stated explicitly so a future default change elsewhere
                // cannot quietly start offering the other books' accounts here.
                'pay_sources'  => $svc->sourcesFor($u, 1, \App\Services\FIN\PaymentSourceService::PURPOSE_EXPENSE),
                'pay_banks'    => $svc->banks(),
                // 🔧 The manager's maintenance types, riding along for the same
                // reason as the accounts: a separate endpoint would carry its own
                // permission gate and lock out the very people who file claims.
                'maint_types'  => app(\App\Services\Riders\MaintenanceTypeService::class)->options(),
                'can_manage_types' => $this->canManageService(),
                // Read-only users see the numbers but must not file claims.
                'can_pay_from' => !($u && method_exists($u, 'isReadOnly') && $u->isReadOnly()),
            ];
        } catch (\Throwable $e) {
            // Bikes is primarily a reporting screen — never fail the whole month
            // because the account list could not be built.
            \Log::warning('FleetFuel pay sources failed', ['error' => $e->getMessage()]);
            return ['pay_sources' => [], 'pay_banks' => [], 'can_pay_from' => false];
        }
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
