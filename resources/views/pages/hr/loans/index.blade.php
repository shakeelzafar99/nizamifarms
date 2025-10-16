@extends('layouts.app')

@section('title', 'Employee Loans')

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
                        <h3 class="kt-card-title text-lg font-semibold">Employee Loans Management</h3>
                    </div>

                    <!-- Right: Buttons -->
                    <div class="flex gap-2">
                        @if(auth()->user()->hasPermission('manage_employee_loans'))
                        <button onclick="openCreateModal()" class="kt-btn kt-btn-primary">
                            <i class="ki-filled ki-plus"></i> Create Loan
                        </button>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div class="kt-card-body">
                <!-- Filters -->
                <div class="flex gap-4 mb-6 flex-wrap">
                    <input type="text" id="filter-search" class="kt-input w-64" placeholder="Search by employee name...">
                    
                    <select id="filter-status" class="kt-select w-48">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <button onclick="loadLoans()" class="kt-btn kt-btn-primary">
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
                            <div class="text-2xl font-bold text-blue-600" id="stat-active">0</div>
                            <div class="text-sm text-gray-600">Active Loans</div>
                        </div>
                    </div>
                    <div class="kt-card bg-green-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-green-600" id="stat-completed">0</div>
                            <div class="text-sm text-gray-600">Completed</div>
                        </div>
                    </div>
                    <div class="kt-card bg-purple-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-purple-600" id="stat-outstanding">PKR 0</div>
                            <div class="text-sm text-gray-600">Total Outstanding</div>
                        </div>
                    </div>
                    <div class="kt-card bg-orange-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-orange-600" id="stat-disbursed">PKR 0</div>
                            <div class="text-sm text-gray-600">Total Disbursed</div>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table text-sm kt-table-border">
                        <thead class="bg-gray-100 font-bold">
                            <tr>
                                <th class="w-[100px]">Loan #</th>
                                <th class="min-w-[150px]">Employee</th>
                                <th class="min-w-[100px]">Loan Type</th>
                                <th class="min-w-[100px]">Date</th>
                                <th class="text-right min-w-[120px]">Principal</th>
                                <th class="text-right min-w-[100px]">Monthly Inst.</th>
                                <th class="text-right min-w-[120px]">Outstanding</th>
                                <th class="text-center min-w-[100px]">Status</th>
                                <th class="text-center min-w-[140px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="loans-table-body">
                            <!-- Loaded dynamically -->
                        </tbody>
                    </table>
                </div>

                <!-- Loading State -->
                <div id="loading-state" class="text-center py-8 hidden">
                    <i class="ki-filled ki-loading animate-spin text-2xl text-gray-400"></i>
                    <p class="text-gray-500 mt-2">Loading loans...</p>
                </div>

                <!-- Empty State -->
                <div id="empty-state" class="text-center py-8 hidden">
                    <i class="ki-filled ki-information-2 text-4xl text-gray-300"></i>
                    <p class="text-gray-500 mt-2">No loans found</p>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

<!-- Create/Edit Loan Modal (Outside section to avoid Livewire conflicts) -->
<div id="loan-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;" onclick="closeLoanModal()">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
            <h3 class="text-lg font-semibold" id="modal-title">Create Employee Loan</h3>
            <button onclick="closeLoanModal()" class="kt-btn kt-btn-sm kt-btn-icon kt-btn-light">
                <i class="ki-filled ki-cross"></i>
            </button>
        </div>
        
        <form id="loan-form" onsubmit="saveLoan(event)">
            <div class="px-6 py-4 space-y-4">
                <input type="hidden" id="loan-id">
                
                <!-- Employee Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Employee *</label>
                    <select id="loan-user-id" name="user_id" required class="kt-select w-full">
                        <option value="">Select Employee...</option>
                        @php
                            $employees = \DB::table('t_sys_user')
                                ->where('is_active', 1)
                                ->orderBy('fullname')
                                ->get();
                        @endphp
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->fullname }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Loan Details -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Loan Date *</label>
                        <input type="date" id="loan-date" name="loan_date" required class="kt-input w-full">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Loan Number</label>
                        <input type="text" id="loan-number" name="loan_number" class="kt-input w-full" placeholder="Auto-generated if empty">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Principal Amount (PKR) *</label>
                        <input type="number" id="principal-amount" name="principal_amount" step="0.01" required 
                               class="kt-input w-full" placeholder="100000.00" onchange="calculateInstallment()">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Monthly Installment (PKR) *</label>
                        <input type="number" id="monthly-installment" name="monthly_installment" step="0.01" required 
                               class="kt-input w-full" placeholder="5000.00">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Loan Type</label>
                        <input type="text" id="loan-type" name="loan_type" class="kt-input w-full" 
                               placeholder="e.g. Personal, Emergency, Housing">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description/Purpose</label>
                        <textarea id="loan-description" name="description" rows="2" class="kt-input w-full" 
                                  placeholder="Purpose or reason for the loan"></textarea>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Terms & Conditions</label>
                        <textarea id="loan-terms" name="terms" rows="2" class="kt-input w-full" 
                                  placeholder="Loan terms, interest details, repayment schedule, etc."></textarea>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea id="loan-notes" name="notes" rows="2" class="kt-input w-full" 
                                  placeholder="Additional notes"></textarea>
                    </div>
                </div>

                <!-- Estimated Months -->
                <div class="p-4 bg-blue-50 rounded-lg">
                    <div class="text-sm text-blue-800">
                        <i class="ki-filled ki-information-2"></i>
                        <span id="estimated-months">Enter loan details to see estimated duration</span>
                    </div>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-2 sticky bottom-0 bg-white">
                <button type="button" onclick="closeLoanModal()" class="kt-btn kt-btn-light">Cancel</button>
                <button type="submit" class="kt-btn kt-btn-primary">
                    <i class="ki-filled ki-check"></i> Create Loan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Loan Details Modal -->
