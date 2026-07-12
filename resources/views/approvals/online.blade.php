@extends('layouts.app')

@section('title', 'Online Approvals')

@push('demo1_css')
<style>
    /* Tab Cards */
    .tab-card {
        background: white;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        padding: 10px 16px;
        cursor: pointer;
        transition: all 0.2s;
        min-width: 132px;
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
        font-size: 22px;
        font-weight: 800;
        color: #111827;
        margin: 4px 0 2px;
    }

    .tab-card .amount {
        font-size: 13px;
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
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="{{ route('fin.bank-balances') }}" style="background: #ECFDF5; color: #065F46; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 1px solid #A7F3D0; cursor: pointer; font-size: 13px; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='#D1FAE5'" onmouseout="this.style.background='#ECFDF5'">
                📊 Bank Balances
            </a>
            @if($isTaimur ?? false)
            <button onclick="openBankAccountsModal()" style="background: #F0F9FF; color: #0369A1; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 1px solid #BAE6FD; cursor: pointer; font-size: 13px; transition: all 0.2s;" onmouseover="this.style.background='#E0F2FE'" onmouseout="this.style.background='#F0F9FF'">
                🏦 Manage Banks
            </button>
            @endif
            <a href="{{ route('approvals.index') }}" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                ← Back to All Approvals
            </a>
        </div>
    </div>

    <!-- Tab Cards -->
    <div class="flex gap-3 mb-4 flex-wrap">
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

        {{-- Shop customers — order-based outstanding list (settled via incremental payments) --}}
        <div class="tab-card shop" id="tab-shop" data-tab="shop" onclick="selectTab('shop')" style="border-left: 4px solid #F59E0B;">
            <div class="title">🏪 Shop</div>
            <div class="count" id="count-shop">{{ $summaries['shop']['count'] ?? 0 }}</div>
            <div class="amount" id="amount-shop">Rs. {{ number_format($summaries['shop']['amount'] ?? 0, 0) }}</div>
        </div>
    </div>

    <!-- Search and Sort Row -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <!-- Row 1: search + sort (left), select-all + counts (right) -->
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-4 flex-wrap">
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

            <div class="flex items-center gap-4">
                <button type="button"
                        id="selectAllBtn"
                        onclick="selectAllFiltered()"
                        class="px-3 py-2 text-sm font-medium border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
                        style="display: none;">☑ Select all</button>
                <div class="text-sm text-gray-600">
                    <span id="totalCount">0</span> invoices •
                    <span class="font-semibold text-red-600">Rs. <span id="totalAmount">0</span></span>
                </div>
            </div>
        </div>

        <!-- Row 2: payment-proof filter (Jun-2026: bulk-approve by WhatsApp/email proof) -->
        <div class="flex items-center gap-2 mt-3 pt-3 border-t border-gray-100 flex-wrap">
            <label class="text-sm font-medium text-gray-600">Proof:</label>
            <div id="proofFilter" class="inline-flex rounded-lg border border-gray-300 overflow-hidden">
                <button type="button" data-proof="all" class="proof-chip px-3 py-2 text-sm font-medium bg-blue-600 text-white" onclick="selectProofFilter('all')">All</button>
                <button type="button" data-proof="pending" class="proof-chip px-3 py-2 text-sm font-medium bg-white text-gray-700 border-l border-gray-300 hover:bg-gray-50" onclick="selectProofFilter('pending')">🕓 No proof</button>
                <button type="button" data-proof="received" class="proof-chip px-3 py-2 text-sm font-medium bg-white text-gray-700 border-l border-gray-300 hover:bg-gray-50" onclick="selectProofFilter('received')">📥 Received</button>
                <button type="button" data-proof="verified" class="proof-chip px-3 py-2 text-sm font-medium bg-white text-gray-700 border-l border-gray-300 hover:bg-gray-50" onclick="selectProofFilter('verified')">✅ Verified</button>
                <button type="button" data-proof="mismatch" class="proof-chip px-3 py-2 text-sm font-medium bg-white text-gray-700 border-l border-gray-300 hover:bg-gray-50" onclick="selectProofFilter('mismatch')">⚠️ Mismatch</button>
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
        <span class="count"><span id="selectedCount">0</span> selected &bull; <span style="color: #34D399; font-weight: 700;">Rs. <span id="selectedAmount">0</span></span></span>
        <button class="clear-btn" onclick="clearSelection()">✕ Clear</button>
        <button style="background: #25D366; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;" onclick="sendPaymentReminder()">💬 Payment Reminder</button>
        <button id="bulkAssignBankBtn" style="background: #F0F9FF; color: #0369A1; border: 1px solid #BAE6FD; padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer;" onclick="openBulkAssignBank()" title="Assign a bank to selected items that have no detected bank, so they can be approved">🏦 Assign bank</button>
        <button class="full-approve-btn" onclick="fullApproveSelected()">✓ Full Approve</button>
        <button class="l1-approve-btn" onclick="l1ApproveSelected()">→ L1 Only</button>
    </div>
</div>

<!-- ⭐ Bulk "assign bank" overlay (for selected invoices with no detected bank) -->
<div id="bulkAssignBankOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10002; align-items:center; justify-content:center; padding:20px;" onclick="if(event.target===this) closeBulkAssignBank()">
    <div style="background:#fff; border-radius:14px; padding:22px; max-width:460px; width:100%;" onclick="event.stopPropagation()">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:#111827;">🏦 Assign bank to selected</h3>
            <button onclick="closeBulkAssignBank()" style="border:none; background:#F3F4F6; border-radius:8px; padding:4px 10px; cursor:pointer; font-size:18px;">×</button>
        </div>
        <p style="font-size:12px; color:#6B7280; margin:0 0 12px;">Items that already have a detected or tagged bank keep theirs. Items with <b>no</b> bank (e.g. proof sent to another WhatsApp number) will use the bank you pick here so they can be approved.</p>
        <div id="bulkAssignBankChips" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
            <button onclick="clearBulkAssignBank()" style="padding:8px 14px; border:1px solid #D1D5DB; background:#fff; border-radius:8px; cursor:pointer; font-size:13px;">Clear</button>
            <button onclick="closeBulkAssignBank()" style="padding:8px 16px; border:none; background:#0369A1; color:#fff; border-radius:8px; cursor:pointer; font-weight:600; font-size:13px;">Done</button>
        </div>
    </div>
</div>

@endsection

@push('modals')
<!-- Approval Modal (Single Item) -->
<div id="approvalModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 10000; justify-content: center; align-items: center;" onclick="if(event.target === this) closeModal()">
    <div style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 420px; margin: 20px; overflow: hidden; display: flex; flex-direction: column; max-height: calc(100vh - 40px);" onclick="event.stopPropagation()">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <h3 id="modalTitle" style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">Approve Invoice</h3>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
        <!-- Scrollable content region — keeps the header + footer (Approve button) fixed so the button stays reachable when a proof image makes the body tall -->
        <div id="approvalModalScroll" style="overflow-y: auto; overflow-x: hidden; flex: 1 1 auto; -webkit-overflow-scrolling: touch;">
        <div id="modalBody" style="padding: 24px;">
            <!-- Modal content will be loaded here -->
        </div>
        <!-- ⭐ Already-received payment proof (auto-matched WhatsApp screenshot) -->
        <div id="receivedProofBox" style="display: none; padding: 0 24px 12px 24px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">🧾 Received proof</label>
            <a id="receivedProofLink" href="#" target="_blank" style="display: block;">
                <img id="receivedProofImg" src="" style="max-height: 150px; border-radius: 8px; border: 1px solid #E5E7EB;">
            </a>
        </div>
        <!-- ⭐ Receiving Bank Account Selection (mandatory for online) -->
        <div style="padding: 0 24px 12px 24px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: #0369A1; margin-bottom: 6px;">🏦 Received in Bank <span style="color:#DC2626;">*</span></label>
            <div id="bankDetectedHint" style="display: none; font-size: 12px; color: #16A34A; margin-bottom: 6px;"></div>
            <div id="bankAccountChips" style="display: flex; flex-wrap: wrap; gap: 6px;">
                <button type="button" class="bank-chip bank-chip-active" data-bank-id="" onclick="selectBankAccount(this, '')" style="padding: 4px 12px; border-radius: 16px; border: 1px solid #CBD5E1; background: #3B82F6; color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                    None
                </button>
            </div>
        </div>
        <!-- ⭐ Proof of Payment Image Upload -->
        <div style="padding: 0 24px 12px 24px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">📷 Proof of Payment (optional)</label>
            <div style="display: flex; align-items: center; gap: 10px;">
                <input type="file" id="proofImageInput" accept="image/*" onchange="previewProofImage(this)" style="font-size: 13px; color: #6B7280;">
                <button id="clearProofBtn" onclick="clearProofImage()" style="display: none; background: #FEE2E2; color: #DC2626; border: 1px solid #FCA5A5; border-radius: 6px; padding: 4px 10px; font-size: 12px; cursor: pointer;">✕ Remove</button>
            </div>
            <img id="proofImagePreview" src="" style="display: none; margin-top: 8px; max-height: 120px; border-radius: 8px; border: 1px solid #E5E7EB;">
        </div>

        <!-- ⭐ Transaction Reference (Apr 2026) — customer-side txn ID for
             audit / recall. Purely informational; not part of finance logic. -->
        <div style="padding: 0 24px 16px 24px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">🔗 Transaction Reference (optional)</label>
            <input type="text" id="transactionReferenceInput" placeholder="e.g. TX-9871231 or banking app reference"
                   style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
        </div>
        </div>
        <!-- /scrollable content region -->
        <div style="padding: 20px 24px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; gap: 12px; flex-shrink: 0;">
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
    <div style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 450px; margin: 20px; overflow: hidden; display: flex; flex-direction: column; max-height: calc(100vh - 40px);" onclick="event.stopPropagation()">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <h3 id="bulkModalTitle" style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">Bulk Approval</h3>
            <button onclick="closeBulkModal()" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
        <div id="bulkModalBody" style="padding: 24px; overflow-y: auto; overflow-x: hidden; flex: 1 1 auto; -webkit-overflow-scrolling: touch;">
            <!-- Bulk modal content will be loaded here -->
        </div>
        <div style="padding: 20px 24px; background: #f9fafb; border-top: 1px solid #e5e7eb; flex-shrink: 0;">
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

<!-- ⭐ Customer Report Modal -->
<div id="reportModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 10000; justify-content: center; align-items: center;" onclick="if(event.target === this) closeReportModal()">
    <div style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 520px; margin: 20px; overflow: hidden; display: flex; flex-direction: column; max-height: calc(100vh - 40px);" onclick="event.stopPropagation()">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <h3 id="reportModalTitle" style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">📋 Report</h3>
            <button onclick="closeReportModal()" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
        <div id="reportModalBody" style="padding: 24px; overflow-y: auto; overflow-x: hidden; flex: 1 1 auto; -webkit-overflow-scrolling: touch;">
            <!-- Report content will be loaded here -->
        </div>
        <div style="padding: 16px 24px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; flex-direction: column; gap: 10px; flex-shrink: 0;">
            <div style="display: flex; gap: 10px;">
                <button onclick="copyReportText()" style="flex: 1; background: #F3F4F6; color: #374151; font-weight: 600; padding: 12px 16px; border-radius: 10px; border: 1px solid #D1D5DB; cursor: pointer; font-size: 14px; transition: background 0.2s;" onmouseover="this.style.background='#E5E7EB'" onmouseout="this.style.background='#F3F4F6'">
                    📋 Copy Text
                </button>
                <button onclick="printReport()" style="flex: 1; background: #EEF2FF; color: #4F46E5; font-weight: 600; padding: 12px 16px; border-radius: 10px; border: 1px solid #C7D2FE; cursor: pointer; font-size: 14px; transition: background 0.2s;" onmouseover="this.style.background='#E0E7FF'" onmouseout="this.style.background='#EEF2FF'">
                    🖨️ Print
                </button>
            </div>
            <button id="reportWhatsAppBtn" onclick="" style="display: none; width: 100%; background: #25D366; color: white; font-weight: 700; padding: 14px 20px; border-radius: 10px; border: none; cursor: pointer; font-size: 15px; align-items: center; justify-content: center; gap: 8px; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(37,211,102,0.4)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                💬 Send to Customer via WhatsApp
            </button>
        </div>
    </div>
</div>

<!-- ⭐ Payment Reminder Confirmation Modal -->
<div id="paymentReminderModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 10000; justify-content: center; align-items: center;" onclick="if(event.target === this) closePaymentReminderModal()">
    <div style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 520px; margin: 20px; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;" onclick="event.stopPropagation()">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <h3 style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">💬 Send Payment Reminder</h3>
            <button onclick="closePaymentReminderModal()" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
        <div id="paymentReminderBody" style="padding: 24px; overflow-y: auto; flex: 1;">
            <!-- Populated by JS -->
        </div>
        <div style="padding: 16px 24px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; gap: 10px; flex-shrink: 0;">
            <button onclick="closePaymentReminderModal()" style="flex: 1; background: #F3F4F6; color: #374151; font-weight: 600; padding: 12px 16px; border-radius: 10px; border: 1px solid #D1D5DB; cursor: pointer; font-size: 14px;">
                Cancel
            </button>
            <button id="paymentReminderSendBtn" onclick="confirmSendPaymentReminder()" style="flex: 1; background: #25D366; color: white; font-weight: 700; padding: 12px 16px; border-radius: 10px; border: none; cursor: pointer; font-size: 14px;">
                💬 Send via WhatsApp
            </button>
        </div>
    </div>
</div>

<!-- ⭐ Bank Accounts Management Modal -->
<div id="bankAccountsModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 10000; justify-content: center; align-items: center;" onclick="if(event.target === this) closeBankAccountsModal()">
    <div style="background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 520px; margin: 20px; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;" onclick="event.stopPropagation()">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <h3 style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">🏦 Manage Bank Accounts</h3>
            <button onclick="closeBankAccountsModal()" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
        </div>
        
        <!-- Add new account form -->
        <div style="padding: 16px 24px; background: #F0F9FF; border-bottom: 1px solid #BAE6FD; flex-shrink: 0;">
            <div style="display: flex; gap: 8px; align-items: flex-end;">
                <div style="flex: 1.5;">
                    <label style="display: block; font-size: 11px; font-weight: 600; color: #6B7280; margin-bottom: 4px;">Name</label>
                    <input type="text" id="newBankName" placeholder="e.g. HBL" style="width: 100%; padding: 8px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; font-size: 11px; font-weight: 600; color: #6B7280; margin-bottom: 4px;">Short Code</label>
                    <input type="text" id="newBankCode" placeholder="e.g. HBL" style="width: 100%; padding: 8px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="flex: 0.7;">
                    <label style="display: block; font-size: 11px; font-weight: 600; color: #6B7280; margin-bottom: 4px;" title="Last 4 digits of this account — used to auto-detect the bank from a proof">Last 4</label>
                    <input type="text" id="newBankLast4" maxlength="4" inputmode="numeric" placeholder="4237" style="width: 100%; padding: 8px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="flex: 0.6;">
                    <label style="display: block; font-size: 11px; font-weight: 600; color: #6B7280; margin-bottom: 4px;">Color</label>
                    <input type="color" id="newBankColor" value="#3B82F6" style="width: 100%; height: 36px; border: 1px solid #D1D5DB; border-radius: 6px; cursor: pointer; padding: 2px;">
                </div>
                <button onclick="addBankAccount()" style="background: #0369A1; color: white; font-weight: 600; padding: 8px 14px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; white-space: nowrap; height: 36px;">+ Add</button>
            </div>
        </div>
        
        <!-- Accounts list -->
        <div id="bankAccountsList" style="padding: 16px 24px; overflow-y: auto; flex: 1;">
            <div style="text-align: center; color: #9CA3AF; padding: 20px;">Loading...</div>
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
let currentProof = 'all'; // 'all', 'pending', 'received', 'verified', 'mismatch'
let selectedItems = new Set();
let allItems = [];
let groupedItems = [];
// Shop-tab multi-select — kept SEPARATE from selectedItems so the L1/L2
// bulk-approve flow is completely untouched. Selection is confined to ONE shop
// customer at a time (one bank transfer has one payer).
let shopSelectedOrderIds = new Set();
let shopSelectedCustomer = null;
// Shop names whose "Recently paid" rollup is currently expanded (collapsed by default).
let shopPaidExpanded = new Set();
let searchTimeout;
// ⭐ Receiving bank accounts for dropdown
const receivingAccounts = @json($receivingAccounts ?? []);
// Only the Taimur role can revert an accidental approval back to pending.
const canRevertApprovals = @json($isTaimur ?? false);

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
    // Reset the shop multi-select whenever the tab changes so a stale
    // selection can't carry over into another tab.
    clearShopSelection();
    
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

    // Select-all is only meaningful for the regular pending queues. Approved
    // can't be bulk-approved, and Shop is an order-based payment list.
    const selectAllBtn = document.getElementById('selectAllBtn');
    if (selectAllBtn) selectAllBtn.style.display = (tab === 'approved' || tab === 'shop') ? 'none' : 'inline-flex';
    
    loadData();
}

