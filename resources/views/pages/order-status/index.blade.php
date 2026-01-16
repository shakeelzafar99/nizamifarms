@extends('layouts.app')

@section('title', 'Order Status Management')

@push('custom_css')
<style>
/* Order Status Management Styles */
.status-management-container {
    background: #f8fafc;
    min-height: 100vh;
}

.status-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.status-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: translateY(-1px);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid;
}

.status-badge.yellow { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.status-badge.orange { background: #fed7aa; color: #c2410c; border-color: #fb923c; }
.status-badge.blue { background: #dbeafe; color: #1d4ed8; border-color: #60a5fa; }
.status-badge.purple { background: #e9d5ff; color: #7c3aed; border-color: #a78bfa; }
.status-badge.green { background: #d1fae5; color: #065f46; border-color: #34d399; }
.status-badge.red { background: #fee2e2; color: #dc2626; border-color: #f87171; }
.status-badge.gray { background: #f3f4f6; color: #374151; border-color: #d1d5db; }

.drag-handle {
    cursor: grab;
    color: #9ca3af;
    transition: color 0.2s ease;
}

.drag-handle:hover {
    color: #6b7280;
}

.drag-handle:active {
    cursor: grabbing;
}

.sortable-ghost {
    opacity: 0.5;
}

.sortable-chosen {
    background: #f3f4f6;
}

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    max-width: 90vw;
    max-height: 90vh;
    overflow-y: auto;
    width: 600px;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.btn-outline {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.btn-outline:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 6px;
}

.form-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s ease;
}

.form-input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

.form-select {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 8px center;
    background-repeat: no-repeat;
    background-size: 16px 12px;
    padding-right: 40px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    text-align: center;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 14px;
    color: #6b7280;
    font-weight: 500;
}

.loading {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #6b7280;
}

.spinner {
    width: 16px;
    height: 16px;
    border: 2px solid #e5e7eb;
    border-top: 2px solid #2563eb;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 14px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #34d399;
}

.alert-error {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #f87171;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.table tbody tr:hover {
    background: #f9fafb;
}
</style>
@endpush

@section('content')
<div class="status-management-container">
    <div class="container-fixed">
        <!-- Header Section -->
        <div class="flex flex-wrap items-center lg:items-end justify-between gap-6 py-6">
            <div class="flex flex-col justify-center gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="ki-filled ki-setting-2 text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold leading-tight text-gray-900">Order Status Management</h1>
                        <div class="flex items-center gap-2 text-sm font-medium text-gray-600 mt-1">
                            <i class="ki-filled ki-information-2 text-purple-500"></i>
                            Manage order statuses and workflow
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <button onclick="openCreateStatusModal()" class="btn btn-primary">
                    <i class="ki-filled ki-plus"></i>
                    Add New Status
                </button>
                <a href="/order-status/history" class="btn btn-outline bg-white text-gray-700 border-gray-300 hover:bg-gray-50">
                    <i class="ki-filled ki-time"></i>
                    Status History
                </a>
                <button onclick="refreshData()" class="btn btn-secondary">
                    <i class="ki-filled ki-arrows-circle"></i>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Statistics Section -->
        <div class="stats-grid" id="statisticsContainer">
            <div class="stat-card">
                <div class="loading">
                    <div class="spinner"></div>
                    Loading statistics...
                </div>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <!-- Status Management Section -->
        <div class="status-card p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-semibold text-gray-900">Order Statuses</h2>
                <div class="text-sm text-gray-500">
                    Drag to reorder • Click to edit
                </div>
            </div>

            <div id="statusesContainer">
                <div class="loading">
                    <div class="spinner"></div>
                    Loading statuses...
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Status Modal -->
<div id="statusModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900" id="modalTitle">Create New Status</h3>
        </div>
        
        <form id="statusForm" class="p-6">
            <input type="hidden" id="statusId" name="id">
            
            <div class="form-group">
                <label class="form-label" for="statusCode">Status Code *</label>
                <input type="text" id="statusCode" name="status_code" class="form-input" 
                       pattern="[a-z_]+" placeholder="e.g., processing, delivered" required>
                <div class="text-xs text-gray-500 mt-1">Use lowercase letters and underscores only</div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="statusName">Display Name *</label>
                <input type="text" id="statusName" name="status_name" class="form-input" 
                       placeholder="e.g., Processing, Delivered" required>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="description">Description</label>
                <textarea id="description" name="description" class="form-input form-textarea" 
                          placeholder="Describe what this status means..."></textarea>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="form-group">
                    <label class="form-label" for="colorClass">Color Theme</label>
                    <select id="colorClass" name="color_class" class="form-input form-select">
                        <option value="yellow">Yellow (Pending)</option>
                        <option value="orange">Orange (On Hold)</option>
                        <option value="blue">Blue (Processing)</option>
                        <option value="purple">Purple (Shipping)</option>
                        <option value="green">Green (Completed)</option>
                        <option value="red">Red (Cancelled)</option>
                        <option value="gray">Gray (Other)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="icon">Icon</label>
                    <select id="icon" name="icon" class="form-input form-select">
                        <option value="">Select an icon...</option>
                        <option value="⏳">⏳ Pending/Waiting</option>
                        <option value="🆕">🆕 New</option>
                        <option value="⏸️">⏸️ On Hold/Paused</option>
                        <option value="⚡">⚡ Processing</option>
                        <option value="🔄">🔄 In Progress</option>
                        <option value="📦">📦 Packing</option>
                        <option value="🚚">🚚 Out for Delivery</option>
                        <option value="?">? Delivery Question</option>
                        <option value="✅">✅ Delivered</option>
                        <option value="✓">✓ Completed</option>
                        <option value="❌">❌ Cancelled</option>
                        <option value="↩️">↩️ Refunded</option>
                        <option value="⚠️">⚠️ Warning/Issue</option>
                        <option value="📋">📋 Review</option>
                        <option value="💳">💳 Payment</option>
                        <option value="🔔">🔔 Notification</option>
                        <option value="📞">📞 Contact</option>
                        <option value="🏪">🏪 Store</option>
                        <option value="🎯">🎯 Priority</option>
                    </select>
                    <div class="text-xs text-gray-500 mt-1">Or type custom: <input type="text" id="iconCustom" class="form-input inline-block w-20 ml-1" placeholder="emoji" maxlength="5" onchange="if(this.value) document.getElementById('icon').value = this.value;"></div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="flex items-center gap-2">
                    <input type="checkbox" id="isFinal" name="is_final" class="rounded">
                    <span class="form-label mb-0">This is a final status (order complete)</span>
                </label>
            </div>
            
            <!-- Mobile Visibility Settings -->
            <div class="border-t border-gray-200 pt-4 mt-4">
                <h4 class="text-sm font-semibold text-gray-700 mb-3">📱 Mobile App Settings</h4>
                
                <div class="form-group">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" id="showInMobile" name="show_in_mobile" class="rounded" checked>
                        <span class="form-label mb-0">Show in Mobile App</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1">When unchecked, this status won't appear in the mobile app's status dropdown</p>
                </div>
                
                <div class="form-group mt-3" id="roleVisibilitySection">
                    <label class="form-label">Visible to Roles</label>
                    <div id="roleCheckboxes" class="max-h-32 overflow-y-auto border border-gray-200 rounded-lg p-2">
                        <!-- Populated by JavaScript -->
                        <p class="text-xs text-gray-400">Loading roles...</p>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Leave all unchecked to make visible to all roles</p>
                </div>
            </div>
        </form>
        
        <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
            <button type="button" onclick="closeStatusModal()" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="saveStatus()" class="btn btn-primary" id="saveStatusBtn">
                <span id="saveStatusText">Create Status</span>
                <div id="saveStatusSpinner" class="spinner" style="display: none;"></div>
            </button>
        </div>
    </div>
</div>

<!-- Bulk Status Change Modal -->
<div id="bulkStatusModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Bulk Status Change</h3>
        </div>
        
        <div class="p-6">
            <div class="form-group">
                <label class="form-label">Select Orders</label>
                <div id="bulkOrdersContainer" class="max-h-60 overflow-y-auto border border-gray-200 rounded-lg p-4">
                    <!-- Orders will be loaded here -->
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="bulkStatusSelect">New Status</label>
                <select id="bulkStatusSelect" class="form-input form-select">
                    <option value="">Select status...</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="bulkNotes">Notes (Optional)</label>
                <textarea id="bulkNotes" class="form-input form-textarea" 
                          placeholder="Reason for status change..."></textarea>
            </div>
        </div>
        
        <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
            <button type="button" onclick="closeBulkStatusModal()" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="executeBulkStatusChange()" class="btn btn-primary">
                Update Selected Orders
            </button>
        </div>
    </div>
</div>
@endsection

@push('page_js')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// Global variables
let statuses = [];
let statistics = [];
let sortable = null;

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    console.log('Order Status Management page loaded');
    setTimeout(() => {
        loadStatistics();
        loadStatuses();
    }, 100);
});

// Also try to load immediately if DOM is already ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePage);
} else {
    initializePage();
}

function initializePage() {
    console.log('Initializing Order Status Management');
    loadStatistics();
    loadStatuses();
    loadRoles();  // Load available roles for visibility settings
}

// Global roles array
let availableRoles = [];

// Load available roles for visibility settings
async function loadRoles() {
    try {
        const response = await fetch('/order-status/api/roles', {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        
        if (data.success) {
            availableRoles = data.roles;
            populateRoleCheckboxes();
        }
    } catch (error) {
        console.error('Error loading roles:', error);
    }
}

// Populate role checkboxes in the modal
function populateRoleCheckboxes(selectedRoles = []) {
    const container = document.getElementById('roleCheckboxes');
    if (!container) return;
    
    if (availableRoles.length === 0) {
        container.innerHTML = '<p class="text-xs text-gray-400">No roles available</p>';
        return;
    }
    
    container.innerHTML = availableRoles.map(role => `
        <label class="flex items-center gap-2 py-1 hover:bg-gray-50 rounded px-1 cursor-pointer">
            <input type="checkbox" 
                   class="role-checkbox rounded" 
                   value="${role.id}" 
                   data-role-name="${role.urole_name}"
                   ${selectedRoles.includes(role.id) ? 'checked' : ''}>
            <span class="text-sm text-gray-700">${role.urole_name}</span>
        </label>
    `).join('');
}

// Load statistics
async function loadStatistics() {
    try {
        const response = await fetch('/order-status/api/statistics', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        });
        const data = await response.json();
        
        if (data.success) {
            statistics = data.data;
            renderStatistics();
        } else {
            showAlert('Failed to load statistics', 'error');
        }
    } catch (error) {
        console.error('Error loading statistics:', error);
        showAlert('Error loading statistics', 'error');
    }
}

// Render statistics
function renderStatistics() {
    const container = document.getElementById('statisticsContainer');
    
    if (statistics.length === 0) {
        container.innerHTML = '<div class="stat-card"><div class="text-gray-500">No data available</div></div>';
        return;
    }
    
    container.innerHTML = statistics.map(stat => `
        <div class="stat-card">
            <div class="stat-value">${stat.order_count || 0}</div>
            <div class="stat-label">
                <span class="status-badge ${stat.color_class}">
                    ${stat.icon} ${stat.status_name}
                </span>
            </div>
            <div class="text-xs text-gray-500 mt-2">
                Total: $${parseFloat(stat.total_value || 0).toFixed(2)}
            </div>
        </div>
    `).join('');
}

// Load statuses
async function loadStatuses() {
    try {
        const response = await fetch('/order-status/api/statuses', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        });
        const data = await response.json();
        
        if (data.success) {
            statuses = data.data;
            renderStatuses();
        } else {
            showAlert('Failed to load statuses', 'error');
        }
    } catch (error) {
        console.error('Error loading statuses:', error);
        showAlert('Error loading statuses', 'error');
    }
}

// Render statuses
function renderStatuses() {
    const container = document.getElementById('statusesContainer');
    
    if (statuses.length === 0) {
        container.innerHTML = '<div class="text-gray-500 text-center py-8">No statuses found</div>';
        return;
    }
    
    container.innerHTML = `
        <div id="statusesList" class="space-y-3">
            ${statuses.map(status => `
                <div class="status-item flex items-center gap-4 p-4 bg-gray-50 rounded-lg border" data-id="${status.id}">
                    <div class="drag-handle">
                        <i class="ki-filled ki-menu text-lg"></i>
                    </div>
                    
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="status-badge ${status.color_class}">
                                ${status.icon} ${status.status_name}
                            </span>
                            <code class="text-xs bg-gray-200 px-2 py-1 rounded">${status.status_code}</code>
                            ${status.is_final ? '<span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">Final</span>' : ''}
                            ${!status.is_active ? '<span class="text-xs bg-red-100 text-red-800 px-2 py-1 rounded">Inactive</span>' : ''}
                            ${status.show_in_mobile === false ? '<span class="text-xs bg-orange-100 text-orange-800 px-2 py-1 rounded">📵 Hidden from Mobile</span>' : ''}
                            ${status.visible_to_roles && status.visible_to_roles.length > 0 ? '<span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded">🔒 Role-restricted</span>' : ''}
                        </div>
                        ${status.description ? `<div class="text-sm text-gray-600">${status.description}</div>` : ''}
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-500">Order: ${status.sequence_order}</span>
                        <button onclick="editStatus(${status.id})" class="btn btn-sm bg-blue-100 text-blue-700 hover:bg-blue-200">
                            <i class="ki-filled ki-pencil"></i>
                            Edit
                        </button>
                        <button onclick="deleteStatus(${status.id})" class="btn btn-sm bg-red-100 text-red-700 hover:bg-red-200">
                            <i class="ki-filled ki-trash"></i>
                            Delete
                        </button>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
    
    // Initialize sortable
    initializeSortable();
}

// Initialize sortable functionality
function initializeSortable() {
    const statusesList = document.getElementById('statusesList');
    if (!statusesList) return;
    
    sortable = new Sortable(statusesList, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function(evt) {
            const statusOrder = Array.from(statusesList.children).map(item => 
                parseInt(item.getAttribute('data-id'))
            );
            updateStatusOrder(statusOrder);
        }
    });
}

// Update status order
async function updateStatusOrder(statusOrder) {
    try {
        const response = await fetch('/order-status/api/reorder', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ status_order: statusOrder })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('Status order updated successfully', 'success');
            loadStatuses(); // Reload to get updated sequence numbers
        } else {
            showAlert(data.message || 'Failed to update status order', 'error');
            loadStatuses(); // Reload to reset order
        }
    } catch (error) {
        console.error('Error updating status order:', error);
        showAlert('Error updating status order', 'error');
        loadStatuses(); // Reload to reset order
    }
}

