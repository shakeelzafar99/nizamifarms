@extends('layouts.app')

@section('title', 'Employee Salary Management')

@section('content')

<!-- Container -->
<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <div class="kt-card kt-card-grid min-w-full">
            <!-- Header -->
            <div class="kt-card-header">
                <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center w-full gap-4">
                    <!-- Left: Title -->
                    <div class="flex items-center gap-4">
                        <h3 class="kt-card-title text-lg font-semibold">Employee Salary Management</h3>
                    </div>

                    <!-- Right: Buttons -->
                    <div class="flex gap-2">
                        <button onclick="checkMissingProfiles()" class="kt-btn kt-btn-light">
                            <i class="ki-filled ki-information-2"></i> Check Missing Profiles
                        </button>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div class="kt-card-body">
                <!-- Filters -->
                <div class="flex gap-4 mb-6 flex-wrap">
                    <input type="text" id="filter-search" class="kt-input w-64" placeholder="Search by name or employee code...">
                    
                    <select id="filter-status" class="kt-select w-48">
                        <option value="">All Status</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>

                    <button onclick="loadEmployees()" class="kt-btn kt-btn-primary">
                        <i class="ki-filled ki-filter"></i> Filter
                    </button>
                    <button onclick="clearFilters()" class="kt-btn kt-btn-light">
                        Clear
                    </button>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="kt-card bg-blue-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-blue-600" id="stat-total">0</div>
                            <div class="text-sm text-gray-600">Total Employees</div>
                        </div>
                    </div>
                    <div class="kt-card bg-green-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-green-600" id="stat-active">0</div>
                            <div class="text-sm text-gray-600">Active</div>
                        </div>
                    </div>
                    <div class="kt-card bg-orange-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-orange-600" id="stat-missing">0</div>
                            <div class="text-sm text-gray-600">Missing Profiles</div>
                        </div>
                    </div>
                    <div class="kt-card bg-purple-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-purple-600" id="stat-total-salary">PKR 0</div>
                            <div class="text-sm text-gray-600">Total Monthly Salary</div>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table text-sm kt-table-border">
                        <thead class="bg-gray-100 font-bold">
                            <tr>
                                <th class="w-[80px]">Code</th>
                                <th class="min-w-[180px]">Employee Name</th>
                                <th class="text-right min-w-[120px]">Loan Outstanding</th>
                                <th class="text-right min-w-[120px]">Salary Adv. Pending</th>
                                <th class="text-right min-w-[120px]">Base Salary</th>
                                <th class="text-center min-w-[100px]">OT Rate/hr</th>
                                <th class="text-center min-w-[120px]">Late Deduction/hr</th>
                                <th class="text-center min-w-[100px]">Status</th>
                                <th class="text-center min-w-[140px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="employees-table-body">
                            <!-- Loaded dynamically -->
                        </tbody>
                    </table>
                </div>

                <!-- Loading State -->
                <div id="loading-state" class="text-center py-8 hidden">
                    <i class="ki-filled ki-loading animate-spin text-2xl text-gray-400"></i>
                    <p class="text-gray-500 mt-2">Loading employees...</p>
                </div>

                <!-- Empty State -->
                <div id="empty-state" class="text-center py-8 hidden">
                    <i class="ki-filled ki-information-2 text-4xl text-gray-300"></i>
                    <p class="text-gray-500 mt-2">No employees found</p>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

<!-- Edit Salary Modal (Outside section to avoid Livewire conflicts) -->
<div id="edit-salary-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;" onclick="closeEditModal()">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()"
>
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
            <h3 class="text-lg font-semibold">Edit Salary Configuration</h3>
            <button onclick="closeEditModal()" class="kt-btn kt-btn-sm kt-btn-icon kt-btn-light">
                <i class="ki-filled ki-cross"></i>
            </button>
        </div>
        
        <form id="salary-form" onsubmit="saveSalary(event)">
            <div class="px-6 py-4 space-y-4">
                <input type="hidden" id="edit-user-id">
                
                <!-- Employee Info -->
                <div class="p-4 bg-gray-50 rounded-lg">
                    <div class="font-semibold text-gray-900" id="edit-employee-name"></div>
                    <div class="text-sm text-gray-600" id="edit-employee-info"></div>
                </div>

                <!-- Salary Configuration -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Base Salary (PKR) *</label>
                        <input type="number" id="edit-base-salary" name="base_salary" step="0.01" required 
                               class="kt-input w-full" placeholder="50000.00">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Salary Effective Date</label>
                        <input type="date" id="edit-effective-date" name="salary_effective_date" 
                               class="kt-input w-full">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Overtime Rate (PKR/hr)</label>
                        <input type="number" id="edit-overtime-rate" name="overtime_rate" step="0.01" 
                               class="kt-input w-full" placeholder="200.00">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Late Deduction (PKR/hr)</label>
                        <input type="number" id="edit-late-deduction" name="late_deduction_rate" step="0.01" 
                               class="kt-input w-full" placeholder="150.00">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Designation</label>
                        <input type="text" id="edit-designation" name="designation" 
                               class="kt-input w-full" placeholder="e.g. Sales Manager">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                        <input type="text" id="edit-department" name="department" 
                               class="kt-input w-full" placeholder="e.g. Sales">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Employee Code</label>
                        <input type="text" id="edit-employee-code" name="employee_code" 
                               class="kt-input w-full" placeholder="e.g. EMP001">
                    </div>
                </div>

                <!-- Bank Details (Optional) -->
                <div class="border-t pt-4">
                    <h4 class="font-medium text-gray-900 mb-3">Bank Details (Optional)</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Bank Name</label>
                            <input type="text" id="edit-bank-name" name="bank_name" 
                                   class="kt-input w-full" placeholder="e.g. Meezan Bank">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Account Number</label>
                            <input type="text" id="edit-account-number" name="bank_account_number" 
                                   class="kt-input w-full" placeholder="0123456789">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Account Title</label>
                            <input type="text" id="edit-account-title" name="bank_account_title" 
                                   class="kt-input w-full" placeholder="Full Name">
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="edit-is-active" name="is_active" class="kt-checkbox" checked>
                    <label for="edit-is-active" class="text-sm font-medium text-gray-700">Active</label>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-2 sticky bottom-0 bg-white">
                <button type="button" onclick="closeEditModal()" class="kt-btn kt-btn-light">Cancel</button>
                <button type="submit" class="kt-btn kt-btn-primary">
                    <i class="ki-filled ki-check"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