// Payment-proof filter selection
function selectProofFilter(proof) {
    currentProof = proof;
    selectedItems.clear();
    updateSelectionBar();

    document.querySelectorAll('#proofFilter .proof-chip').forEach(chip => {
        const isActive = chip.dataset.proof === proof;
        chip.classList.toggle('bg-blue-600', isActive);
        chip.classList.toggle('text-white', isActive);
        chip.classList.toggle('bg-white', !isActive);
        chip.classList.toggle('text-gray-700', !isActive);
    });

    loadData();
}

// Select every currently-listed (filtered) pending item for one-tap bulk approve.
function selectAllFiltered() {
    if (currentTab === 'approved') return;
    const allSelected = allItems.length > 0 &&
        allItems.every(item => selectedItems.has(`${item.type}_${item.id}`));
    if (allSelected) {
        // Acts as a toggle: a second click clears the selection.
        selectedItems.clear();
    } else {
        allItems.forEach(item => selectedItems.add(`${item.type}_${item.id}`));
    }
    updateSelectionUI();
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
        const response = await fetch(`{{ route('approvals.online') }}?tab=${currentTab}&search=${encodeURIComponent(search)}&sort=${sort}&proof=${currentProof}`, {
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
            updateShopSelectionBar(); // keep the shop floating bar in sync after a reload
            document.getElementById('totalCount').textContent = data.count;
            document.getElementById('totalAmount').textContent = numberFormat(data.total_amount);

            // Keep the Shop tab badge in sync (outstanding count + balance).
            if (currentTab === 'shop') {
                const outstanding = (data.items || []).filter(i => (parseFloat(i.balance_remaining) || 0) > 0.01);
                const balSum = (data.items || []).reduce((s, i) => s + (parseFloat(i.balance_remaining) || 0), 0);
                const cEl = document.getElementById('count-shop');
                const aEl = document.getElementById('amount-shop');
                if (cEl) cEl.textContent = outstanding.length;
                if (aEl) aEl.textContent = `Rs. ${numberFormat(balSum)}`;
            }
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
    
    // ⭐ Bank-wise summary bar for approved tab
    if (currentTab === 'approved' && allItems.length > 0) {
        const bankMap = {};
        let untaggedCount = 0, untaggedAmount = 0;
        allItems.forEach(item => {
            if (item.receiving_account_short) {
                const key = item.receiving_account_short;
                if (!bankMap[key]) bankMap[key] = {count: 0, amount: 0, color: item.receiving_account_color || '#6B7280'};
                bankMap[key].count++;
                bankMap[key].amount += parseFloat(item.amount) || 0;
            } else {
                untaggedCount++;
                untaggedAmount += parseFloat(item.amount) || 0;
            }
        });
        const bankEntries = Object.entries(bankMap);
        if (bankEntries.length > 0 || untaggedCount > 0) {
            html += '<div style="display: flex; flex-wrap: wrap; gap: 8px; padding: 12px 16px; background: #F0F9FF; border-bottom: 1px solid #BAE6FD;">';
            html += '<span style="font-size: 13px; font-weight: 600; color: #0369A1; align-self: center; margin-right: 4px;">🏦 Bank-wise:</span>';
            bankEntries.forEach(([bank, data]) => {
                html += `<span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 8px; background: white; border: 1px solid ${data.color}40; font-size: 12px;">
                    <span style="font-weight: 700; color: ${data.color};">${escapeHtml(bank)}</span>
                    <span style="font-weight: 700; color: #111827;">Rs. ${numberFormat(data.amount)}</span>
                    <span style="color: #6B7280; font-size: 11px;">(${data.count})</span>
                </span>`;
            });
            if (untaggedCount > 0) {
                html += `<span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 8px; background: white; border: 1px solid #D1D5DB; font-size: 12px;">
                    <span style="font-weight: 700; color: #6B7280;">Untagged</span>
                    <span style="font-weight: 700; color: #111827;">Rs. ${numberFormat(untaggedAmount)}</span>
                    <span style="color: #6B7280; font-size: 11px;">(${untaggedCount})</span>
                </span>`;
            }
            html += '</div>';
        }
    }
    
    const isShopTab = currentTab === 'shop';

    groups.forEach((group, index) => {
        const colorClass = index % 2 === 0 ? 'blue' : 'yellow';
        const isApproved = currentTab === 'approved';

        // Shop tab: order-based outstanding list — no bulk-approve, no
        // checkboxes. Each row offers Add Payment / View payments instead.
        if (isShopTab) {
            const outstanding = group.items.reduce((s, it) => s + (parseFloat(it.balance_remaining) || 0), 0);
            // First selectable (unpaid/partial) invoice — used by the group
            // "Select all" button, which selects every unpaid invoice of this shop.
            const shopFirstSelectable = (group.items.find(it => (parseFloat(it.balance_remaining) || 0) > 0.01) || {}).order_id;
            html += `
            <div class="customer-group">
                <div class="customer-group-header ${colorClass}">
                    <div class="customer-info">
                        <span class="customer-icon">🏪</span>
                        <span class="customer-name">${escapeHtml(group.customer)}</span>
                        <span class="customer-badge">${group.items.length} order${group.items.length > 1 ? 's' : ''}</span>
                    </div>
                    <div class="customer-actions">
                        <span class="customer-total" title="Outstanding balance">Outstanding: Rs. ${numberFormat(outstanding)}</span>
                        ${shopFirstSelectable ? `
                        <button class="approve-group-btn" style="background: #EEF2FF; color: #4F46E5; border: 1px solid #C7D2FE;" onclick="selectAllShopGroup(${shopFirstSelectable})" title="Select all unpaid invoices of ${escapeHtml(group.customer)} for a bulk payment">☑ All</button>
                        ` : ''}
                        ${group.customer_phone && formatPhoneForWhatsApp(group.customer_phone) ? `
                        <a href="https://wa.me/${formatPhoneForWhatsApp(group.customer_phone)}" target="_blank"
                           class="approve-group-btn" style="background: #25D366; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; color: white;"
                           title="WhatsApp ${escapeHtml(group.customer)}">💬</a>
                        ` : ''}
                        <button class="approve-group-btn" style="background: #EEF2FF; color: #4F46E5; border: 1px solid #C7D2FE;" onclick="openCustomerReport('${escapeHtml(group.customer)}')" title="Generate report for ${escapeHtml(group.customer)}">📋</button>
                    </div>
                </div>
                <div class="customer-items">
                    ${renderShopGroupBody(group)}
                </div>
            </div>`;
            return;
        }

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
                        ${group.customer_phone && formatPhoneForWhatsApp(group.customer_phone) ? `
                        <a href="https://wa.me/${formatPhoneForWhatsApp(group.customer_phone)}" target="_blank"
                           class="approve-group-btn" style="background: #25D366; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; color: white;" 
                           title="WhatsApp ${escapeHtml(group.customer)}">
                            💬
                        </a>
                        ` : ''}
                        ${!isApproved ? `
                        ${group.customer_phone && formatPhoneForWhatsApp(group.customer_phone) ? `
                        <button class="approve-group-btn" style="background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; font-size: 11px;" onclick="sendPaymentReminderForGroup('${escapeHtml(group.customer)}')" title="Send payment reminder via WhatsApp API">
                            💬 Remind
                        </button>
                        ` : ''}
                        <button class="approve-group-btn" style="background: #EEF2FF; color: #4F46E5; border: 1px solid #C7D2FE;" onclick="openCustomerReport('${escapeHtml(group.customer)}')" title="Generate report for ${escapeHtml(group.customer)}">
                            📋
                        </button>
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
            ${renderBankBadge(item)}
            ${renderProofBadges(item)}
            <div class="invoice-actions">
                ${!isApproved ? `
                ${item.customer_phone ? `<button class="view-btn" style="background: #ECFDF5; color: #059669; border-color: #A7F3D0;" onclick="sendPaymentReminderForItem(${item.id})" title="Send payment reminder">💬</button>` : ''}
                <button class="view-btn" onclick="openApprovalModal(${item.id}, ${item.level})">Review</button>
                ` : `
                <a href="${orderViewUrl}" target="_blank" class="view-btn">View Order</a>
                ${canRevertApprovals ? `<button class="view-btn" style="background: #FEF2F2; color: #B91C1C; border-color: #FECACA;" onclick="revertToPending(${item.id}, '${escapeHtml(item.order_number || item.number || ('#' + item.id))}', ${item.amount || 0})" title="Send this approved payment back to pending approval">↩ Revert</button>` : ''}
                `}
            </div>
        </div>
    `;
}

// Render a single Shop-tab order row (order-based, not ledger-based).
function renderShopRow(item) {
    const total = parseFloat(item.amount) || 0;
    const paid = parseFloat(item.total_paid) || 0;
    const remaining = parseFloat(item.balance_remaining) || 0;
    const status = item.payment_status || 'unpaid';
    const statusStyles = {
        paid:    {bg: '#ECFDF5', color: '#059669', label: 'Paid'},
        partial: {bg: '#FFFBEB', color: '#B45309', label: 'Partial'},
        unpaid:  {bg: '#FEF2F2', color: '#B91C1C', label: 'Unpaid'},
    };
    const st = statusStyles[status] || statusStyles.unpaid;
    const orderViewUrl = item.order_id ? `/orders?edit_order_id=${item.order_id}` : '#';
    // Multi-select: only unpaid/partial rows are selectable (a fully-paid order
    // can't take a payment). Reuses the same checkbox styling as the regular tab.
    const shopOid = parseInt(item.order_id);
    const shopSel = shopSelectedOrderIds.has(shopOid);
    const shopSelectable = remaining > 0.01;

    return `
        <div class="invoice-row ${shopSel ? 'selected' : ''}" data-item-key="shop_order_${item.id}">
            ${shopSelectable ? `
            <div class="invoice-checkbox ${shopSel ? 'checked' : ''}" onclick="toggleShopItem(${shopOid})" title="Select for report / bulk payment">
                <span class="check-icon">${shopSel ? '✓' : ''}</span>
            </div>` : `<div class="invoice-checkbox" style="visibility:hidden;"><span class="check-icon"></span></div>`}
            <a href="${orderViewUrl}" target="_blank" class="invoice-number" title="View order details">${item.number}</a>
            <span class="invoice-date">📅 ${formatDate(item.date)}</span>
            <span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:${st.bg}; color:${st.color}; border:1px solid ${st.color}40;">${st.label}</span>
            <span class="invoice-amount" title="Paid of total">Rs. ${numberFormat(paid)} / ${numberFormat(total)}</span>
            ${remaining > 0.01 ? `<span style="color:#B91C1C; font-size:12px; font-weight:600; margin-left:6px;">Bal: Rs. ${numberFormat(remaining)}</span>` : ''}
            ${renderProofBadges(item)}
            <div class="invoice-actions">
                ${remaining > 0.01 ? `<button class="view-btn" style="background:#ECFDF5; color:#059669; border-color:#A7F3D0;" onclick="openShopPaymentModal(${item.order_id}, '${escapeHtml(item.number)}', ${total}, ${paid})">＋ Add payment</button>` : ''}
                <button class="view-btn" onclick="openShopPaymentsList(${item.order_id}, '${escapeHtml(item.number)}')">Payments</button>
            </div>
        </div>
    `;
}

// Shop group body: outstanding invoices shown normally; fully-paid ones (still
// within the 10-day window) collapse under a "Recently paid" rollup so they
// stay reachable (void) without cluttering the outstanding list. Older paid
// invoices aren't returned by the server — their permanent record is the
// customer history view.
function renderShopGroupBody(group) {
    const items = group.items || [];
    const outstanding = items.filter(it => (parseFloat(it.balance_remaining) || 0) > 0.01);
    const paid = items.filter(it => (parseFloat(it.balance_remaining) || 0) <= 0.01);
    let html = outstanding.map(item => renderShopRow(item)).join('');
    if (paid.length) {
        const expanded = shopPaidExpanded.has(group.customer);
        const paidTotal = paid.reduce((s, it) => s + (parseFloat(it.amount) || 0), 0);
        const key = parseInt(paid[0].order_id); // stable numeric id for this group's rollup
        html += `
            <div class="shop-paid-rollup">
                <div class="shop-paid-toggle" onclick="togglePaidRollup(${key}, '${escapeHtml(group.customer)}')"
                     style="cursor:pointer; display:flex; align-items:center; gap:8px; padding:10px 14px; background:#F0FDF4; border-top:1px solid #DCFCE7; color:#065F46; font-size:13px; font-weight:600;">
                    <span id="paidArrow_${key}" style="display:inline-block; transition:transform .15s; transform:rotate(${expanded ? 90 : 0}deg);">▸</span>
                    <span>🧾 Recently paid (${paid.length}) · Rs. ${numberFormat(paidTotal)}</span>
                    <span style="margin-left:auto; font-weight:500; color:#16A34A; font-size:11px;">last 10 days</span>
                </div>
                <div id="paidBody_${key}" style="display:${expanded ? 'block' : 'none'};">
                    ${paid.map(item => renderShopRow(item)).join('')}
                </div>
            </div>`;
    }
    return html;
}

// Expand/collapse a shop's "Recently paid" rollup in place (no full re-render,
// so scroll position is kept). The Set persists the state across re-renders.
function togglePaidRollup(key, customer) {
    const wasExpanded = shopPaidExpanded.has(customer);
    if (wasExpanded) shopPaidExpanded.delete(customer);
    else shopPaidExpanded.add(customer);
    const body = document.getElementById('paidBody_' + key);
    const arrow = document.getElementById('paidArrow_' + key);
    if (body) body.style.display = wasExpanded ? 'none' : 'block';
    if (arrow) arrow.style.transform = 'rotate(' + (wasExpanded ? 0 : 90) + 'deg)';
}

// Shop tab — add an incremental payment against a shop order.
// ⭐ Reusable receiving-bank CHIP picker (used by shop single/bulk payment and
// the bulk-assign-bank flow) — same bubble style as the approve modal. Chips
// render into #containerId; the chosen id lives on window[stateKey]. No "None"
// chip: the bank is mandatory for these flows.
function renderReceivingBankChips(containerId, stateKey) {
    const c = document.getElementById(containerId);
    if (!c) return;
    const sel = window[stateKey] || '';
    c.innerHTML = (receivingAccounts || []).map(a => {
        const active = String(sel) === String(a.id);
        const color = a.color_hex || '#3B82F6';
        return `<button type="button" onclick="selectReceivingBankChip('${containerId}','${stateKey}',${a.id})" style="padding:6px 14px; border-radius:16px; border:1px solid ${active ? color : '#CBD5E1'}; background:${active ? color : '#F1F5F9'}; color:${active ? '#fff' : '#475569'}; font-size:13px; font-weight:600; cursor:pointer;">${escapeHtml(a.short_code || a.name)}</button>`;
    }).join('');
}
function selectReceivingBankChip(containerId, stateKey, id) {
    window[stateKey] = id;
    renderReceivingBankChips(containerId, stateKey);
}

function openShopPaymentModal(orderId, orderNumber, total, paid) {
    const remaining = Math.max(0, (parseFloat(total) || 0) - (parseFloat(paid) || 0));
    let overlay = document.getElementById('shopPayOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'shopPayOverlay';
    overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px;';
    overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };

    const today = new Date().toISOString().slice(0, 10);

    overlay.innerHTML = `
      <div style="background:#fff; border-radius:12px; padding:24px; max-width:440px; width:100%;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
          <h3 style="margin:0; font-size:1.1rem;">Add payment — ${escapeHtml(orderNumber)}</h3>
          <button onclick="document.getElementById('shopPayOverlay').remove()" style="border:none; background:#F3F4F6; border-radius:8px; padding:4px 10px; cursor:pointer; font-size:18px;">×</button>
        </div>
        <div style="font-size:13px; color:#6B7280; margin-bottom:14px;">Remaining balance: <span style="font-weight:700; color:#B91C1C;">Rs. ${numberFormat(remaining)}</span></div>
        <div style="display:flex; flex-direction:column; gap:12px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Amount (Rs.)
            <input id="shopPayAmount" type="number" step="0.01" min="0.01" max="${remaining}" value="${remaining}" style="margin-top:4px; width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:8px;">
          </label>
          <label style="font-size:13px; font-weight:600; color:#374151;">Payment date
            <input id="shopPayDate" type="date" value="${today}" max="${today}" style="margin-top:4px; width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:8px;">
          </label>
          <div style="font-size:13px; font-weight:600; color:#374151;">🏦 Receiving bank <span style="color:#DC2626;">*</span>
            <div id="shopPaySingleBankChips" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;"></div>
          </div>
          <label style="font-size:13px; font-weight:600; color:#374151;">Reference (optional)
            <input id="shopPayRef" type="text" maxlength="255" style="margin-top:4px; width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:8px;">
          </label>
          <label style="font-size:13px; font-weight:600; color:#374151;">Notes (optional)
            <textarea id="shopPayNotes" rows="2" maxlength="1000" style="margin-top:4px; width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:8px;"></textarea>
          </label>
        </div>
        <div id="shopPayError" style="color:#DC2626; font-size:13px; margin-top:10px; display:none;"></div>
        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
          <button onclick="document.getElementById('shopPayOverlay').remove()" style="padding:8px 16px; border:1px solid #D1D5DB; background:#fff; border-radius:8px; cursor:pointer;">Cancel</button>
          <button id="shopPaySubmit" onclick="submitShopPayment(${orderId})" style="padding:8px 16px; border:none; background:#059669; color:#fff; border-radius:8px; cursor:pointer; font-weight:600;">Record payment</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    // Render the bank chips (bubble style) — selection stored on window.shopPaySingleBank.
    window.shopPaySingleBank = '';
    renderReceivingBankChips('shopPaySingleBankChips', 'shopPaySingleBank');
}

async function submitShopPayment(orderId) {
    const errBox = document.getElementById('shopPayError');
    const btn = document.getElementById('shopPaySubmit');
    errBox.style.display = 'none';
    const amount = parseFloat(document.getElementById('shopPayAmount').value);
    if (!amount || amount <= 0) {
        errBox.textContent = 'Enter a valid amount.';
        errBox.style.display = 'block';
        return;
    }
    // Bank is mandatory for online payments (per-bank balance tracking).
    const shopBankId = window.shopPaySingleBank || '';
    if (!shopBankId) {
        errBox.textContent = 'Select which bank received this payment.';
        errBox.style.display = 'block';
        return;
    }
    const payload = {
        amount: amount,
        payment_method: 'online',
        payment_date: document.getElementById('shopPayDate').value || null,
        receiving_account_id: shopBankId,
        reference: document.getElementById('shopPayRef').value || null,
        notes: document.getElementById('shopPayNotes').value || null,
    };
    btn.disabled = true;
    btn.textContent = 'Saving…';
    try {
        const res = await fetch(`/orders/${orderId}/shop-payments`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('shopPayOverlay').remove();
            loadData();
            refreshStats();
        } else {
            errBox.textContent = typeof data.message === 'string' ? data.message : 'Could not record payment.';
            errBox.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Record payment';
        }
    } catch (e) {
        errBox.textContent = 'Network error. Please try again.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Record payment';
    }
}

// Shop tab — list payments already recorded against a shop order (with void).
async function openShopPaymentsList(orderId, orderNumber) {
    let overlay = document.getElementById('shopPayListOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'shopPayListOverlay';
    overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px;';
    overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
    overlay.innerHTML = '<div style="background:#fff; border-radius:12px; padding:24px; max-width:560px; width:100%; max-height:88vh; overflow:auto;"><div style="text-align:center; color:#9CA3AF;">Loading payments…</div></div>';
    document.body.appendChild(overlay);

    try {
        const res = await fetch(`/orders/${orderId}/shop-payments`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        const data = await res.json();
        overlay.querySelector('div').innerHTML = buildShopPaymentsHtml(orderId, orderNumber, data);
    } catch (e) {
        overlay.querySelector('div').innerHTML = '<div style="color:#DC2626; text-align:center;">Could not load payments.</div>';
    }
}

function buildShopPaymentsHtml(orderId, orderNumber, data) {
    const payments = data.payments || [];
    let html = `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3 style="margin:0; font-size:1.1rem;">Payments — ${escapeHtml(orderNumber)}</h3>
        <button onclick="document.getElementById('shopPayListOverlay').remove()" style="border:none; background:#F3F4F6; border-radius:8px; padding:4px 10px; cursor:pointer; font-size:18px;">×</button>
    </div>`;
    html += `<div style="font-size:13px; color:#374151; margin-bottom:12px;">
        Total: <b>Rs. ${numberFormat(data.total_price || 0)}</b> •
        Paid: <b style="color:#059669;">Rs. ${numberFormat(data.total_paid || 0)}</b> •
        Balance: <b style="color:#B91C1C;">Rs. ${numberFormat(data.balance_remaining || 0)}</b></div>`;

    if (payments.length === 0) {
        html += '<div style="color:#9CA3AF;">No payments recorded yet.</div>';
        return html;
    }
    html += '<div style="display:flex; flex-direction:column; gap:8px;">';
    payments.forEach(p => {
        const bank = p.receiving_account_name ? ` • ${escapeHtml(p.receiving_account_name)}` : '';
        html += `<div style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border:1px solid #E5E7EB; border-radius:8px;">
            <div>
                <div style="font-weight:700;">Rs. ${numberFormat(p.amount)}</div>
                <div style="font-size:12px; color:#6B7280;">📅 ${formatDate(p.payment_date)}${bank}${p.reference ? ' • Ref: ' + escapeHtml(p.reference) : ''}</div>
            </div>
            <button onclick="voidShopPayment(${orderId}, ${p.id}, '${escapeHtml(orderNumber)}')" style="border:1px solid #FECACA; background:#FEF2F2; color:#B91C1C; border-radius:8px; padding:6px 10px; cursor:pointer; font-size:12px;">Void</button>
        </div>`;
    });
    html += '</div>';
    return html;
}

async function voidShopPayment(orderId, paymentId, orderNumber) {
    if (!confirm('Void this payment? This reverses its ledger entry.')) return;
    try {
        const res = await fetch(`/orders/${orderId}/shop-payments/${paymentId}`, {
            method: 'DELETE',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        });
        const data = await res.json();
        if (data.success) {
            openShopPaymentsList(orderId, orderNumber);
            loadData();
            refreshStats();
        } else {
            alert(data.message || 'Could not void payment.');
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}

// =====================================================
// Shop tab — multi-select + bulk payment
// =====================================================
let bulkPayItems = []; // snapshot of selected items (FIFO-sorted) for the modal

// Toggle one shop invoice in/out of the selection. The owning customer is
// looked up from groupedItems so we never embed the (quote-unsafe) name in an
// onclick attribute. Selection is confined to ONE shop at a time.
function toggleShopItem(orderId) {
    orderId = parseInt(orderId);
    const group = groupedItems.find(g => (g.items || []).some(it => parseInt(it.order_id) === orderId));
    const customerName = group ? group.customer : null;
    if (shopSelectedCustomer && shopSelectedCustomer !== customerName) {
        shopSelectedOrderIds.clear(); // starting a selection in a different shop
    }
    shopSelectedCustomer = customerName;
    if (shopSelectedOrderIds.has(orderId)) {
        shopSelectedOrderIds.delete(orderId);
    } else {
        shopSelectedOrderIds.add(orderId);
    }
    if (shopSelectedOrderIds.size === 0) shopSelectedCustomer = null;
    updateShopSelectionUI();
}

// Select (or, if already all selected, clear) every unpaid invoice of ONE shop
// customer — for a quick bulk payment. Identified by any of its order ids so we
// never embed the (quote-unsafe) customer name in the onclick.
function selectAllShopGroup(anyOrderId) {
    anyOrderId = parseInt(anyOrderId);
    const group = groupedItems.find(g => (g.items || []).some(it => parseInt(it.order_id) === anyOrderId));
    if (!group) return;
    const selectable = (group.items || []).filter(it => (parseFloat(it.balance_remaining) || 0) > 0.01);
    if (!selectable.length) return;
    const sameCustomer = shopSelectedCustomer === group.customer;
    const allSelected = sameCustomer && selectable.every(it => shopSelectedOrderIds.has(parseInt(it.order_id)));
    shopSelectedOrderIds.clear();
    if (allSelected) {
        shopSelectedCustomer = null; // toggle off
    } else {
        shopSelectedCustomer = group.customer;
        selectable.forEach(it => shopSelectedOrderIds.add(parseInt(it.order_id)));
    }
    updateShopSelectionUI();
}

function clearShopSelection() {
    shopSelectedOrderIds.clear();
    shopSelectedCustomer = null;
    updateShopSelectionUI();
}

function updateShopSelectionUI() {
    // Only the shop tab is re-rendered from cache; other tabs are unaffected.
    if (currentTab === 'shop') renderItems(groupedItems);
    updateShopSelectionBar();
}

// The currently-selected items, restricted to the active shop and to rows that
// are still present in the (possibly filtered) list.
function getShopSelectedItems() {
    if (!shopSelectedCustomer) return [];
    const group = groupedItems.find(g => g.customer === shopSelectedCustomer);
    if (!group) return [];
    return (group.items || []).filter(it => shopSelectedOrderIds.has(parseInt(it.order_id)));
}

// FIFO (oldest-first) — same ordering key the server uses (the on-screen date),
// so the client preview matches the server allocation.
function sortShopItemsFifo(items) {
    return [...items].sort((a, b) => {
        const da = String(a.date || ''), db = String(b.date || '');
        if (da !== db) return da < db ? -1 : 1;
        return (parseInt(a.order_id) || 0) - (parseInt(b.order_id) || 0);
    });
}

function updateShopSelectionBar() {
    let bar = document.getElementById('shopSelectionBar');
    const items = getShopSelectedItems();
    if (!items.length || currentTab !== 'shop') {
        if (bar) bar.style.display = 'none';
        return;
    }
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'shopSelectionBar';
        bar.style.cssText = 'position:fixed; left:50%; transform:translateX(-50%); bottom:24px; z-index:9500; background:#111827; color:#fff; border-radius:14px; padding:12px 16px; display:flex; align-items:center; gap:14px; box-shadow:0 10px 30px rgba(0,0,0,0.25); flex-wrap:wrap; max-width:94vw;';
        document.body.appendChild(bar);
    }
    const selBal = items.reduce((s, it) => s + (parseFloat(it.balance_remaining) || 0), 0);
    bar.style.display = 'flex';
    bar.innerHTML = `
        <div style="font-size:13px;">
            <span style="font-weight:800;">${items.length}</span> selected ·
            <span style="font-weight:800;">Rs. ${numberFormat(selBal)}</span>
            <span style="opacity:.7;"> — ${escapeHtml(shopSelectedCustomer || '')}</span>
        </div>
        <button onclick="openCustomerReport(shopSelectedCustomer, getShopSelectedItems())" style="background:#EEF2FF; color:#4F46E5; border:none; border-radius:8px; padding:8px 12px; font-weight:600; cursor:pointer;">📋 Report selected</button>
        <button onclick="openBulkPaymentModal()" style="background:#10B981; color:#fff; border:none; border-radius:8px; padding:8px 12px; font-weight:700; cursor:pointer;">＋ Add bulk payment</button>
        <button onclick="clearShopSelection()" style="background:transparent; color:#D1D5DB; border:1px solid #374151; border-radius:8px; padding:8px 12px; cursor:pointer;">Clear</button>
    `;
}

// Client-side mirror of the server rule (server stays authoritative). Works in
// integer paisa for exact money maths. Returns {ok, error} or {ok, rows}.
function computeBulkAllocation(sortedItems, amount) {
    const bals = sortedItems.map(it => Math.round((parseFloat(it.balance_remaining) || 0) * 100));
    const n = bals.length;
    if (n === 0) return { ok: false, error: 'No invoices selected.' };
    const amt = Math.round((parseFloat(amount) || 0) * 100);
    const sumAll = bals.reduce((s, c) => s + c, 0);
    const sumButLast = sumAll - bals[n - 1];
    if (amt <= 0) return { ok: false, error: 'Enter a valid amount.' };
    if (amt > sumAll) {
        return { ok: false, error: `Amount (Rs. ${numberFormat(amt / 100)}) is more than the selected total balance (Rs. ${numberFormat(sumAll / 100)}). Reduce it or select more invoices.` };
    }
    if (n > 1 && amt <= sumButLast) {
        return { ok: false, error: `This amount can't leave a balance on only the newest invoice. It must clear everything except the newest (more than Rs. ${numberFormat(sumButLast / 100)}). Deselect the older extras or increase the amount.` };
    }
    const rows = [];
    for (let i = 0; i < n; i++) {
        const allocC = (i < n - 1) ? bals[i] : (amt - sumButLast);
        rows.push({
            item: sortedItems[i],
            alloc: allocC / 100,
            fully: allocC >= bals[i],
            remainingAfter: Math.max(0, bals[i] - allocC) / 100,
        });
    }
    return { ok: true, rows };
}

function openBulkPaymentModal() {
    const items = getShopSelectedItems();
    if (!items.length) { showToast('Select at least one invoice.', 'error'); return; }
    if (items.length < 2) {
        // A single invoice is just a normal payment — steer the user there so
        // the "bulk" concept stays meaningful, but don't block them.
        if (!confirm('Only one invoice is selected. Bulk payment is meant for two or more. Continue anyway?')) return;
    }
    bulkPayItems = sortShopItemsFifo(items);
    const totalBal = bulkPayItems.reduce((s, it) => s + (parseFloat(it.balance_remaining) || 0), 0);

    let overlay = document.getElementById('bulkPayOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'bulkPayOverlay';
    overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10001; display:flex; align-items:center; justify-content:center; padding:20px;';
    overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };

    const today = new Date().toISOString().slice(0, 10);
    const inputStyle = 'margin-top:4px; width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:8px;';
    const labelStyle = 'font-size:13px; font-weight:600; color:#374151;';

    overlay.innerHTML = `
      <div style="background:#fff; border-radius:12px; padding:24px; max-width:520px; width:100%; max-height:92vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <h3 style="margin:0; font-size:1.1rem;">Bulk payment — ${escapeHtml(shopSelectedCustomer || '')}</h3>
          <button onclick="document.getElementById('bulkPayOverlay').remove()" style="border:none; background:#F3F4F6; border-radius:8px; padding:4px 10px; cursor:pointer; font-size:18px;">×</button>
        </div>
        <div style="font-size:13px; color:#6B7280; margin-bottom:14px;">${bulkPayItems.length} invoice(s) · total balance <b style="color:#B91C1C;">Rs. ${numberFormat(totalBal)}</b>. Applied oldest-first; any shortfall stays on the newest invoice.</div>
        <div style="display:flex; flex-direction:column; gap:12px;">
          <label style="${labelStyle}">Total amount received (Rs.)
            <input id="bulkPayAmount" type="number" step="0.01" min="0.01" value="${totalBal.toFixed(2)}" oninput="renderBulkAllocationPreview()" style="${inputStyle}">
          </label>
          <label style="${labelStyle}">Payment date
            <input id="bulkPayDate" type="date" value="${today}" max="${today}" style="${inputStyle}">
          </label>
          <div style="${labelStyle}">🏦 Receiving bank <span style="color:#DC2626;">*</span>
            <div id="shopBulkBankChips" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;"></div>
          </div>
          <label style="${labelStyle}">Reference (optional)
            <input id="bulkPayRef" type="text" maxlength="255" style="${inputStyle}">
          </label>
          <label style="${labelStyle}">Notes (optional)
            <textarea id="bulkPayNotes" rows="2" maxlength="1000" style="${inputStyle}"></textarea>
          </label>
        </div>
        <div style="margin-top:14px;">
          <div style="font-size:12px; font-weight:700; color:#374151; margin-bottom:6px;">Allocation preview</div>
          <div id="bulkPayPreview" style="border:1px solid #E5E7EB; border-radius:8px; overflow:hidden;"></div>
        </div>
        <div id="bulkPayError" style="color:#DC2626; font-size:13px; margin-top:10px; display:none;"></div>
        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
          <button onclick="document.getElementById('bulkPayOverlay').remove()" style="padding:8px 16px; border:1px solid #D1D5DB; background:#fff; border-radius:8px; cursor:pointer;">Cancel</button>
          <button id="bulkPaySubmit" onclick="submitBulkPayment()" style="padding:8px 16px; border:none; background:#10B981; color:#fff; border-radius:8px; cursor:pointer; font-weight:600;">Record bulk payment</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    window.shopBulkBank = '';
    renderReceivingBankChips('shopBulkBankChips', 'shopBulkBank');
    renderBulkAllocationPreview();
}

function renderBulkAllocationPreview() {
    const box = document.getElementById('bulkPayPreview');
    const submitBtn = document.getElementById('bulkPaySubmit');
    if (!box) return;
    const amount = parseFloat(document.getElementById('bulkPayAmount').value) || 0;
    const res = computeBulkAllocation(bulkPayItems, amount);
    if (!res.ok) {
        box.innerHTML = `<div style="padding:12px; background:#FEF2F2; color:#B91C1C; font-size:13px;">${escapeHtml(res.error)}</div>`;
        if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; submitBtn.style.cursor = 'not-allowed'; }
        return;
    }
    let rows = '';
    res.rows.forEach(r => {
        const tag = r.fully
            ? '<span style="color:#059669; font-weight:700;">Paid in full</span>'
            : `<span style="color:#B45309; font-weight:700;">Rs. ${numberFormat(r.remainingAfter)} left</span>`;
        rows += `<div style="display:flex; justify-content:space-between; gap:8px; padding:8px 12px; border-bottom:1px solid #F3F4F6; font-size:13px;">
            <span>${escapeHtml(r.item.number)} <span style="color:#9CA3AF;">· ${formatDate(r.item.date)}</span></span>
            <span>Rs. ${numberFormat(r.alloc)} · ${tag}</span>
        </div>`;
    });
    box.innerHTML = rows;
    if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; submitBtn.style.cursor = 'pointer'; }
}

async function submitBulkPayment() {
    const errBox = document.getElementById('bulkPayError');
    const btn = document.getElementById('bulkPaySubmit');
    errBox.style.display = 'none';
    const amount = parseFloat(document.getElementById('bulkPayAmount').value);
    const bankId = window.shopBulkBank || '';
    if (!amount || amount <= 0) { errBox.textContent = 'Enter a valid amount.'; errBox.style.display = 'block'; return; }
    if (!bankId) { errBox.textContent = 'Select which bank received this payment.'; errBox.style.display = 'block'; return; }
    // Guard with the same rule the server enforces (server is authoritative).
    const check = computeBulkAllocation(bulkPayItems, amount);
    if (!check.ok) { errBox.textContent = check.error; errBox.style.display = 'block'; return; }

    const payload = {
        order_ids: bulkPayItems.map(it => parseInt(it.order_id)),
        amount: amount,
        receiving_account_id: bankId,
        payment_date: document.getElementById('bulkPayDate').value || null,
        reference: document.getElementById('bulkPayRef').value || null,
        notes: document.getElementById('bulkPayNotes').value || null,
    };
    btn.disabled = true;
    btn.textContent = 'Saving…';
    try {
        const res = await fetch('/orders/bulk-shop-payments', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('bulkPayOverlay').remove();
            clearShopSelection();
            showToast(data.message || 'Bulk payment recorded.', 'success');
            loadData();
            refreshStats();
        } else {
            errBox.textContent = typeof data.message === 'string' ? data.message : 'Could not record bulk payment.';
            errBox.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Record bulk payment';
        }
    } catch (e) {
        errBox.textContent = 'Network error. Please try again.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Record bulk payment';
    }
}

// Jun-2026 — Payment-proof badges (WhatsApp screenshot / bank email).
// p.status: none | proof_received | bank_confirmed | verified | amount_mismatch
// p.is_combined: this invoice was paid as part of ONE transfer covering
// p.combined_count invoices (a combined / bulk payment).
// Small bank chip on a row: SOLID when the receiving bank is confirmed (already
// tagged on the ledger), a lighter DASHED "detected" chip when it's only been
// auto-detected from the customer's proof and not yet approved.
function renderBankBadge(item) {
    if (item && item.receiving_account_short) {
        const c = item.receiving_account_color || '#3B82F6';
        return `<span title="Received in ${escapeHtml(item.receiving_account_short)}"
            style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:${c}20; color:${c}; border:1px solid ${c}40; margin-left:6px; white-space:nowrap;">🏦 ${escapeHtml(item.receiving_account_short)}</span>`;
    }
    const p = item && item.payment_proof;
    if (p && p.suggested_receiving_account_short) {
        const c = p.suggested_receiving_account_color || '#3B82F6';
        return `<span title="Detected from proof — confirm when you approve"
            style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:${c}12; color:${c}; border:1px dashed ${c}; margin-left:6px; white-space:nowrap;">🏦 ${escapeHtml(p.suggested_receiving_account_short)} · detected</span>`;
    }
    return '';
}

function renderProofBadges(item) {
    const p = item && item.payment_proof;
    if (!p || p.status === 'none' || !item.order_id) return '';
    const waIcon = p.has_whatsapp ? '📷' : '';
    const mailIcon = p.has_email ? '✉️' : '';
    const combinedPrefix = p.is_combined ? '🧩 ' : '';
    const combinedSuffix = (p.is_combined && p.combined_count) ? ` · combined of ${p.combined_count}` : '';
    const combinedTitle = (p.is_combined && p.combined_count)
        ? ` — one transfer covering ${p.combined_count} invoices` : '';
    let badges = `<span class="proof-badge" onclick="openProofPanel(${item.order_id})"
        title="${escapeHtml(p.label)}${combinedTitle} — click to view the proof"
        style="cursor:pointer; display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:${p.color}1A; color:${p.color}; border:1px solid ${p.color}55; margin-left:6px; white-space:nowrap;">
        ${combinedPrefix}${waIcon}${mailIcon} ${escapeHtml(p.label)}${combinedSuffix}</span>`;
    // 💸 "Discount needed" marker — a matched proof still short by a small amount.
    if (p.needs_discount) {
        const shortTxt = p.short_amount ? ` · Rs ${numberFormat(p.short_amount)}` : '';
        badges += `<span onclick="openProofPanel(${item.order_id})"
            title="This payment is short${p.short_amount ? ' by Rs ' + numberFormat(p.short_amount) : ''} — click to apply a balancing discount"
            style="cursor:pointer; display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; margin-left:6px; white-space:nowrap;">💸 discount needed${shortTxt}</span>`;
    }
    return badges;
}

// Fetch + show the screenshot / parsed email behind a proof badge.
async function openProofPanel(orderId) {
    let overlay = document.getElementById('proofPanelOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'proofPanelOverlay';
    overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px;';
    overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
    overlay.innerHTML = '<div style="background:#fff; border-radius:12px; padding:24px; max-width:560px; width:100%; max-height:88vh; overflow:auto;"><div style="text-align:center; color:#9CA3AF;">Loading payment proof…</div></div>';
    document.body.appendChild(overlay);

    try {
        const res = await fetch(`/admin/payments/order/${orderId}/signals`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        const data = await res.json();
        overlay.querySelector('div').innerHTML = buildProofPanelHtml(data);
    } catch (e) {
        overlay.querySelector('div').innerHTML = '<div style="color:#DC2626; text-align:center;">Could not load payment proof.</div>';
    }
}

// Dismiss a wrongly-detected combined payment: clear the bulk links + refresh.
async function uncombinePayment(orderId) {
    if (!confirm('Mark this as NOT a combined payment? The "combined" badges on these invoices will clear.')) return;
    try {
        const res = await fetch(`/admin/payments/order/${orderId}/uncombine`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            }
        });
        const data = await res.json();
        const overlay = document.getElementById('proofPanelOverlay');
        if (overlay) overlay.remove();
        if (data && data.success) {
            loadData(); // refresh so the combined badges clear
        } else {
            alert('Could not update the payment.');
        }
    } catch (e) {
        alert('Could not update the payment.');
    }
}

// Phase 2 — apply / undo the one-time balancing discount, then reload the panel + list.
async function applyBalanceDiscount(orderId) {
    if (!confirm('Apply a one-time discount to settle the small difference on this payment?')) return;
    await balanceDiscountRequest(`/admin/payments/order/${orderId}/balance-discount`, orderId, 'Could not apply the discount.');
}
async function removeBalanceDiscount(orderId) {
    if (!confirm('Remove the balancing discount (restores the invoice total)?')) return;
    await balanceDiscountRequest(`/admin/payments/order/${orderId}/balance-discount/remove`, orderId, 'Could not remove the discount.');
}
async function balanceDiscountRequest(url, orderId, errMsg) {
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        });
        const data = await res.json();
        if (data && data.success) {
            const overlay = document.getElementById('proofPanelOverlay');
            if (overlay) overlay.remove();
            openProofPanel(orderId); // reopen with the updated state
            loadData();              // refresh the list badges/amounts
        } else {
            alert((data && data.message) || errMsg);
        }
    } catch (e) {
        alert(errMsg);
    }
}

