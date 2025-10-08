{{-- resources/views/pages/requests/create.blade.php --}}

@extends('layouts.app')

@section('title', 'Create New Request')

@section('content')

<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <div class="kt-card max-w-3xl mx-auto">
            <!-- Header -->
            <div class="kt-card-header">
                <h3 class="kt-card-title text-lg font-semibold">Create New Request</h3>
                <div class="kt-card-toolbar">
                    <a href="{{ route('requests.index') }}" class="kt-btn kt-btn-light">
                        <i class="ki-filled ki-left"></i> Back
                    </a>
                </div>
            </div>

            <!-- Form -->
            <div class="kt-card-body">
                <form id="request-form">
                    @csrf
                    
                    @php
                        // Check if user can create requests for others (admin/manager)
                        $canCreateForOthers = false;
                        $debugRoles = [];
                        if (auth()->check()) {
                            // Get ALL user's roles (not just the first one!)
                            $userRoles = \DB::table('t_sys_user_role as ur')
                                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                                ->where('ur.user_id', auth()->id())
                                ->select('r.type', 'r.urole_name')
                                ->get();
                            
                            $debugRoles = $userRoles; // For debugging
                            
                            // Check by role type OR role name
                            $allowedTypes = ['admin', 'manager', 'supervisor'];
                            $allowedNamePatterns = ['admin', 'manager', 'supervisor', 'super'];
                            
                            // Check each role
                            foreach ($userRoles as $roleInfo) {
                                // Check type field
                                $typeMatch = in_array(strtolower($roleInfo->type ?? ''), $allowedTypes);
                                
                                // Check name field (case-insensitive, partial match)
                                $nameMatch = false;
                                $roleName = strtolower($roleInfo->urole_name ?? '');
                                foreach ($allowedNamePatterns as $pattern) {
                                    if (strpos($roleName, $pattern) !== false) {
                                        $nameMatch = true;
                                        break;
                                    }
                                }
                                
                                // If ANY role matches, user can create for others
                                if ($typeMatch || $nameMatch) {
                                    $canCreateForOthers = true;
                                    break;
                                }
                            }
                        }
                    @endphp
                    
                    <!-- User Selection (Admin/Manager Only) -->
                    @if($canCreateForOthers)
                    <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded">
                        <div class="flex items-center gap-2 mb-3">
                            <i class="ki-filled ki-information text-blue-600"></i>
                            <span class="text-sm font-medium text-blue-800">Admin/Manager Mode</span>
                        </div>
                        <label class="kt-label">Create Request For (Leave blank to create for yourself)</label>
                        <select id="requester_user_id" name="requester_user_id" class="kt-select">
                            <option value="">-- Myself --</option>
                            @php
                                $activeUsers = \DB::table('t_sys_user')
                                    ->where('is_active', 1)
                                    ->whereNotIn('id', [auth()->id()])
                                    ->orderBy('fullname')
                                    ->get();
                            @endphp
                            @foreach($activeUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->fullname }} ({{ $user->email }})</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-blue-600 mt-2">
                            💡 Select an employee to create a request on their behalf. The approval flow will apply normally.
                        </p>
                    </div>
                    @endif
                    
                    <!-- Category Selection -->
                    <div class="mb-6">
                        <label class="kt-label required">Request Category</label>
                        <select id="category_id" name="category_id" class="kt-select" required onchange="handleCategoryChange()">
                            <option value="">Select Category</option>
                            @foreach($categories as $category)
                            <option value="{{ $category->id }}" 
                                    data-code="{{ $category->category_code }}"
                                    data-requires-l1="{{ $category->requiresLevel1() ? '1' : '0' }}"
                                    data-requires-l2="{{ $category->requiresLevel2() ? '1' : '0' }}">
                                {{ $category->category_name }}
                            </option>
                            @endforeach
                        </select>
                        <div class="mt-2 text-sm text-gray-600" id="approval-info"></div>
                    </div>

                    <!-- Leave-specific fields (hidden by default) -->
                    <div id="leave-fields" style="display: none;">
                        <div class="grid grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="kt-label required">Leave Start Date</label>
                                <input type="date" name="leave_start_date" class="kt-input" onchange="calculateLeaveDays()">
                            </div>
                            <div>
                                <label class="kt-label required">Leave End Date</label>
                                <input type="date" name="leave_end_date" class="kt-input" onchange="calculateLeaveDays()">
                            </div>
                        </div>

                        <!-- Hidden field: Leave Type defaults to 'annual' -->
                        <input type="hidden" name="leave_type" value="annual">

                        <div class="mb-6">
                            <div class="kt-alert kt-alert-info" id="leave-days-info" style="display: none;">
                                <div class="kt-alert-title">
                                    <span id="leave-days-text"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Title - hidden for leave requests, auto-filled -->
                    <input type="hidden" name="title" id="hidden-title" value="leave">

                    <!-- Expense Category (for expense requests only) -->
                    <div id="expense-category-field" style="display: none;" class="mb-6">
                        <label class="kt-label required">Expense Type</label>
                        <select name="expense_category" id="expense_category" class="kt-select">
                            <option value="">Select Expense Type</option>
                            <option value="Petrol">Petrol</option>
                            <option value="Rent">Rent</option>
                            <option value="Utility Bills">Utility Bills</option>
                            <option value="Packaging - Shrink wrap">Packaging - Shrink wrap</option>
                            <option value="Packaging - Bags">Packaging - Bags</option>
                            <option value="Food">Food</option>
                            <option value="Office Supplies">Office Supplies</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Transportation">Transportation</option>
                            <option value="Communication">Communication</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Insurance">Insurance</option>
                            <option value="Professional Fees">Professional Fees</option>
                            <option value="Bank Charges">Bank Charges</option>
                            <option value="Staff Salaries">Staff Salaries</option>
                            <option value="Miscellaneous">Miscellaneous</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Select the type of expense for proper accounting</p>
                    </div>

                    <!-- Amount field (for advance/expense) -->
                    <div id="amount-field" style="display: none;" class="mb-6">
                        <label class="kt-label">Amount (Rs.)</label>
                        <input type="number" name="amount" class="kt-input" placeholder="0.00" step="0.01" min="0">
                    </div>

                    <!-- Description - Optional for leave requests -->
                    <div class="mb-6">
                        <label class="kt-label" id="description-label">Description</label>
                        <textarea name="description" id="description-field" class="kt-input" rows="4" placeholder="Provide detailed information about your request (optional for leave requests)"></textarea>
                    </div>

                    <!-- Priority -->
                    <div class="mb-6">
                        <label class="kt-label">Priority</label>
                        <select name="priority" class="kt-select">
                            <option value="normal">Normal</option>
                            <option value="low">Low</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex gap-2">
                        <button type="submit" class="kt-btn kt-btn-primary">
                            <i class="ki-filled ki-check"></i> Submit Request
                        </button>
                        <a href="{{ route('requests.index') }}" class="kt-btn kt-btn-light">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function handleCategoryChange() {
    const select = document.getElementById('category_id');
    const selectedOption = select.options[select.selectedIndex];
    const categoryCode = selectedOption.dataset.code;
    const requiresL1 = selectedOption.dataset.requiresL1 === '1';
    const requiresL2 = selectedOption.dataset.requiresL2 === '1';
    
    // Show/hide fields based on category
    const leaveFields = document.getElementById('leave-fields');
    const amountField = document.getElementById('amount-field');
    const expenseCategoryField = document.getElementById('expense-category-field');
    const descriptionLabel = document.getElementById('description-label');
    const descriptionField = document.getElementById('description-field');
    const expenseCategorySelect = document.getElementById('expense_category');
    
    if (categoryCode === 'leave') {
        leaveFields.style.display = 'block';
        amountField.style.display = 'none';
        expenseCategoryField.style.display = 'none';
        // Make leave fields required
        document.querySelector('[name="leave_start_date"]').required = true;
        document.querySelector('[name="leave_end_date"]').required = true;
        document.querySelector('[name="amount"]').required = false;
        expenseCategorySelect.required = false;
        // Description optional for leave
        descriptionField.required = false;
        descriptionLabel.classList.remove('required');
        descriptionField.placeholder = 'Optional: Provide additional details about your leave';
    } else if (categoryCode === 'expense') {
        // EXPENSE: Show both expense category AND amount field
        leaveFields.style.display = 'none';
        expenseCategoryField.style.display = 'block';
        amountField.style.display = 'block';
        // Make expense category and amount required
        expenseCategorySelect.required = true;
        document.querySelector('[name="amount"]').required = true;
        document.querySelector('[name="leave_start_date"]').required = false;
        document.querySelector('[name="leave_end_date"]').required = false;
        // Description required for expense
        descriptionField.required = true;
        descriptionLabel.classList.add('required');
    } else if (categoryCode === 'advance') {
        // ADVANCE: Show only amount field, NO expense category
        leaveFields.style.display = 'none';
        expenseCategoryField.style.display = 'none';
        amountField.style.display = 'block';
        // Make amount required
        document.querySelector('[name="amount"]').required = true;
        document.querySelector('[name="leave_start_date"]').required = false;
        document.querySelector('[name="leave_end_date"]').required = false;
        expenseCategorySelect.required = false;
        // Description required for advance
        descriptionField.required = true;
        descriptionLabel.classList.add('required');
        descriptionField.placeholder = 'Provide detailed information about your request';
    } else {
        leaveFields.style.display = 'none';
        amountField.style.display = 'none';
        document.querySelector('[name="amount"]').required = false;
        document.querySelector('[name="leave_start_date"]').required = false;
        document.querySelector('[name="leave_end_date"]').required = false;
        // Description required for other types
        descriptionField.required = true;
        descriptionLabel.classList.add('required');
        descriptionField.placeholder = 'Provide detailed information about your request';
    }
    
    // Show approval info
    const approvalInfo = document.getElementById('approval-info');
    if (select.value) {
        let infoText = 'This request will require: ';
        if (requiresL1 && requiresL2) {
            infoText += '<strong>Level 1 AND Level 2 approval</strong>';
        } else if (requiresL1) {
            infoText += '<strong>Level 1 approval only</strong>';
        } else {
            infoText += '<strong>No approval required</strong> (auto-approved)';
        }
        approvalInfo.innerHTML = infoText;
    } else {
        approvalInfo.innerHTML = '';
    }
}

function calculateLeaveDays() {
    const startDate = document.querySelector('[name="leave_start_date"]').value;
    const endDate = document.querySelector('[name="leave_end_date"]').value;
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        
        const infoDiv = document.getElementById('leave-days-info');
        const textSpan = document.getElementById('leave-days-text');
        
        if (diffDays > 0) {
            textSpan.textContent = `Total leave days: ${diffDays} day(s)`;
            infoDiv.style.display = 'block';
        } else {
            infoDiv.style.display = 'none';
        }
    }
}

document.getElementById('request-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    
    // Show loading state
    const submitBtn = this.querySelector('[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="ki-filled ki-loading animate-spin"></i> Submitting...';
    
    fetch('{{ route("requests.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Request submitted successfully!');
            window.location.href = '/requests/' + data.request_id;
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});
</script>

@endsection

