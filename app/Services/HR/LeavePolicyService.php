<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for a rider's leave policy — quota, balance, and the
 * same-day-application cap. The web attendance page, the mobile rider app, and both
 * leave-application validators (web + mobile) all call this so the numbers can never
 * disagree.
 *
 * MODEL (owner-confirmed 2026-07-12): ONE pool of leaves (default 10) per year cycle.
 * "Emergency" is NOT a separate balance — it just means a SAME-DAY application, and those
 * are capped per cycle (default 4, before a cutoff time) so people don't all apply in the
 * morning. Same-day leaves draw from the same single pool.
 *
 * Fully guarded: works before the Phase-E SQL (t_hr_leave_grant) is run — a missing table
 * just means zero adjustments.
 */
class LeavePolicyService
{
    private static ?bool $hasGrantTable = null;

    /** t_fin_config read with a default; never throws. */
    private function config(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** The configured yearly cycle (mirrors AttendanceController::attendanceCycle). */
    public function cycle(): array
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
        } catch (\Throwable $ex) { /* fall through */ }
        $y = date('Y');
        return ['start' => "$y-01-01", 'end' => "$y-12-31"];
    }

    public function quotaTotal(): float   { return (float) $this->config('LEAVE_QUOTA_TOTAL', 10); }
    public function samedayCap(): int     { return (int) $this->config('LEAVE_SAMEDAY_CAP', 4); }
    public function samedayCutoff(): string { return (string) $this->config('LEAVE_SAMEDAY_CUTOFF', '10:00'); }

    private function grantTableExists(): bool
    {
        if (self::$hasGrantTable === null) {
            try { self::$hasGrantTable = Schema::hasTable('t_hr_leave_grant'); }
            catch (\Throwable $e) { self::$hasGrantTable = false; }
        }
        return self::$hasGrantTable;
    }

    /**
     * Approved leave DAYS taken in [start,end], counted per distinct date so overlapping
     * requests can't double-count — the exact definition the Month tab / drill-down use.
     */
    public function takenDays(int $userId, string $start, string $end): float
    {
        $dates = [];
        try {
            $rows = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $userId)
                ->where('r.status', 'approved')
                ->where('r.leave_start_date', '<=', $end)
                ->where('r.leave_end_date', '>=', $start)
                ->select('r.leave_start_date', 'r.leave_end_date')
                ->get();
            foreach ($rows as $lv) {
                $ls = max($start, substr((string) $lv->leave_start_date, 0, 10));
                $le = min($end, substr((string) $lv->leave_end_date, 0, 10));
                for ($c = new \DateTime($ls); $c <= new \DateTime($le); $c->modify('+1 day')) {
                    $dates[$c->format('Y-m-d')] = true;
                }
            }
        } catch (\Throwable $e) { /* no leaves */ }
        return (float) count($dates);
    }

    /**
     * How many SAME-DAY (emergency) applications the rider has made this cycle — the cap
     * counter. Counts REQUESTS (rows), not days, and includes pending ones (a pending
     * same-day request has already used a slot). Marked by leave_type='emergency' at apply.
     */
    public function samedayUsed(int $userId, string $start, string $end): int
    {
        try {
            return (int) DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'leave')
                ->where('r.requester_user_id', $userId)
                ->where('r.leave_type', 'emergency')
                ->whereIn('r.status', ['approved', 'pending'])
                ->whereBetween('r.leave_start_date', [$start, $end])
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Net ledger adjustment (grants − penalties) applying to the cycle. */
    public function ledgerAdjustment(int $userId, string $start, string $end): float
    {
        if (!$this->grantTableExists()) { return 0.0; }
        try {
            // A row belongs to the cycle by its effective_date, else by created_at date.
            return (float) DB::table('t_hr_leave_grant')
                ->where('user_id', $userId)
                ->whereRaw('COALESCE(effective_date, DATE(created_at)) BETWEEN ? AND ?', [$start, $end])
                ->sum('days');
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Full balance snapshot for a rider in the CURRENT cycle. Every surface renders from
     * this so the number is identical everywhere.
     */
    public function balance(int $userId): array
    {
        $cycle = $this->cycle();
        $quota = $this->quotaTotal();
        $extra = $this->ledgerAdjustment($userId, $cycle['start'], $cycle['end']);
        $taken = $this->takenDays($userId, $cycle['start'], $cycle['end']);
        $samedayUsed = $this->samedayUsed($userId, $cycle['start'], $cycle['end']);
        $samedayCap = $this->samedayCap();

        $effectiveQuota = $quota + $extra;          // extra can be negative (penalties)
        $remaining = $effectiveQuota - $taken;

        return [
            'quota_total'      => round($quota, 1),
            'extra_granted'    => round($extra, 1),
            'effective_quota'  => round($effectiveQuota, 1),
            'taken_total'      => round($taken, 1),
            'remaining'        => round($remaining, 1),
            'sameday_used'     => $samedayUsed,
            'sameday_cap'      => $samedayCap,
            'sameday_left'     => max(0, $samedayCap - $samedayUsed),
            'sameday_cutoff'   => $this->samedayCutoff(),
            'cycle_start'      => $cycle['start'],
            'cycle_end'        => $cycle['end'],
        ];
    }

    /**
     * Validate a self-service leave application against the policy. Returns
     * ['ok'=>bool, 'message'=>?string, 'is_sameday'=>bool]. Server-authoritative — both the
     * web and mobile apply paths call this so an old app build can't bypass a rule.
     *
     * @param string $start  Y-m-d leave start
     * @param string $end    Y-m-d leave end
     * @param string $today  Y-m-d "now" date (injected for testability)
     * @param string $nowHm  HH:MM local time now
     */
    public function validateApplication(int $userId, string $start, string $end, string $today, string $nowHm): array
    {
        $days = (new \DateTime($start))->diff(new \DateTime($end))->days + 1;
        $isSameday = ($start <= $today); // starting today (or, defensively, earlier) = same-day ask
        $bal = $this->balance($userId);

        // Advance leave must be for tomorrow onward.
        if (!$isSameday && $start <= $today) {
            return ['ok' => false, 'message' => 'Please choose a future date for an advance leave.', 'is_sameday' => false];
        }

        if ($isSameday) {
            if ($nowHm > $this->samedayCutoff()) {
                return ['ok' => false, 'message' => 'Same-day (emergency) leave is not allowed after ' . $this->samedayCutoff() . '. Please contact your manager.', 'is_sameday' => true];
            }
            if ($bal['sameday_left'] <= 0) {
                return ['ok' => false, 'message' => 'You have used all ' . $bal['sameday_cap'] . ' emergency (same-day) leaves this year. Please contact your manager.', 'is_sameday' => true];
            }
        }

        if ($days > $bal['remaining']) {
            $rem = $bal['remaining'];
            return ['ok' => false, 'message' => 'This needs ' . $days . ' leave' . ($days > 1 ? 's' : '') . ' but you have ' . ($rem > 0 ? $rem : 0) . ' left this year. Please ask your manager.', 'is_sameday' => $isSameday];
        }

        return ['ok' => true, 'message' => null, 'is_sameday' => $isSameday];
    }
}