function buildProofPanelHtml(data) {
    const proof = data.proof || {};
    let html = `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3 style="margin:0; font-size:1.1rem;">Payment proof — ${escapeHtml(data.order_number || ('#' + data.order_id))}</h3>
        <button onclick="document.getElementById('proofPanelOverlay').remove()" style="border:none; background:#F3F4F6; border-radius:8px; padding:4px 10px; cursor:pointer; font-size:18px;">×</button>
    </div>`;
    html += `<div style="display:inline-block; padding:3px 10px; border-radius:10px; font-size:12px; font-weight:600; background:${proof.color}1A; color:${proof.color}; border:1px solid ${proof.color}55; margin-bottom:14px;">${escapeHtml(proof.label || '')}</div>`;

    // 🧩 Combined payment — one transfer covering several invoices. Show what it
    // covers and offer a one-click dismissal if the auto-match got it wrong.
    if (data.combined && data.combined.invoices && data.combined.invoices.length >= 2) {
        const c = data.combined;
        const rows = c.invoices.map(inv =>
            `<tr><td style="padding:2px 0;">${escapeHtml(inv.order_number || ('#' + inv.order_id))}</td>
                 <td style="text-align:right;">Rs. ${numberFormat(inv.balance)}</td></tr>`).join('');
        const totalsMatch = Math.abs((c.amount || 0) - (c.open_total || 0)) <= 1;
        html += `<div style="border:1px solid #C7D2FE; background:#EEF2FF; border-radius:10px; padding:12px; margin-bottom:14px;">
            <div style="font-weight:700; color:#4338CA; margin-bottom:6px;">🧩 Combined payment — covers ${c.invoices.length} invoices</div>
            <table style="width:100%; font-size:13px; border-collapse:collapse;">${rows}
                <tr><td style="padding-top:6px; font-weight:600; border-top:1px solid #C7D2FE;">Total of these invoices</td><td style="text-align:right; padding-top:6px; font-weight:600; border-top:1px solid #C7D2FE;">Rs. ${numberFormat(c.open_total)}</td></tr>
                <tr><td style="font-weight:600; color:${totalsMatch ? '#16A34A' : '#DC2626'};">Amount transferred</td><td style="text-align:right; font-weight:600; color:${totalsMatch ? '#16A34A' : '#DC2626'};">Rs. ${numberFormat(c.amount)}${totalsMatch ? ' ✓' : ''}</td></tr>
            </table>
            ${(data.proof && data.proof.is_combined) ? `<button onclick="uncombinePayment(${data.order_id})" style="margin-top:10px; background:#FEF2F2; color:#B91C1C; border:1px solid #FECACA; border-radius:8px; padding:6px 12px; font-size:12px; cursor:pointer;">✕ Not a combined payment</button>` : ''}
        </div>`;
    }

    // 💸 One-time balancing discount — when the transfer is a little SHORT of the
    // invoice(s), an approver can write off the small gap so it settles clean.
    const ba = data.balance_adjustment || {};
    if (ba.eligible && ba.short_amount > 0) {
        html += `<div style="border:1px solid #FDE68A; background:#FFFBEB; border-radius:10px; padding:12px; margin-bottom:14px;">
            <div style="font-weight:700; color:#92400E; margin-bottom:4px;">Payment is Rs ${numberFormat(ba.short_amount)} short</div>
            <div style="font-size:12px; color:#92400E; margin-bottom:8px;">Apply a one-time discount to ${escapeHtml(ba.target_order_number || 'the largest invoice')} so the payment settles exactly.</div>
            <button onclick="applyBalanceDiscount(${data.order_id})" style="background:#059669; color:#fff; border:none; border-radius:8px; padding:7px 14px; font-size:13px; font-weight:600; cursor:pointer;">Apply Rs ${numberFormat(ba.short_amount)} discount</button>
        </div>`;
    } else if (ba.already_applied) {
        html += `<div style="border:1px solid #A7F3D0; background:#ECFDF5; border-radius:10px; padding:12px; margin-bottom:14px;">
            <div style="font-weight:700; color:#065F46; margin-bottom:6px;">✓ Balancing discount applied — Rs ${numberFormat(ba.applied_amount || 0)}</div>
            <button onclick="removeBalanceDiscount(${data.order_id})" style="background:#FEF2F2; color:#B91C1C; border:1px solid #FECACA; border-radius:8px; padding:6px 12px; font-size:12px; cursor:pointer;">Undo discount</button>
        </div>`;
    }

    if (!data.signals || data.signals.length === 0) {
        html += '<div style="color:#9CA3AF;">No signal details available.</div>';
        return html;
    }

    data.signals.forEach(s => {
        const isWa = s.source === 'whatsapp';
        const am = s.agreement || {};
        const amountColor = am.amount_match === true ? '#16A34A' : (am.amount_match === false ? '#DC2626' : '#9CA3AF');
        // Amount note: show the signed gap even when it matches within tolerance,
        // so the approver sees how much short/over the transfer is.
        let amountNote = '';
        if (am.amount_match === true) {
            const d = am.difference;
            amountNote = (d && Math.abs(d) >= 1)
                ? (d < 0 ? ` ✓ matches (Rs ${numberFormat(Math.abs(d))} short)` : ` ✓ matches (Rs ${numberFormat(d)} over)`)
                : ' ✓ matches';
        } else if (am.amount_match === false) {
            amountNote = ' (differs from balance Rs. ' + numberFormat(am.expected) + ')';
        }
        html += `<div style="border:1px solid #E5E7EB; border-radius:10px; padding:14px; margin-bottom:12px;">
            <div style="font-weight:600; margin-bottom:8px;">${isWa ? '📷 Customer WhatsApp screenshot' : '✉️ Bank confirmation email'}
                <span style="font-weight:400; color:#9CA3AF; font-size:12px; margin-left:6px;">${escapeHtml(s.received_at || '')}</span></div>`;

        if (isWa && s.image_url) {
            html += `<a href="${s.image_url}" target="_blank"><img src="${s.image_url}" style="max-width:100%; border-radius:8px; border:1px solid #E5E7EB; margin-bottom:10px;"/></a>`;
        }

        html += `<table style="width:100%; font-size:13px; border-collapse:collapse;">
            <tr><td style="color:#6B7280; padding:2px 0; width:140px;">Amount read</td><td style="font-weight:600; color:${amountColor};">Rs. ${s.amount != null ? numberFormat(s.amount) : '—'}${amountNote}</td></tr>
            <tr><td style="color:#6B7280; padding:2px 0;">Reference</td><td>${escapeHtml(s.reference || '—')}</td></tr>
            <tr><td style="color:#6B7280; padding:2px 0;">Sender name</td><td>${escapeHtml(s.sender_name || '—')}</td></tr>
            <tr><td style="color:#6B7280; padding:2px 0;">Sender bank</td><td>${escapeHtml(s.sender_bank || '—')}${s.sender_account ? ' · ' + escapeHtml(s.sender_account) : ''}</td></tr>
            ${s.to_account ? `<tr><td style="color:#6B7280; padding:2px 0;">To (our bank)</td><td>${escapeHtml(s.to_account)}</td></tr>` : ''}
            <tr><td style="color:#6B7280; padding:2px 0;">Txn time</td><td>${escapeHtml(s.txn_datetime || '—')}</td></tr>
            ${s.paired ? `<tr><td style="color:#6B7280; padding:2px 0;">Corroboration</td><td style="color:#16A34A;">✓ matched by the other source too</td></tr>` : ''}
        </table>`;

        if (!isWa && s.email_body) {
            html += `<details style="margin-top:8px;"><summary style="cursor:pointer; color:#2563EB; font-size:12px;">Show raw email</summary><pre style="white-space:pre-wrap; font-size:11px; color:#374151; background:#F9FAFB; padding:8px; border-radius:6px; margin-top:6px;">${escapeHtml(s.email_body)}</pre></details>`;
        }
        html += `</div>`;
    });

    html += `<p style="font-size:11px; color:#9CA3AF; margin:6px 0 0;">This is read-only evidence to help you decide. Approving still happens through the normal Approve button.</p>`;
    return html;
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
    // Drop any bulk-assigned bank so it can't bleed into the next selection.
    window.bulkAssignBank = '';
    if (typeof updateBulkAssignBankBtn === 'function') updateBulkAssignBankBtn();
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
    const amountEl = document.getElementById('selectedAmount');
    
    if (selectedItems.size > 0 && currentTab !== 'approved') {
        bar.style.display = 'flex';
        count.textContent = selectedItems.size;
        // ⭐ Compute total amount of selected items
        const selectedTotal = allItems
            .filter(item => selectedItems.has(`${item.type}_${item.id}`))
            .reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);
        amountEl.textContent = numberFormat(selectedTotal);
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

    // ⭐ Render bank chips: pre-select the bank already tagged on the ledger, else
    // the bank auto-detected from the customer's payment proof.
    const proof = item.payment_proof || {};
    const detectedBankId = proof.suggested_receiving_account_id || '';
    const preselectBankId = item.receiving_account_id || detectedBankId || '';
    renderBankChips(preselectBankId);

    // Bank hint: (a) green "detected from proof" when we auto-filled it, or
    // (b) amber "unrecognized account" when the proof shows an account that
    // isn't one of our banks — with a Taimur-only quick-add link.
    const hint = document.getElementById('bankDetectedHint');
    if (hint) {
        if (!item.receiving_account_id && detectedBankId && proof.suggested_receiving_account_short) {
            hint.innerHTML = `✓ detected from proof — ${escapeHtml(proof.suggested_receiving_account_short)}`;
            hint.style.color = '#16A34A';
            hint.style.display = 'block';
        } else if (!preselectBankId && proof.detected_last4) {
            const addLink = canRevertApprovals
                ? ` <a href="#" onclick="openBankAccountsModal(); return false;" style="color:#B45309; text-decoration:underline; font-weight:700;">Add bank</a>`
                : '';
            hint.innerHTML = `⚠️ Proof shows account ••${escapeHtml(proof.detected_last4)} — not in your banks.${addLink}`;
            hint.style.color = '#B45309';
            hint.style.display = 'block';
        } else {
            hint.innerHTML = '';
            hint.style.display = 'none';
        }
    }

    // ⭐ Show the already-received proof screenshot inline (read-only). The
    // upload field below still lets the approver attach one manually.
    const proofBox = document.getElementById('receivedProofBox');
    const proofImg = document.getElementById('receivedProofImg');
    const proofLink = document.getElementById('receivedProofLink');
    if (proofBox && proofImg && proofLink) {
        if (proof.proof_image_url) {
            proofImg.src = proof.proof_image_url;
            proofLink.href = proof.proof_image_url;
            proofBox.style.display = 'block';
        } else {
            proofImg.src = '';
            proofBox.style.display = 'none';
        }
    }

    // Store item for confirmation
    window.pendingApprovalItem = item;

    const modal = document.getElementById('approvalModal');
    if (modal) {
        modal.style.display = 'flex';
        // Lock the page behind so scrolling stays inside the modal (the card
        // scrolls internally); reset the card's scroll to the top each open.
        document.body.style.overflow = 'hidden';
        const scrollArea = document.getElementById('approvalModalScroll');
        if (scrollArea) scrollArea.scrollTop = 0;
    }
}

