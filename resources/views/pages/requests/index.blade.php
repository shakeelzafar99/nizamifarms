{{-- resources/views/pages/requests/index.blade.php --}}

@extends('layouts.app')

@section('title', 'Requests Management')

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
                        <h3 class="kt-card-title text-lg font-semibold">Requests Management</h3>
                    </div>

                    <!-- Right: Buttons -->
                    <div class="flex gap-2">
                        <a href="{{ route('requests.settings.index') }}" class="kt-btn kt-btn-light">
                            <i class="ki-filled ki-setting-2"></i> Settings
                        </a>
                        <a href="{{ route('requests.create') }}" class="kt-btn kt-btn-primary">
                            <i class="ki-filled ki-plus"></i> New Request
                        </a>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="kt-card-body">
                <div class="flex gap-2 mb-6 border-b">
                    <button class="kt-tab-btn active" data-view="my" onclick="switchView('my')">
                        My Requests
                    </button>
                    @if($canApproveLevel1 || $canApproveLevel2)
                    <button class="kt-tab-btn" data-view="pending_approval" onclick="switchView('pending_approval')">
                        Pending My Approval
                        <span class="kt-badge kt-badge-sm kt-badge-warning ml-2" id="approval-count"></span>
                    </button>
                    @endif
                    <button class="kt-tab-btn" data-view="all" onclick="switchView('all')">
                        All Requests
                    </button>
                </div>
                
                @if(!$canApproveLevel1 && !$canApproveLevel2)
                <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded">
                    <p class="text-sm text-yellow-800">
                        <strong>ℹ️ Notice:</strong> You can submit requests, but you don't have approval rights yet. 
                        <a href="{{ route('requests.settings.index') }}" class="underline font-medium">Contact your admin</a> to get approval permissions assigned.
                    </p>
                </div>
                @endif

                <!-- Filters -->
                <div class="flex gap-4 mb-4 flex-wrap">
                    <select id="filter-status" class="kt-select w-48">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <select id="filter-category" class="kt-select w-48">
                        <option value="">All Categories</option>
                        @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->category_name }}</option>
                        @endforeach
                    </select>

                    <input type="date" id="filter-date-from" class="kt-input w-48" placeholder="From Date">
                    <input type="date" id="filter-date-to" class="kt-input w-48" placeholder="To Date">

                    <button onclick="loadRequests()" class="kt-btn kt-btn-primary">
                        <i class="ki-filled ki-filter"></i> Filter
                    </button>
                    <button onclick="clearFilters()" class="kt-btn kt-btn-light">
                        Clear
                    </button>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6" id="stats-cards">
                    <!-- Stats will be loaded here -->
                </div>

                <!-- Table -->
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table text-xs kt-table-border">
                        <thead class="bg-gray-100 font-bold">
                            <tr>
                                <th class="w-[120px]">Request #</th>
                                <th class="min-w-[150px]">Category</th>
                                <th class="min-w-[200px]">Title</th>
                                <th class="min-w-[180px]">Requester</th>
                                <th class="w-[100px]">Status</th>
                                <th class="w-[100px]">Priority</th>
                                <th class="w-[150px]">Submitted</th>
                                <th class="w-[120px] text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white" id="requests-tbody">
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="ki-filled ki-loading text-2xl animate-spin"></i>
                                    <p class="mt-2 text-gray-600">Loading requests...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.kt-tab-btn {
    padding: 10px 20px;
    border-bottom: 2px solid transparent;
    background: none;
    border: none;
    cursor: pointer;
    font-weight: 500;
    color: #6b7280;
    transition: all 0.2s;
}
.kt-tab-btn.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}
.kt-tab-btn:hover {
    color: #3b82f6;
}
</style>

<script>
let currentView = 'my';

function switchView(view) {
    currentView = view;
    document.querySelectorAll('.kt-tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-view="${view}"]`).classList.add('active');
    loadRequests();
}

function loadRequests() {
    const status = document.getElementById('filter-status').value;
    const category = document.getElementById('filter-category').value;
    const dateFrom = document.getElementById('filter-date-from').value;
    const dateTo = document.getElementById('filter-date-to').value;
    
    fetch(`{{ route('requests.data') }}?view=${currentView}&status=${status}&category_id=${category}&date_from=${dateFrom}&date_to=${dateTo}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderRequestsTable(data.data);
            }
        })
        .catch(error => {
            console.error('Error loading requests:', error);
            document.getElementById('requests-tbody').innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4 text-red-600">
                        Error loading requests. Please try again.
                    </td>
                </tr>
            `;
        });
}

