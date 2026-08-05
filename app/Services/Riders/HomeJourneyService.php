<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Company-bike "going home" journey (U4). A company-bike rider checks out at his last delivery /
 * office as normal; that arms a home journey with an ETA + a 15-min grace. He must record his ONE
 * meter reading AT HOME within the window; if he's late, the meter is LOCKED until a manager hears
 * why and opens a timed (default 10-min) unlock. Purpose = bike accountability, not petrol.
 *
 * This service holds the pure, DB-only logic (state derivation, the lock check, the reporting
 * helper, config). The ETA call at arm-time lives in RiderController (it has the Google helpers);
 * everything here is verifiable without Google.
 */
class HomeJourneyService
{
    /**
     * A rider is on the going-home flow only if he is on a COMPANY MACHINE and a
     * home pin is set.
     *
     * ⭐ PHASE C — THIS IS WHERE THE TWO SEAMS MEET. The gate ("is he on a company
     *    machine today?") now comes from the vehicle registry via FuelClaimRules,
     *    so assigning a company bike puts a rider on the going-home flow without
     *    anyone ticking a second box. The PIN itself is still his home, which is
     *    correct for a bike: if Danish takes Arslan's bike home the fence must be
     *    Danish's house. A machine with its own base (the van's parking) is
     *    handled by `VehicleResolver::overnightPinFor`, which wraps this.
     *
     * ⚠ Gated: while VEHICLE_RULES is off, or for a rider holding no registered
     *   vehicle, the gate is the old profile checkbox exactly as before.
     */
    public function riderHomePin(int $userId, ?string $date = null): ?array
    {
        if (!Schema::hasColumn('t_ops_rider_profile', 'home_latitude')) {
            return null;
        }
        $p = DB::table('t_ops_rider_profile')->where('user_id', $userId)
            ->first(['company_bike', 'home_latitude', 'home_longitude', 'home_radius_m']);
        if (!$p) {
            return null;
        }
        if (!(new FuelClaimRules())->ridesCompanyBike($userId, $date)) {
            return null;
        }
        if ($p->home_latitude === null || $p->home_longitude === null) {
            return null;
        }
        return [
            'lat' => (float) $p->home_latitude,
            'lng' => (float) $p->home_longitude,
            'radius_m' => ($p->home_radius_m && $p->home_radius_m > 0) ? (int) $p->home_radius_m : (int) $this->config('HOME_RADIUS_M', 300),
        ];
    }

