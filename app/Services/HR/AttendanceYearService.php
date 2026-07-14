<?php

namespace App\Services\HR;

use App\Services\ShiftResolutionService;
use Illuminate\Support\Facades\DB;

/**
 * Absent-working-days + approved-leave-dates for a cycle window. The SINGLE definition
 * used by the web Month tab (AttendanceController) AND the rider app (RiderController), so
 * a rider's "absent this year" can never disagree with the manager's number.
 *
 * A date counts as absent only when dayKind==='working' (not off / holiday / before hire /
 * not-needed) AND the user was not on an approved leave AND had no login.
 */
class AttendanceYearService
{
    /** Absent-working COUNT in the cycle (cycle start → min(today, cycle end)). */
    public function yearAbsentDays(int $userId, ShiftResolutionService $shiftService, array $cycle, string $today): int
    {
        $yearEndEff = min($today, $cycle['end']);
        return count($this->absentWorkingDates($userId, $shiftService, $cycle['start'], $yearEndEff));
    }

    /** The exact absent-working DATES in [$start,$end] (count === size of this list). */
    public function absentWorkingDates(int $userId, ShiftResolutionService $shiftService, string $start, string $end): array
    {
        if ($start > $end) { return []; }
        $leaveDates = $this->approvedLeaveDates($userId, $start, $end);
        $presentDates = [];
        foreach (DB::table('t_ops_attendance')->where('user_id', $userId)
                    ->whereBetween('attendance_date', [$start, $end])
                    ->whereNotNull('login_time')->where('login_time', '!=', '')
                    ->pluck('attendance_date') as $ad) {
            $presentDates[substr((string) $ad, 0, 10)] = true;
        }
        $out = [];
        $cur = new \DateTime($start);
        $endO = new \DateTime($end);
        while ($cur <= $endO) {
            $ds = $cur->format('Y-m-d');
            if (!isset($presentDates[$ds]) && !isset($leaveDates[$ds])
                && $shiftService->dayKind($userId, $ds) === 'working') {
                $out[] = $ds;
            }
            $cur->modify('+1 day');
        }
        return $out;
    }

    /** Approved-leave dates (Y-m-d => leave_type) for a user, clamped to [$start,$end]. */
    public function approvedLeaveDates(int $userId, string $start, string $end): array
    {
        $dates = [];
        $leaves = DB::table('t_req_master as r')
            ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
            ->where('c.category_code', 'leave')
            ->where('r.requester_user_id', $userId)
            ->where('r.status', 'approved')
            ->where('r.leave_start_date', '<=', $end)
            ->where('r.leave_end_date', '>=', $start)
            ->select('r.leave_start_date', 'r.leave_end_date', 'r.leave_type')
            ->get();
        foreach ($leaves as $lv) {
            $ls = max($start, substr((string) $lv->leave_start_date, 0, 10));
            $le = min($end, substr((string) $lv->leave_end_date, 0, 10));
            for ($c = new \DateTime($ls); $c <= new \DateTime($le); $c->modify('+1 day')) {
                $dates[$c->format('Y-m-d')] = $lv->leave_type ?: 'Leave';
            }
        }
        return $dates;
    }
}
