<?php

namespace App\Services;

use App\Models\Ops\ShiftTemplateModel;
use App\Models\Ops\UserShiftAssignmentModel;
use App\Models\Ops\PublicHolidayModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShiftResolutionService
{
    /**
     * In-request memo of resolved shifts, keyed "ver|userId|date". A monthly report
     * resolves the same (user,date) many times; this avoids repeat cache/DB hits.
     * Cleared by clearUserShiftCache (per user) and clearAllShiftCaches (all).
     */
    private static array $shiftMemo = [];

    /**
     * Get the effective shift for a user on a specific date
     * 
     * Resolution order:
     * 1. Check user_shift_assignment (explicit user assignment)
     * 2. Fall back to old rider_profile.shift_start/end if not migrated
     * 3. Fall back to default shift template
     * 4. Fall back to hardcoded values
     * 
     * @param int $userId
     * @param string|null $date (Y-m-d format)
     * @return array ['shift_start' => '09:00', 'shift_end' => '17:00', 'working_days' => [1,2,3,4,5,6], 'shift_name' => '...', 'shift_id' => 1, 'source' => '...']
     */
    public function getUserShift(int $userId, ?string $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');
        
        // In-request memo first (a monthly report resolves the same (user,date) many
        // times); then the file cache (1h). The version segment lets clearAllShiftCaches()
        // invalidate every cached shift at once by bumping the version (no Cache::flush()).
        $ver = (int) Cache::get('shift_cache_ver', 1);
        $memoKey = "{$ver}|{$userId}|{$date}";
        if (isset(self::$shiftMemo[$memoKey])) {
            return self::$shiftMemo[$memoKey];
        }
        $cacheKey = "user_shift_v{$ver}_{$userId}_{$date}";

        $result = Cache::remember($cacheKey, 3600, function() use ($userId, $date) {
            // 1. Check user shift assignment (latest effective_from wins once
            //    history exists; the frozen "since forever" NULL-from row sorts last).
            $assignment = UserShiftAssignmentModel::with('shiftTemplate')
                ->where('user_id', $userId)
                ->effective($date)
                // Most specific / most recent wins: a bounded override (later
                // effective_from) beats the primary; a same-day tie → newest row (id).
                ->orderByRaw('effective_from IS NULL, effective_from DESC')
                ->orderByDesc('id')
                ->first();

            // NOTE: an inactive template is still honored here — history is immutable;
            // `active` only controls whether a template is offered for NEW assignments.
            if ($assignment && $assignment->shiftTemplate) {
                $shift = $assignment->shiftTemplate;
                $loc = $this->resolveLocation($userId, $assignment->location_id ? (int) $assignment->location_id : null);
                return [
                    'shift_start' => substr($shift->shift_start, 0, 5), // HH:MM format
                    'shift_end' => $shift->shift_end ? substr($shift->shift_end, 0, 5) : null,
                    'working_days' => $shift->getWorkingDaysArray(),
                    'shift_name' => $shift->shift_name,
                    'shift_id' => $shift->id,
                    'location_id' => $loc['location_id'],
                    'location_name' => $loc['location_name'],
                    'source' => 'user_assignment'
                ];
            }

            // 1b. Backward freeze (plan R1.2): no row is effective on $date, but if the
            //     user HAS assignment history and $date is BEFORE their earliest dated
            //     assignment, resolve to that earliest assignment's template (so months
            //     straddling a first-ever assignment stay consistent). Gaps and
            //     ended-assignments (removeShiftAssignment) fall through to default below.
            $earliest = UserShiftAssignmentModel::with('shiftTemplate')
                ->where('user_id', $userId)
                ->whereNotNull('effective_from')
                ->orderBy('effective_from', 'asc')
                ->first();

            if ($earliest && $earliest->shiftTemplate
                && $date < $earliest->effective_from->format('Y-m-d')) {
                $shift = $earliest->shiftTemplate;
                $loc = $this->resolveLocation($userId, $earliest->location_id ? (int) $earliest->location_id : null);
                return [
                    'shift_start' => substr($shift->shift_start, 0, 5),
                    'shift_end' => $shift->shift_end ? substr($shift->shift_end, 0, 5) : null,
                    'working_days' => $shift->getWorkingDaysArray(),
                    'shift_name' => $shift->shift_name,
                    'shift_id' => $shift->id,
                    'location_id' => $loc['location_id'],
                    'location_name' => $loc['location_name'],
                    'source' => 'earliest_backfill'
                ];
            }

            // No assignment covers this date → location falls back to the user's
            // default location, else the primary.
            $loc = $this->resolveLocation($userId, null);

            // 2. Fall back to old rider_profile system
            $riderProfile = DB::table('t_ops_rider_profile')
                ->where('user_id', $userId)
                ->where('migrated_to_shift_system', 0)
                ->first();

            if ($riderProfile && $riderProfile->shift_start && $riderProfile->shift_end) {
                return [
                    'shift_start' => substr($riderProfile->shift_start, 0, 5),
                    'shift_end' => substr($riderProfile->shift_end, 0, 5),
                    'working_days' => [1,3,4,5,6,7], // Hardcoded: exclude Tuesday (legacy default)
                    'shift_name' => 'Legacy Shift',
                    'shift_id' => null,
                    'location_id' => $loc['location_id'],
                    'location_name' => $loc['location_name'],
                    'source' => 'legacy_rider_profile'
                ];
            }

            // 3. Fall back to default shift
            $defaultShift = ShiftTemplateModel::getDefaultShift();
            if ($defaultShift) {
                return [
                    'shift_start' => substr($defaultShift->shift_start, 0, 5),
                    'shift_end' => $defaultShift->shift_end ? substr($defaultShift->shift_end, 0, 5) : null,
                    'working_days' => $defaultShift->getWorkingDaysArray(),
                    'shift_name' => $defaultShift->shift_name,
                    'shift_id' => $defaultShift->id,
                    'location_id' => $loc['location_id'],
                    'location_name' => $loc['location_name'],
                    'source' => 'default_shift'
                ];
            }

            // 4. Ultimate fallback (hardcoded)
            return [
                'shift_start' => '09:00',
                'shift_end' => '17:00',
                'working_days' => [1,2,3,4,5,6], // Mon-Sat
                'shift_name' => 'System Default',
                'shift_id' => null,
                'location_id' => $loc['location_id'],
                'location_name' => $loc['location_name'],
                'source' => 'hardcoded_fallback'
            ];
        });

        self::$shiftMemo[$memoKey] = $result;
        return $result;
    }

    /**
     * Resolve the office location that applies to a shift: the assignment's own
     * location (chosen at assign time) → the user's DEFAULT location
     * (t_ops_user_location_assignment) → the primary company location. Returns
     * ['location_id'=>?int, 'location_name'=>?string]. Cheap indexed lookups; runs
     * inside the cached getUserShift closure. A deleted/inactive location falls
     * through to the next level.
     */
    /** Public: a rider's DEFAULT office location (their assignment → primary), for the
     *  assign screens to pre-select. Returns ['location_id'=>?int,'location_name'=>?string]. */
    public function userDefaultLocation(int $userId): array
    {
        return $this->resolveLocation($userId, null);
    }

    private function resolveLocation(int $userId, ?int $assignmentLocationId): array
    {
        // 1) the location picked for this specific assignment (if still active)
        if ($assignmentLocationId) {
            $loc = DB::table('t_ops_company_locations')
                ->where('id', $assignmentLocationId)->where('is_active', 1)
                ->first(['id', 'location_name']);
            if ($loc) {
                return ['location_id' => (int) $loc->id, 'location_name' => $loc->location_name];
            }
        }
        // 2) the user's default location
        $def = DB::table('t_ops_user_location_assignment as ula')
            ->join('t_ops_company_locations as loc', 'loc.id', '=', 'ula.location_id')
            ->where('ula.user_id', $userId)->where('ula.is_active', 1)->where('loc.is_active', 1)
            ->first(['loc.id', 'loc.location_name']);
        if ($def) {
            return ['location_id' => (int) $def->id, 'location_name' => $def->location_name];
        }
        // 3) primary company location (or the first active one)
        $primary = DB::table('t_ops_company_locations')->where('is_active', 1)
            ->orderByDesc('is_primary')->orderBy('id')
            ->first(['id', 'location_name']);
        return $primary
            ? ['location_id' => (int) $primary->id, 'location_name' => $primary->location_name]
            : ['location_id' => null, 'location_name' => null];
    }

    /**
     * Calculate working days in a date range for a specific user
     * Excludes user's off days AND public holidays
     * 
     * @param int $userId
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return int
     */
    public function calculateWorkingDays(int $userId, string $startDate, string $endDate): int
    {
        // Get public holidays in this range
        $holidays = PublicHolidayModel::getHolidaysInRange($startDate, $endDate);

        // Iterate the range resolving the shift FOR EACH DATE. A mid-range shift
        // change with different off-days is now counted correctly per day; the
        // Phase-0 freeze makes past dates resolve to the shift that was actually in
        // effect then, so unchanged users' totals stay identical to before.
        $workingDays = 0;
        $currentDate = new \DateTime($startDate);
        $endDateObj = new \DateTime($endDate);

        while ($currentDate <= $endDateObj) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayOfWeek = (int)$currentDate->format('N'); // 1=Mon, 7=Sun

            $workingDaysOfWeek = $this->getUserShift($userId, $dateStr)['working_days'];

            // Check if it's a working day AND not a holiday
            if (in_array($dayOfWeek, $workingDaysOfWeek) && !in_array($dateStr, $holidays)) {
                $workingDays++;
            }

            $currentDate->modify('+1 day');
        }

        return $workingDays;
    }

    /**
     * Sum late + overtime minutes across a date range, resolving each day correctly.
     *
     * For each attendance day with a login: prefer the FROZEN snapshot
     * (late_minutes / overtime_minutes stamped at check-in) when present; otherwise
     * compute against the shift resolved FOR THAT DATE (not today's shift). This
     * replaces the old single-shift-for-the-whole-month calculation that silently
     * rewrote past lateness whenever a rider's shift changed.
     *
     * @return array ['late_minutes','overtime_minutes','late_days','overtime_days']
     */
    public function sumLateOvertimeMinutes(int $userId, string $startDate, string $endDate): array
    {
        $rows = DB::table('t_ops_attendance')
            ->where('user_id', $userId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->whereNotNull('login_time')
            ->where('login_time', '!=', '')
            ->get(['attendance_date', 'login_time', 'logout_time',
                   'expected_shift_start', 'expected_shift_end',
                   'late_minutes', 'overtime_minutes']);

        $totLate = 0; $totOt = 0; $lateDays = 0; $otDays = 0;

        foreach ($rows as $r) {
            $date = substr((string) $r->attendance_date, 0, 10);

            // --- Late ---
            if (!is_null($r->late_minutes)) {
                $late = (int) $r->late_minutes;
            } else {
                $start = $r->expected_shift_start
                    ?: (($this->getUserShift($userId, $date)['shift_start'] ?? '09:00') . ':00');
                $s = strtotime($date . ' ' . $start);
                $l = strtotime($date . ' ' . $r->login_time);
                // Truncate seconds to whole minutes — matches legacy TIMESTAMPDIFF(MINUTE).
                $late = ($l > $s) ? (int) (($l - $s) / 60) : 0;
            }
            if ($late > 0) { $totLate += $late; $lateDays++; }

            // --- Overtime (only when checked out AND the shift has an end time) ---
            if (!empty($r->logout_time)) {
                if (!is_null($r->overtime_minutes)) {
                    $ot = (int) $r->overtime_minutes;
                } else {
                    // Snapshot end (HH:MM:SS) → else the per-date resolved end (HH:MM).
                    // A start-only shift has NO end → no overtime (don't invent 17:00).
                    $endRaw = $r->expected_shift_end;
                    if (is_null($endRaw)) {
                        $ge = $this->getUserShift($userId, $date)['shift_end'] ?? null;
                        $endRaw = $ge ? ($ge . ':00') : null;
                    }
                    if ($endRaw) {
                        $e = strtotime($date . ' ' . $endRaw);
                        $o = strtotime($date . ' ' . $r->logout_time);
                        // Truncate seconds to whole minutes — matches legacy TIMESTAMPDIFF(MINUTE).
                        $ot = ($o > $e) ? (int) (($o - $e) / 60) : 0;
                    } else {
                        $ot = 0;
                    }
                }
                if ($ot > 0) { $totOt += $ot; $otDays++; }
            }
        }

        return [
            'late_minutes' => $totLate,
            'overtime_minutes' => $totOt,
            'late_days' => $lateDays,
            'overtime_days' => $otDays,
        ];
    }

    /**
     * Get shift info for multiple users at once (bulk operation)
     * Returns array keyed by user_id
     * 
     * @param array $userIds
     * @param string|null $date
     * @return array
     */
    public function getUserShiftsBulk(array $userIds, ?string $date = null): array
    {
        $shifts = [];
        foreach ($userIds as $userId) {
            $shifts[$userId] = $this->getUserShift($userId, $date);
        }
        return $shifts;
    }

    /**
     * Check if a specific date is a working day for a user
     * 
     * @param int $userId
     * @param string $date (Y-m-d)
     * @return bool
     */
    public function isWorkingDay(int $userId, string $date): bool
    {
        // Get user's shift
        $shift = $this->getUserShift($userId, $date);
        
        // Check day of week
        $dayOfWeek = (int)(new \DateTime($date))->format('N');
        if (!in_array($dayOfWeek, $shift['working_days'])) {
            return false;
        }
        
        // Check if it's a public holiday
        if (PublicHolidayModel::isHoliday($date)) {
            return false;
        }
        
        return true;
    }

    /**
     * Freeze the day's expected shift + late/overtime minutes onto the attendance
     * row, so a later shift change can never rewrite this day's history.
     *
     * Idempotent: safe to call at check-in (fills late), check-out (fills overtime),
     * and on manual attendance entry. Resolves the shift FOR THE RECORD'S DATE.
     * Callers MUST wrap this in try/catch — it must never break check-in/out.
     *
     * @param int    $userId
     * @param string $date  (Y-m-d)
     */
    public function stampAttendanceSnapshot(int $userId, string $date): void
    {
        $row = DB::table('t_ops_attendance')
            ->where('user_id', $userId)
            ->whereDate('attendance_date', $date)
            ->first();

        if (!$row) {
            return;
        }

        $shift = $this->getUserShift($userId, $date);
        $start = $shift['shift_start'] ?? null; // 'HH:MM'
        $end   = $shift['shift_end'] ?? null;

        $update = [
            'expected_shift_start' => $start ? $start . ':00' : null,
            'expected_shift_end'   => $end ? $end . ':00' : null,
            'shift_template_id'    => $shift['shift_id'] ?? null,
        ];

        // Late minutes — only when checked in. Same-day comparison, matching the
        // existing report logic (keeps snapshot numbers identical to current reports).
        if (!empty($row->login_time) && $start) {
            $s = strtotime($date . ' ' . $start);
            $l = strtotime($date . ' ' . $row->login_time);
            // Truncate seconds to whole minutes — matches legacy TIMESTAMPDIFF(MINUTE).
            $update['late_minutes'] = ($l > $s) ? (int) (($l - $s) / 60) : 0;
        }

        // Overtime — needs an end time. A start-only shift has NO overtime, so clear
        // any stale value (e.g. after switching a day from an end-having shift to a
        // start-only one). Otherwise compute only when checked out.
        if (!$end) {
            $update['overtime_minutes'] = null;
        } elseif (!empty($row->logout_time)) {
            $e = strtotime($date . ' ' . $end);
            $o = strtotime($date . ' ' . $row->logout_time);
            $update['overtime_minutes'] = ($o > $e) ? (int) (($o - $e) / 60) : 0;
        }

        DB::table('t_ops_attendance')
            ->where('id', $row->id)
            ->update($update);
    }

    /**
     * Re-stamp snapshots for every attendance day in a date range. Used after a
     * BACK-DATED shift assignment so that days already stamped (at check-in, against
     * the old/default shift) get recomputed against the newly-assigned shift — this is
     * what makes "assign the correct old shift → the reports fix themselves" true for
     * late/overtime, not just working days. Idempotent.
     *
     * IMPORTANT: the caller must clear this user's shift cache BEFORE calling, so the
     * per-date resolution inside stampAttendanceSnapshot reflects the new assignment.
     *
     * @return int number of days re-stamped
     */
    public function restampRange(int $userId, string $startDate, string $endDate): int
    {
        $dates = DB::table('t_ops_attendance')
            ->where('user_id', $userId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->whereNotNull('login_time')
            ->where('login_time', '!=', '')
            ->pluck('attendance_date');

        $count = 0;
        foreach ($dates as $d) {
            $this->stampAttendanceSnapshot($userId, substr((string) $d, 0, 10));
            $count++;
        }

        return $count;
    }

    /**
     * Clear cache for a user's shift (call after updating shifts or assignments)
     *
     * @param int $userId
     */
    public function clearUserShiftCache(int $userId): void
    {
        // Clear this user's cached shift entries around today (version-aware keys).
        $ver = (int) Cache::get('shift_cache_ver', 1);
        for ($i = -30; $i <= 30; $i++) {
            $date = now()->addDays($i)->format('Y-m-d');
            Cache::forget("user_shift_v{$ver}_{$userId}_{$date}");
        }

        // Also drop this user's in-request memo entries.
        foreach (array_keys(self::$shiftMemo) as $k) {
            if (str_contains($k, "|{$userId}|")) {
                unset(self::$shiftMemo[$k]);
            }
        }

        Log::info("Cleared shift cache for user {$userId}");
    }

    /**
     * Clear all shift caches (call after creating/updating shift templates)
     */
    public function clearAllShiftCaches(): void
    {
        // Bump a version counter instead of flushing the WHOLE application cache
        // (Cache::flush() also wiped dashboard/report caches on every template edit).
        // getUserShift() embeds this version in its cache key, so one bump
        // invalidates every cached shift without touching unrelated caches.
        $ver = (int) Cache::get('shift_cache_ver', 1);
        Cache::forever('shift_cache_ver', $ver + 1);
        self::$shiftMemo = [];

        Log::info('Bumped shift cache version to ' . ($ver + 1));
    }

    /**
     * Get a summary of shift distribution across users
     * Useful for reports and dashboards
     * 
     * @return array
     */
    public function getShiftDistributionSummary(): array
    {
        $summary = [];
        
        // Get all shift templates
        $shifts = ShiftTemplateModel::active()->get();
        
        foreach ($shifts as $shift) {
            $summary[] = [
                'shift_id' => $shift->id,
                'shift_name' => $shift->shift_name,
                'assigned_users' => $shift->currentUserAssignments()->count(),
                'working_days_count' => $shift->getWorkingDaysCount(),
                'hours' => substr($shift->shift_start, 0, 5) . ' - ' . substr($shift->shift_end, 0, 5)
            ];
        }
        
        // Add legacy users count
        $legacyCount = DB::table('t_ops_rider_profile')
            ->where('migrated_to_shift_system', 0)
            ->whereNotNull('shift_start')
            ->count();
        
        if ($legacyCount > 0) {
            $summary[] = [
                'shift_id' => null,
                'shift_name' => 'Legacy (Not Migrated)',
                'assigned_users' => $legacyCount,
                'working_days_count' => 6,
                'hours' => 'Various'
            ];
        }
        
        return $summary;
    }
}



