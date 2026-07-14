<?php

namespace App\Services\HR;

use App\Models\HR\EmployeeProfileModel;
use App\Models\Request\RequestModel;
use Illuminate\Support\Facades\DB;

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

        $perDay  = $workingDays > 0 ? $base / $workingDays : 0.0;
        $perHour = $workingDays > 0 ? $base / ($workingDays * max(0.1, $this->targetHours())) : 0.0;

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
        $otMinutes = $this->ot->overtimeMinutes($userId, $startDate, $effectiveEnd);
        $bonusLeaves = (int) floor($otMinutes / max(1, (int) round($this->targetHours() * 60)));

        // ── Open salary advances (unsettled) — auto-deducted at pay, settled on pay.
        $advances = $this->openAdvances($userId);
        $advanceTotal = round(array_sum(array_column($advances, 'amount')), 2);

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

            $req = \App\Models\Request\RequestModel::create([
                'request_number'   => \App\Models\Request\RequestModel::generateRequestNumber(),
                'category_id'      => $category->id,
                'requester_user_id' => $userId,
                'title'            => 'Salary advance',
                'amount'           => round($amount, 2),
                'description'      => $note ?: 'Advance given from Payroll',
                'expense_date'     => now()->toDateString(),
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

        $override = [];
        if (array_key_exists('late_deduction', $opts) && $opts['late_deduction'] !== null && $opts['late_deduction'] !== '') {
            $override['late_deduction'] = (float) $opts['late_deduction'];
        }
        $row = $this->computeRow($userId, $month, $override);
        if (!$row['configured']) {
            return ['success' => false, 'skipped' => 'no_salary', 'message' => 'No base salary set.'];
        }
        $net = max(0, (float) $row['net_salary']);
        $funding = ($opts['funding'] ?? 'cash') === 'online' ? 'online' : 'cash';
        $bankId = $funding === 'online' ? ($opts['bank_id'] ?? null) : null;
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        $actorId = (int) ($opts['actor_id'] ?? auth()->id() ?? 1);

        try {
            return DB::transaction(function () use ($userId, $month, $row, $net, $funding, $bankId, $actorId) {
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

                // Settle the advances that were deducted from this net.
                $settledIds = [];
                foreach ($row['advances'] as $a) {
                    if (!empty($a['request_id'])) {
                        DB::table('t_req_master')->where('id', $a['request_id'])->update([
                            'settlement_status' => 'settled',
                            'settled_at'        => now(),
                            'settled_by'        => $actorId,
                            'settlement_notes'  => 'Recovered from ' . date('M Y', strtotime($month . '-01')) . ' salary',
                            'settlement_transaction_id' => $ledgerId,
                            'updated_at'        => now(),
                        ]);
                        $settledIds[] = $a['request_id'];
                    }
                }

                // Leave-ledger side effects (idempotent per user+month+source via effective_date = 1st).
                $effDate = $month . '-01';
                if ((int) $row['late_leave_deduct'] > 0) {
                    $this->grantOnce($userId, -1 * (int) $row['late_leave_deduct'], 'late_penalty',
                        'Late penalty ' . date('M Y', strtotime($effDate)), $effDate, $actorId);
                }
                if ((int) $row['bonus_leaves'] > 0) {
                    $this->grantOnce($userId, (int) $row['bonus_leaves'], 'overtime',
                        'Overtime bonus ' . date('M Y', strtotime($effDate)), $effDate, $actorId);
                }

                // Record the payment (blocks double-pay; drives the grid status).
                DB::table('t_hr_payroll_payment')->insert([
                    'user_id'          => $userId,
                    'pay_month'        => $month,
                    'base_salary'      => $row['base_salary'],
                    'working_days'     => $row['working_days'],
                    'present_days'     => $row['present_days'],
                    'absent_days'      => $row['absent_days'],
                    'leave_days'       => $row['leave_days'],
                    'absent_deduction' => $row['absent_deduction'],
                    'late_minutes'     => $row['late_minutes'],
                    'late_deduction'   => $row['late_deduction'],
                    'late_leave_deduct' => $row['late_leave_deduct'],
                    'bonus_leaves'     => $row['bonus_leaves'],
                    'advance_total'    => $row['advance_total'],
                    'net_salary'       => $net,
                    'funding'          => $funding,
                    'bank_id'          => $bankId,
                    'ledger_id'        => $ledgerId,
                    'status'           => 'paid',
                    'paid_at'          => now(),
                    'paid_by'          => $actorId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

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

    /** Insert a leave-grant row only if one for this user+source+effective_date doesn't already exist. */
    private function grantOnce(int $userId, int $days, string $source, string $reason, string $effectiveDate, int $actorId): void
    {
        $exists = DB::table('t_hr_leave_grant')
            ->where('user_id', $userId)->where('source', $source)
            ->whereDate('effective_date', $effectiveDate)->exists();
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

    /** Unsettled approved salary advances for a user (oldest first). */
    private function openAdvances(int $userId): array
    {
        try {
            return RequestModel::where('requester_user_id', $userId)
                ->whereHas('category', fn ($q) => $q->where('category_code', 'salary_advance'))
                ->where('status', 'approved')
                ->where(function ($q) {
                    $q->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'settled');
                })
                ->orderBy('created_at', 'asc')
                ->get(['id', 'amount', 'request_number', 'created_at'])
                ->map(fn ($r) => [
                    'request_id' => $r->id,
                    'request_number' => $r->request_number,
                    'amount' => (float) ($r->amount ?? 0),
                    'date' => $r->created_at ? substr((string) $r->created_at, 0, 10) : null,
                ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Compute every eligible employee's row for a month (configured-salary employees + advances). */
    public function computeMonth(string $month): array
    {
        // Visible, active users — same visibility rule as the attendance report.
        $users = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->where(function ($q) {
                $q->whereNull('av.is_visible')->orWhere('av.is_visible', 1);
            })
            ->orderBy('u.fullname')
            ->pluck('u.id');

        $paid = $this->paidMap($month);
        $staffExp = $this->staffSalaryExpenses($month);

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
            try {
                $row = $this->computeRow((int) $uid, $month);
                // Show a row only if they have a salary configured OR have open advances to settle.
                if ($row['configured'] || $row['advance_total'] > 0) {
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
}