    public function config(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * The rider's still-OPEN going-home journey row (checked out, timer armed, meter not yet
     * recorded), searched across TODAY and YESTERDAY so a checkout that crosses midnight isn't
     * lost. Bounded to home_expected_by within the last 12h so an abandoned old journey can't
     * resurrect. Returns the attendance row (newest first) or null.
     *
     * This exists because a rider who checks out late (e.g. 11:59 PM) has home_expected_by on the
     * NEXT calendar day; every "today" lookup would then miss the journey the moment the day rolls
     * over — the exact bug that stopped a near-midnight meter submit. All rider-facing home-journey
     * surfaces (card, banner, submit, heartbeat geofence) resolve the row through here.
     */
    public function openJourneyRow(int $userId): ?object
    {
        try {
            if (!Schema::hasColumn('t_ops_attendance', 'home_expected_by')) {
                return null;
            }
            $today = now()->format('Y-m-d');
            $yesterday = now()->subDay()->format('Y-m-d');
            $cutoff = now()->subHours(12)->format('Y-m-d H:i:s');
            return DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->whereIn('attendance_date', [$today, $yesterday])
                ->whereNotNull('logout_time')
                ->whereNotNull('home_expected_by')
                ->whereNull('meter_home')
                ->where('home_expected_by', '>=', $cutoff)
                ->orderByDesc('attendance_date')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Derive the journey state from a t_ops_attendance row (stdClass or array). Pure — timestamps only.
     * Returns one of: none | armed | arrived_pending_meter | completed_on_time | completed_late |
     * late_locked | unlocked.
     *
     * `arrived_pending_meter` = GPS proved he ARRIVED at home ON TIME (heartbeat geofence stamped
     * home_arrived_at ≤ expected_by) but he hasn't typed the meter yet — even past the window he met
     * the time+location rule, so he may still submit (never punish a slow typist who arrived on time).
     */
    public function deriveState($att): string
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        if (!$g('home_expected_by')) {
            return 'none'; // journey never armed (non-bike, no home pin, no-GPS arm, or old row)
        }
        $now = time();
        $expected = strtotime((string) $g('home_expected_by'));
        $meter = $g('meter_home');
        $arrived = $g('home_arrived_at');
        $arrivedTs = $arrived ? strtotime((string) $arrived) : null;
        $arrivedOnTime = $arrivedTs !== null && $arrivedTs <= $expected;

        if ($meter !== null && $meter !== '') {
            // Completed. Late if arrival was past the deadline, or a late reason was recorded
            // (manager unlock / manager entry). An on-time arrival stamp always wins.
            $late = !$arrivedOnTime && (
                ($g('home_late_reason') !== null && $g('home_late_reason') !== '')
                || ($arrivedTs !== null && $arrivedTs > $expected)
            );
            return $late ? 'completed_late' : 'completed_on_time';
        }

        if ($now <= $expected) {
            return 'armed'; // window open, awaiting the meter
        }
        if ($arrivedOnTime) {
            return 'arrived_pending_meter'; // he made it on time — just the typing is pending
        }
        // Window expired, no meter, no on-time arrival → locked unless a manager unlock is active.
        $unlockUntil = $g('home_meter_unlock_until');
        if ($unlockUntil && strtotime((string) $unlockUntil) >= $now) {
            return 'unlocked';
        }
        return 'late_locked';
    }

    /**
     * May the RIDER submit his home meter right now? Allowed while the window is open, when GPS
     * proved an on-time arrival, or while a manager unlock is active. Denied when late_locked.
     * Returns [ok=>bool, reason=>string].
     */
    public function canRiderSubmit($att): array
    {
        $state = $this->deriveState($att);
        if (in_array($state, ['armed', 'arrived_pending_meter', 'unlocked'], true)) {
            return ['ok' => true, 'reason' => $state];
        }
        if (in_array($state, ['completed_on_time', 'completed_late'], true)) {
            return ['ok' => false, 'reason' => 'already_done'];
        }
        // late_locked
        return ['ok' => false, 'reason' => 'late_locked'];
    }

    /**
     * What was wrong at the moment a manager bypassed the lock — the audit the owner asked for
     * ("record what was breached: location, time, or both"). Computed from the row state:
     *   'time'     — he arrived late / the window had passed with no on-time arrival
     *   'location' — his arrival at home was never GPS-confirmed
     *   'both'     — both of the above
     *   'none'     — neither (manager acted while the journey was still valid)
     */
    public function bypassBreach($att): string
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        $expected = $g('home_expected_by') ? strtotime((string) $g('home_expected_by')) : null;
        $arrived  = $g('home_arrived_at') ? strtotime((string) $g('home_arrived_at')) : null;
        $arrivedOnTime = $expected !== null && $arrived !== null && $arrived <= $expected;
        $timeBad = $expected !== null && time() > $expected && !$arrivedOnTime;
        $locBad  = $arrived === null; // GPS never put him at the home pin
        if ($timeBad && $locBad) { return 'both'; }
        if ($timeBad) { return 'time'; }
        if ($locBad) { return 'location'; }
        return 'none';
    }

    /** Human label for a breach code (reports / banners). */
    public function breachLabel(?string $breach): ?string
    {
        return [
            'time' => 'reached home late',
            'location' => 'location not confirmed',
            'both' => 'late + location not confirmed',
        ][$breach] ?? null;
    }

