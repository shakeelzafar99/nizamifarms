@extends('layouts.app')

@section('title', 'Generate Salary Slip')

@section('content')

<!-- Container -->
<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <!-- Step 1: Select Employee & Month -->
        <div class="kt-card" id="step-1">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Step 1: Select Employee & Month</h3>
            </div>
            <div class="kt-card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employee *</label>
                        <select id="employee-select" class="kt-select w-full">
                            <option value="">Select Employee...</option>
                            @php
                                $employees = \DB::table('t_sys_user as u')
                                    ->leftJoin('t_hr_employee_profile as p', 'u.id', '=', 'p.user_id')
                                    ->where('u.is_active', 1)
                                    ->whereNotNull('p.id')
                                    ->where('p.is_active', 1)
                                    ->select('u.id', 'u.fullname', 'p.employee_code')
                                    ->orderBy('u.fullname')
                                    ->get();
                            @endphp
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->fullname }} @if($emp->employee_code)({{ $emp->employee_code }})@endif</option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Salary Month *</label>
                        <input type="month" id="salary-month" class="kt-input w-full">
                    </div>
                </div>

                <div class="mt-6">
                    <button onclick="calculateSalary()" class="kt-btn kt-btn-primary">
                        <i class="ki-filled ki-calculator"></i> Calculate Salary
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Review & Adjust (Hidden initially) -->
        <div class="kt-card hidden" id="step-2">
            <div class="kt-card-header">
                <div class="flex items-center justify-between w-full">
                    <h3 class="kt-card-title">Step 2: Review & Adjust Salary</h3>
                    <button onclick="resetForm()" class="kt-btn kt-btn-sm kt-btn-light">
                        <i class="ki-filled ki-arrows-circle"></i> Start Over
                    </button>
                </div>
            </div>
            <div class="kt-card-body">
                <!-- Employee & Month Info -->
                <div class="p-4 bg-blue-50 rounded-lg mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="font-semibold text-lg" id="review-employee-name"></div>
                            <div class="text-sm text-gray-600" id="review-month"></div>
                        </div>
                        <div id="review-employee-code" class="text-sm font-medium text-blue-600"></div>
                    </div>
                </div>

                <!-- Main Grid: Earnings, Deductions, Summary -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Earnings Column -->
                    <div class="space-y-4">
                        <h4 class="font-semibold text-green-700 text-lg border-b pb-2">💰 Earnings</h4>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Base Salary (PKR)</label>
                            <input type="number" id="base-salary" step="0.01" class="kt-input w-full" readonly>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Overtime Hours
                                <button type="button" onclick="toggleOverride('overtime')" class="text-xs text-blue-600 ml-2">
                                    <i class="ki-filled ki-lock" id="overtime-lock"></i> Override
                                </button>
                            </label>
                            <div class="flex gap-2">
                                <input type="number" id="overtime-hours" step="0.01" class="kt-input w-1/2" readonly>
                                <input type="number" id="overtime-amount" step="0.01" class="kt-input w-1/2 font-medium text-green-600" readonly>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Bonuses</label>
                            <input type="number" id="bonuses" step="0.01" class="kt-input w-full text-green-600" placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Allowances</label>
                            <input type="number" id="allowances" step="0.01" class="kt-input w-full text-green-600" placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Other Earnings</label>
                            <input type="number" id="other-earnings" step="0.01" class="kt-input w-full text-green-600" placeholder="0.00">
                            <input type="text" id="other-earnings-desc" class="kt-input w-full mt-1 text-xs" placeholder="Description...">
                        </div>
                        
                        <div class="pt-3 border-t">
                            <div class="flex items-center justify-between font-bold text-lg">
                                <span>Gross Salary:</span>
                                <span class="text-green-600" id="gross-salary-display">PKR 0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Deductions Column -->
                    <div class="space-y-4">
                        <h4 class="font-semibold text-red-700 text-lg border-b pb-2">➖ Deductions</h4>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Late Minutes
                                <button type="button" onclick="toggleOverride('late')" class="text-xs text-blue-600 ml-2">
                                    <i class="ki-filled ki-lock" id="late-lock"></i> Override
                                </button>
                            </label>
                            <div class="flex gap-2">
                                <input type="number" id="late-minutes" step="0.01" class="kt-input w-1/2" readonly>
                                <input type="number" id="late-deduction" step="0.01" class="kt-input w-1/2 font-medium text-red-600" readonly>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Absent Days
                                <button type="button" onclick="toggleOverride('absent')" class="text-xs text-blue-600 ml-2">
                                    <i class="ki-filled ki-lock" id="absent-lock"></i> Override
                                </button>
                            </label>
                            <div class="flex gap-2">
                                <input type="number" id="absent-days" class="kt-input w-1/2" readonly>
                                <input type="number" id="absent-deduction" step="0.01" class="kt-input w-1/2 font-medium text-red-600" readonly>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center justify-between">
                                <span>Salary Advance</span>
                                <button type="button" onclick="toggleOverride('advance')" class="text-xs text-purple-600 hover:text-purple-700" id="advance-override-btn">
                                    <i class="ki-filled ki-pencil"></i> Override
                                </button>
                            </label>
                            <input type="number" id="salary-advance" step="0.01" class="kt-input w-full text-red-600" readonly>
                            <div class="text-xs text-gray-500 mt-1" id="advance-info"></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center justify-between">
                                <span>Loan Installment</span>
                                <button type="button" onclick="toggleOverride('loan')" class="text-xs text-purple-600 hover:text-purple-700" id="loan-override-btn">
                                    <i class="ki-filled ki-pencil"></i> Override/Skip
                                </button>
                            </label>
                            <input type="number" id="loan-installment" step="0.01" class="kt-input w-full text-red-600" readonly>
                            <div class="text-xs text-gray-500 mt-1" id="loan-info"></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tax Deduction</label>
                            <input type="number" id="tax-deduction" step="0.01" class="kt-input w-full text-red-600" placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Other Deductions</label>
                            <input type="number" id="other-deductions" step="0.01" class="kt-input w-full text-red-600" placeholder="0.00">
                            <input type="text" id="other-deductions-desc" class="kt-input w-full mt-1 text-xs" placeholder="Description...">
                        </div>
                        
                        <div class="pt-3 border-t">
                            <div class="flex items-center justify-between font-bold text-lg">
                                <span>Total Deductions:</span>
                                <span class="text-red-600" id="total-deductions-display">PKR 0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Column -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between border-b pb-2">
                            <h4 class="font-semibold text-blue-700 text-lg">📊 Attendance Summary</h4>
                            <button type="button" onclick="openAttendanceReport()" class="text-xs px-3 py-1.5 bg-purple-600 text-white font-medium rounded hover:bg-purple-700 transition shadow-sm">
                                <i class="ki-filled ki-eye"></i> View Report
                            </button>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="p-3 bg-gray-50 rounded">
                                <div class="text-gray-600">Working Days</div>
                                <div class="font-bold text-lg" id="working-days">0</div>
                            </div>
                            <div class="p-3 bg-green-50 rounded">
                                <div class="text-gray-600">Present Days</div>
                                <div class="font-bold text-lg text-green-600" id="present-days">0</div>
                            </div>
                            <div class="p-3 bg-blue-50 rounded">
                                <div class="text-gray-600">Leave Days</div>
                                <div class="font-bold text-lg text-blue-600" id="leave-days">0</div>
                            </div>
                            <div class="p-3 bg-orange-50 rounded">
                                <div class="text-gray-600">Half Days</div>
                                <div class="font-bold text-lg text-orange-600" id="half-days">0</div>
                            </div>
                        </div>

                        <div class="pt-4 border-t">
                            <h4 class="font-semibold text-purple-700 text-lg mb-3">💵 Net Salary</h4>
                            <div class="p-4 bg-gradient-to-r from-purple-50 to-blue-50 rounded-lg">
                                <div class="text-sm text-gray-600 mb-1">Amount to Pay</div>
                                <div class="font-bold text-3xl text-purple-600" id="net-salary-display">PKR 0.00</div>
                            </div>
                        </div>

                        <!-- Adjustments Info -->
                        <div id="adjustments-info" class="hidden p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <div class="text-sm font-medium text-yellow-800 mb-2">
                                <i class="ki-filled ki-information-2"></i> Manual Adjustments Made
                            </div>
                            <div class="text-xs text-yellow-700" id="adjustments-list"></div>
                        </div>

                        <!-- Override Notes -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Override/Adjustment Notes</label>
                            <textarea id="override-notes" rows="3" class="kt-input w-full text-sm" 
                                      placeholder="Explain any manual overrides or adjustments made..."></textarea>
                        </div>

                        <!-- Action Buttons -->
                        <div class="space-y-2 pt-4">
                            <button onclick="saveSalarySlip('draft')" class="kt-btn kt-btn-secondary w-full">
                                <i class="ki-filled ki-save"></i> Save as Draft
                            </button>
                            @if(auth()->user()->hasPermission('approve_salary_slips'))
                            <button onclick="saveSalarySlip('approved')" class="kt-btn kt-btn-success w-full">
                                <i class="ki-filled ki-check-circle"></i> Approve & Finalize
                            </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('demo1_js')
