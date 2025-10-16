@extends('layouts.app')

@section('title', 'Salary Slips')

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
                        <h3 class="kt-card-title text-lg font-semibold">Salary Slips Management</h3>
                    </div>

                    <!-- Right: Buttons -->
                    <div class="flex gap-2">
                        <a href="{{ route('hr.salary-slips.create') }}" class="kt-btn kt-btn-primary">
                            <i class="ki-filled ki-plus"></i> Generate Salary Slip
                        </a>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div class="kt-card-body">
                <!-- Filters -->
                <div class="flex gap-4 mb-6 flex-wrap">
                    <input type="text" id="filter-search" class="kt-input w-64" placeholder="Search by employee name or slip #...">
                    
                    <select id="filter-status" class="kt-select w-48">
                        <option value="">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="approved">Approved</option>
                        <option value="paid">Paid</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <input type="month" id="filter-month" class="kt-input w-48" placeholder="Salary Month">

                    <button onclick="loadSlips()" class="kt-btn kt-btn-primary">
                        <i class="ki-filled ki-filter"></i> Filter
                    </button>
                    <button onclick="clearFilters()" class="kt-btn kt-btn-light">
                        Clear
                    </button>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                    <div class="kt-card bg-gray-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-gray-600" id="stat-total">0</div>
                            <div class="text-sm text-gray-600">Total Slips</div>
                        </div>
                    </div>
                    <div class="kt-card bg-blue-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-blue-600" id="stat-draft">0</div>
                            <div class="text-sm text-gray-600">Draft</div>
                        </div>
                    </div>
                    <div class="kt-card bg-orange-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-orange-600" id="stat-approved">0</div>
                            <div class="text-sm text-gray-600">Approved</div>
                        </div>
                    </div>
                    <div class="kt-card bg-green-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-green-600" id="stat-paid">0</div>
                            <div class="text-sm text-gray-600">Paid</div>
                        </div>
                    </div>
                    <div class="kt-card bg-purple-50">
                        <div class="kt-card-body p-4">
                            <div class="text-2xl font-bold text-purple-600" id="stat-amount">PKR 0</div>
                            <div class="text-sm text-gray-600">Total Net Salary</div>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table text-sm kt-table-border">
                        <thead class="bg-gray-100 font-bold">
                            <tr>
                                <th class="w-[100px]">Slip #</th>
                                <th class="min-w-[150px]">Employee</th>
                                <th class="min-w-[100px]">Month</th>
                                <th class="text-right min-w-[110px]">Gross Salary</th>
                                <th class="text-right min-w-[110px]">Deductions</th>
                                <th class="text-right min-w-[110px]">Net Salary</th>
                                <th class="text-center min-w-[100px]">Status</th>
                                <th class="min-w-[120px]">Generated On</th>
                                <th class="text-center min-w-[160px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="slips-table-body">
                            <!-- Loaded dynamically -->
                        </tbody>
                    </table>
                </div>

                <!-- Loading State -->
                <div id="loading-state" class="text-center py-8 hidden">
                    <i class="ki-filled ki-loading animate-spin text-2xl text-gray-400"></i>
                    <p class="text-gray-500 mt-2">Loading salary slips...</p>
                </div>

                <!-- Empty State -->
                <div id="empty-state" class="text-center py-8 hidden">
                    <i class="ki-filled ki-information-2 text-4xl text-gray-300"></i>
                    <p class="text-gray-500 mt-2">No salary slips found</p>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('demo1_js')
<script>
// Load slips on page load
document.addEventListener('DOMContentLoaded', function() {
    loadSlips();
});

