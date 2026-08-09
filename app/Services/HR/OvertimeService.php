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

    /** 'uid|date' → last-delivered epoch (or null). A month walk asks per day. */
    private array $lastDeliveryMemo = [];

    private ?bool $unlockColMemo = null;

    /** Is the manager-unlock column deployed? (Guarded — dev DBs may lag.) */
    public function hasUnlockColumn(): bool
    {
        if ($this->unlockColMemo === null) {
            try {
                $this->unlockColMemo = \Illuminate\Support\Facades\Schema::hasColumn(
                    't_ops_attendance', 'checkout_unlock_until');
            } catch (\Throwable $e) {
                $this->unlockColMemo = false;
            }
        }
        return $this->unlockColMemo;
    }

    /**
     * ⭐ DID THIS CHECKOUT RIDE A MANAGER UNLOCK? (owner ruling, Aug-8)
     *
     * TIGHT on purpose: the logout must fall INSIDE the unlock window. The
     * "bypassed" chip on the attendance page uses a looser test (unlock row +
     * any logout), which also matches a rider who was unlocked at 14:00 but then
     * checked out NORMALLY at 21:30 after the unlock expired — and the owner's
     * rule is explicit that a regular checkout, wherever it happens, keeps its
     * real time. Only a checkout that actually USED the valve is re-based.
     *
     * Logout is a bare TIME on the row; past-midnight rolls to the next day
     * (same rule as dailyOvertimeMinutes). 2 min slack for clock skew.
     */
    public function bypassedCheckout(string $date, ?string $login, ?string $logout, $unlockUntil): bool
    {
        if (empty($logout) || empty($unlockUntil)) {
            return false;
        }
        $o = strtotime($date . ' ' . $logout);
        if ($o === false) {
            return false;
        }
        if (!empty($login)) {
            $l = strtotime($date . ' ' . $login);
            if ($l !== false && $o < $l) {
                $o += 86400;
            }
        }
        $u = strtotime((string) $unlockUntil);
        return $u !== false && $o <= $u + 120;
    }

    /**
     * When this rider DELIVERED his last order that day (epoch), or null.
     * The same join CheckoutClassifierService trusts for "away on a delivery".
     */
    public function lastDeliveryTs(int $userId, string $date): ?int
    {
        $key = $userId . '|' . $date;
        if (array_key_exists($key, $this->lastDeliveryMemo)) {
            return $this->lastDeliveryMemo[$key];
        }
        $ts = null;
        try {
            $t = DB::table('t_crm_order_status_history as h')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                ->where('o.assigned_rider_user_id', $userId)
                ->where('h.status_code', 'delivered')
                ->whereDate('h.changed_at', $date)
                ->max('h.changed_at');
            $ts = $t ? (strtotime((string) $t) ?: null) : null;
        } catch (\Throwable $e) {
            $ts = null;
        }
        return $this->lastDeliveryMemo[$key] = $ts;
    }

    /**
     * ⭐⭐ THE OT-COUNTABLE END OF A DAY (epoch), owner ruling Aug-8.
     *
     * A normal checkout — at the office, at the last drop, wherever — keeps its
     * real time: that is the day as worked. A checkout that rode a manager
     * unlock is different: the timestamp is when the VALVE was used, not when
     * the work ended (the live case: a rider bypassed at 00:05 was credited
     * 13h13m and ~4h of bonus-leave overtime for a day that ended with his last
     * drop hours earlier). For those days the end becomes the LAST DELIVERED
     * ORDER — "that's when he was supposed to" — clamped to never exceed the
     * actual logout. A bypassed day with NO deliveries returns null: nothing
     * provable happened past the shift, so nothing is counted.
     */
    public function otEndTs(int $userId, string $date, ?string $login, ?string $logout, $unlockUntil): ?int
    {
        if (empty($logout)) {
            return null;
        }
        $o = strtotime($date . ' ' . $logout);
        if ($o === false) {
            return null;
        }
        if (!empty($login)) {
            $l = strtotime($date . ' ' . $login);
            if ($l !== false && $o < $l) {
                $o += 86400;
            }
        }

        if (!$this->bypassedCheckout($date, $login, $logout, $unlockUntil)) {
            return $o;                        // a real checkout keeps its real time
        }

        $last = $this->lastDeliveryTs($userId, $date);
        if ($last === null) {
            return null;                      // bypassed + nothing delivered → no measurable OT
        }
        return min($last, $o);                // never later than the actual logout
    }

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
            $cols = ['attendance_date', 'login_time', 'logout_time'];
            if ($this->hasUnlockColumn()) {
                $cols[] = 'checkout_unlock_until';   // the bypass tell (guarded — dev DBs may lag)
            }
            $rows = DB::table('t_ops_attendance')->where('user_id', $userId)
                ->whereBetween('attendance_date', [$start, $end])
                ->whereNotNull('login_time')->where('login_time', '!=', '')
                ->whereNotNull('logout_time')->where('logout_time', '!=', '')
                ->get($cols);
            // A HALF-DAY counts no overtime (owner's rule) — mirror the late/OT suppression in
            // sumLateOvertimeMinutes so target-OT (and its bonus-leave accrual) can't reward
            // a day the rider only half-worked.
            $halfDays = (new \App\Services\HR\LeavePolicyService())->halfDayDates($userId, $start, $end);
            foreach ($rows as $r) {
                $date = substr((string) $r->attendance_date, 0, 10);
                if (isset($halfDays[$date])) { continue; }
                // ⭐ The day ends when the WORK ended (owner ruling Aug-8): a checkout
                //   that rode a manager unlock is re-based to the last delivered order;
                //   a normal checkout keeps its real time. null = nothing countable.
                $endTs = $this->otEndTs($userId, $date, $r->login_time, $r->logout_time,
                                        $r->checkout_unlock_until ?? null);
                if ($endTs === null) { continue; }
                $l = strtotime($date . ' ' . $r->login_time);
                if ($l === false || $endTs <= $l) { continue; }
                $workedMin = ($endTs - $l) / 60;
                $targetMin = $this->targetHours() * 60;
                $ot = $workedMin > $targetMin ? (int) round($workedMin - $targetMin) : 0;
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

    /**
     * Overtime minutes → whole BONUS LEAVE DAYS. One full target day of extra work earns
     * one day off; the remainder does not carry over.
     *
     * ⭐ THE one implementation. PayrollService::grantOnce() writes leave grants from this,
     * and every attendance/report screen displays this — so what a manager is shown and what
     * payroll actually grants cannot drift apart. If the rule ever changes (part-days,
     * carry-over, a different divisor), change it HERE and nowhere else.
     */
    public function bonusLeaves(int $overtimeMinutes): int
    {
        if ($overtimeMinutes <= 0) { return 0; }
        $perDay = max(1, (int) round($this->targetHours() * 60));
        return (int) floor($overtimeMinutes / $perDay);
    }

    /** Minutes still to run before the NEXT bonus day (0 when none are being earned). */
    public function minutesToNextBonus(int $overtimeMinutes): int
    {
        if ($overtimeMinutes <= 0) { return 0; }
        $perDay = max(1, (int) round($this->targetHours() * 60));
        return $perDay - ($overtimeMinutes % $perDay);
    }

    /** The divisor itself, so a screen can explain "540 min = 1 day" without re-deriving it. */
    public function minutesPerBonusDay(): int
    {
        return max(1, (int) round($this->targetHours() * 60));
    }
}