<script>
let calculatedData = null;
let currentUserId = null;  // For attendance report link
let currentMonth = null;   // For attendance report link (format: YYYY-MM)
let overrides = {
    overtime: false,
    late: false,
    absent: false,
    advance: false,
    loan: false
};

// Pre-fill month to current month if user_id is in URL
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const userId = urlParams.get('user_id');
    
    if (userId) {
        document.getElementById('employee-select').value = userId;
    }
    
    // Set default month to current month
    const now = new Date();
    const monthStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    document.getElementById('salary-month').value = monthStr;
    
    // Add event listeners for live calculation
    const inputs = ['base-salary', 'overtime-hours', 'overtime-amount', 'bonuses', 'allowances', 'other-earnings',
                    'late-minutes', 'late-deduction', 'absent-days', 'absent-deduction', 
                    'salary-advance', 'loan-installment', 'tax-deduction', 'other-deductions'];
    
    inputs.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateTotals);
        }
    });
});

function calculateSalary() {
    const userId = document.getElementById('employee-select').value;
    const month = document.getElementById('salary-month').value;
    
    if (!userId || !month) {
        alert('Please select both employee and month');
        return;
    }
    
    // Show loading
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="ki-filled ki-loading animate-spin"></i> Calculating...';
    
    fetch('{{ route("hr.salary-slips.calculate") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            user_id: userId,
            month: month + '-01'  // Fixed: Changed from salary_month to month
        })
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(err => {
                throw new Error(err.error || err.message || 'Failed to calculate salary');
            });
        }
        return response.json();
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="ki-filled ki-calculator"></i> Calculate Salary';
        
        if (data.success) {
            calculatedData = data;
            
            // Store for attendance report link
            currentUserId = userId;
            currentMonth = month; // Already in YYYY-MM format
            
            populateForm(data);
            document.getElementById('step-1').classList.add('hidden');
            document.getElementById('step-2').classList.remove('hidden');
        } else {
            alert('Error: ' + (data.error || data.message || 'Failed to calculate salary'));
        }
    })
    .catch(error => {
        console.error('Error calculating salary:', error);
        btn.disabled = false;
        btn.innerHTML = '<i class="ki-filled ki-calculator"></i> Calculate Salary';
        alert('Error calculating salary: ' + error.message);
    });
}

