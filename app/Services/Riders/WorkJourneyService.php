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

    /** How far back to look for a usable closing reading before giving up. */
    const CLOSING_LOOKBACK_ROWS = 60;

    /**
     * ⭐⭐ PHASE D — THE CONTINUITY BASELINE, KEYED TO THE **MACHINE**.
     *
     * THE BUG THIS FIXES. An odometer is a fact about a bike, but this check has
     * always compared a rider's morning reading against HIS OWN last close. The
     * day a rider swaps machines those are two different odometers, so the gap is
     * thousands of kilometres and he is met with *"your reading is LOWER, which
     * should be impossible"* — for a perfectly honest reading. The 1 km threshold
     * is deliberately tight (it is what catches real typos) so a swap could never
     * pass it. Comparing against the MACHINE's own last reading makes the same
     * check correct across a handover: whoever rode it last, that is where the
     * odometer stood.
     *
     * @return null|array{value:int, date:?string, label:?string, source:string}
     *   `label` is the plate/nickname when the baseline came from the machine, so
     *   the message can name it. Null = nothing to compare against; every caller
     *   already treats that as "ask no question" (a brand-new bike, correctly).
     *
     * ⚠ A machine with NO history returns null rather than falling back to the
     *   rider's old bike — silence beats comparing against the wrong odometer,
     *   which is the very failure being fixed.
     */
    public function continuityBaseline(int $userId, string $beforeDate): ?array
    {
        try {
            $resolver = new VehicleResolver();
            if ($resolver->rulesEnabled() && $resolver->available()) {
                $vid = $resolver->currentVehicleFor($userId, $beforeDate);
                if ($vid) {
                    $win   = (new VehicleService())->meterWindowFor($vid, $beforeDate);
                    $floor = $win['floor'] ?? null;
                    return $floor === null ? null : [
                        'value'  => (int) $floor,
                        'date'   => null,
                        'label'  => $resolver->labelFor($vid),
                        'source' => 'vehicle',
                    ];
                }
                // He holds nothing: there is no machine whose continuity to check.
                if ($resolver->hasRiderProfile($userId)) return null;
            }
        } catch (\Throwable $e) {
            Log::warning('continuityBaseline fell back to the rider chain', [
                'user' => $userId, 'error' => $e->getMessage(),
            ]);
        }

        $prev = $this->lastClosingMeter($userId, $beforeDate);
        return $prev === null ? null
            : ['value' => (int) $prev['value'], 'date' => $prev['date'], 'label' => null, 'source' => 'rider'];
    }

    /**
     * The sentence shown when a morning reading is too far from the baseline.
     * One copy, so the three gates that ask cannot drift apart in wording.
     */
    public static function continuityMessage(array $base, int $gapKm): string
    {
        $whose = !empty($base['label'])
            ? $base['label'] . '\'s last reading was ' . number_format($base['value'])
            : 'Last night\'s meter was ' . number_format($base['value']);

        return $gapKm >= 0
            ? $whose . ' — your reading is ' . number_format($gapKm) . ' km higher. '
              . 'Check the meter again, or confirm if it is correct.'
            : $whose . ' — your reading is LOWER, which should be impossible. '
              . 'Check it again, or confirm to send it to your manager.';
    }

    /** A day's ride above this is a typo, not a commute (meter_end / meter_home caps). */
    const MAX_DAY_RUN_KM  = 500;
    const MAX_HOME_RUN_KM = 700;

    /**
     * The last recorded day-closing meter before $date for this rider — meter_home when the
     * proper home flow closed the day, else meter_end. Used for the morning continuity check
     * AND for showing "prev end" context. Returns ['value' => int, 'date' => 'Y-m-d'] or null.
     *
     * ⭐ SKIPS UNUSABLE ROWS (Aug-2026). It used to take the most recent row whatever it
     * said, so one junk value became "last night's meter" and produced a message nobody
     * could act on: Danish's 2026-07-24 row has meter_end = 0, which is why entering a real
     * 33,590 read back as *"Last night's meter was 0 — your reading is 33,590 km higher."*
     * Now such a row is passed over for the most recent one that can actually be read.
     *
     * ⚠ DELIBERATELY NOT the >1000 floor that FuelClaimRules::odometerWindow() and
     *   FleetFuelService::currentMeters() use. That floor assumes 5-figure odometers, but a
     *   NEW bike genuinely reads in the hundreds — Asim ran 165 → 931 km across April 2026 in
     *   a clean ascending sequence, and Danish's May rows do the same. Applying the floor here
     *   would silently return null for those riders and switch their morning continuity check
     *   OFF, which is worse than the message it was fixing. Only two things are refused:
     *     • a closing value of 0 or less — that is "nothing was recorded", never an odometer
     *     • an impossible day's run against a usable start (Arslan 26,261 → 56,403 = +30,142)
     *   Anything else is returned exactly as before, so a clean rider is unaffected.
     *
     * Measured over the replica's 853 rider-days: 850 identical, and the 3 that moved are
     * Arslan's typo, Danish's zero, and Shabib's 2026-01-30 row (start 100 / end 2,686 while
     * his bike reads ~4,000 — junk on both sides, so no usable close exists and this returns
     * NULL). ⚠ NULL means "cannot be measured", NOT "no gap": callers already treat it that
     * way (continuity() returns null, the morning gate asks for no confirmation). Returning a
     * number we know to be wrong is what produced the unactionable message in the first place.
     */
    public function lastClosingMeter(int $userId, string $beforeDate): ?array
    {
        try {
            $rows = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', '<', $beforeDate)
                ->where(function ($q) {
                    $q->whereNotNull('meter_home')->orWhereNotNull('meter_end');
                })
                ->orderByDesc('attendance_date')
                ->limit(self::CLOSING_LOOKBACK_ROWS)
                ->get(['attendance_date', 'meter_start', 'meter_home', 'meter_end']);

            foreach ($rows as $row) {
                $val = self::usableClosing($row);
                if ($val !== null) {
                    return ['value' => $val, 'date' => substr((string) $row->attendance_date, 0, 10)];
                }
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The closing reading on one attendance row, or null when it cannot be trusted.
     * meter_home wins over meter_end when both are present (the home flow is the
     * proper close) — but a junk meter_home now falls through to meter_end instead
     * of poisoning the answer.
     */
    private static function usableClosing($row): ?int
    {
        $start = (isset($row->meter_start) && is_numeric($row->meter_start)) ? (int) $row->meter_start : null;

        foreach (['meter_home' => self::MAX_HOME_RUN_KM, 'meter_end' => self::MAX_DAY_RUN_KM] as $col => $cap) {
            $raw = $row->$col ?? null;
            if ($raw === null || $raw === '' || !is_numeric($raw)) {
                continue;
            }
            $v = (int) $raw;
            if ($v <= 0) {
                continue;                       // 0 = never recorded, not an odometer at zero
            }
            if ($start !== null && $start > 0) {
                $run = $v - $start;
                if ($run < 0 || $run > $cap) {
                    continue;                   // the day cannot have happened
                }
            }
            return $v;
        }
        return null;
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
        $userId = (int) $g('user_id');
        $date   = substr((string) $g('attendance_date'), 0, 10);

        // ⭐⭐ Aug-2026 — JUDGE THE MACHINE, NOT THE MAN.
        //
        // This is the MANAGER-FACING verdict (the attendance sheet's red meter-gap
        // flag and `workIssues`), and it was the last continuity surface still
        // chaining a RIDER's own readings. The morning gates were repointed at the
        // machine in Phase D; this one was not, so the morning after a handover the
        // sheet compared a rider's first reading on his new bike against his last
        // reading on the old one and flagged him for a gap that was never his.
        //
        // ⚠ DATE-scoped (`vehicleForDay`), because a report judges history — the
        //   live gates use `currentVehicleFor` for the opposite reason.
        $prev = null;
        $label = null;
        $transferDay = false;
        try {
            $resolver = new VehicleResolver();
            if ($resolver->rulesEnabled() && $resolver->available()) {
                $vid = $resolver->vehicleForDay($userId, $date);
                if ($vid) {
                    // ⭐ A HANDOVER DAY IS NOT A BREACH. He collected the bike from
                    //   wherever it was — from the other rider's home, from the
                    //   workshop — and that travel is legitimate. Suppressing here
                    //   matches what `workIssueDays` and `DayChecksService` already
                    //   do for the same days; this surface simply never learned it.
                    $transferDay = $resolver->isTransferDay($vid, $date);
                    $win   = (new VehicleService())->meterWindowFor($vid, $date);
                    $floor = $win['floor'] ?? null;
                    if ($floor !== null) {
                        $prev  = ['value' => (int) $floor, 'date' => null];
                        $label = $resolver->labelFor($vid);
                    }
                }
                // He held nothing that day: there is no machine to check against.
                if (!$vid && $resolver->hasRiderProfile($userId)) return null;
            }
        } catch (\Throwable $e) {
            Log::warning('continuity fell back to the rider chain', [
                'user' => $userId, 'date' => $date, 'error' => $e->getMessage(),
            ]);
            $prev = null;
        }

        if (!$prev) {
            $prev = $this->lastClosingMeter($userId, $date);
        }
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
        // ⭐⭐ ACROSS A HANDOVER THERE IS NOTHING TO CHECK — suppressed, not graced.
        //
        // A km ALLOWANCE (the 20 km the attendance page adds) is the right shape for
        // the handover RIDE: he fetches the bike from the other man's house. It is
        // the wrong shape here, because the baseline reading may be hours or a full
        // working day old and belong to the OTHER RIDER: Waseem's first reading back
        // on DCR-799 sat 202 km above the machine's last, and every one of those
        // kilometres was Danish's. Grace by 20 and he is still accused of 182.
        //
        // Continuity asks "did this bike move overnight while HE had it?" — between
        // two different riders that question has no meaning, so it is not asked. The
        // distance is not lost: the engine labels it shared/in-transit and shows it
        // on the day, against nobody.
        if ($transferDay) $breach = false;

        return ['gap' => $gap, 'breach' => $breach, 'prev' => $prev['value'],
                'prev_date' => $prev['date'] ?? null,
                // So the sheet can say WHY it is not complaining.
                'transfer_day' => $transferDay, 'label' => $label];
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
            // ⭐ Phase C: whoever was on a company machine on THIS date (registry
            //    first, profile checkbox as the fallback population).
            $companySet = array_flip((new VehicleResolver())->companyRiderIdsFor($date));
            $bikeUsers = DB::table('t_ops_rider_profile')
                ->whereNotNull('home_latitude')
                ->pluck('user_id')
                ->filter(fn ($uid) => isset($companySet[(int) $uid]))
                ->values()->all();
            if (empty($bikeUsers)) {
                return $out;
            }
            $rows = DB::table('t_ops_attendance')
                ->whereIn('user_id', $bikeUsers)
                ->where('attendance_date', $date)
                ->whereNotNull('login_time')
                ->get(['id', 'user_id', 'attendance_date', 'login_time', 'meter_start', 'meter_start_source',
                       'meter_start_recorded_at', 'work_expected_by', 'work_eta_min', 'work_distance_km']);
            // ⭐ Days a machine changed hands, per rider. On those days the bike did
            //   not sleep at his house, so "no home start" describes the handover
            //   rather than a rider cutting a corner — flagging it is a false
            //   accusation. ⚠ REPORTING ONLY: `checkinLockVerdict` still refuses a
            //   missing home start, because removing a demand and removing a LOCK
            //   are different risks.
            $swapDays = $this->assignmentBoundaryDays($bikeUsers, $date, $date);

            foreach ($rows as $r) {
                $state = $this->deriveState($r);
                $cont = $this->continuity($r, $graceByUser[$r->user_id] ?? null);
                $onSwap = isset($swapDays[$r->user_id . '|' . substr((string) $r->attendance_date, 0, 10)]);
                $issues = [];
                if ($state === 'arrived_late') {
                    $issues[] = 'late_vs_eta';
                }
                if ($state === 'no_home_start' && !$onSwap) {
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

            // ⭐ PHASE D — the days each rider's MACHINE changed. On a handover day the
            //   morning reading belongs to a different odometer than last night's close,
            //   so a gap there is the handover, not a discrepancy. Flagging it sent the
            //   manager chasing a rider who had done nothing wrong. ONE query for all
            //   riders, never per row (this method runs over a whole month).
            $swapDays = $this->assignmentBoundaryDays($bikeUsers);

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
                    && empty($swapDays[$uid . '|' . $date])
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

    /**
     * Every "userId|date" on which one of these riders started or ended holding a
     * machine. A lookup set, so a month-long walk costs ONE query.
     *
     * ⚠ Both ends matter: the day he GIVES a bike up and the day he TAKES one are
     *   each a day with two odometers in play.
     *
     * ⭐ Aug-2026: made PUBLIC and date-bounded so it is the ONE definition of
     *   "was this a handover day for this rider". `VehicleController::riderDays`
     *   used to carry its own SQL copy of the same idea, with a comment promising
     *   it matched — three copies of one rule is how they drift apart.
     */
    public function assignmentBoundaryDays(array $userIds, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        try {
            $res = new VehicleResolver();
            if (!$res->available() || !$res->rulesEnabled() || empty($userIds)) return $out;

            $q = DB::table(VehicleService::T_ASSIGN)->whereIn('user_id', $userIds);
            if ($from !== null && $to !== null) {
                $q->where(function ($w) use ($from, $to) {
                    $w->whereBetween('assigned_on', [$from, $to])
                      ->orWhereBetween('released_on', [$from, $to]);
                });
            }
            foreach ($q->get(['user_id', 'assigned_on', 'released_on']) as $a) {
                foreach ([$a->assigned_on, $a->released_on] as $d) {
                    if (!$d) continue;
                    $day = substr((string) $d, 0, 10);
                    if ($from !== null && ($day < $from || $day > $to)) continue;
                    $out[(int) $a->user_id . '|' . $day] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('assignmentBoundaryDays failed (non-fatal)', ['error' => $e->getMessage()]);
        }
        return $out;
    }
}
