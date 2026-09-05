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
                // ⚠⚠ THIS WAS `3000` while every calculation defaulted to 1,200 — and this
                //   is the value the browser renders as "Company default (N km)" and the
                //   mobile placeholder, so the screen would have offered one number while
                //   the maths used another the moment the config row went missing.
                //   ONE reader of that key now: ServiceIntervalResolver::companyDefault().
                'default_interval_km' => (new \App\Services\Riders\ServiceIntervalResolver())->companyDefault(),
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
            // ⚠ BOTH tables. A type recorded only through "Record service" on the
            //   Bikes screen has no claim, but it does have `t_fleet_service_log` rows
            //   — and those are evidence the derived schedule reads. Hard-deleting it
            //   left orphan rows whose service silently vanished from every countdown.
            $inUse = \DB::table('t_req_master')->where('maintenance_type_id', $model->id)->exists();
            if (!$inUse) {
                try {
                    $inUse = \Illuminate\Support\Facades\Schema::hasTable('t_fleet_service_log')
                        && \DB::table('t_fleet_service_log')
                            ->where('maintenance_type_id', $model->id)->exists();
                } catch (\Throwable $e) {
                    $inUse = true;   // cannot prove it is unused ⇒ retire, never delete
                }
            }
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
            // ⭐⭐ D4 (owner ruling Aug-27): re-point a PENDING claim at the right
            //   machine. A claim stamped to the wrong vehicle used to need hand-written
            //   SQL to fix — that is what the Aug-22 van repair cost. An APPROVED claim
            //   is still untouchable here (money is already in the ledger), which the
            //   status guard below enforces for every field alike.
            'vehicle_id'    => 'nullable|integer',
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
                    // ⭐ Judge the claim against the machine the editor is re-pointing it
                    //   at, not the one it currently carries — otherwise the rules could
                    //   approve one machine while the write below records another.
                    'vehicle_id'    => $data['vehicle_id'] ?? ($req->vehicle_id ?? null),
                    'meter_distance' => $req->meter_distance,
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

            // ⭐ D4 — the machine, when the editor named one and the column exists.
            //   Written with a guarded UPDATE rather than through the model, because
            //   `vehicle_id` is deliberately absent from $fillable (mass assignment
            //   would silently drop it, and hard-fail before batch 13).
            if (array_key_exists('vehicle_id', $data) && $data['vehicle_id']
                && \Illuminate\Support\Facades\Schema::hasColumn('t_req_master', 'vehicle_id')) {
                $before['vehicle_id'] = $req->vehicle_id ?? null;
                \Illuminate\Support\Facades\DB::table('t_req_master')
                    ->where('id', $req->id)
                    ->update(['vehicle_id' => (int) $data['vehicle_id']]);
            }
            if (array_key_exists('description', $data) && $data['description'] !== null) {
                $req->description = $data['description'];
            }
            $req->updated_by = auth()->id();
            $req->save();

            /**
             * ⭐ THE THIRD DOOR ONTO THE SAME READING (review, 3-Sep). A PENDING claim can be
             *   edited here — the original, pre-linking door. If that claim was filed WITH a
             *   service (or attached to one), the log it is linked to must follow, or the pair
             *   disagrees and the history and countdown (which follow the LOG) show the old
             *   number while the claim shows the new one. Only the two observation fields;
             *   the amount is this door's own business and never touches the log.
             */
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                    $linkedLog = \DB::table('t_fleet_service_log')->where('request_id', $req->id)->first(['id', 'user_id']);
                    if ($linkedLog) {
                        $lu = [];
                        if ($req->meter_at_fill !== null && (int) $req->meter_at_fill !== (int) ($before['meter_at_fill'] ?? -1)) {
                            $lu['meter'] = (int) $req->meter_at_fill;
                        }
                        if ($req->maintenance_type_id && (int) $req->maintenance_type_id !== (int) ($before['maintenance_type_id'] ?? 0)) {
                            $lu['maintenance_type_id'] = (int) $req->maintenance_type_id;
                        }
                        if ($lu) {
                            \DB::table('t_fleet_service_log')->where('id', $linkedLog->id)->update($lu);
                            app(\App\Services\Riders\ServiceRecordService::class)->bustCaches((int) $linkedLog->user_id);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('editClaim: linked log mirror failed', ['request' => $req->id, 'error' => $e->getMessage()]);
            }

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
                    // Re-pointing a claim at another machine moves money between two
                    // vehicles' running costs — it belongs in the audit line, not just
                    // in the before-image.
                    'vehicle_id' => array_key_exists('vehicle_id', $before)
                        ? ($data['vehicle_id'] ?? null) : null,
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
        // ⚠⚠ A maintenance TYPE's interval (or its active flag) changes the countdown
        //   for every machine that has ever had that job — machines this write never
        //   names. Without the config bump a manager edited "Oil Change: every 1,200"
        //   to 1,500, saw the type list update, and the bikes kept counting down on
        //   1,200 until the cache expired. Verified before the fix.
        \App\Services\Riders\VehicleService::bumpServiceConfig();

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
            // ⚠ A service cannot have happened in the future — a forward-dated row
            //   would reset the clock today against work not yet done.
            'date'     => 'nullable|date|before_or_equal:today',
            // 0 / null means "follow the company default" (BIKE_SERVICE_INTERVAL_KM).
            'interval_km' => 'nullable|integer|min:0|max:100000',
            // ⭐ WHICH service was done. REQUIRED whenever a meter is given and the
            // type list exists — see the block below.
            'maintenance_type_id' => 'nullable|integer',
            /**
             * 💰 THE BILL — OPTIONAL (owner ask, 3-Sep). Blank means exactly what it always
             *    meant: the work is recorded, no money moves, and the row reads "no bill".
             *    A figure here files a real maintenance expense against the rider and links
             *    it to this service record. See recordServiceBill() below for why it is
             *    filed through RequestController rather than inserted here.
             */
            'amount' => 'nullable|numeric|min:1|max:9999999',
            'payment_source_account_id' => 'nullable|integer',
            /**
             * 🧾 THE BILL ITSELF (owner ask, 3-Sep): "if we are raising a request for expense
             *    from the same, the bill should also be there."
             *
             * ⚠ Validated to the SAME rule RequestController::store applies, so a photo that
             *   would be refused there is refused here — before the service is recorded and
             *   the manager is left with a half-done action to puzzle over.
             * ⚠ Only meaningful alongside an amount; ignored otherwise (see below).
             */
            'bill_image' => 'nullable|image|max:5120',
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
        if ($request->filled('meter')) {
            /**
             * ⭐⭐ THE TYPE IS REQUIRED (owner ruling, 2-Sep-2026). NO GUESSING.
             *
             * ⚠⚠ WHAT USED TO BE HERE: an untyped recording was filed against
             *    "the shortest `resets_service_clock` type", on the reasoning that
             *    untyped had always meant the routine service. That premise DIED on
             *    22-Aug when Oil Change was set to 1,000 km and un-ticked as
             *    clock-resetting: the shortest clock-resetting type became Oil +
             *    Tuning (2,000). Live proof — `t_fleet_service_log` #8, Qasim on
             *    Arslan Aslam's bike, 1-Sep, 36,387 km, filed as Oil + Tuning while
             *    Oil Change's countdown kept running (47 km left, never reset).
             *    Whichever job was really done, one countdown was wrong and nothing
             *    on any screen said the type had been guessed.
             *
             * ⭐ So a meter with no type is now REFUSED rather than guessed. The web
             *   prompt has always asked; mobile now asks too (FleetScreen). An APK
             *   built before that gets this message and its manager records from the
             *   web until the new build is installed — a refusal a human can act on,
             *   in place of a silent misfile nobody could see.
             *
             * ⚠ Guarded on the type list EXISTING: before batch 12 (or if the table
             *   is unreachable) there is nothing to choose from, so the old untyped
             *   behaviour is kept rather than blocking the button outright — the
             *   same degrade-quietly rule the pickers follow.
             */
            // ⭐ THE RULE ITSELF LIVES IN ServiceRecordService, because three callers with
            //   three different permission gates must apply it identically — this screen,
            //   completing a workshop visit, and the RIDER answering "did it get done?"
            //   (who holds no `manage_bike_service` key at all).
            $resolved = app(\App\Services\Riders\ServiceRecordService::class)
                ->resolveType($request->input('maintenance_type_id'));
            if (!$resolved['ok']) {
                return response()->json(['success' => false, 'message' => $resolved['message']], 422);
            }
            $recordType = $resolved['type'];
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

            /**
             * A service actually happened.
             *
             * ⭐⭐ THE WRITE ITSELF LIVES IN ServiceRecordService — the same call the workshop
             *    completion and the RIDER's "did it get done?" both make. Until now only the
             *    type RULE was shared and this method kept its own copy of the insert + the
             *    profile stamp + the cache bump, so "what a service record IS" existed in two
             *    places. Two writers is how the pair drifts; the whole point of the extraction
             *    was that they cannot.
             *
             * ⚠ `$recordType` is null here ONLY when the type table does not exist yet
             *   (pre-batch-12) — a meter with no type is refused by resolveType() above. The
             *   service then writes no log row but still stamps the profile, exactly as this
             *   endpoint behaved before types existed.
             */
            $recorded = null;
            if ($request->filled('meter')) {
                $serviceDate = $data['date'] ?? Carbon::today()->format('Y-m-d');
                $recorded = app(\App\Services\Riders\ServiceRecordService::class)->record([
                    'rider_id' => (int) $data['rider_id'],
                    'meter'    => (int) $data['meter'],
                    'date'     => $serviceDate,
                    'type'     => $recordType,
                    'actor_id' => (int) auth()->id(),
                    'note'     => 'Recorded on the Bikes screen (no bill filed)',
                ]);
                if (!$recorded['ok']) {
                    return response()->json(['success' => false, 'message' => $recorded['message']], 422);
                }

                /**
                 * 💰 …AND THE BILL, IF ONE WAS GIVEN.
                 *
                 * ⭐⭐ ORDER MATTERS: THE WORK IS RECORDED FIRST, THE MONEY SECOND.
                 *    The service log is an OBSERVATION — the bike was serviced, and its
                 *    countdown must reset the moment we are told, not when a bill clears.
                 *    So if the expense fails to file we keep the service and say the bill
                 *    did not go through; we never lose the reading over a money error.
                 *
                 * ⚠⚠ AND WHY THE CLAIM IS NOT INSERTED HERE. Filing an expense means a
                 *    request number, the L1/L2 auto-approval rule, the ledger posting, the
                 *    BikeServiceClock hook and the vehicle stamping — all of which already
                 *    live in RequestController::store. A second copy of that would drift,
                 *    and this codebase has been bitten by exactly that more than once. So
                 *    the money goes through the real door, not a replica of it.
                 */
                $billSaid = null;
                if (!empty($data['amount']) && (float) $data['amount'] > 0) {
                    $bill = $this->recordServiceBill($request, $data, $recordType, $serviceDate,
                                                     $recorded['service_log_id'] ?? null);
                    if (!$bill['ok']) {
                        return response()->json([
                            'success'      => true,   // ⚠ the SERVICE stands — see above
                            'bill_failed'  => true,
                            'message'      => ($recorded['message'] ?? 'Service recorded.')
                                . ' But the expense was NOT filed: ' . $bill['message'],
                        ], 200);
                    }
                    // ⚠ Carried out to the receipt below, which is built from $said — the
                    //   manager must be told what happened to his money, in the same breath
                    //   as what happened to the reading.
                    $billSaid = $bill['message'];
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

            // ⭐⭐ THE SCHEDULE BELONGS TO THE MACHINE (owner ruling, Aug-16).
            //
            //    "How often is this bike due?" is a fact about the bike, not about
            //    whoever happens to be riding it — so when the registry can name the
            //    machine, the override is written THERE as well. Hand the bike over
            //    and its schedule goes with it, instead of staying behind on the old
            //    rider's profile while the new rider's own (unrelated) override
            //    silently takes over the bike he has just been given.
            //
            // ⚠ WHERE THE OVERRIDE ACTUALLY BITES (be honest about this): under the
            //   Aug-3 rule "the schedule follows the work last done", a bike with any
            //   TYPED clock-resetting record takes each countdown's interval from the
            //   TYPE — overrides (machine, then rider, then company default) govern
            //   only bikes whose history has no typed service yet. That is the same
            //   precedence the owner approved for the Bikes chip; writing the machine
            //   copy here keeps the fallback correct across a handover, it does not
            //   outrank the type's own schedule.
            //
            // ⚠ The profile write above is deliberately KEPT: riders with no
            //   registered machine still resolve through it. Two copies of a SETTING
            //   is safe in a way that two copies of a derived FACT was not — a
            //   setting has one writer (this endpoint) and a defined read order.
            // ⚠ `last_service_meter` is deliberately NOT mirrored onto the vehicle
            //   row. That column is the seed that silently froze and started this
            //   whole bug; it stays demoted to one piece of evidence among many, so a
            //   second source of truth can never grow back.
            if (array_key_exists('service_interval_km', $update)) {
                try {
                    $veh = new \App\Services\Riders\VehicleService();
                    if ($veh->available()) {
                        $vid = (new \App\Services\Riders\VehicleResolver())
                            ->currentVehicleFor((int) $data['rider_id']);
                        if ($vid) {
                            \DB::table(\App\Services\Riders\VehicleService::T_VEHICLE)
                                ->where('id', $vid)
                                ->update([
                                    'service_interval_km' => $update['service_interval_km'],
                                    'updated_at'          => now(),
                                ]);
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Machine-level service interval not written', ['error' => $e->getMessage()]);
                }
            }

            // ⚠ The derived service state is memoised per process AND cached across
            //   requests — bump the machine's evidence version so both die, or this
            //   same request (or the next render) could answer from evidence gathered
            //   before the write and tell the manager the service he just recorded
            //   has not happened.
            try {
                $bumpVid = (new \App\Services\Riders\VehicleResolver())
                    ->currentVehicleFor((int) $data['rider_id']);
            } catch (\Throwable $e) {
                $bumpVid = null;
            }
            \App\Services\Riders\VehicleService::bumpServiceEvidence($bumpVid ? (int) $bumpVid : null);

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
                // ⭐ Say the DATE back when it is not today. A backdated record moves
                //   the schedule from a day the manager chose, and a receipt that
                //   omits it reads as "recorded today" — the one thing he needs to
                //   check before trusting the number.
                $backdated = $serviceDate !== Carbon::today()->format('Y-m-d');
                $said[] = ($recordType ? $recordType->type_name : 'Service')
                    . ' recorded at ' . number_format((int) $data['meter']) . ' km'
                    . ($backdated ? ' on ' . Carbon::parse($serviceDate)->format('D j M') : '')
                    . ($recordType && (int) $recordType->interval_km > 0
                        ? ' — next due at ' . number_format((int) $data['meter'] + (int) $recordType->interval_km) . ' km'
                        : '');
                if ($recordType && !$recordType->resets_service_clock) {
                    $said[] = 'The bike\'s overall service-due clock is unchanged (only an oil service moves that)';
                }
                // 💰 What happened to the MONEY, in the same breath as what happened to the
                //    reading — a manager must never have to go looking for that answer.
                if (!empty($billSaid)) $said[] = $billSaid;
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

    /**
     * ✏️ CORRECT a service record (owner ask, 3-Sep): "make sure Qasim or Shabib or Taimur can
     *    modify these service dates later on as well if needed."
     *
     * ⭐ Same permission as recording one — being able to write the record and not being able
     *   to fix it is the gap that left log #8 needing hand-written SQL.
     * ⚠ This corrects the manual SERVICE LOG only. An approved maintenance CLAIM carries money
     *   and is edited through the claims flow (pending only, then reversed and re-filed) —
     *   deliberately not here.
     */
    public function amendServiceRecord(Request $request, $id)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change service records'], 403);
        }
        $data = $request->validate([
            'maintenance_type_id' => 'nullable|integer',
            'meter'               => 'nullable|integer|min:1',
            'date'                => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ]);
        $res = app(\App\Services\Riders\ServiceRecordService::class)
            ->amend((int) $id, $data, (int) auth()->id());
        return response()->json(['success' => $res['ok'], 'message' => $res['message']],
                                $res['ok'] ? 200 : 422);
    }

    /** Remove a service record that should never have been there. Same right as amending. */
    public function deleteServiceRecord(Request $request, $id)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change service records'], 403);
        }
        $res = app(\App\Services\Riders\ServiceRecordService::class)
            ->remove((int) $id, (int) auth()->id());
        return response()->json(['success' => $res['ok'], 'message' => $res['message']],
                                $res['ok'] ? 200 : 422);
    }

    /**
     * ✏️ Correct the ODOMETER (and the job) on a maintenance CLAIM — approved ones included.
     *
     * ⚠⚠ NOT a way round `editClaim`'s approved-claim guard, which stays exactly as it is.
     *    That method owns the money fields; this one owns two observations about a machine —
     *    the reading and which service it was — and nothing else. The amount, the date and the
     *    vehicle are untouchable here; for those, reverse and re-file remains the answer.
     *
     * ⭐ Same right as recording or amending a service: if you may write the number, you may fix
     *   the number. The rule itself lives in ServiceRecordService, next to the log-row version,
     *   so the two cannot drift.
     */
    public function correctClaimReading(Request $request, $id)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change service readings'], 403);
        }
        $data = $request->validate([
            'maintenance_type_id' => 'nullable|integer',
            'meter'               => 'nullable|integer|min:1|max:9999999',
        ]);
        $res = app(\App\Services\Riders\ServiceRecordService::class)
            ->correctClaim((int) $id, $data, (int) auth()->id());
        return response()->json(['success' => $res['ok'], 'message' => $res['message']],
                                $res['ok'] ? 200 : 422);
    }

    /**
     * 💰 File the BILL for a service just recorded, and tie the two together.
     *
     * ⭐⭐ THIS DELIBERATELY DOES NOT INSERT A CLAIM. Filing a maintenance expense means a
     *    request number, the L1/L2 auto-approval rule (which approves instantly for whoever
     *    holds L1 and queues it for everyone else), the ledger posting, the BikeServiceClock
     *    hook and the vehicle stamping. All of that already lives in RequestController::store.
     *    A second copy would drift from it — this codebase has been bitten by exactly that
     *    more than once — so the money goes through the real door and inherits every rule,
     *    including ones added later.
     *
     * ⚠⚠ ONE JOB = ONE ROW. Both the service log and approved maintenance claims feed the same
     *    countdown engine, and it keeps the best meter per type. Left unlinked, this pair would
     *    show TWICE in Past services and — if the two meters ever disagreed — the higher one
     *    would silently win with nothing saying they were the same job. `request_id` is what
     *    collapses them back into one, so it is written here and nowhere else.
     *
     * ⚠ Failure is NOT fatal to the service. The caller keeps the reading and reports that the
     *   bill did not file. The work happened either way.
     *
     * @return array{ok:bool, message:string, request_id?:int}
     */
    private function recordServiceBill(Request $request, array $data, $recordType, string $serviceDate, ?int $logId): array
    {
        try {
            $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'expense')
                ->where('is_active', 1)->first();
            if (!$category) {
                return ['ok' => false, 'message' => 'the expense category is not set up.'];
            }

            $jobName = $recordType->type_name ?? 'Maintenance';

            /**
             * 🧾 THE BILL PHOTO rides through to the real request, under the exact field name
             *    RequestController::store reads (`attachment_image`) — so it is stored, named
             *    and attached by the same code that handles a rider's own receipt, and shows
             *    up wherever those already do.
             *
             * ⚠ Forwarded as the SAME UploadedFile instance rather than copied: its temp file
             *   is still a genuine upload, so `isValid()` and the `image` rule still hold. A
             *   copy would land as an ordinary file and fail that check.
             */
            $files = [];
            if ($request->hasFile('bill_image')) {
                $files['attachment_image'] = $request->file('bill_image');
            }

            /**
             * ⚠⚠ THE BILL INHERITS THE READING FROM THE LOG JUST WRITTEN — it does not resend it
             *    (review, 3-Sep). Resending meter/type/date made the bill door re-judge a reading
             *    the service door had just ACCEPTED: a service recorded 2,000 km on landed fine,
             *    then its own bill was refused as "far above this bike's last reading". Same
             *    number, two verdicts, in one action. Passing `service_log_id` instead means
             *    store() inherits the reading, marks it as inherited (so the plausibility check
             *    stands aside exactly as it does for "Add the bill later"), and LINKS the pair
             *    itself — one path for every bill, whether filed with the service or after it.
             */
            $sub = Request::create('/api/requests/store', 'POST', array_filter([
                'category_id'        => $category->id,
                'requester_user_id'  => (int) $data['rider_id'],
                'title'              => $jobName,
                'description'        => 'Filed with the service recorded on the Bikes screen'
                                        . (isset($data['meter']) ? ' at ' . number_format((int) $data['meter']) . ' km' : '') . '.',
                'amount'             => (float) $data['amount'],
                'expense_category'   => 'Maintenance',
                'service_log_id'     => $logId,
                'payment_source_account_id' => $data['payment_source_account_id'] ?? null,
                // ⚠ Bikes is Nizami Farms operations — ALWAYS business unit 1, never Khaas.
                //   Stated explicitly (the same rule the web "New bike expense" modal states) so
                //   a Khaas-mode manager like Qasim can never file a bike bill into the other
                //   books, and so a future default change elsewhere cannot quietly move it.
                'business_unit_id'   => 1,
            ], fn ($v) => $v !== null), [], $files);
            // The sub-request must act as the SAME signed-in user — the whole approval
            // decision hangs on who is filing.
            $sub->setUserResolver($request->getUserResolver());

            $res  = app(\App\Http\Controllers\Request\RequestController::class)->store($sub);
            $body = json_decode($res->getContent(), true);
            if (($res->getStatusCode() < 200 || $res->getStatusCode() >= 300) || empty($body['success'])) {
                return ['ok' => false, 'message' => $body['message'] ?? 'the request was refused.'];
            }

            $reqId = (int) ($body['request_id'] ?? 0);
            // ⭐ The link is made by store() via attachBillToService — the ONE place that does
            //   it. Only the note is ours, so the row reads as filed-with-bill in the history.
            if ($reqId && $logId && \Illuminate\Support\Facades\Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                \DB::table('t_fleet_service_log')->where('id', $logId)
                    ->update(['note' => 'Recorded on the Bikes screen with the bill']);
            }

            // ⭐ `auto_approved` is the server's own answer to "did this land in the ledger
            //   already?" — it is true when the filer holds the approval levels. We echo it
            //   rather than re-deriving it, so the message can never contradict what happened.
            $approved = !empty($body['auto_approved']);
            // ⚠ Say whether the BILL went with it. A manager who meant to attach one and did
            //   not should learn that here, not weeks later when someone audits the expense.
            $billed = !empty($files) ? ' Bill attached.' : ' No bill photo attached.';
            return ['ok' => true, 'request_id' => $reqId, 'message' => 'Rs '
                . number_format((float) $data['amount']) . ' expense '
                . ($approved ? 'added and approved.' : 'sent for approval.') . $billed];
        } catch (\Throwable $e) {
            \Log::error('recordServiceBill failed', ['error' => $e->getMessage(), 'log' => $logId]);
            return ['ok' => false, 'message' => 'it could not be filed.'];
        }
    }

    /**
     * 🧾 THE SERVICES A BILL CAN BE ATTACHED TO — what the picker on every bill form shows.
     *
     * ⭐ Owner ruling (3-Sep): a bill is tied to a reading by being CHOSEN, never matched on
     *   meter or date. This is the list he chooses from: readings already recorded that no
     *   live bill speaks for yet.
     *
     * ⚠ WHO MAY ASK ABOUT WHOM. Anyone may list their OWN un-billed services — a rider needs
     *   this to bill his own service day. Asking about someone else needs the service right,
     *   because the list says what work a named rider had done and when.
     */
    public function unbilledServices(Request $request)
    {
        $me  = (int) auth()->id();
        $for = (int) ($request->query('rider_id') ?: $me);
        if ($for !== $me && !$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $vehicleId = $request->query('vehicle_id');
        return response()->json([
            'success'  => true,
            'rider_id' => $for,
            'services' => app(\App\Services\Riders\ServiceRecordService::class)
                ->unbilledServicesFor($for, $vehicleId ? (int) $vehicleId : null),
        ]);
    }

    public function apiUnbilledServices(Request $r) { $this->mobileContext = true; return $this->unbilledServices($r); }

    public function apiAmendServiceRecord(Request $r, $id)  { $this->mobileContext = true; return $this->amendServiceRecord($r, $id); }
    public function apiDeleteServiceRecord(Request $r, $id) { $this->mobileContext = true; return $this->deleteServiceRecord($r, $id); }
    public function apiCorrectClaimReading(Request $r, $id) { $this->mobileContext = true; return $this->correctClaimReading($r, $id); }

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
    /**
     * ⭐ WHO WOULD BE LEFT BEHIND BY A COMPANY-WIDE CHANGE (owner ask, Aug-16).
     *
     * "If someone changes the overall service limit he should know which bikes have
     * their own limit set, and decide whether to override them or leave them alone."
     *
     * The old prompt said "bikes with their own interval are unaffected" without ever
     * naming one — so a manager raising the company schedule had no way to know three
     * machines would quietly ignore him. This lists them BEFORE he commits.
     *
     * Read-only. Two populations, because there are two places an override can live:
     *   • MACHINES (`t_ops_vehicle.service_interval_km`) — where a schedule belongs;
     *   • RIDERS (`t_ops_rider_profile.service_interval_km`) — the legacy fallback,
     *     which still governs anyone with no registered machine. Hiding those would
     *     make this list a half-truth.
     */
    public function intervalOverrides(Request $request)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            $default = (new \App\Services\Riders\ServiceIntervalResolver())->companyDefault();

            $vehicles = [];
            $veh = new \App\Services\Riders\VehicleService();
            if ($veh->available()) {
                $vehicles = \DB::table(\App\Services\Riders\VehicleService::T_VEHICLE . ' as v')
                    ->leftJoin(\App\Services\Riders\VehicleService::T_ASSIGN . ' as a', function ($j) {
                        $j->on('a.vehicle_id', '=', 'v.id')->whereNull('a.released_on');
                    })
                    ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                    ->where('v.is_active', 1)
                    ->whereNotNull('v.service_interval_km')
                    ->orderByRaw('COALESCE(v.reg_no, v.nickname)')
                    ->get(['v.id', 'v.reg_no', 'v.nickname', 'v.service_interval_km', 'u.fullname'])
                    ->map(fn ($v) => [
                        'id'          => (int) $v->id,
                        'name'        => $v->reg_no ?: ($v->nickname ?: ('Vehicle #' . $v->id)),
                        'interval_km' => (int) $v->service_interval_km,
                        'keeper_name' => $v->fullname,
                        // Flagged so the UI can grey out the ones that already agree —
                        // "overriding" a bike whose override equals the new value is
                        // a no-op, and showing it as a casualty would be misleading.
                        'same_as_default' => (int) $v->service_interval_km === $default,
                    ])->values()->all();
            }

            $riders = \DB::table('t_ops_rider_profile as p')
                ->join('t_sys_user as u', 'u.id', '=', 'p.user_id')
                ->whereNotNull('p.service_interval_km')
                ->orderBy('u.fullname')
                ->get(['p.user_id', 'u.fullname', 'p.service_interval_km'])
                ->map(fn ($p) => [
                    'user_id'     => (int) $p->user_id,
                    'name'        => $p->fullname,
                    'interval_km' => (int) $p->service_interval_km,
                    'same_as_default' => (int) $p->service_interval_km === $default,
                ])->values()->all();

            return response()->json([
                'success'     => true,
                'default_km'  => $default,
                'vehicles'    => $vehicles,
                'riders'      => $riders,
            ]);
        } catch (\Throwable $e) {
            \Log::error('FleetFuel intervalOverrides failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not read the overrides'], 500);
        }
    }

    public function setDefaultInterval(Request $request)
    {
        if (!$this->canManageService()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change service schedules'], 403);
        }

        $data = $request->validate([
            'interval_km' => 'required|integer|min:100|max:100000',
            // ⭐ The manager's explicit decision about the bikes that have their own
            //    schedule. Absent = LEAVE THEM ALONE, which is the safe reading and
            //    exactly what this endpoint has always done — an old page that has
            //    not been reloaded cannot accidentally wipe a per-bike setting.
            'clear_overrides' => 'nullable|boolean',
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

            // ⭐ "Put everyone on this schedule": CLEAR the per-bike overrides rather
            //    than stamping the new number onto each. Clearing means "follow the
            //    company default", so the next change reaches them too; stamping
            //    would silently re-create the same divergence one change later.
            $cleared = ['vehicles' => 0, 'riders' => 0];
            if ($request->boolean('clear_overrides')) {
                try {
                    $veh = new \App\Services\Riders\VehicleService();
                    if ($veh->available()) {
                        // ⚠⚠ `is_active` — CLEAR EXACTLY WHAT WAS SHOWN. The preview
                        //    lists active machines only, so an unfiltered clear would
                        //    wipe overrides on retired bikes the manager was never
                        //    shown and could not have approved, and report a count
                        //    that did not match his own list. This write is
                        //    irreversible and unaudited; it must not exceed consent.
                        $ids = \DB::table(\App\Services\Riders\VehicleService::T_VEHICLE)
                            ->where('is_active', 1)
                            ->whereNotNull('service_interval_km')->pluck('id');
                        $cleared['vehicles'] = \DB::table(\App\Services\Riders\VehicleService::T_VEHICLE)
                            ->where('is_active', 1)
                            ->whereNotNull('service_interval_km')
                            ->update(['service_interval_km' => null, 'updated_at' => now()]);
                        foreach ($ids as $vid) {
                            \App\Services\Riders\VehicleService::bumpServiceEvidence((int) $vid);
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Clearing vehicle intervals failed', ['error' => $e->getMessage()]);
                }
                try {
                    $cleared['riders'] = \DB::table('t_ops_rider_profile')
                        ->whereNotNull('service_interval_km')
                        ->update(['service_interval_km' => null, 'updated_at' => now()]);
                } catch (\Throwable $e) {
                    \Log::warning('Clearing rider intervals failed', ['error' => $e->getMessage()]);
                }
            }
            // ⚠ The COMPANY default feeds every bike that has no schedule of its own,
            //   so this is a fleet-wide settings change — the per-vehicle counters
            //   cannot express it.
            \App\Services\Riders\VehicleService::bumpServiceConfig();

            $msg = 'Every bike without its own schedule is now serviced every '
                 . number_format($data['interval_km']) . ' km';
            if ($cleared['vehicles'] || $cleared['riders']) {
                $bits = [];
                if ($cleared['vehicles']) $bits[] = $cleared['vehicles'] . ' bike' . ($cleared['vehicles'] === 1 ? '' : 's');
                if ($cleared['riders'])   $bits[] = $cleared['riders'] . ' rider schedule' . ($cleared['riders'] === 1 ? '' : 's');
                $msg .= '. ' . implode(' and ', $bits) . ' had their own schedule — now cleared, so they follow this too';
            }

            return response()->json([
                'success' => true,
                'message' => $msg,
                'interval_km' => (int) $data['interval_km'],
                'cleared' => $cleared,
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

    /**
     * Mobile entry for "which bikes hold their own schedule".
     * ⚠ Same method as the web, so the two surfaces can never disagree about who the
     *   exceptions are — a manager must see the same list on his phone and his desk.
     */
    public function apiIntervalOverrides(Request $request)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $this->mobileContext = true;
        return $this->intervalOverrides($request);
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

        // ⭐ Aug-27-2026: the banks an ONLINE approval can be attributed to. An
        // approver switching a claim to a bank account has to say WHICH bank, or
        // BankBalanceService never counts the movement and the per-bank split
        // drifts. The month popup in Daily Closing approves from this payload and
        // had no bank list to offer at all. Additive key; only built when the
        // approver actually holds a bank source, since banks() computes balances.
        $banks = [];
        if (array_filter($accounts, fn ($a) => !empty($a['is_online']))) {
            try {
                $banks = app(\App\Services\FIN\PaymentSourceService::class)->banks();
            } catch (\Throwable $e) {
                \Log::warning('FleetFuel approval banks failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'can_approve' => !empty($levels),
            'levels'      => $levels,
            'read_only'   => (bool) $readOnly,
            'accounts'    => $accounts,
            'banks'       => $banks,
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
