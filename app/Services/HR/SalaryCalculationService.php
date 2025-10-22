<?php

namespace App\Services\HR;

use App\Models\HR\EmployeeProfileModel;
use App\Models\HR\EmployeeLoanModel;
use App\Models\HR\SalarySlipModel;
use App\Models\Request\RequestModel;
use App\Services\ShiftResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalaryCalculationService
{
    /**
     * Calculate salary for a given user and month
     * Returns detailed breakdown of earnings and deductions
     *
     * @param int $userId
     * @param string $month Format: YYYY-MM-01
     * @param array $overrides Manual overrides from manager
     * @return array
     */
    public function calculateSalary(int $userId, string $month, array $overrides = []): array
    {
        try {
            // 1. Get employee profile
            $profile = EmployeeProfileModel::with('user')->where('user_id', $userId)->first();
            if (!$profile || !$profile->hasSalaryConfigured()) {
                return $this->errorResponse('Employee salary not configured');
            }

            // 2. Get attendance data for the month
            $attendanceData = $this->getAttendanceData($userId, $month);

            // 3. Get salary advances for the month
            $advances = $this->getSalaryAdvances($userId, $month);

            // 4. Get active loans
            $loans = $this->getActiveLoans($userId);

            // 5. Calculate earnings
            $earnings = $this->calculateEarnings($profile, $attendanceData, $overrides);

            // 6. Calculate deductions
            $deductions = $this->calculateDeductions($profile, $attendanceData, $advances, $loans, $overrides);

            // 7. Calculate net salary
            $netSalary = $earnings['gross_salary'] - $deductions['total_deductions'];

            return [
                'success' => true,
                'user_id' => $userId,
                'month' => $month,
                'salary_month' => $month,
                'employee' => [
                    'id' => $profile->user->id,
                    'fullname' => $profile->user->fullname,
                    'email' => $profile->user->email ?? ''
                ],
                'profile' => [
                    'employee_code' => $profile->employee_code,
                    'designation' => $profile->designation,
                    'department' => $profile->department
                ],
                'employee_profile' => [
                    'base_salary' => $profile->base_salary,
                    'overtime_rate' => $profile->overtime_rate,
                    'late_deduction_rate' => $profile->late_deduction_rate,
                    'currency' => $profile->salary_currency
                ],
                'base_salary' => $profile->base_salary,
                'overtime_hours' => $earnings['overtime_hours'],
                'overtime_amount' => $earnings['overtime_amount'],
                'bonuses' => $earnings['bonuses'],
                'allowances' => $earnings['allowances'],
                'other_earnings' => $earnings['other_earnings'],
                'gross_salary' => $earnings['gross_salary'],
                
                // Top-level attendance fields (for frontend compatibility)
                'working_days' => $attendanceData['working_days'],
                'present_days' => $attendanceData['present_days'],
                'absent_days' => $attendanceData['absent_days'],
                'leave_days' => $attendanceData['leave_days'],
                'late_minutes' => $attendanceData['late_minutes'],
                'overtime_minutes' => $attendanceData['overtime_minutes'],
                
                // Top-level deduction fields
                'late_deduction' => $deductions['late_deduction'],
                'absent_deduction' => $deductions['absent_deduction'],
                'salary_advance' => $deductions['salary_advance'],
                'loan_installment' => $deductions['loan_installment'],
                'tax_deduction' => $deductions['tax_deduction'],
                'other_deductions' => $deductions['other_deductions'],
                'total_deductions' => $deductions['total_deductions'],
                
                // CRITICAL: IDs needed for settlement tracking (must be at top level for frontend)
                'advance_request_ids' => $deductions['advance_request_ids'],
                'loan_ids' => $deductions['loan_ids'],
                
                // Nested objects (for detailed breakdown)
                'attendance' => $attendanceData,
                'earnings' => $earnings,
                'deductions' => $deductions,
                'net_salary' => $netSalary,
                'advances' => $advances,
                'loans' => $loans,
                'overrides_applied' => !empty($overrides)
            ];

        } catch (\Exception $e) {
            Log::error('Salary calculation error', [
                'user_id' => $userId,
                'month' => $month,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Get attendance data for employee and month
     */
    protected function getAttendanceData(int $userId, string $month): array
    {
        $startDate = date('Y-m-01', strtotime($month));
        $endDate = date('Y-m-t', strtotime($month));
        
        // CRITICAL: Use today's date as the effective end date if we're in the current month
        // This prevents penalizing employees for future days that haven't occurred yet
        $today = date('Y-m-d');
        $effectiveEndDate = ($endDate > $today) ? $today : $endDate;

        // Query attendance records (with null-safety)
        // IMPORTANT: Only count days up to today (or end of month if past month)
        $attendanceQuery = "
            SELECT 
                COUNT(DISTINCT CASE WHEN login_time IS NOT NULL AND login_time != '' THEN attendance_date END) as present_days,
                SUM(CASE WHEN login_time IS NOT NULL AND login_time != '' THEN 1 ELSE 0 END) as days_with_login,
                SUM(CASE 
                    WHEN (login_time IS NULL OR login_time = '') AND attendance_date IS NOT NULL THEN 1 
                    ELSE 0 
                END) as absent_days,
                0 as leave_days
            FROM t_ops_attendance
            WHERE user_id = ?
            AND attendance_date IS NOT NULL
            AND attendance_date BETWEEN ? AND ?
        ";

        $attendance = DB::selectOne($attendanceQuery, [$userId, $startDate, $effectiveEndDate]);

        // Calculate late minutes and overtime hours using shift resolution service
        // This matches the attendance report logic for consistency
        $shiftService = new \App\Services\ShiftResolutionService();
        $userShift = $shiftService->getUserShift($userId);
        $shiftStart = $userShift['shift_start'] ?? '09:00:00';
        $shiftEnd = $userShift['shift_end'] ?? '17:00:00';
        
        $lateAndOTQuery = "
            SELECT 
                COALESCE(SUM(CASE 
                    WHEN login_time > ? AND login_time IS NOT NULL THEN 
                        TIMESTAMPDIFF(MINUTE, 
                            CONCAT(attendance_date, ' ', ?),
                            CONCAT(attendance_date, ' ', login_time)
                        )
                    ELSE 0 
                END), 0) as total_late_minutes,
                COALESCE(SUM(CASE 
                    WHEN logout_time > ? AND logout_time IS NOT NULL THEN 
                        TIMESTAMPDIFF(MINUTE, 
                            CONCAT(attendance_date, ' ', ?),
                            CONCAT(attendance_date, ' ', logout_time)
                        )
                    ELSE 0 
                END), 0) as total_overtime_minutes
            FROM t_ops_attendance
            WHERE user_id = ?
            AND attendance_date IS NOT NULL
            AND attendance_date BETWEEN ? AND ?
            AND login_time IS NOT NULL
            AND login_time != ''
        ";

        $lateAndOT = DB::selectOne($lateAndOTQuery, [
            $shiftStart, $shiftStart, // For late calculation
            $shiftEnd, $shiftEnd,     // For overtime calculation
            $userId, $startDate, $effectiveEndDate
        ]);

        // Calculate working days using the SAME logic as attendance reports
        // This considers user's shift schedule AND public holidays
        // IMPORTANT: Only count working days up to today (or end of month if past month)
        $shiftService = new \App\Services\ShiftResolutionService();
        $workingDays = $shiftService->calculateWorkingDays($userId, $startDate, $effectiveEndDate);

        // Calculate leave days from approved/pending leave requests
        // IMPORTANT: Same logic as attendance reports for consistency
        // CRITICAL: Only count leaves up to today (or end of month if past month)
        $leaveDays = 0;
        $leaveRequests = RequestModel::where('requester_user_id', $userId)
            ->whereIn('status', ['approved', 'pending'])
            ->whereHas('category', function($q) {
                $q->where('category_code', 'leave');
            })
            ->where(function($q) use ($startDate, $effectiveEndDate) {
                $q->whereBetween('leave_start_date', [$startDate, $effectiveEndDate])
                  ->orWhereBetween('leave_end_date', [$startDate, $effectiveEndDate])
                  ->orWhere(function($q2) use ($startDate, $effectiveEndDate) {
                      $q2->where('leave_start_date', '<=', $startDate)
                         ->where('leave_end_date', '>=', $effectiveEndDate);
                  });
            })
            ->get();

        foreach ($leaveRequests as $request) {
            if ($request->leave_start_date && $request->leave_end_date) {
                $leaveStart = new \DateTime($request->leave_start_date);
                $leaveEnd = new \DateTime($request->leave_end_date);
                $current = clone $leaveStart;
                
                while ($current <= $leaveEnd) {
                    $dateStr = $current->format('Y-m-d');
                    // Only count if within the effective date range (up to today or end of month)
                    if ($dateStr >= $startDate && $dateStr <= $effectiveEndDate) {
                        $leaveDays++;
                    }
                    $current->modify('+1 day');
                }
            }
        }

        $presentDays = $attendance->present_days ?? 0;
        
        Log::info('Salary calculation - attendance breakdown', [
            'user_id' => $userId,
            'month' => $month,
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'absent_days' => max(0, $workingDays - $presentDays - $leaveDays)
        ]);

        return [
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'absent_days' => max(0, $workingDays - $presentDays - $leaveDays), // CRITICAL: Subtract leave days!
            'leave_days' => $leaveDays,
            'late_minutes' => $lateAndOT->total_late_minutes ?? 0,
            'overtime_minutes' => $lateAndOT->total_overtime_minutes ?? 0,
            'overtime_hours' => round(($lateAndOT->total_overtime_minutes ?? 0) / 60, 2)
        ];
    }

    /**
     * Get approved salary advances for the month
     */
    protected function getSalaryAdvances(int $userId, string $month): array
    {
        $startDate = date('Y-m-01', strtotime($month));
        $endDate = date('Y-m-t', strtotime($month));

        try {
            $advances = RequestModel::where('requester_user_id', $userId)
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'salary_advance');
                })
                ->where('status', 'approved')
                ->whereBetween('completed_at', [$startDate, $endDate])
                ->get()
                ->map(function($request) {
                    return [
                        'request_id' => $request->id,
                        'amount' => $request->amount ?? 0,
                        'approved_date' => $request->completed_at,
                        'description' => $request->description ?? ''
                    ];
                })
                ->toArray();

            $totalAdvance = array_sum(array_column($advances, 'amount'));

            return [
                'requests' => $advances,
                'total_amount' => $totalAdvance,
                'request_ids' => implode(',', array_column($advances, 'request_id'))
            ];
        } catch (\Exception $e) {
            Log::error('Error getting salary advances', [
                'user_id' => $userId,
                'month' => $month,
                'error' => $e->getMessage()
            ]);
            
            return [
                'requests' => [],
                'total_amount' => 0,
                'request_ids' => ''
            ];
        }
    }

    /**
     * Get active loans for employee
     */
    protected function getActiveLoans(int $userId): array
    {
        $loans = EmployeeLoanModel::active()
            ->where('user_id', $userId)
            ->get()
            ->map(function($loan) {
                return [
                    'loan_id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'outstanding_balance' => $loan->outstanding_balance,
                    'monthly_installment' => $loan->monthly_installment,
                    'loan_type' => $loan->loan_type
                ];
            })
            ->toArray();

        $totalInstallment = array_sum(array_column($loans, 'monthly_installment'));

        return [
            'loans' => $loans,
            'total_installment' => $totalInstallment,
            'loan_ids' => implode(',', array_column($loans, 'loan_id'))
        ];
    }

    /**
     * Calculate earnings (gross salary)
     */
    protected function calculateEarnings(EmployeeProfileModel $profile, array $attendanceData, array $overrides): array
    {
        // Base salary
        $baseSalary = $profile->base_salary;

        // Overtime calculation
        $overtimeHours = $overrides['overtime_hours'] ?? $attendanceData['overtime_hours'];
        $overtimeAmount = $overtimeHours * $profile->overtime_rate;
        $overtimeOverridden = isset($overrides['overtime_hours']);

        // Bonuses and allowances
        $bonuses = $overrides['bonuses'] ?? 0;
        $allowances = $overrides['allowances'] ?? 0;
        $otherEarnings = $overrides['other_earnings'] ?? 0;
        $otherEarningsDesc = $overrides['other_earnings_desc'] ?? null;

        // Gross salary
        $grossSalary = $baseSalary + $overtimeAmount + $bonuses + $allowances + $otherEarnings;

        return [
            'base_salary' => $baseSalary,
            'overtime_hours' => $overtimeHours,
            'overtime_amount' => $overtimeAmount,
            'overtime_overridden' => $overtimeOverridden,
            'bonuses' => $bonuses,
            'allowances' => $allowances,
            'other_earnings' => $otherEarnings,
            'other_earnings_desc' => $otherEarningsDesc,
            'gross_salary' => $grossSalary
        ];
    }

    /**
     * Calculate deductions
     */
    protected function calculateDeductions(
        EmployeeProfileModel $profile,
        array $attendanceData,
        array $advances,
        array $loans,
        array $overrides
    ): array {
        
        // Late deduction
        $lateMinutes = $attendanceData['late_minutes'];
        $lateHours = $lateMinutes / 60;
        $lateDeduction = $overrides['waive_late'] ?? false ? 0 : ($lateHours * $profile->late_deduction_rate);
        $lateDeductionOverridden = isset($overrides['waive_late']) && $overrides['waive_late'];

        // Absent deduction (pro-rata calculation)
        $absentDays = $attendanceData['absent_days'];
        $perDaySalary = $attendanceData['working_days'] > 0 
            ? $profile->base_salary / $attendanceData['working_days'] 
            : 0;
        $absentDeduction = $overrides['absent_deduction'] ?? ($absentDays * $perDaySalary);
        $absentDeductionOverridden = isset($overrides['absent_deduction']);

        // Salary advance
        $salaryAdvance = $advances['total_amount'];

        // Loan installment
        $loanInstallment = $overrides['skip_loan'] ?? false ? 0 : $loans['total_installment'];
        $loanInstallmentSkipped = isset($overrides['skip_loan']) && $overrides['skip_loan'];

        // Tax and other deductions
        $taxDeduction = $overrides['tax_deduction'] ?? 0;
        $otherDeductions = $overrides['other_deductions'] ?? 0;
        $otherDeductionsDesc = $overrides['other_deductions_desc'] ?? null;

        // Total deductions
        $totalDeductions = $lateDeduction + $absentDeduction + $salaryAdvance + $loanInstallment + $taxDeduction + $otherDeductions;

        return [
            'late_minutes' => $lateMinutes,
            'late_deduction' => $lateDeduction,
            'late_deduction_overridden' => $lateDeductionOverridden,
            'absent_days' => $absentDays,
            'absent_deduction' => $absentDeduction,
            'absent_deduction_overridden' => $absentDeductionOverridden,
            'salary_advance' => $salaryAdvance,
            'advance_request_ids' => $advances['request_ids'],
            'loan_installment' => $loanInstallment,
            'loan_installment_skipped' => $loanInstallmentSkipped,
            'loan_ids' => $loans['loan_ids'],
            'tax_deduction' => $taxDeduction,
            'other_deductions' => $otherDeductions,
            'other_deductions_desc' => $otherDeductionsDesc,
            'total_deductions' => $totalDeductions
        ];
    }

    /**
     * Generate and save salary slip
     */
    public function generateSlip(int $userId, string $month, array $overrides = [], ?int $createdBy = null): array
    {
        try {
            // Check if slip already exists (non-cancelled)
            if (SalarySlipModel::existsForUserMonth($userId, $month)) {
                return $this->errorResponse('Salary slip already exists for this month. Please edit the existing slip or cancel it first.');
            }

            // Calculate salary
            $calculation = $this->calculateSalary($userId, $month, $overrides);
            
            if (!$calculation['success']) {
                return $calculation;
            }

            // Generate slip number
            $slipNumber = SalarySlipModel::generateSlipNumber($userId, $month);

            // Check if any manual adjustments were made
            $hasManualAdjustments = !empty($overrides);

            // Create salary slip
            $slip = SalarySlipModel::create([
                'user_id' => $userId,
                'salary_month' => $month,
                'slip_number' => $slipNumber,
                
                // Earnings
                'base_salary' => $calculation['earnings']['base_salary'],
                'overtime_hours' => $calculation['earnings']['overtime_hours'],
                'overtime_amount' => $calculation['earnings']['overtime_amount'],
                'bonuses' => $calculation['earnings']['bonuses'],
                'allowances' => $calculation['earnings']['allowances'],
                'other_earnings' => $calculation['earnings']['other_earnings'],
                'other_earnings_desc' => $calculation['earnings']['other_earnings_desc'],
                'gross_salary' => $calculation['earnings']['gross_salary'],
                
                // Deductions
                'late_minutes' => $calculation['deductions']['late_minutes'],
                'late_deduction' => $calculation['deductions']['late_deduction'],
                'absent_days' => $calculation['deductions']['absent_days'],
                'absent_deduction' => $calculation['deductions']['absent_deduction'],
                'salary_advance' => $calculation['deductions']['salary_advance'],
                'loan_installment' => $calculation['deductions']['loan_installment'],
                'tax_deduction' => $calculation['deductions']['tax_deduction'],
                'other_deductions' => $calculation['deductions']['other_deductions'],
                'other_deductions_desc' => $calculation['deductions']['other_deductions_desc'],
                'total_deductions' => $calculation['deductions']['total_deductions'],
                
                // Net salary
                'net_salary' => $calculation['net_salary'],
                
                // Attendance
                'working_days' => $calculation['attendance']['working_days'],
                'present_days' => $calculation['attendance']['present_days'],
                'leave_days' => $calculation['attendance']['leave_days'],
                'half_days' => 0,
                
                // Overrides
                'late_deduction_overridden' => $calculation['deductions']['late_deduction_overridden'],
                'overtime_overridden' => $calculation['earnings']['overtime_overridden'],
                'absent_deduction_overridden' => $calculation['deductions']['absent_deduction_overridden'],
                'loan_installment_skipped' => $calculation['deductions']['loan_installment_skipped'],
                'has_manual_adjustments' => $hasManualAdjustments,
                'override_notes' => $overrides['override_notes'] ?? null,
                
                // References
                'advance_request_ids' => $calculation['deductions']['advance_request_ids'],
                'loan_ids' => $calculation['deductions']['loan_ids'],
                
                // Status
                'slip_status' => SalarySlipModel::STATUS_DRAFT,
                'created_by' => $createdBy ?? auth()->id()
            ]);

            return [
                'success' => true,
                'message' => 'Salary slip generated successfully',
                'slip' => $slip->getDetailedBreakdown()
            ];

        } catch (\Exception $e) {
            Log::error('Salary slip generation error', [
                'user_id' => $userId,
                'month' => $month,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to generate salary slip: ' . $e->getMessage());
        }
    }

    /**
     * Error response helper
     */
    protected function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message
        ];
    }
}