function populateForm(data) {
    // Header info
    document.getElementById('review-employee-name').textContent = data.employee.fullname;
    document.getElementById('review-month').textContent = formatMonth(data.salary_month);
    document.getElementById('review-employee-code').textContent = data.profile.employee_code || '';
    
    // Earnings
    document.getElementById('base-salary').value = parseFloat(data.base_salary).toFixed(2);
    document.getElementById('overtime-hours').value = parseFloat(data.overtime_hours).toFixed(2);
    document.getElementById('overtime-amount').value = parseFloat(data.overtime_amount).toFixed(2);
    document.getElementById('bonuses').value = parseFloat(data.bonuses || 0).toFixed(2);
    document.getElementById('allowances').value = parseFloat(data.allowances || 0).toFixed(2);
    document.getElementById('other-earnings').value = parseFloat(data.other_earnings || 0).toFixed(2);
    
    // Deductions
    document.getElementById('late-minutes').value = parseFloat(data.late_minutes).toFixed(2);
    document.getElementById('late-deduction').value = parseFloat(data.late_deduction).toFixed(2);
    document.getElementById('absent-days').value = data.absent_days;
    document.getElementById('absent-deduction').value = parseFloat(data.absent_deduction).toFixed(2);
    document.getElementById('salary-advance').value = parseFloat(data.salary_advance).toFixed(2);
    document.getElementById('loan-installment').value = parseFloat(data.loan_installment).toFixed(2);
    document.getElementById('tax-deduction').value = parseFloat(data.tax_deduction || 0).toFixed(2);
    document.getElementById('other-deductions').value = parseFloat(data.other_deductions || 0).toFixed(2);
    
    // Attendance summary
    document.getElementById('working-days').textContent = data.working_days;
    document.getElementById('present-days').textContent = data.present_days;
    document.getElementById('leave-days').textContent = data.leave_days;
    document.getElementById('half-days').textContent = data.half_days;
    
    // Info texts
    if (data.advance_info) {
        document.getElementById('advance-info').textContent = data.advance_info;
    }
    if (data.loan_info) {
        document.getElementById('loan-info').textContent = data.loan_info;
        document.getElementById('loan-override-btn').classList.remove('hidden');
    } else {
        document.getElementById('loan-override-btn').classList.add('hidden');
    }
    
    updateTotals();
}

