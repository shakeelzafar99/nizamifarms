<?php

namespace App\Services\HR;

use App\Models\HR\EmployeeProfileModel;
use App\Models\Request\RequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G payroll engine — computes one employee's month under the NEW policy:
 *   net = base − absent-days deduction − late salary cut − open advances (+ manual add-ons)
 * Lateness and overtime are handled as LEAVES, not money, except when the rider has no leaves
 * to absorb a late penalty (then it becomes a salary cut), and always for >480-min lateness.
 *
 * PURE COMPUTATION — no writes. Reuses the harness-proven attendance summary
 * (SalaryCalculationService::attendanceSummary), the real late total, OvertimeService, and
 * LeavePolicyService, so every number matches the attendance screens.
 */
class PayrollService
{
    private SalaryCalculationService $salary;
    private OvertimeService $ot;
    private LeavePolicyService $leave;
    private \App\Services\ShiftResolutionService $shift;

    public function __construct()
    {
        $this->salary = new SalaryCalculationService();
        $this->ot = new OvertimeService();
        $this->leave = new LeavePolicyService();
        $this->shift = new \App\Services\ShiftResolutionService();
    }

    private function cfg(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function targetHours(): float { return (float) $this->cfg('SHIFT_TARGET_HOURS', 9); }
    private function lateBufferMin(): int { return (int) $this->cfg('LATE_MONTHLY_BUFFER_MINS', 150); }
    private function lateStepMin(): int   { return (int) $this->cfg('LATE_LEAVE_DEDUCT_STEP_MINS', 480); }

    // ── Business-unit resolution (memoized) ──────────────────────────────────
    private static ?int $nfBuIdMemo = null;
    private static ?int $khaasBuIdMemo = null;
    private static bool $buResolved = false;

    private function resolveBuIds(): void
    {
        if (self::$buResolved) { return; }
        self::$buResolved = true;
        try {
            self::$nfBuIdMemo    = ((int) (DB::table('t_fin_business_units')->where('code', 'NF')->value('id') ?? 0)) ?: null;
            self::$khaasBuIdMemo = ((int) (DB::table('t_fin_business_units')->where('code', 'KHAAS')->value('id') ?? 0)) ?: null;
        } catch (\Throwable $e) { /* leave null → treated as NF everywhere */ }
    }
    private function nfBuId(): ?int { $this->resolveBuIds(); return self::$nfBuIdMemo; }
    private function khaasBuId(): ?int { $this->resolveBuIds(); return self::$khaasBuIdMemo; }

    /** 'NF' | 'KHAAS' for a stored business_unit_id (null / NF id / unknown → 'NF'). */
    private function buCodeFor(?int $buId): string
    {
        $kh = $this->khaasBuId();
        return ($buId && $kh && $buId === $kh) ? 'KHAAS' : 'NF';
    }

    /**
     * The BU id to STAMP on a payment/ledger row for an employee: their tag, or the
     * real NF id (never null, so strict `= nfId` BU scopes match). Null only if the
     * business-unit table/row is missing entirely.
     */
    private function stampBuId(?int $profileBuId): ?int
    {
        return $profileBuId ?: $this->nfBuId();
    }

    // ── Schema guards (safe before the custom-schedule SQL is applied) ────────
    private static ?bool $payrollPeriodColsMemo = null;
    private function payrollHasPeriodCols(): bool
    {
        if (self::$payrollPeriodColsMemo === null) {
            try {
                self::$payrollPeriodColsMemo = Schema::hasColumn('t_hr_payroll_payment', 'period_key')
                    && Schema::hasColumn('t_hr_payroll_payment', 'business_unit_id');
            } catch (\Throwable $e) {
                self::$payrollPeriodColsMemo = false;
            }
        }
        return self::$payrollPeriodColsMemo;
    }

    private static ?bool $profileScheduleColsMemo = null;
    private function profileHasScheduleCols(): bool
    {
        if (self::$profileScheduleColsMemo === null) {
            try {
                self::$profileScheduleColsMemo = Schema::hasColumn('t_hr_employee_profile', 'pay_schedule')
                    && Schema::hasColumn('t_hr_employee_profile', 'business_unit_id');
            } catch (\Throwable $e) {
                self::$profileScheduleColsMemo = false;
            }
        }
        return self::$profileScheduleColsMemo;
    }

    // ── Small date helpers for custom periods ────────────────────────────────
    /** Inclusive calendar-day span (0 when the dates are invalid or reversed). */
    private function calendarDays(string $start, string $end): int
    {
        $a = strtotime($start); $b = strtotime($end);
        if ($a === false || $b === false || $b < $a) { return 0; }
        return (int) floor(($b - $a) / 86400) + 1;
    }

    /** Two inclusive Y-m-d ranges intersect (lexicographic compare is safe for Y-m-d). */
    private function rangesOverlap(string $s1, string $e1, string $s2, string $e2): bool
    {
        return $s1 <= $e2 && $s2 <= $e1;
    }

    /** 'Y-m' → [firstDay, lastDay] as Y-m-d. */
    private function monthBounds(string $month): array
    {
        $start = date('Y-m-01', strtotime($month . '-01'));
        return [$start, date('Y-m-t', strtotime($start))];
    }

    /** Human range label: "3–9 Aug 2026" / "28 Jul – 3 Aug 2026" / cross-year full. */
    private function fmtRange(string $start, string $end): string
    {
        $s = strtotime($start); $e = strtotime($end);
        if ($s === false || $e === false) { return $start . ' – ' . $end; }
        if (date('Y-m', $s) === date('Y-m', $e)) { return date('j', $s) . '–' . date('j M Y', $e); }
        if (date('Y', $s) === date('Y', $e))     { return date('j M', $s) . ' – ' . date('j M Y', $e); }
        return date('j M Y', $s) . ' – ' . date('j M Y', $e);
    }

    /**
     * Compute the payroll row for one employee + month. Returns a flat array the page and the
     * pay/approve steps both consume. `overrides` lets the manager tweak numbers (free-form late
     * deduction, bonuses, allowances) before paying.
     *
     * @param array $overrides ['late_deduction'=>?, 'bonuses'=>?, 'allowances'=>?, 'other'=>?, 'other_desc'=>?, 'base_salary'=>?]
     */
    public function computeRow(int $userId, string $month, array $overrides = []): array
    {
        $profile = EmployeeProfileModel::with('user')->where('user_id', $userId)->first();
        $fullname = $profile && $profile->user ? $profile->user->fullname
            : (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'Employee');

        $base = $overrides['base_salary'] ?? ($profile->base_salary ?? 0);
        $base = (float) $base;
        $configured = $base > 0;

        // Pay schedule + business-unit tag (guarded — null before the SQL is applied).
        $paySchedule   = $profile?->pay_schedule ?? 'monthly';
        $rateType      = $profile?->rate_type ?? 'monthly';
        $profileBuId   = $profile?->business_unit_id ?? null;

        $startDate = date('Y-m-01', strtotime($month . '-01'));
        $endDate = date('Y-m-t', strtotime($startDate));
        $today = date('Y-m-d');
        $effectiveEnd = ($endDate > $today) ? $today : $endDate;

        // Unified attendance (same numbers the slip + attendance screens use).
        $att = $this->salary->attendanceSummary($userId, $month);
        $workingDays = (int) ($att['working_days'] ?? 0);
        $presentDays = (int) ($att['present_days'] ?? 0);
        $absentDays  = (int) ($att['absent_days'] ?? 0);
        $leaveDays   = (int) ($att['leave_days'] ?? 0);
        $lateMinutes = (int) ($att['late_minutes'] ?? 0);

        // The per-day / per-hour RATE divides by the FULL month's working days (a fixed monthly
        // daily rate), NOT the days elapsed so far. Using elapsed days inflates every deduction
        // mid-month — e.g. 1 absence = base/12 (Rs 2,917) instead of base/27 (Rs 1,296). At month
        // end elapsed == full, so this leaves a month-end payment unchanged; it only corrects the
        // mid-month preview / any mid-month payout. ($workingDays stays elapsed for the attendance
        // context; only the rate divisor changes.)
        $fullMonthWorkingDays = $this->shift->calculateWorkingDays($userId, $startDate, $endDate);
        $rateDivisor = $fullMonthWorkingDays > 0 ? $fullMonthWorkingDays : $workingDays;
        $perDay  = $rateDivisor > 0 ? $base / $rateDivisor : 0.0;
        $perHour = $rateDivisor > 0 ? $base / ($rateDivisor * max(0.1, $this->targetHours())) : 0.0;

        // ── Absent deduction (unapproved absences only; leave + not-needed already excluded).
        $absentDeduction = round($absentDays * $perDay, 2);

        // ── Lateness → leaves, with salary-cut fallbacks (owner policy).
        $buffer = $this->lateBufferMin();
        $step   = $this->lateStepMin();
        $lateHours = $lateMinutes / 60;
        $lateLeaveDeduct = 0;      // whole leaves to remove from the ledger on approve
        $lateComputedCut = 0.0;    // suggested salary cut (all late hours × per-hour)
        $lateFlag = null;          // 'no_leaves' | 'over_step'
        $remainingLeaves = (float) ($this->leave->balance($userId)['remaining'] ?? 0);
        if ($lateMinutes > $step) {
            $lateComputedCut = round($lateHours * $perHour, 2);
            $lateFlag = 'over_step';
        } elseif ($lateMinutes > $buffer) {
            if ($remainingLeaves >= 1) {
                $lateLeaveDeduct = 1;
            } else {
                $lateComputedCut = round($lateHours * $perHour, 2);
                $lateFlag = 'no_leaves';
            }
        }
        // The manager may override the salary cut (free-form). Default = computed.
        $lateDeduction = array_key_exists('late_deduction', $overrides) && $overrides['late_deduction'] !== null && $overrides['late_deduction'] !== ''
            ? round((float) $overrides['late_deduction'], 2)
            : $lateComputedCut;

        // ── Overtime → bonus leaves (÷ target hours; whole days only). No pay.
        // The conversion lives in OvertimeService so the attendance/report screens show the
        // SAME number this grants — the formula used to be duplicated here, which is exactly
        // how a display and a payment silently drift apart.
        $otMinutes = $this->ot->overtimeMinutes($userId, $startDate, $effectiveEnd);
        $bonusLeaves = $this->ot->bonusLeaves($otMinutes);

        // ── Open salary advances (unsettled) — auto-deducted at pay, settled on pay.
        $advances = $this->openAdvances($userId);
        $advanceTotal = round(array_sum(array_column($advances, 'amount')), 2);

        // ── Advance REQUESTS still awaiting a decision. NOT money: no ledger row exists, so these
        // are deliberately kept OUT of $advanceTotal, deductions and net pay. They are surfaced
        // only as an "asked for, not given" chip so a manager doesn't pay twice by accident.
        $pendingRequests = $this->pendingAdvanceRequests($userId);
        $pendingRequestTotal = round(array_sum(array_column($pendingRequests, 'amount')), 2);

        // ── Manual add-ons.
        $bonuses = round((float) ($overrides['bonuses'] ?? 0), 2);
        $allowances = round((float) ($overrides['allowances'] ?? 0), 2);
        $other = round((float) ($overrides['other'] ?? 0), 2);

        $totalDeductions = round($absentDeduction + $lateDeduction + $advanceTotal, 2);
        $net = round($base + $bonuses + $allowances + $other - $totalDeductions, 2);

        return [
            'user_id'          => $userId,
            'fullname'         => $fullname,
            'employee_code'    => $profile->employee_code ?? null,
            'designation'      => $profile->designation ?? null,
            'month'            => $month,
            'configured'       => $configured,
            'pay_schedule'     => $paySchedule,
            'rate_type'        => $rateType,
            'business_unit_id' => $this->stampBuId($profileBuId ? (int) $profileBuId : null), // resolved (NF id, never null)
            'bu_code'          => $this->buCodeFor($profileBuId ? (int) $profileBuId : null), // 'NF' | 'KHAAS'
            'base_salary'      => round($base, 2),
            'per_day'          => round($perDay, 2),
            'per_hour'         => round($perHour, 2),
            'working_days'     => $workingDays,
            'present_days'     => $presentDays,
            'absent_days'      => $absentDays,
            'leave_days'       => $leaveDays,
            'absent_deduction' => $absentDeduction,
            'late_minutes'     => $lateMinutes,
            'late_leave_deduct' => $lateLeaveDeduct,       // leaves removed (ledger) on approve
            'late_computed_cut' => $lateComputedCut,       // suggested salary cut
            'late_deduction'    => $lateDeduction,          // effective (may be overridden)
            'late_flag'         => $lateFlag,               // null | no_leaves | over_step
            'late_buffer_min'   => $buffer,
            'late_step_min'     => $step,
            'overtime_minutes'  => $otMinutes,
            'bonus_leaves'      => $bonusLeaves,            // leaves granted (ledger) on approve
            'advances'          => $advances,
            'advance_total'     => $advanceTotal,
            'pending_requests'      => $pendingRequests,      // asked for, NOT given (no money)
            'pending_request_total' => $pendingRequestTotal,  // never part of deductions/net
            'bonuses'           => $bonuses,
            'allowances'        => $allowances,
            'other'             => $other,
            'loan_installment'  => 0,                       // loans PARKED (module broken)
            'total_deductions'  => $totalDeductions,
            'net_salary'        => max(0, $net),
            'net_raw'           => $net,                    // before the max(0) clamp (for the ⚠ negative case)
        ];
    }

    /** Cash + online-bank options for the "pay from" picker (web modal + mobile). */
    public function fundingOptions(): array
    {
        $cash = null;
        try {
            $c = \App\Models\FIN\ConfigModel::getNFCashAccount();
            if ($c) { $cash = ['id' => $c->id, 'name' => $c->account_name]; }
        } catch (\Throwable $e) { /* none */ }

        $banks = [];
        try {
            $banks = DB::table('t_fin_online_receiving_accounts')
                ->where('is_active', 1)->orderBy('sort_order')->orderBy('bank_name')
                ->get(['id', 'name', 'bank_name', 'account_last4'])
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'label' => trim(($b->name ?: $b->bank_name) . ($b->account_last4 ? ' ••' . $b->account_last4 : '')),
                ])->toArray();
        } catch (\Throwable $e) { /* none */ }

