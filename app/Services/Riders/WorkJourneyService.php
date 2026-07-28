<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Company-bike "morning start" journey (U5) — the mirror of HomeJourneyService for the ride
 * TO work. A company-bike rider records his ONE start meter AT HOME before leaving; that arms
 * an ETA (home pin → today's shift office) + buffer deadline (work_expected_by). He then checks
 * in exactly as always — arriving past the deadline or skipping the home reading is RECORDED
 * (chips, monthly counts), and only blocks check-in once the manager turns the Phase-2 lock on
 * (config CHECKIN_ETA_LOCK=1; the checkin_unlock_* valve is the bypass).
 *
 * Pure DB-only logic lives here (state derivation, continuity rule, reporting); the ETA call at
 * arm-time lives in RiderController (it has the Google helpers) — same split as the home flow.
 */
class WorkJourneyService
{
    /** Same rider gate as the evening flow: company_bike + home pin. */
    public function riderHomePin(int $userId): ?array
    {
        return (new HomeJourneyService())->riderHomePin($userId);
    }

    public function config(string $key, $default)
    {
        return (new HomeJourneyService())->config($key, $default);
    }

    /** Is the Phase-2 check-in lock live? (0 for the soft launch; flip by SQL, no redeploy.) */
    public function lockEnabled(): bool
    {
        return (int) $this->config('CHECKIN_ETA_LOCK', 0) === 1;
    }

    /** Morning-reading-vs-last-night tolerance in km (owner rule: bike slept at home). */
    public function continuityKm(): float
    {
        return (float) $this->config('METER_CONTINUITY_KM', 1);
    }

    /**
     * The last recorded day-closing meter before $date for this rider — meter_home when the
     * proper home flow closed the day, else meter_end. Used for the morning continuity check
     * AND for showing "prev end" context. Returns ['value' => int, 'date' => 'Y-m-d'] or null.
     */
    public function lastClosingMeter(int $userId, string $beforeDate): ?array
    {
        try {
            $row = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', '<', $beforeDate)
                ->where(function ($q) {
                    $q->whereNotNull('meter_home')->orWhereNotNull('meter_end');
                })
                ->orderByDesc('attendance_date')
                ->first(['attendance_date', 'meter_home', 'meter_end']);
            if (!$row) {
                return null;
            }
            $val = $row->meter_home !== null && $row->meter_home !== '' ? (int) $row->meter_home : (int) $row->meter_end;
            return ['value' => $val, 'date' => substr((string) $row->attendance_date, 0, 10)];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Derive the morning-journey state from an attendance row. Pure — timestamps only.
     *   none            — journey never armed and no check-in context to judge (non-bike, or
     *                     simply nothing recorded yet today)
     *   riding          — home meter in, deadline armed, not checked in, still within time
     *   overdue         — armed, not checked in, deadline passed (live "where is he?")
     *   arrived_on_time — checked in at/before work_expected_by
     *   arrived_late    — checked in after work_expected_by
     *   no_home_start   — checked in but the start meter did NOT come from home
     *                     (source 'checkin'/'manager', or no reading at all)
     *
     * NOTE: a rider who armed the journey but never checked in stays riding/overdue for the
     * live page; reporting (workIssues/workIssueDays) only judges days WITH a check-in, so an
     * absence never counts as a check-in violation.
     */
    public function deriveState($att): string
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        $expected = $g('work_expected_by') ? strtotime((string) $g('work_expected_by')) : null;
        $login = $g('login_time');

        if ($login) {
            // Checked in. Judge the morning by how the start meter got here + the deadline.
            $source = (string) ($g('meter_start_source') ?? '');
            $homeStart = $source === 'home';
            if (!$homeStart) {
                // No GPS-confirmed home reading. Only bike riders are ever armed/judged — the
                // caller filters to them (home pin) before treating this as an issue.
                return 'no_home_start';
            }
            if ($expected === null) {
                return 'arrived_on_time'; // home start but no timer (ETA failed) — never punish
            }
            $loginTs = $this->loginTimestamp($att);
            return ($loginTs !== null && $loginTs > $expected) ? 'arrived_late' : 'arrived_on_time';
        }

        if ($expected !== null) {
            return time() > $expected ? 'overdue' : 'riding';
        }
        return 'none';
    }

    /**
     * WHERE was the morning start meter actually recorded? Pure — reads the meter_start_*
     * columns written by RiderController::uploadMeterPicture.
     *
     * This exists because `meter_start_source = 'checkin'` was being rendered as "typed at
     * office", which is a claim the system never verified: that value is also what you get
     * when the phone sent NO usable fix at all (Waseem, Jul-25 — he was at home). Now the
     * row carries the measured distance, so the UI can state a fact:
     *   home   — GPS inside the home fence
     *   office — GPS inside the shift-location fence (the only case that may say "at office")
     *   away   — a good fix, neither place: report the metres from home
     *   coarse — a fix too vague to place him at all
     *   none   — no usable fix was sent
     * Returns null for rows recorded before this shipped, so callers keep their old wording.
     *
     * @param object|array $row attendance row
     */
    public static function startPlace($row): ?array
    {
        $g = fn ($k) => is_array($row) ? ($row[$k] ?? null) : ($row->$k ?? null);
        $verdict = $g('meter_start_gps');
        if ($verdict === null || $verdict === '') {
            return null; // pre-hardening row (or SQL not applied) — nothing measured to show
        }
        $dist = $g('meter_start_distance_m'); $dist = $dist !== null ? (int) $dist : null;
        $acc  = $g('meter_start_accuracy_m'); $acc  = $acc !== null ? (float) $acc : null;
        $off  = $g('meter_start_office_m');   $off  = $off !== null ? (int) $off : null;

        $fmt = fn ($m) => $m === null ? null : ($m < 1000 ? ($m . ' m') : (round($m / 1000, 1) . ' km'));

        switch ((string) $verdict) {
            case 'home':   $label = '🏠 at home'; break;
            case 'office': $label = '⌂ at office'; break;
            case 'away':   $label = '📍 ' . ($fmt($dist) ?? 'away') . ' from home'; break;
            case 'coarse': $label = '❓ location unclear' . ($acc !== null ? ' (±' . (int) round($acc) . ' m fix)' : ''); break;
            default:       $label = '❓ location unavailable'; break; // 'none'
        }
        return [
            'verdict'    => (string) $verdict,
            'label'      => $label,
            'distance_m' => $dist,
            'office_m'   => $off,
            'accuracy_m' => $acc,
        ];
    }

    /** login_time (TIME) + attendance_date → unix ts, null when absent. */
    public function loginTimestamp($att): ?int
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        $login = $g('login_time');
        if (!$login) {
            return null;
        }
        $date = substr((string) $g('attendance_date'), 0, 10);
        return strtotime($date . ' ' . $login) ?: null;
    }

    /**
     * Minutes past the work deadline: vs check-in when he arrived, vs "now" for a live
     * overdue journey. 0 when on time / not applicable.
     */
    public function minutesLate($att): ?int
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        if (!$g('work_expected_by')) {
            return null;
        }
        $expected = strtotime((string) $g('work_expected_by'));
        $ref = $this->loginTimestamp($att) ?? time();
        $over = $ref - $expected;
        return $over > 0 ? (int) round($over / 60) : 0;
    }

    /**
     * Morning continuity breach for a row: the START reading vs the last closing meter.
     *   - source 'home'      → breach when |gap| > METER_CONTINUITY_KM (bike slept at home)
     *   - source elsewhere   → breach when gap > the rider's overnight grace (existing rule;
     *                          the commute km are legitimately unmetered) — the CALLER passes
     *                          that grace in, since it is per-rider config on the web page.
     * Returns ['gap' => int, 'breach' => bool, 'prev' => int, 'prev_date' => ..] or null when
     * it can't be judged (no reading / no history).
     */
    public function continuity($att, ?float $officeGraceKm = null): ?array
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        $start = $g('meter_start');
        if ($start === null || $start === '') {
            return null;
        }
        $prev = $this->lastClosingMeter((int) $g('user_id'), substr((string) $g('attendance_date'), 0, 10));
        if (!$prev) {
            return null;
        }
        $gap = (int) $start - $prev['value'];
        $source = (string) ($g('meter_start_source') ?? '');
        if ($source === 'home') {
            $breach = abs($gap) > $this->continuityKm();
        } else {
            $breach = $officeGraceKm !== null && $gap > $officeGraceKm;
        }
        return ['gap' => $gap, 'breach' => $breach, 'prev' => $prev['value'], 'prev_date' => $prev['date']];
    }

    /** Is a manager check-in unlock active on this row right now? */
    public function checkinUnlockActive($att): bool
    {
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        $until = $g('checkin_unlock_until');
        return $until && strtotime((string) $until) >= time();
    }

    /**
     * Should the Phase-2 lock stop THIS check-in attempt right now? Only when:
     * lock config ON, rider is on the bike flow (caller checked the pin), and either
     *   (a) he armed a morning journey and is past work_expected_by, or
     *   (b) he never recorded a home start at all (the skip-the-meter dodge)
     * — and no manager unlock is active. NEVER true while the config is 0.
     */
    public function checkinLockVerdict($att, bool $hasHomeStart): array
    {
        if (!$this->lockEnabled()) {
            return ['locked' => false, 'why' => null];
        }
        if ($this->checkinUnlockActive($att)) {
            return ['locked' => false, 'why' => null];
        }
        $g = fn ($k) => is_array($att) ? ($att[$k] ?? null) : ($att->$k ?? null);
        $expected = $g('work_expected_by') ? strtotime((string) $g('work_expected_by')) : null;
        if ($expected !== null && time() > $expected) {
            return ['locked' => true, 'why' => 'late'];
        }
        if (!$hasHomeStart) {
            return ['locked' => true, 'why' => 'no_home_start'];
        }
        return ['locked' => false, 'why' => null];
    }

    /**
     * Morning issues on $date for the attendance Today page / banner. Per company-bike rider
     * (home pin) who CHECKED IN that day: arrived_late / no_home_start / continuity breach.
     * Riders who didn't come (leave/absent) are never flagged. Keyed by user_id.
     */
    public function workIssues(string $date, array $graceByUser = []): array
    {
        $out = [];
        try {
            if (!Schema::hasColumn('t_ops_attendance', 'work_expected_by')) {
                return $out;
            }
            $bikeUsers = DB::table('t_ops_rider_profile')
                ->where('company_bike', 1)->whereNotNull('home_latitude')
                ->pluck('user_id')->all();
            if (empty($bikeUsers)) {
                return $out;
            }
            $rows = DB::table('t_ops_attendance')
                ->whereIn('user_id', $bikeUsers)
                ->where('attendance_date', $date)
                ->whereNotNull('login_time')
                ->get(['id', 'user_id', 'attendance_date', 'login_time', 'meter_start', 'meter_start_source',
                       'meter_start_recorded_at', 'work_expected_by', 'work_eta_min', 'work_distance_km']);
            foreach ($rows as $r) {
                $state = $this->deriveState($r);
                $cont = $this->continuity($r, $graceByUser[$r->user_id] ?? null);
                $issues = [];
                if ($state === 'arrived_late') {
                    $issues[] = 'late_vs_eta';
                }
                if ($state === 'no_home_start') {
                    $issues[] = 'no_home_start';
                }
                if ($cont && $cont['breach']) {
                    $issues[] = 'meter_gap';
                }
                if (empty($issues)) {
                    continue;
                }
                $out[$r->user_id] = [
                    'attendance_id' => (int) $r->id,
                    'state'         => $state,
                    'issues'        => $issues,
                    'minutes_late'  => $this->minutesLate($r),
                    'expected_by'   => $r->work_expected_by ? substr((string) $r->work_expected_by, 11, 5) : null,
                    'continuity'    => $cont,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('workIssues failed (non-fatal)', ['date' => $date, 'error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /**
     * Check-IN violation days per user over a range (for the Month "In-flags (mo)" column):
     * any checked-in day with arrived_late / no_home_start / home-continuity breach.
     * Only company-bike riders with a pin are judged; everyone else returns no entry.
     * NOTE: the per-rider office overnight grace isn't loaded here (range query, kept cheap) —
     * office-entry gaps are judged by the Today page; this counts the home-flow violations.
     *
     * @return array [userId => ['days' => int, 'detail' => [date => [issue,..]]]]
     */
    public function workIssueDays(array $userIds, string $from, string $to): array
    {
        $out = [];
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (empty($userIds) || !Schema::hasColumn('t_ops_attendance', 'work_expected_by')) {
            return $out;
        }
        try {
            $bikeUsers = DB::table('t_ops_rider_profile')
                ->whereIn('user_id', $userIds)
                ->where('company_bike', 1)->whereNotNull('home_latitude')
                ->pluck('user_id')->all();
            if (empty($bikeUsers)) {
                return $out;
            }
            $rows = DB::table('t_ops_attendance')
                ->whereIn('user_id', $bikeUsers)
                ->whereBetween('attendance_date', [$from, $to])
                ->whereNotNull('login_time')
                ->orderBy('attendance_date')
                ->get(['id', 'user_id', 'attendance_date', 'login_time', 'meter_start', 'meter_start_source',
                       'meter_home', 'meter_end', 'work_expected_by']);

            // Continuity across the range without per-row queries: walk each rider's rows in
            // date order carrying the previous closing meter (seeded by ONE lookup per rider;
            // each day's own meter_home/meter_end is already in the row).
            $prevByUser = [];
            foreach ($rows as $r) {
                $uid = (int) $r->user_id;
                $date = substr((string) $r->attendance_date, 0, 10);
                if (!array_key_exists($uid, $prevByUser)) {
                    $seed = $this->lastClosingMeter($uid, $date);
                    $prevByUser[$uid] = $seed ? $seed['value'] : null;
                }
                $issues = [];
                $state = $this->deriveState($r);
                if ($state === 'arrived_late') { $issues[] = 'late_vs_eta'; }
                if ($state === 'no_home_start') { $issues[] = 'no_home_start'; }
                if ($r->meter_start !== null && $r->meter_start !== '' && $prevByUser[$uid] !== null
                    && (string) ($r->meter_start_source ?? '') === 'home'
                    && abs((int) $r->meter_start - $prevByUser[$uid]) > $this->continuityKm()) {
                    $issues[] = 'meter_gap';
                }
                if (!empty($issues)) {
                    if (!isset($out[$uid])) { $out[$uid] = ['days' => 0, 'detail' => []]; }
                    $out[$uid]['days']++;
                    $out[$uid]['detail'][$date] = $issues;
                }
                // Carry this day's closing meter forward for the next day's continuity.
                if ($r->meter_home !== null && $r->meter_home !== '') {
                    $prevByUser[$uid] = (int) $r->meter_home;
                } elseif ($r->meter_end !== null && $r->meter_end !== '') {
                    $prevByUser[$uid] = (int) $r->meter_end;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('workIssueDays failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

}