function closeModal() {
    document.getElementById('approvalModal').style.display = 'none';
    document.body.style.overflow = '';
    window.pendingApprovalItem = null;
    window.selectedBankAccountId = '';
    clearProofImage();
    // Also reset the txn-reference input so stale text doesn't bleed into
    // the next approval the user opens.
    const txnRefInput = document.getElementById('transactionReferenceInput');
    if (txnRefInput) txnRefInput.value = '';
    // Reset the received-proof box + detected hint for the next approval.
    const proofBox = document.getElementById('receivedProofBox');
    if (proofBox) proofBox.style.display = 'none';
    const hint = document.getElementById('bankDetectedHint');
    if (hint) { hint.textContent = ''; hint.style.display = 'none'; }
}

function previewProofImage(input) {
    const preview = document.getElementById('proofImagePreview');
    const clearBtn = document.getElementById('clearProofBtn');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            clearBtn.style.display = 'inline-block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function clearProofImage() {
    const input = document.getElementById('proofImageInput');
    const preview = document.getElementById('proofImagePreview');
    const clearBtn = document.getElementById('clearProofBtn');
    if (input) input.value = '';
    if (preview) { preview.src = ''; preview.style.display = 'none'; }
    if (clearBtn) clearBtn.style.display = 'none';
}