function loadSlips() {
    const tbody = document.getElementById('slips-table-body');
    const loadingState = document.getElementById('loading-state');
    const emptyState = document.getElementById('empty-state');
    
    // Show loading
    tbody.classList.add('hidden');
    emptyState.classList.add('hidden');
    loadingState.classList.remove('hidden');
    
    // Get filters
    const search = document.getElementById('filter-search').value;
    const status = document.getElementById('filter-status').value;
    const month = document.getElementById('filter-month').value;
    
    // Build query string
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (status) params.append('status', status);
    if (month) params.append('month', month);
    
    fetch(`{{ route('hr.salary-slips.data') }}?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            loadingState.classList.add('hidden');
            
            if (data.success && data.slips.length > 0) {
                renderSlips(data.slips);
                updateStatistics(data.statistics);
                tbody.classList.remove('hidden');
            } else {
                emptyState.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error loading slips:', error);
            loadingState.classList.add('hidden');
            emptyState.classList.remove('hidden');
        });
}

function renderSlips(slips) {
    const tbody = document.getElementById('slips-table-body');
    let html = '';
    
    slips.forEach(slip => {
        const statusClass = getStatusClass(slip.slip_status);
        const canApprove = slip.slip_status === 'draft' && {{ auth()->user()->hasPermission('approve_salary_slips') ? 'true' : 'false' }};
        
        html += `
            <tr>
                <td class="font-medium">${slip.slip_number || '#' + slip.id}</td>
                <td>
                    <div class="font-medium text-gray-900">${slip.employee?.fullname || 'N/A'}</div>
                    <div class="text-xs text-gray-500">${slip.employee?.email || ''}</div>
                </td>
                <td>${formatMonth(slip.salary_month)}</td>
                <td class="text-right font-medium text-green-600">${formatCurrency(slip.gross_salary)}</td>
                <td class="text-right font-medium text-red-600">${formatCurrency(slip.total_deductions)}</td>
                <td class="text-right font-bold text-blue-600">${formatCurrency(slip.net_salary)}</td>
                <td class="text-center">
                    <span class="kt-badge kt-badge-sm kt-badge-${statusClass}">
                        ${slip.slip_status.charAt(0).toUpperCase() + slip.slip_status.slice(1)}
                    </span>
                </td>
                <td>
                    <div class="text-sm">${formatDate(slip.created_at)}</div>
                    ${slip.has_manual_adjustments ? '<div class="text-xs text-orange-600"><i class="ki-filled ki-information-2"></i> Adjusted</div>' : ''}
                </td>
                <td class="text-center">
                    <div class="flex items-center justify-center gap-1">
                        <a href="/hr/salary-slips/${slip.id}" class="kt-btn kt-btn-sm kt-btn-light" title="View Details">
                            <i class="ki-filled ki-eye"></i>
                        </a>
                        ${canApprove ? `
                            <button onclick="approveSlip(${slip.id})" class="kt-btn kt-btn-sm kt-btn-success" title="Approve">
                                <i class="ki-filled ki-check"></i>
                            </button>
                        ` : ''}
                        ${slip.slip_status === 'approved' || slip.slip_status === 'paid' ? `
                            <a href="/hr/salary-slips/${slip.id}/pdf" target="_blank" class="kt-btn kt-btn-sm kt-btn-primary" title="Download PDF">
                                <i class="ki-filled ki-file-down"></i>
                            </a>
                        ` : ''}
                        ${slip.slip_status === 'draft' ? `
                            <button onclick="cancelSlip(${slip.id})" class="kt-btn kt-btn-sm kt-btn-danger" title="Cancel">
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
    document.getElementById('stat-total').textContent = stats.total || 0;
    document.getElementById('stat-draft').textContent = stats.draft || 0;
    document.getElementById('stat-approved').textContent = stats.approved || 0;
    document.getElementById('stat-paid').textContent = stats.paid || 0;
    document.getElementById('stat-amount').textContent = 'PKR ' + formatNumber(stats.total_net_salary || 0);
}

function clearFilters() {
    document.getElementById('filter-search').value = '';
    document.getElementById('filter-status').value = '';
    document.getElementById('filter-month').value = '';
    loadSlips();
}

function approveSlip(slipId) {
    if (!confirm('Approve this salary slip? This will finalize the amounts and allow payment processing.')) {
        return;
    }
    
    fetch(`{{ url('/hr/salary-slips') }}/${slipId}/approve`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Salary slip approved successfully!');
            loadSlips();
        } else {
            alert('Error: ' + (data.message || 'Failed to approve'));
        }
    })
    .catch(error => {
        console.error('Error approving slip:', error);
        alert('Error approving salary slip');
    });
}

function cancelSlip(slipId) {
    if (!confirm('Cancel this salary slip? This action cannot be undone.')) {
        return;
    }
    
    fetch(`{{ url('/hr/salary-slips') }}/${slipId}/cancel`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Salary slip cancelled');
            loadSlips();
        } else {
            alert('Error: ' + (data.message || 'Failed to cancel'));
        }
    });
}

function getStatusClass(status) {
    const classes = {
        'draft': 'secondary',
        'approved': 'warning',
        'paid': 'success',
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

function formatMonth(date) {
    const d = new Date(date);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${months[d.getMonth()]} ${d.getFullYear()}`;
}

function formatDate(datetime) {
    const d = new Date(datetime);
    return d.toLocaleDateString('en-PK', {day: '2-digit', month: 'short', year: 'numeric'});
}
</script>
@endpush