<div id="view-loan-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;" onclick="closeViewModal()">
    <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
            <h3 class="text-lg font-semibold">Loan Details</h3>
            <button onclick="closeViewModal()" class="kt-btn kt-btn-sm kt-btn-icon kt-btn-light">
                <i class="ki-filled ki-cross"></i>
            </button>
        </div>
        
        <div class="px-6 py-4" id="loan-details-content">
            <!-- Loaded dynamically -->
        </div>

        <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-2 sticky bottom-0 bg-white">
            <button type="button" onclick="closeViewModal()" class="kt-btn kt-btn-light">Close</button>
        </div>
    </div>
</div>

@push('demo1_js')
<script>
// Load loans on page load
document.addEventListener('DOMContentLoaded', function() {
    loadLoans();
    
    // Set default date to today
    document.getElementById('loan-date').valueAsDate = new Date();
});

function loadLoans() {
    const tbody = document.getElementById('loans-table-body');
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
    
    fetch(`{{ route('hr.loans.data') }}?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            loadingState.classList.add('hidden');
            
            if (data.success && data.loans.length > 0) {
                renderLoans(data.loans);
                updateStatistics(data.statistics);
                tbody.classList.remove('hidden');
            } else {
                emptyState.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error loading loans:', error);
            loadingState.classList.add('hidden');
            emptyState.classList.remove('hidden');
        });
}

function renderLoans(loans) {
    const tbody = document.getElementById('loans-table-body');
    let html = '';
    
    loans.forEach(loan => {
        const statusClass = getStatusClass(loan.loan_status);
        const progress = ((loan.principal_amount - loan.outstanding_balance) / loan.principal_amount * 100).toFixed(1);
        
        html += `
            <tr>
                <td class="font-medium">${loan.loan_number || '#' + loan.id}</td>
                <td>
                    <div class="font-medium text-gray-900">${loan.employee?.fullname || 'N/A'}</div>
                </td>
                <td>${loan.loan_type || '-'}</td>
                <td>${formatDate(loan.loan_date)}</td>
                <td class="text-right font-medium">${formatCurrency(loan.principal_amount)}</td>
                <td class="text-right">${formatCurrency(loan.monthly_installment)}</td>
                <td class="text-right">
                    <div class="font-bold ${loan.outstanding_balance > 0 ? 'text-orange-600' : 'text-green-600'}">
                        ${formatCurrency(loan.outstanding_balance)}
                    </div>
                    <div class="text-xs text-gray-500">${progress}% paid</div>
                </td>
                <td class="text-center">
                    <span class="kt-badge kt-badge-sm kt-badge-${statusClass}">
                        ${loan.loan_status.charAt(0).toUpperCase() + loan.loan_status.slice(1)}
                    </span>
                </td>
                <td class="text-center">
                    <div class="flex items-center justify-center gap-1">
                        <button onclick="viewLoan(${loan.id})" class="kt-btn kt-btn-sm kt-btn-light" title="View Details">
                            <i class="ki-filled ki-eye"></i>
                        </button>
                        ${loan.loan_status === 'active' && {{ auth()->user()->hasPermission('manage_employee_loans') ? 'true' : 'false' }} ? `
                            <button onclick="cancelLoan(${loan.id})" class="kt-btn kt-btn-sm kt-btn-danger" title="Cancel Loan">
                                <i class="ki-filled ki-trash"></i>
                            </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function updateStatistics(stats) {
    document.getElementById('stat-active').textContent = stats.active || 0;
    document.getElementById('stat-completed').textContent = stats.completed || 0;
    document.getElementById('stat-outstanding').textContent = 'PKR ' + formatNumber(stats.total_outstanding || 0);
    document.getElementById('stat-disbursed').textContent = 'PKR ' + formatNumber(stats.total_disbursed || 0);
}

function clearFilters() {
    document.getElementById('filter-search').value = '';
    document.getElementById('filter-status').value = '';
    loadLoans();
}

function openCreateModal() {
    document.getElementById('loan-form').reset();
    document.getElementById('loan-id').value = '';
    document.getElementById('modal-title').textContent = 'Create Employee Loan';
    document.getElementById('loan-date').valueAsDate = new Date();
    document.getElementById('loan-modal').classList.remove('hidden');
}

function closeLoanModal() {
    document.getElementById('loan-modal').classList.add('hidden');
}

function calculateInstallment() {
    const principal = parseFloat(document.getElementById('principal-amount').value) || 0;
    const installment = parseFloat(document.getElementById('monthly-installment').value) || 0;
    
    if (principal > 0 && installment > 0) {
        const months = Math.ceil(principal / installment);
        document.getElementById('estimated-months').textContent = 
            `Estimated duration: ${months} months (${Math.floor(months/12)} years ${months%12} months)`;
    }
}

function saveLoan(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    const data = Object.fromEntries(formData.entries());
    
    fetch('{{ route("hr.loans.store") }}', {
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
            alert('Loan created successfully!');
            closeLoanModal();
            loadLoans();
        } else {
            alert('Error: ' + (data.message || 'Failed to create loan'));
        }
    })
    .catch(error => {
        console.error('Error creating loan:', error);
        alert('Error creating loan');
    });
}

function viewLoan(loanId) {
    fetch(`{{ url('/hr/loans') }}/${loanId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderLoanDetails(data.loan);
                document.getElementById('view-loan-modal').classList.remove('hidden');
            }
        });
}

