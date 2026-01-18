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
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }

    .approve-group-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
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
            <div class="amount">Rs. {{ number_format($summaries['l1']['amount'] + $summaries['l2']['amount'], 0) }}</div>
        </div>
        @endif

        @if($hasLevel1Rights)
        <div class="tab-card l1" id="tab-l1" data-tab="l1" onclick="selectTab('l1')">
            <div class="title">📋 L1 Pending</div>
            <div class="count" id="count-l1">{{ $summaries['l1']['count'] }}</div>
            <div class="amount">Rs. {{ number_format($summaries['l1']['amount'], 0) }}</div>
        </div>
        @endif

        @if($hasLevel2Rights)
        <div class="tab-card l2" id="tab-l2" data-tab="l2" onclick="selectTab('l2')">
            <div class="title">📋 L2 Pending</div>
            <div class="count" id="count-l2">{{ $summaries['l2']['count'] }}</div>
            <div class="amount">Rs. {{ number_format($summaries['l2']['amount'], 0) }}</div>
        </div>
        @endif

        <div class="tab-card approved" id="tab-approved" data-tab="approved" onclick="selectTab('approved')">
            <div class="title">✅ Approved</div>
            <div class="count" id="count-approved">{{ $summaries['approved']['count'] }}</div>
            <div class="amount">Rs. {{ number_format($summaries['approved']['amount'], 0) }}</div>
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
        <button class="approve-btn" onclick="approveSelected()">✓ Approve Selected</button>
    </div>
</div>

<!-- Approval Modal -->
<div id="approvalModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900" id="modalTitle">Approve Invoice</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
        </div>
        <div class="p-6" id="modalBody">
            <!-- Modal content will be loaded here -->
        </div>
        <div class="p-6 bg-gray-50 border-t border-gray-200 flex gap-3">
            <button id="modalApproveBtn" onclick="confirmApprove()" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-semibold py-3 rounded-lg transition">
                ✓ Approve
            </button>
            <button id="modalL1OnlyBtn" onclick="confirmL1Only()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition" style="display: none;">
                L1 Only → L2
            </button>
            <button onclick="closeModal()" class="px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold rounded-lg transition">
                Cancel
            </button>
        </div>
    </div>
</div>
@endsection

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
                        <button class="approve-group-btn" onclick="approveGroup('${escapeHtml(group.customer)}')">
                            ✓ Approve All
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
    
    return `
        <div class="invoice-row ${isSelected ? 'selected' : ''}" data-item-key="${itemKey}">
            ${!isApproved ? `
            <div class="invoice-checkbox ${isSelected ? 'checked' : ''}" onclick="toggleItem('${itemKey}')">
                <span class="check-icon">${isSelected ? '✓' : ''}</span>
            </div>
            ` : ''}
            <a href="${item.view_url}" target="_blank" class="invoice-number">${item.number}</a>
            <span class="invoice-date">📅 ${formatDate(item.date)}</span>
            ${!isApproved ? `
            <span class="invoice-level ${item.level === 1 ? 'l1' : 'l2'}">L${item.level}</span>
            ` : `
            <span class="invoice-approved-by">✅ ${item.approved_by || 'System'}</span>
            `}
            <span class="invoice-amount">Rs. ${numberFormat(item.amount)}</span>
            <div class="invoice-actions">
                ${!isApproved ? `
                <button class="view-btn" onclick="openApprovalModal(${item.id}, ${item.level})">Review</button>
                ` : `
                <a href="${item.view_url}" target="_blank" class="view-btn">View</a>
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
    
    document.getElementById('approvalModal').style.display = 'flex';
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
    const url = approvalType === 'l1_only' 
        ? `/api/ledger/${ledgerId}/approve-l1-only`
        : `/api/ledger/${ledgerId}/approve`;
    
    const body = approvalType === 'l1_only'
        ? { approval_notes: 'Approved from Online Approvals web' }
        : { approval_notes: 'Approved from Online Approvals web', force_full_approval: true };
    
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
        
        const data = await response.json();
        
        if (data.success) {
            showToast(data.message || 'Approved successfully!', 'success');
            loadData(); // Refresh data
        } else {
            showToast(data.message || 'Failed to approve', 'error');
        }
    } catch (error) {
        console.error('Approval error:', error);
        showToast('Error approving. Please try again.', 'error');
    }
}

// Bulk approval
async function approveSelected() {
    if (selectedItems.size === 0) return;
    
    const items = allItems.filter(item => selectedItems.has(`${item.type}_${item.id}`));
    const hasL1 = items.some(i => i.level === 1);
    
    let message = `Approve ${items.length} selected invoice(s)?`;
    if (hasL1) {
        message += '\n\n• Full Approve: Complete approval\n• L1 Only: Move L1 items to L2 pending';
    }
    
    const result = confirm(message);
    if (!result) return;
    
    // For simplicity, do full approval for all
    await doBulkApprove(items, 'full');
}

async function approveGroup(customerName) {
    const group = groupedItems.find(g => g.customer === customerName);
    if (!group) return;
    
    const hasL1 = group.items.some(i => i.level === 1);
    
    let message = `Approve all ${group.items.length} invoice(s) for ${customerName}?`;
    
    const result = confirm(message);
    if (!result) return;
    
    await doBulkApprove(group.items, 'full');
}

async function doBulkApprove(items, approvalType) {
    let successCount = 0;
    let errorCount = 0;
    
    for (const item of items) {
        try {
            const url = (approvalType === 'l1_only' && item.level === 1)
                ? `/api/ledger/${item.id}/approve-l1-only`
                : `/api/ledger/${item.id}/approve`;
            
            const body = (approvalType === 'l1_only' && item.level === 1)
                ? { approval_notes: 'Bulk approved from Online Approvals web' }
                : { approval_notes: 'Bulk approved from Online Approvals web', force_full_approval: true };
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(body)
            });
            
            const data = await response.json();
            if (data.success) {
                successCount++;
            } else {
                errorCount++;
            }
        } catch (error) {
            errorCount++;
        }
    }
    
    showToast(`✅ Approved: ${successCount}${errorCount > 0 ? ` | ❌ Failed: ${errorCount}` : ''}`, successCount > 0 ? 'success' : 'error');
    
    selectedItems.clear();
    updateSelectionBar();
    loadData();
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