@push('demo1_js')
<script>
let currentView = 'all';

// Load employees on page load
document.addEventListener('DOMContentLoaded', function() {
    loadEmployees();
});

function loadEmployees() {
    const tbody = document.getElementById('employees-table-body');
    const loadingState = document.getElementById('loading-state');
    const emptyState = document.getElementById('empty-state');
    
    // Show loading
    tbody.classList.add('hidden');
    emptyState.classList.add('hidden');
    loadingState.classList.remove('hidden');
    
    // Get filters
    const search = document.getElementById('filter-search').value;
    const status = document.getElementById('filter-status').value;
    
    // Build query string
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (status) params.append('status', status);
    
    fetch(`{{ route('hr.employees.data') }}?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            loadingState.classList.add('hidden');
            
            if (data.success && data.employees.length > 0) {
                renderEmployees(data.employees);
                updateStatistics(data.statistics);
                tbody.classList.remove('hidden');
            } else {
                emptyState.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error loading employees:', error);
            loadingState.classList.add('hidden');
            emptyState.classList.remove('hidden');
        });
}

function renderEmployees(employees) {
    const tbody = document.getElementById('employees-table-body');
    let html = '';
    
    employees.forEach(emp => {
        const profile = emp.hr_profile;
        const hasProfile = profile && profile.id;
        
        html += `
            <tr class="${!hasProfile ? 'bg-yellow-50' : ''}">
                <td class="font-medium">${profile?.employee_code || '-'}</td>
                <td>
                    <div class="font-medium text-gray-900">${emp.fullname}</div>
                    <div class="text-xs text-gray-500">${emp.email || 'No email'}</div>
                </td>
                <td class="text-right ${emp.total_loan_outstanding > 0 ? 'text-red-600 font-semibold' : 'text-gray-500'}">
                    ${emp.total_loan_outstanding > 0 ? formatCurrency(emp.total_loan_outstanding) : '-'}
                </td>
                <td class="text-right ${emp.unadjusted_salary_advances > 0 ? 'text-orange-600 font-semibold' : 'text-gray-500'}">
                    ${emp.unadjusted_salary_advances > 0 ? formatCurrency(emp.unadjusted_salary_advances) : '-'}
                </td>
                <td class="text-right font-medium">${hasProfile ? formatCurrency(profile.base_salary) : '-'}</td>
                <td class="text-center">${hasProfile && profile.overtime_rate > 0 ? formatCurrency(profile.overtime_rate) : '-'}</td>
                <td class="text-center">${hasProfile && profile.late_deduction_rate > 0 ? formatCurrency(profile.late_deduction_rate) : '-'}</td>
                <td class="text-center">
                    ${hasProfile ? `
                        <span class="kt-badge kt-badge-sm kt-badge-${profile.is_active ? 'success' : 'secondary'}">
                            ${profile.is_active ? 'Active' : 'Inactive'}
                        </span>
                    ` : `
                        <span class="kt-badge kt-badge-sm kt-badge-warning">No Profile</span>
                    `}
                </td>
                <td class="text-center">
                    <div class="flex items-center justify-center gap-1">
                        <button onclick="editEmployee(${emp.id})" class="kt-btn kt-btn-sm kt-btn-light" title="Edit Salary">
                            <i class="ki-filled ki-notepad-edit"></i>
                        </button>
                        <a href="/hr/salary-slips/create?user_id=${emp.id}" class="kt-btn kt-btn-sm kt-btn-primary" title="Generate Salary Slip">
                            <i class="ki-filled ki-file-sheet"></i>
                        </a>
                    </div>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function updateStatistics(stats) {
    document.getElementById('stat-total').textContent = stats.total;
    document.getElementById('stat-active').textContent = stats.active;
    document.getElementById('stat-missing').textContent = stats.missing_profiles;
    document.getElementById('stat-total-salary').textContent = 'PKR ' + formatNumber(stats.total_salary);
}

function clearFilters() {
    document.getElementById('filter-search').value = '';
    document.getElementById('filter-status').value = '';
    loadEmployees();
}

function checkMissingProfiles() {
    fetch('{{ route("hr.employees.without-profiles") }}')
        .then(response => response.json())
        .then(data => {
            console.log('Without profiles response:', data);
            if (data.success && data.users && data.users.length > 0) {
                const names = data.users.map(u => u.fullname).join(', ');
                if (confirm(`Found ${data.users.length} employees without salary profiles:\n\n${names}\n\nCreate profiles for them now?`)) {
                    bulkCreateProfiles(data.users.map(u => u.user_id));
                }
            } else if (data.success) {
                alert('All employees have salary profiles! ✓');
            } else {
                alert('Error: ' + (data.message || 'Failed to load data'));
            }
        })
        .catch(error => {
            console.error('Error checking missing profiles:', error);
            alert('Error checking profiles: ' + error.message);
        });
}

function bulkCreateProfiles(userIds) {
    fetch('{{ route("hr.employees.bulk-create") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ user_ids: userIds })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Created ${data.created_count} salary profiles successfully!`);
            loadEmployees();
        }
    });
}

