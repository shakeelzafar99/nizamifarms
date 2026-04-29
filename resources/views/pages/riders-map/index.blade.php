@extends('layouts.app')

@section('title', 'Riders Map')

@push('demo1_css')
<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<style>
    /* Full page container */
    .riders-map-page {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 70px);
        background: #f8fafc;
    }
    
    /* Header bar - orange gradient */
    .riders-map-header {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: white;
        padding: 12px 20px;
        flex-shrink: 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .riders-map-header h1 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
    }
    
    .header-controls {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    
    /* View toggle buttons */
    .view-toggle {
        display: flex;
        background: rgba(255,255,255,0.2);
        border-radius: 6px;
        overflow: hidden;
    }
    
    .view-toggle button {
        background: transparent;
        border: none;
        color: white;
        padding: 6px 14px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        transition: background 0.2s;
    }
    
    .view-toggle button.active {
        background: rgba(255,255,255,0.3);
    }
    
    .view-toggle button:hover:not(.active) {
        background: rgba(255,255,255,0.15);
    }
    
    /* Date picker */
    .date-picker {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .date-picker label {
        font-size: 13px;
        font-weight: 500;
    }
    
    .date-picker input[type="date"] {
        padding: 5px 10px;
        border-radius: 6px;
        border: none;
        font-size: 13px;
        background: rgba(255,255,255,0.9);
        color: #374151;
    }
    
    .btn-header {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
    }
    
    .btn-header:hover {
        background: rgba(255,255,255,0.3);
    }
    
    /* History banner */
    .history-banner {
        display: none;
        padding: 10px 20px;
        background: #fef3c7;
        border-bottom: 1px solid #fcd34d;
        color: #92400e;
        font-size: 13px;
    }
    
    /* Filters section */
    .filters-section {
        display: none;
        padding: 12px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .filters-row {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .filter-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .filter-group label {
        font-size: 13px;
        font-weight: 500;
        color: #374151;
    }
    
    .filter-group select {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        font-size: 13px;
        min-width: 150px;
    }
    
    .filter-summary {
        margin-left: auto;
        font-size: 13px;
        color: #6b7280;
    }
    
    /* Main content area */
    .main-content {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
    }
    
    /* Riders grid */
    .riders-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 12px;
    }
    
    /* Rider card */
    .rider-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 16px;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    
    .rider-card:hover {
        border-color: #3b82f6;
        box-shadow: 0 4px 12px rgba(59,130,246,0.15);
    }
    
    /* Map container */
    .map-container {
        height: 400px;
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 12px;
    }
    
    /* Orders list */
    .orders-list {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: white;
    }
    
    /* Back button */
    .btn-back {
        background: #6b7280;
        border: none;
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
    }
    
    .btn-back:hover {
        background: #4b5563;
    }
    
    /* Loading spinner */
    .loading-spinner {
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    /* Pulse animation for rider location */
    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(59,130,246,0.7); }
        70% { box-shadow: 0 0 0 10px rgba(59,130,246,0); }
        100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); }
    }
    
    /* Single rider view header */
    .view-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        padding: 12px;
        background: #f8fafc;
        border-radius: 8px;
    }
    
    .view-header h3 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
    }
    
    .view-summary {
        margin-left: auto;
        display: flex;
        gap: 16px;
        font-size: 13px;
    }
    
    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
        grid-column: 1 / -1;
    }
    
    .empty-state .icon {
        font-size: 48px;
        margin-bottom: 12px;
    }

    /* ===== DISPATCH TRACKER STYLES ===== */
    .dt-controls {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0 16px;
    }
    .dt-controls input[type="date"] {
        padding: 7px 12px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        font-size: 13px;
    }
    .dt-controls .dt-btn {
        padding: 7px 14px;
        border-radius: 6px;
        border: none;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        color: white;
        background: #f59e0b;
    }
    .dt-controls .dt-btn:hover { background: #d97706; }

    .dt-summary-bar {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .dt-stat {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 16px;
        min-width: 120px;
        text-align: center;
    }
    .dt-stat .dt-val {
        font-size: 20px;
        font-weight: 700;
        color: #1f2937;
    }
    .dt-stat .dt-label {
        font-size: 11px;
        color: #6b7280;
        margin-top: 2px;
    }
    .dt-stat.green .dt-val { color: #059669; }
    .dt-stat.red .dt-val { color: #dc2626; }
    .dt-stat.blue .dt-val { color: #2563eb; }
    .dt-stat.amber .dt-val { color: #d97706; }

    .dt-rider-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        margin-bottom: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .dt-rider-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 16px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .dt-rider-header:hover { background: #f9fafb; }
    .dt-rider-name {
        font-size: 15px;
        font-weight: 600;
        color: #1f2937;
    }
    .dt-rider-meta {
        font-size: 12px;
        color: #6b7280;
        margin-top: 3px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    .dt-rider-badges {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
    }
    .dt-badge {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 3px 8px;
        border-radius: 5px;
        font-size: 11px;
        font-weight: 600;
    }
    .dt-badge.green { background: #d1fae5; color: #065f46; }
    .dt-badge.red { background: #fee2e2; color: #991b1b; }
    .dt-badge.blue { background: #dbeafe; color: #1e40af; }
    .dt-badge.amber { background: #fef3c7; color: #92400e; }
    .dt-badge.purple { background: #ede9fe; color: #5b21b6; }
    .dt-badge.gray { background: #f3f4f6; color: #374151; }

    .dt-rider-body {
        display: none;
        border-top: 1px solid #e5e7eb;
    }
    .dt-rider-body.open { display: block; }

    .dt-batch {
        border-bottom: 1px solid #f3f4f6;
    }
    .dt-batch:last-child { border-bottom: none; }
    .dt-batch-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 16px;
        background: #f0fdf4;
        border-bottom: 1px solid #e5e7eb;
        cursor: pointer;
    }
    .dt-batch-header:hover { background: #ecfdf5; }
    .dt-batch-header.undispatched {
        background: #fef2f2;
    }
    .dt-batch-header.undispatched:hover { background: #fee2e2; }
    .dt-batch-title {
        font-size: 13px;
        font-weight: 600;
        color: #1f2937;
    }
    .dt-batch-stats {
        display: flex;
        gap: 8px;
        font-size: 11px;
        align-items: center;
    }
    .dt-batch-orders {
        display: none;
    }
    .dt-batch-orders.open { display: block; }

    .dt-order-row {
        border-bottom: none;
    }
    .dt-order-row:last-child .dt-order-main { border-bottom: none; }
    .dt-order-row { cursor: pointer; }
    .dt-order-main {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 16px 8px 28px;
        border-bottom: 1px solid #f9fafb;
        font-size: 12px;
        gap: 8px;
    }
    .dt-order-details {
        display: none;
        padding: 4px 16px 8px 56px;
        font-size: 11px;
        color: #6b7280;
        background: #f9fafb;
        border-bottom: 1px solid #f3f4f6;
    }
    .dt-order-details.open { display: block; }
    .dt-order-details span { margin-right: 16px; }
    .dt-order-details .dt-detail-label { color: #9ca3af; font-weight: 500; }
    .dt-order-details .dt-detail-value { color: #374151; }
    .dt-order-left {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }
    .dt-order-seq {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #e5e7eb;
        color: #374151;
        font-weight: 700;
        font-size: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .dt-order-seq.match { background: #d1fae5; color: #065f46; }
    .dt-order-seq.mismatch { background: #fee2e2; color: #991b1b; }
    .dt-order-num {
        font-weight: 600;
        color: #3b82f6;
        white-space: nowrap;
    }
    .dt-order-customer {
        color: #374151;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .dt-order-right {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    .dt-time {
        font-size: 11px;
        color: #6b7280;
        white-space: nowrap;
    }
    .dt-eta-ok { color: #059669; font-weight: 600; }
    .dt-eta-late { color: #dc2626; font-weight: 600; }
    .dt-amount {
        font-weight: 600;
        white-space: nowrap;
    }
    .dt-amount.cash { color: #059669; }
    .dt-amount.online { color: #2563eb; }
</style>
@endpush

@section('content')
<div class="riders-map-page">
    <!-- Header -->
    <div class="riders-map-header">
        <h1 id="pageTitle">📍 All Riders - Live Map</h1>
        <div class="header-controls">
            <!-- View Toggle -->
            <div class="view-toggle">
                <button id="viewRidersBtn" onclick="switchView('riders')" class="active">📍 Rider Map</button>
                <button id="viewOrdersBtn" onclick="switchView('orders')">📦 Open Orders</button>
                <button id="viewHistoryBtn" onclick="switchView('history')">📅 History</button>
                <button id="viewDispatchBtn" onclick="switchView('dispatch')">🚀 Dispatch Tracker</button>
            </div>
            
            <!-- Date Picker (for riders view) -->
            <div id="datePicker" class="date-picker">
                <label>Date:</label>
                <input type="date" id="dateInput" onchange="onDateChange(this.value)">
                <button class="btn-header" onclick="setToday()">Today</button>
            </div>
            
            <!-- Link to Invoices -->
            <a href="{{ route('orders.index') }}" class="btn-header">📋 Invoices</a>
        </div>
    </div>
    
    <!-- History Banner -->
    <div id="historyBanner" class="history-banner">
        📅 Viewing history - Shows deliveries for the selected date (no live location)
    </div>
    
    <!-- Filters Section (for Open Orders view) -->
    <div id="filtersSection" class="filters-section">
        <div class="filters-row">
            <div class="filter-group">
                <label>Status:</label>
                <select id="statusFilter" onchange="applyFilters()">
                    <option value="all">All Statuses</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Rider:</label>
                <select id="riderFilter" onchange="applyFilters()">
                    <option value="all">All Riders</option>
                </select>
            </div>
            <div id="filterSummary" class="filter-summary">Loading...</div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- ========== RIDERS LIST VIEW ========== -->
        <div id="ridersListView">
            <div id="ridersContainer" class="riders-grid">
                <div class="empty-state">
                    <svg class="loading-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading riders...
                </div>
            </div>
        </div>
        
        <!-- ========== SINGLE RIDER VIEW ========== -->
        <div id="singleRiderView" style="display: none;">
            <div class="view-header">
                <button class="btn-back" onclick="backToRidersList()">← Back</button>
                <h3 id="singleRiderTitle"></h3>
                <div id="singleRiderSummary" class="view-summary"></div>
            </div>
            <div id="singleRiderMap" class="map-container"></div>
            <div id="singleRiderOrders" class="orders-list"></div>
        </div>
        
        <!-- ========== OPEN ORDERS VIEW ========== -->
        <div id="openOrdersView" style="display: none;">
            <div id="openOrdersMap" class="map-container"></div>
            <div id="openOrdersList" class="orders-list"></div>
        </div>
        
        <!-- ========== HISTORY VIEW ========== -->
        <div id="historyView" style="display: none;">
            <!-- Level 1: Rider List -->
            <div id="historyRidersList">
                <div style="padding: 12px; background: #f8fafc; border-radius: 8px; margin-bottom: 16px;">
                    📊 Select a rider to view their delivery history (Last 3 months)
                </div>
                <div id="historyRidersContainer" class="riders-grid">
                    <div class="empty-state">Loading riders...</div>
                </div>
            </div>
            
            <!-- Level 2: Date Groups -->
            <div id="historyDateGroups" style="display: none;">
                <div class="view-header">
                    <button class="btn-back" onclick="backToHistoryRiders()">← Back</button>
                    <h3 id="historyRiderName"></h3>
                    <div id="historyRiderSummary" class="view-summary"></div>
                </div>
                <div id="historyDateContainer" style="max-height: calc(100vh - 280px); overflow-y: auto;"></div>
            </div>
            
            <!-- Level 3: Date Map -->
            <div id="historyDateMap" style="display: none;">
                <div class="view-header" style="flex-wrap: wrap;">
                    <button class="btn-back" onclick="backToHistoryDates()">← Back</button>
                    <h3 id="historyMapTitle"></h3>
                    <div style="margin-left: auto; display: flex; gap: 8px; align-items: center;">
                        <span id="historyMapSummary" style="font-size: 13px; color: #6b7280;"></span>
                        <button onclick="geocodeHistoryAll()" style="background: #3b82f6; border: none; color: white; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;">🔄 Refresh All</button>
                    </div>
                </div>
                <div id="historyMapContainer" class="map-container"></div>
                <div id="historyOrdersList" class="orders-list"></div>
            </div>
        </div>
        
        <!-- ========== DISPATCH TRACKER VIEW ========== -->
        <div id="dispatchView" style="display: none;">
            <div class="dt-controls">
                <label style="font-weight:500; font-size:13px; color:#374151;">Date:</label>
                <input type="date" id="dtDateInput" onchange="loadDispatchReport(this.value)">
                <button class="dt-btn" onclick="dtSetToday()">Today</button>
                <button class="dt-btn" style="background:#6b7280;" onclick="dtPrevDay()">← Prev Day</button>
                <button class="dt-btn" style="background:#6b7280;" onclick="dtNextDay()">Next Day →</button>
                <span id="dtDateLabel" style="font-size:14px; font-weight:600; color:#1f2937; margin-left:8px;"></span>
            </div>
            <div id="dtSummaryBar" class="dt-summary-bar"></div>
            <div id="dtRidersContainer"></div>
        </div>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
// =============================================
// STATE VARIABLES
// =============================================
let currentView = 'riders'; // 'riders', 'orders', 'history', 'dispatch'
let currentDate = null; // null = today/live
let autoRefreshInterval = null;

// Maps
let singleRiderMap = null;
let openOrdersMap = null;
let historyMap = null;
let allOrdersMarkers = [];

// Current data
let currentRiderId = null;
let currentRiderName = null;
let currentRiderOrders = [];
let allOrdersData = null;

// History state
let historyRiderId = null;
let historyRiderName = null;

// Dispatch Tracker state
let dtCurrentDate = null;
let dtReportData = null;

// =============================================
// INITIALIZATION
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('dateInput').value = today;
    document.getElementById('dateInput').max = today;
    
    loadRiders();
    startAutoRefresh();
});

// =============================================
// VIEW SWITCHING
// =============================================
function switchView(view) {
    currentView = view;
    
    // Update button states
    document.getElementById('viewRidersBtn').classList.toggle('active', view === 'riders');
    document.getElementById('viewOrdersBtn').classList.toggle('active', view === 'orders');
    document.getElementById('viewHistoryBtn').classList.toggle('active', view === 'history');
    document.getElementById('viewDispatchBtn').classList.toggle('active', view === 'dispatch');
    
    // Hide all views
    document.getElementById('ridersListView').style.display = 'none';
    document.getElementById('singleRiderView').style.display = 'none';
    document.getElementById('openOrdersView').style.display = 'none';
    document.getElementById('historyView').style.display = 'none';
    document.getElementById('dispatchView').style.display = 'none';
    document.getElementById('filtersSection').style.display = 'none';
    document.getElementById('historyBanner').style.display = 'none';
    
    if (view === 'riders') {
        document.getElementById('datePicker').style.display = 'flex';
        document.getElementById('ridersListView').style.display = 'block';
        document.getElementById('pageTitle').textContent = currentDate ? '📅 Riders History' : '📍 All Riders - Live Map';
        loadRiders();
        startAutoRefresh();
    } else if (view === 'orders') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('filtersSection').style.display = 'flex';
        document.getElementById('openOrdersView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '📦 Open Orders Map';
        stopAutoRefresh();
        loadOpenOrders();
    } else if (view === 'history') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('historyView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '📅 Delivery History';
        stopAutoRefresh();
        resetHistoryView();
        loadHistoryRiders();
    } else if (view === 'dispatch') {
        document.getElementById('datePicker').style.display = 'none';
        document.getElementById('dispatchView').style.display = 'block';
        document.getElementById('pageTitle').textContent = '🚀 Dispatch Tracker';
        stopAutoRefresh();
        dtInit();
    }
}

// =============================================
// DATE HANDLING
// =============================================
function onDateChange(dateValue) {
    const today = new Date().toISOString().split('T')[0];
    
    if (dateValue === today) {
        currentDate = null;
        document.getElementById('pageTitle').textContent = '📍 All Riders - Live Map';
        document.getElementById('historyBanner').style.display = 'none';
        startAutoRefresh();
    } else {
        currentDate = dateValue;
        const dateObj = new Date(dateValue);
        const formattedDate = dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        document.getElementById('pageTitle').textContent = `📅 Riders History - ${formattedDate}`;
        document.getElementById('historyBanner').style.display = 'block';
        stopAutoRefresh();
    }
    
    loadRiders();
}

function setToday() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('dateInput').value = today;
    onDateChange(today);
}

// =============================================
// AUTO-REFRESH
// =============================================
function startAutoRefresh() {
    stopAutoRefresh();
    if (currentDate === null && currentView === 'riders') {
        autoRefreshInterval = setInterval(() => {
            loadRiders(true);
        }, 30000);
    }
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// =============================================
// RIDERS LIST
// =============================================
async function loadRiders(silent = false) {
    const container = document.getElementById('ridersContainer');
    const isHistory = currentDate !== null;
    
    if (!silent) {
        container.innerHTML = `
            <div class="empty-state">
                <svg class="loading-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Loading ${isHistory ? 'history' : 'riders'}...
            </div>
        `;
    }
    
    try {
        let url = '/orders/riders-map/active';
        if (currentDate) url += `?date=${currentDate}`;
        
        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (data.success && data.riders.length > 0) {
            const isHistoryView = data.is_history === true;
            container.innerHTML = data.riders.map(rider => renderRiderCard(rider, isHistoryView)).join('');
        } else {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="icon">${isHistory ? '📅' : '📍'}</div>
                    <div style="font-weight: 500;">No ${isHistory ? 'activity' : 'active riders'} found</div>
                    <div style="font-size: 13px; margin-top: 4px;">
                        ${isHistory ? 'No riders found for this date' : 'Riders will appear here when they check in or have location data'}
                    </div>
                </div>
            `;
        }
    } catch (error) {
        console.error('Failed to load riders:', error);
        container.innerHTML = `<div class="empty-state"><div class="icon">⚠️</div>Failed to load riders</div>`;
    }
}

function renderRiderCard(rider, isHistoryView) {
    const hasLocation = rider.last_location !== null;
    const hasSync = rider.last_sync !== null;
    const isOnDuty = rider.is_checked_in && !rider.is_checked_out;
    
    let statusColor, statusText, statusBadge;
    
    if (isHistoryView) {
        if (rider.is_checked_in) {
            statusColor = '#10B981';
            statusText = rider.is_checked_out ? '✓ Checked Out' : '✓ Was On Duty';
            statusBadge = `<div style="background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">📅 ${rider.orders_today.delivered} delivered</div>`;
        } else {
            statusColor = '#6B7280';
            statusText = '— Not checked in';
            statusBadge = `<div style="background: #f3f4f6; color: #6b7280; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">${rider.orders_today.delivered} delivered</div>`;
        }
    } else {
        let isOnline = false, isAway = false, lastSeenText = 'Never', lastSeenMinutes = null;
        
        if (hasSync && rider.last_sync.age_minutes !== null) {
            lastSeenMinutes = rider.last_sync.age_minutes;
            lastSeenText = rider.last_sync.age || 'Unknown';
            isOnline = lastSeenMinutes <= 2;
            isAway = lastSeenMinutes > 2 && lastSeenMinutes <= 10;
        } else if (hasLocation && rider.last_location.age_minutes !== null) {
            lastSeenMinutes = rider.last_location.age_minutes;
            lastSeenText = rider.last_location.age || 'Unknown';
            isOnline = lastSeenMinutes <= 6;
            isAway = lastSeenMinutes > 6 && lastSeenMinutes <= 15;
        }
        
        if (!isOnDuty) {
            statusColor = '#6B7280';
            statusText = rider.is_checked_out ? '⚫ Checked Out' : '⚫ Offline';
        } else if (isOnline) {
            statusColor = '#10B981';
            statusText = '🟢 Online';
        } else if (isAway) {
            statusColor = '#f59e0b';
            statusText = '🟡 Away';
        } else {
            statusColor = '#6B7280';
            statusText = '⚫ Offline';
        }
        
        if (isOnline) {
            statusBadge = `<div style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">● Active now</div>`;
        } else if (lastSeenMinutes !== null) {
            const bg = lastSeenMinutes <= 10 ? '#fef3c7' : '#fee2e2';
            const color = lastSeenMinutes <= 10 ? '#92400e' : '#991b1b';
            statusBadge = `<div style="background: ${bg}; color: ${color}; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">Last: ${lastSeenText}</div>`;
        } else {
            statusBadge = `<div style="background: #f3f4f6; color: #6b7280; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">No activity</div>`;
        }
    }
    
    let appStatusHtml = '';
    if (!isHistoryView && rider.app_status) {
        const appLoggedIn = rider.app_status.is_logged_in;
        const appVersion = rider.app_status.app_version;
        const appColor = appLoggedIn ? '#10b981' : '#ef4444';
        const appIcon = appLoggedIn ? '📱' : '📵';
        const appText = appLoggedIn ? 'Logged In' : 'Logged Out';
        appStatusHtml = `<div style="display: flex; align-items: center; gap: 6px; margin-top: 4px; font-size: 11px;"><span>${appIcon}</span><span style="color: ${appColor}; font-weight: 500;">${appText}</span>${appVersion ? `<span style="color: #9ca3af;">v${appVersion}</span>` : ''}</div>`;
    }
    
    return `
        <div class="rider-card" onclick="openRiderMap(${rider.id}, '${rider.name.replace(/'/g, "\\'")}')">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                <div>
                    <div style="font-weight: 600; font-size: 16px; color: #1f2937;">${rider.name}</div>
                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 4px;">
                        <span style="font-size: 12px; color: ${statusColor}; font-weight: 500;">${statusText}</span>
                    </div>
                    ${appStatusHtml}
                </div>
                ${statusBadge}
            </div>
            <div style="display: flex; gap: 16px; font-size: 13px;">
                ${isHistoryView ? `
                    <div title="Delivered"><span style="color: #10b981;">✓</span> <span style="font-weight: 600; color: #10b981;">${rider.orders_today.delivered}</span> <span style="color: #6b7280; font-size: 11px;">Delivered</span></div>
                ` : `
                    <div title="Open"><span style="color: #f59e0b;">⏳</span> <span style="font-weight: 600; color: #f59e0b;">${rider.orders_today.pending}</span> <span style="color: #6b7280; font-size: 11px;">Open</span></div>
                    <div title="Today"><span style="color: #10b981;">✓</span> <span style="font-weight: 600; color: #10b981;">${rider.orders_today.delivered}</span> <span style="color: #6b7280; font-size: 11px;">Today</span></div>
                `}
            </div>
        </div>
    `;
}

// =============================================
// SINGLE RIDER MAP
// =============================================
async function openRiderMap(riderId, riderName) {
    currentRiderId = riderId;
    currentRiderName = riderName;
    
    const title = currentDate 
        ? `📅 ${riderName} - ${new Date(currentDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} Deliveries`
        : `📍 ${riderName} - Orders & Deliveries`;
    
    document.getElementById('singleRiderTitle').textContent = title;
    document.getElementById('ridersListView').style.display = 'none';
    document.getElementById('singleRiderView').style.display = 'block';
    
    // Initialize map
    if (singleRiderMap) singleRiderMap.remove();
    singleRiderMap = L.map('singleRiderMap').setView([33.6844, 73.0479], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(singleRiderMap);
    
    await loadRiderMapData(riderId);
}

async function loadRiderMapData(riderId) {
    try {
        let url = `/orders/riders-map/${riderId}`;
        if (currentDate) url += `?date=${currentDate}`;
        
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Failed to load');
        
        currentRiderOrders = data.orders || [];
        const withLocation = currentRiderOrders.filter(o => o.location).length;
        const withoutLocation = currentRiderOrders.length - withLocation;
        
        document.getElementById('singleRiderSummary').innerHTML = `
            <span>📦 ${data.summary.total_orders} orders</span>
            <span style="color: #10b981;">✓ ${data.summary.delivered} delivered</span>
            <span style="color: #f59e0b;">⏳ ${data.summary.pending} pending</span>
            <span style="color: #10b981;">📍 ${withLocation}</span>
            <span style="color: #ef4444;">❌ ${withoutLocation}</span>
        `;
        
        const bounds = [];
        
        // Add rider location
        if (data.rider.current_location) {
            const riderIcon = L.divIcon({
                className: 'rider-marker',
                html: `<div style="width: 24px; height: 24px; background: #3b82f6; border: 3px solid white; border-radius: 50%; box-shadow: 0 2px 8px rgba(59,130,246,0.5); animation: pulse 2s infinite;"></div>`,
                iconSize: [24, 24], iconAnchor: [12, 12]
            });
            L.marker([data.rider.current_location.latitude, data.rider.current_location.longitude], {icon: riderIcon})
                .addTo(singleRiderMap)
                .bindPopup(`<b>🚴 ${data.rider.name}</b><br>Last seen: ${data.rider.current_location.age}`);
            bounds.push([data.rider.current_location.latitude, data.rider.current_location.longitude]);
        }
        
        // Add order markers
        data.orders.forEach(order => {
            if (!order.location) return;
            const isDelivered = ['delivered', 'completed'].includes(order.status);
            const color = isDelivered ? '#10b981' : (order.status.includes('delivery') ? '#f59e0b' : '#6b7280');
            const deliveryTime = isDelivered && order.delivered_at ? new Date(order.delivered_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }) : '';
            const etaTime = order.estimated_delivery_at_display || '';
            const etaCompare = order.eta_comparison;
            
            const icon = L.divIcon({
                className: 'order-marker',
                html: `<div style="display: flex; flex-direction: column; align-items: center;">
                    <div style="width: 28px; height: 28px; background: ${color}; border: 2px solid white; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px; box-shadow: 0 2px 6px rgba(0,0,0,0.2);">${isDelivered ? '✓' : '📦'}</div>
                    ${deliveryTime ? `<div style="background: ${color}; color: white; font-size: 9px; padding: 1px 4px; border-radius: 3px; margin-top: 2px; font-weight: 600;">${deliveryTime}</div>` : ''}
                </div>`,
                iconSize: isDelivered ? [40, 55] : [28, 28], iconAnchor: isDelivered ? [20, 28] : [14, 14]
            });
            
            let popupHtml = `<b>${order.order_number}</b><br>${order.customer_name}<br><span style="color: ${color};">${order.status_display}</span>`;
            if (deliveryTime) popupHtml += `<br>🕐 Delivered: ${deliveryTime}`;
            if (etaTime) popupHtml += `<br>⏱️ ETA: ${etaTime}`;
            if (etaCompare) popupHtml += `<br><span style="color: ${etaCompare.status === 'early' ? '#10b981' : '#ef4444'}; font-weight: 600;">${etaCompare.status_text}</span>`;
            
            L.marker([order.location.latitude, order.location.longitude], {icon})
                .addTo(singleRiderMap)
                .bindPopup(popupHtml);
            bounds.push([order.location.latitude, order.location.longitude]);
        });
        
        if (bounds.length > 0) singleRiderMap.fitBounds(bounds, {padding: [50, 50]});
        
        // Render orders list
        const ordersContainer = document.getElementById('singleRiderOrders');
        ordersContainer.innerHTML = data.orders.length > 0 ? data.orders.map(order => {
            const isDelivered = ['delivered', 'completed'].includes(order.status);
            const color = isDelivered ? '#10b981' : '#f59e0b';
            const hasLoc = order.location !== null;
            return `<div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-bottom: 1px solid #f3f4f6;">
                <div><span style="font-weight: 600; color: #3b82f6;">${order.order_number}</span> <span style="color: #374151; margin-left: 8px;">${order.customer_name}</span></div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span style="color: ${color}; font-size: 12px;">${order.status_display}</span>
                    <span style="background: ${hasLoc ? '#d1fae5' : '#fee2e2'}; color: ${hasLoc ? '#065f46' : '#991b1b'}; padding: 2px 6px; border-radius: 4px; font-size: 11px;">${hasLoc ? '📍' : '❌'}</span>
                </div>
            </div>`;
        }).join('') : '<div style="padding: 20px; text-align: center; color: #6b7280;">No orders</div>';
        
    } catch (error) {
        console.error('Failed to load rider data:', error);
        document.getElementById('singleRiderSummary').innerHTML = `<span style="color: #ef4444;">Error</span>`;
    }
}

function backToRidersList() {
    document.getElementById('singleRiderView').style.display = 'none';
    document.getElementById('ridersListView').style.display = 'block';
    if (singleRiderMap) { singleRiderMap.remove(); singleRiderMap = null; }
}

// =============================================
// OPEN ORDERS MAP
// =============================================
async function loadOpenOrders() {
    const mapContainer = document.getElementById('openOrdersMap');
    const listContainer = document.getElementById('openOrdersList');
    const summaryEl = document.getElementById('filterSummary');
    
    summaryEl.textContent = 'Loading...';
    listContainer.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">Loading orders...</div>';
    
    try {
        const statusFilter = document.getElementById('statusFilter').value;
        const riderFilter = document.getElementById('riderFilter').value;
        
        let url = '/orders/riders-map/all-open-orders';
        const params = [];
        if (statusFilter && statusFilter !== 'all') params.push(`status=${statusFilter}`);
        if (riderFilter && riderFilter !== 'all') params.push(`rider_id=${riderFilter}`);
        if (params.length > 0) url += '?' + params.join('&');
        
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Failed to load');
        
        allOrdersData = data;
        populateFilters(data.filters);
        
        summaryEl.innerHTML = `<span style="font-weight: 600;">${data.summary.total}</span> orders (<span style="color: #10b981;">${data.summary.with_location} with GPS</span>, <span style="color: #ef4444;">${data.summary.without_location} without</span>)`;
        
        // Initialize map
        if (openOrdersMap) openOrdersMap.remove();
        openOrdersMap = L.map('openOrdersMap').setView([33.6844, 73.0479], 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(openOrdersMap);
        
        allOrdersMarkers = [];
        const bounds = [];
        
        // Add markers
        data.orders.forEach((order, index) => {
            if (!order.location) return;
            const statusColors = { 'processing': '#f59e0b', 'confirmed': '#3b82f6', 'out_for_delivery': '#8b5cf6', 'out-for-delivery': '#8b5cf6', 'pending': '#6b7280' };
            const color = statusColors[order.status] || '#f59e0b';
            
            const icon = L.divIcon({
                className: 'order-marker',
                html: `<div style="width: 12px; height: 12px; background: ${color}; border: 2px solid white; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>`,
                iconSize: [12, 12], iconAnchor: [6, 6]
            });
            
            const marker = L.marker([order.location.latitude, order.location.longitude], {icon})
                .addTo(openOrdersMap)
                .bindPopup(`<b>${order.order_number}</b><br>${order.customer_name}<br><span style="color: ${color};">${order.status_display}</span><br>🛵 ${order.rider_name}`);
            
            allOrdersMarkers.push(marker);
            bounds.push([order.location.latitude, order.location.longitude]);
        });
        
        if (bounds.length > 0) openOrdersMap.fitBounds(bounds, {padding: [30, 30]});
        
        // Render list with action buttons
        listContainer.innerHTML = data.orders.length > 0 ? `
            <div style="background: #f8fafc; padding: 8px 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">📦 Orders (${data.orders.length})</div>
            ${data.orders.map((order, idx) => {
                const statusColors = { 'processing': '#f59e0b', 'confirmed': '#3b82f6', 'out_for_delivery': '#8b5cf6', 'out-for-delivery': '#8b5cf6', 'pending': '#6b7280' };
                const color = statusColors[order.status] || '#6b7280';
                const hasLoc = order.location !== null;
                const isVerified = order.location_source === 'verified_location';
                const locIcon = hasLoc ? (isVerified ? '📍' : '📍~') : '❌';
                const locTitle = hasLoc ? (isVerified ? 'Verified location' : 'Geocoded (approximate)') : 'No location';
                
                // Action buttons - verify location and geocode
                const verifyBtn = !isVerified && order.customer_id ? 
                    `<button onclick="event.stopPropagation(); openVerifyLocation(${order.customer_id}, ${order.id}, '${(order.customer_name || '').replace(/'/g, "\\'")}', '${(order.address || '').replace(/'/g, "\\'")}')" 
                             style="padding: 2px 6px; background: #10b981; color: white; border: none; border-radius: 4px; font-size: 10px; cursor: pointer;" 
                             title="Set verified location">📌</button>` : '';
                
                const geocodeBtn = order.customer_id && !isVerified ? 
                    `<button onclick="event.stopPropagation(); geocodeCustomer(${order.customer_id}, '${(order.customer_name || '').replace(/'/g, "\\'")}')" 
                             style="padding: 2px 6px; background: #f59e0b; color: white; border: none; border-radius: 4px; font-size: 10px; cursor: pointer;" 
                             title="Re-geocode address">🔄</button>` : '';
                
                return `<div onclick="focusOrder(${idx})" style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; cursor: pointer;" onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background='transparent'">
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span onclick="event.stopPropagation(); viewOrderDetails(${order.id})" 
                                  style="font-weight: 600; color: #3b82f6; cursor: pointer; text-decoration: underline;"
                                  title="Click to view order details">${order.order_number}</span>
                            <span style="color: #374151;">${order.customer_name}</span>
                        </div>
                        ${order.address ? `<div style="font-size: 11px; color: #9ca3af; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${order.address}">📍 ${order.address}</div>` : ''}
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center; flex-shrink: 0;">
                        <span style="color: ${color}; font-size: 12px;">${order.status_display}</span>
                        <span style="color: #6b7280; font-size: 11px;">${order.rider_name}</span>
                        <span title="${locTitle}">${locIcon}</span>
                        ${verifyBtn}
                        ${geocodeBtn}
                    </div>
                </div>`;
            }).join('')}
        ` : '<div style="padding: 20px; text-align: center; color: #6b7280;">No open orders found</div>';
        
    } catch (error) {
        console.error('Failed to load orders:', error);
        summaryEl.innerHTML = `<span style="color: #ef4444;">Failed to load</span>`;
    }
}

function populateFilters(filters) {
    const statusSelect = document.getElementById('statusFilter');
    const riderSelect = document.getElementById('riderFilter');
    
    if (statusSelect.options.length <= 1 && filters.statuses) {
        filters.statuses.forEach(status => {
            const count = filters.status_counts ? filters.status_counts[status] || 0 : 0;
            const opt = document.createElement('option');
            opt.value = status;
            opt.textContent = `${status.replace(/_/g, ' ')} (${count})`;
            statusSelect.appendChild(opt);
        });
    }
    
    if (riderSelect.options.length <= 1 && filters.riders) {
        if (filters.unassigned_count > 0) {
            const opt = document.createElement('option');
            opt.value = 'unassigned';
            opt.textContent = `⚠️ Unassigned (${filters.unassigned_count})`;
            riderSelect.appendChild(opt);
        }
        filters.riders.forEach(rider => {
            const count = filters.rider_counts ? filters.rider_counts[rider.id] || 0 : 0;
            const opt = document.createElement('option');
            opt.value = rider.id;
            opt.textContent = `${rider.name} (${count})`;
            riderSelect.appendChild(opt);
        });
    }
}

function applyFilters() { loadOpenOrders(); }

function focusOrder(index) {
    const orders = allOrdersData?.orders;
    if (!orders || !orders[index]) return;
    
    const order = orders[index];
    if (order.location) {
        openOrdersMap.setView([order.location.latitude, order.location.longitude], 16, { animate: true });
        if (allOrdersMarkers[index]) allOrdersMarkers[index].openPopup();
    }
}

// =============================================
// HISTORY VIEW
// =============================================
function resetHistoryView() {
    document.getElementById('historyRidersList').style.display = 'block';
    document.getElementById('historyDateGroups').style.display = 'none';
    document.getElementById('historyDateMap').style.display = 'none';
    historyRiderData = null; // Clear cached data
}

async function loadHistoryRiders() {
    const container = document.getElementById('historyRidersContainer');
    container.innerHTML = '<div class="empty-state"><svg class="loading-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Loading...</div>';
    
    try {
        const response = await fetch('/orders/riders-map/riders-for-history', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        const data = await response.json();
        
        if (data.success && data.riders.length > 0) {
            container.innerHTML = data.riders.map(rider => `
                <div class="rider-card" onclick="openRiderHistory(${rider.id}, '${rider.name.replace(/'/g, "\\'")}')">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div style="font-weight: 600; font-size: 16px; color: #1f2937;">${rider.name}</div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">View delivery history</div>
                        </div>
                        <div style="background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;">📅 History</div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<div class="empty-state"><div class="icon">📅</div>No riders found</div>';
        }
    } catch (error) {
        console.error('Failed to load history riders:', error);
        container.innerHTML = '<div class="empty-state"><div class="icon">⚠️</div>Failed to load</div>';
    }
}

// Store rider history data for use in map view
let historyRiderData = null;

async function openRiderHistory(riderId, riderName) {
    historyRiderId = riderId;
    historyRiderName = riderName;
    
    document.getElementById('historyRidersList').style.display = 'none';
    document.getElementById('historyDateGroups').style.display = 'block';
    document.getElementById('historyRiderName').textContent = riderName;
    
    const container = document.getElementById('historyDateContainer');
    container.innerHTML = '<div style="padding: 40px; text-align: center; color: #6b7280;"><svg class="loading-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Loading...</div>';
    
    try {
        const response = await fetch(`/orders/riders-map/rider-history/${riderId}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Failed to load');
        }
        
        // Store for later use
        historyRiderData = data;
        
        // Update summary with cash/online breakdown
        document.getElementById('historyRiderSummary').innerHTML = `
            <span style="font-weight: 600;">${data.summary.total_delivered}</span> delivered • 
            <span style="color: #10b981;">Cash: Rs. ${Math.round(data.summary.cash_total).toLocaleString()} (${data.summary.cash_count})</span> • 
            <span style="color: #3b82f6;">Online: Rs. ${Math.round(data.summary.online_total).toLocaleString()} (${data.summary.online_count})</span>
        `;
        
        // Render date groups - API returns 'dates' array with 'date_display'
        if (data.dates && data.dates.length > 0) {
            container.innerHTML = data.dates.map((dateGroup, idx) => {
                const totalAmount = (dateGroup.cash_total || 0) + (dateGroup.online_total || 0);
                const isExpanded = idx === 0; // First group starts expanded
                return `
                    <div style="background: white; border-radius: 12px; margin-bottom: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; cursor: pointer;"
                             onclick="toggleHistoryDateGroup('${dateGroup.date}')"
                             title="Click to show/hide orders">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span id="historyToggle_${dateGroup.date}" style="font-size: 14px; color: #9ca3af; transition: transform 0.2s; ${isExpanded ? 'transform: rotate(90deg);' : ''}">▶</span>
                                <div>
                                    <div style="font-size: 14px; font-weight: 600; color: #1f2937;">📅 ${dateGroup.date_display}</div>
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                        ${dateGroup.total_delivered} orders • Rs. ${Math.round(totalAmount).toLocaleString()} total
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <div style="display: flex; gap: 8px;">
                                    <span style="background: #d1fae5; color: #065f46; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 500;">
                                        💵 ${dateGroup.cash_count} • Rs. ${Math.round(dateGroup.cash_total || 0).toLocaleString()}
                                    </span>
                                    <span style="background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 500;">
                                        💳 ${dateGroup.online_count} • Rs. ${Math.round(dateGroup.online_total || 0).toLocaleString()}
                                    </span>
                                </div>
                                <button onclick="event.stopPropagation(); openHistoryDate('${dateGroup.date}', '${dateGroup.date_display}')" 
                                        style="background: #9333EA; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; cursor: pointer;"
                                        title="View on map">
                                    🗺️ Map
                                </button>
                            </div>
                        </div>
                        <div id="historyOrders_${dateGroup.date}" style="display: ${isExpanded ? 'block' : 'none'}; max-height: 300px; overflow-y: auto;">
                            ${(dateGroup.orders || []).map(order => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-bottom: 1px solid #f3f4f6;">
                                    <div>
                                        <span style="font-weight: 600; color: #3b82f6;">${order.order_number}</span>
                                        <span style="color: #374151; margin-left: 8px;">${order.customer_name}</span>
                                    </div>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <span style="font-weight: 600; color: ${order.payment_type === 'cash' ? '#10b981' : '#3b82f6'};">
                                            ${order.amount_formatted}
                                        </span>
                                        <span style="background: ${order.payment_type === 'cash' ? '#d1fae5' : '#dbeafe'}; color: ${order.payment_type === 'cash' ? '#065f46' : '#1e40af'}; padding: 2px 6px; border-radius: 4px; font-size: 10px;">
                                            ${order.payment_type === 'cash' ? '💵 Cash' : '💳 Online'}
                                        </span>
                                        <span style="color: #6b7280; font-size: 11px;">🕐 ${order.delivered_at}</span>
                                        ${order.estimated_delivery_at_display ? `<span style="color: #8B5CF6; font-size: 11px;">⏱️ ${order.estimated_delivery_at_display}</span>` : ''}
                                        ${order.eta_comparison ? `<span style="color: ${order.eta_comparison.status === 'early' ? '#10b981' : '#ef4444'}; font-size: 10px; font-weight: 600;">${order.eta_comparison.status_text}</span>` : ''}
                                        <span style="color: ${order.location ? '#10b981' : '#ef4444'};">${order.location ? '📍' : '❌'}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            container.innerHTML = '<div style="padding: 60px; text-align: center; color: #6b7280;"><div style="font-size: 48px; margin-bottom: 12px;">📅</div>No delivery history found</div>';
        }
    } catch (error) {
        console.error('Failed to load rider history:', error);
        container.innerHTML = '<div style="padding: 40px; text-align: center; color: #ef4444;">Failed to load: ' + error.message + '</div>';
    }
}

// Toggle date group expansion
function toggleHistoryDateGroup(dateKey) {
    const ordersDiv = document.getElementById('historyOrders_' + dateKey);
    const toggleIcon = document.getElementById('historyToggle_' + dateKey);
    
    if (ordersDiv.style.display === 'none') {
        ordersDiv.style.display = 'block';
        toggleIcon.style.transform = 'rotate(90deg)';
    } else {
        ordersDiv.style.display = 'none';
        toggleIcon.style.transform = 'rotate(0deg)';
    }
}

function backToHistoryRiders() {
    document.getElementById('historyDateGroups').style.display = 'none';
    document.getElementById('historyRidersList').style.display = 'block';
    historyRiderData = null; // Clear cached data
}

async function openHistoryDate(date, displayDate) {
    document.getElementById('historyDateGroups').style.display = 'none';
    document.getElementById('historyDateMap').style.display = 'block';
    document.getElementById('historyMapTitle').textContent = `${historyRiderName} - ${displayDate}`;
    
    // Get orders for this date from cached data
    let dateOrders = [];
    if (historyRiderData && historyRiderData.dates) {
        const dateGroup = historyRiderData.dates.find(d => d.date === date);
        if (dateGroup && dateGroup.orders) {
            dateOrders = dateGroup.orders;
        }
    }
    
    // Initialize map
    if (historyMap) historyMap.remove();
    historyMap = L.map('historyMapContainer').setView([33.6844, 73.0479], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(historyMap);
    
    // Add markers for orders with location
    const bounds = [];
    let deliveredCount = 0;
    
    dateOrders.forEach(order => {
        deliveredCount++;
        if (!order.location) return;
        
        const color = '#10b981'; // Green for delivered
        const deliveryTime = order.delivered_at || '';
        
        const icon = L.divIcon({
            className: 'order-marker',
            html: `<div style="display: flex; flex-direction: column; align-items: center;">
                <div style="width: 28px; height: 28px; background: ${color}; border: 2px solid white; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px;">✓</div>
                ${deliveryTime ? `<div style="background: ${color}; color: white; font-size: 9px; padding: 1px 4px; border-radius: 3px; margin-top: 2px; font-weight: 600;">${deliveryTime}</div>` : ''}
            </div>`,
            iconSize: [40, 55], iconAnchor: [20, 28]
        });
        
        let histPopup = `<b>${order.order_number}</b><br>${order.customer_name}<br>Delivered`;
        if (deliveryTime) histPopup += `<br>🕐 Delivered: ${deliveryTime}`;
        if (order.estimated_delivery_at_display) histPopup += `<br>⏱️ ETA: ${order.estimated_delivery_at_display}`;
        if (order.eta_comparison) histPopup += `<br><span style="color: ${order.eta_comparison.status === 'early' ? '#10b981' : '#ef4444'}; font-weight: 600;">${order.eta_comparison.status_text}</span>`;
        histPopup += `<br>${order.amount_formatted}`;
        
        L.marker([order.location.latitude, order.location.longitude], {icon})
            .addTo(historyMap)
            .bindPopup(histPopup);
        bounds.push([order.location.latitude, order.location.longitude]);
    });
    
    if (bounds.length > 0) {
        historyMap.fitBounds(bounds, {padding: [50, 50]});
    }
    
    document.getElementById('historyMapSummary').textContent = `${deliveredCount} delivered (${bounds.length} with GPS)`;
    
    // Render orders list
    document.getElementById('historyOrdersList').innerHTML = dateOrders.length > 0 ? dateOrders.map(order => {
        const hasLoc = order.location !== null;
        return `<div style="border-bottom: 1px solid #f3f4f6;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 16px;">
                <div>
                    <span style="font-weight: 600; color: #3b82f6;">${order.order_number}</span>
                    <span style="color: #374151; margin-left: 8px;">${order.customer_name}</span>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span style="font-weight: 600; color: ${order.payment_type === 'cash' ? '#10b981' : '#3b82f6'};">
                        ${order.amount_formatted}
                    </span>
                    <span style="background: ${order.payment_type === 'cash' ? '#d1fae5' : '#dbeafe'}; color: ${order.payment_type === 'cash' ? '#065f46' : '#1e40af'}; padding: 2px 6px; border-radius: 4px; font-size: 10px;">
                        ${order.payment_type === 'cash' ? '💵' : '💳'}
                    </span>
                    <span style="color: #6b7280; font-size: 11px;">🕐 ${order.delivered_at}</span>
                    ${order.estimated_delivery_at_display ? `<span style="color: #8B5CF6; font-size: 11px;">⏱️ ${order.estimated_delivery_at_display}</span>` : ''}
                    ${order.eta_comparison ? `<span style="color: ${order.eta_comparison.status === 'early' ? '#10b981' : '#ef4444'}; font-size: 10px; font-weight: 600;">${order.eta_comparison.status_text}</span>` : ''}
                    <span style="color: ${hasLoc ? '#10b981' : '#ef4444'};">${hasLoc ? '📍' : '❌'}</span>
                    <button onclick="event.stopPropagation(); loadDeliveryJourney(${order.id}, '${(order.order_number || '').replace(/'/g, "\\'")}');" style="padding: 2px 8px; background: #8B5CF6; color: white; border: none; border-radius: 4px; font-size: 10px; cursor: pointer; font-weight: 500;" title="View delivery journey">🛣️ Journey</button>
                </div>
            </div>
            <div id="journey-panel-${order.id}" style="display: none;"></div>
        </div>`;
    }).join('') : '<div style="padding: 20px; text-align: center; color: #6b7280;">No orders for this date</div>';
}

function backToHistoryDates() {
    document.getElementById('historyDateMap').style.display = 'none';
    document.getElementById('historyDateGroups').style.display = 'block';
    if (historyMap) { historyMap.remove(); historyMap = null; }
}

function geocodeHistoryAll() {
    // Placeholder for geocoding functionality
    alert('Geocoding not implemented in standalone view. Use the orders page for full functionality.');
}

// =============================================
// ACTION BUTTON FUNCTIONS
// =============================================

// View order details - opens in new tab
function viewOrderDetails(orderId) {
    window.open('/orders?order_id=' + orderId, '_blank');
}

// Open verify location prompt
function openVerifyLocation(customerId, orderId, customerName, address) {
    const url = prompt(`Enter Google Maps URL for ${customerName}:`, '');
    if (url && url.trim()) {
        saveVerifiedLocation(customerId, url.trim());
    }
}

// Save verified location from Google Maps URL
function saveVerifiedLocation(customerId, googleMapsUrl) {
    fetch(`/customers/${customerId}/set-verified-location`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            google_maps_url: googleMapsUrl
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Verified location saved successfully!');
            // Refresh open orders
            loadOpenOrders();
        } else {
            alert('❌ Failed to save location: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error saving location:', error);
        alert('❌ Error saving location');
    });
}

// =============================================
// DELIVERY JOURNEY
// =============================================
let journeyMaps = {}; // Track Leaflet maps per order to clean up

function loadDeliveryJourney(orderId, orderNumber) {
    const panel = document.getElementById('journey-panel-' + orderId);
    if (!panel) return;
    
    // Toggle: if already visible, hide it
    if (panel.style.display === 'block') {
        panel.style.display = 'none';
        if (journeyMaps[orderId]) {
            journeyMaps[orderId].remove();
            delete journeyMaps[orderId];
        }
        return;
    }
    
    // Show loading
    panel.style.display = 'block';
    panel.innerHTML = '<div style="padding: 16px; text-align: center; color: #6b7280; font-size: 13px;"><span style="display: inline-block; animation: spin 1s linear infinite; font-size: 16px;">⏳</span> Loading journey for ' + orderNumber + '...</div>';
    
    fetch('/orders/delivery-journey/' + orderId, {
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            panel.innerHTML = '<div style="padding: 12px 16px; color: #ef4444; font-size: 12px;">❌ ' + (data.message || 'Failed to load journey') + '</div>';
            return;
        }
        
        const s = data.summary;
        const hasTrail = data.trail && data.trail.length > 1;
        const hasStops = data.stops && data.stops.length > 0;
        
        let html = '<div style="background: #f8fafc; border-top: 1px solid #e5e7eb; padding: 12px 16px;">';
        
        // Summary bar
        html += '<div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: center; margin-bottom: 10px;">';
        html += '<span style="font-size: 12px; font-weight: 600; color: #374151;">🛣️ Journey: ' + data.journey_start_label + '</span>';
        html += '<span style="font-size: 11px; color: #6b7280;">' + data.journey_start + ' → ' + data.journey_end + '</span>';
        html += '<span style="background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">⏱ ' + s.total_duration_display + '</span>';
        html += '<span style="background: #dcfce7; color: #166534; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">📏 ' + s.total_distance_display + '</span>';
        html += '<span style="background: #f3e8ff; color: #7c3aed; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">🏃 ' + s.moving_time_display + '</span>';
        if (s.stop_count > 0) {
            html += '<span style="background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">🛑 ' + s.stop_count + ' stop' + (s.stop_count > 1 ? 's' : '') + ' (' + s.stopped_time_display + ')</span>';
        }
        if (s.avg_speed_kmh > 0) {
            html += '<span style="font-size: 10px; color: #6b7280;">Avg: ' + s.avg_speed_kmh + ' km/h</span>';
        }
        if (data.eta_comparison) {
            const ec = data.eta_comparison;
            html += '<span style="color: ' + (ec.status === 'early' ? '#10b981' : '#ef4444') + '; font-size: 10px; font-weight: 600;">ETA ' + ec.estimated_at_display + ' → Actual ' + ec.actual_at_display + ' (' + ec.status_text + ')</span>';
        }
        html += '</div>';
        
        // Stops detail (if any)
        if (hasStops) {
            html += '<div style="margin-bottom: 10px;">';
            data.stops.forEach(function(stop, idx) {
                html += '<div style="display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 4px; padding: 2px 8px; margin-right: 6px; margin-bottom: 4px; font-size: 10px;">';
                html += '<span style="font-weight: 600; color: #92400e;">🛑 Stop ' + (idx + 1) + '</span>';
                html += '<span style="color: #78350f;">' + stop.start_time + ' - ' + stop.end_time + '</span>';
                html += '<span style="color: #b45309; font-weight: 600;">' + stop.duration_display + '</span>';
                html += '</div>';
            });
            html += '</div>';
        }
        
        // Mini map
        if (hasTrail) {
            html += '<div id="journey-map-' + orderId + '" style="height: 250px; border-radius: 8px; border: 1px solid #e5e7eb;"></div>';
        } else {
            html += '<div style="padding: 20px; text-align: center; color: #9ca3af; font-size: 12px;">No GPS trail data available for this journey segment</div>';
        }
        
        html += '</div>';
        panel.innerHTML = html;
        
        // Render Leaflet map
        if (hasTrail) {
            setTimeout(function() {
                try {
                    const mapEl = document.getElementById('journey-map-' + orderId);
                    if (!mapEl) return;
                    
                    const map = L.map(mapEl);
                    journeyMaps[orderId] = map;
                    
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap',
                        maxZoom: 19
                    }).addTo(map);
                    
                    // Draw trail polyline
                    const trailCoords = data.trail.map(function(p) { return [p.latitude, p.longitude]; });
                    const polyline = L.polyline(trailCoords, {
                        color: '#3b82f6',
                        weight: 3,
                        opacity: 0.8
                    }).addTo(map);
                    
                    // Start marker (green)
                    L.circleMarker(trailCoords[0], {
                        radius: 7, color: '#16a34a', fillColor: '#22c55e', fillOpacity: 1, weight: 2
                    }).addTo(map).bindPopup('<b>Journey Start</b><br>' + data.journey_start + '<br><small>' + data.journey_start_label + '</small>');
                    
                    // End marker (red - delivery point)
                    L.circleMarker(trailCoords[trailCoords.length - 1], {
                        radius: 7, color: '#dc2626', fillColor: '#ef4444', fillOpacity: 1, weight: 2
                    }).addTo(map).bindPopup('<b>Delivered</b><br>' + data.journey_end + '<br>' + (data.address || ''));
                    
                    // Stop markers (orange)
                    if (hasStops) {
                        data.stops.forEach(function(stop, idx) {
                            L.circleMarker([stop.latitude, stop.longitude], {
                                radius: 6, color: '#d97706', fillColor: '#fbbf24', fillOpacity: 0.9, weight: 2
                            }).addTo(map).bindPopup('<b>🛑 Stop ' + (idx + 1) + '</b><br>' + stop.start_time + ' - ' + stop.end_time + '<br>' + stop.duration_display);
                        });
                    }
                    
                    // Fit to trail bounds
                    map.fitBounds(polyline.getBounds(), { padding: [20, 20] });
                    
                } catch(e) {
                    console.error('Journey map render error:', e);
                }
            }, 100);
        }
    })
    .catch(err => {
        console.error('Journey load error:', err);
        panel.innerHTML = '<div style="padding: 12px 16px; color: #ef4444; font-size: 12px;">❌ Error loading journey data</div>';
    });
}

// =============================================
// DISPATCH TRACKER
// =============================================
function dtInit() {
    if (!dtCurrentDate) {
        dtCurrentDate = new Date().toISOString().split('T')[0];
    }
    const inp = document.getElementById('dtDateInput');
    inp.value = dtCurrentDate;
    inp.max = new Date().toISOString().split('T')[0];
    loadDispatchReport(dtCurrentDate);
}

function dtSetToday() {
    dtCurrentDate = new Date().toISOString().split('T')[0];
    document.getElementById('dtDateInput').value = dtCurrentDate;
    loadDispatchReport(dtCurrentDate);
}

function dtPrevDay() {
    const d = new Date(dtCurrentDate);
    d.setDate(d.getDate() - 1);
    dtCurrentDate = d.toISOString().split('T')[0];
    document.getElementById('dtDateInput').value = dtCurrentDate;
    loadDispatchReport(dtCurrentDate);
}

function dtNextDay() {
    const d = new Date(dtCurrentDate);
    d.setDate(d.getDate() + 1);
    const today = new Date().toISOString().split('T')[0];
    if (d.toISOString().split('T')[0] > today) return;
    dtCurrentDate = d.toISOString().split('T')[0];
    document.getElementById('dtDateInput').value = dtCurrentDate;
    loadDispatchReport(dtCurrentDate);
}

async function loadDispatchReport(date) {
    dtCurrentDate = date;
    const container = document.getElementById('dtRidersContainer');
    const summaryBar = document.getElementById('dtSummaryBar');

    container.innerHTML = '<div style="padding:40px; text-align:center; color:#6b7280;"><svg class="loading-spinner" style="width:32px; height:32px; margin:0 auto 12px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Loading dispatch report...</div>';
    summaryBar.innerHTML = '';

    try {
        const response = await fetch(`/orders/riders-map/dispatch-report?date=${date}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        const data = await response.json();

        if (!data.success) throw new Error(data.message || 'Failed');

        dtReportData = data;
        document.getElementById('dtDateLabel').textContent = data.date_display;

        dtRenderSummary(data.summary);
        dtRenderRiders(data.riders);
    } catch (err) {
        console.error('Dispatch report error:', err);
        container.innerHTML = `<div style="padding:40px; text-align:center; color:#ef4444;">Failed to load: ${err.message}</div>`;
    }
}

function dtRenderSummary(s) {
    const bar = document.getElementById('dtSummaryBar');
    const complianceColor = s.dispatch_compliance_pct >= 80 ? 'green' : (s.dispatch_compliance_pct >= 50 ? 'amber' : 'red');
    const etaColor = (s.eta_accuracy_pct !== null && s.eta_accuracy_pct >= 70) ? 'green' : ((s.eta_accuracy_pct !== null && s.eta_accuracy_pct >= 40) ? 'amber' : 'red');

    bar.innerHTML = `
        <div class="dt-stat blue">
            <div class="dt-val">${s.total_delivered}</div>
            <div class="dt-label">Delivered</div>
        </div>
        <div class="dt-stat">${'<div class="dt-val">' + s.total_riders + '</div><div class="dt-label">Riders</div>'}</div>
        <div class="dt-stat ${complianceColor}">
            <div class="dt-val">${s.dispatch_compliance_pct}%</div>
            <div class="dt-label">Dispatched (${s.total_dispatched}/${s.total_delivered})</div>
        </div>
        <div class="dt-stat ${s.total_undispatched > 0 ? 'red' : 'green'}">
            <div class="dt-val">${s.total_undispatched}</div>
            <div class="dt-label">Not Dispatched</div>
        </div>
        ${s.eta_accuracy_pct !== null ? `
        <div class="dt-stat ${etaColor}">
            <div class="dt-val">${s.eta_accuracy_pct}%</div>
            <div class="dt-label">ETA On-Time (${s.eta_on_time}/${s.eta_total})</div>
        </div>` : ''}
        <div class="dt-stat green">
            <div class="dt-val">Rs. ${Math.round(s.cash_total).toLocaleString()}</div>
            <div class="dt-label">Cash</div>
        </div>
        <div class="dt-stat blue">
            <div class="dt-val">Rs. ${Math.round(s.online_total).toLocaleString()}</div>
            <div class="dt-label">Online</div>
        </div>
        <div class="dt-stat ${s.has_verified_count > 0 ? (s.delivered_at_verified_count === s.has_verified_count ? 'green' : 'amber') : 'gray'}">
            <div class="dt-val">${s.delivered_at_verified_count}/${s.has_verified_count}</div>
            <div class="dt-label">At Verified Loc</div>
        </div>
    `;
}

function dtRenderRiders(riders) {
    const container = document.getElementById('dtRidersContainer');

    if (!riders || riders.length === 0) {
        container.innerHTML = '<div style="padding:60px; text-align:center; color:#6b7280;"><div style="font-size:48px; margin-bottom:12px;">📭</div>No delivered orders for this date</div>';
        return;
    }

    container.innerHTML = riders.map((rider, rIdx) => {
        const compPct = rider.dispatch_compliance_pct;
        const compClass = compPct >= 80 ? 'green' : (compPct >= 50 ? 'amber' : 'red');
        const etaPct = rider.eta_accuracy_pct;
        const etaClass = etaPct !== null ? (etaPct >= 70 ? 'green' : (etaPct >= 40 ? 'amber' : 'red')) : 'gray';

        return `
        <div class="dt-rider-card" id="dtRider_${rIdx}">
            <div class="dt-rider-header" onclick="dtToggleRider(${rIdx})">
                <div>
                    <div class="dt-rider-name">🏍️ ${rider.name}</div>
                    <div class="dt-rider-meta">
                        ${rider.login_time ? `<span>🟢 In: ${rider.login_time}</span>` : '<span style="color:#ef4444;">⚠️ No login</span>'}
                        ${rider.logout_time ? `<span>🔴 Out: ${rider.logout_time}</span>` : '<span style="color:#9ca3af;">— Still active</span>'}
                        ${rider.first_dispatch_delay_display ? `<span>⏱️ First dispatch: ${rider.first_dispatch_delay_display} after login</span>` : ''}
                        <span>📦 ${rider.total_orders} orders</span>
                        <span>${rider.num_dispatches} dispatch${rider.num_dispatches !== 1 ? 'es' : ''}</span>
                    </div>
                </div>
                <div class="dt-rider-badges">
                    <span class="dt-badge ${compClass}">${compPct}% dispatched</span>
                    ${rider.undispatched_count > 0 ? `<span class="dt-badge red">⚠️ ${rider.undispatched_count} missed</span>` : ''}
                    ${etaPct !== null ? `<span class="dt-badge ${etaClass}">${etaPct}% on-time</span>` : ''}
                    ${rider.has_verified_count > 0 ? `<span class="dt-badge ${rider.delivered_at_verified_count === rider.has_verified_count ? 'green' : 'amber'}">📍 ${rider.delivered_at_verified_count}/${rider.has_verified_count} at verified</span>` : '<span class="dt-badge gray">📍 0 verified</span>'}
                    <span class="dt-badge green">💵 ${rider.cash_count} · Rs.${Math.round(rider.cash_total).toLocaleString()}</span>
                    <span class="dt-badge blue">💳 ${rider.online_count} · Rs.${Math.round(rider.online_total).toLocaleString()}</span>
                    <span style="font-size:16px; color:#9ca3af; transition:transform 0.2s;" id="dtChevron_${rIdx}">▶</span>
                </div>
            </div>
            <div class="dt-rider-body" id="dtBody_${rIdx}">
                <div style="padding: 6px 16px; text-align: right; border-bottom: 1px solid #f3f4f6;">
                    <button onclick="event.stopPropagation(); dtExpandAllOrders(${rIdx})" style="font-size:11px; padding:3px 10px; border:1px solid #d1d5db; border-radius:4px; background:#fff; cursor:pointer; color:#374151;">⬇ Expand All</button>
                    <button onclick="event.stopPropagation(); dtCollapseAllOrders(${rIdx})" style="font-size:11px; padding:3px 10px; border:1px solid #d1d5db; border-radius:4px; background:#fff; cursor:pointer; color:#374151; margin-left:4px;">⬆ Collapse All</button>
                </div>
                ${dtRenderBatches(rider.dispatch_batches, rider.undispatched_group, rIdx)}
            </div>
        </div>`;
    }).join('');
}

function dtToggleRider(rIdx) {
    const body = document.getElementById('dtBody_' + rIdx);
    const chevron = document.getElementById('dtChevron_' + rIdx);
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open');
    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
}

function dtExpandAllOrders(rIdx) {
    const body = document.getElementById('dtBody_' + rIdx);
    if (!body) return;
    body.querySelectorAll('.dt-order-details').forEach(el => el.classList.add('open'));
}

function dtCollapseAllOrders(rIdx) {
    const body = document.getElementById('dtBody_' + rIdx);
    if (!body) return;
    body.querySelectorAll('.dt-order-details').forEach(el => el.classList.remove('open'));
}

function dtRenderBatches(batches, undispatchedGroup, rIdx) {
    let html = '';

    if (batches && batches.length > 0) {
        batches.forEach((batch, bIdx) => {
            const batchId = `dt_b_${rIdx}_${bIdx}`;
            const etaLabel = batch.eta_total > 0 ? `${batch.eta_on_time}/${batch.eta_total} on-time` : 'No ETAs';
            const priLabel = batch.priority_total > 0 ? `${batch.priority_match}/${batch.priority_total} in sequence` : '';
            const avgGap = batch.time_gaps.length > 0 ? Math.round(batch.time_gaps.reduce((a,b)=>a+b,0) / batch.time_gaps.length) : null;

            const durDisplay = batch.batch_duration_min >= 60
                ? `${Math.floor(batch.batch_duration_min/60)}h ${batch.batch_duration_min%60}m`
                : `${batch.batch_duration_min}m`;

            html += `
            <div class="dt-batch">
                <div class="dt-batch-header" onclick="dtToggleBatch('${batchId}')">
                    <div>
                        <span class="dt-batch-title">🚀 Dispatch at ${batch.dispatch_time_display}${batch.ofd_time_display && batch.ofd_time_display !== batch.dispatch_time_display ? ` · OFD ${batch.ofd_time_display}` : ''}</span>
                        <span style="font-size:11px; color:#6b7280; margin-left:8px;">${batch.order_count} orders</span>
                    </div>
                    <div class="dt-batch-stats">
                        ${batch.first_delivery && batch.last_delivery ? `<span class="dt-badge gray">🕐 ${batch.first_delivery} → ${batch.last_delivery} (${durDisplay})</span>` : ''}
                        <span class="dt-badge ${batch.eta_total > 0 && batch.eta_on_time === batch.eta_total ? 'green' : (batch.eta_total > 0 ? 'amber' : 'gray')}">${etaLabel}</span>
                        ${priLabel ? `<span class="dt-badge ${batch.priority_match === batch.priority_total ? 'green' : 'amber'}">📋 ${priLabel}</span>` : ''}
                        ${avgGap !== null ? `<span class="dt-badge gray">~${avgGap}m between stops</span>` : ''}
                        <span style="font-size:14px; color:#9ca3af;" id="dtBChev_${batchId}">▶</span>
                    </div>
                </div>
                <div class="dt-batch-orders" id="${batchId}">
                    ${dtRenderOrdersWithOfficeReturns(batch.orders, batch.office_returns || [], true)}
                </div>
            </div>`;
        });
    }

    if (undispatchedGroup && undispatchedGroup.order_count > 0) {
        const uBatchId = `dt_b_${rIdx}_undispatched`;
        html += `
        <div class="dt-batch">
            <div class="dt-batch-header undispatched" onclick="dtToggleBatch('${uBatchId}')">
                <div>
                    <span class="dt-batch-title">⚠️ Not Dispatched</span>
                    <span style="font-size:11px; color:#991b1b; margin-left:8px;">${undispatchedGroup.order_count} orders — dispatch button was not pressed</span>
                </div>
                <div class="dt-batch-stats">
                    <span style="font-size:14px; color:#9ca3af;" id="dtBChev_${uBatchId}">▶</span>
                </div>
            </div>
            <div class="dt-batch-orders" id="${uBatchId}">
                ${dtRenderOrders(undispatchedGroup.orders, false)}
            </div>
        </div>`;
    }

    if (!html) {
        html = '<div style="padding:16px; text-align:center; color:#6b7280; font-size:13px;">No orders</div>';
    }

    return html;
}

function dtToggleBatch(batchId) {
    const el = document.getElementById(batchId);
    const chev = document.getElementById('dtBChev_' + batchId);
    const isOpen = el.classList.contains('open');
    el.classList.toggle('open');
    if (chev) chev.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
}

function dtRenderOrdersWithOfficeReturns(orders, officeReturns, showDispatchInfo) {
    if (!orders || orders.length === 0) return '<div style="padding:12px 16px; color:#6b7280; font-size:12px;">No orders</div>';
    const returnMap = {};
    (officeReturns || []).forEach(r => { returnMap[r.after_order_idx] = r.visits; });

    let html = '';
    orders.forEach((o, idx) => {
        html += dtRenderSingleOrder(o, showDispatchInfo);
        if (returnMap[idx]) {
            returnMap[idx].forEach(v => {
                const durH = Math.floor(v.duration_min / 60);
                const durM = v.duration_min % 60;
                const durStr = durH > 0 ? `${durH}h ${durM}m` : `${durM}m`;
                html += `<div class="dt-order-row" style="background:#eff6ff; border-left:3px solid #3b82f6;">
                    <div class="dt-order-left" style="gap:6px;">
                        <div style="width:22px; height:22px; border-radius:50%; background:#3b82f6; color:white; font-size:10px; display:flex; align-items:center; justify-content:center;">🏢</div>
                        <span style="font-weight:600; color:#1e40af; font-size:11px;">Returned to office</span>
                    </div>
                    <div class="dt-order-right">
                        <span class="dt-time" style="color:#1e40af;">🏢 ${v.arrived} → ${v.left} (${durStr})</span>
                    </div>
                </div>`;
            });
        }
    });
    return html;
}

function dtRenderSingleOrder(o, showDispatchInfo) {
    const seqMatch = o.delivery_priority !== null && o.actual_sequence === o.delivery_priority;
    const seqClass = o.delivery_priority !== null ? (seqMatch ? 'match' : 'mismatch') : '';
    const seqTitle = o.delivery_priority !== null
        ? (seqMatch ? `Priority ${o.delivery_priority} — delivered in correct sequence` : `Priority ${o.delivery_priority} — delivered as #${o.actual_sequence}`)
        : 'No priority set';

    let etaHtml = '';
    if (showDispatchInfo && o.eta_comparison) {
        etaHtml = `<span class="dt-time">ETA: ${o.eta_comparison.eta_display}</span>
                   <span class="${o.eta_comparison.on_time ? 'dt-eta-ok' : 'dt-eta-late'}" style="font-size:10px;">${o.eta_comparison.label}</span>`;
    } else if (showDispatchInfo && !o.eta_comparison) {
        etaHtml = '<span class="dt-time" style="color:#d1d5db;">No ETA</span>';
    }

    const priorityHtml = o.delivery_priority !== null
        ? `<span class="dt-badge ${seqMatch ? 'green' : 'red'}" style="font-size:10px; padding:1px 5px;" title="${seqTitle}">P${o.delivery_priority}${!seqMatch ? '→#' + o.actual_sequence : ''}</span>`
        : (showDispatchInfo ? '<span class="dt-badge gray" style="font-size:10px; padding:1px 5px;">No priority</span>' : '');

    let locHtml = '';
    if (o.delivered_at_verified) {
        const distLabel = o.verification_distance_m !== null ? `${o.verification_distance_m}m` : '';
        locHtml = `<span class="dt-badge green" style="font-size:9px; padding:1px 4px;" title="Delivered at verified location (${distLabel})">📍✓</span>`;
    } else if (o.has_verified_location && !o.delivered_at_verified) {
        const distLabel = o.verification_distance_m !== null
            ? (o.verification_distance_m >= 1000 ? `${(o.verification_distance_m/1000).toFixed(1)}km away` : `${o.verification_distance_m}m away`)
            : 'no GPS';
        locHtml = `<span class="dt-badge red" style="font-size:9px; padding:1px 4px;" title="Not at verified location (${distLabel})">📍✗</span>`;
    } else {
        locHtml = `<span class="dt-badge gray" style="font-size:9px; padding:1px 4px;" title="No verified location set">📍—</span>`;
    }

    const regionHtml = o.delivery_region ? `<span><span class="dt-detail-label">Region:</span> <span class="dt-detail-value">${o.delivery_region}</span></span>` : '';
    const addressHtml = o.customer_address ? `<span><span class="dt-detail-label">Address:</span> <span class="dt-detail-value">${o.customer_address}</span></span>` : '';
    const cityHtml = o.customer_city ? `<span><span class="dt-detail-label">City:</span> <span class="dt-detail-value">${o.customer_city}</span></span>` : '';

    return `
    <div class="dt-order-row" onclick="this.querySelector('.dt-order-details').classList.toggle('open')">
        <div class="dt-order-main">
            <div class="dt-order-left">
                <div class="dt-order-seq ${seqClass}" title="${seqTitle}">${o.actual_sequence}</div>
                <span class="dt-order-num">${o.order_number}</span>
                <span class="dt-order-customer">${o.customer_name}${o.customer_city ? ' · ' + o.customer_city : ''}</span>
                ${priorityHtml}
                ${locHtml}
            </div>
            <div class="dt-order-right">
                ${o.ofd_display ? `<span class="dt-time" title="Marked out for delivery">📤 ${o.ofd_display}</span>` : ''}
                <span class="dt-time" title="Delivered at">✅ ${o.delivered_at_display}</span>
                ${etaHtml}
                <span class="dt-amount ${o.payment_type}">${o.payment_type === 'cash' ? '💵' : '💳'} Rs.${Math.round(o.amount).toLocaleString()}</span>
            </div>
        </div>
        <div class="dt-order-details">${addressHtml}${cityHtml}${regionHtml}</div>
    </div>`;
}

function dtRenderOrders(orders, showDispatchInfo) {
    if (!orders || orders.length === 0) return '<div style="padding:12px 16px; color:#6b7280; font-size:12px;">No orders</div>';
    return orders.map(o => dtRenderSingleOrder(o, showDispatchInfo)).join('');
}

// Geocode customer address
function geocodeCustomer(customerId, customerName) {
    if (!confirm(`Re-geocode address for ${customerName}?\n\nThis will update the location based on the stored address.`)) {
        return;
    }
    
    fetch(`/customers/${customerId}/geocode-single`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`✅ Geocoded successfully!\n\nLocation: ${data.latitude}, ${data.longitude}`);
            // Refresh the map
            loadOpenOrders();
        } else {
            alert('❌ Geocoding failed: ' + (data.message || 'Address could not be geocoded'));
        }
    })
    .catch(error => {
        console.error('Error geocoding:', error);
        alert('❌ Error geocoding address');
    });
}
</script>
@endsection
