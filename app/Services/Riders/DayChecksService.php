<?php

namespace App\Services\Riders;

/**
 * ONE brain for the attendance "day checks" — the ⛽ meter verdict, the 📡 GPS verdict, the
 * per-row issue chips, and the overall "is this day clean" flag. BOTH the web attendance page
 * (AttendanceController::data) AND the mobile store screen (RiderController::getStoreAttendanceDaily)
 * build day_checks through this, so the phone and the web can NEVER disagree about the same rider.
 *
 * The GPS phase analyzer lives here too, and is the exact same code the GPS-details modal uses
 * (AttendanceController::gpsReadingsAudit delegates here) — so the 📡 tick and the modal always match.
 *
 * build() and computeGpsPhases() are pure (timestamps + already-computed journey state) — no DB.
 * The only queries in here are in the batched month helpers (meterMissDays), which exist so the
 * MONTH columns are counted by the very same rule the daily ⛽ tick uses.
 */
class DayChecksService
{
    /** Why a completed working day counts as a missed meter (shared web + mobile wording). */
    public const METER_MISS_LABELS = [
        'none'     => 'No meter reading',
        'no_start' => 'No start meter',
        'no_end'   => 'No closing meter',
    ];

    /**
     * THE meter rule — one definition for the daily ⛽ tick and the monthly "Meter" column.
     * Returns null (= nothing missed / not applicable) or a METER_MISS_LABELS key.
     *
     * A day is only judged when the person is expected to carry a meter AND the day is FINISHED
     * (checked in and checked out). That single condition is what keeps absences, leave days,
     * not-needed days, future dates and today's still-running shift out of the count — none of
     * them have both stamps.
     */
    public static function meterMissReason(bool $isMeterUser, $loginTime, $logoutTime, $meterStart, $meterEnd, $meterHome = null): ?string
    {
        if (!$isMeterUser || empty($loginTime) || empty($logoutTime)) { return null; }
        $has = fn ($v) => $v !== null && $v !== '';
        $noStart = !$has($meterStart);
        // A bike rider closes on meter_home (mirrored to meter_end on write); accept either so a
        // legacy row that only carries meter_home isn't reported as a missing closing reading.
        $noEnd = !$has($meterEnd) && !$has($meterHome);
        if ($noStart && $noEnd) { return 'none'; }
        if ($noStart) { return 'no_start'; }
        if ($noEnd) { return 'no_end'; }
        return null;
    }