function renderRequestsTable(requests) {
    const tbody = document.getElementById('requests-tbody');
    
    if (requests.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-gray-600">
                    No requests found.
                </td>
            </tr>
        `;
        return;
    }
    
    let html = '';
    requests.forEach(request => {
        // Check if created by someone else
        const createdByOther = request.created_by !== request.requester_user_id;
        const createdByName = createdByOther && request.created_by ? (request.created_by.fullname || 'Manager') : '';
        
        html += `
            <tr>
                <td class="font-medium">${request.request_number}</td>
                <td>${request.category.category_name}</td>
                <td>${request.title}</td>
                <td>
                    <div>
                        <div>${request.requester.fullname}</div>
                        ${createdByOther ? `
                            <div class="text-xs text-blue-600 mt-1">
                                <i class="ki-filled ki-information-2"></i>
                                via ${createdByName}
                            </div>
                        ` : ''}
                    </div>
                </td>
                <td>
                    <span class="kt-badge kt-badge-sm kt-badge-${getStatusClass(request.status)}">
                        ${request.status.charAt(0).toUpperCase() + request.status.slice(1)}
                    </span>
                </td>
                <td>
                    <span class="kt-badge kt-badge-sm kt-badge-outline kt-badge-${getPriorityClass(request.priority)}">
                        ${request.priority.charAt(0).toUpperCase() + request.priority.slice(1)}
                    </span>
                </td>
                <td>${formatDateTime(request.submitted_at)}</td>
                <td class="text-center">
                    <a href="/requests/${request.id}" class="kt-btn kt-btn-sm kt-btn-primary">
                        <i class="ki-filled ki-eye"></i> View
                    </a>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function loadStatistics() {
    fetch('{{ route("requests.approval.statistics") }}')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderStatistics(data.data);
            }
        });
}

function renderStatistics(stats) {
    let html = '';
    let totalPendingApprovals = 0;
    
    if (stats.my_pending !== undefined) {
        html += `
            <div class="kt-card bg-blue-50">
                <div class="kt-card-body p-4">
                    <div class="text-2xl font-bold text-blue-600">${stats.my_pending}</div>
                    <div class="text-sm text-gray-600">My Pending</div>
                </div>
            </div>
        `;
    }
    
    if (stats.pending_level_1 !== undefined) {
        totalPendingApprovals += stats.pending_level_1;
        html += `
            <div class="kt-card bg-orange-50">
                <div class="kt-card-body p-4">
                    <div class="text-2xl font-bold text-orange-600">${stats.pending_level_1}</div>
                    <div class="text-sm text-gray-600">Needs My Level 1 Approval</div>
                </div>
            </div>
        `;
    }
    
    if (stats.pending_level_2 !== undefined) {
        totalPendingApprovals += stats.pending_level_2;
        html += `
            <div class="kt-card bg-purple-50">
                <div class="kt-card-body p-4">
                    <div class="text-2xl font-bold text-purple-600">${stats.pending_level_2}</div>
                    <div class="text-sm text-gray-600">Needs My Level 2 Approval</div>
                </div>
            </div>
        `;
    }
    
    if (stats.my_approved !== undefined) {
        html += `
            <div class="kt-card bg-green-50">
                <div class="kt-card-body p-4">
                    <div class="text-2xl font-bold text-green-600">${stats.my_approved}</div>
                    <div class="text-sm text-gray-600">My Approved</div>
                </div>
            </div>
        `;
    }
    
    document.getElementById('stats-cards').innerHTML = html;
    
    // Update approval count badge
    const badge = document.getElementById('approval-count');
    if (badge && totalPendingApprovals > 0) {
        badge.textContent = totalPendingApprovals;
        badge.style.display = 'inline-block';
    } else if (badge) {
        badge.style.display = 'none';
    }
}

function getStatusClass(status) {
    const classes = {
        'pending': 'warning',
        'approved': 'success',
        'rejected': 'danger',
        'cancelled': 'secondary'
    };
    return classes[status] || 'secondary';
}

function getPriorityClass(priority) {
    const classes = {
        'low': 'secondary',
        'normal': 'primary',
        'high': 'warning',
        'urgent': 'danger'
    };
    return classes[priority] || 'primary';
}

function formatDateTime(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
}

function clearFilters() {
    document.getElementById('filter-status').value = '';
    document.getElementById('filter-category').value = '';
    document.getElementById('filter-date-from').value = '';
    document.getElementById('filter-date-to').value = '';
    loadRequests();
}

// Initial load
document.addEventListener('DOMContentLoaded', function() {
    loadRequests();
    loadStatistics();
});
</script>

@endsection