// Modal functions
function openCreateStatusModal() {
    document.getElementById('modalTitle').textContent = 'Create New Status';
    document.getElementById('saveStatusText').textContent = 'Create Status';
    document.getElementById('statusForm').reset();
    document.getElementById('statusId').value = '';
    document.getElementById('iconCustom').value = '';  // Reset custom icon field
    document.getElementById('showInMobile').checked = true;  // Default to shown in mobile
    populateRoleCheckboxes([]);  // Clear role selections
    document.getElementById('statusModal').style.display = 'flex';
}

function editStatus(statusId) {
    const status = statuses.find(s => s.id === statusId);
    if (!status) return;
    
    document.getElementById('modalTitle').textContent = 'Edit Status';
    document.getElementById('saveStatusText').textContent = 'Update Status';
    document.getElementById('statusId').value = status.id;
    document.getElementById('statusCode').value = status.status_code;
    document.getElementById('statusName').value = status.status_name;
    document.getElementById('description').value = status.description || '';
    document.getElementById('colorClass').value = status.color_class || 'gray';
    document.getElementById('isFinal').checked = status.is_final;
    
    // Mobile visibility settings
    document.getElementById('showInMobile').checked = status.show_in_mobile !== false;  // Default true if not set
    
    // Role visibility - parse if string, otherwise use as-is
    let selectedRoles = status.visible_to_roles || [];
    if (typeof selectedRoles === 'string') {
        try {
            selectedRoles = JSON.parse(selectedRoles);
        } catch (e) {
            selectedRoles = [];
        }
    }
    populateRoleCheckboxes(selectedRoles);
    
    // Handle icon - check if it's in the dropdown options
    const iconSelect = document.getElementById('icon');
    const iconCustom = document.getElementById('iconCustom');
    const statusIcon = status.icon || '';
    
    // Try to find in dropdown
    let foundInDropdown = false;
    for (let option of iconSelect.options) {
        if (option.value === statusIcon) {
            iconSelect.value = statusIcon;
            iconCustom.value = '';
            foundInDropdown = true;
            break;
        }
    }
    
    // If not found in dropdown, put in custom field
    if (!foundInDropdown && statusIcon) {
        iconSelect.value = '';
        iconCustom.value = statusIcon;
    } else if (!statusIcon) {
        iconSelect.value = '';
        iconCustom.value = '';
    }
    
    document.getElementById('statusModal').style.display = 'flex';
}