function toggleOverride(type) {
    overrides[type] = !overrides[type];
    
    const btn = document.getElementById(`${type}-override-btn`);
    if (btn) {
        btn.classList.toggle('text-purple-600');
        btn.classList.toggle('text-green-600');
        btn.textContent = overrides[type] ? '✓ Overridden' : (type === 'loan' ? '✎ Override/Skip' : '✎ Override');
    }
    
    if (type === 'overtime') {
        document.getElementById('overtime-hours').readOnly = !overrides[type];
        document.getElementById('overtime-amount').readOnly = !overrides[type];
    } else if (type === 'late') {
        document.getElementById('late-minutes').readOnly = !overrides[type];
        document.getElementById('late-deduction').readOnly = !overrides[type];
    } else if (type === 'absent') {
        document.getElementById('absent-days').readOnly = !overrides[type];
        document.getElementById('absent-deduction').readOnly = !overrides[type];
    } else if (type === 'advance') {
        document.getElementById('salary-advance').readOnly = !overrides[type];
        if (!overrides[type] && calculatedData) {
            document.getElementById('salary-advance').value = calculatedData.salary_advance || '0.00';
        }
    } else if (type === 'loan') {
        document.getElementById('loan-installment').readOnly = !overrides[type];
        if (overrides[type]) {
            // Allow editing - default to 0 but user can change
            if (!document.getElementById('loan-installment').value || document.getElementById('loan-installment').value == calculatedData?.loan_installment) {
                document.getElementById('loan-installment').value = '0.00';
            }
        } else if (calculatedData) {
            document.getElementById('loan-installment').value = calculatedData.loan_installment || '0.00';
        }
    }
    
    updateTotals();
    updateAdjustmentsInfo();
}

function updateTotals() {
    // Calculate gross salary
    const baseSalary = parseFloat(document.getElementById('base-salary').value) || 0;
    const overtimeAmount = parseFloat(document.getElementById('overtime-amount').value) || 0;
    const bonuses = parseFloat(document.getElementById('bonuses').value) || 0;
    const allowances = parseFloat(document.getElementById('allowances').value) || 0;
    const otherEarnings = parseFloat(document.getElementById('other-earnings').value) || 0;
    
    const grossSalary = baseSalary + overtimeAmount + bonuses + allowances + otherEarnings;
    document.getElementById('gross-salary-display').textContent = formatCurrency(grossSalary);
    
    // Calculate total deductions
    const lateDeduction = parseFloat(document.getElementById('late-deduction').value) || 0;
    const absentDeduction = parseFloat(document.getElementById('absent-deduction').value) || 0;
    const salaryAdvance = parseFloat(document.getElementById('salary-advance').value) || 0;
    const loanInstallment = parseFloat(document.getElementById('loan-installment').value) || 0;
    const taxDeduction = parseFloat(document.getElementById('tax-deduction').value) || 0;
    const otherDeductions = parseFloat(document.getElementById('other-deductions').value) || 0;
    
    const totalDeductions = lateDeduction + absentDeduction + salaryAdvance + loanInstallment + taxDeduction + otherDeductions;
    document.getElementById('total-deductions-display').textContent = formatCurrency(totalDeductions);
    
    // Calculate net salary
    const netSalary = grossSalary - totalDeductions;
    document.getElementById('net-salary-display').textContent = formatCurrency(netSalary);
}

function updateAdjustmentsInfo() {
    const adjustments = [];
    
    if (overrides.overtime) adjustments.push('Overtime manually adjusted');
    if (overrides.late) adjustments.push('Late deduction overridden');
    if (overrides.absent) adjustments.push('Absent deduction adjusted');
    if (overrides.advance) adjustments.push('Salary advance overridden');
    if (overrides.loan) adjustments.push('Loan installment skipped/overridden');
    
    const infoDiv = document.getElementById('adjustments-info');
    const listDiv = document.getElementById('adjustments-list');
    
    if (adjustments.length > 0) {
        infoDiv.classList.remove('hidden');
        listDiv.innerHTML = adjustments.map(a => `• ${a}`).join('<br>');
    } else {
        infoDiv.classList.add('hidden');
    }
}

