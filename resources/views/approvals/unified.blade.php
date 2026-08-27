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
                <!-- ⭐ Search Bar -->
                <div class="flex items-center gap-2">
                    <div class="relative">
                        <input type="text" 
                               id="searchInput" 
                               placeholder="🔍 Search customer..."
                               class="pl-3 pr-8 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-[180px]"
                               onkeyup="onSearchInput(event)">
                        <button type="button" 
                                id="clearSearchBtn" 
                                onclick="clearSearch()" 
                                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 hidden"
                                title="Clear search">✕</button>
                    </div>
                </div>
                <!-- ⭐ Sort option (visible for Online area or Approved view) -->
                <div class="flex items-center gap-2" id="sortContainer" style="display: none;">
                    <label for="sortFilter" class="text-xs font-medium text-gray-600">Sort:</label>
                    <select id="sortFilter"
                            class="kt-select kt-select-sm min-w-[160px]"
                            onchange="onSortChange()">
                        <option value="date">📅 By Request Date</option>
                        <option value="name">👤 By Customer</option>
                        <option value="approved_date" id="sortApprovedOption" style="display: none;">✅ By Approved Date</option>
                    </select>
                </div>
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
                <!-- ⭐ Bulk Approve Button (hidden by default, shown when items selected) -->
                <button id="bulkApproveBtn" 
                        onclick="bulkApproveSelected()" 
                        style="display: none; padding: 8px 16px; font-size: 14px; font-weight: 600; color: white; background-color: #16a34a; border-radius: 8px; border: none; cursor: pointer; box-shadow: 0 2px 8px rgba(22, 163, 74, 0.4); animation: pulse 2s infinite;">
                    ✓ Approve Selected (<span id="selectedCount">0</span>)
                </button>
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
                <thead class="bg-gray-50" id="tableHead">
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
        assignee_id: null,
        sort: 'date' // ⭐ Default sort by date
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
    
    // ⭐ Format date only (without time) - for delivery dates
    function formatDateOnly(dateString) {
        if (!dateString || dateString === '-') return '-';
        
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString; // Invalid date
            
            // Format as: Oct 20, 2025
            const options = {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
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
        
        // ⭐ Set default sort based on view type
        if (level === 'approved') {
            window.approvalFilters.sort = 'approved_date'; // Default to approved date for approved view
            const sortFilter = document.getElementById('sortFilter');
            if (sortFilter) sortFilter.value = 'approved_date';
        } else {
            window.approvalFilters.sort = 'date'; // Default to request date for other views
            const sortFilter = document.getElementById('sortFilter');
            if (sortFilter) sortFilter.value = 'date';
        }

        // Update card active states
        document.querySelectorAll('.level-card').forEach(card => card.classList.remove('active'));
        document.getElementById('card-' + level).classList.add('active');
        document.querySelectorAll('.area-card').forEach(card => card.classList.remove('active'));

        // Collapse Layer 1, Show Layer 2
        document.getElementById('layer1Container').classList.add('collapsed');
        
        // ⭐ Show Layer 2 for all levels (so back button is always visible)
        showLayer2(level);

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
            
            // ⭐ Set default sort to 'name' (customer) for Online area
            if (area === 'online') {
                window.approvalFilters.sort = 'name';
                const sortFilter = document.getElementById('sortFilter');
                if (sortFilter) sortFilter.value = 'name';
            }
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
            assignee_id: null,
            sort: 'date'
        };

        // Reset assignee dropdown
        const assigneeSelect = document.getElementById('assigneeFilter');
        if (assigneeSelect) {
            assigneeSelect.value = '';
        }
        
        // Reset search input
        const searchInput = document.getElementById('searchInput');
        const clearBtn = document.getElementById('clearSearchBtn');
        if (searchInput) {
            searchInput.value = '';
        }
        if (clearBtn) {
            clearBtn.classList.add('hidden');
        }
        
        // Reset sort dropdown
        const sortSelect = document.getElementById('sortFilter');
        if (sortSelect) {
            sortSelect.value = 'date';
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

    // ⭐ Handle search input
    let searchTimeout = null;
    function onSearchInput(event) {
        const searchInput = document.getElementById('searchInput');
        const clearBtn = document.getElementById('clearSearchBtn');
        const value = searchInput.value.trim();
        
        // Show/hide clear button
        if (value) {
            clearBtn.classList.remove('hidden');
        } else {
            clearBtn.classList.add('hidden');
        }
        
        // Debounce search - wait 300ms after typing stops
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // If Enter key pressed, search immediately
        if (event.key === 'Enter') {
            window.approvalFilters.search = value;
            loadTableData();
            return;
        }
        
        searchTimeout = setTimeout(() => {
            window.approvalFilters.search = value;
            loadTableData();
        }, 300);
    }
    
    function clearSearch() {
        const searchInput = document.getElementById('searchInput');
        const clearBtn = document.getElementById('clearSearchBtn');
        searchInput.value = '';
        clearBtn.classList.add('hidden');
        window.approvalFilters.search = '';
        loadTableData();
    }
    
    // ⭐ Handle sort change
    function onSortChange() {
        const select = document.getElementById('sortFilter');
        if (select) {
            window.approvalFilters.sort = select.value;
            loadTableData();
        }
    }
    
    // ⭐ Show/hide sort container based on area
    function updateSortVisibility() {
        const sortContainer = document.getElementById('sortContainer');
        const sortApprovedOption = document.getElementById('sortApprovedOption');
        const sortFilter = document.getElementById('sortFilter');
        
        if (sortContainer) {
            const isApprovedView = window.approvalFilters.level === 'approved';
            const isOnlineArea = window.approvalFilters.area === 'online';
            
            // Show sort option for online area OR approved view
            if (isOnlineArea || isApprovedView) {
                sortContainer.style.display = 'flex';
                
                // Show/hide approved date option based on view
                if (sortApprovedOption) {
                    sortApprovedOption.style.display = isApprovedView ? 'block' : 'none';
                }
                
                // If in approved view and current sort is invalid, reset to date
                if (isApprovedView && !isOnlineArea && sortFilter.value === 'name') {
                    sortFilter.value = 'date';
                }
            } else {
                sortContainer.style.display = 'none';
            }
        }
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
        
        // ⭐ Update sort visibility
        updateSortVisibility();

        // Render rows
        const tbody = document.getElementById('tableBody');
        const colSpan = window.approvalFilters.level === 'approved' ? 9 : 8;
        
        if (items.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="${colSpan}" class="p-8 text-center text-gray-500">
                        No items found
                    </td>
                </tr>
            `;
            return;
        }
        
        // Reset bulk selection when data changes
        document.getElementById('bulkApproveBtn').style.display = 'none';
        document.getElementById('selectedCount').textContent = '0';
        document.getElementById('floatingActionBar').style.display = 'none';
        
        // ⭐ For ONLINE area, group items by customer name (requester)
        const isOnlineArea = window.approvalFilters.area === 'online';
        const isApprovedView = window.approvalFilters.level === 'approved';
        const sortBy = window.approvalFilters.sort || 'date';
        
        // ⭐ Apply client-side sorting for approved view
        if (isApprovedView && !isOnlineArea) {
            let sortedItems = [...items];
            if (sortBy === 'approved_date') {
                // Sort by approved date (newest first)
                sortedItems.sort((a, b) => {
                    const dateA = new Date(a.approved_at || '1970-01-01');
                    const dateB = new Date(b.approved_at || '1970-01-01');
                    return dateB - dateA;
                });
            } else if (sortBy === 'name') {
                // Sort by customer name (requester)
                sortedItems.sort((a, b) => {
                    const nameA = (a.requester || '').toLowerCase();
                    const nameB = (b.requester || '').toLowerCase();
                    return nameA.localeCompare(nameB);
                });
            } else {
                // Sort by request date (newest first)
                sortedItems.sort((a, b) => {
                    const dateA = new Date(a.date || '1970-01-01');
                    const dateB = new Date(b.date || '1970-01-01');
                    return dateB - dateA;
                });
            }
            
            // Update table header for approved view
            updateTableHeaderForGrouped(false);
            
            tbody.innerHTML = sortedItems.map(item => renderItemRow(item, false, null)).join('');
            return;
        }
        
        if (isOnlineArea) {
            // Sort items based on selected sort option
            let sortedItems = [...items];
            if (sortBy === 'name') {
                // Sort by customer name (requester)
                sortedItems.sort((a, b) => {
                    const nameA = (a.requester || '').toLowerCase();
                    const nameB = (b.requester || '').toLowerCase();
                    return nameA.localeCompare(nameB);
                });
            } else {
                // Sort by date (newest first)
                sortedItems.sort((a, b) => {
                    const dateA = new Date(a.date || '1970-01-01');
                    const dateB = new Date(b.date || '1970-01-01');
                    return dateB - dateA;
                });
            }
            
            // Group by customer name
            const grouped = {};
            sortedItems.forEach(item => {
                const name = item.requester || 'Unknown';
                if (!grouped[name]) {
                    grouped[name] = {
                        items: [],
                        totalAmount: 0
                    };
                }
                grouped[name].items.push(item);
                // ⭐ FIX: Parse amount as float to prevent string concatenation
                grouped[name].totalAmount += parseFloat(item.amount) || 0;
            });
            
            // Render grouped table
            let html = '';
            const customerNames = Object.keys(grouped);
            
            // Sort customer names if sorting by name, otherwise keep order from sorted items
            if (sortBy === 'name') {
                customerNames.sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()));
            }
            
            customerNames.forEach((name, groupIndex) => {
                const group = grouped[name];
                const itemCount = group.items.length;
                const bgColor = groupIndex % 2 === 0 ? '#f0f9ff' : '#fef3c7'; // Alternate blue/yellow
                
                // Customer group header - Format total with thousand separators, no decimals
                const formattedTotal = Math.round(group.totalAmount).toLocaleString('en-PK');
                const groupId = `group_${groupIndex}`;
                
                html += `
                    <tr style="background: ${bgColor}; border-top: 2px solid #3b82f6;" class="customer-group-header" data-group-id="${groupId}">
                        <td colspan="7" style="padding: 10px 16px;">
                            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: nowrap; width: 100%;">
                                <input type="checkbox" class="group-checkbox" 
                                       data-group-id="${groupId}"
                                       onchange="toggleGroupSelection('${groupId}')"
                                       style="width: 18px; height: 18px; cursor: pointer; flex-shrink: 0;"
                                       title="Select all ${itemCount} invoices">
                                <span style="font-size: 20px; flex-shrink: 0;">👤</span>
                                <span style="font-weight: 700; font-size: 15px; color: #1e40af; white-space: nowrap;">${name}</span>
                                <span style="background: #3b82f6; color: white; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; flex-shrink: 0;">
                                        ${itemCount} invoice${itemCount > 1 ? 's' : ''}
                                    </span>
                                <span style="font-weight: 700; color: #059669; font-size: 14px; white-space: nowrap; flex-shrink: 0;">
                                    Rs. ${formattedTotal}
                                </span>
                                <button onclick="approveCustomerGroup('${groupId}'); event.stopPropagation();" 
                                        class="px-3 py-1 text-xs font-medium text-white rounded-md transition"
                                        style="margin-left: auto; background: #16a34a; flex-shrink: 0; white-space: nowrap;">
                                    ✓ Approve All ${itemCount}
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
                
                // Items in this group - pass group ID for selection
                group.items.forEach(item => {
                    html += renderItemRow(item, true, groupId);
                });
            });
            
            // Update table header for grouped view (different columns)
            updateTableHeaderForGrouped(true);
            
            tbody.innerHTML = html;
        } else {
            // Regular rendering (non-grouped)
            updateTableHeaderForGrouped(false);
            tbody.innerHTML = items.map(item => renderItemRow(item, false, null)).join('');
        }
    }
    
    // ⭐ Helper function to render a single item row
    // isGrouped = true for online area grouped display (different column order + checkbox)
    // groupId = identifier for the customer group (for bulk selection)
    function renderItemRow(item, isGrouped, groupId) {
        const areaLabels = {
            'exp_fund': '💰 EXP FUND',
            'nf_cash': '💵 NF CASH',
            'online': '🏦 ONLINE',
            'others': '📦 OTHERS'
        };

        const levelBadge = item.level ? 
            `<span class="badge badge-${item.level === 1 ? 'yellow' : 'blue'}">L${item.level}</span>` : 
            '<span class="badge badge-gray">-</span>';
        
        const formattedAmount = parseFloat(item.amount) > 0 
            ? 'Rs. ' + Math.round(parseFloat(item.amount)).toLocaleString('en-PK') 
            : (item.leave_days > 0 ? item.leave_days + ' days' : '-');
        
        // ⭐ Build pending adjustment indicator if there's a pending amount change
        let pendingAdjustmentHtml = '';
        if (item.has_pending_adjustment && item.pending_new_amount !== null) {
            const pendingFormattedAmount = 'Rs. ' + Math.round(parseFloat(item.pending_new_amount)).toLocaleString('en-PK');
            pendingAdjustmentHtml = `
                <div style="margin-top: 4px;">
                    <span style="font-size: 10px; color: #f59e0b; font-weight: 600; background-color: #fef3c7; padding: 2px 6px; border-radius: 4px; display: inline-block;">
                        ⏳ Pending: ${pendingFormattedAmount}
                    </span>
                </div>
            `;
        }

        // For grouped items (online), use different column order: Checkbox, Request#, Amount, Date, Level, Category, Action
        if (isGrouped && groupId) {
            // ⭐ For ONLINE grouped view: use delivery_date field (date-only format) or fall back to date
            const displayDate = item.delivery_date 
                ? formatDateOnly(item.delivery_date) 
                : formatDateOnly(item.date);
                
        return `
                <tr class="hover:bg-gray-50 group-item" data-item-id="${item.id}" data-item-type="${item.type}" data-item-level="${item.level}" data-group-id="${groupId}">
                    <td class="text-center" style="width: 40px;">
                        <input type="checkbox" class="item-checkbox" 
                               data-id="${item.id}" 
                               data-type="${item.type}" 
                               data-level="${item.level}"
                               data-amount="${item.amount}"
                               data-order-id="${item.order_id || ''}"
                               data-group-id="${groupId}"
                               onchange="updateBulkSelection()"
                               style="width: 16px; height: 16px; cursor: pointer;">
                </td>
                    <td class="font-medium text-blue-600">${item.number}</td>
                    <td class="text-right font-semibold">
                        ${formattedAmount}
                        ${pendingAdjustmentHtml}
                    </td>
                <td class="text-sm text-gray-600">${displayDate}</td>
                    <td class="text-center">${levelBadge}</td>
                    <td class="text-sm">${item.category}</td>
                <td class="text-center">
                    <button onclick="openApprovalModal('${item.view_url}')" 
                       class="inline-block px-3 py-1 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition">
                        View & Approve
                    </button>
                    </td>
                </tr>
            `;
        }
        
        // Regular (non-grouped) display
        const isApprovedView = window.approvalFilters.level === 'approved';
        const isRejectedView = window.approvalFilters.level === 'rejected';
        const approvedDateCell = isApprovedView 
            ? `<td class="text-sm text-green-600 font-medium">${formatDateTime(item.approved_at)}</td>` 
            : '';
        
        // Different button styles based on view
        let actionButton;
        if (isApprovedView || isRejectedView) {
            actionButton = `<button onclick="openApprovalModal('${item.view_url}')" 
                   style="display: inline-block; padding: 4px 12px; font-size: 12px; font-weight: 500; color: white; background-color: #6b7280; border-radius: 6px; cursor: pointer; border: none;">
                    View Details
                </button>`;
        } else {
            actionButton = `<button onclick="openApprovalModal('${item.view_url}')" 
                   class="inline-block px-3 py-1 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition">
                    View & Approve
                </button>`;
        }
        
        return `
            <tr class="hover:bg-gray-50">
                <td class="font-medium text-blue-600">${item.number}</td>
                <td>${item.requester}</td>
                <td>${item.category}</td>
                <td class="text-sm">${areaLabels[item.area] || item.area}</td>
                <td class="text-right font-semibold">
                    ${formattedAmount}
                    ${pendingAdjustmentHtml}
                </td>
                <td class="text-center">${levelBadge}</td>
                <td class="text-sm text-gray-600">${formatDateTime(item.date)}</td>
                ${approvedDateCell}
                <td class="text-center">
                    ${actionButton}
                </td>
            </tr>
        `;
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

    // ==================== BULK APPROVAL FUNCTIONS ====================
    
    // Update table header for grouped vs non-grouped view
    function updateTableHeaderForGrouped(isGrouped) {
        const thead = document.getElementById('tableHead');
        if (!thead) return;
        
        const isApprovedView = window.approvalFilters.level === 'approved';
        const isOnlineArea = window.approvalFilters.area === 'online';
        
        if (isGrouped) {
            // ⭐ Grouped columns for ONLINE: Checkbox, ORDER #, Amount, DELIVERY DATE, Level, Category, Action
            thead.innerHTML = `
                <tr>
                    <th class="text-center" style="width: 40px;">
                        <input type="checkbox" id="selectAllCheckbox" 
                               onchange="toggleSelectAll(this.checked)"
                               style="width: 16px; height: 16px; cursor: pointer;"
                               title="Select All">
                    </th>
                    <th class="text-left">ORDER #</th>
                    <th class="text-right">AMOUNT</th>
                    <th class="text-left">DELIVERY DATE</th>
                    <th class="text-center">LEVEL</th>
                    <th class="text-left">CATEGORY</th>
                    <th class="text-center">ACTION</th>
                </tr>
            `;
        } else if (isApprovedView) {
            // Approved view columns: includes APPROVED DATE
            thead.innerHTML = `
                <tr>
                    <th class="text-left">REQUEST #</th>
                    <th class="text-left">REQUESTER</th>
                    <th class="text-left">CATEGORY</th>
                    <th class="text-left">AREA</th>
                    <th class="text-right">AMOUNT</th>
                    <th class="text-center">LEVEL</th>
                    <th class="text-left">REQUEST DATE</th>
                    <th class="text-left">APPROVED DATE</th>
                    <th class="text-center">ACTION</th>
                </tr>
            `;
        } else {
            // Default columns for pending
            thead.innerHTML = `
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
            `;
        }
    }
    
    // Toggle selection of all items in a specific group
    function toggleGroupSelection(groupId) {
        const groupCheckbox = document.querySelector(`.group-checkbox[data-group-id="${groupId}"]`);
        const isChecked = groupCheckbox ? groupCheckbox.checked : false;
        
        // Select/deselect all items in this group
        document.querySelectorAll(`.item-checkbox[data-group-id="${groupId}"]`).forEach(cb => {
            cb.checked = isChecked;
        });
        
        updateBulkSelection();
    }
    
    // Toggle selection of all items
    function toggleSelectAll(checked) {
        document.querySelectorAll('.item-checkbox').forEach(cb => {
            cb.checked = checked;
        });
        document.querySelectorAll('.group-checkbox').forEach(cb => {
            cb.checked = checked;
        });
        updateBulkSelection();
    }
    
    // Update bulk selection count and button visibility
    function updateBulkSelection() {
        const selectedItems = document.querySelectorAll('.item-checkbox:checked');
        const count = selectedItems.length;
        const btn = document.getElementById('bulkApproveBtn');
        const countSpan = document.getElementById('selectedCount');
        const floatingBar = document.getElementById('floatingActionBar');
        const floatingCount = document.getElementById('floatingSelectedCount');
        const floatingAmount = document.getElementById('floatingSelectedAmount');
        
        console.log('updateBulkSelection called, count:', count); // Debug log
        
        // Calculate total amount of selected items
        let totalAmount = 0;
        selectedItems.forEach(cb => {
            totalAmount += parseFloat(cb.dataset.amount) || 0;
        });
        
        if (count > 0) {
            // Show header button
            btn.style.display = 'inline-flex';
            countSpan.textContent = count;
            
            // Show floating action bar
            floatingBar.style.display = 'block';
            floatingCount.textContent = count + ' item' + (count > 1 ? 's' : '') + ' selected';
            floatingAmount.textContent = 'Rs. ' + Math.round(totalAmount).toLocaleString('en-PK');
            
            // Add padding to body to prevent content from being hidden behind floating bar
            document.body.style.paddingBottom = '80px';
        } else {
            btn.style.display = 'none';
            floatingBar.style.display = 'none';
            
            // Remove padding when floating bar is hidden
            document.body.style.paddingBottom = '0px';
        }
        
        // Update group checkboxes state
        document.querySelectorAll('.group-checkbox').forEach(groupCb => {
            const groupId = groupCb.dataset.groupId;
            const groupItems = document.querySelectorAll(`.item-checkbox[data-group-id="${groupId}"]`);
            const checkedItems = document.querySelectorAll(`.item-checkbox[data-group-id="${groupId}"]:checked`);
            
            groupCb.checked = groupItems.length > 0 && groupItems.length === checkedItems.length;
            groupCb.indeterminate = checkedItems.length > 0 && checkedItems.length < groupItems.length;
        });
        
        // Update select all checkbox
        const allItems = document.querySelectorAll('.item-checkbox');
        const allChecked = document.querySelectorAll('.item-checkbox:checked');
        const selectAllCb = document.getElementById('selectAllCheckbox');
        if (selectAllCb) {
            selectAllCb.checked = allItems.length > 0 && allItems.length === allChecked.length;
            selectAllCb.indeterminate = allChecked.length > 0 && allChecked.length < allItems.length;
        }
    }
    
    // Clear all selections
    function clearAllSelections() {
        document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.group-checkbox').forEach(cb => cb.checked = false);
        const selectAllCb = document.getElementById('selectAllCheckbox');
        if (selectAllCb) selectAllCb.checked = false;
        updateBulkSelection();
    }
    
    // Approve all items in a customer group
    async function approveCustomerGroup(groupId) {
        const items = document.querySelectorAll(`.item-checkbox[data-group-id="${groupId}"]`);
        if (items.length === 0) {
            alert('No items found in this group');
            return;
        }
        
        const approvalType = document.getElementById('bulkApprovalType')?.value || 'full';
        const approvalText = approvalType === 'l1_only' ? 'L1-ONLY approve (move to L2)' : 'FULLY approve';
        
        const confirmed = confirm(`Are you sure you want to ${approvalText} all ${items.length} invoice(s) in this group?`);
        if (!confirmed) return;
        
        await bulkApproveItems(Array.from(items));
    }
    
    // Approve all selected items
    async function bulkApproveSelected() {
        const selectedItems = document.querySelectorAll('.item-checkbox:checked');
        if (selectedItems.length === 0) {
            alert('No items selected');
            return;
        }
        
        const approvalType = document.getElementById('bulkApprovalType')?.value || 'full';
        const approvalText = approvalType === 'l1_only' ? 'L1-ONLY approve (move to L2)' : 'FULLY approve';
        
        const confirmed = confirm(`Are you sure you want to ${approvalText} ${selectedItems.length} selected item(s)?`);
        if (!confirmed) return;
        
        await bulkApproveItems(Array.from(selectedItems));
    }
    
    // Perform bulk approval
    async function bulkApproveItems(checkboxes, approvalTypeOverride = null) {
        const btn = document.getElementById('bulkApproveBtn');
        const originalText = btn.innerHTML;
        btn.innerHTML = '⏳ Processing...';
        btn.disabled = true;
        
        // Get approval type from dropdown (or use override for group approvals)
        const approvalType = approvalTypeOverride || document.getElementById('bulkApprovalType')?.value || 'full';
        const isL1Only = approvalType === 'l1_only';
        
        let successCount = 0;
        let errorCount = 0;
        const errors = [];
        // Invoices settled in this batch, so an overpayment on any of them can
        // be raised once at the end (see nfuOfferOverpaysAfterBulk).
        const settledOrderIds = [];

        for (const cb of checkboxes) {
            const itemId = cb.dataset.id;
            const itemType = cb.dataset.type;
            const itemLevel = cb.dataset.level;
            
            try {
                let endpoint;
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                
                // Determine the correct endpoint based on item type and approval type
                // ⭐ Use web routes (session auth) instead of API routes (token auth)
                if (itemType === 'ledger') {
                    // For ledger items, support L1-only approval
                    if (isL1Only && itemLevel == 1) {
                        endpoint = `/finance/ledger/${itemId}/approve-l1-only`;
                    } else {
                        endpoint = `/finance/ledger/${itemId}/approve`;
                    }
                } else if (itemType === 'request') {
                    endpoint = `/requests/${itemId}/approve`;
                } else if (itemType === 'adjustment') {
                    endpoint = `/finance/ledger/adjustments/${itemId}/approve`;
                } else {
                    throw new Error(`Unknown item type: ${itemType}`);
                }
                
                // Build the request body based on item type
                let bodyData = {};
                const noteText = isL1Only ? 'Bulk L1-approved from approvals dashboard' : 'Bulk approved from approvals dashboard';
                if (itemType === 'ledger') {
                    bodyData = { 
                        approval_notes: noteText,
                        force_full_approval: !isL1Only // ⭐ Request full approval if not L1-only
                    };
                } else if (itemType === 'request') {
                    bodyData = { level: parseInt(itemLevel) || 1, notes: noteText };
                } else if (itemType === 'adjustment') {
                    bodyData = { level: parseInt(itemLevel) || 1, comments: noteText };
                }
                
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(bodyData)
                });
                
                const data = await response.json();
                
                if (response.ok && (data.success !== false)) {
                    successCount++;
                    if (cb.dataset.orderId) {
                        settledOrderIds.push(parseInt(cb.dataset.orderId, 10));
                    }
                    // Disable the checkbox and mark as approved
                    cb.disabled = true;
                    cb.closest('tr').style.opacity = '0.5';
                    cb.closest('tr').style.backgroundColor = '#d1fae5';
                } else {
                    errorCount++;
                    errors.push(`${itemId}: ${data.message || 'Unknown error'}`);
                }
            } catch (error) {
                errorCount++;
                errors.push(`${itemId}: ${error.message}`);
            }
        }
        
        btn.innerHTML = originalText;
        btn.disabled = false;
        
        // Show result
        let message = `✅ Successfully approved: ${successCount}`;
        if (errorCount > 0) {
            message += `\n❌ Failed: ${errorCount}`;
            if (errors.length > 0) {
                message += '\n\nErrors:\n' + errors.slice(0, 5).join('\n');
                if (errors.length > 5) {
                    message += `\n... and ${errors.length - 5} more`;
                }
            }
        }
        alert(message);

        // Ask about anything that was paid MORE than its invoice needed.
        // Advisory only — the approvals above already stand.
        try { await nfuOfferOverpaysAfterBulk(settledOrderIds); } catch (e) { /* ignore */ }

        // Refresh the data
        if (window.approvalFilters.level || window.approvalFilters.area || window.approvalFilters.search) {
            loadTableData();
        } else {
            loadAllPendingItems();
        }
    }

    /**
     * "That customer paid more than the invoice — bank it or leave it?"
     *
     * Same two endpoints the Online Approvals screen and the mobile app use,
     * so what counts as an overpayment is decided in ONE place on the server
     * and no surface can quietly disagree with another.
     *
     * Capped at three questions: a manager clearing thirty invoices will not
     * sit through thirty dialogs, and an unanswered one costs nothing — the
     * extra stays on the order and can still be banked from its proof panel.
     * The cap is announced, never silent.
     */
    async function nfuOfferOverpaysAfterBulk(orderIds) {
        const ids = (orderIds || []).filter(Boolean);
        if (!ids.length) return;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const jsonHeaders = {
            'Accept': 'application/json', 'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf,
        };

        let list = [];
        try {
            const res = await fetch('/admin/payments/overpay-batch', {
                method: 'POST', headers: jsonHeaders,
                body: JSON.stringify({ order_ids: ids })
            });
            const data = await res.json();
            list = (data && data.overpays) || [];
        } catch (e) { return; }
        if (!list.length) return;

        const fmt = n => Number(n || 0).toLocaleString('en-PK', { maximumFractionDigits: 2 });
        const MAX_ASK = 3;
        for (const ov of list.slice(0, MAX_ASK)) {
            const yes = confirm(
                `${ov.order_number || ('Order #' + ov.order_id)} was paid Rs ${fmt(ov.amount)} MORE than it needed.\n\n` +
                `OK = add it to the customer's account balance\n` +
                `Cancel = leave it as it is`
            );
            if (!yes) continue;
            try {
                const r = await fetch(`/admin/payments/order/${ov.order_id}/overpay-to-balance`, {
                    method: 'POST', headers: jsonHeaders
                });
                const j = await r.json();
                alert(j.message || (j.success ? 'Added.' : 'Could not add it.'));
            } catch (e) { /* keep going through the rest */ }
        }
        if (list.length > MAX_ASK) {
            alert(`${list.length - MAX_ASK} more invoice(s) in this batch were also overpaid.\n\n` +
                  `Open each invoice's payment proof to add those to the customer's balance.`);
        }
    }

    // Add CSS animation for pulse effect
    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
    `;
    document.head.appendChild(styleSheet);
</script>
@endpush

<!-- ⭐ Floating Action Bar for Bulk Selection -->
<div id="floatingActionBar" style="display: none; position: fixed; bottom: 0; left: 0; right: 0; background: linear-gradient(135deg, #1f2937 0%, #111827 100%); padding: 16px 24px; box-shadow: 0 -4px 20px rgba(0,0,0,0.3); z-index: 99998; border-top: 3px solid #16a34a;">
    <div style="max-width: 1400px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <span style="font-size: 24px;">✅</span>
            <div>
                <span style="color: white; font-weight: 700; font-size: 16px;" id="floatingSelectedCount">0 items selected</span>
                <span style="color: #9ca3af; font-size: 13px; margin-left: 12px;" id="floatingSelectedAmount">Rs. 0</span>
            </div>
            <button onclick="clearAllSelections()" style="padding: 6px 12px; font-size: 12px; color: #d1d5db; background: #374151; border: none; border-radius: 6px; cursor: pointer;">
                Clear Selection
            </button>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <!-- Approval Type Selection -->
            <select id="bulkApprovalType" style="padding: 10px 14px; font-size: 14px; font-weight: 600; background: #374151; color: white; border: 1px solid #4b5563; border-radius: 8px; cursor: pointer;">
                <option value="full">Full Approval</option>
                <option value="l1_only">L1 Only (Move to L2)</option>
            </select>
            <button onclick="bulkApproveSelected()" id="floatingApproveBtn" style="padding: 12px 24px; font-size: 15px; font-weight: 700; color: white; background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); border: none; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.4); display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 18px;">✓</span>
                <span>Approve Selected</span>
            </button>
        </div>
    </div>
</div>

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