        return ['cash' => $cash, 'banks' => $banks];
    }

    /** Set / insert an employee's base salary (audited). Throws on failure. */
    public function setBaseSalary(int $userId, float $newBase, int $actorId): void
    {
        $newBase = round($newBase, 2);
        $existing = DB::table('t_hr_employee_profile')->where('user_id', $userId)->first();
        if ($existing) {
            DB::table('t_hr_employee_profile')->where('user_id', $userId)->update([
                'previous_salary' => $existing->base_salary,
                'base_salary' => $newBase,
                'last_salary_change_date' => now()->toDateString(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('t_hr_employee_profile')->insert([
                'user_id' => $userId,
                'base_salary' => $newBase,
                'salary_effective_date' => now()->toDateString(),
                'last_salary_change_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        \Log::info('Payroll base salary set', ['user_id' => $userId, 'base' => $newBase, 'by' => $actorId]);
    }

    /** Give a mid-month advance (creates + posts a salary_advance). Returns [success, message]. */
    public function giveAdvance(int $userId, float $amount, string $funding, ?int $bankId, ?string $note, int $actorId): array
    {
        if ($amount < 1) {
            return ['success' => false, 'message' => 'Enter an amount.'];
        }
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        try {
            $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'salary_advance')->firstOrFail();
            $fundingAcct = $funding === 'online'
                ? \App\Models\FIN\ConfigModel::getOnlineBankAccount()
                : \App\Models\FIN\ConfigModel::getNFCashAccount();
            if (!$fundingAcct) {
                return ['success' => false, 'message' => 'Funding account not found.'];
            }

            // Tag the advance with the employee's business unit so a Khaas employee's
            // advance is bucketed under Khaas on the Expenses page (which reads the
            // request's business_unit_id). NULL = NF, matching the codebase convention.
            $empBuId = null;
            try {
                if ($this->profileHasScheduleCols()) {
                    $empBuId = DB::table('t_hr_employee_profile')->where('user_id', $userId)->value('business_unit_id');
                    $empBuId = $empBuId ? (int) $empBuId : null;
                }
            } catch (\Throwable $e) { /* no tag → NF */ }

            $req = \App\Models\Request\RequestModel::create([
                'request_number'   => \App\Models\Request\RequestModel::generateRequestNumber(),
                'category_id'      => $category->id,
                'requester_user_id' => $userId,
                'title'            => 'Salary advance',
                'amount'           => round($amount, 2),
                'description'      => $note ?: 'Advance given from Payroll',
                'expense_date'     => now()->toDateString(),
                'business_unit_id' => $empBuId,
                'payment_source_account_id' => $fundingAcct->id,
                'receiving_account_id' => $funding === 'online' ? $bankId : null,
                'status'           => \App\Models\Request\RequestModel::STATUS_APPROVED,
                'settlement_status' => 'pending',
                'requires_level_1' => false,
                'requires_level_2' => false,
                'submitted_at'     => now(),
                'completed_at'     => now(),
                'created_by'       => $actorId,
                'updated_by'       => $actorId,
            ]);

            $post = (new \App\Services\FIN\LedgerPostingService())->postSalaryAdvanceFromRequest($req);
            if (empty($post['success'])) {
                throw new \RuntimeException($post['message'] ?? 'Ledger posting failed');
            }
            \Log::info('Payroll advance given', ['user_id' => $userId, 'amount' => $amount, 'request_id' => $req->id, 'by' => $actorId]);
            return ['success' => true, 'message' => 'Advance of Rs ' . number_format($amount) . ' given.'];
        } catch (\Throwable $e) {
            \Log::error('giveAdvance failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not give advance: ' . $e->getMessage()];
        }
    }

    /** Pay a batch of rows; aggregates the per-row results. */
    public function payMany(array $items, string $month, string $funding, ?int $bankId, int $actorId): array
    {
        $paid = 0; $skipped = 0; $failed = 0; $total = 0.0; $details = [];
        foreach ($items as $item) {
            $res = $this->payRow((int) $item['user_id'], $month, [
                'funding' => $funding,
                'bank_id' => $bankId,
                'late_deduction' => $item['late_deduction'] ?? null,
                // Manual take-home override (bypasses attendance deductions + advance settling).
                'net_override' => $item['net_override'] ?? null,
                // Manager bypass toggles (default = apply the recommendation). When on, the
                // matching leave-ledger row is NOT written and the receipt records 0 for it.
                'skip_overtime'  => !empty($item['skip_overtime']),
                'skip_late_leave' => !empty($item['skip_late_leave']),
                'actor_id' => $actorId,
            ]);
            if (!empty($res['success'])) {
                $paid++; $total += (float) ($res['net'] ?? 0);
            } elseif (!empty($res['skipped'])) {
                $skipped++;
            } else {
                $failed++;
            }
            $details[] = ['user_id' => $item['user_id']] + $res;
        }
        return ['paid' => $paid, 'skipped' => $skipped, 'failed' => $failed, 'total' => $total, 'details' => $details];
    }

    // ── Payment status (drives the grid's Paid/Unpaid + double-pay guard) ─────────────

    /** user_id => payment record for a month (empty when the table isn't there yet). */
    public function paidMap(string $month): array
    {
        try {
            return DB::table('t_hr_payroll_payment')->where('pay_month', $month)
                ->get()->keyBy('user_id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function isPaid(int $userId, string $month): bool
    {
        try {
            return DB::table('t_hr_payroll_payment')
                ->where('user_id', $userId)->where('pay_month', $month)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Pay ONE employee for a month. Atomic (own transaction), idempotent (UNIQUE user+month),
     * and it does the four side-effects the policy demands:
     *   1. posts a `salary_payment` ledger entry from the chosen funding account,
     *   2. settles the employee's open salary advances (they were deducted from net),
     *   3. writes the late-penalty (−1) / overtime (+N) leave-ledger rows,
     *   4. records the payment (t_hr_payroll_payment).
     *
     * @param array $opts ['funding'=>'cash'|'online', 'bank_id'=>?int, 'late_deduction'=>?float, 'actor_id'=>int]
     * @return array ['success'=>bool, 'skipped'=>?string, 'net'=>?, 'ledger_id'=>?, 'message'=>?]
     */
    public function payRow(int $userId, string $month, array $opts): array
    {
        if ($this->isPaid($userId, $month)) {
            return ['success' => false, 'skipped' => 'already_paid', 'message' => 'Already paid for this month.'];
        }

        // Custom-schedule employees are paid by date range on the Custom tab, never
        // as a whole month. Both grids already hide them here; this closes the
        // direct-request hole (stale form, mobile, crafted call).
        if ($this->profileHasScheduleCols()) {
            try {
                $sched = DB::table('t_hr_employee_profile')->where('user_id', $userId)->value('pay_schedule');
                if ($sched === 'custom') {
                    return ['success' => false, 'message' => 'This employee is on a custom schedule — pay them from the Custom tab.'];
                }
            } catch (\Throwable $e) { /* treat as monthly */ }
        }

        // Reverse guard: refuse to pay a whole month when custom periods already
        // cover part of it (the employee was custom for part of the month). Keeps a
        // mid-month monthly⇄custom switch safe in both directions.
        $block = $this->monthlyBlockedByCustom($userId, $month);
        if ($block) {
            return ['success' => false, 'message' => $block];
        }

        $override = [];
        if (array_key_exists('late_deduction', $opts) && $opts['late_deduction'] !== null && $opts['late_deduction'] !== '') {
            $override['late_deduction'] = (float) $opts['late_deduction'];
        }
        $row = $this->computeRow($userId, $month, $override);
        if (!$row['configured']) {
            return ['success' => false, 'skipped' => 'no_salary', 'message' => 'No base salary set.'];
        }
        // Manual net override: the manager types the exact take-home to pay, bypassing the
        // attendance-based deductions entirely (e.g. an employee with no attendance). When set,
        // open advances are NOT auto-deducted/settled — the amount is exactly what's paid.
        $manualNet = null;
        if (array_key_exists('net_override', $opts) && $opts['net_override'] !== null && $opts['net_override'] !== '') {
            $manualNet = max(0, round((float) $opts['net_override'], 2));
        }
        $net = $manualNet !== null ? $manualNet : max(0, (float) $row['net_salary']);
        $funding = ($opts['funding'] ?? 'cash') === 'online' ? 'online' : 'cash';
        $bankId = $funding === 'online' ? ($opts['bank_id'] ?? null) : null;
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        $actorId = (int) ($opts['actor_id'] ?? auth()->id() ?? 1);

        // Manager bypass toggles: if on, don't grant/deduct that leave, and freeze 0 into the
        // receipt so the paid-detail + leave history reflect what was actually applied.
        $appliedLateLeave   = !empty($opts['skip_late_leave']) ? 0 : (int) $row['late_leave_deduct'];
        $appliedBonusLeaves = !empty($opts['skip_overtime'])   ? 0 : (int) $row['bonus_leaves'];

        try {
            return DB::transaction(function () use ($userId, $month, $row, $net, $funding, $bankId, $actorId, $appliedLateLeave, $appliedBonusLeaves, $manualNet) {
                // Funding (source) account — NF Cash, or the single ONLINE ledger account tagged per bank.
                if ($funding === 'online') {
                    $source = \App\Models\FIN\ConfigModel::getOnlineBankAccount();
                    $mode = 'online';
                } else {
                    $source = \App\Models\FIN\ConfigModel::getNFCashAccount();
                    $mode = 'cash';
                }
                if (!$source) {
                    throw new \RuntimeException('Funding account not found.');
                }

                // Employee cash account (resolve by user+category first, else create).
                $empCash = \App\Models\FIN\AccountModel::where('user_id', $userId)
                    ->where('account_category', 'employee_cash')->first();
                if (!$empCash) {
                    $uname = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: ('User ' . $userId);
                    $empCash = \App\Models\FIN\AccountModel::createEmployeeCashAccount($userId, $uname);
                }

                // Post the salary payment. 'salary_payment' is an EXCLUDED employee-cash type, so the
                // BalancePostingService automatically skips the employee leg (salary is a personal
                // payment to them, not company cash they hold) and only decrements the funding account.
                // Net 0 (deductions ≥ salary) disburses nothing, so we don't post a zero-amount entry.
                $ledger = null;
                if ($net > 0) {
                    $ledger = \App\Models\FIN\LedgerModel::create([
                        'transaction_date'   => now(),
                        'transaction_type'   => 'salary_payment',
                        'description'        => 'Salary ' . date('M Y', strtotime($month . '-01')) . ' — ' . $row['fullname'],
                        'from_account_id'    => $source->id,
                        'to_account_id'      => $empCash->id,
                        'amount'             => $net,
                        'mode'               => $mode,
                        'receiving_account_id' => $bankId,
                        'business_unit_id'   => $row['business_unit_id'], // BU tag (inert for HQ; drives Expenses BU split)
                        'approval_status'    => 'approved',
                        'approval_date'      => now(),
                        'approved_by'        => $actorId,
                        'external_source'    => 'payroll',
                        'external_ref_id'    => $month . '/' . $userId,
                        'comments'           => 'Paid from: ' . $source->account_name . ($funding === 'online' ? (' (bank #' . $bankId . ')') : ''),
                        'created_by'         => $actorId,
                    ]);
                    (new \App\Services\FIN\BalancePostingService())->apply($ledger);
                }
                $ledgerId = $ledger ? $ledger->id : null;

                // Settle the advances that were deducted from this net. On a MANUAL override the
                // amount is exactly what's paid (advances weren't deducted), so leave them open.
                //
                // The update is GUARDED on the advance still being approved-and-unsettled, and we
                // insist on exactly one affected row. The advance list was computed before this
                // transaction, so between then and now it could have been voided (owner) or
                // settled (another manager on a stale grid). Either way the net we just paid is
                // wrong, so we abort the whole payment rather than settle a cancelled advance or
                // double-deduct one.
                $settledIds = [];
                if ($manualNet === null) {
                    foreach ($row['advances'] as $a) {
                        if (empty($a['request_id'])) {
                            continue;
                        }
                        $affected = DB::table('t_req_master')
                            ->where('id', $a['request_id'])
                            ->where('status', 'approved')
                            ->where(function ($q) {
                                $q->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'settled');
                            })
                            ->update([
                                'settlement_status' => 'settled',
                                'settled_at'        => now(),
                                'settled_by'        => $actorId,
                                'settlement_notes'  => 'Recovered from ' . date('M Y', strtotime($month . '-01')) . ' salary',
                                'settlement_transaction_id' => $ledgerId,
                                'updated_at'        => now(),
                            ]);
                        if ($affected !== 1) {
                            throw new \RuntimeException('the advances changed while this page was open — refresh payroll and pay again');
                        }
                        $settledIds[] = $a['request_id'];
                    }
                }

                // Leave-ledger side effects (idempotent per user+month+source via effective_date = 1st).
                // Skipped (bypassed) items apply 0 → no row written.
                $effDate = $month . '-01';
                if ($appliedLateLeave > 0) {
                    $this->grantOnce($userId, -1 * $appliedLateLeave, 'late_penalty',
                        'Late penalty ' . date('M Y', strtotime($effDate)), $effDate, $actorId);
                }
                if ($appliedBonusLeaves > 0) {
                    $this->grantOnce($userId, $appliedBonusLeaves, 'overtime',
                        'Overtime bonus ' . date('M Y', strtotime($effDate)), $effDate, $actorId);
                }

                // Stamp any manager bypass onto the receipt so a paid month explains itself later
                // (0 bonus_leaves could mean "no overtime" OR "overtime bypassed" — this disambiguates).
                $noteBits = [];
                if ((int) $row['bonus_leaves'] > 0 && $appliedBonusLeaves === 0) {
                    $noteBits[] = 'OT bonus (+' . (int) $row['bonus_leaves'] . ' leave) bypassed';
                }
                if ((int) $row['late_leave_deduct'] > 0 && $appliedLateLeave === 0) {
                    $noteBits[] = 'Late -1 leave waived';
                }
                // A manual override pays a flat amount; freeze the deduction/advance figures to 0
                // on the receipt so it reflects what actually happened (not the bypassed calc).
                if ($manualNet !== null) {
                    $noteBits[] = 'Manual amount set by manager (attendance deductions bypassed)';
                }
                $notes = $noteBits ? implode('; ', $noteBits) : null;

                // Record the payment (blocks double-pay; drives the grid status).
                $insert = [
                    'user_id'          => $userId,
                    'pay_month'        => $month,
                    'base_salary'      => $row['base_salary'],
                    'working_days'     => $row['working_days'],
                    'present_days'     => $row['present_days'],
                    'absent_days'      => $row['absent_days'],
                    'leave_days'       => $row['leave_days'],
                    'absent_deduction' => $manualNet !== null ? 0 : $row['absent_deduction'],
                    'late_minutes'     => $row['late_minutes'],
                    'late_deduction'   => $manualNet !== null ? 0 : $row['late_deduction'],
                    'late_leave_deduct' => $appliedLateLeave,
                    'bonus_leaves'     => $appliedBonusLeaves,
                    'advance_total'    => $manualNet !== null ? 0 : $row['advance_total'],
                    'net_salary'       => $net,
                    'funding'          => $funding,
                    'bank_id'          => $bankId,
                    'ledger_id'        => $ledgerId,
                    'status'           => 'paid',
                    'notes'            => $notes,
                    'paid_at'          => now(),
                    'paid_by'          => $actorId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
                // Monthly rows carry an empty period_key (so the widened UNIQUE key is
                // byte-equivalent to the old user+month guard) + the stamped BU.
                if ($this->payrollHasPeriodCols()) {
                    $insert['period_start']     = null;
                    $insert['period_end']       = null;
                    $insert['period_key']       = '';
                    $insert['business_unit_id'] = $row['business_unit_id'];
                }
                DB::table('t_hr_payroll_payment')->insert($insert);

                return ['success' => true, 'net' => $net, 'ledger_id' => $ledgerId, 'settled_advances' => $settledIds];
            });
        } catch (\Throwable $e) {
            \Log::error('Payroll payRow failed', ['user_id' => $userId, 'month' => $month, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not pay: ' . $e->getMessage()];
        }
    }

    /**
     * "Staff Salaries" expense reimbursements per user for a month (the OLD way the team was paid).
     * Used only as a double-pay WARNING on the grid — it never changes any number.
     * Keyed by user_id => {total, cnt}.
     */
    private function staffSalaryExpenses(string $month): array
    {
        try {
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            return DB::table('t_req_master')
                ->where('title', 'like', '%Staff Salar%')
                ->whereIn('status', ['approved', 'completed'])
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('expense_date', [$start, $end])
                      ->orWhere(function ($q2) use ($start, $end) {
                          $q2->whereNull('expense_date')->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59']);
                      });
                })
                ->whereNotNull('requester_user_id')
                ->groupBy('requester_user_id')
                ->selectRaw('requester_user_id, SUM(amount) as total, COUNT(*) as cnt')
                ->get()->keyBy('requester_user_id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The individual "Staff Salaries" expense records behind the double-pay warning for a
     * user+month (the aggregate that staffSalaryExpenses sums). Drives the clickable chip.
     */
    public function staffExpenseDetail(int $userId, string $month): array
    {
        try {
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            return DB::table('t_req_master')
                ->where('title', 'like', '%Staff Salar%')
                ->whereIn('status', ['approved', 'completed'])
                ->where('requester_user_id', $userId)
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('expense_date', [$start, $end])
                      ->orWhere(function ($q2) use ($start, $end) {
                          $q2->whereNull('expense_date')->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59']);
                      });
                })
                ->orderBy('expense_date')
                ->get(['id', 'request_number', 'title', 'amount', 'status', 'expense_category', 'expense_date', 'created_at'])
                ->map(fn ($r) => [
                    'id'             => $r->id,
                    'request_number' => $r->request_number,
                    'title'          => $r->title,
                    'category'       => $r->expense_category,
                    'amount'         => (float) $r->amount,
                    'date'           => $r->expense_date ? substr((string) $r->expense_date, 0, 10) : substr((string) $r->created_at, 0, 10),
                    'status'         => $r->status,
                ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Insert a leave-grant row only if one for this user+source+effective_date+reason doesn't
     * already exist. The reason is part of the key because manager bonus ADJUSTMENTS (attendance
     * grant-leave, kind=bonus) also write source='overtime' rows — payroll's own reasons are
     * deterministic per month ("Overtime bonus Jul 2026"), so a retry still dedupes, but a
     * manual adjustment that happens to land on the 1st can't block the month's real grant.
     */
    private function grantOnce(int $userId, int $days, string $source, string $reason, string $effectiveDate, int $actorId): void
    {
        $exists = DB::table('t_hr_leave_grant')
            ->where('user_id', $userId)->where('source', $source)
            ->whereDate('effective_date', $effectiveDate)
            ->where('reason', $reason)->exists();
        if ($exists) {
            return;
        }
        DB::table('t_hr_leave_grant')->insert([
            'user_id'        => $userId,
            'days'           => $days,
            'reason'         => $reason,
            'source'         => $source,
            'effective_date' => $effectiveDate,
            'created_by'     => $actorId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /**
     * Unsettled approved salary advances for a user (oldest first).
     *
     * Carries the PROVENANCE of each advance (funding account, who gave it, the note) so the
     * drill-down can show WHICH advance is which — a manager voiding a wrong one must be able
     * to tell two same-amount advances apart before acting. Two extra lookups, not N+1.
     */
    private function openAdvances(int $userId): array
    {
        try {
            $rows = RequestModel::where('requester_user_id', $userId)
                ->whereHas('category', fn ($q) => $q->where('category_code', 'salary_advance'))
                ->where('status', 'approved')
                ->where(function ($q) {
                    $q->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'settled');
                })
                ->orderBy('created_at', 'asc')
                ->get(['id', 'amount', 'request_number', 'created_at', 'description',
                       'payment_source_account_id', 'created_by', 'ledger_transaction_id']);
            if ($rows->isEmpty()) {
                return [];
            }

            $acctNames = [];
            $ids = $rows->pluck('payment_source_account_id')->filter()->unique()->all();
            if ($ids) {
                $acctNames = DB::table('t_fin_accounts')->whereIn('id', $ids)
                    ->pluck('account_name', 'id')->toArray();
            }
            $userNames = [];
            $uids = $rows->pluck('created_by')->filter()->unique()->all();
            if ($uids) {
                $userNames = DB::table('t_sys_user')->whereIn('id', $uids)
                    ->pluck('fullname', 'id')->toArray();
            }

            return $rows->map(fn ($r) => [
                'request_id' => $r->id,
                'request_number' => $r->request_number,
                'amount' => (float) ($r->amount ?? 0),
                'date' => $r->created_at ? substr((string) $r->created_at, 0, 10) : null,
                'source' => $r->payment_source_account_id
                    ? ($acctNames[$r->payment_source_account_id] ?? null) : null,
                'given_by' => $r->created_by ? ($userNames[$r->created_by] ?? null) : null,
                'note' => $r->description ?: null,
                // Only a POSTED advance can be voided (the void restores its ledger row).
                'voidable' => (bool) $r->ledger_transaction_id,
            ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Salary-advance requests still WAITING for a decision — the ones an employee raised from the
     * mobile app (or a manager entered) that were never approved, so NO money has moved and no
     * ledger row exists. These are NOT advances yet; they are asks.
     *
     * Deliberately requires ledger_transaction_id IS NULL: a request that somehow reached
     * 'pending' with money already posted is not something this queue should offer to pay again.
     *
     * @return array one entry per open request, oldest first
     */
    public function pendingAdvanceRequests(?int $userId = null): array
    {
        try {
            $q = RequestModel::whereHas('category', fn ($c) => $c->where('category_code', 'salary_advance'))
                ->where('status', RequestModel::STATUS_PENDING)
                ->whereNull('ledger_transaction_id');
            if ($userId) {
                $q->where('requester_user_id', $userId);
            }
            $rows = $q->orderBy('created_at', 'asc')
                ->get(['id', 'request_number', 'amount', 'created_at', 'description',
                       'requester_user_id', 'created_by']);
            if ($rows->isEmpty()) {
                return [];
            }

            $ids = $rows->pluck('requester_user_id')->merge($rows->pluck('created_by'))
                ->filter()->unique()->all();
            $names = $ids ? DB::table('t_sys_user')->whereIn('id', $ids)->pluck('fullname', 'id')->toArray() : [];
            $today = time();

            return $rows->map(function ($r) use ($names, $today) {
                $date = $r->created_at ? substr((string) $r->created_at, 0, 10) : null;
                $age = $date ? (int) floor(($today - strtotime($date)) / 86400) : 0;
                return [
                    'request_id'     => $r->id,
                    'request_number' => $r->request_number,
                    'user_id'        => (int) $r->requester_user_id,
                    'fullname'       => $names[$r->requester_user_id] ?? 'Employee',
                    'amount'         => (float) ($r->amount ?? 0),
                    'date'           => $date,
                    'age_days'       => $age,
                    'note'           => $r->description ?: null,
                    // Who typed it: the employee themselves (mobile self-request) or a manager.
                    'self_requested' => (int) $r->created_by === (int) $r->requester_user_id,
                    'raised_by'      => $names[$r->created_by] ?? null,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * APPROVE a pending advance request and pay it in one step — the money leaves the chosen
     * account immediately, exactly as if it had been given via "+ advance".
     *
     * The funding choice (cash / which bank) is made HERE at approval time, not when the employee
     * asked, because the employee has no business choosing which company account pays them. We
     * stamp payment_source_account_id + receiving_account_id BEFORE posting so the ledger row and
     * the per-bank tag are correct, then post through the same LedgerPostingService every other
     * advance uses.
     */
    public function approveAdvanceRequest(int $requestId, string $funding, ?int $bankId, int $actorId): array
    {
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        try {
            return DB::transaction(function () use ($requestId, $funding, $bankId, $actorId) {
                $req = RequestModel::with('category')->lockForUpdate()->find($requestId);
                if (!$req) {
                    return ['success' => false, 'message' => 'Request not found.'];
                }
                if (!$req->category || $req->category->category_code !== 'salary_advance') {
                    return ['success' => false, 'message' => 'That request is not a salary advance.'];
                }
                if ($req->status !== RequestModel::STATUS_PENDING) {
                    return ['success' => false, 'message' => 'This request is already ' . $req->status . '.'];
                }
                if (!empty($req->ledger_transaction_id)) {
                    return ['success' => false, 'message' => 'This request already has a ledger entry — it has been paid.'];
                }
                if ((float) $req->amount <= 0) {
                    return ['success' => false, 'message' => 'This request has no amount — reject it instead.'];
                }

                $fundingAcct = $funding === 'online'
                    ? \App\Models\FIN\ConfigModel::getOnlineBankAccount()
                    : \App\Models\FIN\ConfigModel::getNFCashAccount();
                if (!$fundingAcct) {
                    return ['success' => false, 'message' => 'Funding account not found.'];
                }

                // Business unit follows the EMPLOYEE (same rule as giveAdvance) so a Khaas
                // employee's advance lands in the Khaas expense bucket.
                $empBuId = null;
                try {
                    if ($this->profileHasScheduleCols()) {
                        $empBuId = DB::table('t_hr_employee_profile')->where('user_id', $req->requester_user_id)
                            ->value('business_unit_id');
                        $empBuId = $empBuId ? (int) $empBuId : null;
                    }
                } catch (\Throwable $e) { /* no tag → NF */ }

                $req->payment_source_account_id = $fundingAcct->id;
                $req->receiving_account_id = $funding === 'online' ? $bankId : null;
                $req->business_unit_id = $empBuId;
                // Payroll approval is a single owner-level act, exactly like "+ advance" (which
                // creates advances with no approval levels at all). Mark both levels satisfied so
                // the row's shape matches a payroll-given advance and it can't re-enter any queue.
                $req->requires_level_1 = false;
                $req->requires_level_2 = false;
                $req->level_1_status = RequestModel::APPROVAL_STATUS_APPROVED;
                $req->level_2_status = RequestModel::APPROVAL_STATUS_APPROVED;
                $req->status = RequestModel::STATUS_APPROVED;
                $req->settlement_status = 'pending';   // recovered from the next salary
                $req->completed_at = now();
                // postSalaryAdvanceFromRequest copies updated_by into ledger.approved_by.
                $req->updated_by = $actorId;
                $req->save();

                $post = (new \App\Services\FIN\LedgerPostingService())->postSalaryAdvanceFromRequest($req);
                if (empty($post['success'])) {
                    throw new \RuntimeException($post['message'] ?? 'Ledger posting failed');
                }

                \Log::info('Payroll approved advance request', [
                    'request_id' => $req->id, 'request_number' => $req->request_number,
                    'user_id' => $req->requester_user_id, 'amount' => $req->amount,
                    'funding' => $funding, 'bank_id' => $bankId, 'by' => $actorId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Approved — Rs ' . number_format((float) $req->amount)
                        . ' paid from ' . $fundingAcct->account_name
                        . '. It will be deducted from the next salary.',
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('approveAdvanceRequest failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not approve: ' . $e->getMessage()];
        }
    }

    /** REJECT a pending advance request. No money has moved, so this only closes the request. */
    public function rejectAdvanceRequest(int $requestId, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Please type a reason.'];
        }
        try {
            return DB::transaction(function () use ($requestId, $reason, $actorId) {
                $req = RequestModel::with('category')->lockForUpdate()->find($requestId);
                if (!$req) {
                    return ['success' => false, 'message' => 'Request not found.'];
                }
                if (!$req->category || $req->category->category_code !== 'salary_advance') {
                    return ['success' => false, 'message' => 'That request is not a salary advance.'];
                }
                if ($req->status !== RequestModel::STATUS_PENDING) {
                    return ['success' => false, 'message' => 'This request is already ' . $req->status . '.'];
                }
                // Guard: never "reject" something that already moved money.
                if (!empty($req->ledger_transaction_id)) {
                    return ['success' => false, 'message' => 'This request already has a ledger entry — void it instead.'];
                }

                $actorName = DB::table('t_sys_user')->where('id', $actorId)->value('fullname') ?: ('User ' . $actorId);
                $req->status = RequestModel::STATUS_REJECTED;
                $req->level_1_status = RequestModel::APPROVAL_STATUS_REJECTED;
                $req->level_2_status = RequestModel::APPROVAL_STATUS_REJECTED;
                $req->settlement_status = 'not_required';
                $req->rejection_reason = 'Rejected by ' . $actorName . ' on ' . now()->format('Y-m-d H:i:s') . ' — ' . $reason;
                $req->completed_at = now();
                $req->updated_by = $actorId;
                $req->save();

                \Log::info('Payroll rejected advance request', [
                    'request_id' => $req->id, 'user_id' => $req->requester_user_id,
                    'amount' => $req->amount, 'by' => $actorId, 'reason' => $reason,
                ]);
                return ['success' => true, 'message' => 'Request rejected. No money was moved.'];
            });
        } catch (\Throwable $e) {
            \Log::error('rejectAdvanceRequest failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not reject: ' . $e->getMessage()];
        }
    }

    /**
     * VOID a wrongly-given salary advance (owner action — permission-gated in the controller).
     *
     * Restores the money through the SAME engine that posted it (BalancePostingService::reverse,
     * idempotent, locks the accounts). Only the FUNDING account moves: 'salary_advance' is an
     * EXCLUDED employee-cash type, so no employee's cash balance shifts in either direction —
     * posting and voiding are exact mirrors.
     *
     * The ledger row is KEPT and marked REVERSED (audit trail), mirroring the expense-delete
     * convention; the request becomes 'cancelled', which removes it from every payroll and
     * expense surface on web AND mobile — they all filter status='approved', so no client
     * change is needed anywhere.
     *
     * ⛔ A SETTLED advance is NEVER voidable. Its money story is already closed by the salary
     * that recovered it (or a manual store settle), and `settlement_transaction_id` on a
     * payroll-settled advance points at the WHOLE MONTH'S salary_payment row — reversing that
     * would un-post an entire salary. This method never reads or touches that column; the
     * guards below refuse before it could ever matter.
     */
    public function voidAdvance(int $requestId, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Please type why this advance is being voided.'];
        }
        try {
            return DB::transaction(function () use ($requestId, $reason, $actorId) {
                $req = RequestModel::with('category')->lockForUpdate()->find($requestId);
                if (!$req) {
                    return ['success' => false, 'message' => 'Advance not found.'];
                }
                if (!$req->category || $req->category->category_code !== 'salary_advance') {
                    return ['success' => false, 'message' => 'That request is not a salary advance.'];
                }
                if ($req->status !== RequestModel::STATUS_APPROVED) {
                    return ['success' => false, 'message' => 'Only an active advance can be voided — this one is already ' . $req->status . '.'];
                }
                if ((string) $req->settlement_status === 'settled') {
                    return ['success' => false, 'message' => 'This advance was already settled ('
                        . ($req->settlement_notes ?: 'recovered from salary') . ') — it can no longer be voided.'];
                }
                // Defensive: a settlement row exists even though the flag doesn't say 'settled'.
                // Never unwind that here (it may be a shared salary row) — refuse and let a human look.
                if (!empty($req->settlement_transaction_id)) {
                    return ['success' => false, 'message' => 'This advance already has a settlement transaction — it cannot be voided automatically.'];
                }
                if (empty($req->ledger_transaction_id)) {
                    return ['success' => false, 'message' => 'This advance has no ledger entry linked, so there is nothing to restore. Please check it before changing anything.'];
                }

                $ledger = \App\Models\FIN\LedgerModel::lockForUpdate()->find($req->ledger_transaction_id);
                if (!$ledger) {
                    return ['success' => false, 'message' => 'The ledger entry for this advance is missing — please check it before changing anything.'];
                }
                if ($ledger->approval_status !== \App\Models\FIN\LedgerModel::STATUS_APPROVED) {
                    return ['success' => false, 'message' => 'The ledger entry for this advance is already ' . $ledger->approval_status . ' — nothing to restore.'];
                }

                $actorName = DB::table('t_sys_user')->where('id', $actorId)->value('fullname') ?: ('User ' . $actorId);
                $stamp = 'VOIDED by ' . $actorName . ' on ' . now()->format('Y-m-d H:i:s') . ' — Reason: ' . $reason;
                $restoredTo = \App\Models\FIN\AccountModel::find($ledger->from_account_id)?->account_name;
                $amount = (float) $ledger->amount;

                // 1. Money back, through the canonical engine.
                (new \App\Services\FIN\BalancePostingService())->reverse($ledger);

                // 2. Keep the row, mark it reversed (audit).
                $ledger->approval_status = \App\Models\FIN\LedgerModel::STATUS_REVERSED;
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n" : '') . $stamp;
                $ledger->save();

                // 3. Retire the request. 'not_required' keeps a voided advance out of every
                //    unsettled/settlement queue (it will never be recovered from a salary).
                $req->status = RequestModel::STATUS_CANCELLED;
                $req->settlement_status = 'not_required';
                $req->rejection_reason = $stamp;
                $req->updated_by = $actorId;
                $req->save();

                \Log::info('Salary advance voided', [
                    'request_id' => $req->id, 'request_number' => $req->request_number,
                    'amount' => $amount, 'ledger_id' => $ledger->id,
                    'user_id' => $req->requester_user_id, 'by' => $actorId, 'reason' => $reason,
                ]);

                return [
                    'success' => true,
                    'message' => 'Advance ' . $req->request_number . ' voided — Rs ' . number_format($amount)
                        . ' returned to ' . ($restoredTo ?: 'the funding account') . '.',
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('voidAdvance failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not void this advance: ' . $e->getMessage()];
        }
    }

    /** Compute every eligible employee's row for a month (configured-salary employees + advances). */
    public function computeMonth(string $month): array
    {
        // Active users in name order; payroll visibility resolved per the effective rule
        // (explicit "Show in Salary" flag, else attendance visibility).
        $users = DB::table('t_sys_user as u')
            ->where('u.is_active', 1)
            ->orderBy('u.fullname')
            ->pluck('u.id');
        $payVis = $this->payrollVisibilityMap();

        $paid = $this->paidMap($month);
        $staffExp = $this->staffSalaryExpenses($month);
        // Custom-schedule employees are paid by date-range on the Custom tab — keep them
        // off the monthly grid entirely. Empty before the SQL is applied → no exclusion.
        $customIds = $this->customScheduleUserIds();

        // Lookups for the paid-detail view (bank names + who paid), one query each.
        $bankLabels = [];
        $paidByNames = [];
        try {
            $bankIds = array_filter(array_map(fn ($p) => $p->bank_id ?? null, $paid));
            if ($bankIds) {
                $bankLabels = DB::table('t_fin_online_receiving_accounts')->whereIn('id', $bankIds)
                    ->get(['id', 'name', 'bank_name', 'account_last4'])
                    ->mapWithKeys(fn ($b) => [$b->id => trim(($b->name ?: $b->bank_name) . ($b->account_last4 ? ' ••' . $b->account_last4 : ''))])
                    ->toArray();
            }
            $payerIds = array_filter(array_map(fn ($p) => $p->paid_by ?? null, $paid));
            if ($payerIds) {
                $paidByNames = DB::table('t_sys_user')->whereIn('id', $payerIds)->pluck('fullname', 'id')->toArray();
            }
        } catch (\Throwable $e) { /* detail lookups are cosmetic */ }

        $rows = [];
        foreach ($users as $uid) {
            $uid = (int) $uid;
            if (isset($customIds[$uid])) { continue; }
            $vis = $payVis[$uid] ?? ['on' => true, 'explicit' => false];
            if (!$vis['on']) { continue; }
            try {
                $row = $this->computeRow($uid, $month);
                // Show the row when: the owner explicitly put them on Payroll (even with no
                // salary yet, so "＋ Set salary" is reachable), OR they have a salary / open advance.
                if ($vis['explicit'] || $row['configured'] || $row['advance_total'] > 0) {
                    $p = $paid[(int) $uid] ?? null;
                    $row['paid'] = $p !== null;
                    $row['paid_net'] = $p ? (float) $p->net_salary : null;
                    $row['paid_funding'] = $p ? $p->funding : null;
                    $row['paid_at'] = $p ? (string) $p->paid_at : null;
                    // The FROZEN figures that were actually paid (the live row recomputes daily,
                    // this is the receipt): drives the click-to-view payment detail.
                    $row['paid_detail'] = $p ? [
                        'net'               => (float) $p->net_salary,
                        'base'              => (float) $p->base_salary,
                        'working_days'      => (int) $p->working_days,
                        'present_days'      => (int) $p->present_days,
                        'absent_days'       => (int) $p->absent_days,
                        'leave_days'        => (int) $p->leave_days,
                        'absent_deduction'  => (float) $p->absent_deduction,
                        'late_minutes'      => (int) $p->late_minutes,
                        'late_deduction'    => (float) $p->late_deduction,
                        'late_leave_deduct' => (int) $p->late_leave_deduct,
                        'bonus_leaves'      => (int) $p->bonus_leaves,
                        'advance_total'     => (float) $p->advance_total,
                        'notes'             => $p->notes ?? null,
                        'funding'           => $p->funding,
                        'bank_label'        => $p->bank_id ? ($bankLabels[$p->bank_id] ?? ('Bank #' . $p->bank_id)) : null,
                        'ledger_id'         => $p->ledger_id,
                        'paid_at'           => (string) $p->paid_at,
                        'paid_by_name'      => $p->paid_by ? ($paidByNames[$p->paid_by] ?? null) : null,
                    ] : null;
                    // Double-pay guardrail: this employee already got a "Staff Salaries" expense
                    // reimbursement this month (the OLD way of paying). Warn before paying again.
                    $se = $staffExp[(int) $uid] ?? null;
                    $row['staff_expense_total'] = $se ? (float) $se->total : 0.0;
                    $row['staff_expense_count'] = $se ? (int) $se->cnt : 0;
                    $rows[] = $row;
                }
            } catch (\Throwable $e) {
                \Log::warning('Payroll computeRow failed', ['user_id' => $uid, 'month' => $month, 'error' => $e->getMessage()]);
            }
        }
        return $rows;
    }

    // =========================================================================
    //  CUSTOM-SCHEDULE PAYROLL (date-range / weekly employees)
    // =========================================================================

    /**
     * Effective payroll visibility for every active user: user_id => ['on'=>bool, 'explicit'=>bool].
     *   - explicit = the owner set a dedicated "Show in Salary" flag in the attendance
     *     Customize-User-List modal. It OVERRIDES attendance visibility AND forces the
     *     row to appear even before a salary is set (so "＋ Set salary" is reachable).
     *   - no flag (NULL) → follows attendance "Show in Attendance" (COALESCE default visible).
     * Guarded: before the show_in_payroll column exists this is exactly the old
     * attendance-visibility rule, so the grid is byte-identical.
     */
    private function payrollVisibilityMap(): array
    {
        $hasCol = false;
        try { $hasCol = Schema::hasColumn('t_ops_attendance_visibility', 'show_in_payroll'); } catch (\Throwable $e) { $hasCol = false; }
        $rows = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->get(['u.id', 'av.is_visible', $hasCol ? 'av.show_in_payroll' : DB::raw('NULL as show_in_payroll')]);
        $map = [];
        foreach ($rows as $r) {
            $attVisible = ($r->is_visible === null) ? true : ((int) $r->is_visible === 1);
            $explicit = $hasCol && $r->show_in_payroll !== null;
            $map[(int) $r->id] = [
                'on'       => $explicit ? ((int) $r->show_in_payroll === 1) : $attVisible,
                'explicit' => $explicit,
            ];
        }
        return $map;
    }

    /** Map user_id => true for employees tagged pay_schedule='custom' (empty pre-SQL). */
    private function customScheduleUserIds(): array
    {
        if (!$this->profileHasScheduleCols()) { return []; }
        try {
            return DB::table('t_hr_employee_profile')->where('pay_schedule', 'custom')
                ->pluck('user_id')->flip()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Present days (with a login) inside a range — reference only, never affects pay. */
    private function presentDaysInRange(int $userId, string $start, string $end): int
    {
        try {
            return (int) DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->whereBetween('attendance_date', [$start, $end])
                ->whereNotNull('login_time')->where('login_time', '!=', '')
                ->distinct()->count('attendance_date');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Compute a custom employee's pay for a date range. PURE COMPUTATION — no writes.
     * Gross = daily rate × calendar days, or monthly rate ÷ 30 × calendar days.
     * Attendance (present/absent) is REFERENCE ONLY (owner policy: no automatic
     * absent/late/OT effects for custom employees); open advances auto-deduct.
     *
     * @param array $overrides ['amount' => manager's final gross before advances]
     */
    public function computeCustomPeriod(int $userId, string $start, string $end, array $overrides = []): array
    {
        $start = substr($start, 0, 10);
        $end   = substr($end, 0, 10);

        $profile = EmployeeProfileModel::with('user')->where('user_id', $userId)->first();
        $fullname = $profile && $profile->user ? $profile->user->fullname
            : (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'Employee');

        $base       = (float) ($profile?->base_salary ?? 0);
        $rateType   = ($profile?->rate_type ?? 'monthly') === 'daily' ? 'daily' : 'monthly';
        $configured = $base > 0;
        $profileBu  = $profile?->business_unit_id ? (int) $profile->business_unit_id : null;

        $today = date('Y-m-d');
        $days  = $this->calendarDays($start, $end);

        // Reference attendance for the range (clamped to today; never penalises pay).
        $workingDaysInRange = 0; $presentDays = 0; $absentDays = 0;
        try {
            $refEnd = ($end > $today) ? $today : $end;
            if ($days > 0 && $refEnd >= $start) {
                $workingDaysInRange = $this->shift->calculateWorkingDays($userId, $start, $refEnd);
                $presentDays = $this->presentDaysInRange($userId, $start, $refEnd);
                $absentDays  = count((new AttendanceYearService())
                    ->absentWorkingDates($userId, $this->shift, $start, $refEnd));
            }
        } catch (\Throwable $e) { /* reference only */ }

        // Gross: daily → rate × days; monthly → base ÷ 30 × days (flat ÷30 convention).
        $computed = $rateType === 'daily'
            ? round($base * $days, 2)
            : round(($base / 30) * $days, 2);

        $amount = (array_key_exists('amount', $overrides) && $overrides['amount'] !== null && $overrides['amount'] !== '')
            ? round((float) $overrides['amount'], 2)
            : $computed;

        // Advances: recover only what THIS period's pay can absorb (oldest first).
        // Settlement is all-or-nothing per advance, and a weekly period is often
        // smaller than an open advance — settling everything like the monthly path
        // would write off the uncovered excess invisibly. Anything that doesn't fit
        // stays OPEN (still visible, recovered by a later/bigger period or manually).
        $allAdvances = $this->openAdvances($userId);
        $advances = [];            // the ones deducted from THIS pay (settled on pay)
        $advanceTotal = 0.0;
        $advanceOpenAfter = 0.0;   // left open for next time
        foreach ($allAdvances as $a) {
            if (round($advanceTotal + $a['amount'], 2) <= $amount) {
                $advances[] = $a;
                $advanceTotal = round($advanceTotal + $a['amount'], 2);
            } else {
                $advanceOpenAfter = round($advanceOpenAfter + $a['amount'], 2);
            }
        }
        $net = round($amount - $advanceTotal, 2);

        return [
            'user_id'               => $userId,
            'fullname'              => $fullname,
            'employee_code'         => $profile?->employee_code,
            'designation'           => $profile?->designation,
            'rate_type'             => $rateType,
            'base_rate'             => round($base, 2),
            'configured'            => $configured,
            'business_unit_id'      => $this->stampBuId($profileBu),
            'bu_code'               => $this->buCodeFor($profileBu),
            'start'                 => $start,
            'end'                   => $end,
            'days'                  => $days,
            'working_days_in_range' => $workingDaysInRange,
            'present_days'          => $presentDays,
            'absent_days'           => $absentDays,
            'computed_amount'       => $computed,
            'amount'                => $amount,
            'advances'              => $advances,          // deducted THIS pay (settled on pay)
            'advance_total'         => $advanceTotal,
            'advance_open_after'    => $advanceOpenAfter,  // still open after this pay
            'net_amount'            => max(0, $net),
            'net_raw'               => $net,
        ];
    }

    /**
     * Every paid payment row for a user, normalised for overlap checks.
     * period_start/end are trimmed to Y-m-d; monthly rows carry period_key=''.
     */
    private function userPaidRows(int $userId, bool $forUpdate = false): array
    {
        try {
            // The frozen receipt columns ride along so a paid CUSTOM period can explain
            // itself on the Custom tab (what was recovered, from which account, by whom)
            // without a second query per chip. All of these predate the period columns.
            $cols = [
                'id', 'pay_month', 'net_salary', 'paid_at',
                'base_salary', 'working_days', 'present_days', 'absent_days',
                'advance_total', 'funding', 'bank_id', 'ledger_id', 'notes', 'paid_by',
            ];
            $hasPeriods = $this->payrollHasPeriodCols();
            if ($hasPeriods) {
                $cols = array_merge($cols, ['period_start', 'period_end', 'period_key']);
            }
            $q = DB::table('t_hr_payroll_payment')->where('user_id', $userId)->where('status', 'paid');
            if ($forUpdate) { $q->lockForUpdate(); }
            $rows = $q->get($cols);
            return $rows->map(function ($r) use ($hasPeriods) {
                $r->period_key   = $hasPeriods ? ($r->period_key ?? '') : '';
                $r->period_start = $hasPeriods && $r->period_start ? substr((string) $r->period_start, 0, 10) : null;
                $r->period_end   = $hasPeriods && $r->period_end ? substr((string) $r->period_end, 0, 10) : null;
                return $r;
            })->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Reject a new custom range that overlaps anything already paid for the user:
     *   - a paid CUSTOM period whose range intersects it, or
     *   - a paid MONTHLY month (the whole month counts as covered).
     * Returns a human message, or null when the range is free.
     */
    private function customPeriodConflict(string $start, string $end, array $paidRows): ?string
    {
        foreach ($paidRows as $r) {
            $pk = $r->period_key ?? '';
            if ($pk !== '' && $r->period_start && $r->period_end) {
                if ($this->rangesOverlap($start, $end, $r->period_start, $r->period_end)) {
                    return 'Overlaps an already-paid period (' . $this->fmtRange($r->period_start, $r->period_end) . ').';
                }
            } else {
                [$ms, $me] = $this->monthBounds($r->pay_month);
                if ($this->rangesOverlap($start, $end, $ms, $me)) {
                    return date('F Y', strtotime($r->pay_month . '-01')) . ' was already paid as a monthly salary.';
                }
            }
        }
        return null;
    }

    /**
     * Reverse guard for the monthly path: a message if custom periods already cover
     * part of the given month, else null.
     */
    private function monthlyBlockedByCustom(int $userId, string $month): ?string
    {
        if (!$this->payrollHasPeriodCols()) { return null; }
        [$ms, $me] = $this->monthBounds($month);
        foreach ($this->userPaidRows($userId) as $r) {
            if (($r->period_key ?? '') !== '' && $r->period_start && $r->period_end
                && $this->rangesOverlap($ms, $me, $r->period_start, $r->period_end)) {
                return 'Custom periods already exist in ' . date('F Y', strtotime($month . '-01'))
                    . '. Finish this month on the Custom tab, or switch this employee to Monthly from next month.';
            }
        }
        return null;
    }

    /**
     * Pay a custom employee for a date range. Atomic; overlap-checked inside the
     * transaction (row-locked); posts the same salary_payment ledger + settles
     * advances as the monthly path. NO leave-grant side effects. pay_month = the
     * month of the period END (so 28 Jul–3 Aug files under August everywhere).
     *
     * @param array $opts ['funding','bank_id','amount','note','actor_id']
     */
    public function payCustomPeriod(int $userId, string $start, string $end, array $opts): array
    {
        if (!$this->payrollHasPeriodCols()) {
            return ['success' => false, 'message' => 'Custom payroll needs the schema update to be applied first.'];
        }
        $start = substr($start, 0, 10);
        $end   = substr($end, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return ['success' => false, 'message' => 'Pick a valid start and end date.'];
        }
        if ($end < $start) {
            return ['success' => false, 'message' => 'End date must be on or after the start date.'];
        }
        if ($end > date('Y-m-d')) {
            return ['success' => false, 'message' => "You can't pay for days that haven't happened yet."];
        }
        if ($this->calendarDays($start, $end) > 62) {
            return ['success' => false, 'message' => 'That period is longer than two months — split it into smaller ranges.'];
        }

        $funding = ($opts['funding'] ?? 'cash') === 'online' ? 'online' : 'cash';
        $bankId  = $funding === 'online' ? ($opts['bank_id'] ?? null) : null;
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        $actorId = (int) ($opts['actor_id'] ?? auth()->id() ?? 1);

        $profile = EmployeeProfileModel::where('user_id', $userId)->first();
        if (($profile?->pay_schedule ?? 'monthly') !== 'custom') {
            return ['success' => false, 'message' => 'This employee is not on a custom schedule.'];
        }
        if ((float) ($profile?->base_salary ?? 0) <= 0) {
            return ['success' => false, 'message' => "Set this employee's rate first."];
        }

        try {
            return DB::transaction(function () use ($userId, $start, $end, $funding, $bankId, $actorId, $opts) {
                // Serialize concurrent pays for this employee: lock their PROFILE row
                // (always exists for a custom employee). Locking only the payment rows
                // isn't enough — when none exist yet there is nothing to lock, and two
                // managers paying overlapping ranges at once could both pass the check.
                DB::table('t_hr_employee_profile')->where('user_id', $userId)->lockForUpdate()->first();

                // Re-check overlap inside the tx (race-safe behind the lock above).
                $paidRows = $this->userPaidRows($userId, true);
                $conflict = $this->customPeriodConflict($start, $end, $paidRows);
                if ($conflict) {
                    return ['success' => false, 'message' => $conflict];
                }

                $row = $this->computeCustomPeriod($userId, $start, $end, ['amount' => $opts['amount'] ?? null]);
                $net = max(0, (float) $row['net_amount']);
                $buId = $row['business_unit_id'];
                $label = $this->fmtRange($start, $end);

                // Funding (source) account.
                if ($funding === 'online') {
                    $source = \App\Models\FIN\ConfigModel::getOnlineBankAccount();
                    $mode = 'online';
                } else {
                    $source = \App\Models\FIN\ConfigModel::getNFCashAccount();
                    $mode = 'cash';
                }
                if (!$source) {
                    throw new \RuntimeException('Funding account not found.');
                }

                // Employee cash account (resolve by user+category first, else create).
                $empCash = \App\Models\FIN\AccountModel::where('user_id', $userId)
                    ->where('account_category', 'employee_cash')->first();
                if (!$empCash) {
                    $uname = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: ('User ' . $userId);
                    $empCash = \App\Models\FIN\AccountModel::createEmployeeCashAccount($userId, $uname);
                }

                // Post the salary payment (same excluded-type behaviour as the monthly path).
                $ledger = null;
                if ($net > 0) {
                    $ledger = \App\Models\FIN\LedgerModel::create([
                        'transaction_date'   => now(),
                        'transaction_type'   => 'salary_payment',
                        'description'        => 'Salary ' . $label . ' — ' . $row['fullname'],
                        'from_account_id'    => $source->id,
                        'to_account_id'      => $empCash->id,
                        'amount'             => $net,
                        'mode'               => $mode,
                        'receiving_account_id' => $bankId,
                        'business_unit_id'   => $buId,
                        'approval_status'    => 'approved',
                        'approval_date'      => now(),
                        'approved_by'        => $actorId,
                        'external_source'    => 'payroll',
                        'external_ref_id'    => $start . '_' . $end . '/' . $userId,
                        'comments'           => 'Paid from: ' . $source->account_name . ($funding === 'online' ? (' (bank #' . $bankId . ')') : ''),
                        'created_by'         => $actorId,
                    ]);
                    (new \App\Services\FIN\BalancePostingService())->apply($ledger);
                }
                $ledgerId = $ledger ? $ledger->id : null;

                // Settle the advances deducted from this net. Same guard as the monthly path: the
                // advance must still be approved-and-unsettled or the whole payment aborts (it
                // could have been voided by the owner since this page loaded).
                $settledIds = [];
                foreach ($row['advances'] as $a) {
                    if (empty($a['request_id'])) {
                        continue;
                    }
                    $affected = DB::table('t_req_master')
                        ->where('id', $a['request_id'])
                        ->where('status', 'approved')
                        ->where(function ($q) {
                            $q->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'settled');
                        })
                        ->update([
                            'settlement_status' => 'settled',
                            'settled_at'        => now(),
                            'settled_by'        => $actorId,
                            'settlement_notes'  => 'Recovered from ' . $label . ' salary',
                            'settlement_transaction_id' => $ledgerId,
                            'updated_at'        => now(),
                        ]);
                    if ($affected !== 1) {
                        throw new \RuntimeException('the advances changed while this page was open — refresh payroll and pay again');
                    }
                    $settledIds[] = $a['request_id'];
                }

                // Record the payment. pay_month = the END month; period_key blocks an
                // exact-duplicate range via the widened UNIQUE key.
                $payMonth = date('Y-m', strtotime($end));
                DB::table('t_hr_payroll_payment')->insert([
                    'user_id'          => $userId,
                    'pay_month'        => $payMonth,
                    'base_salary'      => $row['base_rate'],
                    'working_days'     => $row['days'],           // days paid (custom row)
                    'present_days'     => $row['present_days'],
                    'absent_days'      => $row['absent_days'],
                    'leave_days'       => 0,
                    'absent_deduction' => 0,
                    'late_minutes'     => 0,
                    'late_deduction'   => 0,
                    'late_leave_deduct' => 0,
                    'bonus_leaves'     => 0,
                    'advance_total'    => $row['advance_total'],
                    'net_salary'       => $net,
                    'funding'          => $funding,
                    'bank_id'          => $bankId,
                    'ledger_id'        => $ledgerId,
                    'status'           => 'paid',
                    'notes'            => $opts['note'] ?? null,
                    'paid_at'          => now(),
                    'paid_by'          => $actorId,
                    'period_start'     => $start,
                    'period_end'       => $end,
                    'period_key'       => $start . '_' . $end,
                    'business_unit_id' => $buId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                return ['success' => true, 'net' => $net, 'ledger_id' => $ledgerId, 'settled_advances' => $settledIds, 'pay_month' => $payMonth];
            });
        } catch (\Throwable $e) {
            \Log::error('Payroll payCustomPeriod failed', ['user_id' => $userId, 'start' => $start, 'end' => $end, 'error' => $e->getMessage()]);
            if (stripos($e->getMessage(), 'uniq_user_month_period') !== false || stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return ['success' => false, 'message' => 'This exact period was already paid.'];
            }
            return ['success' => false, 'message' => 'Could not pay: ' . $e->getMessage()];
        }
    }

    /** Every custom-schedule employee + their coverage for a month (Custom tab grid). */
    public function computeMonthCustom(string $month): array
    {
        $customIds = array_keys($this->customScheduleUserIds());
        if (empty($customIds)) { return []; }

        // Same effective payroll-visibility rule as the monthly grid.
        $users = DB::table('t_sys_user as u')
            ->where('u.is_active', 1)
            ->whereIn('u.id', $customIds)
            ->orderBy('u.fullname')
            ->pluck('u.id');
        $payVis = $this->payrollVisibilityMap();

        $rows = [];
        foreach ($users as $uid) {
            $uid = (int) $uid;
            $vis = $payVis[$uid] ?? ['on' => true, 'explicit' => false];
            if (!$vis['on']) { continue; }
            try {
                $profile  = EmployeeProfileModel::where('user_id', (int) $uid)->first();
                $fullname = DB::table('t_sys_user')->where('id', $uid)->value('fullname') ?: 'Employee';
                $base     = (float) ($profile?->base_salary ?? 0);
                $rateType = ($profile?->rate_type ?? 'monthly') === 'daily' ? 'daily' : 'monthly';
                $profileBu = $profile?->business_unit_id ? (int) $profile->business_unit_id : null;

                $advances = $this->openAdvances((int) $uid);
                $advTotal = round(array_sum(array_column($advances, 'amount')), 2);

                // Paid custom periods filed under this month (pay_month = end month) + last end overall.
                $paid = []; $lastEnd = null;
                foreach ($this->userPaidRows((int) $uid) as $r) {
                    if (($r->period_key ?? '') === '' || !$r->period_start || !$r->period_end) { continue; }
                    if ($lastEnd === null || $r->period_end > $lastEnd) { $lastEnd = $r->period_end; }
                    if ($r->pay_month === $month) {
                        $paid[] = [
                            'id'      => $r->id,
                            'start'   => $r->period_start,
                            'end'     => $r->period_end,
                            'net'     => (float) $r->net_salary,
                            'paid_at' => (string) $r->paid_at,
                            'label'   => $this->fmtRange($r->period_start, $r->period_end),
                            // Frozen receipt: what was actually paid, and how. `gross` is
                            // derived from the two frozen numbers so it can never disagree
                            // with them (the live rate may have changed since).
                            'days_paid'      => (int) ($r->working_days ?? 0),
                            'present_days'   => (int) ($r->present_days ?? 0),
                            'absent_days'    => (int) ($r->absent_days ?? 0),
                            'rate_at_pay'    => (float) ($r->base_salary ?? 0),
                            'advance_total'  => (float) ($r->advance_total ?? 0),
                            'gross'          => round((float) $r->net_salary + (float) ($r->advance_total ?? 0), 2),
                            'funding'        => $r->funding ?? null,
                            'bank_id'        => $r->bank_id ? (int) $r->bank_id : null,
                            'ledger_id'      => $r->ledger_id ? (int) $r->ledger_id : null,
                            'notes'          => $r->notes ?? null,
                            'paid_by'        => $r->paid_by ? (int) $r->paid_by : null,
                        ];
                    }
                }
                usort($paid, fn ($a, $b) => strcmp($a['start'], $b['start']));

                $rows[] = [
                    'user_id'          => (int) $uid,
                    'fullname'         => $fullname,
                    'employee_code'    => $profile?->employee_code,
                    'designation'      => $profile?->designation,
                    'configured'       => $base > 0,
                    'rate_type'        => $rateType,
                    'base_rate'        => round($base, 2),
                    'business_unit_id' => $this->stampBuId($profileBu),
                    'bu_code'          => $this->buCodeFor($profileBu),
                    'advances'         => $advances,
                    'advance_total'    => $advTotal,
                    'paid_periods'     => $paid,
                    'paid_total'       => round(array_sum(array_column($paid, 'net')), 2),
                    'last_period_end'  => $lastEnd,
                    'suggested_start'  => $lastEnd
                        ? date('Y-m-d', strtotime($lastEnd . ' +1 day'))
                        : date('Y-m-01', strtotime($month . '-01')),
                ];
            } catch (\Throwable $e) {
                \Log::warning('Payroll custom row failed', ['user_id' => $uid, 'month' => $month, 'error' => $e->getMessage()]);
            }
        }

        // Human labels for the paid-period receipts (bank + who paid), two queries
        // for the whole grid. Cosmetic — a failure here never blanks a row.
        try {
            $bankIds = []; $payerIds = [];
            foreach ($rows as $r) {
                foreach ($r['paid_periods'] as $p) {
                    if (!empty($p['bank_id'])) { $bankIds[$p['bank_id']] = true; }
                    if (!empty($p['paid_by'])) { $payerIds[$p['paid_by']] = true; }
                }
            }
            $bankLabels = $bankIds
                ? DB::table('t_fin_online_receiving_accounts')->whereIn('id', array_keys($bankIds))
                    ->get(['id', 'name', 'bank_name', 'account_last4'])
                    ->mapWithKeys(fn ($b) => [$b->id => trim(($b->name ?: $b->bank_name) . ($b->account_last4 ? ' ••' . $b->account_last4 : ''))])
                    ->toArray()
                : [];
            $payerNames = $payerIds
                ? DB::table('t_sys_user')->whereIn('id', array_keys($payerIds))->pluck('fullname', 'id')->toArray()
                : [];
            foreach ($rows as $i => $r) {
                foreach ($r['paid_periods'] as $j => $p) {
                    $rows[$i]['paid_periods'][$j]['bank_label'] = !empty($p['bank_id'])
                        ? ($bankLabels[$p['bank_id']] ?? ('Bank #' . $p['bank_id'])) : null;
                    $rows[$i]['paid_periods'][$j]['paid_by_name'] = !empty($p['paid_by'])
                        ? ($payerNames[$p['paid_by']] ?? null) : null;
                }
            }
        } catch (\Throwable $e) { /* labels are cosmetic */ }

        return $rows;
    }

    /** Ensure an employee profile row exists (so tag updates have a row to write). */
    private function ensureProfile(int $userId): void
    {
        $exists = DB::table('t_hr_employee_profile')->where('user_id', $userId)->exists();
        if (!$exists) {
            DB::table('t_hr_employee_profile')->insert([
                'user_id'               => $userId,
                'base_salary'           => 0,
                'salary_effective_date' => now()->toDateString(),
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }
    }

    /** Set an employee's pay schedule (monthly|custom) + rate type (daily|monthly). Audited. */
    public function setSchedule(int $userId, string $paySchedule, ?string $rateType, int $actorId): array
    {
        if (!$this->profileHasScheduleCols()) {
            return ['success' => false, 'message' => 'Apply the schema update before changing schedules.'];
        }
        $paySchedule = $paySchedule === 'custom' ? 'custom' : 'monthly';
        $rateType = in_array($rateType, ['daily', 'monthly'], true) ? $rateType : 'monthly';
        try {
            $this->ensureProfile($userId);
            DB::table('t_hr_employee_profile')->where('user_id', $userId)->update([
                'pay_schedule' => $paySchedule,
                'rate_type'    => $paySchedule === 'custom' ? $rateType : 'monthly',
                'updated_at'   => now(),
            ]);
            \Log::info('Payroll schedule set', ['user_id' => $userId, 'schedule' => $paySchedule, 'rate_type' => $rateType, 'by' => $actorId]);
            return ['success' => true, 'message' => 'Saved.'];
        } catch (\Throwable $e) {
            \Log::error('setSchedule failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not save the schedule.'];
        }
    }

    /** Tag an employee's business unit. Only NF (null) or the real Khaas id is stored. Audited. */
    public function setBusinessUnit(int $userId, ?int $buId, int $actorId): array
    {
        if (!$this->profileHasScheduleCols()) {
            return ['success' => false, 'message' => 'Apply the schema update before tagging business units.'];
        }
        $khaas = $this->khaasBuId();
        $store = ($buId && $khaas && $buId === $khaas) ? $khaas : null; // null = NF (codebase convention)
        try {
            $this->ensureProfile($userId);
            DB::table('t_hr_employee_profile')->where('user_id', $userId)->update([
                'business_unit_id' => $store,
                'updated_at'       => now(),
            ]);
            \Log::info('Payroll BU set', ['user_id' => $userId, 'bu_id' => $store, 'by' => $actorId]);
            return ['success' => true, 'message' => 'Saved.'];
        } catch (\Throwable $e) {
            \Log::error('setBusinessUnit failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not save the business unit.'];
        }
    }

    /** Whether the schedule/BU tag columns exist yet (gates the schedule gear + Custom tab). */
    public function scheduleTaggingAvailable(): bool
    {
        return $this->profileHasScheduleCols();
    }

    /** Whether Khaas tagging is offered (schema applied + a Khaas BU exists). */
    public function khaasTaggingAvailable(): bool
    {
        return $this->profileHasScheduleCols() && $this->khaasBuId() !== null;
    }

    /** The Khaas business-unit id (for the front-end BU toggle to post), or null. */
    public function khaasBuIdValue(): ?int
    {
        return $this->khaasBuId();
    }
}