function saveSalarySlip(status) {
    const userId = document.getElementById('employee-select').value;
    const month = document.getElementById('salary-month').value + '-01';
    
    const data = {
        user_id: userId,
        salary_month: month,
        
        // Earnings
        base_salary: parseFloat(document.getElementById('base-salary').value) || 0,
        overtime_hours: parseFloat(document.getElementById('overtime-hours').value) || 0,
        overtime_amount: parseFloat(document.getElementById('overtime-amount').value) || 0,
        bonuses: parseFloat(document.getElementById('bonuses').value) || 0,
        allowances: parseFloat(document.getElementById('allowances').value) || 0,
        other_earnings: parseFloat(document.getElementById('other-earnings').value) || 0,
        other_earnings_desc: document.getElementById('other-earnings-desc').value,
        
        // Deductions
        late_minutes: parseFloat(document.getElementById('late-minutes').value) || 0,
        late_deduction: parseFloat(document.getElementById('late-deduction').value) || 0,
        absent_days: parseInt(document.getElementById('absent-days').value) || 0,
        absent_deduction: parseFloat(document.getElementById('absent-deduction').value) || 0,
        salary_advance: parseFloat(document.getElementById('salary-advance').value) || 0,
        loan_installment: parseFloat(document.getElementById('loan-installment').value) || 0,
        tax_deduction: parseFloat(document.getElementById('tax-deduction').value) || 0,
        other_deductions: parseFloat(document.getElementById('other-deductions').value) || 0,
        other_deductions_desc: document.getElementById('other-deductions-desc').value,
        
        // Attendance
        working_days: parseInt(document.getElementById('working-days').textContent) || 0,
        present_days: parseInt(document.getElementById('present-days').textContent) || 0,
        leave_days: parseInt(document.getElementById('leave-days').textContent) || 0,
        half_days: parseInt(document.getElementById('half-days').textContent) || 0,
        
        // Overrides
        late_deduction_overridden: overrides.late ? 1 : 0,
        overtime_overridden: overrides.overtime ? 1 : 0,
        absent_deduction_overridden: overrides.absent ? 1 : 0,
        salary_advance_overridden: overrides.advance ? 1 : 0,
        loan_installment_skipped: overrides.loan ? 1 : 0,
        has_manual_adjustments: (overrides.overtime || overrides.late || overrides.absent || overrides.advance || overrides.loan) ? 1 : 0,
        override_notes: document.getElementById('override-notes').value,
        
        // Status
        slip_status: status,
        
        // IDs from calculation
        advance_request_ids: calculatedData?.advance_request_ids || null,
        loan_ids: calculatedData?.loan_ids || null
    };
    
    // Calculate totals
    data.gross_salary = data.base_salary + data.overtime_amount + data.bonuses + data.allowances + data.other_earnings;
    data.total_deductions = data.late_deduction + data.absent_deduction + data.salary_advance + data.loan_installment + data.tax_deduction + data.other_deductions;
    data.net_salary = data.gross_salary - data.total_deductions;
    
    if (!confirm(`${status === 'draft' ? 'Save this salary slip as draft?' : 'Approve and finalize this salary slip?'}\n\nNet Salary: ${formatCurrency(data.net_salary)}`)) {
        return;
    }
    
    // Save
    fetch('{{ route("hr.salary-slips.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Salary slip saved successfully!');
            window.location.href = '{{ route("hr.salary-slips.index") }}';
        } else {
            alert('Error: ' + (data.message || 'Failed to save'));
        }
    })
    .catch(error => {
        console.error('Error saving salary slip:', error);
        alert('Error saving salary slip');
    });
}

function openAttendanceReport() {
    if (!currentUserId || !currentMonth) {
        alert('Please calculate salary first to view attendance report');
        return;
    }
    
    // Open attendance report in new tab with filters for this user and month
    const reportUrl = `/attendance/reports?user_id=${currentUserId}&month=${currentMonth}`;
    window.open(reportUrl, '_blank');
}

function resetForm() {
    if (confirm('Start over? All entered data will be lost.')) {
        document.getElementById('step-1').classList.remove('hidden');
        document.getElementById('step-2').classList.add('hidden');
        calculatedData = null;
        overrides = { overtime: false, late: false, absent: false, loan: false };
    }
}

function formatCurrency(amount) {
    return 'PKR ' + parseFloat(amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function formatMonth(date) {
    const d = new Date(date);
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                    'July', 'August', 'September', 'October', 'November', 'December'];
    return `${months[d.getMonth()]} ${d.getFullYear()}`;
}
</script>
@endpush