// ⭐ Bank account selection
window.selectedBankAccountId = '';

function renderBankChips(preselectedId) {
    const container = document.getElementById('bankAccountChips');
    if (!container) return;
    
    let html = `<button type="button" class="bank-chip ${!preselectedId ? 'bank-chip-active' : ''}" data-bank-id="" onclick="selectBankAccount(this, '')" style="padding: 4px 12px; border-radius: 16px; border: 1px solid #CBD5E1; background: ${!preselectedId ? '#3B82F6' : '#F1F5F9'}; color: ${!preselectedId ? '#fff' : '#475569'}; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;">None</button>`;
    
    receivingAccounts.forEach(account => {
        const isActive = preselectedId == account.id;
        const color = account.color_hex || '#3B82F6';
        html += `<button type="button" class="bank-chip ${isActive ? 'bank-chip-active' : ''}" data-bank-id="${account.id}" onclick="selectBankAccount(this, ${account.id})" style="padding: 4px 12px; border-radius: 16px; border: 1px solid ${isActive ? color : '#CBD5E1'}; background: ${isActive ? color : '#F1F5F9'}; color: ${isActive ? '#fff' : '#475569'}; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;">${account.short_code || account.name}</button>`;
    });
    
    container.innerHTML = html;
    window.selectedBankAccountId = preselectedId || '';
}