function editEmployee(userId) {
    console.log('Edit employee clicked:', userId);
    
    fetch(`{{ url('/hr/employees') }}/${userId}/get-or-create`)
        .then(response => response.json())
        .then(data => {
            console.log('Got employee data:', data);
            if (data.success) {
                openEditModal(data.employee);
            } else {
                alert('Error: ' + (data.message || 'Failed to load employee data'));
            }
        })
        .catch(error => {
            console.error('Error loading employee:', error);
            alert('Error loading employee data: ' + error.message);
        });
}

function openEditModal(employee) {
    console.log('Opening edit modal for employee:', employee);
    
    try {
        const profile = employee.hr_profile || {};
        
        document.getElementById('edit-user-id').value = employee.id;
        document.getElementById('edit-employee-name').textContent = employee.fullname;
        document.getElementById('edit-employee-info').textContent = employee.email || 'No email';
        
        document.getElementById('edit-base-salary').value = profile.base_salary || '';
        document.getElementById('edit-effective-date').value = profile.salary_effective_date || '';
        document.getElementById('edit-overtime-rate').value = profile.overtime_rate || '';
        document.getElementById('edit-late-deduction').value = profile.late_deduction_rate || '';
        document.getElementById('edit-designation').value = profile.designation || '';
        document.getElementById('edit-department').value = profile.department || '';
        document.getElementById('edit-employee-code').value = profile.employee_code || '';
        document.getElementById('edit-bank-name').value = profile.bank_name || '';
        document.getElementById('edit-account-number').value = profile.bank_account_number || '';
        document.getElementById('edit-account-title').value = profile.bank_account_title || '';
        document.getElementById('edit-is-active').checked = profile.is_active !== 0;
        
        const modal = document.getElementById('edit-salary-modal');
        console.log('Modal element:', modal);
        console.log('Modal computed display BEFORE:', window.getComputedStyle(modal).display);
        console.log('Modal computed visibility BEFORE:', window.getComputedStyle(modal).visibility);
        console.log('Modal computed z-index BEFORE:', window.getComputedStyle(modal).zIndex);
        
        // Remove hidden class
        modal.classList.remove('hidden');
        
        // Force display style as backup
        modal.style.display = 'flex';
        
        console.log('Modal computed display AFTER:', window.getComputedStyle(modal).display);
        console.log('Modal classList AFTER:', modal.classList.toString());
        console.log('Modal should now be visible');
        
        // Scroll to top of page to ensure modal is in view
        window.scrollTo(0, 0);
    } catch (error) {
        console.error('Error opening modal:', error);
        alert('Error opening edit modal: ' + error.message);
    }
}

function closeEditModal() {
    const modal = document.getElementById('edit-salary-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

function saveSalary(event) {
    event.preventDefault();
    
    const userId = document.getElementById('edit-user-id').value;
    const formData = new FormData(event.target);
    const data = Object.fromEntries(formData.entries());
    data.is_active = document.getElementById('edit-is-active').checked ? 1 : 0;
    
    fetch(`{{ url('/hr/employees') }}/${userId}/salary`, {
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
            alert('Salary configuration saved successfully!');
            closeEditModal();
            loadEmployees();
        } else {
            alert('Error: ' + (data.message || 'Failed to save'));
        }
    })
    .catch(error => {
        console.error('Error saving salary:', error);
        alert('Error saving salary configuration');
    });
}

function formatCurrency(amount) {
    return 'PKR ' + parseFloat(amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function formatNumber(amount) {
    return parseFloat(amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>
@endpush

