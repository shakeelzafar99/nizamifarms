<?php

namespace App\Services\HR;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ONE salary-cost engine. HQ (Executive P&L + its Salaries drill), the Reports page
 * (web + mobile share it) and the Expenses page all read salary cost through here, so the
 * three can never disagree about what a month's wage bill was.
 *
 * ── What a month's salary cost IS (owner ruling, Sep-2026) ────────────────────────────
 * Accrual, not cash: the cost belongs to the MONTH WORKED, not the day the money left.
 * Pressing Pay in September for August books August. Two components, never overlapping:
 *
 *   1. PAID payroll rows for that month  — `t_hr_payroll_payment` keyed by `pay_month`,
 *      GROSS = net_salary + advance_total. (Gross, because an advance was paid early and
 *      already deducted from net; net alone understates the wage bill by the advance.)
 *
 *   2. UNRECOVERED advances for that month — money that has physically left the building
 *      but whose salary has not been paid yet. Without this the cash is invisible in the
 *      P&L until payday, which is exactly the hole the owner hit on 1 Sep 2026.
 *
 * ⭐ These cannot double-count. An advance sits in (2) only while unsettled; the instant
 * payRow settles it, it is inside `advance_total` of the payment row for the SAME
 * `pay_month` — because PayrollService::openAdvances is month-scoped, a month's pay can
 * only ever settle that month's advances. A manual net override records advance_total = 0
 * and deliberately leaves its advances open, so (2) keeps covering them. Either way the
 * rupee is counted exactly once.
 *
 * Legacy salary slips (an abandoned flow) are added on their own, always NF, by created_at.
 */
class SalaryCostService
{
    /** Advances live in the Requests table under this category. */
    public const ADVANCE_CATEGORY = 'salary_advance';

    /** Cached once per request — the column ships with payroll_advance_month_sep2026.sql. */
    private static ?bool $hasMonthCol = null;

    /**
     * Whether `t_req_master.payroll_month` exists yet. Before the SQL runs, every month
     * question falls back to the advance's expense_date, which is exactly today's behaviour.
     */
    public static function hasMonthColumn(): bool
    {
        if (self::$hasMonthCol === null) {
            try {
                self::$hasMonthCol = Schema::hasColumn('t_req_master', 'payroll_month');
            } catch (\Throwable $e) {
                self::$hasMonthCol = false;
            }
        }
        return self::$hasMonthCol;
    }

    /**
     * The canonical SQL for "which payroll month does this advance belong to".
     * ONE definition, used by this service, by PayrollService (which month's pay recovers
     * it) and by the Expenses page (which month it is listed under) — so a row can never
     * be costed to one month and recovered in another.
     *
     * @param string $alias table alias of t_req_master in the caller's query
     */
    public static function monthExpr(string $alias = 'r'): string
    {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        $derived = "DATE_FORMAT(COALESCE($a.expense_date, $a.created_at), '%Y-%m')";
        return self::hasMonthColumn()
            ? "COALESCE(NULLIF($a.payroll_month, ''), $derived)"
            : $derived;
    }

    /** Every 'YYYY-MM' touched by a window, so a month-keyed table can be range-queried. */
    public static function monthsInWindow($start, $end): array
    {
        $s = Carbon::parse($start)->startOfMonth();
        $e = Carbon::parse($end)->startOfMonth();
        $out = [];
        // Defensive bound: a corrupt window can never spin here.
        for ($i = 0; $i < 240 && $s->lte($e); $i++) {
            $out[] = $s->format('Y-m');
            $s->addMonth();
        }
        return $out;
    }

