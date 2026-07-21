<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;

/**
 * TARGET-BASED overtime for DISPLAY (Phase F preview / manager reporting): the minutes a
 * rider worked BEYOND the configured shift length (SHIFT_TARGET_HOURS). Worked = logout −
 * login on a day the rider actually checked out.
 *
 * NOTE: this is deliberately SEPARATE from `t_ops_attendance.overtime_minutes` (which is
 * shift-end based and feeds salary). Overtime here is NOT paid — it accumulates toward bonus
 * leaves (Phase F). Touching this never affects salary.
 */
class OvertimeService
{
    private ?float $targetHoursMemo = null;

    private function targetHours(): float
    {
        if ($this->targetHoursMemo === null) {
            try {
                $v = DB::table('t_fin_config')->where('config_key', 'SHIFT_TARGET_HOURS')->value('config_value');
                $this->targetHoursMemo = ($v !== null && $v !== '') ? (float) $v : 9.0;
            } catch (\Throwable $e) {
                $this->targetHoursMemo = 9.0;
            }
        }
        return $this->targetHoursMemo;
    }

    /** Overtime minutes for one day from its login/logout TIME strings (0 when not checked out). */
    public function dailyOvertimeMinutes(?string $login, ?string $logout): int
    {
        if (empty($login) || empty($logout)) {
            return 0;
        }
        $l = strtotime('2000-01-01 ' . $login);
        $o = strtotime('2000-01-01 ' . $logout);
        if ($l === false || $o === false) {
            return 0;
        }
        if ($o < $l) {
            $o += 86400; // logout after midnight → next day
        }
        $workedMin = ($o - $l) / 60;
        $targetMin = $this->targetHours() * 60;
        return $workedMin > $targetMin ? (int) round($workedMin - $targetMin) : 0;
    }

    /**
     * Overtime across [$start,$end]. Returns ['total'=>minutes, 'dates'=>[ 'Y-m-d'=>minutes ]].
     * Only days with BOTH a login and a logout count (you can't measure OT without a checkout).
     */
    public function overtimeForRange(int $userId, string $start, string $end): array
    {
        $total = 0;
        $dates = [];
        try {
            $rows = DB::table('t_ops_attendance')->where('user_id', $userId)
                ->whereBetween('attendance_date', [$start, $end])
                ->whereNotNull('login_time')->where('login_time', '!=', '')
                ->whereNotNull('logout_time')->where('logout_time', '!=', '')
                ->get(['attendance_date', 'login_time', 'logout_time']);
            // A HALF-DAY counts no overtime (owner's rule) — mirror the late/OT suppression in
            // sumLateOvertimeMinutes so target-OT (and its bonus-leave accrual) can't reward
            // a day the rider only half-worked.
            $halfDays = (new \App\Services\HR\LeavePolicyService())->halfDayDates($userId, $start, $end);
            foreach ($rows as $r) {
                $date = substr((string) $r->attendance_date, 0, 10);
                if (isset($halfDays[$date])) { continue; }
                $ot = $this->dailyOvertimeMinutes($r->login_time, $r->logout_time);
                if ($ot > 0) {
                    $total += $ot;
                    $dates[$date] = $ot;
                }
            }
            ksort($dates);
        } catch (\Throwable $e) { /* no data → zero */ }
        return ['total' => $total, 'dates' => $dates];
    }

    /** Just the total minutes across [$start,$end]. */
    public function overtimeMinutes(int $userId, string $start, string $end): int
    {
        return $this->overtimeForRange($userId, $start, $end)['total'];
    }
}