function closeStatusModal() {
    document.getElementById('statusModal').style.display = 'none';
}

// Save status
async function saveStatus() {
    const statusId = document.getElementById('statusId').value;
    
    // Collect form data as JSON (better for emoji handling)
    const statusCode = document.getElementById('statusCode').value.trim();
    const statusName = document.getElementById('statusName').value.trim();
    const description = document.getElementById('description').value.trim();
    const colorClass = document.getElementById('colorClass').value;
    const iconCustom = document.getElementById('iconCustom').value.trim();
    const iconSelect = document.getElementById('icon').value;
    const icon = iconCustom || iconSelect; // Custom icon takes priority
    const isFinal = document.getElementById('isFinal').checked;
    
    // Mobile visibility settings
    const showInMobile = document.getElementById('showInMobile').checked;
    const selectedRoles = Array.from(document.querySelectorAll('.role-checkbox:checked'))
        .map(cb => parseInt(cb.value));
    
    // Validate
    if (!statusCode) {
        showAlert('Status code is required', 'error');
        return;
    }
    if (!/^[a-z_]+$/.test(statusCode)) {
        showAlert('Status code must be lowercase letters and underscores only', 'error');
        return;
    }
    if (!statusName) {
        showAlert('Display name is required', 'error');
        return;
    }
    
    // Show loading
    document.getElementById('saveStatusText').style.display = 'none';
    document.getElementById('saveStatusSpinner').style.display = 'block';
    document.getElementById('saveStatusBtn').disabled = true;
    
    try {
        const url = statusId ? `/order-status/api/statuses/${statusId}` : '/order-status/api/statuses';
        const method = statusId ? 'PUT' : 'POST';
        
        const payload = {
            status_code: statusCode,
            status_name: statusName,
            description: description || null,
            color_class: colorClass,
            icon: icon || null,
            is_final: isFinal,
            show_in_mobile: showInMobile,
            visible_to_roles: selectedRoles.length > 0 ? selectedRoles : null  // null = visible to all
        };
        
        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(payload)
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert(data.message || 'Status saved successfully', 'success');
            closeStatusModal();
            loadStatuses();
            loadStatistics();
        } else {
            // Show detailed error message
            let errorMsg = data.message || 'Failed to save status';
            if (data.errors) {
                errorMsg = Object.values(data.errors).flat().join(', ');
            }
            showAlert(errorMsg, 'error');
        }
    } catch (error) {
        console.error('Error saving status:', error);
        showAlert('Error saving status: ' + error.message, 'error');
    } finally {
        // Hide loading
        document.getElementById('saveStatusText').style.display = 'block';
        document.getElementById('saveStatusSpinner').style.display = 'none';
        document.getElementById('saveStatusBtn').disabled = false;
    }
}

// Delete status
async function deleteStatus(statusId) {
    if (!confirm('Are you sure you want to delete this status? This action cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch(`/order-status/api/statuses/${statusId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert(data.message || 'Status deleted successfully', 'success');
            loadStatuses();
            loadStatistics();
        } else {
            showAlert(data.message || 'Failed to delete status', 'error');
        }
    } catch (error) {
        console.error('Error deleting status:', error);
        showAlert('Error deleting status', 'error');
    }
}

// Utility functions
function showAlert(message, type = 'success') {
    const container = document.getElementById('alertContainer');
    const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
    
    container.innerHTML = `
        <div class="alert ${alertClass}">
            ${message}
        </div>
    `;
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        container.innerHTML = '';
    }, 5000);
}

function refreshData() {
    loadStatistics();
    loadStatuses();
    showAlert('Data refreshed', 'success');
}

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
    }
});
</script>
@endpush