    /**
     * Salary cost for a window, split by business unit.
     *
     * @return array{all:float,kh:float,nf:float}
     */
    public function costForWindow($start, $end, int $khaasBuId): array
    {
        $months = self::monthsInWindow($start, $end);
        $all = 0.0;
        $kh = 0.0;

        foreach ($this->paidRows($months, $khaasBuId) as $r) {
            $all += (float) $r->amt;
            if ((int) $r->bu === $khaasBuId) {
                $kh += (float) $r->amt;
            }
        }
        foreach ($this->unrecoveredAdvances($months, $khaasBuId) as $r) {
            $all += (float) $r->amt;
            if ((int) $r->bu === $khaasBuId) {
                $kh += (float) $r->amt;
            }
        }
        $all += $this->legacySlips($start, $end); // always NF

        return [
            'all' => round($all, 2),
            'kh'  => round($kh, 2),
            'nf'  => round($all - $kh, 2),
        ];
    }

    /**
     * Per-employee breakdown for the drills. Advance rows are labelled so the drill
     * explains itself instead of showing an unexplained figure next to the paid ones.
     *
     * `user_id` (Sep-2026) lets a caller group these rows per EMPLOYEE without matching on the
     * display name — two staff can share a name, and a renamed user must not split into two people.
     * It is nullable only in the theoretical case of an orphaned row; callers fall back to the name.
     *
     * @return array<int,array{user_id:?int,employee:string,amount:float,is_khaas:bool,kind:string,date:?string,note:?string}>
     */
    public function detailForWindow($start, $end, int $khaasBuId): array
    {
        $months = self::monthsInWindow($start, $end);
        $out = [];

        foreach ($this->paidRows($months, $khaasBuId, true) as $r) {
            $out[] = [
                'user_id'  => $r->user_id !== null ? (int) $r->user_id : null,
                'employee' => $r->fullname ?: ('User ' . $r->user_id),
                'amount'   => round((float) $r->amt, 2),
                'is_khaas' => ((int) $r->bu === $khaasBuId),
                'kind'     => 'paid',
                'date'     => $r->paid_at ? substr((string) $r->paid_at, 0, 10) : null,
                'note'     => null,
            ];
        }
        foreach ($this->unrecoveredAdvances($months, $khaasBuId, true) as $r) {
            $out[] = [
                'user_id'  => $r->user_id !== null ? (int) $r->user_id : null,
                'employee' => $r->fullname ?: ('User ' . $r->user_id),
                'amount'   => round((float) $r->amt, 2),
                'is_khaas' => ((int) $r->bu === $khaasBuId),
                'kind'     => 'advance_open',
                'date'     => $r->money_date ? substr((string) $r->money_date, 0, 10) : null,
                'note'     => 'advance — salary not paid yet',
            ];
        }
        // Legacy slips are part of costForWindow, so they must be here too or the drill
        // would not sum to the headline it drills into.
        foreach ($this->legacySlipRows($start, $end) as $r) {
            $out[] = $r;
        }

        usort($out, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        return $out;
    }

    // =====================================================================
    //  Components
    // =====================================================================

    /**
     * Component 1 — payroll payments for the months, GROSS, grouped by BU (or by employee
     * when $perEmployee). Guarded: a prod without the BU column degrades to all-NF.
     */
    private function paidRows(array $months, int $khaasBuId, bool $perEmployee = false)
    {
        if (empty($months)) {
            return collect();
        }
        try {
            if (!Schema::hasTable('t_hr_payroll_payment')) {
                return collect();
            }
            $hasBu = Schema::hasColumn('t_hr_payroll_payment', 'business_unit_id');
            $buSel = $hasBu ? 'p.business_unit_id' : DB::raw('NULL');

            $q = DB::table('t_hr_payroll_payment as p')
                ->where('p.status', 'paid')
                ->whereIn('p.pay_month', $months);

            if ($perEmployee) {
                return $q->leftJoin('t_sys_user as u', 'u.id', '=', 'p.user_id')
                    ->groupBy('p.user_id', 'u.fullname', $hasBu ? 'p.business_unit_id' : DB::raw('1'), 'p.paid_at')
                    ->selectRaw('p.user_id, u.fullname, ' . ($hasBu ? 'p.business_unit_id' : 'NULL') . ' as bu,'
                        . ' MAX(p.paid_at) as paid_at, SUM(p.net_salary + p.advance_total) as amt')
                    ->get();
            }
            return $q->groupBy($hasBu ? 'p.business_unit_id' : DB::raw('1'))
                ->selectRaw(($hasBu ? 'p.business_unit_id' : 'NULL') . ' as bu, SUM(p.net_salary + p.advance_total) as amt')
                ->get();
        } catch (\Throwable $e) {
            \Log::warning('SalaryCostService::paidRows failed', ['error' => $e->getMessage()]);
            return collect();
        }
    }

    /**
     * Component 2 — advances already PAID OUT (a ledger row exists) but not yet recovered
     * from a salary, keyed to the payroll month they belong to.
     *
     * `ledger_transaction_id IS NOT NULL` is the whole point: a merely REQUESTED advance is
     * not money and must never reach the P&L. That is the same line PayrollService draws
     * between an advance and an advance request.
     */
    private function unrecoveredAdvances(array $months, int $khaasBuId, bool $perEmployee = false)
    {
        if (empty($months)) {
            return collect();
        }
        try {
            $monthExpr = self::monthExpr('r');
            $q = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', self::ADVANCE_CATEGORY)
                ->where('r.status', 'approved')
                ->whereNotNull('r.ledger_transaction_id')
                ->where(function ($w) {
                    $w->whereNull('r.settlement_status')
                      ->orWhere('r.settlement_status', '!=', 'settled');
                })
                ->whereIn(DB::raw($monthExpr), $months);

            if ($perEmployee) {
                return $q->leftJoin('t_sys_user as u', 'u.id', '=', 'r.requester_user_id')
                    ->groupBy('r.requester_user_id', 'u.fullname', 'r.business_unit_id')
                    ->selectRaw('r.requester_user_id as user_id, u.fullname, r.business_unit_id as bu,'
                        . ' MAX(COALESCE(r.expense_date, r.created_at)) as money_date, SUM(r.amount) as amt')
                    ->get();
            }
            return $q->groupBy('r.business_unit_id')
                ->selectRaw('r.business_unit_id as bu, SUM(r.amount) as amt')
                ->get();
        } catch (\Throwable $e) {
            \Log::warning('SalaryCostService::unrecoveredAdvances failed', ['error' => $e->getMessage()]);
            return collect();
        }
    }

    /** Legacy salary slips as per-employee detail rows (always NF). */
    private function legacySlipRows($start, $end): array
    {
        $out = [];
        try {
            foreach (\App\Models\HR\SalarySlipModel::with('employee')
                ->whereIn('slip_status', ['approved', 'paid'])
                ->whereNotNull('ledger_transaction_id')
                ->whereBetween('created_at', [
                    Carbon::parse($start)->startOfDay(),
                    Carbon::parse($end)->endOfDay(),
                ])->get() as $s) {
                $out[] = [
                    'user_id'  => $s->user_id !== null ? (int) $s->user_id : null,
                    'employee' => $s->employee ? $s->employee->fullname : ('User ' . $s->user_id),
                    'amount'   => round((float) $s->net_salary, 2),
                    'is_khaas' => false,
                    'kind'     => 'slip',
                    'date'     => substr((string) $s->created_at, 0, 10),
                    'note'     => null,
                ];
            }
        } catch (\Throwable $e) { /* skip */ }
        return $out;
    }

    /** Legacy salary slips (abandoned flow) — always NF, still dated by created_at. */
    private function legacySlips($start, $end): float
    {
        try {
            return (float) \App\Models\HR\SalarySlipModel::whereIn('slip_status', ['approved', 'paid'])
                ->whereNotNull('ledger_transaction_id')
                ->whereBetween('created_at', [
                    Carbon::parse($start)->startOfDay(),
                    Carbon::parse($end)->endOfDay(),
                ])
                ->sum('net_salary');
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