function renderLoanDetails(loan) {
    const progress = ((loan.principal_amount - loan.outstanding_balance) / loan.principal_amount * 100).toFixed(1);
    const paidAmount = loan.principal_amount - loan.outstanding_balance;
    
    let html = `
        <div class="space-y-4">
            <!-- Employee Info -->
            <div class="p-4 bg-gray-50 rounded-lg">
                <div class="font-semibold text-gray-900 text-lg">${loan.employee?.fullname || 'N/A'}</div>
                <div class="text-sm text-gray-600">${loan.employee?.email || ''}</div>
            </div>

            <!-- Loan Summary -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-sm text-gray-600">Loan Number</div>
                    <div class="font-semibold">${loan.loan_number || '#' + loan.id}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">Loan Date</div>
                    <div class="font-semibold">${formatDate(loan.loan_date)}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">Loan Type</div>
                    <div class="font-semibold">${loan.loan_type || '-'}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">Status</div>
                    <div><span class="kt-badge kt-badge-${getStatusClass(loan.loan_status)}">${loan.loan_status}</span></div>
                </div>
            </div>

            <!-- Financial Details -->
            <div class="border-t pt-4">
                <h4 class="font-semibold mb-3">Financial Details</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <div class="text-sm text-gray-600">Principal Amount</div>
                        <div class="font-bold text-lg">${formatCurrency(loan.principal_amount)}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600">Monthly Installment</div>
                        <div class="font-bold text-lg">${formatCurrency(loan.monthly_installment)}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600">Amount Paid</div>
                        <div class="font-bold text-lg text-green-600">${formatCurrency(paidAmount)}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600">Outstanding Balance</div>
                        <div class="font-bold text-lg text-orange-600">${formatCurrency(loan.outstanding_balance)}</div>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="mt-4">
                    <div class="flex justify-between text-sm mb-1">
                        <span>Payment Progress</span>
                        <span class="font-semibold">${progress}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3">
                        <div class="bg-green-500 h-3 rounded-full transition-all" style="width: ${progress}%"></div>
                    </div>
                </div>
            </div>

            ${loan.description ? `
            <div class="border-t pt-4">
                <h4 class="font-semibold mb-2">Description</h4>
                <p class="text-gray-700">${loan.description}</p>
            </div>
            ` : ''}

            ${loan.terms ? `
            <div class="border-t pt-4">
                <h4 class="font-semibold mb-2">Terms & Conditions</h4>
                <p class="text-gray-700">${loan.terms}</p>
            </div>
            ` : ''}

            ${loan.notes ? `
            <div class="border-t pt-4">
                <h4 class="font-semibold mb-2">Notes</h4>
                <p class="text-gray-700">${loan.notes}</p>
            </div>
            ` : ''}
        </div>
    `;
    
    document.getElementById('loan-details-content').innerHTML = html;
}

function closeViewModal() {
    document.getElementById('view-loan-modal').classList.add('hidden');
}

function cancelLoan(loanId) {
    const reason = prompt('Enter reason for cancelling this loan:');
    if (!reason) return;
    
    fetch(`{{ url('/hr/loans') }}/${loanId}/cancel`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ cancellation_reason: reason })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Loan cancelled successfully');
            loadLoans();
        } else {
            alert('Error: ' + (data.message || 'Failed to cancel'));
        }
    });
}

function getStatusClass(status) {
    const classes = {
        'active': 'primary',
        'completed': 'success',
        'cancelled': 'danger'
    };
    return classes[status] || 'secondary';
}

function formatCurrency(amount) {
    return 'PKR ' + parseFloat(amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function formatNumber(amount) {
    return parseFloat(amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function formatDate(date) {
    const d = new Date(date);
    return d.toLocaleDateString('en-PK', {day: '2-digit', month: 'short', year: 'numeric'});
}

// Recalculate on input change
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('principal-amount')?.addEventListener('input', calculateInstallment);
    document.getElementById('monthly-installment')?.addEventListener('input', calculateInstallment);
});
</script>
@endpush