function selectBankAccount(btn, bankId) {
    window.selectedBankAccountId = bankId;
    // Re-render all chips to update active state
    renderBankChips(bankId);
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
    
    console.log('=== doApprove ===');
    console.log('URL:', url);
    console.log('Approval type:', approvalType);
    
    try {
        // ⭐ Check if there's a proof image to upload
        const proofInput = document.getElementById('proofImageInput');
        const hasProofImage = proofInput && proofInput.files && proofInput.files.length > 0;
        
        let fetchOptions;
        const bankId = window.selectedBankAccountId || '';
        
        // ⭐ Grab the optional transaction reference once — reused for both
        // the FormData and JSON code paths.
        const txnRefInput = document.getElementById('transactionReferenceInput');
        const txnRef = (txnRefInput && txnRefInput.value) ? txnRefInput.value.trim() : '';

        if (hasProofImage) {
            // Use FormData for multipart upload
            const formData = new FormData();
            formData.append('approval_notes', 'Approved from Online Approvals web');
            if (approvalType !== 'l1_only') {
                formData.append('force_full_approval', '1');
            }
            if (bankId) {
                formData.append('receiving_account_id', bankId);
            }
            if (txnRef) {
                formData.append('transaction_reference', txnRef);
            }
            formData.append('proof_image', proofInput.files[0]);
            
            fetchOptions = {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: formData
            };
        } else {
            // Regular JSON request (no image)
            const body = approvalType === 'l1_only'
                ? { approval_notes: 'Approved from Online Approvals web' }
                : { approval_notes: 'Approved from Online Approvals web', force_full_approval: true };
            if (bankId) {
                body.receiving_account_id = parseInt(bankId);
            }
            if (txnRef) {
                body.transaction_reference = txnRef;
            }
            
            fetchOptions = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(body)
            };
        }
        
        const response = await fetch(url, fetchOptions);
        
        console.log('Response status:', response.status);
        const data = await response.json();
        console.log('Response data:', data);
        
        if (data.success) {
            showToast(data.message || 'Approved successfully!', 'success');
            clearProofImage(); // ⭐ Reset image after success
            // Reset txn-reference input too so the next approval starts clean.
            const _txnRefInput = document.getElementById('transactionReferenceInput');
            if (_txnRefInput) _txnRefInput.value = '';
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
// REVERT TO PENDING (Taimur-only, individual)
// =============================================
// Sends an accidentally-approved online invoice back to the pending queue.
// Always confirms first; operates on ONE transaction at a time. Balances are
// reversed server-side using the exact inverse of approval.
async function revertToPending(ledgerId, label, amount) {
    if (!canRevertApprovals) {
        showToast('You do not have permission to revert approvals.', 'error');
        return;
    }

    const amountStr = amount ? `Rs. ${numberFormat(amount)}` : 'this payment';
    const confirmed = confirm(
        `Revert ${label} (${amountStr}) back to PENDING approval?\n\n` +
        `• The approval will be undone and it returns to the L1 approval queue.\n` +
        `• Account balances will be reversed exactly.\n` +
        `• This affects only this single transaction.\n\n` +
        `Continue?`
    );
    if (!confirmed) return;

    try {
        const response = await fetch(`/finance/ledger/${ledgerId}/revert-to-pending`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ reason: 'Reverted from Online Approvals web' })
        });

        const data = await response.json();

        if (response.ok && data.success) {
            showToast(data.message || 'Reverted to pending.', 'success');
            loadData();      // Refresh current tab (item leaves the Approved list)
            refreshStats();  // Refresh counts (L1 +1, Approved -1)
        } else {
            showToast(data.message || 'Failed to revert.', 'error');
        }
    } catch (error) {
        console.error('Revert error:', error);
        showToast('Error reverting. Please try again.', 'error');
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
        document.body.style.overflow = 'hidden'; // lock page scroll behind the modal
    }
}

