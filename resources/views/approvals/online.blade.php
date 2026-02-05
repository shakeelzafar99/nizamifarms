@extends('layouts.app')

@section('title', 'Online Approvals')

@push('demo1_css')
<style>
    /* Tab Cards */
    .tab-card {
        background: white;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px 20px;
        cursor: pointer;
        transition: all 0.2s;
        min-width: 160px;
    }

    .tab-card:hover {
        border-color: #3b82f6;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }

    .tab-card.active {
        border-color: #3b82f6;
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border-width: 3px;
    }

    .tab-card.all-pending { border-left: 4px solid #8B5CF6; }
    .tab-card.l1 { border-left: 4px solid #f59e0b; }
    .tab-card.l2 { border-left: 4px solid #3b82f6; }
    .tab-card.approved { border-left: 4px solid #10b981; }

    .tab-card .title {
        font-size: 12px;
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .tab-card .count {
        font-size: 28px;
        font-weight: 800;
        color: #111827;
        margin: 8px 0 4px;
    }

    .tab-card .amount {
        font-size: 14px;
        font-weight: 600;
        color: #059669;
    }

    /* Customer Group Card */
    .customer-group {
        background: white;
        border-radius: 12px;
        margin-bottom: 16px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .customer-group-header {
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid #f3f4f6;
    }

    .customer-group-header.blue {
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border-left: 4px solid #3b82f6;
    }

    .customer-group-header.yellow {
        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        border-left: 4px solid #f59e0b;
    }

    .customer-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .customer-icon {
        font-size: 24px;
    }

    .customer-name {
        font-size: 16px;
        font-weight: 700;
        color: #111827;
    }

    .customer-badge {
        background: #7C3AED;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 12px;
    }

    .customer-actions {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .customer-total {
        font-size: 18px;
        font-weight: 700;
        color: #DC2626;
    }

    .approve-group-btn {
        color: white;
        border: none;
        padding: 8px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .approve-group-btn.full-approve {
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
    }

    .approve-group-btn.full-approve:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(16, 185, 129, 0.4);
    }

    .approve-group-btn.l1-only {
        background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
        box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
    }

    .approve-group-btn.l1-only:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);
    }

    /* Invoice Row */
    .invoice-row {
        padding: 14px 20px;
        display: flex;
        align-items: center;
        border-bottom: 1px solid #f3f4f6;
        transition: background 0.15s;
    }

    .invoice-row:hover {
        background: #f9fafb;
    }

    .invoice-row:last-child {
        border-bottom: none;
    }

    .invoice-row.selected {
        background: #F3E8FF;
    }

    .invoice-checkbox {
        width: 22px;
        height: 22px;
        border: 2px solid #d1d5db;
        border-radius: 4px;
        margin-right: 16px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s;
        flex-shrink: 0;
    }

    .invoice-checkbox.checked {
        background: #7C3AED;
        border-color: #7C3AED;
    }

    .invoice-checkbox .check-icon {
        color: white;
        font-weight: bold;
        font-size: 14px;
    }

    .invoice-number {
        font-size: 15px;
        font-weight: 600;
        color: #3B82F6;
        min-width: 120px;
        cursor: pointer;
    }

    .invoice-number:hover {
        text-decoration: underline;
    }

    .invoice-date {
        font-size: 13px;
        color: #6b7280;
        min-width: 120px;
    }

    .invoice-level {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        min-width: 50px;
        text-align: center;
    }

    .invoice-level.l1 {
        background: #DBEAFE;
        color: #1E40AF;
    }

    .invoice-level.l2 {
        background: #FEF3C7;
        color: #92400E;
    }

    .invoice-amount {
        font-size: 15px;
        font-weight: 700;
        color: #111827;
        min-width: 120px;
        text-align: right;
        margin-left: auto;
    }

    .invoice-actions {
        display: flex;
        gap: 8px;
        margin-left: 20px;
    }

    .view-btn {
        background: #7C3AED;
        color: white;
        border: none;
        padding: 6px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
    }

    .view-btn:hover {
        background: #6D28D9;
    }

    /* Floating Action Bar */
    .selection-bar {
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%);
        background: #1F2937;
        padding: 14px 24px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        gap: 20px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        z-index: 1000;
    }

    .selection-bar .count {
        color: white;
        font-size: 15px;
        font-weight: 600;
    }

    .selection-bar .clear-btn {
        background: rgba(255,255,255,0.15);
        color: #F87171;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
    }

    .selection-bar .full-approve-btn {
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .selection-bar .full-approve-btn:hover {
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
        transform: translateY(-1px);
    }

    .selection-bar .l1-approve-btn {
        background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .selection-bar .l1-approve-btn:hover {
        background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
        transform: translateY(-1px);
    }

    /* Legacy - keeping for backward compatibility */
    .selection-bar .approve-btn {
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
    }

    .empty-state-icon {
        font-size: 64px;
        margin-bottom: 16px;
    }

    .empty-state-title {
        font-size: 20px;
        font-weight: 700;
        color: #374151;
        margin-bottom: 8px;
    }

    .empty-state-text {
        font-size: 14px;
        color: #6b7280;
    }

    /* Loading */
    .loading-spinner {
        display: inline-block;
        width: 24px;
        height: 24px;
        border: 3px solid #e5e7eb;
        border-radius: 50%;
        border-top-color: #3b82f6;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Approved row styling */
    .invoice-approved-by {
        font-size: 12px;
        color: #059669;
        min-width: 120px;
    }
</style>
@endpush

@section('content')
<div class="px-4 py-6" style="max-width: 100%;">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">💳 Online Approvals</h1>
            <p class="text-gray-600 mt-2">Manage online payment approvals</p>
        </div>
        <a href="{{ route('approvals.index') }}" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
            ← Back to All Approvals
        </a>
    </div>

    <!-- Tab Cards -->
    <div class="flex gap-4 mb-6 flex-wrap">
        {{-- All Pending (L1 + L2 combined) --}}
        @if($hasLevel1Rights || $hasLevel2Rights)
        <div class="tab-card all-pending" id="tab-all" data-tab="all" onclick="selectTab('all')" style="border-left: 4px solid #8B5CF6;">
            <div class="title">📋 All Pending</div>
            <div class="count" id="count-all">{{ $summaries['l1']['count'] + $summaries['l2']['count'] }}</div>
            <div class="amount" id="amount-all">Rs. {{ number_format($summaries['l1']['amount'] + $summaries['l2']['amount'], 0) }}</div>
        </div>
        @endif

        @if($hasLevel1Rights)
        <div class="tab-card l1" id="tab-l1" data-tab="l1" onclick="selectTab('l1')">
            <div class="title">📋 L1 Pending</div>
            <div class="count" id="count-l1">{{ $summaries['l1']['count'] }}</div>
            <div class="amount" id="amount-l1">Rs. {{ number_format($summaries['l1']['amount'], 0) }}</div>
        </div>
        @endif

        @if($hasLevel2Rights)
        <div class="tab-card l2" id="tab-l2" data-tab="l2" onclick="selectTab('l2')">
            <div class="title">📋 L2 Pending</div>
            <div class="count" id="count-l2">{{ $summaries['l2']['count'] }}</div>
            <div class="amount" id="amount-l2">Rs. {{ number_format($summaries['l2']['amount'], 0) }}</div>
        </div>
        @endif

        <div class="tab-card approved" id="tab-approved" data-tab="approved" onclick="selectTab('approved')">
            <div class="title">✅ Approved</div>
            <div class="count" id="count-approved">{{ $summaries['approved']['count'] }}</div>
            <div class="amount" id="amount-approved">Rs. {{ number_format($summaries['approved']['amount'], 0) }}</div>
        </div>
    </div>

    <!-- Search and Sort Row -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <!-- Search -->
                <div class="relative">
                    <input type="text" 
                           id="searchInput" 
                           placeholder="🔍 Search customer or invoice..."
                           class="pl-4 pr-10 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 w-[280px]"
                           oninput="onSearchInput()">
                    <button type="button" 
                            id="clearSearchBtn" 
                            onclick="clearSearch()" 
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 hidden">✕</button>
                </div>

                <!-- Sort -->
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium text-gray-600">Sort:</label>
                    <select id="sortSelect" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500" onchange="loadData()">
                        <option value="date">📅 By Date</option>
                        <option value="name">👤 By Customer</option>
                        <option value="approved_date" id="sortApprovedOption" style="display: none;">✅ By Approved Date</option>
                    </select>
                </div>
            </div>

            <div class="text-sm text-gray-600">
                <span id="totalCount">0</span> invoices • 
                <span class="font-semibold text-red-600">Rs. <span id="totalAmount">0</span></span>
            </div>
        </div>
    </div>

    <!-- Loading State -->
    <div id="loadingState" class="text-center py-12 hidden">
        <div class="loading-spinner"></div>
        <p class="text-gray-600 mt-4">Loading...</p>
    </div>

    <!-- Items Container -->
    <div id="itemsContainer">
        <!-- Items will be loaded here via JS -->
    </div>

    <!-- Selection Action Bar (hidden by default) -->
    <div id="selectionBar" class="selection-bar" style="display: none;">
        <span class="count"><span id="selectedCount">0</span> selected</span>
        <button class="clear-btn" onclick="clearSelection()">✕ Clear</button>
        <button class="full-approve-btn" onclick="fullApproveSelected()">✓ Full Approve</button>
        <button class="l1-approve-btn" onclick="l1ApproveSelected()">→ L1 Only</button>
    </div>
</div>

@endsection

@push('modals')
<!-- Approval Modal (Single Item) -->
<div id="approvalModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 10000; justify-content: center; align-items: center;" onclick="if(event.target === this) closeModal()">
    <div style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 420px; margin: 20px; overflow: hidden;" onclick="event.stopPropagation()">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between;">
            <h3 id="modalTitle" style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">Approve Invoice</h3>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
        <div id="modalBody" style="padding: 24px;">
            <!-- Modal content will be loaded here -->
        </div>
        <div style="padding: 20px 24px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; gap: 12px;">
            <button id="modalApproveBtn" onclick="confirmApprove()" style="flex: 1; background: #16a34a; color: white; font-weight: 600; padding: 14px 20px; border-radius: 10px; border: none; cursor: pointer; font-size: 15px; transition: background 0.2s;">
                ✓ Full Approve
            </button>
            <button id="modalL1OnlyBtn" onclick="confirmL1Only()" style="display: none; flex: 1; background: #2563eb; color: white; font-weight: 600; padding: 14px 20px; border-radius: 10px; border: none; cursor: pointer; font-size: 15px; transition: background 0.2s;">
                L1 Only → L2
            </button>
            <button onclick="closeModal()" style="padding: 14px 24px; background: #e5e7eb; color: #374151; font-weight: 600; border-radius: 10px; border: none; cursor: pointer; font-size: 15px; transition: background 0.2s;">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- Bulk Approval Modal -->
<div id="bulkApprovalModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 10000; justify-content: center; align-items: center;" onclick="if(event.target === this) closeBulkModal()">
    <div style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 450px; margin: 20px; overflow: hidden;" onclick="event.stopPropagation()">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between;">
            <h3 id="bulkModalTitle" style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">Bulk Approval</h3>
            <button onclick="closeBulkModal()" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
        <div id="bulkModalBody" style="padding: 24px;">
            <!-- Bulk modal content will be loaded here -->
        </div>
        <div style="padding: 20px 24px; background: #f9fafb; border-top: 1px solid #e5e7eb;">
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <!-- Full Approve Button -->
                <button id="bulkFullApproveBtn" onclick="confirmBulkApprove('full')" style="width: 100%; background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); color: white; font-weight: 600; padding: 16px 20px; border-radius: 10px; border: none; cursor: pointer; font-size: 15px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(22,163,74,0.4)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                    <span>✓</span>
                    <span>Full Approve</span>
                    <span style="font-size: 12px; opacity: 0.85;">(Complete approval)</span>
                </button>
                
                <!-- L1 Only Button (shown only when L1 items present) -->
                <button id="bulkL1OnlyBtn" onclick="confirmBulkApprove('l1_only')" style="display: none; width: 100%; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; font-weight: 600; padding: 16px 20px; border-radius: 10px; border: none; cursor: pointer; font-size: 15px; align-items: center; justify-content: center; gap: 8px; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(37,99,235,0.4)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                    <span>→</span>
                    <span>L1 Only</span>
                    <span style="font-size: 12px; opacity: 0.85;">(Move to L2 pending)</span>
                </button>
                
                <!-- Cancel Button -->
                <button onclick="closeBulkModal()" style="width: 100%; padding: 14px 24px; background: #e5e7eb; color: #374151; font-weight: 600; border-radius: 10px; border: none; cursor: pointer; font-size: 15px; transition: background 0.2s;" onmouseover="this.style.background='#d1d5db';" onmouseout="this.style.background='#e5e7eb';">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Processing Overlay -->
<div id="processingOverlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(4px); z-index: 10001; justify-content: center; align-items: center;">
    <div style="background: white; border-radius: 16px; padding: 40px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
        <div class="loading-spinner" style="margin-bottom: 20px;"></div>
        <p id="processingText" style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;">Processing approvals...</p>
        <p id="processingProgress" style="font-size: 14px; color: #6b7280; margin-top: 8px;">0 / 0</p>
    </div>
</div>
@endpush

@push('demo1_js')
<script>
// State
let currentTab = 'l1';
let selectedItems = new Set();
let allItems = [];
let groupedItems = [];
let searchTimeout;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Auto-select "All Pending" tab by default if user has any approval rights
    @if($hasLevel1Rights || $hasLevel2Rights)
    selectTab('all');
    @else
    selectTab('approved');
    @endif
});

// Tab selection
function selectTab(tab) {
    currentTab = tab;
    selectedItems.clear();
    updateSelectionBar();
    
    // Update tab active states
    document.querySelectorAll('.tab-card').forEach(card => card.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    
    // Show/hide approved date sort option
    const sortApprovedOption = document.getElementById('sortApprovedOption');
    if (tab === 'approved') {
        sortApprovedOption.style.display = 'block';
        document.getElementById('sortSelect').value = 'approved_date';
    } else {
        sortApprovedOption.style.display = 'none';
        if (document.getElementById('sortSelect').value === 'approved_date') {
            document.getElementById('sortSelect').value = 'date';
        }
    }
    
    loadData();
}

// Load data from server
async function loadData() {
    const container = document.getElementById('itemsContainer');
    const loading = document.getElementById('loadingState');
    
    container.innerHTML = '';
    loading.classList.remove('hidden');
    
    const search = document.getElementById('searchInput').value;
    const sort = document.getElementById('sortSelect').value;
    
    try {
        const response = await fetch(`{{ route('approvals.online') }}?tab=${currentTab}&search=${encodeURIComponent(search)}&sort=${sort}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            allItems = data.items;
            groupedItems = data.grouped;
            renderItems(data.grouped);
            document.getElementById('totalCount').textContent = data.count;
            document.getElementById('totalAmount').textContent = numberFormat(data.total_amount);
        }
    } catch (error) {
        console.error('Error loading data:', error);
        container.innerHTML = '<div class="text-center py-12 text-red-600">Error loading data. Please try again.</div>';
    } finally {
        loading.classList.add('hidden');
    }
}

// Refresh stats (counts and amounts) without full page reload
async function refreshStats() {
    try {
        // Fetch stats for all tabs to update counts
        const [l1Response, l2Response, approvedResponse] = await Promise.all([
            fetch(`{{ route('approvals.online') }}?tab=l1`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }),
            fetch(`{{ route('approvals.online') }}?tab=l2`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }),
            fetch(`{{ route('approvals.online') }}?tab=approved`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        ]);
        
        const [l1Data, l2Data, approvedData] = await Promise.all([
            l1Response.json(),
            l2Response.json(),
            approvedResponse.json()
        ]);
        
        // Update L1 counts
        if (l1Data.success) {
            const l1Count = document.getElementById('count-l1');
            const l1Amount = document.getElementById('amount-l1');
            if (l1Count) l1Count.textContent = l1Data.count;
            if (l1Amount) l1Amount.textContent = `Rs. ${numberFormat(l1Data.total_amount)}`;
        }
        
        // Update L2 counts
        if (l2Data.success) {
            const l2Count = document.getElementById('count-l2');
            const l2Amount = document.getElementById('amount-l2');
            if (l2Count) l2Count.textContent = l2Data.count;
            if (l2Amount) l2Amount.textContent = `Rs. ${numberFormat(l2Data.total_amount)}`;
        }
        
        // Update Approved counts
        if (approvedData.success) {
            const approvedCount = document.getElementById('count-approved');
            const approvedAmount = document.getElementById('amount-approved');
            if (approvedCount) approvedCount.textContent = approvedData.count;
            if (approvedAmount) approvedAmount.textContent = `Rs. ${numberFormat(approvedData.total_amount)}`;
        }
        
        // Update All Pending (L1 + L2)
        const allCount = document.getElementById('count-all');
        const allAmount = document.getElementById('amount-all');
        if (allCount && l1Data.success && l2Data.success) {
            allCount.textContent = l1Data.count + l2Data.count;
        }
        if (allAmount && l1Data.success && l2Data.success) {
            allAmount.textContent = `Rs. ${numberFormat(l1Data.total_amount + l2Data.total_amount)}`;
        }
        
        console.log('Stats refreshed:', { l1: l1Data.count, l2: l2Data.count, approved: approvedData.count });
    } catch (error) {
        console.error('Error refreshing stats:', error);
    }
}

// Render grouped items
function renderItems(groups) {
    const container = document.getElementById('itemsContainer');
    
    if (!groups || groups.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">✅</div>
                <div class="empty-state-title">No items found</div>
                <div class="empty-state-text">${currentTab === 'approved' ? 'No approved invoices in the selected date range' : 'All caught up! No pending approvals.'}</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    groups.forEach((group, index) => {
        const colorClass = index % 2 === 0 ? 'blue' : 'yellow';
        const isApproved = currentTab === 'approved';
        
        html += `
            <div class="customer-group">
                <div class="customer-group-header ${colorClass}">
                    <div class="customer-info">
                        ${!isApproved ? `
                        <div class="invoice-checkbox ${isGroupSelected(group.items) ? 'checked' : ''}" 
                             onclick="toggleGroupSelection('${escapeHtml(group.customer)}')">
                            <span class="check-icon">${isGroupSelected(group.items) ? '✓' : ''}</span>
                        </div>
                        ` : ''}
                        <span class="customer-icon">👤</span>
                        <span class="customer-name">${escapeHtml(group.customer)}</span>
                        <span class="customer-badge">${group.items.length} invoice${group.items.length > 1 ? 's' : ''}</span>
                    </div>
                    <div class="customer-actions">
                        <span class="customer-total">Rs. ${numberFormat(group.total_amount)}</span>
                        ${!isApproved ? `
                        <button class="approve-group-btn full-approve" onclick="fullApproveGroup('${escapeHtml(group.customer)}')" title="Fully approve all invoices">
                            ✓ Full
                        </button>
                        <button class="approve-group-btn l1-only" onclick="l1ApproveGroup('${escapeHtml(group.customer)}')" title="Move L1 items to L2 pending">
                            → L1 Only
                        </button>
                        ` : ''}
                    </div>
                </div>
                <div class="customer-items">
                    ${group.items.map(item => renderInvoiceRow(item, isApproved)).join('')}
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// Render single invoice row
function renderInvoiceRow(item, isApproved) {
    const itemKey = `${item.type}_${item.id}`;
    const isSelected = selectedItems.has(itemKey);
    
    // Format approval date if available
    const approvalDateStr = item.approved_at ? formatDate(item.approved_at) : '';
    
    // ⭐ Build order view URL - opens order details modal in new tab
    const orderViewUrl = item.order_id ? `/orders?edit_order_id=${item.order_id}` : '#';
    
    return `
        <div class="invoice-row ${isSelected ? 'selected' : ''}" data-item-key="${itemKey}">
            ${!isApproved ? `
            <div class="invoice-checkbox ${isSelected ? 'checked' : ''}" onclick="toggleItem('${itemKey}')">
                <span class="check-icon">${isSelected ? '✓' : ''}</span>
            </div>
            ` : ''}
            <a href="${orderViewUrl}" target="_blank" class="invoice-number" title="View order details">${item.number}</a>
            <span class="invoice-date">📅 ${formatDate(item.date)}</span>
            ${!isApproved ? `
            <span class="invoice-level ${item.level === 1 ? 'l1' : 'l2'}">L${item.level}</span>
            ` : `
            <span class="invoice-approved-by">✅ ${item.approved_by || 'System'}</span>
            ${approvalDateStr ? `<span class="invoice-approved-date" style="color: #059669; font-size: 12px; margin-left: 8px;">📅 ${approvalDateStr}</span>` : ''}
            `}
            <span class="invoice-amount">Rs. ${numberFormat(item.amount)}</span>
            <div class="invoice-actions">
                ${!isApproved ? `
                <button class="view-btn" onclick="openApprovalModal(${item.id}, ${item.level})">Review</button>
                ` : `
                <a href="${orderViewUrl}" target="_blank" class="view-btn">View Order</a>
                `}
            </div>
        </div>
    `;
}

// Selection functions
function toggleItem(itemKey) {
    if (selectedItems.has(itemKey)) {
        selectedItems.delete(itemKey);
    } else {
        selectedItems.add(itemKey);
    }
    updateSelectionUI();
}

function toggleGroupSelection(customerName) {
    const group = groupedItems.find(g => g.customer === customerName);
    if (!group) return;
    
    const itemKeys = group.items.map(item => `${item.type}_${item.id}`);
    const allSelected = itemKeys.every(key => selectedItems.has(key));
    
    if (allSelected) {
        itemKeys.forEach(key => selectedItems.delete(key));
    } else {
        itemKeys.forEach(key => selectedItems.add(key));
    }
    updateSelectionUI();
}

function isGroupSelected(items) {
    if (!items || items.length === 0) return false;
    return items.every(item => selectedItems.has(`${item.type}_${item.id}`));
}

function clearSelection() {
    selectedItems.clear();
    updateSelectionUI();
}

function updateSelectionUI() {
    // Re-render to update checkboxes
    renderItems(groupedItems);
    updateSelectionBar();
}

function updateSelectionBar() {
    const bar = document.getElementById('selectionBar');
    const count = document.getElementById('selectedCount');
    
    if (selectedItems.size > 0 && currentTab !== 'approved') {
        bar.style.display = 'flex';
        count.textContent = selectedItems.size;
    } else {
        bar.style.display = 'none';
    }
}

// Approval functions
function openApprovalModal(ledgerId, level) {
    const item = allItems.find(i => i.id === ledgerId);
    if (!item) return;
    
    document.getElementById('modalTitle').textContent = `Approve ${item.number}`;
    document.getElementById('modalBody').innerHTML = `
        <div class="space-y-4">
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span class="text-gray-600">Invoice #</span>
                <span class="font-semibold">${item.number}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span class="text-gray-600">Customer</span>
                <span class="font-semibold">${escapeHtml(item.requester)}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span class="text-gray-600">Amount</span>
                <span class="font-bold text-red-600">Rs. ${numberFormat(item.amount)}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span class="text-gray-600">Date</span>
                <span class="font-semibold">${formatDate(item.date)}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span class="text-gray-600">Level</span>
                <span class="invoice-level ${item.level === 1 ? 'l1' : 'l2'}">L${item.level}</span>
            </div>
        </div>
    `;
    
    // Show L1 Only button for L1 items
    document.getElementById('modalL1OnlyBtn').style.display = level === 1 ? 'block' : 'none';
    
    // Store item for confirmation
    window.pendingApprovalItem = item;
    
    const modal = document.getElementById('approvalModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeModal() {
    document.getElementById('approvalModal').style.display = 'none';
    window.pendingApprovalItem = null;
}

async function confirmApprove() {
    if (!window.pendingApprovalItem) return;
    await doApprove(window.pendingApprovalItem.id, 'full');
    closeModal();
}

async function confirmL1Only() {
    if (!window.pendingApprovalItem) return;
    await doApprove(window.pendingApprovalItem.id, 'l1_only');
    closeModal();
}

async function doApprove(ledgerId, approvalType) {
    // Use web routes (session auth) instead of API routes (Sanctum auth)
    const url = approvalType === 'l1_only' 
        ? `/finance/ledger/${ledgerId}/approve-l1-only`
        : `/finance/ledger/${ledgerId}/approve`;
    
    const body = approvalType === 'l1_only'
        ? { approval_notes: 'Approved from Online Approvals web' }
        : { approval_notes: 'Approved from Online Approvals web', force_full_approval: true };
    
    console.log('=== doApprove ===');
    console.log('URL:', url);
    console.log('Body:', body);
    
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify(body)
        });
        
        console.log('Response status:', response.status);
        const data = await response.json();
        console.log('Response data:', data);
        
        if (data.success) {
            showToast(data.message || 'Approved successfully!', 'success');
            loadData(); // Refresh data
            refreshStats(); // Refresh counts
        } else {
            showToast(data.message || 'Failed to approve', 'error');
        }
    } catch (error) {
        console.error('Approval error:', error);
        showToast('Error approving. Please try again.', 'error');
    }
}

// =============================================
// BULK APPROVAL FUNCTIONS (Similar to Mobile)
// =============================================

// Store pending bulk items
window.pendingBulkItems = [];
window.pendingBulkContext = ''; // 'selection' or customer name for group

// Open bulk approval modal for selected items (legacy - kept for backwards compatibility)
function approveSelected() {
    if (selectedItems.size === 0) {
        showToast('Please select items to approve', 'error');
        return;
    }
    
    const items = allItems.filter(item => selectedItems.has(`${item.type}_${item.id}`));
    
    if (items.length === 0) {
        showToast('No items found for selection', 'error');
        return;
    }
    
    openBulkApprovalModal(items, 'Selected Items');
}

// ⭐ Direct full approval for selected items (skips modal)
async function fullApproveSelected() {
    if (selectedItems.size === 0) {
        showToast('Please select items to approve', 'error');
        return;
    }
    
    const items = allItems.filter(item => selectedItems.has(`${item.type}_${item.id}`));
    
    if (items.length === 0) {
        showToast('No items found for selection', 'error');
        return;
    }
    
    const totalAmount = items.reduce((sum, i) => sum + (parseFloat(i.amount) || 0), 0);
    const confirmed = confirm(
        `FULL APPROVE ${items.length} invoice(s)?\n\n` +
        `Total: Rs. ${numberFormat(totalAmount)}\n\n` +
        `This will fully approve all items (L1→Approved, L2→Approved).`
    );
    
    if (!confirmed) return;
    
    await doBulkApprove([...items], 'full');
}

// ⭐ Direct L1-only approval for selected items (skips modal)
async function l1ApproveSelected() {
    if (selectedItems.size === 0) {
        showToast('Please select items to approve', 'error');
        return;
    }
    
    const items = allItems.filter(item => selectedItems.has(`${item.type}_${item.id}`));
    
    if (items.length === 0) {
        showToast('No items found for selection', 'error');
        return;
    }
    
    const l1Items = items.filter(i => i.level === 1);
    if (l1Items.length === 0) {
        showToast('No L1 items in your selection - L1 Only is for moving L1 items to L2 pending', 'info');
        return;
    }
    
    const totalAmount = items.reduce((sum, i) => sum + (parseFloat(i.amount) || 0), 0);
    const confirmed = confirm(
        `L1 APPROVE ${items.length} invoice(s)?\n\n` +
        `Total: Rs. ${numberFormat(totalAmount)}\n` +
        `L1 items: ${l1Items.length}\n\n` +
        `This will move L1 items to L2 pending for second review.\n` +
        `L2 items will be fully approved.`
    );
    
    if (!confirmed) return;
    
    await doBulkApprove([...items], 'l1_only');
}

// Open bulk approval modal for a customer group (legacy - now replaced by direct buttons)
function approveGroup(customerName) {
    const group = groupedItems.find(g => g.customer === customerName);
    if (!group || !group.items || group.items.length === 0) {
        showToast('No items found for this group', 'error');
        return;
    }
    
    openBulkApprovalModal(group.items, customerName);
}

// ⭐ Direct full approval for customer group (skips modal)
async function fullApproveGroup(customerName) {
    const group = groupedItems.find(g => g.customer === customerName);
    if (!group || !group.items || group.items.length === 0) {
        showToast('No items found for this group', 'error');
        return;
    }
    
    const totalAmount = group.items.reduce((sum, i) => sum + (parseFloat(i.amount) || 0), 0);
    const confirmed = confirm(
        `FULL APPROVE ${group.items.length} invoice(s) for ${customerName}?\n\n` +
        `Total: Rs. ${numberFormat(totalAmount)}\n\n` +
        `This will fully approve all items (L1→Approved, L2→Approved).`
    );
    
    if (!confirmed) return;
    
    await doBulkApprove([...group.items], 'full');
}

// ⭐ Direct L1-only approval for customer group (skips modal)
async function l1ApproveGroup(customerName) {
    const group = groupedItems.find(g => g.customer === customerName);
    if (!group || !group.items || group.items.length === 0) {
        showToast('No items found for this group', 'error');
        return;
    }
    
    const l1Items = group.items.filter(i => i.level === 1);
    if (l1Items.length === 0) {
        showToast('No L1 items in this group', 'info');
        return;
    }
    
    const totalAmount = group.items.reduce((sum, i) => sum + (parseFloat(i.amount) || 0), 0);
    const confirmed = confirm(
        `L1 APPROVE ${group.items.length} invoice(s) for ${customerName}?\n\n` +
        `Total: Rs. ${numberFormat(totalAmount)}\n` +
        `L1 items: ${l1Items.length}\n\n` +
        `This will move L1 items to L2 pending for second review.`
    );
    
    if (!confirmed) return;
    
    await doBulkApprove([...group.items], 'l1_only');
}

// Open the bulk approval modal with item details
function openBulkApprovalModal(items, contextLabel) {
    window.pendingBulkItems = items;
    window.pendingBulkContext = contextLabel;
    
    const hasL1 = items.some(i => i.level === 1);
    const hasL2 = items.some(i => i.level === 2);
    const l1Count = items.filter(i => i.level === 1).length;
    const l2Count = items.filter(i => i.level === 2).length;
    const totalAmount = items.reduce((sum, i) => sum + (parseFloat(i.amount) || 0), 0);
    
    // Set modal title
    document.getElementById('bulkModalTitle').textContent = `Approve ${items.length} Invoice(s)`;
    
    // Build modal body
    let bodyHtml = `
        <div class="space-y-4">
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="text-sm text-gray-600 mb-1">Customer/Group</div>
                <div class="font-semibold text-gray-900">${escapeHtml(contextLabel)}</div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-blue-700">${items.length}</div>
                    <div class="text-xs text-blue-600">Invoices</div>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <div class="text-lg font-bold text-green-700">Rs. ${numberFormat(totalAmount)}</div>
                    <div class="text-xs text-green-600">Total Amount</div>
                </div>
            </div>
            
            <div class="border-t border-gray-200 pt-4">
                <div class="text-sm font-medium text-gray-700 mb-2">Approval Levels:</div>
                <div class="flex gap-3">
                    ${hasL1 ? `<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-100 text-amber-800">
                        L1: ${l1Count} invoice${l1Count > 1 ? 's' : ''}
                    </span>` : ''}
                    ${hasL2 ? `<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                        L2: ${l2Count} invoice${l2Count > 1 ? 's' : ''}
                    </span>` : ''}
                </div>
            </div>
    `;
    
    // Add explanation based on what's present
    if (hasL1) {
        bodyHtml += `
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                <div class="text-sm text-amber-800">
                    <strong>💡 Approval Options:</strong><br>
                    • <strong>Full Approve</strong>: Fully approve all items (L1→Approved, L2→Approved)<br>
                    • <strong>L1 Only</strong>: Move L1 items to L2 pending (for second review)
                </div>
            </div>
        `;
    }
    
    bodyHtml += '</div>';
    
    document.getElementById('bulkModalBody').innerHTML = bodyHtml;
    
    // Show/hide L1 Only button based on whether there are L1 items
    const l1Btn = document.getElementById('bulkL1OnlyBtn');
    if (l1Btn) l1Btn.style.display = hasL1 ? 'flex' : 'none';
    
    // Show modal
    const modal = document.getElementById('bulkApprovalModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeBulkModal() {
    document.getElementById('bulkApprovalModal').style.display = 'none';
    window.pendingBulkItems = [];
    window.pendingBulkContext = '';
}

// Confirm and execute bulk approval
async function confirmBulkApprove(approvalType) {
    console.log('=== confirmBulkApprove called ===');
    console.log('approvalType:', approvalType);
    console.log('pendingBulkItems:', window.pendingBulkItems);
    
    if (!window.pendingBulkItems || window.pendingBulkItems.length === 0) {
        showToast('No items to approve', 'error');
        return;
    }
    
    // IMPORTANT: Save items to local variable BEFORE closing modal (which clears them)
    const itemsToApprove = [...window.pendingBulkItems];
    
    closeBulkModal();
    await doBulkApprove(itemsToApprove, approvalType);
}

// Execute bulk approval with progress tracking
async function doBulkApprove(items, approvalType) {
    console.log('=== doBulkApprove started ===');
    console.log('Items count:', items.length);
    console.log('Approval type:', approvalType);
    
    const total = items.length;
    let successCount = 0;
    let errorCount = 0;
    const errors = [];
    
    // Show processing overlay (with error handling)
    try {
        const overlay = document.getElementById('processingOverlay');
        if (overlay) {
            overlay.style.display = 'flex';
            document.getElementById('processingText').textContent = approvalType === 'l1_only' 
                ? 'Processing L1 approvals...' 
                : 'Processing full approvals...';
            document.getElementById('processingProgress').textContent = `0 / ${total}`;
        } else {
            console.warn('Processing overlay not found');
        }
    } catch (e) {
        console.error('Error showing overlay:', e);
    }
    
    console.log('Starting approval loop...');
    
    for (let i = 0; i < items.length; i++) {
        const item = items[i];
        console.log(`Processing item ${i + 1}/${total}:`, item.number, item.id);
        
        try {
            const progressEl = document.getElementById('processingProgress');
            if (progressEl) progressEl.textContent = `${i + 1} / ${total}`;
        } catch (e) {}
        
        try {
            // Determine URL and body based on approval type and item level
            // Use web routes (session auth) instead of API routes (Sanctum auth)
            let url, body;
            
            if (approvalType === 'l1_only' && item.level === 1) {
                // L1 Only: Move L1 items to L2 pending
                url = `/finance/ledger/${item.id}/approve-l1-only`;
                body = { approval_notes: 'Bulk L1-approved from Online Approvals web' };
            } else {
                // Full approval for all items
                url = `/finance/ledger/${item.id}/approve`;
                body = { 
                    approval_notes: 'Bulk approved from Online Approvals web', 
                    force_full_approval: true 
                };
            }
            
            console.log(`Approving item ${item.number}: ${url}`);
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(body)
            });
            
            console.log(`Response status for ${item.number}:`, response.status);
            
            const data = await response.json();
            console.log(`Response data for ${item.number}:`, data);
            
            if (data.success) {
                successCount++;
            } else {
                errorCount++;
                errors.push(`${item.number}: ${data.message || 'Unknown error'}`);
            }
        } catch (error) {
            console.error(`Error for ${item.number}:`, error);
            errorCount++;
            errors.push(`${item.number}: ${error.message || 'Network error'}`);
        }
    }
    
    console.log('=== Approval loop completed ===');
    console.log('Success:', successCount, 'Errors:', errorCount);
    
    // Hide processing overlay
    try {
        const overlay = document.getElementById('processingOverlay');
        if (overlay) overlay.style.display = 'none';
    } catch (e) {}
    
    // Show result
    let resultMessage = `✅ Approved: ${successCount}`;
    if (errorCount > 0) {
        resultMessage += ` | ❌ Failed: ${errorCount}`;
    }
    
    showToast(resultMessage, successCount > 0 ? 'success' : 'error');
    
    // Show detailed errors if any
    if (errors.length > 0) {
        console.error('Bulk approval errors:', errors);
        // Optionally show a detailed error dialog
        if (errors.length <= 5) {
            setTimeout(() => {
                alert('Some approvals failed:\n\n' + errors.join('\n'));
            }, 500);
        }
    }
    
    // Clear selection and refresh
    selectedItems.clear();
    updateSelectionBar();
    
    // Refresh the data list and stats
    loadData();
    refreshStats();
}

// Search
function onSearchInput() {
    clearTimeout(searchTimeout);
    const btn = document.getElementById('clearSearchBtn');
    const input = document.getElementById('searchInput');
    
    btn.classList.toggle('hidden', !input.value);
    
    searchTimeout = setTimeout(() => {
        loadData();
    }, 300);
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('clearSearchBtn').classList.add('hidden');
    loadData();
}

// Utilities
function numberFormat(num) {
    return Math.round(num || 0).toLocaleString();
}

function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showToast(message, type) {
    // Simple toast implementation
    const toast = document.createElement('div');
    toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 font-semibold ${type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}
</script>
@endpush
