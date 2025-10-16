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
                        <select name="expense_category" id="expense_category" class="kt-select" onchange="handleExpenseCategoryChange()">
                            <option value="">Select Expense Type</option>
                            @php
                                $expenseCategories = \App\Models\FIN\ConfigModel::where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
                                    ->orderBy('config_value')
                                    ->pluck('config_value');
                            @endphp
                            @if($expenseCategories->count() > 0)
                                @foreach($expenseCategories as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            @else
                                {{-- Fallback if no categories in database --}}
                                <option value="Petrol">Petrol</option>
                                <option value="Rent">Rent</option>
                                <option value="Office Supplies">Office Supplies</option>
                            @endif
                            <option value="__ADD_NEW__" style="background-color: #f3f4f6; font-weight: bold; color: #059669;">➕ Add New Category...</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Select the type of expense for proper accounting, or add a new category</p>
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
    const hiddenTitle = document.getElementById('hidden-title');
    
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
        // Set title to "leave"
        hiddenTitle.value = 'leave';
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
        // Set title to "expense" (will be updated when expense category is selected)
        hiddenTitle.value = 'expense';
    } else if (categoryCode === 'advance' || categoryCode === 'salary_advance') {
        // ADVANCE/SALARY ADVANCE: Show only amount field, NO expense category
        leaveFields.style.display = 'none';
        expenseCategoryField.style.display = 'none';
        amountField.style.display = 'block';
        // Make amount required
        const amountInput = document.querySelector('[name="amount"]');
        amountInput.required = true;
        // Set default value to 5000 for salary advance (approvers can change it)
        if (!amountInput.value || amountInput.value == '0') {
            amountInput.value = '5000.00';
            amountInput.placeholder = 'Default: 5000.00 (can be changed by approver)';
        }
        document.querySelector('[name="leave_start_date"]').required = false;
        document.querySelector('[name="leave_end_date"]').required = false;
        expenseCategorySelect.required = false;
        // Set title to "advance"
        hiddenTitle.value = 'advance';
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

function handleExpenseCategoryChange() {
    const expenseCategorySelect = document.getElementById('expense_category');
    const selectedValue = expenseCategorySelect.value;
    
    if (selectedValue === '__ADD_NEW__') {
        // Open modal to add new category
        openInlineExpenseCategoryModal();
        // Reset selection
        expenseCategorySelect.value = '';
    } else {
        // Update title
        updateExpenseTitle();
    }
}

function updateExpenseTitle() {
    const expenseCategorySelect = document.getElementById('expense_category');
    const hiddenTitle = document.getElementById('hidden-title');
    const selectedExpense = expenseCategorySelect.value;
    
    if (selectedExpense && selectedExpense !== '__ADD_NEW__') {
        // Set title to the selected expense category
        hiddenTitle.value = selectedExpense;
    } else {
        // Fallback to "expense" if no category selected yet
        hiddenTitle.value = 'expense';
    }
}

function openInlineExpenseCategoryModal() {
    document.getElementById('inlineExpenseCategoryModal').classList.remove('hidden');
    document.getElementById('inlineExpenseCategoryModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeInlineExpenseCategoryModal() {
    document.getElementById('inlineExpenseCategoryModal').classList.add('hidden');
    document.getElementById('inlineExpenseCategoryModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function submitInlineCategory() {
    const categoryName = document.getElementById('inline_category_name').value.trim();
    
    if (!categoryName) {
        alert('Please enter a category name');
        return;
    }
    
    // Show loading
    const submitBtn = document.querySelector('#inlineExpenseCategoryModal button[type="button"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '⏳ Creating...';
    submitBtn.disabled = true;
    
    // Submit via AJAX
    fetch('{{ route("fin.expense-category.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ category_name: categoryName })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success || data.message.includes('successfully')) {
            // Add to dropdown
            const expenseCategorySelect = document.getElementById('expense_category');
            const newOption = document.createElement('option');
            newOption.value = categoryName;
            newOption.textContent = categoryName;
            
            // Insert before the "Add New" option
            const addNewOption = expenseCategorySelect.querySelector('option[value="__ADD_NEW__"]');
            expenseCategorySelect.insertBefore(newOption, addNewOption);
            
            // Select the new option
            expenseCategorySelect.value = categoryName;
            updateExpenseTitle();
            
            // Close modal
            closeInlineExpenseCategoryModal();
            document.getElementById('inline_category_name').value = '';
            
            alert('✓ Category "' + categoryName + '" created successfully!');
        } else {
            alert('Error: ' + (data.message || 'Failed to create category'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
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

<!-- Inline Expense Category Modal -->
<div id="inlineExpenseCategoryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">➕ Add New Expense Category</h2>
                <button onclick="closeInlineExpenseCategoryModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category Name <span class="text-red-500">*</span></label>
                    <input type="text" id="inline_category_name"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500"
                           placeholder="e.g., Fuel, Marketing, Travel">
                </div>
                
                <div class="p-3 bg-purple-50 border border-purple-200 rounded-md">
                    <p class="text-xs text-purple-800">
                        ℹ️ <strong>System will automatically:</strong>
                    </p>
                    <ul class="text-xs text-purple-700 mt-1 ml-4 list-disc">
                        <li>Create an expense account (e.g., EXP_FUEL)</li>
                        <li>Add to expense type dropdown</li>
                        <li>Make it available for all expense requests</li>
                    </ul>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeInlineExpenseCategoryModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="button" onclick="submitInlineCategory()" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-md">
                        ✓ Create & Select
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