function closeBulkModal() {
    document.getElementById('bulkApprovalModal').style.display = 'none';
    document.body.style.overflow = '';
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

// An item's effective receiving bank: the bank already tagged on the ledger,
// else the bank auto-detected from the proof. Null when neither is known.
function itemBankId(item) {
    if (item && item.receiving_account_id) return item.receiving_account_id;
    const p = item && item.payment_proof;
    if (p && p.suggested_receiving_account_id) return p.suggested_receiving_account_id;
    return null;
}

// The bank we'll actually use for an item in a bulk approve: its own detected /
// tagged bank if any, otherwise the manager's "assign bank" choice (for the
// no-proof-auto-detected cases). Null → still skipped.
function effectiveBulkBankId(item) {
    return itemBankId(item) || window.bulkAssignBank || null;
}

// ── Bulk "assign bank" overlay ─────────────────────────────────────────────
window.bulkAssignBank = '';
function openBulkAssignBank() {
    document.getElementById('bulkAssignBankOverlay').style.display = 'flex';
    renderReceivingBankChips('bulkAssignBankChips', 'bulkAssignBank');
}
function closeBulkAssignBank() {
    document.getElementById('bulkAssignBankOverlay').style.display = 'none';
    updateBulkAssignBankBtn();
}
function clearBulkAssignBank() {
    window.bulkAssignBank = '';
    renderReceivingBankChips('bulkAssignBankChips', 'bulkAssignBank');
    updateBulkAssignBankBtn();
}
function updateBulkAssignBankBtn() {
    const btn = document.getElementById('bulkAssignBankBtn');
    if (!btn) return;
    if (window.bulkAssignBank) {
        const acc = (receivingAccounts || []).find(a => String(a.id) === String(window.bulkAssignBank));
        btn.textContent = '🏦 ' + (acc ? (acc.short_code || acc.name) : 'bank set');
        btn.style.background = '#0369A1';
        btn.style.color = '#fff';
    } else {
        btn.textContent = '🏦 Assign bank';
        btn.style.background = '#F0F9FF';
        btn.style.color = '#0369A1';
    }
}

// Execute bulk approval with progress tracking
async function doBulkApprove(items, approvalType) {
    console.log('=== doBulkApprove started ===');
    console.log('Items count:', items.length);
    console.log('Approval type:', approvalType);

    // Bank is mandatory for online approvals. Each item uses its own detected /
    // tagged bank; items with none use the manager's "Assign bank" choice (if
    // set). Only items that STILL have no bank are skipped.
    const skipped = [];
    const approvable = items.filter(item => {
        if (effectiveBulkBankId(item)) return true;
        skipped.push(item);
        return false;
    });

    if (approvable.length === 0) {
        showToast(`Nothing auto-approvable — ${skipped.length} item(s) need a bank. Use 🏦 Assign bank, or open each and pick one.`, 'error');
        return;
    }

    const total = approvable.length;
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
    
    for (let i = 0; i < approvable.length; i++) {
        const item = approvable[i];
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

            // Explicitly tag the bank for this item — its own, or the bulk
            // "assign bank" fallback for no-detected-bank items (mandatory online).
            const bulkBankId = effectiveBulkBankId(item);
            if (bulkBankId) {
                body.receiving_account_id = parseInt(bulkBankId);
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
    if (skipped.length > 0) {
        resultMessage += ` | ⏭️ Skipped ${skipped.length} (no bank — approve individually)`;
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
    window.bulkAssignBank = '';
    if (typeof updateBulkAssignBankBtn === 'function') updateBulkAssignBankBtn();
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
    // These are calendar dates (invoice date, approved date, etc.). Build the
    // date from its Y-M-D parts as a LOCAL date so it renders as the same day
    // everywhere. Using `new Date("2026-07-07T00:00:00Z")` would apply a
    // timezone shift and could roll the day backward on machines behind UTC+5
    // (e.g. an approval stamped in PKT showing the previous day on a UTC+3 box).
    const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
    const date = m
        ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
        : new Date(dateStr);
    if (isNaN(date.getTime())) return 'N/A';
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg font-semibold ${type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'}`;
    toast.style.zIndex = '99999';
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 4000);
}

// ⭐ Format phone number with Pakistan country code (+92) for WhatsApp
function formatPhoneForWhatsApp(phone) {
    if (!phone) return '';
    let cleaned = phone.replace(/\D/g, '');
    if (cleaned.length > 10) cleaned = cleaned.slice(-10);
    if (cleaned.length < 10) return '';
    return '92' + cleaned;
}

// ⭐ Per-customer report modal
function openCustomerReport(customerName, itemsOverride) {
    const group = groupedItems.find(g => g.customer === customerName);
    if (!group || !group.items || group.items.length === 0) {
        showToast('No items found for this customer', 'error');
        return;
    }

    // When a shop multi-select is active, report only the chosen invoices;
    // otherwise report the whole group (the 📋 header button).
    const items = (Array.isArray(itemsOverride) && itemsOverride.length) ? itemsOverride : group.items;
    const totalAmount = items.reduce((sum, i) => sum + (parseFloat(i.amount) || 0), 0);
    const customerPhone = group.customer_phone;
    const waNumber = customerPhone ? formatPhoneForWhatsApp(customerPhone) : '';
    const dateStr = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    
    // Build report table rows
    let tableRows = '';
    items.forEach((item, index) => {
        tableRows += `
            <tr style="background: ${index % 2 === 0 ? '#FAFAFA' : '#fff'};">
                <td style="padding: 10px 12px; font-weight: 600; color: #3B82F6; border-bottom: 1px solid #E5E7EB;">${item.number}</td>
                <td style="padding: 10px 12px; color: #6B7280; border-bottom: 1px solid #E5E7EB; text-align: center;">${formatDate(item.date)}</td>
                <td style="padding: 10px 12px; font-weight: 700; color: #059669; border-bottom: 1px solid #E5E7EB; text-align: right;">Rs. ${numberFormat(item.amount)}</td>
            </tr>`;
    });
    
    // Build report text for copy/WhatsApp
    let reportText = `📋 PENDING ONLINE INVOICES\n━━━━━━━━━━━━━━━━━━━━━━━━━\n`;
    reportText += `Customer: ${customerName}\n`;
    reportText += `Generated: ${dateStr}\n━━━━━━━━━━━━━━━━━━━━━━━━━\n\n`;
    items.forEach((item, index) => {
        reportText += `${index + 1}. ${item.number}\n   📅 ${formatDate(item.date)}\n   💰 Rs. ${numberFormat(item.amount)}\n\n`;
    });
    reportText += `━━━━━━━━━━━━━━━━━━━━━━━━━\n📊 SUMMARY\nTotal Invoices: ${items.length}\nTotal Amount: Rs. ${numberFormat(totalAmount)}\n━━━━━━━━━━━━━━━━━━━━━━━━━`;
    
    // Store for copy/share
    window.currentReportText = reportText;
    
    const modal = document.getElementById('reportModal');
    document.getElementById('reportModalTitle').textContent = `📋 Report: ${customerName}`;
    document.getElementById('reportModalBody').innerHTML = `
        <div style="margin-bottom: 16px; display: flex; gap: 12px;">
            <div style="flex: 1; background: #EFF6FF; padding: 12px; border-radius: 8px; text-align: center;">
                <div style="font-size: 24px; font-weight: 800; color: #1E40AF;">${items.length}</div>
                <div style="font-size: 11px; color: #3B82F6;">Invoices</div>
            </div>
            <div style="flex: 1; background: #ECFDF5; padding: 12px; border-radius: 8px; text-align: center;">
                <div style="font-size: 18px; font-weight: 800; color: #059669;">Rs. ${numberFormat(totalAmount)}</div>
                <div style="font-size: 11px; color: #10B981;">Total Amount</div>
            </div>
        </div>
        <div class="report-scroll" style="max-height: 350px; overflow-y: auto; border: 1px solid #E5E7EB; border-radius: 8px;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #F3F4F6;">
                        <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #6B7280;">Invoice #</th>
                        <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #6B7280;">Date</th>
                        <th style="padding: 10px 12px; text-align: right; font-size: 12px; font-weight: 700; color: #6B7280;">Amount</th>
                    </tr>
                </thead>
                <tbody>${tableRows}</tbody>
                <tfoot>
                    <tr style="background: #ECFDF5; border-top: 2px solid #10B981;">
                        <td colspan="2" style="padding: 12px; font-weight: 700; color: #065F46;">Total (${items.length} invoices)</td>
                        <td style="padding: 12px; font-weight: 800; color: #059669; text-align: right; font-size: 16px;">Rs. ${numberFormat(totalAmount)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="margin-top: 4px; text-align: right; font-size: 11px; color: #9CA3AF;">Generated ${dateStr}</div>
    `;
    
    // Show/hide WhatsApp button based on phone availability
    const waBtn = document.getElementById('reportWhatsAppBtn');
    if (waBtn) {
        if (waNumber) {
            waBtn.style.display = 'flex';
            waBtn.onclick = function() {
                const encodedText = encodeURIComponent(window.currentReportText);
                window.open('https://wa.me/' + waNumber + '?text=' + encodedText, '_blank');
            };
        } else {
            waBtn.style.display = 'none';
        }
    }
    
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // lock page scroll behind the modal
    }
}

function closeReportModal() {
    document.getElementById('reportModal').style.display = 'none';
    document.body.style.overflow = '';
    window.currentReportText = '';
}

function copyReportText() {
    if (!window.currentReportText) return;
    navigator.clipboard.writeText(window.currentReportText).then(() => {
        showToast('Report copied to clipboard!', 'success');
    }).catch(() => {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = window.currentReportText;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('Report copied to clipboard!', 'success');
    });
}

function printReport() {
    const content = document.getElementById('reportModalBody').innerHTML;
    const title = document.getElementById('reportModalTitle').textContent;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head><title>${title}</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 20px; }
            /* Unclamp the on-screen scroll box so the FULL report prints, not just the visible rows. */
            .report-scroll { max-height: none !important; overflow: visible !important; }
            table { border-collapse: collapse; }
            thead { display: table-header-group; } /* repeat the column headers on each printed page */
            tr { page-break-inside: avoid; }
            @page { margin: 12mm; }
        </style>
        </head>
        <body>
            <h2>${title}</h2>
            ${content}
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

// =====================================================
// ⭐ Payment Reminder via WhatsApp API
// =====================================================
let pendingReminderData = null;

function sendPaymentReminderForItem(ledgerId) {
    const item = allItems.find(i => i.id === ledgerId);
    if (!item) {
        showToast('Item not found', 'error');
        return;
    }
    showPaymentReminderConfirmation([item], item.requester, item.customer_phone);
}

function sendPaymentReminderForGroup(customerName) {
    const group = groupedItems.find(g => g.customer === customerName);
    if (!group || !group.items || group.items.length === 0) {
        showToast('No items found for this customer', 'error');
        return;
    }
    showPaymentReminderConfirmation(group.items, group.customer, group.customer_phone);
}

function sendPaymentReminder() {
    if (selectedItems.size === 0) {
        showToast('Please select invoices first', 'error');
        return;
    }

    const items = allItems.filter(item => selectedItems.has(`${item.type}_${item.id}`));
    if (items.length === 0) {
        showToast('No items found for selection', 'error');
        return;
    }

    const customers = [...new Set(items.map(i => i.requester))];
    if (customers.length > 1) {
        showToast('Selected invoices belong to different customers. Please select invoices for one customer only.', 'error');
        return;
    }

    const customerPhone = items[0].customer_phone;
    showPaymentReminderConfirmation(items, customers[0], customerPhone);
}

function showPaymentReminderConfirmation(items, customerName, customerPhone) {
    const waPhone = customerPhone ? formatPhoneForWhatsApp(customerPhone) : '';
    if (!waPhone) {
        showToast('No phone number available for this customer', 'error');
        return;
    }

    const totalAmount = items.reduce((sum, i) => sum + (parseFloat(i.amount) || 0), 0);
    const invoiceNumbers = items.map(i => i.order_number || i.number).filter(Boolean);
    const isSingle = items.length === 1;
    const templateName = isSingle ? 'payment_reminder_single' : 'payment_reminder_multiples';

    const nameForTemplate = isSingle
        ? customerName
        : (customerName || '').split(' ')[0] || customerName;

    const invoicesStr = invoiceNumbers.join(', ');
    const amountStr = numberFormat(totalAmount);

    pendingReminderData = {
        phone: waPhone,
        template_name: templateName,
        body_params: [nameForTemplate, invoicesStr, amountStr],
        customerName: customerName,
        orderId: isSingle ? items[0].order_id : null,
        isSingle: isSingle,
    };

    const messagePreview = isSingle
        ? `Assalamoalikum ${escapeHtml(nameForTemplate)},\n\nWe hope this message finds you well. We are writing to kindly remind you of an outstanding invoice ${escapeHtml(invoicesStr)} on your account. Please settle the payment of Rs ${amountStr} at your earliest convenience.\n\nIf you have already made the payment, kindly share a screenshot of the transaction so we can update our records accordingly.\n\nThank you for your understanding and cooperation`
        : `Dear ${escapeHtml(nameForTemplate)},\n\nThis is a payment reminder from Nizami Farms for invoice(s): ${escapeHtml(invoicesStr)}.\n\nTotal pending amount: PKR ${amountStr}.\n\nIf payment has already been made, please reply with the payment confirmation so we can update your account.\n\nThank you,\nNizami Farms`;

    let invoiceListHtml = '';
    items.forEach((item, idx) => {
        invoiceListHtml += `
            <tr style="background: ${idx % 2 === 0 ? '#FAFAFA' : '#fff'};">
                <td style="padding: 8px 12px; font-weight: 600; color: #3B82F6; border-bottom: 1px solid #E5E7EB;">${item.order_number || item.number}</td>
                <td style="padding: 8px 12px; font-weight: 600; color: #059669; border-bottom: 1px solid #E5E7EB; text-align: right;">Rs. ${numberFormat(item.amount)}</td>
            </tr>`;
    });

    // Jun-2026 — Warn if the customer has already sent payment proof for any
    // of these invoices, so staff don't nag a customer who already paid.
    const proofItems = items.filter(i => i.payment_proof && i.payment_proof.status && i.payment_proof.status !== 'none');
    let proofWarningHtml = '';
    if (proofItems.length > 0) {
        const labels = [...new Set(proofItems.map(i => i.payment_proof.label))].join(', ');
        proofWarningHtml = `
        <div style="background:#FEE2E2; border:1px solid #FCA5A5; border-radius:10px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:flex-start; gap:10px;">
            <span style="font-size:18px;">🛑</span>
            <div style="font-size:13px; color:#991B1B;">
                <strong>This customer may have already paid.</strong> Payment proof was received (${escapeHtml(labels)}).
                Please review the proof before sending a reminder — consider skipping this message.
            </div>
        </div>`;
    }

    document.getElementById('paymentReminderBody').innerHTML = `
        ${proofWarningHtml}
        <div style="background: #FEF3C7; border: 1px solid #FCD34D; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px;">
            <span style="font-size: 18px;">⚠️</span>
            <div style="font-size: 13px; color: #92400E;">
                <strong>Review carefully before sending.</strong> This will send an official WhatsApp payment reminder to the customer.
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
            <div style="background: #EFF6FF; padding: 12px; border-radius: 8px;">
                <div style="font-size: 11px; color: #3B82F6; font-weight: 600; margin-bottom: 4px;">CUSTOMER</div>
                <div style="font-size: 14px; font-weight: 700; color: #1E40AF;">${escapeHtml(customerName)}</div>
                <div style="font-size: 12px; color: #6B7280; margin-top: 2px;">${customerPhone || ''}</div>
            </div>
            <div style="background: #ECFDF5; padding: 12px; border-radius: 8px;">
                <div style="font-size: 11px; color: #059669; font-weight: 600; margin-bottom: 4px;">TOTAL AMOUNT</div>
                <div style="font-size: 18px; font-weight: 800; color: #059669;">Rs. ${amountStr}</div>
            </div>
        </div>
        <div style="margin-bottom: 12px;">
            <div style="font-size: 12px; font-weight: 600; color: #6B7280; margin-bottom: 6px;">TEMPLATE: <span style="color: #7C3AED;">${isSingle ? 'Single Invoice' : 'Multiple Invoices'}</span></div>
        </div>
        <div style="border: 1px solid #E5E7EB; border-radius: 8px; overflow: hidden; margin-bottom: 12px;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #F3F4F6;">
                        <th style="padding: 8px 12px; text-align: left; font-size: 11px; font-weight: 700; color: #6B7280;">Invoice #</th>
                        <th style="padding: 8px 12px; text-align: right; font-size: 11px; font-weight: 700; color: #6B7280;">Amount</th>
                    </tr>
                </thead>
                <tbody>${invoiceListHtml}</tbody>
                ${items.length > 1 ? `<tfoot>
                    <tr style="background: #ECFDF5; border-top: 2px solid #10B981;">
                        <td style="padding: 10px 12px; font-weight: 700; color: #065F46;">${items.length} invoices</td>
                        <td style="padding: 10px 12px; font-weight: 800; color: #059669; text-align: right;">Rs. ${amountStr}</td>
                    </tr>
                </tfoot>` : ''}
            </table>
        </div>
        <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 10px; padding: 16px; margin-bottom: 0;">
            <div style="font-size: 12px; font-weight: 700; color: #166534; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">💬 Message the customer will receive:</div>
            <div style="background: white; border-radius: 8px; padding: 14px; font-size: 13px; color: #1F2937; line-height: 1.6; white-space: pre-wrap; border: 1px solid #D1FAE5; max-height: 220px; overflow-y: auto;">${messagePreview}</div>
        </div>
    `;

    document.getElementById('paymentReminderModal').style.display = 'flex';
}

function closePaymentReminderModal() {
    document.getElementById('paymentReminderModal').style.display = 'none';
    pendingReminderData = null;
}

function captureReminderInvoiceImage(invoiceUrl, orderId) {
    return new Promise((resolve, reject) => {
        const iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:900px;height:1400px;border:none;opacity:0;';
        document.body.appendChild(iframe);
        iframe.src = invoiceUrl;
        iframe.onload = async function() {
            try {
                const addScript = (doc, src) => new Promise(r => { const s = doc.createElement('script'); s.src = src; s.onload = r; doc.head.appendChild(s); });
                const iDoc = iframe.contentDocument || iframe.contentWindow.document;
                await addScript(iDoc, 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
                const node = iDoc.querySelector('.invoice-container');
                if (!node) { iframe.remove(); reject(new Error('Invoice container not found')); return; }
                const canvas = await iframe.contentWindow.html2canvas(node, {scale: 2, useCORS: true, allowTaint: true});
                const dataUrl = canvas.toDataURL('image/png');
                iframe.remove();

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const uploadRes = await fetch('/messages/upload-invoice-image', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                    body: JSON.stringify({order_id: orderId, image_data: dataUrl})
                }).then(r => r.json());

                if (uploadRes.success) {
                    resolve(uploadRes.image_url);
                } else {
                    reject(new Error(uploadRes.message || 'Upload failed'));
                }
            } catch (err) { iframe.remove(); reject(err); }
        };
        iframe.onerror = function() { iframe.remove(); reject(new Error('Failed to load invoice')); };
    });
}

async function getOrGenerateInvoiceImage(orderId) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const imgRes = await fetch(`/messages/invoice-image/${orderId}`, {
        headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json());

    if (!imgRes.success) throw new Error(imgRes.message || 'Failed to check invoice image');
    if (!imgRes.needs_capture) return imgRes.image_url;

    return await captureReminderInvoiceImage(imgRes.invoice_url, orderId);
}

async function confirmSendPaymentReminder() {
    if (!pendingReminderData) return;

    const btn = document.getElementById('paymentReminderSendBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.style.opacity = '0.6';

    const payload = {
        phone: pendingReminderData.phone,
        template_name: pendingReminderData.template_name,
        body_params: pendingReminderData.body_params,
    };

    try {
        if (pendingReminderData.isSingle && pendingReminderData.orderId) {
            btn.innerHTML = '⏳ Preparing invoice...';
            // Capture the invoice PNG to disk (html2canvas can only run here),
            // then let the SERVER attach it to Meta by media_id via order_id.
            // Do NOT hand Meta a /public-storage link header — Meta's fetch of
            // that link 403s on this host (error 131053) and fails the whole
            // send. sendTemplate mirrors the mobile media_id auto-attach.
            await getOrGenerateInvoiceImage(pendingReminderData.orderId);
            payload.order_id = pendingReminderData.orderId;
        }

        btn.innerHTML = '⏳ Sending...';

        const sendPayload = async (force) => {
            const p = {...payload};
            if (force) p.force = true;
            const response = await fetch('/messages/send-template', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(p)
            });
            return {response, data: await response.json()};
        };

        const {response, data} = await sendPayload(false);

        if (data.success) {
            showToast(`Payment reminder sent to ${pendingReminderData.customerName}!`, 'success');
            closePaymentReminderModal();
        } else if (response.status === 409 && data.recently_sent) {
            if (confirm(`A template was recently sent to this customer (${data.last_template || 'unknown'}).\n\nSend anyway?`)) {
                const force = await sendPayload(true);
                if (force.data.success) {
                    showToast(`Payment reminder sent to ${pendingReminderData.customerName}!`, 'success');
                    closePaymentReminderModal();
                } else {
                    showToast(force.data.message || 'Failed to send reminder', 'error');
                }
            }
        } else {
            showToast(data.message || 'Failed to send reminder', 'error');
        }
    } catch (error) {
        console.error('Payment reminder error:', error);
        showToast(error.message || 'Failed to prepare/send reminder', 'error');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
        btn.style.opacity = '1';
    }
}

// =====================================================
// ⭐ Bank Accounts Management
// =====================================================
let bankAccountsData = [];

function openBankAccountsModal() {
    document.getElementById('bankAccountsModal').style.display = 'flex';
    loadBankAccounts();
}

function closeBankAccountsModal() {
    document.getElementById('bankAccountsModal').style.display = 'none';
}

async function loadBankAccounts() {
    const container = document.getElementById('bankAccountsList');
    container.innerHTML = '<div style="text-align: center; color: #9CA3AF; padding: 20px;">Loading...</div>';
    
    try {
        const response = await fetch('/online-receiving-accounts', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }
        });
        const result = await response.json();
        
        if (result.success) {
            bankAccountsData = result.data;
            renderBankAccountsList();
        }
    } catch (error) {
        container.innerHTML = '<div style="text-align: center; color: #EF4444; padding: 20px;">Failed to load accounts</div>';
    }
}

function renderBankAccountsList() {
    const container = document.getElementById('bankAccountsList');
    
    if (bankAccountsData.length === 0) {
        container.innerHTML = '<div style="text-align: center; color: #9CA3AF; padding: 20px;">No bank accounts configured yet. Add one above.</div>';
        return;
    }
    
    container.innerHTML = bankAccountsData.map(acc => `
        <div id="bank-row-${acc.id}" style="display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #F3F4F6; ${!acc.is_active ? 'opacity: 0.5;' : ''}">
            <div style="width: 14px; height: 14px; border-radius: 50%; background: ${escapeHtml(acc.color_hex || '#3B82F6')}; flex-shrink: 0; border: 2px solid ${escapeHtml(acc.color_hex || '#3B82F6')}40;"></div>
            <div style="flex: 1; min-width: 0;">
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    <span style="font-weight: 600; color: #1F2937; font-size: 14px;">${escapeHtml(acc.name)}</span>
                    <span style="background: #F3F4F6; color: #6B7280; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 600;">${escapeHtml(acc.short_code)}</span>
                    ${acc.account_last4 ? `<span style="background: #EFF6FF; color: #1D4ED8; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 600;">••${escapeHtml(acc.account_last4)}</span>` : ''}
                    ${!acc.is_active ? '<span style="background: #FEE2E2; color: #DC2626; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">INACTIVE</span>' : ''}
                </div>
                ${(acc.opening_balance && parseFloat(acc.opening_balance) !== 0) || acc.opening_balance_date ? `<div style="font-size: 11px; color: #9CA3AF; margin-top: 2px;">Opening: Rs. ${numberFormat(parseFloat(acc.opening_balance || 0))}${acc.opening_balance_date ? ' · ' + escapeHtml(String(acc.opening_balance_date).slice(0,10)) : ''}</div>` : ''}
            </div>
            <button onclick="editBankAccount(${acc.id})" style="background: #F3F4F6; color: #374151; padding: 4px 8px; border-radius: 4px; border: none; cursor: pointer; font-size: 11px;" title="Edit">✏️</button>
            <button onclick="toggleBankAccount(${acc.id})" style="background: ${acc.is_active ? '#FEF3C7' : '#D1FAE5'}; color: ${acc.is_active ? '#92400E' : '#065F46'}; padding: 4px 8px; border-radius: 4px; border: none; cursor: pointer; font-size: 11px;" title="${acc.is_active ? 'Deactivate' : 'Activate'}">${acc.is_active ? '⏸' : '▶'}</button>
            <button onclick="deleteBankAccount(${acc.id}, '${escapeHtml(acc.name)}')" style="background: #FEE2E2; color: #DC2626; padding: 4px 8px; border-radius: 4px; border: none; cursor: pointer; font-size: 11px;" title="Delete">🗑</button>
        </div>
    `).join('');
}

async function addBankAccount() {
    const name = document.getElementById('newBankName').value.trim();
    const shortCode = document.getElementById('newBankCode').value.trim();
    const last4 = (document.getElementById('newBankLast4').value || '').replace(/\D/g, '').slice(-4);
    const color = document.getElementById('newBankColor').value;

    if (!name) { alert('Please enter a bank name'); return; }
    if (!shortCode) { alert('Please enter a short code'); return; }

    try {
        const response = await fetch('/online-receiving-accounts', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ name, short_code: shortCode, account_last4: last4 || null, color_hex: color })
        });

        const result = await response.json();
        if (result.success) {
            document.getElementById('newBankName').value = '';
            document.getElementById('newBankCode').value = '';
            document.getElementById('newBankLast4').value = '';
            document.getElementById('newBankColor').value = '#3B82F6';
            loadBankAccounts();
            // Refresh the receivingAccounts used in approval modal
            refreshReceivingAccountsData();
        } else {
            alert(result.message || 'Failed to add account');
        }
    } catch (error) {
        alert('Failed to add account: ' + error.message);
    }
}

