@extends('layouts.app')

@section('title', 'Approvals Dashboard')

@push('demo1_css')
<style>
    /* Layer 1 Cards - Compact */
    .level-card {
        background: white;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        padding: 12px;
        cursor: pointer;
        transition: all 0.2s;
        height: 80px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .level-card:hover {
        border-color: #3b82f6;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }

    .level-card.active {
        border-color: #3b82f6;
        background: #eff6ff;
        border-width: 3px;
    }

    .level-card .title {
        font-size: 11px;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .level-card .count {
        font-size: 18px;
        font-weight: 700;
        color: #111827;
        margin: 4px 0;
    }

    .level-card .amount {
        font-size: 14px;
        font-weight: 600;
        color: #059669;
    }

    /* Layer 2 Cards - Smaller but more visible */
    .area-card {
        background: white;
        border: 2px solid #d1d5db;
        border-radius: 8px;
        padding: 10px;
        cursor: pointer;
        transition: all 0.2s;
        height: 75px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        min-width: 0;
    }

    .area-card:hover {
        background: #f9fafb;
        border-color: #9ca3af;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        transform: translateY(-1px);
    }

    .area-card.active {
        background: #eff6ff;
        border-color: #3b82f6;
        border-width: 3px;
        box-shadow: 0 4px 6px rgba(59,130,246,0.2);
    }

    .area-card .icon {
        font-size: 20px;
        margin-bottom: 2px;
    }

    .area-card .title {
        font-size: 10px;
        font-weight: 700;
        color: #374151;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .area-card .stats {
        font-size: 10px;
        font-weight: 600;
        color: #111827;
        white-space: nowrap;
    }

    /* Layer 1 Container - Collapsible */
    #layer1Container {
        max-height: 200px;
        overflow: hidden;
        transition: all 0.3s ease-in-out;
        opacity: 1;
    }

    #layer1Container.collapsed {
        max-height: 0;
        opacity: 0;
        margin-bottom: 0;
    }

    /* Layer 2 Container - Hidden by default */
    #layer2Container {
        max-height: 0;
        overflow: hidden;
        transition: all 0.3s ease-in-out;
        opacity: 0;
    }

    #layer2Container.show {
        max-height: 120px;
        opacity: 1;
    }

    /* Color accents */
    .level-card.l1-pending { border-left: 4px solid #f59e0b; }
    .level-card.l2-pending { border-left: 4px solid #3b82f6; }
    .level-card.approved { border-left: 4px solid #10b981; }
    .level-card.rejected { border-left: 4px solid #ef4444; }

    .area-card.exp-fund { border-left: 3px solid #f97316; }
    .area-card.nf-cash { border-left: 3px solid #22c55e; }
    .area-card.online { border-left: 3px solid #3b82f6; }
    .area-card.others { border-left: 3px solid #6b7280; }

    /* Table styling */
    .approvals-table {
        font-size: 14px;
    }

    .approvals-table th {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        color: #6b7280;
        padding: 12px 16px;
    }

    .approvals-table td {
        padding: 12px 16px;
    }

    .badge {
        padding: 4px 8px;
        border-radius: 9999px;
        font-size: 11px;
        font-weight: 600;
    }

    .badge-yellow { background: #fef3c7; color: #92400e; }
    .badge-blue { background: #dbeafe; color: #1e40af; }
    .badge-green { background: #d1fae5; color: #065f46; }
    .badge-red { background: #fee2e2; color: #991b1b; }
    .badge-gray { background: #f3f4f6; color: #374151; }
</style>
@endpush

@section('content')
<div class="px-4 py-6" style="max-width: 100%;">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">🎯 Approvals Dashboard</h1>
        <p class="text-gray-600 mt-2">Unified view of all pending approvals</p>
    </div>

    <!-- Layer 1: Level Cards (Collapsible) -->
    <div id="layer1Container" class="mb-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <!-- L1 Pending Card -->
            @if($hasLevel1Rights)
            <div class="level-card l1-pending" id="card-l1" data-level="l1">
                <div class="title">
                    <span>📋</span>
                    <span>L1 PENDING</span>
                </div>
                <div class="count">{{ $summaries['l1']['count'] }} items</div>
                <div class="amount">Rs. {{ number_format($summaries['l1']['amount'], 0) }}</div>
            </div>
            @endif

            <!-- L2 Pending Card -->
            @if($hasLevel2Rights)
            <div class="level-card l2-pending" id="card-l2" data-level="l2">
                <div class="title">
                    <span>📋</span>
                    <span>L2 PENDING</span>
                </div>
                <div class="count">{{ $summaries['l2']['count'] }} items</div>
                <div class="amount">Rs. {{ number_format($summaries['l2']['amount'], 0) }}</div>
            </div>
            @endif

            <!-- Approved Card -->
            <div class="level-card approved" id="card-approved" data-level="approved">
                <div class="title">
                    <span>✅</span>
                    <span>APPROVED</span>
                </div>
                <div class="count">{{ $summaries['approved']['count'] }} items</div>
                <div class="amount">Rs. {{ number_format($summaries['approved']['amount'], 0) }}</div>
            </div>

            <!-- Rejected Card -->
            <div class="level-card rejected" id="card-rejected" data-level="rejected">
                <div class="title">
                    <span>❌</span>
                    <span>REJECTED</span>
                </div>
                <div class="count">{{ $summaries['rejected']['count'] }} items</div>
                <div class="amount">Rs. {{ number_format($summaries['rejected']['amount'], 0) }}</div>
            </div>
        </div>
    </div>

    <!-- Layer 2: Area Cards (Hidden by default) -->
    <div id="layer2Container" class="mb-4">
        <div class="flex items-center justify-between mb-3">
            <div class="text-sm font-semibold text-gray-700">
                <span id="breadcrumb" class="text-blue-600"></span> → Filter by Area:
            </div>
            <button onclick="clearFilters()" class="text-xs text-gray-600 hover:text-blue-600 transition">
                ← Back to All Levels
            </button>
        </div>
        <div class="grid grid-cols-4 gap-2" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
            <!-- EXP_FUND Card -->
            <div class="area-card exp-fund" id="area-exp-fund" data-area="exp_fund">
                <div class="icon">💰</div>
                <div class="title">EXP FUND</div>
                <div class="stats">
                    <span id="area-exp-fund-count">0</span> items • 
                    Rs. <span id="area-exp-fund-amount">0</span>
                </div>
            </div>

            <!-- NF_CASH Card -->
            <div class="area-card nf-cash" id="area-nf-cash" data-area="nf_cash">
                <div class="icon">💵</div>
                <div class="title">NF CASH</div>
                <div class="stats">
                    <span id="area-nf-cash-count">0</span> items • 
                    Rs. <span id="area-nf-cash-amount">0</span>
                </div>
            </div>

            <!-- ONLINE Card -->
            <div class="area-card online" id="area-online" data-area="online">
                <div class="icon">🏦</div>
                <div class="title">ONLINE</div>
                <div class="stats">
                    <span id="area-online-count">0</span> items • 
                    Rs. <span id="area-online-amount">0</span>
                </div>
            </div>

            <!-- OTHERS Card -->
            <div class="area-card others" id="area-others" data-area="others">
                <div class="icon">📦</div>
                <div class="title">OTHERS</div>
                <div class="stats">
                    <span id="area-others-count">0</span> items • 
                    Rs. <span id="area-others-amount">0</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Table Container -->
    <div class="bg-white rounded-lg shadow-md border border-gray-200">
        <!-- Table Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900" id="tableTitle">All Pending Approvals</h2>
                <p class="text-sm text-gray-600 mt-1" id="tableSubtitle">
                    <span id="itemCount">0</span> items • Rs. <span id="totalAmount">0</span>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <!-- Assignee filter (My assignments / specific user / all) -->
                <div class="flex items-center gap-2">
                    <label for="assigneeFilter" class="text-xs font-medium text-gray-600">Assignee:</label>
                    <select id="assigneeFilter"
                            class="kt-select kt-select-sm min-w-[180px]"
                            onchange="onAssigneeFilterChange()">
                        <option value="">All approvers</option>
                        <option value="{{ auth()->id() }}">My assignments ({{ auth()->user()->fullname ?? auth()->user()->name }})</option>
                        @foreach($approverUsers as $approver)
                            @if($approver->id !== auth()->id())
                            <option value="{{ $approver->id }}">
                                {{ $approver->fullname ?? $approver->name ?? $approver->email }}
                            </option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <button id="clearFiltersBtn" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md transition">
                    Clear Filters
                </button>
            </div>
        </div>

        <!-- Loading State -->
        <div id="loadingState" class="p-8 text-center hidden">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <p class="text-gray-600 mt-2">Loading...</p>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full approvals-table" id="approvalsTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left">REQUEST #</th>
                        <th class="text-left">REQUESTER</th>
                        <th class="text-left">CATEGORY</th>
                        <th class="text-left">AREA</th>
                        <th class="text-right">AMOUNT</th>
                        <th class="text-center">LEVEL</th>
                        <th class="text-left">DATE</th>
                        <th class="text-center">ACTION</th>
                    </tr>
                </thead>
                <tbody id="tableBody" class="divide-y divide-gray-100">
                    <tr>
                        <td colspan="8" class="p-8 text-center text-gray-500">
                            Select a filter above to view approvals
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection

@push('demo1_js')
<script>
    // State management
    window.approvalFilters = {
        level: null,
        area: null,
        search: '',
        assignee_id: null
    };

    // Summaries from backend
    const summaries = @json($summaries);

    // Format datetime to readable format (Pakistan Time)
    function formatDateTime(dateString) {
        if (!dateString || dateString === '-') return '-';
        
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString; // Invalid date
            
            // Format as: Oct 20, 2025 3:45 PM
            const options = {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            };
            
            return date.toLocaleString('en-US', options);
        } catch (e) {
            return dateString;
        }
    }

    // Filter by level
    function filterByLevel(level) {
        // Toggle if clicking same card
        if (window.approvalFilters.level === level) {
            clearFilters();
            return;
        }

        window.approvalFilters.level = level;
        window.approvalFilters.area = null; // Reset area filter

        // Update card active states
        document.querySelectorAll('.level-card').forEach(card => card.classList.remove('active'));
        document.getElementById('card-' + level).classList.add('active');
        document.querySelectorAll('.area-card').forEach(card => card.classList.remove('active'));

        // Collapse Layer 1, Show Layer 2
        document.getElementById('layer1Container').classList.add('collapsed');
        
        if (level === 'l1' || level === 'l2') {
            showLayer2(level);
        } else {
            hideLayer2();
        }

        // Load data
        loadTableData();
    }

    // Filter by area
    function filterByArea(area) {
        // Toggle if clicking same card
        if (window.approvalFilters.area === area) {
            window.approvalFilters.area = null;
            document.querySelectorAll('.area-card').forEach(card => card.classList.remove('active'));
        } else {
            window.approvalFilters.area = area;
            document.querySelectorAll('.area-card').forEach(card => card.classList.remove('active'));
            // Replace underscores with hyphens for ID matching
            const cardId = 'area-' + area.replace(/_/g, '-');
            document.getElementById(cardId).classList.add('active');
        }

        // Load data
        loadTableData();
    }

    // Show Layer 2 and populate area stats
    function showLayer2(level) {
        console.log('showLayer2 called with level:', level);
        console.log('Summaries:', summaries);
        
        const container = document.getElementById('layer2Container');
        container.classList.add('show');

        // Update breadcrumb
        const levelNames = {
            'l1': 'L1 Pending',
            'l2': 'L2 Pending',
            'approved': 'Approved',
            'rejected': 'Rejected'
        };
        document.getElementById('breadcrumb').textContent = levelNames[level];

        // Get area breakdown for this level
        const areaData = summaries[level]['by_area'];
        console.log('Area data for', level, ':', areaData);

        // Update area cards
        Object.keys(areaData).forEach(area => {
            const count = areaData[area].count || 0;
            const amount = areaData[area].amount || 0;
            
            console.log(`Updating ${area}: count=${count}, amount=${amount}`);
            
            document.getElementById(`area-${area.replace('_', '-')}-count`).textContent = count;
            document.getElementById(`area-${area.replace('_', '-')}-amount`).textContent = amount.toLocaleString();
        });
    }

    // Hide Layer 2
    function hideLayer2() {
        document.getElementById('layer2Container').classList.remove('show');
    }

    // Clear all filters
    function clearFilters() {
        window.approvalFilters = {
            level: null,
            area: null,
            search: '',
            assignee_id: null
        };

        // Reset assignee dropdown
        const assigneeSelect = document.getElementById('assigneeFilter');
        if (assigneeSelect) {
            assigneeSelect.value = '';
        }

        // Remove all active states
        document.querySelectorAll('.level-card').forEach(card => card.classList.remove('active'));
        document.querySelectorAll('.area-card').forEach(card => card.classList.remove('active'));

        // Restore Layer 1, Hide Layer 2
        document.getElementById('layer1Container').classList.remove('collapsed');
        hideLayer2();

        // Load all pending items again
        loadAllPendingItems();
    }

    // Load table data via AJAX
    function loadTableData() {
        const { level, area, search, assignee_id } = window.approvalFilters;

        // Show loading state
        document.getElementById('loadingState').classList.remove('hidden');
        document.getElementById('approvalsTable').classList.add('opacity-50');

        // Build query params
        const params = new URLSearchParams();
        if (level) params.append('level', level);
        if (area) params.append('area', area);
        if (search) params.append('search', search);
        if (assignee_id) params.append('assignee_id', assignee_id);

        // Make AJAX request
        fetch(`/approvals?${params.toString()}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            // Hide loading state
            document.getElementById('loadingState').classList.add('hidden');
            document.getElementById('approvalsTable').classList.remove('opacity-50');

            if (data.success) {
                renderTable(data.items, data.count, data.total_amount);
                
                // Update summary cards if filtered summaries are provided
                if (data.summaries) {
                    updateSummaryCards(data.summaries);
                }
            } else {
                alert('Error loading data');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('loadingState').classList.add('hidden');
            document.getElementById('approvalsTable').classList.remove('opacity-50');
            alert('Error loading data');
        });
    }
    
    // Update summary cards with filtered data
    function updateSummaryCards(filteredSummaries) {
        // Update L1 card
        const l1Card = document.querySelector('[data-level="l1"]');
        if (l1Card && filteredSummaries.l1) {
            l1Card.querySelector('.count').textContent = filteredSummaries.l1.count;
            l1Card.querySelector('.amount').textContent = 'Rs. ' + filteredSummaries.l1.amount.toLocaleString();
        }
        
        // Update L2 card
        const l2Card = document.querySelector('[data-level="l2"]');
        if (l2Card && filteredSummaries.l2) {
            l2Card.querySelector('.count').textContent = filteredSummaries.l2.count;
            l2Card.querySelector('.amount').textContent = 'Rs. ' + filteredSummaries.l2.amount.toLocaleString();
        }
        
        // Update area cards if Layer 2 is visible and we have a level selected
        if (window.approvalFilters.level && (window.approvalFilters.level === 'l1' || window.approvalFilters.level === 'l2')) {
            const areaData = filteredSummaries[window.approvalFilters.level].by_area;
            Object.keys(areaData).forEach(area => {
                const count = areaData[area].count || 0;
                const amount = areaData[area].amount || 0;
                
                const countEl = document.getElementById(`area-${area.replace('_', '-')}-count`);
                const amountEl = document.getElementById(`area-${area.replace('_', '-')}-amount`);
                
                if (countEl) countEl.textContent = count;
                if (amountEl) amountEl.textContent = amount.toLocaleString();
            });
        }
        
        // Store filtered summaries globally for layer 2 navigation
        window.filteredSummaries = filteredSummaries;
    }

    // Render table
    function renderTable(items, count, totalAmount) {
        // Update title
        let title = 'All Pending Approvals';
        if (window.approvalFilters.level) {
            const levelNames = {
                'l1': 'Level 1 Pending',
                'l2': 'Level 2 Pending',
                'approved': 'Approved',
                'rejected': 'Rejected'
            };
            title = levelNames[window.approvalFilters.level];

            if (window.approvalFilters.area) {
                const areaNames = {
                    'exp_fund': 'EXP FUND',
                    'nf_cash': 'NF CASH',
                    'online': 'ONLINE',
                    'others': 'OTHERS'
                };
                title += ' > ' + areaNames[window.approvalFilters.area];
            }
        }

        document.getElementById('tableTitle').textContent = title;
        document.getElementById('itemCount').textContent = count;
        document.getElementById('totalAmount').textContent = totalAmount.toLocaleString();

        // Render rows
        const tbody = document.getElementById('tableBody');
        if (items.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="p-8 text-center text-gray-500">
                        No items found
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = items.map(item => {
            const areaLabels = {
                'exp_fund': '💰 EXP FUND',
                'nf_cash': '💵 NF CASH',
                'online': '🏦 ONLINE',
                'others': '📦 OTHERS'
            };

            const levelBadge = item.level ? 
                `<span class="badge badge-${item.level === 1 ? 'yellow' : 'blue'}">L${item.level}</span>` : 
                '<span class="badge badge-gray">-</span>';

            return `
                <tr class="hover:bg-gray-50">
                    <td class="font-medium text-blue-600">${item.number}</td>
                    <td>${item.requester}</td>
                    <td>${item.category}</td>
                    <td class="text-sm">${areaLabels[item.area] || item.area}</td>
                    <td class="text-right font-semibold">
                        ${item.amount > 0 ? 'Rs. ' + item.amount.toLocaleString() : 
                          item.leave_days > 0 ? item.leave_days + ' days' : '-'}
                    </td>
                    <td class="text-center">${levelBadge}</td>
                    <td class="text-sm text-gray-600">${formatDateTime(item.date)}</td>
                    <td class="text-center">
                        <button onclick="openApprovalModal('${item.view_url}')" 
                           class="inline-block px-3 py-1 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition">
                            View & Approve
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Store original summaries for reset functionality
        window.originalSummaries = {
            l1: {
                count: {{ $summaries['l1']['count'] }},
                amount: {{ $summaries['l1']['amount'] }},
                by_area: @json($summaries['l1']['by_area'])
            },
            l2: {
                count: {{ $summaries['l2']['count'] }},
                amount: {{ $summaries['l2']['amount'] }},
                by_area: @json($summaries['l2']['by_area'])
            }
        };
        
        // Attach event listeners to level cards
        document.querySelectorAll('.level-card').forEach(card => {
            card.addEventListener('click', function() {
                const level = this.getAttribute('data-level');
                filterByLevel(level);
            });
        });

        // Attach event listeners to area cards
        document.querySelectorAll('.area-card').forEach(card => {
            card.addEventListener('click', function() {
                const area = this.getAttribute('data-area');
                filterByArea(area);
            });
        });

        // Attach event listener to clear filters button
        document.getElementById('clearFiltersBtn').addEventListener('click', clearFilters);

        // Load all pending items by default (no filter)
        loadAllPendingItems();
    });

    // Load all pending items (L1 + L2 + Ledger transactions)
    function loadAllPendingItems() {
        // Keep current assignee filter but reset level/area/search
        const currentAssignee = window.approvalFilters.assignee_id || null;
        window.approvalFilters = {
            level: null,
            area: null,
            search: '',
            assignee_id: currentAssignee
        };

        document.getElementById('tableTitle').textContent = 'All Pending Approvals';
        loadTableData();
    }

    // Handle assignee filter changes
    function onAssigneeFilterChange() {
        const select = document.getElementById('assigneeFilter');
        if (!select) return;

        const value = select.value;
        window.approvalFilters.assignee_id = value ? parseInt(value, 10) : null;

        // If filter is cleared, restore original summaries
        if (!value) {
            restoreOriginalSummaries();
        }

        // If level/area filters are set, respect them; otherwise show all pending
        if (window.approvalFilters.level || window.approvalFilters.area || window.approvalFilters.search) {
            loadTableData();
        } else {
            loadAllPendingItems();
        }
    }
    
    // Restore original summaries (from page load)
    function restoreOriginalSummaries() {
        if (window.originalSummaries) {
            updateSummaryCards(window.originalSummaries);
        }
    }

    // Modal functions
    function openApprovalModal(url) {
        const modal = document.getElementById('approvalModal');
        const iframe = document.getElementById('approvalIframe');
        
        // Add origin parameter to URL if not already present
        const separator = url.includes('?') ? '&' : '?';
        const fullUrl = url.includes('origin=') ? url : url + separator + 'origin=approvals';
        
        iframe.src = fullUrl;
        modal.style.display = 'flex';
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }

    function closeApprovalModal() {
        const modal = document.getElementById('approvalModal');
        const iframe = document.getElementById('approvalIframe');
        
        modal.style.display = 'none';
        modal.classList.add('hidden');
        iframe.src = 'about:blank'; // Clear iframe
        document.body.style.overflow = ''; // Restore scrolling
        
        // Reload the approvals data to reflect any changes
        if (window.approvalFilters.level || window.approvalFilters.area || window.approvalFilters.search) {
            loadTableData();
        } else {
            loadAllPendingItems();
        }
    }

    // Listen for messages from iframe (when approval/rejection is complete)
    window.addEventListener('message', function(event) {
        // Check if message is from our iframe
        if (event.data && event.data.type === 'approval_complete') {
            // Show success message if provided
            if (event.data.message) {
                // Create a temporary success toast
                const toast = document.createElement('div');
                toast.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 16px 24px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); z-index: 999999; font-weight: 600;';
                toast.textContent = '✓ ' + event.data.message;
                document.body.appendChild(toast);
                
                // Remove toast after 3 seconds
                setTimeout(() => {
                    toast.style.transition = 'opacity 0.3s';
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            }
            
            // Close modal and refresh
            closeApprovalModal();
        }
    });
</script>
@endpush

<!-- Approval Modal -->
<div id="approvalModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px;" onclick="closeApprovalModal()">
    <div id="approvalCard" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1200px; height: 90vh; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;" onclick="event.stopPropagation();">
        
        <!-- Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; color: #2563eb; font-size: 18px; font-weight: bold;">
                        ✓
                    </div>
                    <div>
                        <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;">Review & Approve</h3>
                        <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Review the details and take action</p>
                    </div>
                </div>
                <button type="button" onclick="closeApprovalModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
            </div>
        </div>

        <!-- Scrollable iframe Container -->
        <div style="flex: 1 1 auto; overflow: hidden; min-height: 0; background: white;">
            <iframe 
                id="approvalIframe" 
                src="about:blank" 
                style="width: 100%; height: 100%; border: 0;"
                title="Approval Details">
            </iframe>
        </div>

    </div>
</div>

