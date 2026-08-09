<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ShiftResolutionService;

class AttendanceController extends Controller
{
    // Admin/Manager view
    public function index(Request $request)
    {
        return view('pages.attendance.index');
    }

    // Get all users with their attendance visibility status
    public function getUsersVisibility(Request $request)
    {
        // "Show in Salary" is a separate flag (added later). NULL follows attendance
        // visibility, so the effective payroll value = COALESCE(show_in_payroll, is_visible, 1).
        $hasSalaryCol = false;
        try {
            $hasSalaryCol = \Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance_visibility', 'show_in_payroll');
        } catch (\Throwable $e) { $hasSalaryCol = false; }
        $salaryExpr = $hasSalaryCol
            ? 'COALESCE(av.show_in_payroll, av.is_visible, 1)'
            : 'COALESCE(av.is_visible, 1)';

        $users = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('u.is_active', 1)
            ->select(
                'u.id',
                'u.fullname',
                'r.urole_name as role_name',
                DB::raw('COALESCE(av.is_visible, 1) as is_visible'),
                DB::raw($salaryExpr . ' as show_in_payroll'),
                // Delivery rider = active rider profile (separate from attendance visibility).
                DB::raw('CASE WHEN p.active = 1 THEN 1 ELSE 0 END as is_delivery_rider'),
                'av.notes as hide_reason'
            )
            ->groupBy('u.id', 'u.fullname', 'r.urole_name', 'av.is_visible', DB::raw($salaryExpr), 'p.active', 'av.notes')
            ->orderBy('u.fullname')
            ->get();

        return response()->json(['success' => true, 'data' => $users, 'salary_toggle_available' => $hasSalaryCol]);
    }

