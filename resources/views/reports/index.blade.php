@extends('layouts.app')

@section('title', 'Reports')

@push('demo1_css')
<style>
    .reports-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 24px;
    }
    
    /* Tab Bar */
    .tab-bar {
        display: flex;
        gap: 4px;
        background: #F3F4F6;
        border-radius: 10px;
        padding: 4px;
        margin-bottom: 24px;
    }
    .tab-btn {
        flex: 1;
        padding: 10px 16px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        background: transparent;
        color: #6B7280;
        transition: all 0.2s;
    }
    .tab-btn.active {
        background: white;
        color: #10B981;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .tab-btn:hover:not(.active) {
        color: #374151;
    }
    
    /* Month Cards */
    .month-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 12px;
        cursor: pointer;
        border: 1px solid #E5E7EB;
        transition: all 0.2s;
    }
    .month-card:hover {
        border-color: #10B981;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.1);
    }
    .month-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
    }
    .month-name {
        font-size: 17px;
        font-weight: 700;
        color: #1F2937;
    }
    .profit-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 600;
    }
    .profit-positive { background: #D1FAE5; color: #059669; }
    .profit-negative { background: #FEE2E2; color: #DC2626; }
    
    .month-summary {
        display: flex;
        gap: 12px;
    }
    .month-summary-item {
        flex: 1;
        text-align: center;
    }
    .month-summary-label {
        font-size: 11px;
        color: #6B7280;
        margin-bottom: 2px;
    }
    .month-summary-value {
        font-size: 14px;
        font-weight: 600;
    }
    .val-green { color: #10B981; }
    .val-red { color: #EF4444; }
    .val-orange { color: #F59E0B; }
    .val-purple { color: #8B5CF6; }
    
    .tap-hint {
        text-align: right;
        margin-top: 12px;
        padding-top: 8px;
        border-top: 1px solid #E5E7EB;
        font-size: 11px;
        color: #9CA3AF;
    }
    
    /* Month Detail View */
    .back-button {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 14px;
        font-weight: 600;
        color: #10B981;
        margin-bottom: 16px;
        cursor: pointer;
        background: none;
        border: none;
        padding: 0;
    }
    .back-button:hover {
        color: #059669;
    }
    .detail-header {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 16px;
        border: 1px solid #E5E7EB;
    }
    .detail-month-name {
        font-size: 22px;
        font-weight: 700;
        color: #1F2937;
        margin-bottom: 10px;
    }
    .profit-badge-large {
        display: inline-block;
        padding: 6px 16px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 700;
    }
    
    /* Collapsible Section */
    .section-card {
        background: white;
        border-radius: 12px;
        margin-bottom: 12px;
        overflow: hidden;
        border: 1px solid #E5E7EB;
    }
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 18px;
        cursor: pointer;
        user-select: none;
        transition: background 0.15s;
    }
    .section-header:hover { opacity: 0.9; }
    .section-header-left { flex: 1; }
    .section-title {
        font-size: 15px;
        font-weight: 700;
        color: #1F2937;
    }
    .section-count {
        font-size: 11px;
        color: #6B7280;
        margin-top: 2px;
    }
    .section-header-right {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .section-total {
        font-size: 15px;
        font-weight: 700;
        color: #1F2937;
    }
    .expand-icon {
        font-size: 12px;
        color: #6B7280;
    }
    .section-content {
        padding: 12px;
        border-top: 1px solid rgba(0,0,0,0.05);
    }
    
    /* Date sub-section */
    .date-section {
        margin-bottom: 8px;
        border-radius: 8px;
        overflow: hidden;
    }
    .date-section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 10px;
        cursor: pointer;
    }
    .date-section-date {
        font-size: 13px;
        font-weight: 600;
        color: #374151;
    }
    .date-section-right {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .date-section-total {
        font-size: 13px;
        font-weight: 700;
        color: #1F2937;
    }
    .date-items {
        padding: 0 10px 10px;
    }
    .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 6px 0;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .detail-item-left { flex: 1; padding-right: 8px; }
    .detail-item-title { font-size: 13px; font-weight: 600; color: #1F2937; }
    .detail-item-sub { font-size: 11px; color: #6B7280; margin-top: 1px; }
    .detail-item-amount { font-size: 13px; font-weight: 700; color: #1F2937; }
    .detail-item-highlight {
        background: #FEF9C3;
        border-left: 3px solid #F59E0B;
        padding-left: 8px;
        margin-left: -8px;
        border-radius: 4px;
    }
    .detail-item-date-bold {
        font-size: 11px;
        font-weight: 700;
        color: #D97706;
        margin-top: 2px;
    }
    
    /* Daily Report */
    .daily-header {
        background: white;
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 12px;
        border: 1px solid #E5E7EB;
    }
    .daily-header-title {
        font-size: 16px;
        font-weight: 700;
        color: #1F2937;
    }
    .daily-header-sub {
        font-size: 12px;
        color: #6B7280;
        margin-top: 4px;
    }
    
    .day-card {
        background: white;
        border-radius: 12px;
        margin-bottom: 10px;
        overflow: hidden;
        border: 1px solid #E5E7EB;
    }
    .day-card-today {
        border: 2px solid #10B981;
    }
    .day-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        background: #F9FAFB;
        cursor: pointer;
        user-select: none;
    }
    .day-header:hover { background: #F3F4F6; }
    .day-header-left {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .day-date-text {
        font-size: 14px;
        font-weight: 700;
        color: #1F2937;
    }
    .today-badge {
        font-size: 9px;
        font-weight: 700;
        color: white;
        background: #10B981;
        padding: 2px 6px;
        border-radius: 4px;
    }
    .day-header-right {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .day-total-text {
        font-size: 14px;
        font-weight: 700;
        color: #1F2937;
    }
    
    .day-summary-row {
        display: flex;
        padding: 8px 12px;
        gap: 6px;
    }
    .day-summary-item {
        flex: 1;
        text-align: center;
        padding: 8px;
        border-radius: 8px;
    }
    .day-summary-label {
        font-size: 10px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 2px;
    }
    .day-summary-value {
        font-size: 12px;
        font-weight: 700;
        color: #1F2937;
    }
    .day-summary-count {
        font-size: 10px;
        color: #6B7280;
    }
    
    .day-details {
        border-top: 1px solid #E5E7EB;
        padding: 12px 16px;
    }
    .day-detail-section {
        margin-bottom: 12px;
    }
    .day-detail-section-title {
        font-size: 13px;
        font-weight: 700;
        color: #374151;
        margin-bottom: 8px;
        padding-bottom: 6px;
        border-bottom: 1px solid #E5E7EB;
    }
    .day-detail-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 6px 0;
        border-bottom: 1px solid #F3F4F6;
    }
    .day-detail-item-left { flex: 1; padding-right: 8px; }
    .day-detail-item-title { font-size: 13px; font-weight: 600; color: #1F2937; }
    .day-detail-item-sub { font-size: 11px; color: #6B7280; margin-top: 1px; }
    .day-detail-item-amount { font-size: 13px; font-weight: 700; color: #1F2937; }
    
    /* Load More */
    .load-more-container {
        text-align: center;
        padding: 16px;
    }
    .load-more-btn {
        background: #10B981;
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s;
    }
    .load-more-btn:hover { background: #059669; }
    .load-more-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    
    .no-data-text {
        font-size: 12px;
        color: #9CA3AF;
        font-style: italic;
        text-align: center;
        padding: 8px;
    }
    
    .loading-container {
        text-align: center;
        padding: 60px 20px;
        color: #6B7280;
    }
    .loading-container .spinner {
        display: inline-block;
        width: 32px;
        height: 32px;
        border: 3px solid #E5E7EB;
        border-top-color: #10B981;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    /* Tips Card */
    .tips-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        padding: 14px 18px;
        margin-bottom: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    .tips-card:hover {
        border-color: #F59E0B;
        box-shadow: 0 4px 12px rgba(245,158,11,0.1);
    }
    .tips-card-left { display: flex; align-items: center; gap: 10px; }
    .tips-card-icon { font-size: 22px; }
    .tips-card-title { font-size: 15px; font-weight: 700; color: #1F2937; }
    .tips-card-count { font-size: 11px; color: #6B7280; margin-top: 2px; }
    .tips-card-amount { font-size: 16px; font-weight: 700; color: #F59E0B; }
    
    /* Tips Modal */
    .modal-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        animation: fadeIn 0.2s;
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .modal-box {
        background: white;
        border-radius: 16px;
        width: 90%;
        max-width: 600px;
        max-height: 80vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        animation: slideUp 0.25s;
    }
    @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header {
        padding: 18px 20px;
        border-bottom: 1px solid #E5E7EB;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .modal-title { font-size: 17px; font-weight: 700; color: #1F2937; }
    .modal-close {
        width: 32px; height: 32px;
        border: none; background: #F3F4F6;
        border-radius: 8px; font-size: 18px;
        cursor: pointer; color: #6B7280;
        display: flex; align-items: center; justify-content: center;
    }
    .modal-close:hover { background: #E5E7EB; }
    .modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
    .modal-footer {
        padding: 12px 20px;
        border-top: 1px solid #E5E7EB;
        text-align: center;
        font-size: 14px;
        font-weight: 700;
        color: #F59E0B;
    }
    .tip-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 10px 0;
        border-bottom: 1px solid #F3F4F6;
    }
    .tip-row:last-child { border-bottom: none; }
    .tip-row-left { flex: 1; padding-right: 12px; }
    .tip-order { font-size: 13px; font-weight: 600; color: #1F2937; }
    .tip-customer { font-size: 11px; color: #6B7280; margin-top: 2px; }
    .tip-rider { font-size: 11px; color: #10B981; margin-top: 1px; }
    .tip-date-info { font-size: 10px; color: #9CA3AF; margin-top: 1px; }
    .tip-row-right { text-align: right; }
    .tip-amount { font-size: 14px; font-weight: 700; color: #F59E0B; }
    .tip-order-total { font-size: 11px; color: #6B7280; }
    
    /* View Toggle inside month details */
    .view-toggle-bar {
        display: flex;
        gap: 4px;
        background: #F3F4F6;
        border-radius: 8px;
        padding: 3px;
        margin-bottom: 16px;
    }
    .view-toggle-btn {
        flex: 1;
        padding: 8px 14px;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        background: transparent;
        color: #6B7280;
        transition: all 0.2s;
    }
    .view-toggle-btn.active {
        background: white;
        color: #10B981;
        box-shadow: 0 1px 2px rgba(0,0,0,0.08);
    }
    .view-toggle-btn:hover:not(.active) { color: #374151; }
    
    /* Daily breakdown inside month detail */
    .daily-breakdown-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        margin-bottom: 10px;
        overflow: hidden;
    }
    .daily-breakdown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        background: #F9FAFB;
        cursor: pointer;
        user-select: none;
    }
    .daily-breakdown-header:hover { background: #F3F4F6; }
    .daily-breakdown-date { font-size: 14px; font-weight: 700; color: #1F2937; }
    .daily-breakdown-total { font-size: 14px; font-weight: 700; color: #1F2937; }
    .daily-breakdown-pills {
        display: flex;
        padding: 6px 12px 10px;
        gap: 6px;
    }
    .daily-pill {
        flex: 1;
        text-align: center;
        padding: 6px 4px;
        border-radius: 8px;
    }
    .daily-pill-label { font-size: 10px; font-weight: 600; color: #374151; }
    .daily-pill-value { font-size: 12px; font-weight: 700; color: #1F2937; }
    .daily-pill-count { font-size: 10px; color: #6B7280; }
    .daily-breakdown-details {
        border-top: 1px solid #E5E7EB;
        padding: 12px 16px;
    }
</style>
@endpush

@section('content')
<div class="reports-container">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">📊 Reports</h1>
            <p class="text-gray-600 mt-2">Monthly & daily audit financial summaries</p>
        </div>
    </div>

    <!-- Tab Bar -->
    <div class="tab-bar">
        <button class="tab-btn active" id="tabMonthly" onclick="switchTab('monthly')">📅 Monthly Summary</button>
        <button class="tab-btn" id="tabDaily" onclick="switchTab('daily')">📋 Daily Audit</button>
    </div>

    <!-- Content Area -->
    <div id="contentArea"></div>
</div>
@endsection

@push('demo1_js')
<script>
    let currentTab = 'monthly';
    let monthlyData = [];
    let selectedMonth = null;
    let monthDetails = null;
    let expandedSections = {};
    let expandedDates = {};
    let dailySummary = [];
    let expandedDays = {};
    let dayDetailsCache = {};
    let loadingDay = null;
    let daysLoaded = 30;
    let hasMoreDays = true;
    let monthDetailView = 'daily'; // 'daily' (default) or 'category'
    let expandedDailyDays = {};
    let showTipsModal = false;
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    // ========== Utility ==========
    function formatCurrency(amount) {
        return 'PKR ' + Math.round(amount || 0).toLocaleString();
    }
    function formatShortDate(dateString) {
        if (!dateString) return '-';
        const d = new Date(dateString);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    
    // ========== Tab Switching ==========
    function switchTab(tab) {
        currentTab = tab;
        document.getElementById('tabMonthly').classList.toggle('active', tab === 'monthly');
        document.getElementById('tabDaily').classList.toggle('active', tab === 'daily');
        
        if (tab === 'monthly') {
            selectedMonth = null;
            monthDetails = null;
            expandedSections = {};
            expandedDates = {};
            loadMonthlySummary();
        } else {
            expandedDays = {};
            dayDetailsCache = {};
            daysLoaded = 30;
            hasMoreDays = true;
            loadDailySummary(30);
        }
    }
    
    // ========== API Calls ==========
    async function apiGet(url) {
        const resp = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        });
        return resp.json();
    }
    
    // ========== MONTHLY ==========
    async function loadMonthlySummary() {
        document.getElementById('contentArea').innerHTML = '<div class="loading-container"><div class="spinner"></div><p style="margin-top:12px">Loading monthly summary...</p></div>';
        try {
            const data = await apiGet('/reports/api/monthly-summary');
            if (data.success) {
                monthlyData = data.data || [];
                renderMonthlySummary();
            }
        } catch (e) {
            document.getElementById('contentArea').innerHTML = '<div class="loading-container">Failed to load monthly summary</div>';
        }
    }
    
    function renderMonthlySummary() {
        if (selectedMonth && monthDetails) {
            renderMonthDetails();
            return;
        }
        
        if (monthlyData.length === 0) {
            document.getElementById('contentArea').innerHTML = '<div class="loading-container">No data available</div>';
            return;
        }
        
        let html = '';
        monthlyData.forEach(item => {
            const profitClass = item.profit >= 0 ? 'profit-positive' : 'profit-negative';
            const profitEmoji = item.profit >= 0 ? '📈' : '📉';
            html += `
                <div class="month-card" onclick="loadMonthDetails('${item.month_key}')">
                    <div class="month-header">
                        <span class="month-name">${escapeHtml(item.month_name)}</span>
                        <span class="profit-badge ${profitClass}">${profitEmoji} ${formatCurrency(item.profit)}</span>
                    </div>
                    <div class="month-summary">
                        <div class="month-summary-item">
                            <div class="month-summary-label">Invoices</div>
                            <div class="month-summary-value val-green">${formatCurrency(item.invoices)}</div>
                        </div>
                        <div class="month-summary-item">
                            <div class="month-summary-label">Expenses</div>
                            <div class="month-summary-value val-red">${formatCurrency(item.expenses)}</div>
                        </div>
                        <div class="month-summary-item">
                            <div class="month-summary-label">Purchases</div>
                            <div class="month-summary-value val-orange">${formatCurrency(item.vendor_purchases)}</div>
                        </div>
                        ${item.asset_purchases > 0 ? `
                        <div class="month-summary-item">
                            <div class="month-summary-label">Assets</div>
                            <div class="month-summary-value val-purple">${formatCurrency(item.asset_purchases)}</div>
                        </div>` : ''}
                        ${item.tips > 0 ? `
                        <div class="month-summary-item">
                            <div class="month-summary-label">Tips</div>
                            <div class="month-summary-value" style="color:#F59E0B">${formatCurrency(item.tips)}</div>
                        </div>` : ''}
                    </div>
                    <div class="tap-hint">Click for details →</div>
                </div>`;
        });
        document.getElementById('contentArea').innerHTML = html;
    }
    
    async function loadMonthDetails(monthKey) {
        selectedMonth = monthKey;
        expandedSections = {};
        expandedDates = {};
        expandedDailyDays = {};
        monthDetailView = 'daily';
        document.getElementById('contentArea').innerHTML = '<div class="loading-container"><div class="spinner"></div><p style="margin-top:12px">Loading details...</p></div>';
        try {
            const data = await apiGet(`/reports/api/month-details?month=${monthKey}`);
            if (data.success) {
                monthDetails = data.data;
                renderMonthDetails();
            }
        } catch (e) {
            document.getElementById('contentArea').innerHTML = '<div class="loading-container">Failed to load month details</div>';
        }
    }
    
    function renderMonthDetails() {
        const d = monthDetails;
        const profitClass = (d.profit || 0) >= 0 ? 'profit-positive' : 'profit-negative';
        
        let html = `
            <button class="back-button" onclick="goBackToSummary()">← Back to Summary</button>
            <div class="detail-header">
                <div class="detail-month-name">${escapeHtml(d.month_name)}</div>
                <span class="profit-badge-large ${profitClass}">Profit: ${formatCurrency(d.profit)}</span>
            </div>
        `;
        
        // Tips Card (clickable to open modal)
        if (d.tips && d.tips.total > 0) {
            html += `
                <div class="tips-card" onclick="openTipsModal()">
                    <div class="tips-card-left">
                        <span class="tips-card-icon">💵</span>
                        <div>
                            <div class="tips-card-title">Tips Collected</div>
                            <div class="tips-card-count">${d.tips.count} order${d.tips.count !== 1 ? 's' : ''} with tips</div>
                        </div>
                    </div>
                    <div class="tips-card-amount">${formatCurrency(d.tips.total)}</div>
                </div>
            `;
        }
        
        // View Toggle
        html += `
            <div class="view-toggle-bar">
                <button class="view-toggle-btn ${monthDetailView === 'daily' ? 'active' : ''}" onclick="switchMonthView('daily')">📅 Daily Breakdown</button>
                <button class="view-toggle-btn ${monthDetailView === 'category' ? 'active' : ''}" onclick="switchMonthView('category')">📂 By Category</button>
            </div>
        `;
        
        if (monthDetailView === 'daily') {
            html += renderDailyBreakdownView(d);
        } else {
            html += renderCategoryView(d);
        }
        
        document.getElementById('contentArea').innerHTML = html;
        
        // Render tips modal if open
        if (showTipsModal) {
            renderTipsModal(d.tips);
        }
    }
    
    function switchMonthView(view) {
        monthDetailView = view;
        renderMonthDetails();
    }
    
    // Build daily breakdown from category-grouped data
    function buildDailyBreakdown(d) {
        const dayMap = {};
        
        // Merge invoices by date
        (d.invoices?.by_date || []).forEach(dd => {
            if (!dayMap[dd.date]) dayMap[dd.date] = { invoices: { total: 0, items: [] }, expenses: { total: 0, items: [] }, purchases: { total: 0, items: [] }, assets: { total: 0, items: [] } };
            dayMap[dd.date].invoices = { total: dd.total, items: dd.items };
        });
        
        // Merge expenses by date
        (d.expenses?.by_date || []).forEach(dd => {
            if (!dayMap[dd.date]) dayMap[dd.date] = { invoices: { total: 0, items: [] }, expenses: { total: 0, items: [] }, purchases: { total: 0, items: [] }, assets: { total: 0, items: [] } };
            dayMap[dd.date].expenses = { total: dd.total, items: dd.items };
        });
        
        // Merge vendor purchases by date
        (d.vendor_purchases?.by_date || []).forEach(dd => {
            if (!dayMap[dd.date]) dayMap[dd.date] = { invoices: { total: 0, items: [] }, expenses: { total: 0, items: [] }, purchases: { total: 0, items: [] }, assets: { total: 0, items: [] } };
            dayMap[dd.date].purchases = { total: dd.total, items: dd.items };
        });
        
        // Merge asset purchases by date
        (d.asset_purchases?.by_date || []).forEach(dd => {
            if (!dayMap[dd.date]) dayMap[dd.date] = { invoices: { total: 0, items: [] }, expenses: { total: 0, items: [] }, purchases: { total: 0, items: [] }, assets: { total: 0, items: [] } };
            dayMap[dd.date].assets = { total: dd.total, items: dd.items };
        });
        
        // Sort dates descending
        const sortedDates = Object.keys(dayMap).sort((a, b) => b.localeCompare(a));
        return sortedDates.map(date => ({
            date,
            ...dayMap[date],
            profit: (dayMap[date].invoices.total || 0) - (dayMap[date].expenses.total || 0) - (dayMap[date].purchases.total || 0),
        }));
    }
    
    function renderDailyBreakdownView(d) {
        const days = buildDailyBreakdown(d);
        if (days.length === 0) return '<div class="no-data-text">No data for this month</div>';
        
        let html = '';
        days.forEach(day => {
            const isExpanded = expandedDailyDays[day.date];
            const invCount = day.invoices.items?.length || 0;
            const expCount = day.expenses.items?.length || 0;
            const purCount = day.purchases.items?.length || 0;
            const astCount = day.assets.items?.length || 0;
            
            html += `
                <div class="daily-breakdown-card">
                    <div class="daily-breakdown-header" onclick="toggleDailyDay('${day.date}')">
                        <div style="display:flex;align-items:center;gap:8px">
                            <span class="daily-breakdown-date">${formatShortDate(day.date)}</span>
                            <span style="font-size:11px;color:#6B7280">${new Date(day.date + 'T00:00:00').toLocaleDateString('en-US', {weekday:'short'})}</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px">
                            <span class="daily-breakdown-total" style="color:${day.profit >= 0 ? '#059669' : '#DC2626'}">${day.profit >= 0 ? '📈' : '📉'} ${formatCurrency(day.profit)}</span>
                            <span class="expand-icon">${isExpanded ? '▼' : '▶'}</span>
                        </div>
                    </div>
                    <div class="daily-breakdown-pills">
                        <div class="daily-pill" style="background:#DBEAFE">
                            <div class="daily-pill-label">📦 Invoices</div>
                            <div class="daily-pill-value">${formatCurrency(day.invoices.total)}</div>
                            <div class="daily-pill-count">${invCount}</div>
                        </div>
                        <div class="daily-pill" style="background:#FEE2E2">
                            <div class="daily-pill-label">💰 Expenses</div>
                            <div class="daily-pill-value">${formatCurrency(day.expenses.total)}</div>
                            <div class="daily-pill-count">${expCount}</div>
                        </div>
                        <div class="daily-pill" style="background:#FEF3C7">
                            <div class="daily-pill-label">🏭 Purchases</div>
                            <div class="daily-pill-value">${formatCurrency(day.purchases.total)}</div>
                            <div class="daily-pill-count">${purCount}</div>
                        </div>
                        ${astCount > 0 ? `
                        <div class="daily-pill" style="background:#E0E7FF">
                            <div class="daily-pill-label">📦 Assets</div>
                            <div class="daily-pill-value">${formatCurrency(day.assets.total)}</div>
                            <div class="daily-pill-count">${astCount}</div>
                        </div>` : ''}
                    </div>`;
            
            if (isExpanded) {
                html += `<div class="daily-breakdown-details">`;
                
                // Invoices for this day
                if (invCount > 0) {
                    html += `<div class="day-detail-section">
                        <div class="day-detail-section-title">📦 Invoices (${invCount})</div>`;
                    day.invoices.items.forEach(item => {
                        html += `
                            <div class="day-detail-item">
                                <div class="day-detail-item-left">
                                    <div class="day-detail-item-title">${escapeHtml(item.order_number)}</div>
                                    <div class="day-detail-item-sub">${escapeHtml(item.customer_name)}</div>
                                </div>
                                <div class="day-detail-item-amount">${formatCurrency(item.amount)}</div>
                            </div>`;
                    });
                    html += `</div>`;
                }
                
                // Expenses for this day
                if (expCount > 0) {
                    html += `<div class="day-detail-section">
                        <div class="day-detail-section-title">💰 Expenses (${expCount})</div>`;
                    day.expenses.items.forEach(item => {
                        html += `
                            <div class="day-detail-item">
                                <div class="day-detail-item-left">
                                    <div class="day-detail-item-title">${escapeHtml(item.category || 'Expense')}</div>
                                    <div class="day-detail-item-sub">By: ${escapeHtml(item.user)}</div>
                                </div>
                                <div class="day-detail-item-amount">${formatCurrency(item.amount)}</div>
                            </div>`;
                    });
                    html += `</div>`;
                }
                
                // Purchases for this day
                if (purCount > 0) {
                    html += `<div class="day-detail-section">
                        <div class="day-detail-section-title">🏭 Vendor Purchases (${purCount})</div>`;
                    day.purchases.items.forEach(item => {
                        html += `
                            <div class="day-detail-item">
                                <div class="day-detail-item-left">
                                    <div class="day-detail-item-title">${escapeHtml(item.vendor_name || 'Vendor')}</div>
                                    <div class="day-detail-item-sub">By: ${escapeHtml(item.user)}</div>
                                </div>
                                <div class="day-detail-item-amount">${formatCurrency(item.amount)}</div>
                            </div>`;
                    });
                    html += `</div>`;
                }
                
                // Assets for this day
                if (astCount > 0) {
                    html += `<div class="day-detail-section">
                        <div class="day-detail-section-title">📦 Asset Purchases (${astCount})</div>`;
                    day.assets.items.forEach(item => {
                        html += `
                            <div class="day-detail-item">
                                <div class="day-detail-item-left">
                                    <div class="day-detail-item-title">${escapeHtml(item.asset_name || 'Asset')}</div>
                                    <div class="day-detail-item-sub">${escapeHtml(item.business_unit)} • By: ${escapeHtml(item.user)}</div>
                                </div>
                                <div class="day-detail-item-amount">${formatCurrency(item.amount)}</div>
                            </div>`;
                    });
                    html += `</div>`;
                }
                
                if (invCount + expCount + purCount + astCount === 0) {
                    html += '<div class="no-data-text">No transactions</div>';
                }
                
                html += `</div>`;
            }
            
            html += `</div>`;
        });
        return html;
    }
    
    function toggleDailyDay(date) {
        expandedDailyDays[date] = !expandedDailyDays[date];
        renderMonthDetails();
    }
    
    function renderCategoryView(d) {
        let html = '';
        
        // Invoices Section
        html += renderSection('invoices', '📦 Delivered Invoices', d.invoices, '#DBEAFE', '#EFF6FF', (item) => `
            <div class="detail-item-title">${escapeHtml(item.order_number)}</div>
            <div class="detail-item-sub">${escapeHtml(item.customer_name)}</div>
        `);
        
        // Expenses Section
        html += renderSection('expenses', '💰 Expenses', d.expenses, '#FEE2E2', '#FEF2F2', (item, dateData) => {
            const isDiff = item.entry_date !== dateData.date;
            return `
                <div class="detail-item-title">${escapeHtml(item.category || 'Expense')}</div>
                <div class="detail-item-sub">By: ${escapeHtml(item.user)}</div>
                ${isDiff ? `<div class="detail-item-date-bold">⚠️ Entered: ${formatShortDate(item.entry_date)}</div>` : ''}
            `;
        });
        
        // Vendor Purchases Section
        html += renderSection('purchases', '🏭 Vendor Purchases', d.vendor_purchases, '#FEF3C7', '#FFFBEB', (item, dateData) => {
            const isDiff = item.entry_date !== dateData.date;
            return `
                <div class="detail-item-title">${escapeHtml(item.vendor_name || 'Vendor')}</div>
                <div class="detail-item-sub">By: ${escapeHtml(item.user)}</div>
                ${isDiff ? `<div class="detail-item-date-bold">⚠️ Entered: ${formatShortDate(item.entry_date)}</div>` : ''}
            `;
        });
        
        // Asset Purchases Section
        if (d.asset_purchases && (d.asset_purchases.count > 0 || d.asset_purchases.total > 0)) {
            html += renderSection('assets', '📦 Asset Purchases', d.asset_purchases, '#E0E7FF', '#EEF2FF', (item, dateData) => {
                const isDiff = item.entry_date !== dateData.date;
                return `
                    <div class="detail-item-title">${escapeHtml(item.asset_name || 'Asset')}</div>
                    <div class="detail-item-sub">${escapeHtml(item.business_unit)} • By: ${escapeHtml(item.user)}</div>
                    ${isDiff ? `<div class="detail-item-date-bold">⚠️ Entered: ${formatShortDate(item.entry_date)}</div>` : ''}
                `;
            });
        }
        
        return html;
    }
    
    // Tips Modal
    function openTipsModal() {
        showTipsModal = true;
        renderTipsModal(monthDetails?.tips);
    }
    
    function closeTipsModal() {
        showTipsModal = false;
        const el = document.getElementById('tipsModalOverlay');
        if (el) el.remove();
    }
    
    function renderTipsModal(tips) {
        // Remove existing modal
        const existing = document.getElementById('tipsModalOverlay');
        if (existing) existing.remove();
        
        if (!tips || !tips.items?.length) return;
        
        let rows = '';
        tips.items.forEach(item => {
            rows += `
                <div class="tip-row">
                    <div class="tip-row-left">
                        <div class="tip-order">${escapeHtml(item.order_number)}</div>
                        <div class="tip-customer">${escapeHtml(item.customer_name)}</div>
                        <div class="tip-rider">🏍 ${escapeHtml(item.rider_name)}</div>
                        <div class="tip-date-info">${formatShortDate(item.delivery_date)}</div>
                    </div>
                    <div class="tip-row-right">
                        <div class="tip-amount">${formatCurrency(item.tip_amount)}</div>
                        <div class="tip-order-total">Order: ${formatCurrency(item.order_total)}</div>
                    </div>
                </div>`;
        });
        
        const modalHtml = `
            <div class="modal-overlay" id="tipsModalOverlay" onclick="if(event.target===this)closeTipsModal()">
                <div class="modal-box">
                    <div class="modal-header">
                        <span class="modal-title">💵 Tips Details</span>
                        <button class="modal-close" onclick="closeTipsModal()">✕</button>
                    </div>
                    <div class="modal-body">${rows}</div>
                    <div class="modal-footer">Total Tips: ${formatCurrency(tips.total)} from ${tips.count} orders</div>
                </div>
            </div>`;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    
    function renderSection(key, title, sectionData, headerBg, dateBg, renderItemContent) {
        const isExpanded = expandedSections[key];
        let html = `<div class="section-card">
            <div class="section-header" style="background:${headerBg}" onclick="toggleSection('${key}')">
                <div class="section-header-left">
                    <div class="section-title">${title}</div>
                    <div class="section-count">${sectionData?.count || 0} transactions</div>
                </div>
                <div class="section-header-right">
                    <span class="section-total">${formatCurrency(sectionData?.total)}</span>
                    <span class="expand-icon">${isExpanded ? '▼' : '▶'}</span>
                </div>
            </div>`;
        
        if (isExpanded) {
            html += `<div class="section-content">`;
            if (sectionData?.by_date?.length > 0) {
                sectionData.by_date.forEach(dateData => {
                    const dateKey = `${key}_${dateData.date}`;
                    const isDateExpanded = expandedDates[dateKey];
                    html += `
                        <div class="date-section" style="background:${dateBg}">
                            <div class="date-section-header" onclick="toggleDate('${key}','${dateData.date}')">
                                <span class="date-section-date">${formatShortDate(dateData.date)}</span>
                                <div class="date-section-right">
                                    <span class="date-section-total">${formatCurrency(dateData.total)}</span>
                                    <span class="expand-icon">${isDateExpanded ? '▼' : '▶'}</span>
                                </div>
                            </div>`;
                    if (isDateExpanded) {
                        html += `<div class="date-items">`;
                        dateData.items.forEach(item => {
                            const isDiff = item.entry_date && item.entry_date !== dateData.date;
                            html += `
                                <div class="detail-item ${isDiff ? 'detail-item-highlight' : ''}">
                                    <div class="detail-item-left">${renderItemContent(item, dateData)}</div>
                                    <div class="detail-item-amount">${formatCurrency(item.amount)}</div>
                                </div>`;
                        });
                        html += `</div>`;
                    }
                    html += `</div>`;
                });
            } else {
                html += `<div class="no-data-text">No data for this section</div>`;
            }
            html += `</div>`;
        }
        
        html += `</div>`;
        return html;
    }
    
    function toggleSection(key) {
        expandedSections[key] = !expandedSections[key];
        renderMonthDetails();
    }
    
    function toggleDate(section, date) {
        const key = `${section}_${date}`;
        expandedDates[key] = !expandedDates[key];
        renderMonthDetails();
    }
    
    function goBackToSummary() {
        selectedMonth = null;
        monthDetails = null;
        renderMonthlySummary();
    }
    
    // ========== DAILY ==========
    async function loadDailySummary(days) {
        document.getElementById('contentArea').innerHTML = '<div class="loading-container"><div class="spinner"></div><p style="margin-top:12px">Loading daily summary...</p></div>';
        daysLoaded = days;
        hasMoreDays = days < 365;
        try {
            const data = await apiGet(`/reports/api/daily-summary?days=${days}`);
            if (data.success) {
                dailySummary = data.data?.days || [];
                renderDailySummary();
            }
        } catch (e) {
            document.getElementById('contentArea').innerHTML = '<div class="loading-container">Failed to load daily summary</div>';
        }
    }
    
    function renderDailySummary() {
        let html = `
            <div class="daily-header">
                <div class="daily-header-title">📋 Transaction Activity</div>
                <div class="daily-header-sub">Showing last ${daysLoaded} days • Click a date to see details</div>
            </div>`;
        
        if (dailySummary.length === 0) {
            html += '<div class="loading-container">No transactions found</div>';
        }
        
        dailySummary.forEach(day => {
            const details = dayDetailsCache[day.date];
            const isExpanded = expandedDays[day.date];
            const isLoading = loadingDay === day.date;
            
            html += `
                <div class="day-card ${day.is_today ? 'day-card-today' : ''}">
                    <div class="day-header" onclick="toggleDayExpanded('${day.date}')">
                        <div class="day-header-left">
                            <span class="day-date-text">${escapeHtml(day.formatted_date)}</span>
                            ${day.is_today ? '<span class="today-badge">TODAY</span>' : ''}
                        </div>
                        <div class="day-header-right">
                            <span class="day-total-text">${formatCurrency(day.total_amount)}</span>
                            <span class="expand-icon">${isExpanded ? '▼' : '▶'}</span>
                        </div>
                    </div>
                    <div class="day-summary-row">
                        <div class="day-summary-item" style="background:#FEE2E2">
                            <div class="day-summary-label">💰 Exp</div>
                            <div class="day-summary-value">${formatCurrency(day.expenses.total)}</div>
                            <div class="day-summary-count">${day.expenses.count}</div>
                        </div>
                        <div class="day-summary-item" style="background:#FEF3C7">
                            <div class="day-summary-label">📥 Purch</div>
                            <div class="day-summary-value">${formatCurrency(day.purchases.total)}</div>
                            <div class="day-summary-count">${day.purchases.count}</div>
                        </div>
                        <div class="day-summary-item" style="background:#DBEAFE">
                            <div class="day-summary-label">💸 Pay</div>
                            <div class="day-summary-value">${formatCurrency(day.payments.total)}</div>
                            <div class="day-summary-count">${day.payments.count}</div>
                        </div>
                        ${day.assets && day.assets.count > 0 ? `
                        <div class="day-summary-item" style="background:#E0E7FF">
                            <div class="day-summary-label">📦 Asset</div>
                            <div class="day-summary-value">${formatCurrency(day.assets.total)}</div>
                            <div class="day-summary-count">${day.assets.count}</div>
                        </div>` : ''}
                    </div>`;
            
            if (isExpanded) {
                html += `<div class="day-details">`;
                if (isLoading) {
                    html += '<div class="loading-container"><div class="spinner" style="width:20px;height:20px;border-width:2px"></div><p style="margin-top:8px;font-size:12px">Loading...</p></div>';
                } else if (details) {
                    html += renderDayDetails(details, day.date);
                } else {
                    html += '<div class="no-data-text">Failed to load details</div>';
                }
                html += `</div>`;
            }
            
            html += `</div>`;
        });
        
        // Load More button
        html += `<div class="load-more-container">`;
        if (hasMoreDays && dailySummary.length > 0) {
            html += `<button class="load-more-btn" id="loadMoreBtn" onclick="loadMoreDays()">📅 Load 30 More Days</button>`;
        } else if (dailySummary.length > 0) {
            html += `<span class="no-data-text">No more transactions to show</span>`;
        }
        html += `</div>`;
        
        document.getElementById('contentArea').innerHTML = html;
    }
    
    function renderDayDetails(details, date) {
        let html = '';
        
        // Expenses
        if (details.expenses?.items?.length > 0) {
            html += `<div class="day-detail-section">
                <div class="day-detail-section-title">💰 Expenses (${details.expenses.count})</div>`;
            details.expenses.items.forEach(item => {
                const isDiff = item.transaction_date !== date;
                html += `
                    <div class="day-detail-item ${isDiff ? 'detail-item-highlight' : ''}">
                        <div class="day-detail-item-left">
                            <div class="day-detail-item-title">${escapeHtml(item.category)}</div>
                            <div class="day-detail-item-sub">By: ${escapeHtml(item.created_by)}</div>
                            ${isDiff ? `<div class="detail-item-date-bold">⚠️ Expense Date: ${formatShortDate(item.transaction_date)}</div>` : ''}
                        </div>
                        <div class="day-detail-item-amount">${formatCurrency(item.amount)}</div>
                    </div>`;
            });
            html += `</div>`;
        }
        
        // Purchases
        if (details.purchases?.items?.length > 0) {
            html += `<div class="day-detail-section">
                <div class="day-detail-section-title">📥 Vendor Purchases (${details.purchases.count})</div>`;
            details.purchases.items.forEach(item => {
                const isDiff = item.transaction_date !== date;
                html += `
                    <div class="day-detail-item ${isDiff ? 'detail-item-highlight' : ''}">
                        <div class="day-detail-item-left">
                            <div class="day-detail-item-title">${escapeHtml(item.vendor_name)}</div>
                            <div class="day-detail-item-sub">By: ${escapeHtml(item.created_by)}</div>
                            ${isDiff ? `<div class="detail-item-date-bold">⚠️ Txn Date: ${formatShortDate(item.transaction_date)}</div>` : ''}
                        </div>
                        <div class="day-detail-item-amount">${formatCurrency(item.amount)}</div>
                    </div>`;
            });
            html += `</div>`;
        }
        
        // Payments
        if (details.payments?.items?.length > 0) {
            html += `<div class="day-detail-section">
                <div class="day-detail-section-title">💸 Vendor Payments (${details.payments.count})</div>`;
            details.payments.items.forEach(item => {
                const isDiff = item.transaction_date !== date;
                html += `
                    <div class="day-detail-item ${isDiff ? 'detail-item-highlight' : ''}">
                        <div class="day-detail-item-left">
                            <div class="day-detail-item-title">${escapeHtml(item.vendor_name)}</div>
                            <div class="day-detail-item-sub">By: ${escapeHtml(item.created_by)}</div>
                            ${isDiff ? `<div class="detail-item-date-bold">⚠️ Txn Date: ${formatShortDate(item.transaction_date)}</div>` : ''}
                        </div>
                        <div class="day-detail-item-amount">${formatCurrency(item.amount)}</div>
                    </div>`;
            });
            html += `</div>`;
        }
        
        // Assets
        if (details.assets?.items?.length > 0) {
            html += `<div class="day-detail-section">
                <div class="day-detail-section-title">📦 Asset Purchases (${details.assets.count})</div>`;
            details.assets.items.forEach(item => {
                const isDiff = item.transaction_date !== date;
                html += `
                    <div class="day-detail-item ${isDiff ? 'detail-item-highlight' : ''}">
                        <div class="day-detail-item-left">
                            <div class="day-detail-item-title">${escapeHtml(item.asset_name || 'Asset')}</div>
                            <div class="day-detail-item-sub">${escapeHtml(item.business_unit)} • By: ${escapeHtml(item.created_by)}</div>
                            ${isDiff ? `<div class="detail-item-date-bold">⚠️ Txn Date: ${formatShortDate(item.transaction_date)}</div>` : ''}
                        </div>
                        <div class="day-detail-item-amount">${formatCurrency(item.amount)}</div>
                    </div>`;
            });
            html += `</div>`;
        }
        
        if (!html) {
            html = '<div class="no-data-text">No details available</div>';
        }
        
        return html;
    }
    
    async function toggleDayExpanded(date) {
        const isExpanding = !expandedDays[date];
        expandedDays[date] = isExpanding;
        
        if (isExpanding && !dayDetailsCache[date]) {
            loadingDay = date;
            renderDailySummary();
            
            try {
                const data = await apiGet(`/reports/api/daily-details?date=${date}`);
                if (data.success) {
                    dayDetailsCache[date] = data.data;
                }
            } catch (e) {
                console.error('Failed to load day details:', e);
            }
            loadingDay = null;
        }
        
        renderDailySummary();
    }
    
    async function loadMoreDays() {
        const newDays = Math.min(daysLoaded + 30, 365);
        if (newDays === daysLoaded) {
            hasMoreDays = false;
            renderDailySummary();
            return;
        }
        
        const btn = document.getElementById('loadMoreBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Loading...'; }
        
        try {
            const data = await apiGet(`/reports/api/daily-summary?days=${newDays}`);
            if (data.success) {
                dailySummary = data.data?.days || [];
                daysLoaded = newDays;
                hasMoreDays = newDays < 365;
                renderDailySummary();
            }
        } catch (e) {
            console.error('Failed to load more days:', e);
            if (btn) { btn.disabled = false; btn.textContent = '📅 Load 30 More Days'; }
        }
    }
    
    // ========== Init ==========
    document.addEventListener('DOMContentLoaded', function() {
        loadMonthlySummary();
    });
</script>
@endpush