    /**
     * Minutes late (checkout→home overrun) for a row, or null if not applicable. Uses arrival if
     * present, else "now" for a still-open late journey.
     */
    public function minutesLate($att): ?int
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        if (!$g('home_expected_by')) { return null; }
        $expected = strtotime((string) $g('home_expected_by'));
        $ref = $g('home_arrived_at') ? strtotime((string) $g('home_arrived_at')) : time();
        $over = $ref - $expected;
        return $over > 0 ? (int) round($over / 60) : 0;
    }

    /**
     * Should MANAGEMENT be alerted about this row right now? (owner's rule: single strong alert to
     * the rider on arrival, then escalate to management if the meter still isn't in after 10 min.)
     *   - arrived home but no meter 10+ min later  → escalate
     *   - never arrived and the window has passed   → escalate (late / forgot)
     * Suppressed once the meter is in, or while a manager unlock is active (they're already handling
     * it). The alerted-once dedup (home_mgmt_alerted_at) is applied by the caller, not here.
     */
    public function needsMgmtAlert($att): bool
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        if ($g('meter_home') !== null && $g('meter_home') !== '') { return false; } // done
        if (!$g('home_expected_by')) { return false; }                              // no timed journey
        // Manager already engaged (opened a bypass window) → don't nag management again.
        if ($g('home_meter_unlock_until') && strtotime((string) $g('home_meter_unlock_until')) >= time()) { return false; }
        $now = time();
        $arrived = $g('home_arrived_at') ? strtotime((string) $g('home_arrived_at')) : null;
        if ($arrived !== null) {
            return $now >= $arrived + 600; // home 10+ min, still hasn't typed it
        }
        return $now > strtotime((string) $g('home_expected_by')); // never arrived, window passed
    }

    /**
     * Open going-home journeys (today + yesterday, to catch cross-midnight) that MANAGEMENT should
     * see — for the escalation push (sweep) and the dismissable web/app banners. Each entry carries
     * the display context + whether the push already fired (home_mgmt_alerted_at).
     *
     * @return array [ {attendance_id, user_id, rider_name, state, minutes_late, expected_by,
     *                  arrived_at, breach, reason, already_pushed} ]
     */
    public function openEscalations(): array
    {
        $out = [];
        try {
            if (!Schema::hasColumn('t_ops_attendance', 'home_expected_by')) {
                return $out;
            }
            $today = now()->format('Y-m-d');
            $yesterday = now()->subDay()->format('Y-m-d');
            $cutoff = now()->subHours(18)->format('Y-m-d H:i:s');
            $hasAlerted = Schema::hasColumn('t_ops_attendance', 'home_mgmt_alerted_at');
            $hasBreach  = Schema::hasColumn('t_ops_attendance', 'home_bypass_breach');
            $cols = ['id', 'user_id', 'attendance_date', 'logout_time', 'home_expected_by', 'home_arrived_at',
                     'meter_home', 'home_meter_unlock_until', 'home_late_reason'];
            if ($hasAlerted) { $cols[] = 'home_mgmt_alerted_at'; }
            if ($hasBreach)  { $cols[] = 'home_bypass_breach'; }
            $rows = DB::table('t_ops_attendance')
                ->whereIn('attendance_date', [$today, $yesterday])
                ->whereNotNull('logout_time')
                ->whereNotNull('home_expected_by')
                ->whereNull('meter_home')
                ->where('home_expected_by', '>=', $cutoff)
                ->get($cols);
            if ($rows->isEmpty()) {
                return $out;
            }
            $names = DB::table('t_sys_user')->whereIn('id', $rows->pluck('user_id')->all())->pluck('fullname', 'id');
            foreach ($rows as $r) {
                if (!$this->needsMgmtAlert($r)) { continue; }
                $out[] = [
                    'attendance_id' => (int) $r->id,
                    'user_id'       => (int) $r->user_id,
                    'date'          => substr((string) $r->attendance_date, 0, 10),
                    'rider_name'    => $names[$r->user_id] ?? 'A rider',
                    'state'         => $this->deriveState($r),
                    'minutes_late'  => $this->minutesLate($r),
                    'expected_by'   => $r->home_expected_by ? substr((string) $r->home_expected_by, 11, 5) : null,
                    'arrived_at'    => $r->home_arrived_at ? substr((string) $r->home_arrived_at, 11, 5) : null,
                    'breach'        => $hasBreach ? ($r->home_bypass_breach ?? null) : null,
                    'reason'        => $r->home_late_reason,
                    'already_pushed'=> $hasAlerted ? ($r->home_mgmt_alerted_at !== null) : false,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('openEscalations failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /**
     * Home-journey issues on $date for the Daily Issues report + attendance banners. Per company-bike
     * rider with an armed journey today: the derived state + context. Only "problem" states
     * (late_locked / unlocked / completed_late / a completed-but-no-meter edge) are returned;
     * on-time completions and still-open armed journeys are not issues.
     *
     * @return array [userId => {rider_name, state, minutes_late, checkout_time, expected_by,
     *                           distance_km, arrived_at, has_meter, unlocked_by, reason}]
     */
    public function homeIssues(string $date): array
    {
        $out = [];
        try {
            if (!Schema::hasColumn('t_ops_attendance', 'home_expected_by')) {
                return $out;
            }
            $rows = DB::table('t_ops_attendance')
                ->where('attendance_date', $date)
                ->whereNotNull('home_expected_by')
                ->get(['id', 'user_id', 'logout_time', 'home_expected_by', 'home_arrived_at', 'home_arrival_source',
                       'home_distance_km', 'meter_home', 'home_meter_unlock_until', 'home_meter_unlock_by', 'home_late_reason']);
            if ($rows->isEmpty()) {
                return $out;
            }
            $names = DB::table('t_sys_user')->whereIn('id', $rows->pluck('user_id')->all())->pluck('fullname', 'id');
            $unlockerNames = [];
            $unlockerIds = array_filter($rows->pluck('home_meter_unlock_by')->all());
            if (!empty($unlockerIds)) {
                $unlockerNames = DB::table('t_sys_user')->whereIn('id', $unlockerIds)->pluck('fullname', 'id')->all();
            }

            foreach ($rows as $r) {
                $state = $this->deriveState($r);
                if (in_array($state, ['none', 'armed', 'arrived_pending_meter', 'completed_on_time'], true)) {
                    continue; // not an issue (arrived_pending_meter = he made it on time, just typing)
                }
                $out[$r->user_id] = [
                    'attendance_id' => (int) $r->id,
                    'rider_name'   => $names[$r->user_id] ?? 'Rider',
                    'state'        => $state,
                    'minutes_late' => $this->minutesLate($r),
                    'checkout_time' => $r->logout_time ? substr((string) $r->logout_time, 0, 5) : null,
                    'expected_by'  => $r->home_expected_by ? substr((string) $r->home_expected_by, 11, 5) : null,
                    'distance_km'  => $r->home_distance_km !== null ? (float) $r->home_distance_km : null,
                    'arrived_at'   => $r->home_arrived_at ? substr((string) $r->home_arrived_at, 11, 5) : null,
                    'arrival_source' => $r->home_arrival_source,
                    'has_meter'    => $r->meter_home !== null && $r->meter_home !== '',
                    'unlocked_by'  => $r->home_meter_unlock_by ? ($unlockerNames[$r->home_meter_unlock_by] ?? 'manager') : null,
                    'reason'       => $r->home_late_reason,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('homeIssues failed (non-fatal)', ['date' => $date, 'error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /**
     * Home-journey issue days per user over a range (the Month "Out-flags" column).
     * "Issue" = any non-on-time completed / late_locked / unlocked day.
     *
     * @return array [userId => ['days' => int, 'detail' => [date => [state]]]]
     */
    public function homeIssueDays(array $userIds, string $from, string $to): array
    {
        $out = [];
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (empty($userIds) || !Schema::hasColumn('t_ops_attendance', 'home_expected_by')) {
            return $out;
        }
        try {
            $rows = DB::table('t_ops_attendance')
                ->whereIn('user_id', $userIds)
                ->whereBetween('attendance_date', [$from, $to])
                ->whereNotNull('home_expected_by')
                ->orderBy('attendance_date')
                ->get(['user_id', 'attendance_date', 'home_expected_by', 'home_arrived_at', 'meter_home',
                       'home_meter_unlock_until', 'home_late_reason']);
            foreach ($rows as $r) {
                $state = $this->deriveState($r);
                if (in_array($state, ['completed_late', 'late_locked', 'unlocked'], true)) {
                    $uid = (int) $r->user_id;
                    if (!isset($out[$uid])) { $out[$uid] = ['days' => 0, 'detail' => []]; }
                    $out[$uid]['days']++;
                    $out[$uid]['detail'][substr((string) $r->attendance_date, 0, 10)] = [$state];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('homeIssueDays failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }
}