    // Toggle whether a user appears on the Payroll screen (salary tracking), independent
    // of attendance visibility. Backed by t_ops_attendance_visibility.show_in_payroll.
    public function updateSalaryVisibility(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'show_in_payroll' => 'required|boolean',
        ]);
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance_visibility', 'show_in_payroll')) {
                return response()->json(['success' => false, 'message' => 'Apply the salary-visibility schema update first.'], 422);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not check the schema.'], 500);
        }

        $userId = (int) $validated['user_id'];
        $show = $validated['show_in_payroll'] ? 1 : 0;

        $existing = DB::table('t_ops_attendance_visibility')->where('user_id', $userId)->first();
        if ($existing) {
            DB::table('t_ops_attendance_visibility')->where('user_id', $userId)
                ->update(['show_in_payroll' => $show, 'updated_at' => now()]);
        } else {
            // No visibility row yet: create one that keeps attendance DEFAULT (visible)
            // but sets the explicit payroll choice.
            DB::table('t_ops_attendance_visibility')->insert([
                'user_id' => $userId,
                'is_visible' => 1,
                'show_in_payroll' => $show,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Salary visibility updated']);
    }

    // Toggle whether a user is a delivery rider (appears in the rider-assign lists on
    // web + mobile). Independent of attendance visibility. Backed by rider_profile.active.
    public function updateDeliveryRider(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'is_rider' => 'required|boolean',
        ]);

        $userId = (int) $validated['user_id'];
        $isRider = $validated['is_rider'] ? 1 : 0;

        $existing = DB::table('t_ops_rider_profile')->where('user_id', $userId)->first();
        if ($existing) {
            DB::table('t_ops_rider_profile')->where('user_id', $userId)
                ->update(['active' => $isRider, 'updated_at' => now()]);
        } else {
            DB::table('t_ops_rider_profile')->insert([
                'user_id' => $userId,
                'active' => $isRider,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Rider list updated']);
    }

    // Update user visibility in attendance
    public function updateUserVisibility(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'is_visible' => 'required|boolean',
            'notes' => 'nullable|string|max:500'
        ]);

        $userId = $validated['user_id'];
        $isVisible = $validated['is_visible'];
        $notes = $validated['notes'] ?? null;

        // Check if record exists
        $existing = DB::table('t_ops_attendance_visibility')->where('user_id', $userId)->first();

        if ($existing) {
            // Update existing record
            DB::table('t_ops_attendance_visibility')
                ->where('user_id', $userId)
                ->update([
                    'is_visible' => $isVisible,
                    'notes' => $notes,
                    'hidden_by' => $isVisible ? null : auth()->id(),
                    'hidden_at' => $isVisible ? null : now(),
                    'updated_at' => now()
                ]);
        } else {
            // Insert new record
            DB::table('t_ops_attendance_visibility')->insert([
                'user_id' => $userId,
                'is_visible' => $isVisible,
                'notes' => $notes,
                'hidden_by' => $isVisible ? null : auth()->id(),
                'hidden_at' => $isVisible ? null : now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Visibility updated successfully']);
    }

    public function data(Request $request)
    {
        // Build subquery of leave requests (category=leave) with fields we need
        $leaveSub = DB::table('t_req_master as r')
            ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
            ->where('c.category_code', '=', 'leave')
            ->select(
                'r.id',
                'r.requester_user_id',
                'r.status',
                'r.leave_type',
                'r.leave_start_date',
                'r.leave_end_date'
            );

        // Start from users and LEFT JOIN everything else so users on leave without attendance still show
        $selectedDate = $request->input('date', now()->toDateString());
        
        $query = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance as a', function($join) use ($selectedDate) {
                $join->on('u.id', '=', 'a.user_id')
                     ->whereDate('a.attendance_date', '=', $selectedDate);
            })
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
            // Join role information for filtering
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            // Join attendance visibility (default to visible if no record exists)
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            // Join leave subquery matching the selected date
            ->leftJoinSub($leaveSub, 'lr', function($join) use ($selectedDate) {
                $join->on('lr.requester_user_id', '=', 'u.id')
                    ->whereIn('lr.status', ['approved', 'pending'])
                    ->whereRaw('? BETWEEN lr.leave_start_date AND lr.leave_end_date', [$selectedDate]);
            })
            ->select(
                'u.id as user_id',
                'u.fullname',
                'u.is_active',
                'r.urole_name as role_name',
                'a.id as attendance_id',
                'a.attendance_date',
                'a.login_time',
                'a.logout_time',
                'a.notes',
                // Frozen snapshot (preferred over recomputation for late/OT)
                'a.expected_shift_start',
                'a.expected_shift_end',
                'a.late_minutes',
                'a.overtime_minutes',
                // Meter readings + their photo proof (present/absent shown as icons)
                'a.meter_start',
                'a.meter_end',
                'a.picture_start',
                'a.picture_end',
                // U5 timeline anchors — feed the shared GPS phase analyzer (ride-in / ride-home)
                'a.meter_start_recorded_at',
                'a.home_meter_recorded_at',
                'a.meter_start_source',
                // Location tracking fields
                'a.checkin_latitude',
                'a.checkin_longitude',
                'a.checkin_distance_from_base',
                'a.is_remote_checkin',
                'a.checkout_latitude',
                'a.checkout_longitude',
                // Blocked-checkout capture (latest attempt) — feeds the bypass-modal context + alert
                'a.checkout_attempt_at',
                'a.checkout_attempt_lat',
                'a.checkout_attempt_lng',
                'a.checkout_attempt_reason',
                'a.checkout_attempt_distance_m',
                'a.checkout_attempt_limit_m',
                'a.checkout_attempt_age_min',
                'a.checkout_attempt_drop_lat',
                'a.checkout_attempt_drop_lng',
                'a.checkout_attempt_drop_label',
                'a.checkout_attempt_count',
                // Road distance (stored on checkout)
                'a.road_distance_km',
                'a.road_distance_source',
                // Keep legacy shifts for fallback only (will be replaced by ShiftResolutionService)
                DB::raw('COALESCE(rp.shift_start, "09:00") as legacy_shift_start'),
                DB::raw('COALESCE(rp.shift_end, "17:00") as legacy_shift_end'),
                // Leave fields
                'lr.id as leave_request_id',
                'lr.status as leave_status',
                'lr.leave_type as leave_type_from_req',
                // Visibility (default to 1 if no record)
                DB::raw('COALESCE(av.is_visible, 1) as is_attendance_visible')
            )
            // Only show users that are visible in attendance (default to visible if no record)
            ->where(function($q) {
                $q->whereNull('av.is_visible')  // No visibility record = visible
                  ->orWhere('av.is_visible', 1); // Explicitly visible
            })
            ->orderBy('u.fullname');

        // ⭐ The bypass tell, for the OT fallback below (owner ruling Aug-8): a
        //   checkout that rode a manager unlock must not count as overtime.
        //   Guarded — dev DBs may lag the column.
        if ((new \App\Services\HR\OvertimeService())->hasUnlockColumn()) {
            $query->addSelect('a.checkout_unlock_until');
        }

        // ⭐ GPS-accuracy hardening columns. Selected ONLY once the hardening SQL has been
        // applied, so uploading the web files before running it can never 500 this page
        // (unlike the checkout_attempt_* block above, which is unconditional). They carry the
        // morning start-meter fix + the accuracy of a blocked checkout attempt.
        if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'meter_start_gps')) {
            $query->addSelect([
                'a.meter_start_gps',
                'a.meter_start_lat',
                'a.meter_start_lng',
                'a.meter_start_accuracy_m',
                'a.meter_start_distance_m',
                'a.meter_start_office_m',
                'a.checkout_attempt_accuracy_m',
            ]);
        }

        // Filter by active/all users (default to active only)
        $activeFilter = $request->input('active_filter', 'active');
        if ($activeFilter === 'active') {
            $query->where('u.is_active', 1);
        }

        if ($request->filled('user_id')) {
            $query->where('u.id', (int)$request->input('user_id'));
        }
        if ($request->filled('user')) {
            $search = $request->input('user');
            $query->where(function($q) use ($search) {
                $q->where('u.fullname', 'like', '%' . $search . '%')
                  ->orWhere('u.id', '=', $search);
            });
        }

        $rows = $query->limit(500)->get();
        
        // Resolve actual shifts using ShiftResolutionService
        $shiftService = new ShiftResolutionService();
        $locationService = new \App\Services\LocationService();
        
        // Collect user IDs for batch GPS query
        $userIdsWithAttendance = [];
        
        foreach ($rows as $row) {
            // Resolve the shift FOR THE SELECTED DATE (not today) so past dates show the
            // shift that was actually in effect then.
            $shiftData = $shiftService->getUserShift($row->user_id, $selectedDate);
            $row->shift_start = $shiftData['shift_start'];
            $row->shift_end = $shiftData['shift_end'];
            $row->shift_name = $shiftData['shift_name'];
            $row->shift_source = $shiftData['source'];

            // Unified per-day classification (working|off|holiday|not_joined) so a
            // no-login day on a holiday / weekly off / before the hire date is NOT
            // painted red "Absent". Leave is layered on the frontend from leave_status.
            $row->day_kind = $shiftService->dayKind($row->user_id, $selectedDate);

            // Per-row late/overtime minutes — prefer the frozen snapshot, else compute
            // for the selected date. The frontend should DISPLAY these, not recompute.
            if (!$row->login_time) {
                $row->late_minutes = 0;
            } elseif (!is_null($row->late_minutes)) {
                $row->late_minutes = (int) $row->late_minutes;
            } else {
                $s = strtotime($selectedDate . ' ' . $shiftData['shift_start'] . ':00');
                $l = strtotime($selectedDate . ' ' . $row->login_time);
                $row->late_minutes = ($l > $s) ? (int) (($l - $s) / 60) : 0;
            }
            if (!$row->logout_time || empty($shiftData['shift_end'])) {
                // No checkout, or a start-only shift (no end) → no overtime.
                $row->overtime_minutes = 0;
            } elseif (!is_null($row->overtime_minutes)) {
                $row->overtime_minutes = (int) $row->overtime_minutes;
            } else {
                // ⭐ Bypass re-base (owner ruling Aug-8), same rule as the snapshot
                //   stamper: an unlocked checkout counts to the last delivered order
                //   (null = nothing provable → 0); a normal checkout keeps its time.
                $e = strtotime($selectedDate . ' ' . $shiftData['shift_end'] . ':00');
                $o = (new \App\Services\HR\OvertimeService())->otEndTs(
                    (int) $row->user_id, $selectedDate, $row->login_time, $row->logout_time,
                    $row->checkout_unlock_until ?? null
                );
                $row->overtime_minutes = ($o !== null && $o > $e) ? (int) (($o - $e) / 60) : 0;
            }

            // ⭐ Calculate meter distance
            $row->meter_distance = null;
            if ($row->meter_start && $row->meter_end) {
                $row->meter_distance = abs((int) $row->meter_end - (int) $row->meter_start);
            }
            
            // ⭐ Initialize GPS distance fields
            $row->gps_distance = null;
            $row->gps_readings_count = 0;
            $row->gps_coverage_percent = null;
            
            // Track users with attendance for GPS batch query
            if ($row->login_time) {
                $userIdsWithAttendance[] = $row->user_id;
            }
            
            // Recalculate distance against the SHIFT location for the selected date —
            // the SAME base the mobile check-in measured against — NOT the rider's
            // default assigned location. $shiftData (resolved per-date above) carries
            // location_id, so a rider at LaCarne today is measured vs LaCarne, and a
            // past date uses that day's shift location (calculateDistanceFromBase falls
            // back to assigned→primary if the shift has no explicit location).
            if ($row->checkin_latitude && $row->checkin_longitude) {
                $distanceResult = $locationService->calculateDistanceFromBase(
                    $row->checkin_latitude,
                    $row->checkin_longitude,
                    $row->user_id,
                    $shiftData['location_id'] ?? null
                );

                // Update with recalculated values — but keep the frozen check-in value
                // if the recompute couldn't resolve a base (no location configured), so
                // a config gap never blanks a real distance.
                if ($distanceResult['distance_meters'] !== null) {
                    $row->checkin_distance_from_base = $distanceResult['distance_meters'];
                    $row->is_remote_checkin = $distanceResult['is_remote'] ? 1 : 0;
                }
                $row->assigned_office_location = $distanceResult['base_location']->location_name ?? null;
            }
        }
        
        // ⭐ Batch calculate GPS distance for users with attendance
        if (!empty($userIdsWithAttendance)) {
            $allGpsReadings = DB::table('t_ops_rider_location')
                ->whereIn('user_id', $userIdsWithAttendance)
                ->whereDate('captured_at', $selectedDate)
                ->where('accuracy', '<=', 100) // Skip inaccurate readings
                ->orderBy('user_id')
                ->orderBy('captured_at')
                ->select('user_id', 'latitude', 'longitude', 'accuracy', 'captured_at')
                ->get()
                ->groupBy('user_id');
            
            foreach ($rows as $row) {
                if (isset($allGpsReadings[$row->user_id])) {
                    $readings = $allGpsReadings[$row->user_id]->values()->all();
                    $gpsResult = $this->calculateGpsDistanceFromReadings($readings);
                    $row->gps_distance = $gpsResult['distance'];
                    $row->gps_readings_count = $gpsResult['readings_count'];
                    
                    // ⭐ Calculate GPS coverage percentage
                    if ($row->login_time && $row->logout_time) {
                        $loginTime = strtotime($selectedDate . ' ' . $row->login_time);
                        $logoutTime = strtotime($selectedDate . ' ' . $row->logout_time);
                        $workingMinutes = ($logoutTime - $loginTime) / 60;
                        $expectedReadings = max(1, floor($workingMinutes / 5)); // One reading every 5 min
                        $row->gps_coverage_percent = min(100, round(($gpsResult['readings_count'] / $expectedReadings) * 100));
                    }
                }
            }
        }

        // ── Meter-flag inputs (Phase B): yesterday's end meter (for the company-bike
        //    overnight check) + the company_bike flag. The frontend computes the actual
        //    flags from these + the thresholds below. Fully guarded — a missing column
        //    or no prior data never breaks the daily view.
        $userIds = $rows->pluck('user_id')->filter()->values()->all();
        $prevMeter = []; $bikeSet = []; $graceByUser = []; $meterExempt = [];
        if (!empty($userIds)) {
            try {
                $ph = implode(',', array_fill(0, count($userIds), '?'));
                $prev = DB::select("
                    SELECT a.user_id, a.meter_end, a.attendance_date
                    FROM t_ops_attendance a
                    INNER JOIN (
                        SELECT user_id, MAX(attendance_date) as md FROM t_ops_attendance
                        WHERE user_id IN ($ph) AND attendance_date < ? AND meter_end IS NOT NULL
                        GROUP BY user_id
                    ) l ON a.user_id = l.user_id AND a.attendance_date = l.md
                ", array_merge($userIds, [$selectedDate]));
                foreach ($prev as $p) {
                    $prevMeter[$p->user_id] = ['end' => $p->meter_end, 'date' => $p->attendance_date];
                }
            } catch (\Throwable $e) { /* no prior meters → no overnight flag */ }
            try {
                $hasBike  = \Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'company_bike');
                $hasGrace = \Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'overnight_grace_km');
                if ($hasBike) {
                    // ⭐ Phase C: who is on a company machine THAT DAY (registry
                    //    first, profile checkbox as the fallback population).
                    //    Set-based on purpose — this runs for a whole month grid.
                    $companyToday = array_flip(
                        (new \App\Services\Riders\VehicleResolver())->companyRiderIdsFor($selectedDate)
                    );
                    $cols = $hasGrace ? ['user_id', 'company_bike', 'overnight_grace_km'] : ['user_id', 'company_bike'];
                    foreach (DB::table('t_ops_rider_profile')->whereIn('user_id', $userIds)->get($cols) as $prof) {
                        if (!isset($companyToday[(int) $prof->user_id])) continue;
                        $bikeSet[$prof->user_id] = true;
                        if ($hasGrace && $prof->overnight_grace_km !== null && $prof->overnight_grace_km !== '') {
                            $graceByUser[$prof->user_id] = (float) $prof->overnight_grace_km;
                        }
                    }
                }
            } catch (\Throwable $e) { /* column not added yet → no bike flags */ }
            // Riders the owner exempted from the meter in Rider Management ("Meter reading is
            // compulsory" unticked) — the ⛽ tick must not judge them, same as the rider app
            // stops asking them. Guarded: no column ⇒ nobody exempt ⇒ previous behaviour.
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'meter_required')) {
                    foreach (DB::table('t_ops_rider_profile')->whereIn('user_id', $userIds)
                        ->where('meter_required', 0)->pluck('user_id') as $uid) {
                        $meterExempt[$uid] = true;
                    }
                }
            } catch (\Throwable $e) { /* no column → nobody exempt */ }
            // ⭐ PHASE D: the registry exempts too — a rider with no machine on THIS
            //   date was not asked for a meter, so this page must not expect one.
            //   Date-scoped (this is a historical sheet), silent while rules are off.
            try {
                $vres = new \App\Services\Riders\VehicleResolver();
                if ($vres->rulesEnabled() && $vres->available()) {
                    $heldToday = $vres->machineHoldersOn($selectedDate);
                    foreach (DB::table('t_ops_rider_profile')->whereIn('user_id', $userIds)
                        ->pluck('user_id') as $uid) {
                        if (!isset($heldToday[(int) $uid])) { $meterExempt[(int) $uid] = true; }
                    }
                }
            } catch (\Throwable $e) { /* registry silent → previous behaviour */ }
        }
        // ── Company-bike GOING-HOME meter state per rider on this date (the manager valve:
        //    unlock a late-locked rider / enter the meter for a dead phone). Guarded — no
        //    home-journey columns yet ⇒ no valve, page unaffected.
        $homeByUser = [];
        try {
            if (!empty($userIds) && \Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'home_expected_by')) {
                $hjSvc = new \App\Services\Riders\HomeJourneyService();
                $hasBreach = \Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'home_bypass_breach');
                $hasRecAt  = \Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'home_meter_recorded_at');
                $hjCols = ['id', 'user_id', 'logout_time', 'home_expected_by', 'home_eta_min', 'home_arrived_at', 'home_arrival_source',
                           'home_distance_km', 'meter_home', 'home_meter_unlock_until', 'home_meter_unlock_by', 'home_late_reason'];
                if ($hasBreach) { $hjCols[] = 'home_bypass_breach'; }
                if ($hasRecAt)  { $hjCols[] = 'home_meter_recorded_at'; }
                $hjRows = DB::table('t_ops_attendance')
                    ->whereIn('user_id', $userIds)->whereDate('attendance_date', $selectedDate)
                    ->whereNotNull('home_expected_by')
                    ->get($hjCols);
                foreach ($hjRows as $hr) {
                    $homeByUser[$hr->user_id] = [
                        'attendance_id' => (int) $hr->id,
                        'state'         => $hjSvc->deriveState($hr),
                        'expected_by'   => $hr->home_expected_by ? substr((string) $hr->home_expected_by, 11, 5) : null,
                        'eta_min'       => $hr->home_eta_min !== null ? (int) $hr->home_eta_min : null,
                        'arrived_at'    => $hr->home_arrived_at ? substr((string) $hr->home_arrived_at, 11, 5) : null,
                        'arrival_source'=> $hr->home_arrival_source,
                        'distance_km'   => $hr->home_distance_km !== null ? (float) $hr->home_distance_km : null,
                        'recorded_at'   => ($hasRecAt && $hr->home_meter_recorded_at) ? substr((string) $hr->home_meter_recorded_at, 11, 5) : null,
                        'minutes_late'  => $hjSvc->minutesLate($hr),
                        'has_meter'     => $hr->meter_home !== null && $hr->meter_home !== '',
                        'meter_home'    => $hr->meter_home !== null ? (int) $hr->meter_home : null,
                        'unlock_active' => $hr->home_meter_unlock_until && strtotime((string) $hr->home_meter_unlock_until) >= time(),
                        'breach'        => $hasBreach ? ($hr->home_bypass_breach ?? null) : null,
                        'reason'        => $hr->home_late_reason,
                    ];
                }
            }
        } catch (\Throwable $e) { /* no home valve on error — page must still load */ }

        // ── U5 morning-start journey per rider (guarded — no wave-4 columns ⇒ nothing shown).
        //    Judged ONLY for company-bike riders with a home pin; carries the START-line data
        //    (due / eta / when the meter was typed / source) + the home-continuity verdict +
        //    the Phase-2 check-in-unlock state for the valve modal.
        $workByUser = [];
        try {
            if (!empty($userIds) && \Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'work_expected_by')) {
                $wjSvc = new \App\Services\Riders\WorkJourneyService();
                // ⭐ Phase C: company-machine cohort for THAT day, still requiring a
                //    home pin (the going-home flow needs somewhere to go home to).
                $companySet = array_flip(
                    (new \App\Services\Riders\VehicleResolver())->companyRiderIdsFor($selectedDate)
                );
                $bikeUsers = DB::table('t_ops_rider_profile')
                    ->whereIn('user_id', $userIds)
                    ->whereNotNull('home_latitude')
                    ->pluck('user_id')
                    ->filter(fn ($uid) => isset($companySet[(int) $uid]))
                    ->values()->all();
                if (!empty($bikeUsers)) {
                    $wCols = ['id', 'user_id', 'attendance_date', 'login_time', 'meter_start', 'meter_start_source',
                              'meter_start_recorded_at', 'work_expected_by', 'work_eta_min', 'work_distance_km',
                              'checkin_unlock_until', 'checkin_unlock_by', 'checkin_unlock_reason'];
                    // Measured start-meter location (guarded — added by the GPS-accuracy hardening SQL).
                    if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'meter_start_gps')) {
                        array_push($wCols, 'meter_start_gps', 'meter_start_distance_m', 'meter_start_office_m', 'meter_start_accuracy_m');
                    }
                    $wRows = DB::table('t_ops_attendance')
                        ->whereIn('user_id', $bikeUsers)->whereDate('attendance_date', $selectedDate)
                        ->get($wCols);
                    $unlockerIds = array_filter($wRows->pluck('checkin_unlock_by')->all());
                    $unlockerNames = empty($unlockerIds) ? collect()
                        : DB::table('t_sys_user')->whereIn('id', $unlockerIds)->pluck('fullname', 'id');
                    foreach ($wRows as $wr) {
                        $state = $wjSvc->deriveState($wr);
                        if ($state === 'none' && empty($wr->meter_start_recorded_at) && empty($wr->checkin_unlock_until)) {
                            continue; // nothing morning-related on this row
                        }
                        $cont = $wjSvc->continuity($wr); // home-source 1-km rule; office gaps stay with the page's grace logic
                        $workByUser[$wr->user_id] = [
                            'attendance_id' => (int) $wr->id,
                            'state'         => $state, // riding|overdue|arrived_on_time|arrived_late|no_home_start|none
                            'expected_by'   => $wr->work_expected_by ? substr((string) $wr->work_expected_by, 11, 5) : null,
                            'eta_min'       => $wr->work_eta_min !== null ? (int) $wr->work_eta_min : null,
                            'buffer_min'    => (int) $wjSvc->config('WORK_ETA_BUFFER_MIN', 10), // so the START line shows ETA + grace
                            'distance_km'   => $wr->work_distance_km !== null ? (float) $wr->work_distance_km : null,
                            'recorded_at'   => $wr->meter_start_recorded_at ? substr((string) $wr->meter_start_recorded_at, 11, 5) : null,
                            'source'        => $wr->meter_start_source,
                            // WHERE it was recorded, as a measurement (null on pre-hardening rows).
                            'place'         => \App\Services\Riders\WorkJourneyService::startPlace($wr),
                            'minutes_late'  => $wjSvc->minutesLate($wr),
                            'continuity'    => $cont, // {gap, breach, prev, prev_date} | null
                            'checkin_unlock'=> [
                                'active'  => $wjSvc->checkinUnlockActive($wr),
                                'until'   => $wr->checkin_unlock_until ? substr((string) $wr->checkin_unlock_until, 11, 5) : null,
                                'by_name' => $wr->checkin_unlock_by ? ($unlockerNames[$wr->checkin_unlock_by] ?? 'manager') : null,
                                'reason'  => $wr->checkin_unlock_reason,
                            ],
                        ];
                    }
                }
            }
        } catch (\Throwable $e) { /* morning info is optional — page must still load */ }

        // ── Checkout-unlock state per rider (the non-bike "forgot to check out" bypass).
        //    Guarded — no columns yet ⇒ no bypass info, page unaffected.
        $unlockByUser = [];
        try {
            if (!empty($userIds) && \Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'checkout_unlock_until')) {
                $uRows = DB::table('t_ops_attendance')
                    ->whereIn('user_id', $userIds)->whereDate('attendance_date', $selectedDate)
                    ->whereNotNull('checkout_unlock_until')
                    ->get(['user_id', 'logout_time', 'checkout_unlock_until', 'checkout_unlock_by', 'checkout_unlock_reason']);
                if ($uRows->isNotEmpty()) {
                    $unlockerNames = DB::table('t_sys_user')
                        ->whereIn('id', array_filter($uRows->pluck('checkout_unlock_by')->all()))
                        ->pluck('fullname', 'id');
                    foreach ($uRows as $u2) {
                        $unlockByUser[$u2->user_id] = [
                            'active'  => strtotime((string) $u2->checkout_unlock_until) >= time(),
                            'until'   => substr((string) $u2->checkout_unlock_until, 11, 5),
                            'used'    => !empty($u2->logout_time),
                            'reason'  => $u2->checkout_unlock_reason,
                            'by_name' => $u2->checkout_unlock_by ? ($unlockerNames[$u2->checkout_unlock_by] ?? 'manager') : null,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) { /* page must still load */ }

        // Which riders have a HALF-DAY on the selected date → their row shows "½ day", no late/OT.
        $halfDayToday = [];
        try {
            if (!empty($userIds)) {
                $halfDayToday = array_flip(DB::table('t_req_master as r')
                    ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                    ->where('c.category_code', 'leave')
                    ->where('r.leave_type', 'half_day')
                    ->whereIn('r.status', ['approved', 'pending'])
                    ->whereIn('r.requester_user_id', $userIds)
                    ->where('r.leave_start_date', '<=', $selectedDate)
                    ->where('r.leave_end_date', '>=', $selectedDate)
                    ->pluck('r.requester_user_id')->all());
            }
        } catch (\Throwable $e) { /* no half-days */ }

        $defaultGrace = (float) $this->attendanceConfig('ATTENDANCE_OVERNIGHT_GRACE_KM', 30);
        // Inline context for the Today view: this-month lateness (always) and
        // absent-this-year (only for a rider who is actually absent today, so the
        // heavier year scan runs on a bounded set).
        $cycle = $this->attendanceCycle();
        $monthStart = date('Y-m-01', strtotime($selectedDate));
        $checkoutClassifier = new \App\Services\Riders\CheckoutClassifierService();
        $dcSvc = new \App\Services\Riders\DayChecksService(); // shared web+mobile day-checks brain
        // Config reads hoisted ONCE — the loop below runs per rider, per refresh poll.
        $meterGpsWarnKm = (float) $this->attendanceConfig('ATTENDANCE_METER_GPS_WARN_KM', 10);
        $stuckMins = (int) $this->attendanceConfig('CHECKOUT_STUCK_ALERT_MINS', 25);
        // Delivered-order counts per rider on the selected date — used to flag the office-checkout
        // EXCEPTION (a non-bike rider who delivered but checked out at the office; R5). Guarded.
        $deliveredByUser = [];
        try {
            if (!empty($userIds)) {
                $delRows = DB::table('t_crm_order_status_history as h')
                    ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                    ->where('h.status_code', 'delivered')
                    ->whereIn('o.assigned_rider_user_id', $userIds)
                    ->whereDate('h.changed_at', $selectedDate)
                    ->groupBy('o.assigned_rider_user_id')
                    ->select('o.assigned_rider_user_id as uid', DB::raw('COUNT(*) as c'))
                    ->pluck('c', 'uid');
                foreach ($delRows as $uid => $c) { $deliveredByUser[$uid] = (int) $c; }
            }
        } catch (\Throwable $e) { /* no delivery data → no office-checkout exception flag */ }
        foreach ($rows as $row) {
            $pm = $prevMeter[$row->user_id] ?? null;
            $row->prev_meter_end = $pm['end'] ?? null;
            $row->prev_meter_date = $pm['date'] ?? null;
            $row->company_bike = isset($bikeSet[$row->user_id]) ? 1 : 0;
            // 0 = exempted from the meter in Rider Management (⛽ tick skips him).
            $row->meter_required = isset($meterExempt[$row->user_id]) ? 0 : 1;
            // Effective overnight grace: per-rider override → global default.
            $row->overnight_grace_km = $graceByUser[$row->user_id] ?? $defaultGrace;
            // ⭐ PHASE C3 — TRANSFER-DAY ALLOWANCE (owner ruling: "on transfer day
            //    instead of marking it personal usage we should give some room").
            //    Handing a bike over is real travel: the outgoing rider brings it
            //    to the incoming one. On the day an assignment starts or ends for
            //    the machine he is on, the overnight allowance gains
            //    VEHICLE_TRANSFER_GRACE_KM. Flag-gated with everything else — off,
            //    this line does nothing.
            $row->transfer_day = false;
            try {
                $vres = new \App\Services\Riders\VehicleResolver();
                if ($vres->rulesEnabled()) {
                    $vid = $vres->vehicleForDay((int) $row->user_id, $selectedDate);
                    if ($vid && $vres->isTransferDay($vid, $selectedDate)) {
                        $row->transfer_day = true;
                        $row->overnight_grace_km = (float) $row->overnight_grace_km
                            + (new \App\Services\Riders\VehicleService())->transferGraceKm();
                    }
                }
            } catch (\Throwable $e) { /* grace is a kindness, never a 500 */ }
            // Going-home meter state (drives the manager valve on the row); null = not on the flow.
            $row->home_journey = $homeByUser[$row->user_id] ?? null;
            // U5 morning-start state (START line + check-in-unlock valve); null = not on the flow.
            $row->work_journey = $workByUser[$row->user_id] ?? null;
            // Checkout-unlock state (the "forgot to check out" bypass); null = never granted today.
            $row->checkout_unlock = $unlockByUser[$row->user_id] ?? null;

            // HALF-DAY: the day counts as present with no lateness/overtime (owner's rule). Suppress
            // the per-row numbers so the pill shows "½ day", never "Late"; the frozen row is untouched.
            $row->is_half_day = isset($halfDayToday[$row->user_id]);
            if ($row->is_half_day) {
                $row->late_minutes = 0;
                $row->overtime_minutes = 0;
            }

            // Month-to-date total late minutes (same source as the Month tab).
            try {
                $lo = $shiftService->sumLateOvertimeMinutes((int) $row->user_id, $monthStart, $selectedDate);
                $row->month_late_minutes = (int) ($lo['late_minutes'] ?? 0);
            } catch (\Throwable $e) { $row->month_late_minutes = 0; }

            // Absent-this-year — only when this row is genuinely absent for the selected
            // day (working day, no login, not on approved/pending leave).
            $onLeave = $row->leave_request_id
                && in_array(strtolower((string) $row->leave_status), ['approved', 'pending'], true);
            $isAbsentToday = empty($row->login_time) && (($row->day_kind ?? 'working') === 'working') && !$onLeave;
            $row->year_absent_days = $isAbsentToday
                ? $this->yearAbsentDays((int) $row->user_id, $shiftService, $cycle, $selectedDate)
                : null;

            // Where did he check out? (manager view — office / at a customer / elsewhere,
            // + a flag if the delivery point was away from the address's verified pin).
            $row->checkout_info = ($row->logout_time && ($row->checkout_latitude ?? null) !== null)
                ? $checkoutClassifier->classify((int) $row->user_id, $selectedDate, $row->checkout_latitude, $row->checkout_longitude)
                : null;
            // Office-checkout EXCEPTION: a non-bike rider who delivered ≥1 order today but checked
            // out at the office (he came back). Marks the chip amber; bike / zero-delivery = normal.
            if (is_array($row->checkout_info) && ($row->checkout_info['status'] ?? null) === 'office'
                && (int) $row->company_bike === 0 && (int) ($deliveredByUser[$row->user_id] ?? 0) >= 1) {
                $row->checkout_info['office_exception'] = true;
            }

            // ── DAY CHECKS — the ⛽/📡 verdicts + issue chips + clean flag. Built by the SHARED
            //    DayChecksService so the web page, the mobile store screen, and the modals all
            //    read the SAME result (the one-brain rule). Only for rows that worked today.
            $row->day_checks = $row->login_time ? $dcSvc->build([
                'login_time' => $row->login_time, 'logout_time' => $row->logout_time,
                'role_name' => $row->role_name ?? null, 'company_bike' => ((int) $row->company_bike === 1),
                'meter_required' => $row->meter_required,
                'meter_start' => $row->meter_start, 'meter_end' => $row->meter_end, 'meter_distance' => $row->meter_distance,
                'road_distance_km' => $row->road_distance_km, 'gps_distance' => $row->gps_distance,
                'checkout_info' => $row->checkout_info, 'home_journey' => $row->home_journey,
                'work_journey' => $row->work_journey, 'checkout_unlock' => $row->checkout_unlock,
                'meter_start_recorded_at' => $row->meter_start_recorded_at ?? null,
                'home_meter_recorded_at' => $row->home_meter_recorded_at ?? null,
                'meter_start_source' => $row->meter_start_source ?? null,
            ], isset($allGpsReadings[$row->user_id]) ? $allGpsReadings[$row->user_id]->values()->all() : [],
               $selectedDate, $meterGpsWarnKm) : null;

            // Blocked-checkout context for the bypass modal — same shared formatter the mobile
            // sheet uses, so the two never disagree. null when no attempt was recorded.
            $row->checkout_attempt = \App\Services\Riders\DayChecksService::checkoutAttempt($row, $stuckMins);
        }

        $config = [
            'meter_gps_warn_km' => $meterGpsWarnKm,
            'overnight_grace_km' => $defaultGrace,
        ];

        return response()->json(['success' => true, 'data' => $rows, 'config' => $config]);
    }

    /** Read a t_fin_config value with a default (never throws — a config hiccup must
     *  not break the attendance page). Used for the meter-flag thresholds. */
    private function attendanceConfig(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Absent working-days for a user within the configured cycle
     * (cycle start → min(today, cycle end)). A date counts only when dayKind==='working'
     * (not off / holiday / before hire) AND the user was not on an approved leave AND had
     * no login. This is the SINGLE definition shared by the Month tab and the Today view,
     * so the "absent this year" number is consistent everywhere.
     */
    // Absent/leave-date logic now lives in HR\AttendanceYearService so the web Month tab
    // and the rider app share ONE definition. These thin wrappers keep call sites unchanged.
    private ?\App\Services\HR\AttendanceYearService $yearSvcMemo = null;
    private function yearSvc(): \App\Services\HR\AttendanceYearService
    {
        return $this->yearSvcMemo ??= new \App\Services\HR\AttendanceYearService();
    }
    private function yearAbsentDays(int $userId, \App\Services\ShiftResolutionService $shiftService, array $cycle, string $today): int
    {
        return $this->yearSvc()->yearAbsentDays($userId, $shiftService, $cycle, $today);
    }
    private function absentWorkingDates(int $userId, \App\Services\ShiftResolutionService $shiftService, string $start, string $end): array
    {
        return $this->yearSvc()->absentWorkingDates($userId, $shiftService, $start, $end);
    }
    private function approvedLeaveDates(int $userId, string $start, string $end): array
    {
        return $this->yearSvc()->approvedLeaveDates($userId, $start, $end);
    }

    /**
     * Exact dates behind a Month-tab number (absent/leave, month or year cycle). Powers the
     * click-to-see-dates drill-down. type ∈ month_absent | month_leave | year_absent | year_leave.
     */
    public function dateBreakdown(Request $request)
    {
        $userId = (int) $request->input('user_id');
        $type = $request->input('type');
        $month = $request->input('month'); // Y-m (for month_* types)
        if (!$userId || !in_array($type, ['month_absent', 'month_leave', 'year_absent', 'year_leave', 'month_overtime', 'month_late', 'leave_grants', 'month_office_checkout', 'month_meter_missed'], true)) {
            return response()->json(['success' => false, 'message' => 'Bad request'], 400);
        }
        $shiftService = new ShiftResolutionService();
        $today = date('Y-m-d');
        $cycle = $this->attendanceCycle();

        if (str_starts_with($type, 'month_')) {
            if (!preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
                return response()->json(['success' => false, 'message' => 'Bad month'], 400);
            }
            $start = "$month-01";
            $end = date('Y-m-t', strtotime($start));
        } else {
            $start = $cycle['start'];
            $end = $cycle['end'];
        }

        if ($type === 'leave_grants') {
            // Dated, attributed leave adjustments (overtime bonus / late penalty / manual) for
            // the current cycle — the "who/when/why" history. Cycle-scoped inside the service.
            $adj = (new \App\Services\HR\LeavePolicyService())->adjustments($userId);
            $srcLabels = ['overtime' => 'bonus (overtime)', 'late_penalty' => 'late penalty', 'manual' => 'yearly adjustment', 'half_day' => 'half day'];
            $items = [];
            foreach ($adj as $a) {
                if (empty($a['date'])) { continue; }
                $days = (float) $a['days'];
                $num = rtrim(rtrim(number_format(abs($days), 1), '0'), '.');
                // Show the manager's typed reason, but not payroll's auto-reasons ("Overtime
                // bonus Jul 2026") — those just repeat the source label.
                $reason = (string) ($a['reason'] ?? '');
                $showReason = $reason !== '' && !preg_match('/^(Overtime bonus|Late penalty) /', $reason);
                $lbl = ($days >= 0 ? '+' : '−') . $num . ' leave · ' . ($srcLabels[$a['source']] ?? $a['source'])
                    . ($showReason ? ' · “' . $reason . '”' : '')
                    . ($a['by_name'] ? ' · by ' . $a['by_name'] : '');
                $items[] = ['date' => $a['date'], 'label' => $lbl];
            }
        } elseif ($type === 'month_overtime') {
            // Days worked beyond the shift length + how much (label = "Xh Ym").
            $ot = (new \App\Services\HR\OvertimeService())->overtimeForRange($userId, $start, min($end, $today));
            $items = [];
            foreach ($ot['dates'] as $d => $mins) {
                $h = intdiv($mins, 60); $m = $mins % 60;
                $items[] = ['date' => $d, 'label' => ($h > 0 ? $h . 'h ' . $m . 'm' : $m . 'm')];
            }
        } elseif ($type === 'month_late') {
            // Days the rider clocked in late + by how much (label = "Xh Ym late").
            $lateDays = $shiftService->lateDaysBreakdown($userId, $start, min($end, $today));
            $items = array_map(function ($d) {
                $m = (int) $d['minutes']; $h = intdiv($m, 60); $mm = $m % 60;
                return ['date' => $d['date'], 'label' => ($h > 0 ? $h . 'h ' . $mm . 'm late' : $mm . 'm late')];
            }, $lateDays);
        } elseif ($type === 'month_office_checkout') {
            // Days a non-bike rider delivered but checked out at the office (R9.1).
            $ocMap = (new \App\Services\Riders\CheckoutClassifierService())
                ->officeCheckoutDays([$userId], $start, min($end, $today));
            $items = [];
            foreach (($ocMap[$userId] ?? []) as $oc) {
                $after = ($oc['minutes_after'] !== null) ? ($oc['minutes_after'] . ' min after last delivery to ' . $oc['last_customer']) : ('after delivering to ' . $oc['last_customer']);
                $items[] = ['date' => $oc['date'], 'label' => 'out ' . $oc['checkout_time'] . ' · ' . $after];
            }
        } elseif ($type === 'month_meter_missed') {
            // Finished working days with a start and/or closing meter missing (shared rule).
            $svc = new \App\Services\Riders\DayChecksService();
            $mm = $svc->meterMissDays([$userId], $start, min($end, $today));
            $items = [];
            foreach (($mm[$userId]['detail'] ?? []) as $d => $reason) {
                $items[] = ['date' => $d, 'label' => \App\Services\Riders\DayChecksService::METER_MISS_LABELS[$reason] ?? $reason];
            }
        } elseif (str_ends_with($type, '_absent')) {
            // Absent is only meaningful up to today (future working days aren't absences yet).
            $dates = $this->absentWorkingDates($userId, $shiftService, $start, min($end, $today));
            $items = array_map(fn($d) => ['date' => $d, 'label' => null], $dates);
        } else {
            $leaveMap = $this->approvedLeaveDates($userId, $start, $end);
            ksort($leaveMap);
            $items = [];
            foreach ($leaveMap as $d => $ltype) { $items[] = ['date' => $d, 'label' => $ltype]; }
        }

        return response()->json(['success' => true, 'type' => $type, 'count' => count($items), 'dates' => $items]);
    }

    /**
     * The configured yearly attendance cycle (['start'=>Y-m-d,'end'=>Y-m-d]) used for
     * "leaves this year" / "absent this year". The owner sets it in Attendance →
     * Settings (e.g. Jun→May), so it's NOT a fixed Jan–Dec calendar. Falls back to the
     * current calendar year when unset or invalid — preserving the old behaviour.
     */
    private function attendanceCycle(): array
    {
        try {
            $s = DB::table('t_fin_config')->where('config_key', 'ATTENDANCE_CYCLE_START')->value('config_value');
            $e = DB::table('t_fin_config')->where('config_key', 'ATTENDANCE_CYCLE_END')->value('config_value');
            if ($s && $e && strtotime((string) $s) && strtotime((string) $e)) {
                $s = substr((string) $s, 0, 10);
                $e = substr((string) $e, 0, 10);
                if ($s <= $e) {
                    return ['start' => $s, 'end' => $e];
                }
            }
        } catch (\Throwable $ex) { /* fall through to calendar year */ }
        $y = date('Y');
        return ['start' => "$y-01-01", 'end' => "$y-12-31"];
    }

    /**
     * Save the attendance policy settings (year cycle + meter thresholds) from the
     * Attendance → Settings modal. Upserts t_fin_config keys; validates the cycle.
     */
    public function saveAttendanceSettings(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'cycle_start' => 'nullable|date',
            'cycle_end' => 'nullable|date|after_or_equal:cycle_start',
            'meter_gps_warn_km' => 'nullable|numeric|min:0|max:1000',
            'overnight_grace_km' => 'nullable|numeric|min:0|max:1000',
            'leave_quota_total' => 'nullable|numeric|min:0|max:365',
            'leave_sameday_cap' => 'nullable|integer|min:0|max:365',
            'leave_sameday_cutoff' => 'nullable|date_format:H:i',
            'shift_target_hours' => 'nullable|numeric|min:0|max:24',
            'checkout_rule_enabled' => 'nullable|in:0,1',
            'checkout_window_mins' => 'nullable|integer|min:1|max:1440',
            'checkout_radius_m' => 'nullable|integer|min:10|max:5000',
            'require_location' => 'nullable|in:0,1',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $put = function ($key, $value) {
            $exists = DB::table('t_fin_config')->where('config_key', $key)->exists();
            if ($exists) {
                DB::table('t_fin_config')->where('config_key', $key)->update(['config_value' => $value, 'updated_at' => now()]);
            } else {
                DB::table('t_fin_config')->insert(['config_key' => $key, 'config_value' => $value, 'description' => 'Attendance policy setting', 'created_at' => now(), 'updated_at' => now()]);
            }
        };
        try {
            if ($request->filled('cycle_start') && $request->filled('cycle_end')) {
                $put('ATTENDANCE_CYCLE_START', substr($request->cycle_start, 0, 10));
                $put('ATTENDANCE_CYCLE_END', substr($request->cycle_end, 0, 10));
            }
            if ($request->filled('meter_gps_warn_km'))  { $put('ATTENDANCE_METER_GPS_WARN_KM', (string) (float) $request->meter_gps_warn_km); }
            if ($request->filled('overnight_grace_km')) { $put('ATTENDANCE_OVERNIGHT_GRACE_KM', (string) (float) $request->overnight_grace_km); }
            if ($request->filled('petrol_window_days')) { $put('PETROL_WINDOW_DAYS', (string) max(1, (int) $request->petrol_window_days)); }
            // Company-bike "going home" journey (U4)
            if ($request->filled('home_eta_buffer_min'))  { $put('HOME_ETA_BUFFER_MIN', (string) max(0, (int) $request->home_eta_buffer_min)); }
            if ($request->filled('home_late_unlock_mins')) { $put('HOME_LATE_UNLOCK_MINS', (string) max(1, (int) $request->home_late_unlock_mins)); }
            if ($request->filled('home_radius_m'))         { $put('HOME_RADIUS_M', (string) max(30, (int) $request->home_radius_m)); }
            // Leave policy (single pool + same-day cap) + overtime target hours.
            if ($request->filled('leave_quota_total'))    { $put('LEAVE_QUOTA_TOTAL', (string) (float) $request->leave_quota_total); }
            if ($request->filled('leave_sameday_cap'))    { $put('LEAVE_SAMEDAY_CAP', (string) (int) $request->leave_sameday_cap); }
            if ($request->filled('leave_sameday_cutoff')) { $put('LEAVE_SAMEDAY_CUTOFF', substr((string) $request->leave_sameday_cutoff, 0, 5)); }
            if ($request->filled('shift_target_hours'))   { $put('SHIFT_TARGET_HOURS', (string) (float) $request->shift_target_hours); }
            // Checkout rule (Phase H). enabled always written (a 0 is meaningful); numbers when present.
            if ($request->has('checkout_rule_enabled'))   { $put('CHECKOUT_RULE_ENABLED', $request->checkout_rule_enabled ? '1' : '0'); }
            if ($request->filled('checkout_window_mins')) { $put('CHECKOUT_DELIVERY_WINDOW_MINS', (string) (int) $request->checkout_window_mins); }
            if ($request->filled('checkout_radius_m'))    { $put('CHECKOUT_DELIVERY_RADIUS_M', (string) (int) $request->checkout_radius_m); }
            // Check-in rule: rider may only check in at their shift location. enabled always written.
            if ($request->has('require_location'))        { $put('ATTENDANCE_REQUIRE_LOCATION', $request->require_location ? '1' : '0'); }
            if ($request->filled('office_at_radius_m'))   { $put('OFFICE_AT_RADIUS_M', (string) max(30, (int) $request->office_at_radius_m)); }
            return response()->json(['success' => true, 'message' => 'Attendance settings saved.']);
        } catch (\Throwable $e) {
            \Log::error('saveAttendanceSettings failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the settings.'], 500);
        }
    }

    /** Current attendance policy settings for the settings modal. */
    public function getAttendanceSettings()
    {
        $cycle = $this->attendanceCycle();
        return response()->json([
            'success' => true,
            'cycle_start' => $cycle['start'],
            'cycle_end' => $cycle['end'],
            'meter_gps_warn_km' => (float) $this->attendanceConfig('ATTENDANCE_METER_GPS_WARN_KM', 10),
            'overnight_grace_km' => (float) $this->attendanceConfig('ATTENDANCE_OVERNIGHT_GRACE_KM', 30),
            'petrol_window_days' => (int) $this->attendanceConfig('PETROL_WINDOW_DAYS', 5),
            'home_eta_buffer_min' => (int) $this->attendanceConfig('HOME_ETA_BUFFER_MIN', 15),
            'home_late_unlock_mins' => (int) $this->attendanceConfig('HOME_LATE_UNLOCK_MINS', 10),
            'home_radius_m' => (int) $this->attendanceConfig('HOME_RADIUS_M', 300),
            'leave_quota_total' => (float) $this->attendanceConfig('LEAVE_QUOTA_TOTAL', 10),
            'leave_sameday_cap' => (int) $this->attendanceConfig('LEAVE_SAMEDAY_CAP', 4),
            'leave_sameday_cutoff' => (string) $this->attendanceConfig('LEAVE_SAMEDAY_CUTOFF', '10:00'),
            'shift_target_hours' => (float) $this->attendanceConfig('SHIFT_TARGET_HOURS', 9),
            'checkout_rule_enabled' => (int) $this->attendanceConfig('CHECKOUT_RULE_ENABLED', 0),
            'checkout_window_mins' => (int) $this->attendanceConfig('CHECKOUT_DELIVERY_WINDOW_MINS', 15),
            'checkout_radius_m' => (int) $this->attendanceConfig('CHECKOUT_DELIVERY_RADIUS_M', 150),
            'require_location' => (int) $this->attendanceConfig('ATTENDANCE_REQUIRE_LOCATION', 0),
            'office_at_radius_m' => (int) $this->attendanceConfig('OFFICE_AT_RADIUS_M', 300),
        ]);
    }

    /**
     * Apply a leave for a rider from the attendance page (manager on-behalf). Creates a
     * standard t_req_master leave request. Because a manager is granting it, it's marked
     * approved immediately, with an audit note recording who applied it. Overlapping
     * leave is rejected (same rule as the normal request form). A rider applying for
     * themselves still uses the normal request/approval flow — this endpoint is the
     * manager's shortcut only.
     */
    public function applyLeave(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'leave_start_date' => 'required|date',
            'leave_end_date' => 'required|date|after_or_equal:leave_start_date',
            'note' => 'nullable|string|max:500',
            'override_quota' => 'nullable|boolean',
            'leave_type' => 'nullable|in:planned,emergency,half_day',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $userId = (int) $request->user_id;
        $start = $request->leave_start_date;
        $end = $request->leave_end_date;
        // Manager picks the kind explicitly (default planned). NO time cutoff applies
        // to a manager — the 10:00 same-day rule only binds rider self-applies. An
        // 'emergency' choice counts toward the rider's same-day allowance (cap query
        // counts every emergency row), which is intended: the rider still took a
        // same-day leave, regardless of who typed it in.
        $leaveType = $request->input('leave_type') ?: 'planned';
        $isHalfDay = $leaveType === 'half_day';

        // Half-day rules (RW-1 manager-only path; RW-2 needs a check-in that day):
        // single date, and the rider must have actually checked in on it (½ day = worked half).
        if ($isHalfDay) {
            if (substr((string) $start, 0, 10) !== substr((string) $end, 0, 10)) {
                return response()->json(['success' => false, 'message' => 'A half day is for a single date only.'], 422);
            }
            $checkedIn = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->whereDate('attendance_date', substr((string) $start, 0, 10))
                ->whereNotNull('login_time')->where('login_time', '!=', '')
                ->exists();
            if (!$checkedIn) {
                return response()->json([
                    'success' => false,
                    'message' => 'A half day needs a check-in that day. Use a full leave (or mark absent) for a day he did not come in.',
                ], 422);
            }
        }

        try {
            $catId = DB::table('t_req_category')->where('category_code', 'leave')->value('id');
            if (!$catId) {
                return response()->json(['success' => false, 'message' => 'Leave category is not configured.'], 500);
            }

            // Manager is UNBOUND by the quota, but we warn once if this pushes the rider
            // over his yearly balance — the frontend re-submits with override_quota=1.
            // A half day charges 0.5 against the yearly counter.
            $days = $isHalfDay ? 0.5 : (\Carbon\Carbon::parse($start)->diffInDays(\Carbon\Carbon::parse($end)) + 1);
            $overQuota = false;
            if (!$request->boolean('override_quota')) {
                $bal = (new \App\Services\HR\LeavePolicyService())->balance($userId);
                if ($days > $bal['remaining']) {
                    $name = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'This rider';
                    $rem = $bal['remaining'] > 0 ? $bal['remaining'] : 0;
                    return response()->json([
                        'success' => false,
                        'needs_confirm' => true,
                        'message' => "{$name} has only {$rem} leave(s) left this year but this adds {$days}. Grant anyway (over quota)?",
                    ], 200);
                }
            } else {
                $overQuota = true;
            }

            // Reject overlap with an existing pending/approved leave (same guard the
            // normal request form uses) so a day isn't double-counted.
            $overlap = DB::table('t_req_master as r')
                ->where('r.category_id', $catId)
                ->where('r.requester_user_id', $userId)
                ->whereIn('r.status', ['approved', 'pending'])
                ->where('r.leave_start_date', '<=', $end)
                ->where('r.leave_end_date', '>=', $start)
                ->value('r.request_number');
            if ($overlap) {
                return response()->json([
                    'success' => false,
                    'message' => "This rider already has a leave covering those dates ({$overlap})."
                ], 422);
            }

            $managerId = auth()->id();
            $managerName = DB::table('t_sys_user')->where('id', $managerId)->value('fullname') ?: 'Manager';
            $note = trim((string) $request->input('note', ''));
            $desc = ($note !== '' ? $note . ' — ' : '')
                . 'Applied on behalf by ' . $managerName . ' via Attendance on ' . now()->format('d M Y H:i')
                . ($overQuota ? ' (over quota, granted by manager)' : '');

            $id = DB::table('t_req_master')->insertGetId([
                'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
                'category_id' => $catId,
                'requester_user_id' => $userId,
                'title' => 'Leave',
                'description' => $desc,
                'business_unit_id' => 1,
                'leave_start_date' => $start,
                'leave_end_date' => $end,
                // Manager-chosen kind (default planned; emergency = same-day, counts
                // toward the rider's same-day allowance). Never time-restricted here.
                'leave_type' => $leaveType,
                // leave_days is an INT calendar-day count (1 for a half day — it occupies one
                // date). The 0.5 quota weight lives in LeavePolicyService::takenDays(), not here.
                'leave_days' => $isHalfDay ? 1 : $days,
                'status' => 'approved',
                'priority' => 'normal',
                'requires_level_1' => 1,
                'requires_level_2' => 0,
                'level_1_status' => 'approved',
                'settlement_status' => 'not_required',
                'remarks' => 'Manager-applied leave (auto-approved)',
                'submitted_at' => now(),
                'completed_at' => now(),
                'created_by' => $managerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            \Log::info('Manager-applied leave', [
                'leave_id' => $id, 'user_id' => $userId, 'by' => $managerId, 'from' => $start, 'to' => $end, 'days' => $days,
            ]);

            $msg = $isHalfDay
                ? 'Half day approved (0.5 leave).'
                : "Leave approved — {$days} day" . ($days > 1 ? 's' : '') . '.';
            return response()->json(['success' => true, 'message' => $msg, 'id' => $id]);
        } catch (\Throwable $e) {
            \Log::error('Apply-leave failed', ['error' => $e->getMessage(), 'user_id' => $userId]);
            return response()->json(['success' => false, 'message' => 'Could not apply the leave. Please try again.'], 500);
        }
    }

    /**
     * Pending leave requests the rider submitted himself — shown on the Attendance page so the
     * manager can clear them without leaving. Upcoming/current first; stale past ones included so
     * nothing is silently stuck. (Manager-applied leaves are already approved, so excluded.)
     */
    public function pendingLeaves(Request $request)
    {
        try {
            $catId = DB::table('t_req_category')->where('category_code', 'leave')->value('id');
            if (!$catId) {
                return response()->json(['success' => true, 'requests' => []]);
            }
            $rows = DB::table('t_req_master as r')
                ->join('t_sys_user as u', 'u.id', '=', 'r.requester_user_id')
                ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
                ->where('r.category_id', $catId)
                ->whereIn('r.status', ['pending', 'pending_l1', 'pending_l2', 'submitted'])
                ->where('u.is_active', 1)
                ->where(function ($q) {
                    $q->whereNull('av.is_visible')->orWhere('av.is_visible', 1);
                })
                ->orderByRaw('CASE WHEN r.leave_end_date >= CURDATE() THEN 0 ELSE 1 END')
                ->orderBy('r.leave_start_date')
                ->limit(100)
                ->get([
                    'r.id', 'r.requester_user_id', 'u.fullname', 'r.leave_start_date', 'r.leave_end_date',
                    'r.leave_type', 'r.leave_days', 'r.description', 'r.status', 'r.submitted_at', 'r.created_at',
                ]);

            $out = $rows->map(function ($r) {
                $days = (int) ($r->leave_days ?: (\Carbon\Carbon::parse($r->leave_start_date)->diffInDays(\Carbon\Carbon::parse($r->leave_end_date)) + 1));
                return [
                    'id' => $r->id,
                    'user_id' => $r->requester_user_id,
                    'name' => $r->fullname,
                    'start' => substr((string) $r->leave_start_date, 0, 10),
                    'end' => substr((string) $r->leave_end_date, 0, 10),
                    'days' => $days,
                    'type' => $r->leave_type ?: 'leave',
                    'reason' => $r->description,
                    'upcoming' => substr((string) $r->leave_end_date, 0, 10) >= date('Y-m-d'),
                    'submitted' => substr((string) ($r->submitted_at ?: $r->created_at), 0, 10),
                ];
            })->values();

            return response()->json(['success' => true, 'requests' => $out, 'count' => $out->count()]);
        } catch (\Throwable $e) {
            \Log::error('pendingLeaves failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load pending leaves.'], 500);
        }
    }

    /**
     * Approve a rider-submitted leave request from the Attendance page (manager authority —
     * same effect as the on-behalf apply-leave: status→approved, so it counts as a leave and the
     * rider stops showing Absent for those days). The rider's app re-syncs the status.
     */
    public function approveLeaveRequest(Request $request, $id)
    {
        try {
            $catId = DB::table('t_req_category')->where('category_code', 'leave')->value('id');
            $req = DB::table('t_req_master')->where('id', $id)->where('category_id', $catId)->first();
            if (!$req) {
                return response()->json(['success' => false, 'message' => 'Leave request not found.'], 404);
            }
            if ($req->status === 'approved') {
                return response()->json(['success' => true, 'message' => 'Already approved.']);
            }
            if (in_array($req->status, ['rejected', 'cancelled'], true)) {
                return response()->json(['success' => false, 'message' => 'This request was already ' . $req->status . '.'], 400);
            }

            $managerId = auth()->id();
            $managerName = DB::table('t_sys_user')->where('id', $managerId)->value('fullname') ?: 'Manager';
            DB::table('t_req_master')->where('id', $id)->update([
                'status' => 'approved',
                'level_1_status' => 'approved',
                'remarks' => trim((string) $req->remarks . ' | Approved by ' . $managerName . ' via Attendance on ' . now()->format('d M Y H:i')),
                'requester_sync_required' => 1,
                'completed_at' => now(),
                'updated_by' => $managerId,
                'updated_at' => now(),
            ]);
            \Log::info('Leave request approved via attendance', ['leave_id' => $id, 'user_id' => $req->requester_user_id, 'by' => $managerId]);
            return response()->json(['success' => true, 'message' => 'Leave approved.']);
        } catch (\Throwable $e) {
            \Log::error('approveLeaveRequest failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not approve the leave.'], 500);
        }
    }

    /** Reject a rider-submitted leave request from the Attendance page (manager authority). */
    public function rejectLeaveRequest(Request $request, $id)
    {
        try {
            $catId = DB::table('t_req_category')->where('category_code', 'leave')->value('id');
            $req = DB::table('t_req_master')->where('id', $id)->where('category_id', $catId)->first();
            if (!$req) {
                return response()->json(['success' => false, 'message' => 'Leave request not found.'], 404);
            }
            if (in_array($req->status, ['approved', 'rejected', 'cancelled'], true)) {
                return response()->json(['success' => false, 'message' => 'This request was already ' . $req->status . '.'], 400);
            }

            $managerId = auth()->id();
            $reason = trim((string) $request->input('reason', '')) ?: 'Rejected via Attendance';
            DB::table('t_req_master')->where('id', $id)->update([
                'status' => 'rejected',
                'level_1_status' => 'rejected',
                'rejection_reason' => $reason,
                'requester_sync_required' => 1,
                'completed_at' => now(),
                'updated_by' => $managerId,
                'updated_at' => now(),
            ]);
            \Log::info('Leave request rejected via attendance', ['leave_id' => $id, 'user_id' => $req->requester_user_id, 'by' => $managerId]);
            return response()->json(['success' => true, 'message' => 'Leave rejected.']);
        } catch (\Throwable $e) {
            \Log::error('rejectLeaveRequest failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not reject the leave.'], 500);
        }
    }

    /**
     * List a user's APPROVED leave requests in the current cycle (with ids) so the manager can
     * pick one to UNDO. Read-only; feeds the Undo affordance in the leave-summary modal.
     */
    public function approvedLeaves(Request $request)
    {
        try {
            $userId = (int) $request->input('user_id');
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'user_id required.'], 422);
            }
            $catId = DB::table('t_req_category')->where('category_code', 'leave')->value('id');
            if (!$catId) {
                return response()->json(['success' => true, 'leaves' => []]);
            }
            $cycle = $this->attendanceCycle();
            $rows = DB::table('t_req_master')
                ->where('category_id', $catId)
                ->where('requester_user_id', $userId)
                ->where('status', 'approved')
                // any leave that overlaps the cycle window
                ->where('leave_start_date', '<=', $cycle['end'])
                ->where('leave_end_date', '>=', $cycle['start'])
                ->orderByDesc('leave_start_date')
                ->limit(100)
                ->get(['id', 'leave_start_date', 'leave_end_date', 'leave_type', 'leave_days']);

            $out = $rows->map(function ($r) {
                $days = (int) ($r->leave_days ?: (\Carbon\Carbon::parse($r->leave_start_date)->diffInDays(\Carbon\Carbon::parse($r->leave_end_date)) + 1));
                return [
                    'id' => $r->id,
                    'start' => substr((string) $r->leave_start_date, 0, 10),
                    'end' => substr((string) $r->leave_end_date, 0, 10),
                    'type' => $r->leave_type ?: 'leave',
                    'days' => $days,
                ];
            })->values();

            return response()->json(['success' => true, 'leaves' => $out]);
        } catch (\Throwable $e) {
            \Log::error('approvedLeaves failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load approved leaves.'], 500);
        }
    }

    /**
     * UNDO a mistakenly-approved leave (manager authority, from the Attendance page).
     * Sets the leave back to 'cancelled' (distinct from 'rejected' — it wasn't a refusal),
     * which returns the day(s) to the rider's balance EVERYWHERE automatically: every balance/
     * absent/on-leave surface derives live from status='approved', and nothing is persisted at
     * approval (t_hr_leave_grant is untouched). Also deletes the vestigial marker rows in
     * t_ops_attendance (leave_request_id set, no login) — only some approval paths create them, so
     * a zero-row delete is fine. After undo the manager re-applies the correct day via apply-leave.
     */
    public function undoLeaveApproval(Request $request, $id)
    {
        try {
            $catId = DB::table('t_req_category')->where('category_code', 'leave')->value('id');
            $req = DB::table('t_req_master')->where('id', $id)->where('category_id', $catId)->first();
            if (!$req) {
                return response()->json(['success' => false, 'message' => 'Leave request not found.'], 404);
            }
            if ($req->status !== 'approved') {
                return response()->json(['success' => false, 'message' => 'Only an APPROVED leave can be undone (this one is ' . $req->status . ').'], 400);
            }

            $managerId = auth()->id();
            $managerName = DB::table('t_sys_user')->where('id', $managerId)->value('fullname') ?: 'Manager';
            $reason = trim((string) $request->input('reason', ''));
            $note = 'Approval UNDONE by ' . $managerName . ' on ' . now()->format('d M Y H:i') . ($reason !== '' ? ' (' . $reason . ')' : '');

            DB::transaction(function () use ($id, $req, $managerId, $note) {
                DB::table('t_req_master')->where('id', $id)->update([
                    'status' => 'cancelled',
                    'level_1_status' => 'cancelled',
                    'remarks' => trim((string) $req->remarks . ' | ' . $note),
                    'requester_sync_required' => 1,
                    'completed_at' => now(),
                    'updated_by' => $managerId,
                    'updated_at' => now(),
                ]);
                // Clean up any leave-marker attendance rows (present only for the processApproval path).
                DB::table('t_ops_attendance')
                    ->where('leave_request_id', $id)
                    ->whereNull('login_time')
                    ->delete();
            });

            \Log::info('Leave approval undone via attendance', ['leave_id' => $id, 'user_id' => $req->requester_user_id, 'by' => $managerId]);
            return response()->json([
                'success' => true,
                'message' => 'Leave approval undone — the day(s) return to the balance. Apply the correct leave if needed.',
            ]);
        } catch (\Throwable $e) {
            \Log::error('undoLeaveApproval failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not undo the leave.'], 500);
        }
    }

    /**
     * Correct a rider's meter reading from the web Attendance page (manager action, audited).
     * Mirror of the mobile RiderController::updateMeterValues. Used when a rider recorded a wrong
     * meter (e.g. a rejected petrol request) — the manager fixes it here, then the rider re-raises
     * the petrol request which recomputes from the corrected meter. Attendance-admin only
     * (block.rider on the route); NEVER touches petrol request rows, only the attendance meter cols.
     */
    public function updateMeterValues(Request $request)
    {
        try {
            $validated = $request->validate([
                'attendance_id' => 'required|integer',
                'meter_start' => 'nullable|integer|min:0',
                'meter_end' => 'nullable|integer|min:0',
            ]);

            $att = DB::table('t_ops_attendance')->where('id', $validated['attendance_id'])->first();
            if (!$att) {
                return response()->json(['success' => false, 'message' => 'Attendance record not found.'], 404);
            }

            // Only write the fields that were actually sent (so a blank field doesn't wipe a value).
            $update = ['updated_by' => auth()->id(), 'updated_at' => now()];
            if ($request->has('meter_start')) { $update['meter_start'] = $validated['meter_start']; }
            if ($request->has('meter_end'))   { $update['meter_end']   = $validated['meter_end']; }

            // ⭐ U5 — audit where the START reading came from: a manager filling a MISSING /
            // office-typed start stamps source='manager' (+moment). A correction of a genuine
            // home recording keeps its 'home' stamp — the manager only fixed digits.
            if ($request->has('meter_start') && $validated['meter_start'] !== null
                && \Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'meter_start_source')
                && (string) ($att->meter_start_source ?? '') !== 'home') {
                $update['meter_start_source'] = 'manager';
                $update['meter_start_recorded_at'] = now();
            }

            // ⭐ U4: for a company-bike rider on the going-home flow, meter_home IS the day-closing
            // reading (a mirror of meter_end). Keep them in sync so a manager correcting meter_end
            // never leaves the home journey stuck "locked" with a stale/empty meter_home. Only
            // touches bike-journey rows (home_expected_by set); non-bike rows are unaffected.
            if ($request->has('meter_end') && $validated['meter_end'] !== null
                && !empty($att->home_expected_by)
                && \Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'meter_home')) {
                $update['meter_home'] = $validated['meter_end'];
                if (empty($att->meter_home)) {
                    // Journey was still open → close it now (honest arrival = the correction moment).
                    if (empty($att->home_arrived_at)) {
                        $update['home_arrived_at'] = now();
                        $update['home_arrival_source'] = 'manager';
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'home_meter_recorded_at')) {
                        $update['home_meter_recorded_at'] = now();
                    }
                }
            }

            DB::table('t_ops_attendance')->where('id', $att->id)->update($update);

            \Log::info('Meter values corrected via web attendance', [
                'attendance_id' => $att->id, 'user_id' => $att->user_id, 'by' => auth()->id(),
                'meter_start' => $update['meter_start'] ?? $att->meter_start,
                'meter_end' => $update['meter_end'] ?? $att->meter_end,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Meter updated.',
                'meter_start' => $update['meter_start'] ?? $att->meter_start,
                'meter_end' => $update['meter_end'] ?? $att->meter_end,
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['success' => false, 'message' => $ve->validator->errors()->first()], 422);
        } catch (\Throwable $e) {
            \Log::error('updateMeterValues (web) failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not update the meter.'], 500);
        }
    }

    /**
     * U4 — manager BYPASS for a late home journey: open a timed unlock so the rider can enter his
     * home meter (he was locked out for being late). Reason required; audited. Attendance-admin only
     * (block.rider on the route). Optional FCM nudge to the rider.
     */
    public function homeJourneyUnlock(Request $request)
    {
        try {
            $validated = $request->validate([
                'attendance_id' => 'required|integer',
                'reason' => 'required|string|max:200',
                'action' => 'nullable|in:unlock,clear',
            ]);
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'home_meter_unlock_until')) {
                return response()->json(['success' => false, 'message' => 'Run the home-journey SQL first.'], 422);
            }
            $att = DB::table('t_ops_attendance')->where('id', $validated['attendance_id'])->first();
            if (!$att) {
                return response()->json(['success' => false, 'message' => 'Attendance record not found.'], 404);
            }
            $managerId = auth()->id();

            if (($validated['action'] ?? 'unlock') === 'clear') {
                DB::table('t_ops_attendance')->where('id', $att->id)->update([
                    'home_meter_unlock_until' => null, 'home_meter_unlock_by' => null, 'updated_at' => now(),
                ]);
                return response()->json(['success' => true, 'message' => 'Unlock cleared.']);
            }

            $hjSvc = new \App\Services\Riders\HomeJourneyService();
            $mins = (int) $hjSvc->config('HOME_LATE_UNLOCK_MINS', 10);
            if ($mins < 1) { $mins = 10; }
            $until = now()->addMinutes($mins);
            $upd = [
                'home_meter_unlock_until' => $until,
                'home_meter_unlock_by' => $managerId,
                'home_late_reason' => trim($validated['reason']),
                'updated_at' => now(),
            ];
            // Record WHAT was breached at bypass time (owner's rule: the audit must show
            // whether the location, the time, or both were wrong when the manager overrode).
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'home_bypass_breach')) {
                $upd['home_bypass_breach'] = $hjSvc->bypassBreach($att);
            }
            DB::table('t_ops_attendance')->where('id', $att->id)->update($upd);

            // Non-fatal nudge to the rider that entry is open.
            try {
                (new \App\Services\FirebaseService())->notifyUser(
                    (int) $att->user_id,
                    ['title' => 'Meter entry unlocked', 'body' => 'Your manager opened meter entry — record your home meter within ' . $mins . ' minutes.'],
                    ['type' => 'home_meter_unlock'],
                    'shift_notifications'
                );
            } catch (\Throwable $e) { /* push is best-effort */ }

            return response()->json(['success' => true, 'message' => 'Unlocked for ' . $mins . ' minutes.', 'unlock_until' => $until->format('Y-m-d H:i:s')]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['success' => false, 'message' => $ve->validator->errors()->first()], 422);
        } catch (\Throwable $e) {
            \Log::error('homeJourneyUnlock failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not unlock.'], 500);
        }
    }

    /**
     * Checkout BYPASS — a rider blocked by the checkout-location rule (forgot to check out at his
     * last delivery / the office and went home) gets a timed unlock so he can press OUT from where
     * he is. Granted from the attendance page's "Rider bypasses" modal; audited on the row.
     */
    public function checkoutUnlock(Request $request)
    {
        try {
            $validated = $request->validate([
                'attendance_id' => 'required|integer',
                'reason' => 'required|string|max:200',
                'action' => 'nullable|in:unlock,clear',
            ]);
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'checkout_unlock_until')) {
                return response()->json(['success' => false, 'message' => 'Run the wave-2 SQL first.'], 422);
            }
            $att = DB::table('t_ops_attendance')->where('id', $validated['attendance_id'])->first();
            if (!$att) {
                return response()->json(['success' => false, 'message' => 'Attendance record not found.'], 404);
            }
            if (($validated['action'] ?? 'unlock') === 'clear') {
                DB::table('t_ops_attendance')->where('id', $att->id)->update([
                    'checkout_unlock_until' => null, 'checkout_unlock_by' => null,
                    'checkout_unlock_reason' => null, 'updated_at' => now(),
                ]);
                return response()->json(['success' => true, 'message' => 'Checkout unlock cleared.']);
            }
            if (!empty($att->logout_time)) {
                return response()->json(['success' => false, 'message' => 'Already checked out — nothing to unlock.'], 422);
            }
            $mins = (int) $this->attendanceConfig('CHECKOUT_UNLOCK_MINS', 10);
            if ($mins < 1) { $mins = 10; }
            $until = now()->addMinutes($mins);
            DB::table('t_ops_attendance')->where('id', $att->id)->update([
                'checkout_unlock_until' => $until,
                'checkout_unlock_by' => auth()->id(),
                'checkout_unlock_reason' => trim($validated['reason']),
                'updated_at' => now(),
            ]);
            // Tell the rider his OUT button will work now (best-effort).
            try {
                (new \App\Services\FirebaseService())->notifyUser(
                    (int) $att->user_id,
                    ['title' => 'Checkout unlocked', 'body' => 'Your manager unlocked checkout — press OUT within ' . $mins . ' minutes from wherever you are.'],
                    ['type' => 'checkout_unlock'],
                    'shift_notifications'
                );
            } catch (\Throwable $e) { /* push best-effort */ }
            return response()->json(['success' => true, 'message' => 'Checkout unlocked for ' . $mins . ' minutes.', 'unlock_until' => $until->format('Y-m-d H:i:s')]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['success' => false, 'message' => $ve->validator->errors()->first()], 422);
        } catch (\Throwable $e) {
            \Log::error('checkoutUnlock failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not unlock.'], 500);
        }
    }

    /**
     * U5 — check-IN unlock (the Phase-2 morning-lock valve). When CHECKIN_ETA_LOCK is on, a
     * company-bike rider past his ride-to-work deadline — or with no home start at all — is
     * blocked from checking in; this opens a timed window so his IN button works. Accepts
     * user_id (+date today) because the locked rider may have NO attendance row yet (he never
     * recorded a home start) — in that case a bare row is created to carry the unlock, and
     * checkIn() later fills login_time into that same row. Audited; harmless while the lock
     * config is off.
     */
    public function checkinUnlock(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer',
                'reason' => 'required|string|max:200',
                'action' => 'nullable|in:unlock,clear',
            ]);
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'checkin_unlock_until')) {
                return response()->json(['success' => false, 'message' => 'Run the wave-4 SQL first.'], 422);
            }
            $today = now()->format('Y-m-d');
            $att = DB::table('t_ops_attendance')
                ->where('user_id', $validated['user_id'])->whereDate('attendance_date', $today)->first();

            if (($validated['action'] ?? 'unlock') === 'clear') {
                if ($att) {
                    DB::table('t_ops_attendance')->where('id', $att->id)->update([
                        'checkin_unlock_until' => null, 'checkin_unlock_by' => null,
                        'checkin_unlock_reason' => null, 'updated_at' => now(),
                    ]);
                }
                return response()->json(['success' => true, 'message' => 'Check-in unlock cleared.']);
            }
            if ($att && !empty($att->login_time)) {
                return response()->json(['success' => false, 'message' => 'Already checked in — nothing to unlock.'], 422);
            }
            $mins = (int) $this->attendanceConfig('CHECKIN_UNLOCK_MINS', 10);
            if ($mins < 1) { $mins = 10; }
            $until = now()->addMinutes($mins);
            $fields = [
                'checkin_unlock_until' => $until,
                'checkin_unlock_by' => auth()->id(),
                'checkin_unlock_reason' => trim($validated['reason']),
                'updated_at' => now(),
            ];
            if ($att) {
                DB::table('t_ops_attendance')->where('id', $att->id)->update($fields);
            } else {
                DB::table('t_ops_attendance')->insert(array_merge($fields, [
                    'user_id' => (int) $validated['user_id'],
                    'attendance_date' => $today,
                    'created_at' => now(),
                ]));
            }
            try {
                (new \App\Services\FirebaseService())->notifyUser(
                    (int) $validated['user_id'],
                    ['title' => 'Check-in unlocked', 'body' => 'Your manager unlocked check-in — press IN within ' . $mins . ' minutes.'],
                    ['type' => 'checkin_unlock'],
                    'shift_notifications'
                );
            } catch (\Throwable $e) { /* push best-effort */ }
            return response()->json(['success' => true, 'message' => 'Check-in unlocked for ' . $mins . ' minutes.', 'unlock_until' => $until->format('Y-m-d H:i:s')]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['success' => false, 'message' => $ve->validator->errors()->first()], 422);
        } catch (\Throwable $e) {
            \Log::error('checkinUnlock failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not unlock.'], 500);
        }
    }

    /**
     * U4 — manager enters the home meter FOR the rider (phone dead / can't submit). Closes the day:
     * writes meter_home + meter_end + home_arrived_at (source=manager) + reason. Attendance-admin only.
     */
    public function homeMeterManagerEntry(Request $request)
    {
        try {
            $validated = $request->validate([
                'attendance_id' => 'required|integer',
                'meter_home' => 'required|integer|min:0',
                'reason' => 'nullable|string|max:200',
            ]);
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'meter_home')) {
                return response()->json(['success' => false, 'message' => 'Run the home-journey SQL first.'], 422);
            }
            $att = DB::table('t_ops_attendance')->where('id', $validated['attendance_id'])->first();
            if (!$att) {
                return response()->json(['success' => false, 'message' => 'Attendance record not found.'], 404);
            }
            $managerId = auth()->id();
            $reason = trim((string) ($validated['reason'] ?? '')) ?: 'Entered by manager';
            $upd = [
                'meter_home' => (int) $validated['meter_home'],
                'meter_end' => (int) $validated['meter_home'], // day-closing reading
                'home_arrived_at' => now(),
                'home_arrival_source' => 'manager',
                'home_late_reason' => $reason,
                'home_meter_unlock_by' => $managerId,
                'updated_by' => $managerId,
                'updated_at' => now(),
            ];
            // Audit what was wrong BEFORE the manager entered it (computed on the pre-update row).
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'home_bypass_breach')) {
                $upd['home_bypass_breach'] = (new \App\Services\Riders\HomeJourneyService())->bypassBreach($att);
            }
            // Exact meter-entry moment for the attendance-page timeline (guarded — column is wave-3).
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'home_meter_recorded_at')) {
                $upd['home_meter_recorded_at'] = now();
            }
            DB::table('t_ops_attendance')->where('id', $att->id)->update($upd);
            \Log::info('Home meter entered by manager', ['attendance_id' => $att->id, 'user_id' => $att->user_id, 'by' => $managerId]);
            return response()->json(['success' => true, 'message' => 'Home meter recorded for the rider.']);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['success' => false, 'message' => $ve->validator->errors()->first()], 422);
        } catch (\Throwable $e) {
            \Log::error('homeMeterManagerEntry failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not record the home meter.'], 500);
        }
    }

    /**
     * U4 — dismissable in-app banners: company-bike riders who came home late / forgot their meter.
     * Audience-gated by the 'receive_bike_meter_alerts' permission (the owner assigns the roles);
     * anyone else gets an empty list so the banner never shows. Excludes the caller's dismissals.
     */
    public function homeAlerts(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user || !$user->hasMobilePermission('receive_bike_meter_alerts')) {
                return response()->json(['success' => true, 'alerts' => []]);
            }
            $alerts = (new \App\Services\Riders\HomeJourneyService())->openEscalations();
            if (!empty($alerts) && \Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal')) {
                $keys = array_map(fn ($a) => 'home_meter:' . $a['attendance_id'], $alerts);
                $dismissed = array_flip(DB::table('t_ops_alert_dismissal')
                    ->where('user_id', $user->id)->whereIn('alert_key', $keys)->pluck('alert_key')->all());
                $alerts = array_values(array_filter($alerts, fn ($a) => !isset($dismissed['home_meter:' . $a['attendance_id']])));
            }
            return response()->json(['success' => true, 'alerts' => $alerts]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'alerts' => []]);
        }
    }

    /** U4 — the current user dismisses a home-meter banner (per user, per attendance row). */
    public function dismissHomeAlert(Request $request)
    {
        try {
            $validated = $request->validate(['attendance_id' => 'required|integer']);
            $user = auth()->user();
            if (!$user) { return response()->json(['success' => false], 401); }
            if (\Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal')) {
                DB::table('t_ops_alert_dismissal')->updateOrInsert(
                    ['user_id' => $user->id, 'alert_key' => 'home_meter:' . $validated['attendance_id']],
                    ['dismissed_at' => now()]
                );
            }
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not dismiss.'], 500);
        }
    }

    /**
     * Live "rider stuck at checkout" alerts (dismissable). A rider pressed OUT and the mandatory
     * location/time rule refused it — recorded as a checkout_attempt on his row (Phase 1). Returns
     * riders whose LATEST blocked attempt is still OPEN (not checked out) and RECENT (within
     * CHECKOUT_STUCK_ALERT_MINS), minus the caller's dismissals. The definition of "stuck" comes
     * straight from DayChecksService::checkoutAttempt (one brain — same block the bypass modal shows).
     * Audience-gated by 'view_store_attendance' (the people who manage the store attendance screen).
     * A dismissal is re-raised only if the rider tries AGAIN after it (attempt time > dismissed_at).
     */
    public function checkoutStuckAlerts(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user || !$user->hasMobilePermission('view_store_attendance')) {
                return response()->json(['success' => true, 'alerts' => [], 'count' => 0, 'latest_id' => 0]);
            }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'checkout_attempt_at')) {
                return response()->json(['success' => true, 'alerts' => [], 'count' => 0, 'latest_id' => 0]);
            }
            $freshMins = (int) $this->attendanceConfig('CHECKOUT_STUCK_ALERT_MINS', 25);
            $today = now()->format('Y-m-d');
            $since = now()->subMinutes(max(1, $freshMins))->format('Y-m-d H:i:s');
            $rows = DB::table('t_ops_attendance as a')
                ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->whereDate('a.attendance_date', $today)
                ->whereNull('a.logout_time')                 // still on duty — not yet checked out
                ->whereNotNull('a.checkout_attempt_at')
                ->where('a.checkout_attempt_at', '>=', $since)
                ->select('a.*', 'u.fullname')
                ->get();

            $alerts = [];
            foreach ($rows as $r) {
                $ca = \App\Services\Riders\DayChecksService::checkoutAttempt($r, $freshMins);
                if (!$ca || empty($ca['still_open']) || empty($ca['is_recent'])) { continue; }
                $alerts[] = [
                    'attendance_id' => (int) $r->id,
                    'user_id'       => (int) $r->user_id,
                    'rider_name'    => $r->fullname,
                    'headline'      => $ca['headline'],
                    'reason'        => $ca['reason'],
                    'at'            => $ca['at'],
                    'count'         => $ca['count'],
                    'maps_url'      => $ca['maps_url'],
                    'date'          => $today,
                ];
            }

            // Exclude the caller's dismissals — but a NEWER attempt after a dismissal re-raises it.
            if (!empty($alerts) && \Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal')) {
                $keys = array_map(fn ($a) => 'checkout_stuck:' . $a['attendance_id'], $alerts);
                $dism = DB::table('t_ops_alert_dismissal')->where('user_id', $user->id)
                    ->whereIn('alert_key', $keys)->pluck('dismissed_at', 'alert_key');
                $alerts = array_values(array_filter($alerts, function ($a) use ($dism) {
                    $k = 'checkout_stuck:' . $a['attendance_id'];
                    if (!isset($dism[$k])) { return true; }                       // never dismissed
                    return strtotime((string) $a['at']) > strtotime((string) $dism[$k]); // tried again since
                }));
            }

            $latestId = 0;
            foreach ($alerts as $a) { if ($a['attendance_id'] > $latestId) { $latestId = $a['attendance_id']; } }
            return response()->json(['success' => true, 'alerts' => $alerts, 'count' => count($alerts), 'latest_id' => $latestId]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'alerts' => [], 'count' => 0, 'latest_id' => 0]);
        }
    }

    /** A manager dismisses a "stuck at checkout" alert for a rider (per user, per attendance row). */
    public function dismissCheckoutStuckAlert(Request $request)
    {
        try {
            $validated = $request->validate(['attendance_id' => 'required|integer']);
            $user = auth()->user();
            if (!$user) { return response()->json(['success' => false], 401); }
            if (\Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal')) {
                DB::table('t_ops_alert_dismissal')->updateOrInsert(
                    ['user_id' => $user->id, 'alert_key' => 'checkout_stuck:' . $validated['attendance_id']],
                    ['dismissed_at' => now()]
                );
            }
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not dismiss.'], 500);
        }
    }

    /**
     * Grant or deduct EXTRA leave days for a rider (manager action, audited). Writes a signed
     * row to the leave ledger (t_hr_leave_grant). Requires the Phase-E SQL.
     *
     * kind = 'bonus'  → source 'overtime': adjusts the overtime-earned bonus pool, so the
     *                   "+ Bonus (overtime)" line nets out on every surface (a −1 here cancels
     *                   a payroll-granted bonus day). No new ENUM value needed.
     * kind = 'yearly' → source 'manual' (default, the original behaviour): a one-off change
     *                   on top of the yearly quota, shown as "Manual adjustment".
     */
    public function grantLeave(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'days' => 'required|numeric|not_in:0|min:-365|max:365',
            'reason' => 'nullable|string|max:200',
            'kind' => 'nullable|in:yearly,bonus',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('t_hr_leave_grant')) {
            return response()->json(['success' => false, 'message' => 'Leave-ledger table not set up yet — run the Phase-E SQL.'], 422);
        }
        try {
            $userId = (int) $request->user_id;
            $days = round((float) $request->days, 1);
            $kind = $request->input('kind') === 'bonus' ? 'bonus' : 'yearly';
            DB::table('t_hr_leave_grant')->insert([
                'user_id' => $userId,
                'days' => $days,
                'reason' => trim((string) $request->input('reason', '')) ?: null,
                'source' => $kind === 'bonus' ? 'overtime' : 'manual',
                'effective_date' => now()->toDateString(),
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $bal = (new \App\Services\HR\LeavePolicyService())->balance($userId);
            $verb = $days > 0 ? 'Granted' : 'Deducted';
            $what = $kind === 'bonus' ? 'bonus (overtime)' : 'yearly-allowance';
            return response()->json([
                'success' => true,
                'message' => "{$verb} " . abs($days) . " {$what} leave day(s). Balance is now {$bal['remaining']} of {$bal['effective_quota']}.",
                'balance' => $bal,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Grant-leave failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the grant.'], 500);
        }
    }

    /** Current leave balance for one rider (apply-leave modal + Riders page chips). */
    public function leaveBalance(Request $request)
    {
        $userId = (int) $request->input('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 400);
        }
        return response()->json(['success' => true, 'balance' => (new \App\Services\HR\LeavePolicyService())->balance($userId)]);
    }

    /**
     * Toggle a "not needed" day tag for a rider. A tagged day is PAID AS PRESENT — it is
     * never counted as absent and no salary is deducted. Requires the Phase-E SQL.
     */
    public function toggleDayTag(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'date' => 'required|date',
            'note' => 'nullable|string|max:200',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_day_tag')) {
            return response()->json(['success' => false, 'message' => '"Not needed" is not set up yet — run the Phase-E SQL.'], 422);
        }
        try {
            $userId = (int) $request->user_id;
            $date = substr((string) $request->date, 0, 10);
            $existing = DB::table('t_ops_day_tag')->where('user_id', $userId)->where('tag_date', $date)->first();
            if ($existing) {
                DB::table('t_ops_day_tag')->where('id', $existing->id)->delete();
                $tagged = false;
            } else {
                DB::table('t_ops_day_tag')->insert([
                    'user_id' => $userId,
                    'tag_date' => $date,
                    'tag' => 'not_needed',
                    'note' => trim((string) $request->input('note', '')) ?: null,
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                ]);
                $tagged = true;
            }
            // Bust the shift/tag cache so dayKind + salary see the change immediately.
            (new ShiftResolutionService())->clearUserShiftCache($userId);
            return response()->json([
                'success' => true,
                'tagged' => $tagged,
                'message' => $tagged ? 'Marked as not needed — this day won\'t count as absent.' : 'Removed the "not needed" mark.',
            ]);
        } catch (\Throwable $e) {
            \Log::error('toggleDayTag failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not update the day.'], 500);
        }
    }

    /**
     * Set / clear "not needed" for a DATE RANGE across one or more riders — the "🚫 Not required"
     * option in the shift assign sheet (web planner + mobile). Idempotent add (won't duplicate) or
     * remove. Off/holiday days inside the range are harmless (dayKind ignores the tag there); the
     * cell/report only shows "not needed" on working days. Bounded to 92 days.
     */
    public function setDayTagRange(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:t_sys_user,id',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'action' => 'nullable|in:add,remove',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_day_tag')) {
            return response()->json(['success' => false, 'message' => '"Not needed" is not set up yet — run the Phase-E SQL.'], 422);
        }
        try {
            $from = \Carbon\Carbon::parse($request->from)->startOfDay();
            $to = $request->filled('to') ? \Carbon\Carbon::parse($request->to)->startOfDay() : $from->copy();
            // NB: Carbon 3 diffInDays is SIGNED — use abs() so a past-ordered pair still bounds.
            if (abs($from->diffInDays($to)) > 92) {
                return response()->json(['success' => false, 'message' => 'Please pick a range of 92 days or less.'], 422);
            }
            $userIds = array_values(array_unique(array_map('intval', $request->user_ids)));
            $remove = $request->input('action', 'add') === 'remove';
            $dates = [];
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $dates[] = $d->format('Y-m-d');
            }
            $svc = new ShiftResolutionService();
            $affected = 0;
            DB::transaction(function () use ($userIds, $dates, $remove, $svc, &$affected) {
                foreach ($userIds as $uid) {
                    if ($remove) {
                        $affected += DB::table('t_ops_day_tag')->where('user_id', $uid)->whereIn('tag_date', $dates)->delete();
                    } else {
                        $existing = DB::table('t_ops_day_tag')->where('user_id', $uid)->whereIn('tag_date', $dates)->pluck('tag_date')
                            ->map(fn ($t) => substr((string) $t, 0, 10))->all();
                        $existingSet = array_flip($existing);
                        $rows = [];
                        foreach ($dates as $ds) {
                            if (!isset($existingSet[$ds])) {
                                $rows[] = ['user_id' => $uid, 'tag_date' => $ds, 'tag' => 'not_needed', 'note' => null, 'created_by' => auth()->id(), 'created_at' => now()];
                            }
                        }
                        if (!empty($rows)) { DB::table('t_ops_day_tag')->insert($rows); $affected += count($rows); }
                    }
                    $svc->clearUserShiftCache($uid);
                }
            });
            $dayCount = count($dates);
            return response()->json([
                'success' => true,
                'affected' => $affected,
                'message' => $remove
                    ? 'Cleared "not needed" on ' . $dayCount . ' day(s).'
                    : 'Marked not needed on ' . $dayCount . ' day(s) — paid, not counted absent.',
            ]);
        } catch (\Throwable $e) {
            \Log::error('setDayTagRange failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not update the days.'], 500);
        }
    }

    // Store new attendance record
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:t_sys_user,id',
                'attendance_date' => 'required|date',
                'login_time' => 'nullable|date_format:H:i',
                'logout_time' => 'nullable|date_format:H:i'
            ]);

            $loggedInUserId = auth()->id() ?? 1; // Track who made the change

            // R8 — a web user may NOT manually edit their OWN attendance from the web.
            // Own check-in/out must come from the mobile app (genuine GPS + location) so
            // nobody can hand-enter their own time. Applies to current AND past dates
            // (store() accepts any date). Another admin can still fix this person's row.
            $authUserId = auth()->id();
            if ($authUserId && (int) $validated['user_id'] === (int) $authUserId) {
                return response()->json([
                    'success' => false,
                    'self_edit_blocked' => true,
                    'message' => "You can't edit your own attendance from the web — check in or out from the app.",
                ], 403);
            }

            // Check if attendance already exists
            $existing = DB::table('t_ops_attendance')
                ->where('user_id', $validated['user_id'])
                ->where('attendance_date', $validated['attendance_date'])
                ->first();

            if ($existing) {
                // Update existing - only update fields that were provided
                $updateData = [];
                if ($request->filled('login_time')) {
                    $updateData['login_time'] = $validated['login_time'];
                }
                if ($request->filled('logout_time')) {
                    $updateData['logout_time'] = $validated['logout_time'];
                }
                
                // Add audit fields
                $updateData['updated_by'] = $loggedInUserId;
                $updateData['updated_at'] = now();
                
                DB::table('t_ops_attendance')
                    ->where('id', $existing->id)
                    ->update($updateData);
            } else {
                // Insert new
                DB::table('t_ops_attendance')->insert([
                    'user_id' => $validated['user_id'],
                    'attendance_date' => $validated['attendance_date'],
                    'login_time' => $validated['login_time'] ?? null,
                    'logout_time' => $validated['logout_time'] ?? null,
                    'created_at' => now(),
                    'created_by' => $loggedInUserId
                ]);
            }

            // Freeze the shift snapshot onto this row, resolved for the record's
            // own date. NON-FATAL — a snapshot failure must not fail the save.
            try {
                (new ShiftResolutionService())->stampAttendanceSnapshot(
                    (int) $validated['user_id'],
                    $validated['attendance_date']
                );
            } catch (\Exception $snapErr) {
                \Log::warning('Attendance shift snapshot failed on manual entry (non-fatal)', [
                    'user_id' => $validated['user_id'],
                    'date' => $validated['attendance_date'],
                    'error' => $snapErr->getMessage(),
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Attendance recorded']);
        } catch (\Exception $e) {
            \Log::error('Attendance store error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    // Monthly report for all employees
    public function monthlyReport(Request $request)
    {
        try {
        $month = $request->input('month', date('Y-m'));
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        // For current month, only count working days up to today (not the full month)
        $today = date('Y-m-d');
        $isCurrentMonth = (date('Y-m', strtotime($today)) === $month);
        $effectiveEndDate = $isCurrentMonth ? min($today, $endDate) : $endDate;
        
            Log::info('Monthly report requested', [
                'month' => $month,
                'startDate' => $startDate,
                'endDate' => $endDate
            ]);
            
            // Get all users with their attendance and leave data
            $data = DB::table('t_sys_user as u')
                ->leftJoin('t_ops_attendance as a', function($join) use ($startDate, $endDate) {
                    $join->on('u.id', '=', 'a.user_id')
                         ->whereBetween('a.attendance_date', [$startDate, $endDate]);
                })
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
                ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
                ->leftJoin('t_req_master as lr', function($join) use ($startDate, $endDate) {
                    $join->on('lr.requester_user_id', '=', 'u.id')
                         ->whereIn('lr.status', ['approved', 'pending'])
                         ->where(function($q) use ($startDate, $endDate) {
                             $q->whereBetween('lr.leave_start_date', [$startDate, $endDate])
                               ->orWhereBetween('lr.leave_end_date', [$startDate, $endDate])
                               ->orWhere(function($q2) use ($startDate, $endDate) {
                                   $q2->where('lr.leave_start_date', '<=', $startDate)
                                      ->where('lr.leave_end_date', '>=', $endDate);
                               });
                         });
                })
                ->leftJoin('t_req_category as lc', 'lc.id', '=', 'lr.category_id')
                ->where(function($query) {
                    $query->where('lc.category_code', '=', 'leave')
                          ->orWhereNull('lc.category_code');
                })
                ->where(function($query) {
                    $query->where('av.is_visible', '=', 1)
                          ->orWhereNull('av.is_visible');
                })
                // Only ACTIVE users (matches the Today view's default) — former/inactive
                // staff shouldn't clutter the monthly totals with 100%-absent rows.
                ->where('u.is_active', 1)
            ->select(
                    'u.id as user_id',
                'u.fullname',
                    'a.id as attendance_id',
                    'a.attendance_date',
                    'a.login_time',
                    'a.logout_time',
                    'a.picture_start',
                    'a.picture_end',
                    'a.meter_start',
                    'a.meter_end',
                    DB::raw('COALESCE(rp.shift_start, "09:00") as legacy_shift_start'),
                    DB::raw('COALESCE(rp.shift_end, "17:00") as legacy_shift_end'),
                    'lr.id as leave_request_id',
                    'lr.status as leave_status',
                    'lr.leave_type',
                    'lr.leave_start_date',
                    'lr.leave_end_date'
            )
            ->orderBy('u.fullname')
            ->orderBy('a.attendance_date')
            ->get();
            
            Log::info('Monthly report data fetched', ['record_count' => $data->count()]);
            
            // Initialize ShiftResolutionService
            $shiftService = new ShiftResolutionService();
            
        // Group by user and resolve shifts
        $byUser = [];
        foreach ($data as $record) {
            if (!isset($byUser[$record->user_id])) {
                try {
                    // Get user's shift info using today's date (current shift assignment)
                    // This ensures shifts apply retroactively to all past dates
                    $lookupDate = date('Y-m-d');
                    $shiftData = $shiftService->getUserShift($record->user_id, $lookupDate);
                    
                    Log::info('User shift resolved', [
                        'user_id' => $record->user_id,
                        'fullname' => $record->fullname,
                        'shift_data' => $shiftData
                    ]);
                    
                    // Calculate working days for this user in this month
                    // For current month, only count up to today
                    $workingDays = $shiftService->calculateWorkingDays($record->user_id, $startDate, $effectiveEndDate);
                    
                    Log::info('Working days calculated', [
                        'user_id' => $record->user_id,
                        'working_days' => $workingDays
                    ]);
                } catch (\Exception $e) {
                    Log::error('Error resolving shift for user', [
                        'user_id' => $record->user_id,
                        'error' => $e->getMessage()
                    ]);
                    // Fall back to legacy/default values
                    $shiftData = [
                        'shift_start' => $record->legacy_shift_start ?? '09:00',
                        'shift_end' => $record->legacy_shift_end ?? '17:00',
                        'shift_name' => 'Legacy Shift'
                    ];
                    $workingDays = 27;
                }
                
                $byUser[$record->user_id] = [
                    'user_id' => $record->user_id,
                    'fullname' => $record->fullname,
                    'working_days' => $workingDays,
                    'shift_name' => $shiftData['shift_name'],
                    'shift_start' => $shiftData['shift_start'],
                    'shift_end' => $shiftData['shift_end'],
                    'total_days' => 0,
                    'present_days' => 0,
                    'leave_days' => 0,
                    'absent_days' => 0,
                    'late_days' => 0,
                    'overtime_days' => 0,
                    'total_hours' => 0,
                    'total_late_minutes' => 0,
                    'total_overtime_minutes' => 0,
                    'leave_dates' => [],
                    'half_dates' => [], // half-day leave dates (0.5 each; shown as "½ day", not on-leave)
                    'daily' => [],
                    'processed_attendance_ids' => [] // Track processed attendance records to prevent duplicates
                ];
            }

            // Track leave dates for this user — half-days kept separate so they count 0.5 and
            // display as "½ day" (present) rather than a full on-leave day.
            if ($record->leave_request_id && $record->leave_start_date && $record->leave_end_date) {
                $isHalf = strtolower((string) ($record->leave_type ?? '')) === 'half_day';
                $leaveStart = new \DateTime($record->leave_start_date);
                $leaveEnd = new \DateTime($record->leave_end_date);
                $current = clone $leaveStart;

                while ($current <= $leaveEnd) {
                    $dateStr = $current->format('Y-m-d');
                    // Only count if within the month range
                    if ($dateStr >= $startDate && $dateStr <= $endDate) {
                        if ($isHalf) { $byUser[$record->user_id]['half_dates'][$dateStr] = true; }
                        else { $byUser[$record->user_id]['leave_dates'][$dateStr] = true; }
                    }
                    $current->modify('+1 day');
                }
            }
            
            // IMPORTANT: Only process each attendance record once to prevent duplicates from JOINs
            // The LEFT JOIN with t_req_master can create duplicate rows for the same attendance record
            $isNewAttendanceRecord = false;
            if ($record->attendance_id && !in_array($record->attendance_id, $byUser[$record->user_id]['processed_attendance_ids'])) {
                $byUser[$record->user_id]['processed_attendance_ids'][] = $record->attendance_id;
                $isNewAttendanceRecord = true;
            }
            
            if ($isNewAttendanceRecord) {
                $byUser[$record->user_id]['total_days']++;
                if ($record->login_time) {
                    $byUser[$record->user_id]['present_days']++;

                    // Late/overtime TOTALS are computed once per user below via
                    // ShiftResolutionService::sumLateOvertimeMinutes (per-date + frozen
                    // snapshot), so this report agrees with the salary calculation.
                    // Here we only tally hours worked.
                    if ($record->logout_time) {
                        $hours = (strtotime($record->logout_time) - strtotime($record->login_time)) / 3600;
                        // Overnight checkout: bare TIMEs make a past-midnight logout read
                        // as negative hours — roll to the next day (same rule as
                        // OvertimeService::dailyOvertimeMinutes).
                        if ($hours < 0) { $hours += 24; }
                        $byUser[$record->user_id]['total_hours'] += $hours;
                    }
                }
            }
            
            // Add as array for JSON serialization
            // IMPORTANT: Only add records that have actual attendance data
            // Skip NULL attendance_date records (these come from leave request JOINs)
            if ($record->attendance_date !== null) {
                // Determine status for all records
                $status = null; // Default: let frontend determine from login/logout times
                
                // Check if this date is on leave
                if (isset($byUser[$record->user_id]['leave_dates'][$record->attendance_date])) {
                    $status = 'on_leave';
                } elseif (isset($byUser[$record->user_id]['half_dates'][$record->attendance_date])) {
                    // Half-day: present (he checked in), just flagged ½ day — never a full leave.
                    $status = $record->login_time ? 'half_day' : 'on_leave';
                } elseif (!$record->login_time && !$record->logout_time) {
                    // No attendance and no leave = absent
                    $status = 'absent';
                }
                
                // Resolve the shift FOR THIS DATE so the per-day row shows the shift
                // that was actually in effect (not today's), matching the totals.
                // KEYED BY DATE (not appended): the leave-request JOIN yields one row per
                // (attendance × leave) pair, so a user with any leave in the month used to get
                // duplicate day rows. Last write wins — by then the leave/half maps for this
                // date are complete, so its status is the most informed one.
                $dayShift = $shiftService->getUserShift($record->user_id, $record->attendance_date);
                $byUser[$record->user_id]['daily'][$record->attendance_date] = [
                    'attendance_date' => $record->attendance_date,
                    'login_time' => $record->login_time,
                    'logout_time' => $record->logout_time,
                    'picture_start' => $record->picture_start,
                    'picture_end' => $record->picture_end,
                    'meter_start' => $record->meter_start,
                    'meter_end' => $record->meter_end,
                    'shift_start' => $dayShift['shift_start'],
                    'shift_end' => $dayShift['shift_end'],
                    'status' => $status // ✅ Always add status field
                ];
            }
        }
        
        // Calculate leave_days and absent_days for each user
        $cycle = $this->attendanceCycle(); // configured yearly window (once, for the loop)
        // Office-checkout exceptions for the whole month, batched ONCE for all users (R9.1) —
        // a non-bike rider who delivered but checked out at the office. Guarded / non-fatal.
        $officeCheckoutMap = [];
        try {
            $officeCheckoutMap = (new \App\Services\Riders\CheckoutClassifierService())
                ->officeCheckoutDays(array_keys($byUser), $startDate, $effectiveEndDate);
        } catch (\Throwable $e) { $officeCheckoutMap = []; }
        // U5/U4 — check-IN and check-OUT violation days for the month, batched once (guarded).
        // In = missed ride-in ETA / no home start / morning meter gap; Out = home-journey
        // issue days (reached home late / locked / manager-unlocked). Bike riders only.
        $inViolMap = [];
        $outViolMap = [];
        try {
            $inViolMap = (new \App\Services\Riders\WorkJourneyService())
                ->workIssueDays(array_keys($byUser), $startDate, $effectiveEndDate);
        } catch (\Throwable $e) { $inViolMap = []; }
        try {
            $outViolMap = (new \App\Services\Riders\HomeJourneyService())
                ->homeIssueDays(array_keys($byUser), $startDate, $effectiveEndDate);
        } catch (\Throwable $e) { $outViolMap = []; }
        // Missed-meter days — the SAME rule as the daily ⛽ tick (DayChecksService), which only
        // judges FINISHED working days, so absences / leave / today-still-running / future dates
        // can never be counted. Meter-carrying people only. Guarded / non-fatal.
        $meterMissMap = [];
        try {
            $meterMissMap = (new \App\Services\Riders\DayChecksService())
                ->meterMissDays(array_keys($byUser), $startDate, $effectiveEndDate);
        } catch (\Throwable $e) { $meterMissMap = []; }
        // Also add absent day records to the daily array for easier tracking
        foreach ($byUser as $userId => &$userData) {
            // Full leave dates count 1; half-days count 0.5 (matches the yearly counter).
            $userData['leave_days'] = count($userData['leave_dates']) + 0.5 * count($userData['half_dates']);
            $userData['half_days'] = count($userData['half_dates']);
            $userData['office_checkout_days'] = count($officeCheckoutMap[$userId] ?? []);
            // U5/U4 — company-bike violation columns (0 for everyone else). detail = date → issues,
            // rendered client-side in the month popup.
            $userData['checkin_violation_days'] = $inViolMap[$userId]['days'] ?? 0;
            $userData['checkin_violation_detail'] = $inViolMap[$userId]['detail'] ?? new \stdClass();
            $userData['checkout_violation_days'] = $outViolMap[$userId]['days'] ?? 0;
            $userData['checkout_violation_detail'] = $outViolMap[$userId]['detail'] ?? new \stdClass();
            // Missed meter readings this month (0 for anyone who doesn't carry a meter).
            $userData['meter_missed_days'] = $meterMissMap[$userId]['days'] ?? 0;
            $userData['meter_missed_detail'] = $meterMissMap[$userId]['detail'] ?? new \stdClass();
            // Absent (MO) uses the SAME canonical definition as Absent (YR), the month_absent
            // drill, and the rider app: a working-kind day with no login and no approved leave.
            // This excludes manager "not needed" tags (and off/holiday), so tagging a no-show
            // "not needed" drops it here too — and the column now always matches its own drill.
            $userData['absent_days'] = count($this->absentWorkingDates($userId, $shiftService, $startDate, $effectiveEndDate));

            // Per-date + snapshot-aware late/overtime totals (SAME helper as salary,
            // so this report and the salary slip always show identical numbers).
            $lateOt = $shiftService->sumLateOvertimeMinutes($userId, $startDate, $effectiveEndDate);
            $userData['total_late_minutes'] = $lateOt['late_minutes'];
            $userData['total_overtime_minutes'] = $lateOt['overtime_minutes'];
            $userData['late_days'] = $lateOt['late_days'];
            $userData['overtime_days'] = $lateOt['overtime_days'];

            // TARGET-based overtime (worked beyond the configured shift length) — this IS
            // "overtime" on every manager-facing screen (owner ruling Jul-28): it is the work
            // that earns BONUS LEAVE. The bonus figure comes from the same service payroll
            // grants from, so the column and the payslip can never disagree.
            $otSvcMonth = new \App\Services\HR\OvertimeService();
            $otRangeMonth = $otSvcMonth->overtimeForRange($userId, $startDate, $effectiveEndDate);
            $userData['overtime_target_minutes'] = (int) ($otRangeMonth['total'] ?? 0);
            $userData['overtime_bonus_leaves'] = $otSvcMonth
                ->bonusLeaves($userData['overtime_target_minutes']);
            $userData['overtime_minutes_per_bonus'] = $otSvcMonth->minutesPerBonusDay();
            // Stamp the per-DAY figure onto the day rows too. The Reports page used to derive
            // overtime client-side from logout vs shift_end — a third implementation that
            // could disagree with both the column above and payroll. Now every surface reads
            // this one number.
            foreach (($otRangeMonth['dates'] ?? []) as $otDate => $otMins) {
                if (isset($userData['daily'][$otDate])) {
                    $userData['daily'][$otDate]['overtime_minutes'] = (int) $otMins;
                }
            }
            foreach ($userData['daily'] as $dKey => $dRow) {
                if (!array_key_exists('overtime_minutes', $dRow)) {
                    $userData['daily'][$dKey]['overtime_minutes'] = 0;
                }
            }

            // Create a set of dates that have attendance records
            $attendanceDates = [];
            foreach ($userData['daily'] as $day) {
                $attendanceDates[$day['attendance_date']] = true;
            }
            
            // Add absent/leave day records for dates within the reporting period that have no attendance
            // IMPORTANT: Only add for WORKING DAYS (respects shift off days and public holidays)
            $currentDate = new \DateTime($startDate);
            $endDateObj = new \DateTime($effectiveEndDate);
            
            while ($currentDate <= $endDateObj) {
                $dateStr = $currentDate->format('Y-m-d');
                
                // Skip if attendance record exists
                if (!isset($attendanceDates[$dateStr])) {
                    // CRITICAL: Only add if this is a WORKING DAY for this user
                    // This respects shift schedule (e.g., Tuesday off) AND public holidays
                    if ($shiftService->isWorkingDay($userId, $dateStr)) {
                        // Shift in effect on THIS date (memoized) for the row display.
                        $fillerShift = $shiftService->getUserShift($userId, $dateStr);
                        // Check if on leave (a half-day date with no attendance shouldn't exist —
                        // apply blocks it — but if data drifted, show leave rather than a false Absent)
                        if (isset($userData['leave_dates'][$dateStr]) || isset($userData['half_dates'][$dateStr])) {
                            // This is a working day on leave = ON LEAVE
                            $userData['daily'][] = [
                                'attendance_date' => $dateStr,
                                'login_time' => null,
                                'logout_time' => null,
                                'shift_start' => $fillerShift['shift_start'],
                                'shift_end' => $fillerShift['shift_end'],
                                'status' => 'on_leave' // ✅ Mark as on leave
                            ];
                        } else {
                            // This is a working day with no attendance = ABSENT
                            $userData['daily'][] = [
                                'attendance_date' => $dateStr,
                                'login_time' => null,
                                'logout_time' => null,
                                'shift_start' => $fillerShift['shift_start'],
                                'shift_end' => $fillerShift['shift_end'],
                                'status' => 'absent' // Mark as absent for frontend rendering
                            ];
                        }
                    }
                    // else: it's a day off or holiday, don't show in the report
                }
                
                $currentDate->modify('+1 day');
            }
            
            // Sort daily records by date for proper chronological display
            usort($userData['daily'], function($a, $b) {
                return strcmp($a['attendance_date'], $b['attendance_date']);
            });
            
            unset($userData['leave_dates']);
            unset($userData['half_dates']);
            unset($userData['processed_attendance_ids']);

            // Approved leaves for current year. Use OVERLAP with the year window (not
            // fully-contained) so a leave spanning New Year — e.g. Dec 30 → Jan 2 — is
            // still counted; each leave's days are then clamped to the year before
            // counting (the old start>=Jan1 AND end<=Dec31 test dropped such leaves).
            // Yearly window comes from the CONFIGURED cycle (e.g. Jun→May), not a fixed
            // Jan–Dec calendar. Resolved once before the loop below.
            $currentYear = date('Y');
            $yearStart = $cycle['start'];
            $yearEnd = $cycle['end'];
            $yearLeaveDays = 0;
            $yearLeaves = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $userId)
                ->where('r.status', 'approved')
                ->where('r.leave_start_date', '<=', $yearEnd)
                ->where('r.leave_end_date', '>=', $yearStart)
                ->select('r.leave_start_date', 'r.leave_end_date')
                ->get();
            $yStart = \Carbon\Carbon::parse($yearStart);
            $yEnd = \Carbon\Carbon::parse($yearEnd);
            foreach ($yearLeaves as $yl) {
                $s = \Carbon\Carbon::parse($yl->leave_start_date);
                $e = \Carbon\Carbon::parse($yl->leave_end_date);
                if ($s->lt($yStart)) { $s = $yStart->copy(); }
                if ($e->gt($yEnd)) { $e = $yEnd->copy(); }
                if ($e->gte($s)) {
                    $yearLeaveDays += $s->diffInDays($e) + 1;
                }
            }
            $userData['leaves_taken_year'] = $yearLeaveDays;
            $userData['leaves_year'] = (int) substr($yearStart, 0, 4); // cycle start year (label/compat)
            $userData['cycle_start'] = $yearStart;
            $userData['cycle_end'] = $yearEnd;

            // Absent THIS YEAR (cycle start → today), using the SAME working-day-aware
            // definition as the Today view — shared helper so the number can't drift.
            $userData['absent_days_year'] = $this->yearAbsentDays($userId, $shiftService, $cycle, $today);

            // Leave BALANCE + segregation for the "Leave (yr)" cell — remaining leaves and where
            // the extra/missing ones came from (overtime earned vs late penalty vs manual). Same
            // LeavePolicyService every leave surface shares, so the numbers can't disagree.
            try {
                $bal = (new \App\Services\HR\LeavePolicyService())->balance($userId);
                $userData['leave_remaining']       = $bal['remaining'];
                $userData['leave_effective_quota'] = $bal['effective_quota'];
                $userData['leave_quota_total']     = $bal['quota_total'];
                $userData['leave_earned_overtime'] = $bal['earned_overtime'];
                $userData['leave_late_penalties']  = $bal['late_penalties'];
                $userData['leave_manual_adjust']   = $bal['manual_adjust'];
            } catch (\Throwable $e) {
                $userData['leave_remaining'] = null;
            }
        }
        
        Log::info('Monthly report processed', [
            'user_count' => count($byUser),
            'sample_user' => array_values($byUser)[0] ?? null
        ]);
        
        return response()->json(['success' => true, 'data' => array_values($byUser), 'month' => $month]);
        
        } catch (\Exception $e) {
            Log::error('Monthly report error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Get summary/reports
    public function summary(Request $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');

        if (!$start || !$end) {
            return response()->json(['success' => false, 'message' => 'Start and end dates required'], 400);
        }

        $records = DB::table('t_ops_attendance as a')
            ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
            ->whereBetween('a.attendance_date', [$start, $end])
            ->select(
                'a.*',
                'u.fullname',
                DB::raw('COALESCE(rp.shift_start, "09:00") as shift_start')
            )
            ->get();

        $onTime = 0;
        $late = 0;
        $absent = 0;
        $byUser = [];
        $shiftService = new ShiftResolutionService();

        // HALF-DAY dates in range (all users, one query): those days count on-time, never late.
        $halfDayByUserDate = [];
        try {
            $hdRows = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.leave_type', 'half_day')
                ->whereIn('r.status', ['approved', 'pending'])
                ->where('r.leave_start_date', '<=', $end)
                ->where('r.leave_end_date', '>=', $start)
                ->get(['r.requester_user_id', 'r.leave_start_date']);
            foreach ($hdRows as $h) {
                $halfDayByUserDate[$h->requester_user_id . '|' . substr((string) $h->leave_start_date, 0, 10)] = true;
            }
        } catch (\Throwable $e) { /* none */ }

        foreach ($records as $r) {
            $isHalf = isset($halfDayByUserDate[$r->user_id . '|' . substr((string) $r->attendance_date, 0, 10)]);
            // Effective shift start for THIS date: prefer the frozen check-in snapshot,
            // else resolve the shift that was in effect on that date (not today's, not
            // the legacy rider-profile column).
            $shiftStart = $r->expected_shift_start
                ?: (($shiftService->getUserShift($r->user_id, $r->attendance_date)['shift_start'] ?? '09:00') . ':00');

            if (!$r->login_time) {
                $absent++;
            } elseif (!$isHalf && $r->login_time > $shiftStart) {
                $late++;
            } else {
                $onTime++; // on time, or a half-day (no lateness counted)
            }

            // Per user stats
            if (!isset($byUser[$r->user_id])) {
                $byUser[$r->user_id] = [
                    'name' => $r->fullname,
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                    'total_hours' => 0,
                    'days' => 0
                ];
            }

            if (!$r->login_time) {
                $byUser[$r->user_id]['absent']++;
            } else {
                $byUser[$r->user_id]['present']++;
                if (!$isHalf && $r->login_time > $shiftStart) {
                    $byUser[$r->user_id]['late']++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'on_time' => $onTime,
                'late' => $late,
                'absent' => $absent,
                'by_user' => array_values($byUser)
            ]
        ]);
    }

    // Rider self view
    public function mine(Request $request)
    {
        return view('pages.attendance.mine');
    }

    public function mineData(Request $request)
    {
        $userId = $request->user()->id;
        $rows = DB::table('t_ops_attendance')->where('user_id', $userId)
            ->orderByDesc('attendance_date')->limit(500)->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    // Get employee details for last 30 days with order delivery stats
    public function employeeDetails(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            $fromDate = $request->input('from_date');
            
            if (!$userId || !$fromDate) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id and from_date are required'
                ], 400);
            }

            // Date range. The Month tab passes an explicit start_date/end_date (the SELECTED
            // month) — honour it so the detail matches the month the manager is looking at, not a
            // rolling window. Otherwise (Today tab) fall back to 30 days before from_date. Either
            // way the end is clamped to today so future working days aren't painted "Absent".
            $startParam = $request->input('start_date');
            $endParam = $request->input('end_date');
            if ($startParam && $endParam && strtotime((string) $startParam) && strtotime((string) $endParam)) {
                $startDate = substr((string) $startParam, 0, 10);
                $endDate = min(substr((string) $endParam, 0, 10), date('Y-m-d'));
            } else {
                $endDate = min($fromDate, date('Y-m-d'));
                $startDate = date('Y-m-d', strtotime($endDate . ' -30 days'));
            }

            // Half-day dates in range → present with no late/OT, shown as "½ day" (owner's rule).
            $halfDaySet = (new \App\Services\HR\LeavePolicyService())->halfDayDates($userId, $startDate, $endDate);

            // Get user info
            $user = DB::table('t_sys_user as u')
                ->select('u.id', 'u.fullname')
                ->where('u.id', $userId)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get shift info using ShiftResolutionService
            $shiftService = new ShiftResolutionService();
            $shiftInfo = $shiftService->getUserShift($userId, $fromDate);
            
            // Add shift times to user object for backward compatibility
            $user->shift_start = $shiftInfo['shift_start'];
            $user->shift_end = $shiftInfo['shift_end'];

            // Build per-day delivered orders via subquery to avoid cross-day aggregation
            // Filter by the selected rider and the requested date range, and only current assignments
            $deliveredPerDay = DB::table('t_ops_order_rider_history as orh')
                ->join('t_crm_order_status_history as osh', function($join) {
                    $join->on('osh.order_id', '=', 'orh.order_id')
                         ->where('osh.status_code', 'delivered')
                         ->where('osh.is_current', 1);
                })
                ->select(
                    'orh.rider_user_id as rider_id',
                    DB::raw('DATE(osh.changed_at) as delivered_date'),
                    DB::raw('COUNT(DISTINCT osh.order_id) as orders_delivered'),
                    DB::raw('MIN(TIME(osh.changed_at)) as first_delivery_time'),
                    DB::raw('MAX(TIME(osh.changed_at)) as last_delivery_time')
                )
                ->where('orh.rider_user_id', '=', $userId)
                ->where('orh.is_current', '=', 1)
                ->whereBetween(DB::raw('DATE(osh.changed_at)'), [$startDate, $endDate])
                ->groupBy('orh.rider_user_id', DB::raw('DATE(osh.changed_at)'));

            // Leave requests subquery
            $leaveSub = DB::table('t_req_master')
                ->select(
                    'requester_user_id',
                    'id as leave_request_id',
                    'status as leave_status',
                    'leave_type',
                    'leave_start_date',
                    'leave_end_date'
                )
                ->where('category_id', function($q) {
                    $q->select('id')
                      ->from('t_req_category')
                      ->where('category_code', 'leave')
                      ->limit(1);
                });

            // Attendance rows joined with per-day delivered orders and leave requests for this user
            $query = DB::table('t_ops_attendance as a')
                ->leftJoinSub($deliveredPerDay, 'd', function($join) {
                    $join->on('d.rider_id', '=', 'a.user_id')
                         ->on('d.delivered_date', '=', 'a.attendance_date');
                })
                ->leftJoinSub($leaveSub, 'lr', function($join) {
                    $join->on('lr.requester_user_id', '=', 'a.user_id')
                         ->whereColumn('a.attendance_date', '>=', 'lr.leave_start_date')
                         ->whereColumn('a.attendance_date', '<=', 'lr.leave_end_date');
                })
                ->where('a.user_id', '=', $userId)
                ->whereBetween('a.attendance_date', [$startDate, $endDate])
                ->select(
                    'a.attendance_date',
                    'a.login_time',
                    'a.logout_time',
                    'a.picture_start',
                    'a.picture_end',
                    'a.meter_start',
                    'a.meter_end',
                    // Frozen snapshot (preferred over recomputation)
                    'a.late_minutes as snap_late_minutes',
                    'a.overtime_minutes as snap_overtime_minutes',
                    'lr.leave_request_id',
                    'lr.leave_status',
                    'lr.leave_type',
                    DB::raw('COALESCE(d.orders_delivered, 0) as total_orders_delivered'),
                    DB::raw("COALESCE(d.first_delivery_time, '-') as first_delivery_time"),
                    DB::raw("COALESCE(d.last_delivery_time, '-') as last_delivery_time")
                )
                ->orderByDesc('a.attendance_date');
            
            // Log the SQL query for debugging
            $sql = $query->toSql();
            $bindings = $query->getBindings();
            
            // Replace ? with actual values for easier debugging
            $fullSql = $sql;
            foreach ($bindings as $binding) {
                $value = is_numeric($binding) ? $binding : "'{$binding}'";
                $fullSql = preg_replace('/\?/', $value, $fullSql, 1);
            }
            
            Log::info('Employee details query', [
                'user_id' => $userId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'sql' => $sql,
                'bindings' => $bindings,
                'full_sql' => $fullSql
            ]);
            
            $records = $query->get();
            
            // Clean up any null values in the records immediately after fetch
            foreach ($records as $record) {
                if ($record->first_delivery_time === null) {
                    $record->first_delivery_time = '-';
                }
                if ($record->last_delivery_time === null) {
                    $record->last_delivery_time = '-';
                }
            }
            
            // Log results for debugging
            Log::info('Employee details results', [
                'user_id' => $userId,
                'record_count' => $records->count(),
                'first_3_records' => $records->take(3)->toArray(),
                'total_orders_sum' => $records->sum('total_orders_delivered')
            ]);

            // Calculate working days using ShiftResolutionService
            // This considers user's shift schedule AND public holidays
            $shiftService = new ShiftResolutionService();
            $workingDays = $shiftService->calculateWorkingDays($userId, $startDate, $endDate);

            // Calculate statistics
            $totalDays = $records->count();
            $presentDays = $records->where('login_time', '!=', null)->count();
            
            // Count leave days — a half-day is NOT a full on-leave day (he was present); it's
            // reflected as 0.5 in the yearly leave balance, not in this present/absent day split.
            $onLeaveDays = 0;
            foreach ($records as $record) {
                $rd = substr((string) $record->attendance_date, 0, 10);
                if (isset($halfDaySet[$rd])) { continue; }
                if ($record->leave_request_id &&
                    in_array(strtolower($record->leave_status), ['approved', 'pending'])) {
                    $onLeaveDays++;
                }
            }
            
            // Calculate absent days = working days - present - on leave
            $absentDays = $workingDays - $presentDays - $onLeaveDays;
            if ($absentDays < 0) $absentDays = 0; // Safety check
            
            $lateDays = 0;
            $overtimeDays = 0;
            $totalHours = 0;
            $totalOrdersDelivered = 0;

            // ⭐ OVERTIME = TARGET-BASED, everywhere (owner ruling Jul-28). Overtime is the
            // work beyond SHIFT_TARGET_HOURS that EARNS BONUS LEAVE — the same figure payroll
            // grants from. The old shift-END overtime is not shown to managers any more: it
            // read ~0 for nearly everyone (the resolved shift returns a NULL shift_end), so a
            // dead zero sat beside the Month tab's real number and looked like a contradiction.
            // Computed once for the range; the per-day map below feeds the day rows and the
            // "Overtime" tile filter, so tile, list and Month tab cannot disagree.
            $otSvc      = new \App\Services\HR\OvertimeService();
            $otRange    = $otSvc->overtimeForRange($userId, $startDate, $endDate);
            $otByDate   = $otRange['dates'] ?? [];
            $otMinutes  = (int) ($otRange['total'] ?? 0);
            $otBonusDays = $otSvc->bonusLeaves($otMinutes);

            foreach ($records as $record) {
                $recDate = substr((string) $record->attendance_date, 0, 10);
                $record->is_half_day = isset($halfDaySet[$recDate]);
                // Calculate hours worked
                if ($record->login_time && $record->logout_time) {
                    $login = strtotime($record->login_time);
                    $logout = strtotime($record->logout_time);
                    $hours = ($logout - $login) / 3600;
                    $totalHours += $hours;
                    $record->hours_worked = round($hours, 1);
                } else {
                    $record->hours_worked = 0;
                }

                // Half-day: no lateness, no overtime (owner's rule). Skip the whole computation.
                if ($record->is_half_day) {
                    $record->late_minutes = 0;
                    $record->overtime_minutes = 0;
                    $totalOrdersDelivered += $record->total_orders_delivered;
                    if ($record->meter_start && $record->meter_end) {
                        $record->meter_distance = abs(intval($record->meter_end) - intval($record->meter_start));
                    } else {
                        $record->meter_distance = null;
                    }
                    $record->first_delivery_time = ($record->first_delivery_time && $record->first_delivery_time !== '-')
                        ? @date('H:i', strtotime($record->first_delivery_time)) : '-';
                    $record->last_delivery_time = ($record->last_delivery_time && $record->last_delivery_time !== '-')
                        ? @date('H:i', strtotime($record->last_delivery_time)) : '-';
                    continue;
                }

                // Per-day late/overtime — prefer the FROZEN snapshot; else resolve the
                // shift in effect ON THIS DATE and compute (truncate seconds). This keeps
                // per-day rows consistent with the snapshot-preferring monthly totals + salary.
                if (!$record->login_time) {
                    $record->late_minutes = 0;
                } elseif (!is_null($record->snap_late_minutes)) {
                    $record->late_minutes = (int) $record->snap_late_minutes;
                    if ($record->late_minutes > 0) { $lateDays++; }
                } else {
                    $dayStart = $shiftService->getUserShift($userId, $record->attendance_date)['shift_start'] ?? null;
                    $shiftStart = $dayStart ? strtotime($record->attendance_date . ' ' . $dayStart) : null;
                    $actualLogin = strtotime($record->attendance_date . ' ' . $record->login_time);
                    if ($shiftStart && $actualLogin > $shiftStart) {
                        $lateDays++;
                        $record->late_minutes = (int) (($actualLogin - $shiftStart) / 60);
                    } else {
                        $record->late_minutes = 0;
                    }
                }

                // Target-based overtime for THIS day, straight from the map built above. The
                // service already drops half-days and days without a checkout, so a missing
                // key simply means "no overtime earned".
                $recDateOt = substr((string) $record->attendance_date, 0, 10);
                $record->overtime_minutes = (int) ($otByDate[$recDateOt] ?? 0);
                if ($record->overtime_minutes > 0) { $overtimeDays++; }

                // Add order count to total
                $totalOrdersDelivered += $record->total_orders_delivered;
                
                // Calculate meter distance
                if ($record->meter_start && $record->meter_end) {
                    $record->meter_distance = abs(intval($record->meter_end) - intval($record->meter_start));
                } else {
                    $record->meter_distance = null;
                }

                // Format delivery times for display (handle null values)
                if ($record->first_delivery_time && $record->first_delivery_time !== '-') {
                    try {
                        $record->first_delivery_time = date('H:i', strtotime($record->first_delivery_time));
                    } catch (\Exception $e) {
                        $record->first_delivery_time = '-';
                    }
                } else {
                    $record->first_delivery_time = '-';
                }
                
                if ($record->last_delivery_time && $record->last_delivery_time !== '-') {
                    try {
                        $record->last_delivery_time = date('H:i', strtotime($record->last_delivery_time));
                    } catch (\Exception $e) {
                        $record->last_delivery_time = '-';
                    }
                } else {
                    $record->last_delivery_time = '-';
                }
            }

            // ── Fill in days that have NO attendance row so the detail list shows the
            //    full picture — an ABSENT day must not silently disappear (that was the
            //    bug: Jul 1 had no row so it wasn't shown). Off days, public holidays and
            //    pre-hire days are skipped (not attendance events). Every row also gets a
            //    `status` so the frontend colours it consistently.
            $byDate = [];
            foreach ($records as $r) {
                $r->attendance_date = substr((string) $r->attendance_date, 0, 10);
                $isHalf = isset($halfDaySet[$r->attendance_date]);
                $r->is_half_day = $isHalf;
                $onLeave = $r->leave_request_id && in_array(strtolower((string) $r->leave_status), ['approved', 'pending']);
                if ($isHalf && $r->login_time)                 $r->status = 'half_day';
                elseif ($onLeave)                              $r->status = 'on_leave';
                elseif ($r->login_time && $r->late_minutes > 0) $r->status = 'late';
                elseif ($r->login_time)                        $r->status = 'present';
                else                                           $r->status = 'absent';
                $byDate[$r->attendance_date] = $r;
            }
            // Leave dates covering this range (approved/pending).
            $leaveDates = [];
            foreach (DB::table('t_req_master as lr')
                        ->join('t_req_category as lc', 'lc.id', '=', 'lr.category_id')
                        ->where('lc.category_code', 'leave')
                        ->where('lr.requester_user_id', $userId)
                        ->whereIn('lr.status', ['approved', 'pending'])
                        ->where('lr.leave_start_date', '<=', $endDate)
                        ->where('lr.leave_end_date', '>=', $startDate)
                        ->get(['lr.leave_start_date', 'lr.leave_end_date']) as $lv) {
                $ls = max($startDate, substr((string) $lv->leave_start_date, 0, 10));
                $le = min($endDate, substr((string) $lv->leave_end_date, 0, 10));
                for ($c = new \DateTime($ls); $c <= new \DateTime($le); $c->modify('+1 day')) {
                    $leaveDates[$c->format('Y-m-d')] = true;
                }
            }
            // Rebuild the list newest-first, inserting absent/leave fillers for gaps.
            $full = [];
            for ($cur = new \DateTime($endDate), $startObj = new \DateTime($startDate); $cur >= $startObj; $cur->modify('-1 day')) {
                $ds = $cur->format('Y-m-d');
                if (isset($byDate[$ds])) { $full[] = $byDate[$ds]; continue; }
                $kind = $shiftService->dayKind($userId, $ds);
                if ($kind === 'off' || $kind === 'holiday' || $kind === 'not_joined') { continue; }
                // A tagged "not needed" day shows as its own status (paid, not absent).
                $fillerStatus = isset($leaveDates[$ds]) ? 'on_leave'
                              : ($kind === 'not_needed' ? 'not_needed' : 'absent');
                $full[] = (object) [
                    'attendance_date' => $ds,
                    'login_time' => null, 'logout_time' => null,
                    'hours_worked' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0,
                    'total_orders_delivered' => 0, 'first_delivery_time' => '-', 'last_delivery_time' => '-',
                    'meter_start' => null, 'meter_end' => null, 'meter_distance' => null,
                    'picture_start' => null, 'picture_end' => null,
                    'leave_request_id' => null, 'leave_status' => null,
                    'status' => $fillerStatus,
                ];
            }
            $records = collect($full);
            // Recompute absent from the actual per-day rows so the header matches what's
            // shown and excludes "not needed" (paid) + leave days. Mirrors salary's absent.
            $absentDays = $records->where('status', 'absent')->count();

            // ⭐ …and recompute ON LEAVE from those same rows, for exactly the same reason.
            // The earlier count (above) walks only rows that EXIST in t_ops_attendance, but
            // approving a leave request does not reliably write an attendance row — 7 of 9
            // approved July-2026 leaves had none. Those days are restored as `on_leave`
            // filler rows just above, so the table already showed them; only this header
            // tile still said otherwise, contradicting the list underneath it. Counting the
            // rebuilt rows makes the tile agree with its own table AND with the Month tab /
            // mobile, which both read the leave REQUESTS (the real source of truth).
            // Half-days stay excluded, as before: they are 0.5 in the yearly balance and
            // display as "½ day" (present), never as a full on-leave day.
            $onLeaveDays = $records->filter(function ($r) use ($halfDaySet) {
                $d = substr((string) ($r->attendance_date ?? ''), 0, 10);
                return ($r->status ?? '') === 'on_leave' && !isset($halfDaySet[$d]);
            })->count();

            return response()->json([
                'success' => true,
                'employee' => [
                    'user_id' => $user->id,
                    'fullname' => $user->fullname,
                    'shift_start' => $user->shift_start,
                    'shift_end' => $user->shift_end,
                    'working_days' => $workingDays,
                    'present_days' => $presentDays,
                    'on_leave_days' => $onLeaveDays,
                    'absent_days' => $absentDays,
                    'late_days' => $lateDays,
                    'overtime_days' => $overtimeDays,
                    // Same three the Month tab and both mobile screens show, from the same
                    // service — so "12h 10m → 1 bonus day" reads identically everywhere.
                    'overtime_minutes' => $otMinutes,
                    'overtime_bonus_leaves' => $otBonusDays,
                    'overtime_minutes_per_bonus' => $otSvc->minutesPerBonusDay(),
                    'total_hours' => round($totalHours, 1),
                    'total_orders_delivered' => $totalOrdersDelivered,
                    'shift_info' => [
                        'shift_name' => $shiftInfo['shift_name'],
                        'shift_source' => $shiftInfo['source'],
                        'working_days_per_week' => count($shiftInfo['working_days'])
                    ],
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate
                    ]
                ],
                'daily_records' => $records
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in employeeDetails: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error loading employee details: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ GPS Readings Audit - Identify gaps in GPS tracking
     * Helps audit riders who may have gaps in their tracking
     */
    public function gpsReadingsAudit(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            $date = $request->input('date', now()->toDateString());
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id is required'
                ], 400);
            }
            
            // Get user info
            $user = DB::table('t_sys_user')
                ->select('id', 'fullname')
                ->where('id', $userId)
                ->first();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            // Get attendance for the date
            $attendance = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', $date)
                ->first();
            
            if (!$attendance || !$attendance->login_time) {
                return response()->json([
                    'success' => true,
                    'user' => $user,
                    'date' => $date,
                    'has_attendance' => false,
                    'message' => 'No attendance record for this date'
                ]);
            }
            
            // Get all GPS readings for the date (ordered by time)
            $readings = DB::table('t_ops_rider_location')
                ->where('user_id', $userId)
                ->whereDate('captured_at', $date)
                ->orderBy('captured_at')
                ->select('id', 'latitude', 'longitude', 'accuracy', 'captured_at')
                ->get();
            
            // Calculate expected readings based on 5-min intervals
            $loginTime = strtotime($date . ' ' . $attendance->login_time);
            $logoutTime = $attendance->logout_time ? strtotime($date . ' ' . $attendance->logout_time) : time();
            
            // If logout is not yet, cap at current time
            if ($logoutTime > time()) {
                $logoutTime = time();
            }
            
            $workingMinutes = ($logoutTime - $loginTime) / 60;
            $expectedReadings = max(1, floor($workingMinutes / 5)); // One reading every 5 min
            $actualReadings = $readings->count();
            
            // Analyze gaps - find periods > 10 minutes between readings
            $gaps = [];
            $totalGapMinutes = 0;
            $minGapThreshold = 10; // Minutes - gaps larger than this are flagged
            
            // Helper function to check if two points are at same location (within 50m)
            $isSameLocation = function($lat1, $lng1, $lat2, $lng2) {
                $earthRadius = 6371000;
                $lat1Rad = deg2rad($lat1);
                $lat2Rad = deg2rad($lat2);
                $deltaLat = deg2rad($lat2 - $lat1);
                $deltaLng = deg2rad($lng2 - $lng1);
                $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
                     cos($lat1Rad) * cos($lat2Rad) *
                     sin($deltaLng / 2) * sin($deltaLng / 2);
                $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                return ($earthRadius * $c) < 50; // Within 50 meters = same location
            };
            
            $stationaryGapMinutes = 0; // Gaps where rider didn't move (harmless)
            
            if ($actualReadings > 0) {
                // Check gap from login to first reading
                $firstReading = $readings->first();
                $firstReadingTime = strtotime($firstReading->captured_at);
                $gapFromLogin = ($firstReadingTime - $loginTime) / 60;
                
                if ($gapFromLogin > $minGapThreshold) {
                    $gaps[] = [
                        'from' => date('H:i:s', $loginTime),
                        'to' => date('H:i:s', $firstReadingTime),
                        'duration_minutes' => round($gapFromLogin),
                        'type' => 'start_delay',
                        'description' => 'Gap between login and first GPS reading',
                        'is_stationary' => false // Unknown - no prior reading to compare
                    ];
                    $totalGapMinutes += $gapFromLogin;
                }
                
                // Check gaps between consecutive readings
                $readingsArray = $readings->values()->all();
                for ($i = 1; $i < count($readingsArray); $i++) {
                    $prev = $readingsArray[$i - 1];
                    $curr = $readingsArray[$i];
                    $prevTime = strtotime($prev->captured_at);
                    $currTime = strtotime($curr->captured_at);
                    $gapMinutes = ($currTime - $prevTime) / 60;
                    
                    if ($gapMinutes > $minGapThreshold) {
                        // ⭐ Smart check: if rider didn't move during gap, it's harmless
                        $isStationary = $isSameLocation(
                            (float) $prev->latitude, (float) $prev->longitude,
                            (float) $curr->latitude, (float) $curr->longitude
                        );
                        
                        $gaps[] = [
                            'from' => date('H:i:s', $prevTime),
                            'to' => date('H:i:s', $currTime),
                            'duration_minutes' => round($gapMinutes),
                            'type' => $isStationary ? 'stationary_gap' : 'tracking_gap',
                            'description' => $isStationary 
                                ? 'GPS gap but rider stayed in same location (no loss)' 
                                : 'GPS tracking gap',
                            'is_stationary' => $isStationary
                        ];
                        
                        $totalGapMinutes += $gapMinutes;
                        if ($isStationary) {
                            $stationaryGapMinutes += $gapMinutes;
                        }
                    }
                }
                
                // Check gap from last reading to logout
                if ($attendance->logout_time) {
                    $lastReading = $readings->last();
                    $lastReadingTime = strtotime($lastReading->captured_at);
                    $gapToLogout = ($logoutTime - $lastReadingTime) / 60;
                    
                    if ($gapToLogout > $minGapThreshold) {
                        $gaps[] = [
                            'from' => date('H:i:s', $lastReadingTime),
                            'to' => date('H:i:s', $logoutTime),
                            'duration_minutes' => round($gapToLogout),
                            'type' => 'end_gap',
                            'description' => 'Gap between last GPS reading and logout',
                            'is_stationary' => false // Unknown - no next reading to compare
                        ];
                        $totalGapMinutes += $gapToLogout;
                    }
                }
            } else {
                // No readings at all
                $gaps[] = [
                    'from' => $attendance->login_time,
                    'to' => $attendance->logout_time ?? 'ongoing',
                    'duration_minutes' => round($workingMinutes),
                    'type' => 'no_tracking',
                    'description' => 'No GPS readings captured',
                    'is_stationary' => false
                ];
                $totalGapMinutes = $workingMinutes;
            }
            
            // ⭐ Effective gap = only gaps where we might have lost distance data
            $effectiveGapMinutes = $totalGapMinutes - $stationaryGapMinutes;
            
            // Calculate coverage percentage (based on readings, not gaps)
            $coveragePercent = $workingMinutes > 0 
                ? round((($workingMinutes - $totalGapMinutes) / $workingMinutes) * 100) 
                : 0;
            
            // ⭐ Effective coverage = coverage when excluding harmless stationary gaps
            $effectiveCoveragePercent = $workingMinutes > 0 
                ? round((($workingMinutes - $effectiveGapMinutes) / $workingMinutes) * 100) 
                : 0;
            
            // Calculate GPS straight-line distance
            $gpsDistance = null;
            if ($actualReadings >= 2) {
                $gpsResult = $this->calculateGpsDistanceFromReadings($readings->toArray());
                $gpsDistance = $gpsResult['distance'];
            }
            
            // ⭐ Calculate ROAD distance using OpenRouteService API (same as mobile)
            // BUT only if straight-line distance is meaningful (> 0.5 km)
            // This prevents API calls for stationary GPS that just has drift
            $roadDistance = null;
            $roadDistanceSource = null;
            if ($actualReadings >= 2 && $gpsDistance !== null && $gpsDistance >= 0.5) {
                // Sample readings to max 25 points with GPS drift filtering
                $sampledReadings = $this->sampleGpsReadings($readings->toArray(), 25);
                if (count($sampledReadings) >= 2) {
                    $roadDistance = $this->callOpenRouteService($sampledReadings);
                    $roadDistanceSource = $roadDistance !== null ? 'openrouteservice' : 'unavailable';
                }
            } elseif ($gpsDistance !== null && $gpsDistance < 0.5) {
                $roadDistanceSource = 'skipped_stationary';
            }
            
            // Meter distance from attendance (with raw values for transparency)
            $meterDistance = null;
            $meterStart = $attendance->meter_start;
            $meterEnd = $attendance->meter_end;
            if ($meterStart && $meterEnd) {
                $meterDistance = abs((int) $meterEnd - (int) $meterStart);
            }
            
            // ⭐ Get previous day's meter end for gap detection
            $prevMeter = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', '<', $date)
                ->whereNotNull('meter_end')
                ->orderBy('attendance_date', 'desc')
                ->select('meter_end', 'attendance_date')
                ->first();
            
            $prevMeterEnd = $prevMeter->meter_end ?? null;
            $prevMeterDate = $prevMeter->attendance_date ?? null;
            $meterGap = null;
            if ($prevMeterEnd && $meterStart) {
                $meterGap = (int)$meterStart - (int)$prevMeterEnd;
            }
            
            // Audit status
            $auditStatus = 'good';
            $auditNotes = [];
            
            if ($coveragePercent < 50) {
                $auditStatus = 'critical';
                $auditNotes[] = 'Less than 50% GPS coverage';
            } elseif ($coveragePercent < 80) {
                $auditStatus = 'warning';
                $auditNotes[] = 'GPS coverage below 80%';
            }
            
            if (count($gaps) > 3) {
                if ($auditStatus === 'good') $auditStatus = 'warning';
                $auditNotes[] = 'Multiple tracking gaps detected';
            }
            
            // Compare meter vs road/GPS distance if available
            $comparisonDistance = $roadDistance ?? $gpsDistance;
            if ($meterDistance && $comparisonDistance && $comparisonDistance > 0) {
                $discrepancyPercent = abs($meterDistance - $comparisonDistance) / $comparisonDistance * 100;
                if ($discrepancyPercent > 50) {
                    if ($auditStatus === 'good') $auditStatus = 'warning';
                    $auditNotes[] = 'Significant difference between meter and GPS distance';
                }
            }
            
            // ── GPS v2: PHASE coverage — the SHARED analyzer (same DayChecksService the row 📡
            //    tick uses, so modal and tick can never disagree).
            $phases = (new \App\Services\Riders\DayChecksService())->computeGpsPhases($readings->values()->all(), $attendance, $date)['phases'];

            // ── Meter story (feeds the separate Meter-details modal). Raw picture paths — the
            //    page's viewMeterPicturePath() opens them (no server-side URL building needed).
            //    is_company_bike drives the modal's shape: bike riders get the full home story
            //    (last-night / overnight / at-home); everyone else gets just start + end.
            // ⭐ Phase C: the machine he had ON THIS DAY decides, not today's
            //    checkbox — this modal is often opened on a past date, and the
            //    meter story's shape must match the bike he actually rode then.
            $isCompanyBike = (new \App\Services\Riders\FuelClaimRules())
                ->ridesCompanyBike($userId, substr((string) $date, 0, 10));
            $meterStory = [
                'prev'  => ['value' => $prevMeterEnd !== null ? (int) $prevMeterEnd : null, 'date' => $prevMeterDate ? substr((string) $prevMeterDate, 0, 10) : null],
                'start' => [
                    'value'  => $meterStart !== null ? (int) $meterStart : null,
                    'time'   => !empty($attendance->meter_start_recorded_at) ? substr((string) $attendance->meter_start_recorded_at, 11, 5) : null,
                    'source' => $attendance->meter_start_source ?? null, // home | checkin | manager
                    // Measured location behind that source (null on pre-hardening rows) — so the
                    // modal can show "820 m from home" instead of assuming "typed at office".
                    'place'  => \App\Services\Riders\WorkJourneyService::startPlace($attendance),
                    'photo'  => $attendance->picture_start ?? null,
                ],
                'end'   => [
                    'value'  => $meterEnd !== null ? (int) $meterEnd : null,
                    'time'   => !empty($attendance->home_meter_recorded_at) ? substr((string) $attendance->home_meter_recorded_at, 11, 5) : ($attendance->logout_time ? substr((string) $attendance->logout_time, 0, 5) : null),
                    'source' => ($attendance->meter_home ?? null) !== null ? 'home' : null,
                    'photo'  => $attendance->picture_home ?? $attendance->picture_end ?? null,
                ],
                'gap_km'       => $meterGap,
                'day_meter_km' => $meterDistance,
                'day_road_km'  => $roadDistance !== null ? round($roadDistance, 1) : ($gpsDistance !== null ? round($gpsDistance, 1) : null),
                'day_road_is_gps' => $roadDistance === null && $gpsDistance !== null,
                'is_company_bike' => $isCompanyBike,
            ];

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'fullname' => $user->fullname
                ],
                'date' => $date,
                'has_attendance' => true,
                'phases' => $phases,          // GPS v2 (ride-focused)
                'meter_story' => $meterStory, // Meter-details modal
                'attendance' => [
                    'login_time' => $attendance->login_time,
                    'logout_time' => $attendance->logout_time,
                    'working_minutes' => round($workingMinutes)
                ],
                'gps_analysis' => [
                    'expected_readings' => $expectedReadings,
                    'actual_readings' => $actualReadings,
                    'coverage_percent' => $coveragePercent,
                    'effective_coverage_percent' => $effectiveCoveragePercent,
                    'total_gap_minutes' => round($totalGapMinutes),
                    'stationary_gap_minutes' => round($stationaryGapMinutes),
                    'effective_gap_minutes' => round($effectiveGapMinutes),
                    'gaps_count' => count($gaps),
                    'stationary_gaps_count' => count(array_filter($gaps, fn($g) => $g['is_stationary'] ?? false)),
                    'gaps' => $gaps
                ],
                'distance' => [
                    'meter_km' => $meterDistance,
                    'meter_start' => $meterStart,
                    'meter_end' => $meterEnd,
                    'prev_meter_end' => $prevMeterEnd,
                    'prev_meter_date' => $prevMeterDate,
                    'meter_gap' => $meterGap,
                    'gps_straight_km' => $gpsDistance,
                    'gps_road_km' => $roadDistance !== null ? round($roadDistance, 1) : null,
                    'road_source' => $roadDistanceSource
                ],
                'audit' => [
                    'status' => $auditStatus,
                    'notes' => $auditNotes
                ],
                // Include readings for timeline visualization (limit to avoid huge response)
                'readings_preview' => $readings->take(100)->map(function($r) {
                    return [
                        'time' => date('H:i', strtotime($r->captured_at)),
                        'accuracy' => $r->accuracy
                    ];
                })
            ]);
            
        } catch (\Exception $e) {
            Log::error('GPS Audit Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error performing GPS audit: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Calculate GPS distance from array of readings using Haversine
     * Filters out GPS drift (< 20m movements)
     */
    private function calculateGpsDistanceFromReadings(array $readings): array
    {
        $totalDistance = 0;
        $validReadingsCount = count($readings);
        
        if ($validReadingsCount < 2) {
            return ['distance' => null, 'readings_count' => $validReadingsCount];
        }
        
        $minMovementMeters = 20; // Filter out GPS drift
        
        for ($i = 1; $i < $validReadingsCount; $i++) {
            $prev = is_array($readings[$i - 1]) ? (object) $readings[$i - 1] : $readings[$i - 1];
            $curr = is_array($readings[$i]) ? (object) $readings[$i] : $readings[$i];
            
            $distanceMeters = $this->haversineDistance(
                (float) $prev->latitude,
                (float) $prev->longitude,
                (float) $curr->latitude,
                (float) $curr->longitude
            );
            
            if ($distanceMeters >= $minMovementMeters) {
                $totalDistance += $distanceMeters;
            }
        }
        
        $distanceKm = round($totalDistance / 1000, 1);
        
        return [
            'distance' => $distanceKm > 0 ? $distanceKm : null,
            'readings_count' => $validReadingsCount,
        ];
    }
    
    /**
     * ⭐ Haversine formula to calculate distance between two lat/lng points
     * Returns distance in meters
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000;
        
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);
        
        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLng / 2) * sin($deltaLng / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadiusMeters * $c;
    }
    
    /**
     * ⭐ Sample GPS readings for road distance API call
     * 
     * Strategy:
     * 1. Filter out GPS DRIFT - consecutive readings within ~30m are noise
     * 2. Then sample evenly from filtered points to get max 50 waypoints
     * 
     * This removes fake distance from GPS drift while preserving actual route
     */
    private function sampleGpsReadings(array $readings, int $maxPoints = 25): array
    {
        $count = count($readings);
        
        if ($count <= 2) {
            return $readings;
        }
        
        // ⭐ Step 1: Filter GPS drift - consecutive points within ~30m
        $filtered = [];
        $prevLat = null;
        $prevLng = null;
        $driftThreshold = 0.0003; // ~30m
        
        foreach ($readings as $reading) {
            $lat = (float)(is_object($reading) ? $reading->latitude : ($reading['latitude'] ?? 0));
            $lng = (float)(is_object($reading) ? $reading->longitude : ($reading['longitude'] ?? 0));
            
            if ($prevLat === null || 
                abs($lat - $prevLat) > $driftThreshold || 
                abs($lng - $prevLng) > $driftThreshold) {
                $filtered[] = $reading;
                $prevLat = $lat;
                $prevLng = $lng;
            }
        }
        
        $filteredCount = count($filtered);
        
        if ($filteredCount < 2) {
            $filtered = $readings;
            $filteredCount = $count;
        }
        
        if ($filteredCount <= $maxPoints) {
            return $filtered;
        }
        
        // ⭐ Step 2: Sample evenly
        $sampled = [];
        $step = ($filteredCount - 1) / ($maxPoints - 1);
        
        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int) round($i * $step);
            if ($index < $filteredCount) {
                $sampled[] = $filtered[$index];
            }
        }
        
        // Ensure first/last included
        $first = $filtered[0];
        $last = $filtered[$filteredCount - 1];
        
        $firstLat = (float)(is_object($first) ? $first->latitude : ($first['latitude'] ?? 0));
        $firstLng = (float)(is_object($first) ? $first->longitude : ($first['longitude'] ?? 0));
        $sFirst = $sampled[0];
        $sFirstLat = (float)(is_object($sFirst) ? $sFirst->latitude : ($sFirst['latitude'] ?? 0));
        $sFirstLng = (float)(is_object($sFirst) ? $sFirst->longitude : ($sFirst['longitude'] ?? 0));
        
        if (abs($firstLat - $sFirstLat) > 0.00001 || abs($firstLng - $sFirstLng) > 0.00001) {
            array_unshift($sampled, $first);
        }
        
        $lastLat = (float)(is_object($last) ? $last->latitude : ($last['latitude'] ?? 0));
        $lastLng = (float)(is_object($last) ? $last->longitude : ($last['longitude'] ?? 0));
        $sLast = end($sampled);
        $sLastLat = (float)(is_object($sLast) ? $sLast->latitude : ($sLast['latitude'] ?? 0));
        $sLastLng = (float)(is_object($sLast) ? $sLast->longitude : ($sLast['longitude'] ?? 0));
        
        if (abs($lastLat - $sLastLat) > 0.00001 || abs($lastLng - $sLastLng) > 0.00001) {
            $sampled[] = $last;
        }
        
        return $sampled;
    }
    
    /**
     * ⭐ Call OpenRouteService Directions API for road distance
     * Same API used in mobile app - Free tier: 2,000 requests/day
     */
    private function callOpenRouteService(array $readings): ?float
    {
        // OpenRouteService API key
        $apiKey = env('OPENROUTESERVICE_API_KEY', '5b3ce3597851110001cf62487c37b3c0b8d74b9fb9f7d9f3c3d7f8e9');
        
        // Build coordinates array [lng, lat] format (GeoJSON standard)
        $coordinates = array_map(function($reading) {
            return [
                (float) (is_object($reading) ? $reading->longitude : ($reading['longitude'] ?? 0)),
                (float) (is_object($reading) ? $reading->latitude : ($reading['latitude'] ?? 0))
            ];
        }, $readings);
        
        // ⭐ Debug logging
        Log::info('OpenRouteService: Attempting API call', [
            'coordinates_count' => count($coordinates),
            'first_coord' => $coordinates[0] ?? null,
            'last_coord' => $coordinates[count($coordinates) - 1] ?? null,
        ]);
        
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);
            
            $response = $client->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'coordinates' => $coordinates,
                    'instructions' => false,
                    'geometry' => false,
                    'units' => 'km',
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (isset($data['routes'][0]['summary']['distance'])) {
                $distance = $data['routes'][0]['summary']['distance'];
                Log::info('OpenRouteService: Success', ['distance_km' => $distance]);
                return $distance; // Already in km
            }
            
            Log::warning('OpenRouteService response missing distance', ['data' => $data]);
            return null;
            
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response';
            Log::warning('OpenRouteService API client error (4xx)', [
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'body' => $responseBody,
                'coordinates_count' => count($coordinates)
            ]);
            return null;
            
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response';
            Log::warning('OpenRouteService API server error (5xx)', [
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'body' => $responseBody,
                'coordinates_count' => count($coordinates)
            ]);
            return null;
            
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::warning('OpenRouteService API connection error', [
                'error' => $e->getMessage(),
                'coordinates_count' => count($coordinates)
            ]);
            return null;
            
        } catch (\Exception $e) {
            Log::warning('OpenRouteService API error', [
                'error' => $e->getMessage(),
                'coordinates_count' => count($coordinates)
            ]);
            return null;
        }
    }

    /**
     * Get fuel rate groups (JSON for modal)
     */
    public function getFuelRateGroups(Request $request)
    {
        try {
            $groups = DB::table('t_fin_petrol_rate_group')
                ->where('is_active', 1)
                ->orderBy('name')
                ->get();

            $rateGroups = [];
            foreach ($groups as $group) {
                $users = [];
                if (!empty($group->user_ids)) {
                    $ids = array_map('trim', explode(',', $group->user_ids));
                    $ids = array_filter($ids);
                    $users = DB::table('t_sys_user')
                        ->whereIn('id', $ids)
                        ->select('id', 'fullname')
                        ->get()
                        ->map(fn($u) => ['id' => $u->id, 'name' => $u->fullname])
                        ->values()
                        ->toArray();
                }
                $rateGroups[] = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'rate' => (float) $group->rate,
                    'user_ids' => $group->user_ids ?: '',
                    'users' => $users,
                ];
            }

            $allRiders = DB::table('t_sys_user as u')
                ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
                ->where('u.is_active', 1)
                ->where(function($q) {
                    $q->whereNull('av.is_visible')
                      ->orWhere('av.is_visible', 1);
                })
                ->select('u.id', 'u.fullname')
                ->orderBy('u.fullname')
                ->get()
                ->map(fn($u) => ['id' => $u->id, 'name' => $u->fullname])
                ->values();

            return response()->json([
                'success' => true,
                'rate_groups' => $rateGroups,
                'all_riders' => $allRiders,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Save fuel rate groups (JSON POST from modal)
     */
    public function saveFuelRateGroups(Request $request)
    {
        try {
            $validated = $request->validate([
                'groups' => 'required|array|min:1',
                'groups.*.id' => 'nullable|integer',
                'groups.*.name' => 'required|string|max:100',
                'groups.*.rate' => 'required|numeric|min:0.01|max:999',
                'groups.*.user_ids' => 'nullable|string',
            ]);

            // Validate no user in multiple groups
            $allAssignedIds = [];
            foreach ($validated['groups'] as $group) {
                if (!empty($group['user_ids'])) {
                    $ids = array_map('trim', explode(',', $group['user_ids']));
                    $ids = array_filter($ids);
                    foreach ($ids as $id) {
                        if (in_array($id, $allAssignedIds)) {
                            $userName = DB::table('t_sys_user')->where('id', $id)->value('fullname') ?: "User #$id";
                            return response()->json([
                                'success' => false,
                                'message' => "$userName is assigned to multiple rate groups."
                            ], 422);
                        }
                        $allAssignedIds[] = $id;
                    }
                }
            }

            DB::beginTransaction();

            $existingIds = DB::table('t_fin_petrol_rate_group')->where('is_active', 1)->pluck('id')->toArray();
            $incomingIds = array_filter(array_column($validated['groups'], 'id'));

            $toDelete = array_diff($existingIds, $incomingIds);
            if (!empty($toDelete)) {
                DB::table('t_fin_petrol_rate_group')
                    ->whereIn('id', $toDelete)
                    ->update(['is_active' => 0, 'updated_at' => now()]);
            }

            foreach ($validated['groups'] as $groupData) {
                if (!empty($groupData['id'])) {
                    DB::table('t_fin_petrol_rate_group')
                        ->where('id', $groupData['id'])
                        ->update([
                            'name' => $groupData['name'],
                            'rate' => $groupData['rate'],
                            'user_ids' => $groupData['user_ids'] ?? null,
                            'is_active' => 1,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('t_fin_petrol_rate_group')->insert([
                        'name' => $groupData['name'],
                        'rate' => $groupData['rate'],
                        'user_ids' => $groupData['user_ids'] ?? null,
                        'is_active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Keep legacy config in sync
            $firstGroup = $validated['groups'][0] ?? null;
            if ($firstGroup) {
                \App\Models\FIN\ConfigModel::set('PETROL_RATE_PER_KM', (string) $firstGroup['rate'], 'Petrol rate (synced from rate groups)');
                \App\Models\FIN\ConfigModel::set('PETROL_AUTO_CALC_USER_IDS', implode(',', $allAssignedIds), 'Petrol user IDs (synced from rate groups)');
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Fuel rate groups saved successfully']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