    /**
     * Missed-meter days per user over a range (the Month "Meter" column + its date drill).
     * Same shape as WorkJourneyService::workIssueDays so the month report treats all three
     * flag families identically.
     *
     * @return array [userId => ['days' => int, 'detail' => [date => reasonKey]]]
     */
    public function meterMissDays(array $userIds, string $from, string $to): array
    {
        $out = [];
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (empty($userIds)) { return $out; }
        // Never judge a day that hasn't happened yet, whatever range the caller passes.
        $today = date('Y-m-d');
        if ($to > $today) { $to = $today; }
        if ($from > $to) { return $out; }
        try {
            // Who is even expected to carry a meter? Rider by role, company bike — OR anyone who
            // ACTUALLY recorded meter readings in this range. The last test is what catches a
            // supervisor who rides (e.g. types meters 17 of 18 days): the data proves the duty,
            // the role name doesn't. Someone with zero readings all month (office staff, a
            // supervisor with no vehicle) is left alone — a person who never carries a meter
            // can't "miss" one.
            $meterUsers = \Illuminate\Support\Facades\DB::table('t_sys_user as u')
                ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
                ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
                ->whereIn('u.id', $userIds)
                ->where(function ($q) {
                    $q->where('r.urole_name', 'like', '%rider%')->orWhere('rp.company_bike', 1);
                })
                ->distinct()->pluck('u.id')->all();
            $recordedUsers = \Illuminate\Support\Facades\DB::table('t_ops_attendance')
                ->whereIn('user_id', $userIds)
                ->whereBetween('attendance_date', [$from, $to])
                ->where(function ($q) {
                    $q->whereNotNull('meter_start')->orWhereNotNull('meter_end')->orWhereNotNull('meter_home');
                })
                ->distinct()->pluck('user_id')->all();
            $meterUsers = array_values(array_unique(array_merge($meterUsers, $recordedUsers)));
            // The owner's OWN exemption switch wins over every test above: Rider Management →
            // "Meter reading is compulsory" unticked means the app stops asking this person for a
            // meter, so the report must stop counting it against him. (Same flag the rider app
            // reads as `meter_required`.)
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_rider_profile', 'meter_required')) {
                $exempt = \Illuminate\Support\Facades\DB::table('t_ops_rider_profile')
                    ->whereIn('user_id', $meterUsers)->where('meter_required', 0)
                    ->pluck('user_id')->all();
                $meterUsers = array_values(array_diff($meterUsers, $exempt));
            }
            if (empty($meterUsers)) { return $out; }

            $rows = \Illuminate\Support\Facades\DB::table('t_ops_attendance')
                ->whereIn('user_id', $meterUsers)
                ->whereBetween('attendance_date', [$from, $to])
                ->whereNotNull('login_time')
                ->whereNotNull('logout_time')   // finished days only — see meterMissReason()
                ->orderBy('attendance_date')
                ->get(['user_id', 'attendance_date', 'login_time', 'logout_time', 'meter_start', 'meter_end', 'meter_home']);

            foreach ($rows as $r) {
                $reason = self::meterMissReason(true, $r->login_time, $r->logout_time, $r->meter_start, $r->meter_end, $r->meter_home);
                if ($reason === null) { continue; }
                $uid = (int) $r->user_id;
                if (!isset($out[$uid])) { $out[$uid] = ['days' => 0, 'detail' => []]; }
                $out[$uid]['days']++;
                $out[$uid]['detail'][substr((string) $r->attendance_date, 0, 10)] = $reason;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('meterMissDays failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /**
     * Shared GPS phase analysis. Segments the day into ride-in / on-duty / ride-home (U5 timeline
     * anchors) and scores each phase on MOVING time only — stationary gaps folded out. Low-accuracy
     * readings are dropped INSIDE here so every caller scores on the same set.
     *
     * @param array  $readingsArr rows with ->latitude ->longitude ->captured_at (->accuracy), time-ordered
     * @param object $att         attendance row: login_time, logout_time, meter_start_recorded_at,
     *                            home_meter_recorded_at, meter_start_source
     */
    public function computeGpsPhases(array $readingsArr, $att, string $date): array
    {
        if (empty($att->login_time)) {
            return ['phases' => [], 'worst_coverage' => null, 'moving_gap_min' => 0, 'has_readings' => !empty($readingsArr)];
        }
        // Drop low-accuracy noise INSIDE the analyzer so both callers (bulk tick + single modal)
        // score on the exact same readings regardless of how they queried — one source of truth.
        $readingsArr = array_values(array_filter($readingsArr, function ($r) {
            return !isset($r->accuracy) || $r->accuracy === null || (float) $r->accuracy <= 100;
        }));
        $minGapThreshold = 10;
        $isSameLocation = function ($lat1, $lng1, $lat2, $lng2) {
            $R = 6371000; $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
            return ($R * 2 * atan2(sqrt($a), sqrt(1 - $a))) < 50;
        };
        $loginTime = strtotime($date . ' ' . $att->login_time);
        $logoutTime = !empty($att->logout_time) ? strtotime($date . ' ' . $att->logout_time) : time();
        if ($logoutTime > time()) { $logoutTime = time(); }

        $phaseCoverage = function ($fromTs, $toTs) use ($readingsArr, $isSameLocation, $minGapThreshold) {
            if (!$fromTs || !$toTs || $toTs <= $fromTs) { return null; }
            $mins = ($toTs - $fromTs) / 60;
            $inb = array_values(array_filter($readingsArr, function ($r) use ($fromTs, $toTs) {
                $t = strtotime($r->captured_at); return $t >= $fromTs && $t <= $toTs;
            }));
            $movingGaps = []; $statMin = 0; $movingGapMin = 0; $prevTs = $fromTs; $prevR = null;
            foreach ($inb as $r) {
                $ts = strtotime($r->captured_at); $gap = ($ts - $prevTs) / 60;
                if ($gap > $minGapThreshold) {
                    $stat = $prevR && $isSameLocation((float) $prevR->latitude, (float) $prevR->longitude, (float) $r->latitude, (float) $r->longitude);
                    if ($stat) { $statMin += $gap; }
                    else { $movingGapMin += $gap; $movingGaps[] = ['from' => date('H:i', $prevTs), 'to' => date('H:i', $ts), 'min' => (int) round($gap)]; }
                }
                $prevTs = $ts; $prevR = $r;
            }
            $gEnd = ($toTs - $prevTs) / 60;
            if ($gEnd > $minGapThreshold) { $movingGapMin += $gEnd; $movingGaps[] = ['from' => date('H:i', $prevTs), 'to' => date('H:i', $toTs), 'min' => (int) round($gEnd)]; }
            return [
                'minutes' => (int) round($mins),
                'coverage' => $mins > 0 ? max(0, (int) round((($mins - $movingGapMin) / $mins) * 100)) : 0,
                'moving_gaps' => $movingGaps,
                'stationary_min' => (int) round($statMin),
                'readings' => count($inb),
            ];
        };
        // `judged` = does this phase drive the 📡 verdict? ONLY on-duty does. The commute phases
        // (ride in / ride home) are the rider's own time on his own phone — they are still shown
        // for context, but a dead GPS on the way to work must never raise an alert against him.
        $phases = [];
        $rideInStart = !empty($att->meter_start_recorded_at) ? strtotime((string) $att->meter_start_recorded_at) : null;
        if ($rideInStart && (string) ($att->meter_start_source ?? '') === 'home' && $rideInStart < $loginTime) {
            $p = $phaseCoverage($rideInStart, $loginTime);
            if ($p) { $p['name'] = 'Ride in'; $p['from'] = date('H:i', $rideInStart); $p['to'] = date('H:i', $loginTime); $p['judged'] = false; $phases[] = $p; }
        }
        $onDuty = $phaseCoverage($loginTime, $logoutTime);
        if ($onDuty) { $onDuty['name'] = 'On duty'; $onDuty['from'] = date('H:i', $loginTime); $onDuty['to'] = !empty($att->logout_time) ? date('H:i', $logoutTime) : 'now'; $onDuty['judged'] = true; $phases[] = $onDuty; }
        $homeMeterTs = !empty($att->home_meter_recorded_at) ? strtotime((string) $att->home_meter_recorded_at) : null;
        if (!empty($att->logout_time) && $homeMeterTs && $homeMeterTs > $logoutTime) {
            $p = $phaseCoverage($logoutTime, $homeMeterTs);
            if ($p) { $p['name'] = 'Ride home'; $p['from'] = date('H:i', $logoutTime); $p['to'] = date('H:i', $homeMeterTs); $p['judged'] = false; $phases[] = $p; }
        }
        // Verdict inputs come from judged phases ONLY. No judged phase (shouldn't happen once he
        // logs in) ⇒ worst stays null ⇒ callers show "can't judge", not a false alarm.
        $worst = null; $movingGapTotal = 0;
        foreach ($phases as $p) {
            if (empty($p['judged'])) { continue; }
            $worst = $worst === null ? $p['coverage'] : min($worst, $p['coverage']);
            $movingGapTotal += array_sum(array_column($p['moving_gaps'], 'min'));
        }
        return ['phases' => $phases, 'worst_coverage' => $worst, 'moving_gap_min' => $movingGapTotal, 'has_readings' => !empty($readingsArr)];
    }

    /**
     * Build the day_checks block for ONE worked row. Returns null when the rider did not work
     * (no login). All inputs are passed in $ctx so web and mobile produce IDENTICAL results.
     *
     * @param array  $ctx  login_time, logout_time, role_name, company_bike, meter_start, meter_end,
     *                     meter_distance, road_distance_km, gps_distance, checkout_info, home_journey,
     *                     work_journey, checkout_unlock, meter_start_recorded_at,
     *                     home_meter_recorded_at, meter_start_source
     * @param array  $readings the rider's GPS readings for the date (may be empty)
     */
    public function build(array $ctx, array $readings, string $date, float $meterGpsWarnKm): ?array
    {
        $g = fn ($k) => $ctx[$k] ?? null;
        if (empty($g('login_time'))) { return null; }

        $isRider = stripos((string) ($g('role_name') ?? ''), 'rider') !== false;
        $bike = !empty($g('company_bike'));
        $hj = $g('home_journey'); $wj = $g('work_journey'); $cu = $g('checkout_unlock');
        $checkoutInfo = $g('checkout_info');
        $chips = [];        // timeline/structural issues shown on the compact row
        $meterIssues = [];  // reasons the ⛽ tick is amber (tooltip)

        // Morning (bike): home-start + ride-in ETA + continuity + check-in bypass.
        if ($bike && is_array($wj)) {
            if (($wj['state'] ?? '') === 'no_home_start') { $chips[] = ['label' => 'no home start', 'tone' => 'amber']; $meterIssues[] = 'Start meter not recorded at home'; }
            if (($wj['state'] ?? '') === 'arrived_late')  { $chips[] = ['label' => '+' . ($wj['minutes_late'] ?? '?') . 'm past ride-in ETA', 'tone' => 'amber']; }
            if (!empty($wj['continuity']) && !empty($wj['continuity']['breach'])) {
                $gap = $wj['continuity']['gap']; $chips[] = ['label' => 'meter gap ' . ($gap > 0 ? '+' : '') . $gap . ' km', 'tone' => 'red']; $meterIssues[] = 'Overnight/continuity gap ' . $gap . ' km';
            }
            if (!empty($wj['checkin_unlock']) && !empty($wj['checkin_unlock']['active'])) { $chips[] = ['label' => 'check-in bypassed', 'tone' => 'amber']; }
        }
        // Evening home meter (bike): late-locked / unlocked / bypass breach.
        if ($bike && is_array($hj)) {
            if (($hj['state'] ?? '') === 'late_locked') { $chips[] = ['label' => 'home late — locked', 'tone' => 'red']; $meterIssues[] = 'Reached home late (locked)'; }
            elseif (($hj['state'] ?? '') === 'unlocked') { $chips[] = ['label' => 'home meter unlocked', 'tone' => 'amber']; $meterIssues[] = 'Home meter unlocked by manager'; }
            elseif (!empty($hj['breach'])) { $meterIssues[] = 'Home meter recorded with a breach'; }
        }
        // Checkout bypass (any rider) + off-pin / back-at-office exceptions.
        if (is_array($cu) && !empty($cu['used'])) { $chips[] = ['label' => 'checkout bypassed', 'tone' => 'amber']; $meterIssues[] = 'Checkout bypassed'; }
        if (is_array($checkoutInfo) && !empty($checkoutInfo['office_exception'])) { $chips[] = ['label' => 'back at office after delivering', 'tone' => 'amber']; }
        if (is_array($checkoutInfo) && !empty($checkoutInfo['pin_away'])) { $chips[] = ['label' => 'checkout off pin', 'tone' => 'amber']; }

        // ⛽ meter verdict — missing reading (rider who checked out) + meter-vs-GPS off.
        $meterStart = $g('meter_start'); $meterEnd = $g('meter_end'); $meterDistance = $g('meter_distance');
        // Shared rule (same one the Month "Meter" column counts with) — a finished working day
        // for a meter-carrying person that is missing the start and/or the closing reading.
        // "Carries a meter" = rider role, company bike, OR typed any reading today (a supervisor
        // who rides proves the duty by recording; mirrors meterMissDays' recorded-users test —
        // the one day this can't see is a zero-reading day for a role-less meter user, which the
        // month column still catches with its month-wide view).
        $meterHome = $g('meter_home') ?? (is_array($hj) ? ($hj['meter_home'] ?? null) : null);
        $has = fn ($v) => $v !== null && $v !== '';
        // meter_required === 0 = the owner exempted him in Rider Management; the app stops asking,
        // so the tick must stop judging. Only an explicit 0 exempts (null/absent = judge as before).
        $carriesMeter = ((int) ($g('meter_required') ?? 1) !== 0)
            && ($isRider || $bike || $has($meterStart) || $has($meterEnd) || $has($meterHome));
        $missReason = self::meterMissReason(
            $carriesMeter, $g('login_time'), $g('logout_time'),
            $meterStart, $meterEnd, $meterHome
        );
        if ($missReason !== null) {
            $meterIssues[] = self::METER_MISS_LABELS[$missReason];
            $chips[] = ['label' => 'no meter', 'tone' => 'amber'];
        }
        $cmp = $g('road_distance_km') ?? $g('gps_distance');
        if ($meterDistance !== null && $cmp !== null && $cmp > 0) {
            if (abs($meterDistance - $cmp) > $meterGpsWarnKm) { $meterIssues[] = 'Meter vs GPS off by ' . round(abs($meterDistance - $cmp)) . ' km'; }
        }
        $meterOk = empty($meterIssues);

        // 📡 GPS verdict — the SHARED analyzer (identical to the modal). null = can't judge.
        $gpsOk = null; $gpsWorst = null; $gpsNote = null;
        try {
            $attObj = (object) [
                'login_time' => $g('login_time'), 'logout_time' => $g('logout_time'),
                'meter_start_recorded_at' => $g('meter_start_recorded_at'),
                'home_meter_recorded_at' => $g('home_meter_recorded_at'),
                'meter_start_source' => $g('meter_start_source'),
            ];
            $pa = $this->computeGpsPhases($readings, $attObj, $date);
            $gpsWorst = $pa['worst_coverage'];
            if ($gpsWorst !== null) {
                $gpsOk = $gpsWorst >= 90;
                if (!$gpsOk) { $gpsNote = 'GPS off ' . $pa['moving_gap_min'] . ' min while moving on duty'; }
            }
        } catch (\Throwable $e) { $gpsOk = null; }

        return [
            'is_clean'     => $meterOk && ($gpsOk !== false) && empty($chips),
            'meter_ok'     => $meterOk,
            'meter_issues' => $meterIssues,
            'gps_ok'       => $gpsOk,     // true | false | null
            'gps_worst'    => $gpsWorst,
            'gps_note'     => $gpsNote,
            'chips'        => $chips,
        ];
    }

    /**
     * Normalize the LATEST blocked-checkout attempt recorded on an attendance row into ONE block
     * consumed identically by the web bypass modal AND the mobile bypass sheet (and reused by the
     * live "stuck at checkout" alert). Pure — reads the checkout_attempt_* columns already on the
     * row, no new query. Returns null when there is no recorded attempt.
     *
     * @param object|array $row       attendance row with checkout_attempt_* (+ logout_time)
     * @param int          $freshMins how recent (minutes) still counts as "current"
     */
    public static function checkoutAttempt($row, int $freshMins = 25): ?array
    {
        $get = fn ($k) => is_array($row) ? ($row[$k] ?? null) : ($row->$k ?? null);
        $at = $get('checkout_attempt_at');
        if (empty($at)) { return null; }

        $reason   = (string) ($get('checkout_attempt_reason') ?: 'wrong_place');
        $dist     = $get('checkout_attempt_distance_m'); $dist = $dist !== null ? (int) $dist : null;
        $limit    = $get('checkout_attempt_limit_m');    $limit = $limit !== null ? (int) $limit : null;
        $age      = $get('checkout_attempt_age_min');    $age = $age !== null ? (int) $age : null;
        $label    = $get('checkout_attempt_drop_label');
        $acc      = $get('checkout_attempt_accuracy_m'); $acc = $acc !== null ? (float) $acc : null;
        $aLat = $get('checkout_attempt_lat');      $aLat = $aLat !== null ? (float) $aLat : null;
        $aLng = $get('checkout_attempt_lng');      $aLng = $aLng !== null ? (float) $aLng : null;
        $dLat = $get('checkout_attempt_drop_lat'); $dLat = $dLat !== null ? (float) $dLat : null;
        $dLng = $get('checkout_attempt_drop_lng'); $dLng = $dLng !== null ? (float) $dLng : null;

        $ts = strtotime((string) $at);
        $isRecent = $ts ? ((time() - $ts) <= max(1, $freshMins) * 60) : false;

        $fmtDist = function ($m) {
            if ($m === null) { return null; }
            return $m < 1000 ? ($m . ' m') : (round($m / 1000, 1) . ' km');
        };
        $refName = ($label && strcasecmp($label, 'Office') === 0) ? 'the office' : ('last delivery' . ($label ? " ($label)" : ''));

        switch ($reason) {
            case 'too_far':
                $headline = 'Too far — ' . ($fmtDist($dist) ?? 'away') . ' from ' . $refName
                          . ($limit !== null ? ' (limit ' . $fmtDist($limit) . ')' : '');
                break;
            case 'too_late':
                $headline = 'Too late — ' . $refName . ' was ' . ($age !== null ? $age . ' min' : 'a while') . ' ago';
                break;
            case 'no_gps':
                $headline = 'Location was off when he tried to check out';
                break;
            default: // wrong_place
                $headline = $dist !== null
                    ? ('Not at a valid spot — ' . $fmtDist($dist) . ' from ' . $refName)
                    : 'Not at the office or a recent delivery';
        }

        // How good was the fix we judged him on? A distance measured from a +-200 m network
        // fix deserves a different decision from one measured at +-8 m, and until now the
        // manager had no way to tell them apart. Only shown when the phone reported it.
        if ($acc !== null) {
            $headline .= ' [fix +-' . (int) round($acc) . ' m'
                       . ($acc > 150 ? ', coarse — treat the distance as rough' : '') . ']';
        }

        // Map link: a directions line from where he stood → the delivery/office pin, so the manager
        // literally sees "delivery was here, rider was there". Falls back to a single pin.
        $mapsUrl = null;
        if ($aLat !== null && $aLng !== null && $dLat !== null && $dLng !== null) {
            $mapsUrl = 'https://www.google.com/maps/dir/?api=1&origin=' . $aLat . ',' . $aLng
                     . '&destination=' . $dLat . ',' . $dLng;
        } elseif ($aLat !== null && $aLng !== null) {
            $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . $aLat . ',' . $aLng;
        } elseif ($dLat !== null && $dLng !== null) {
            $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . $dLat . ',' . $dLng;
        }

        return [
            'reason'      => $reason,
            'headline'    => $headline,
            'at'          => (string) $at,
            'distance_m'  => $dist,
            'limit_m'     => $limit,
            'age_min'     => $age,
            'accuracy_m'  => $acc,
            'is_coarse'   => $acc !== null && $acc > 150,
            'drop_label'  => $label,
            'attempt_lat' => $aLat, 'attempt_lng' => $aLng,
            'drop_lat'    => $dLat, 'drop_lng'    => $dLng,
            'count'       => (int) ($get('checkout_attempt_count') ?? 0),
            'is_recent'   => $isRecent,
            'still_open'  => empty($get('logout_time')),
            'maps_url'    => $mapsUrl,
        ];
    }
}