function editBankAccount(id) {
    const acc = bankAccountsData.find(a => a.id === id);
    if (!acc) return;
    
    const row = document.getElementById(`bank-row-${id}`);
    row.innerHTML = `
        <div style="width: 100%; display: flex; flex-direction: column; gap: 6px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <input type="text" id="edit-name-${id}" value="${escapeHtml(acc.name)}" placeholder="Name" style="flex: 1.5; padding: 6px 8px; border: 1px solid #3B82F6; border-radius: 4px; font-size: 13px;">
                <input type="text" id="edit-code-${id}" value="${escapeHtml(acc.short_code)}" placeholder="Code" style="flex: 1; padding: 6px 8px; border: 1px solid #3B82F6; border-radius: 4px; font-size: 13px;">
                <input type="text" id="edit-last4-${id}" value="${escapeHtml(acc.account_last4 || '')}" maxlength="4" inputmode="numeric" placeholder="Last4" style="width: 60px; padding: 6px 8px; border: 1px solid #3B82F6; border-radius: 4px; font-size: 13px;">
                <input type="color" id="edit-color-${id}" value="${acc.color_hex || '#3B82F6'}" style="width: 36px; height: 30px; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; padding: 1px;">
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <input type="number" step="0.01" id="edit-opening-${id}" value="${acc.opening_balance != null ? parseFloat(acc.opening_balance) : ''}" placeholder="Opening balance (Rs.)" style="flex: 1.2; padding: 6px 8px; border: 1px solid #CBD5E1; border-radius: 4px; font-size: 13px;">
                <input type="date" id="edit-opening-date-${id}" value="${acc.opening_balance_date ? String(acc.opening_balance_date).slice(0,10) : ''}" title="Balance counts from this date" style="flex: 1; padding: 6px 8px; border: 1px solid #CBD5E1; border-radius: 4px; font-size: 13px;">
                <button onclick="saveBankAccount(${id})" style="background: #16A34A; color: white; padding: 6px 10px; border-radius: 4px; border: none; cursor: pointer; font-size: 12px; font-weight: 600;">Save</button>
                <button onclick="renderBankAccountsList()" style="background: #E5E7EB; color: #374151; padding: 6px 10px; border-radius: 4px; border: none; cursor: pointer; font-size: 12px;">Cancel</button>
            </div>
        </div>
    `;
}

async function saveBankAccount(id) {
    const name = document.getElementById(`edit-name-${id}`).value.trim();
    const shortCode = document.getElementById(`edit-code-${id}`).value.trim();
    const last4 = (document.getElementById(`edit-last4-${id}`).value || '').replace(/\D/g, '').slice(-4);
    const color = document.getElementById(`edit-color-${id}`).value;
    const openingRaw = document.getElementById(`edit-opening-${id}`).value;
    const openingDate = document.getElementById(`edit-opening-date-${id}`).value;

    if (!name || !shortCode) { alert('Name and short code are required'); return; }

    const body = { name, short_code: shortCode, account_last4: last4 || null, color_hex: color };
    if (openingRaw !== '') body.opening_balance = parseFloat(openingRaw);
    if (openingDate) body.opening_balance_date = openingDate;

    try {
        const response = await fetch(`/online-receiving-accounts/${id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify(body)
        });
        
        const result = await response.json();
        if (result.success) {
            loadBankAccounts();
            refreshReceivingAccountsData();
        } else {
            alert(result.message || 'Failed to update');
        }
    } catch (error) {
        alert('Failed to update: ' + error.message);
    }
}

async function toggleBankAccount(id) {
    try {
        const response = await fetch(`/online-receiving-accounts/${id}/toggle-active`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        
        const result = await response.json();
        if (result.success) {
            loadBankAccounts();
            refreshReceivingAccountsData();
        }
    } catch (error) {
        alert('Failed to toggle: ' + error.message);
    }
}

async function deleteBankAccount(id, name) {
    if (!confirm(`Delete "${name}"? This cannot be undone. If this account is in use, it will be rejected.`)) return;
    
    try {
        const response = await fetch(`/online-receiving-accounts/${id}`, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        
        const result = await response.json();
        if (result.success) {
            loadBankAccounts();
            refreshReceivingAccountsData();
        } else {
            alert(result.message || 'Failed to delete');
        }
    } catch (error) {
        alert('Failed to delete: ' + error.message);
    }
}

// Refresh the receivingAccounts array used in the approval modal chips
async function refreshReceivingAccountsData() {
    try {
        const resp = await fetch('/online-receiving-accounts', {
            headers: { 'Accept': 'application/json' }
        });
        const result = await resp.json();
        if (result.success) {
            // Update the global receivingAccounts with only active ones
            receivingAccounts.length = 0;
            result.data.filter(a => a.is_active).forEach(a => receivingAccounts.push(a));
        }
    } catch (e) {
        // Silently fail - chips will update on next page load
    }
}
</script>
@endpush
