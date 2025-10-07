@extends('layouts.app')

@section('title', 'Order #' . ($order->order_number ?? $order->id) . ' - Status History')

@push('custom_css')
<style>
.history-container {
    background: #f8fafc;
    min-height: 100vh;
}

.history-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    margin-bottom: 24px;
}

.timeline {
    position: relative;
    padding-left: 40px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e5e7eb;
}

.timeline-item {
    position: relative;
    padding-bottom: 32px;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-marker {
    position: absolute;
    left: -28px;
    top: 4px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 2px solid white;
    box-shadow: 0 0 0 2px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: white;
    font-weight: bold;
}

.timeline-marker.current {
    width: 20px;
    height: 20px;
    left: -30px;
    box-shadow: 0 0 0 3px;
}

.timeline-content {
    background: #f9fafb;
    border-radius: 8px;
    padding: 16px;
    border-left: 4px solid;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    border: 1px solid;
    margin-bottom: 8px;
}

.status-badge.yellow { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.status-badge.orange { background: #fed7aa; color: #c2410c; border-color: #fb923c; }
.status-badge.blue { background: #dbeafe; color: #1d4ed8; border-color: #60a5fa; }
.status-badge.purple { background: #e9d5ff; color: #7c3aed; border-color: #a78bfa; }
.status-badge.green { background: #d1fae5; color: #065f46; border-color: #34d399; }
.status-badge.red { background: #fee2e2; color: #dc2626; border-color: #f87171; }
.status-badge.gray { background: #f3f4f6; color: #374151; border-color: #d1d5db; }

.order-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.summary-item {
    text-align: center;
    padding: 16px;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.summary-value {
    font-size: 20px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 4px;
}

.summary-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
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

.btn-outline {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.btn-outline:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}
</style>
@endpush

@section('content')
<div class="history-container">
    <div class="container-fixed">
        <!-- Header Section -->
        <div class="flex flex-wrap items-center lg:items-end justify-between gap-6 py-6">
            <div class="flex flex-col justify-center gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="ki-filled ki-time text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold leading-tight text-gray-900">Order #{{ $order->order_number ?? $order->id }}</h1>
                        <div class="flex items-center gap-2 text-sm font-medium text-gray-600 mt-1">
                            <i class="ki-filled ki-information-2 text-blue-500"></i>
                            Status change history and timeline
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <a href="/order-status/history" class="btn btn-outline">
                    <i class="ki-filled ki-left"></i>
                    Back to History
                </a>
                <a href="/orders/{{ $order->id }}" class="btn btn-primary">
                    <i class="ki-filled ki-eye"></i>
                    View Order
                </a>
            </div>
        </div>

        <!-- Order Summary -->
        <div class="history-card p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Order Summary</h2>
            <div class="order-summary">
                <div class="summary-item">
                    <div class="summary-value">{{ $order->customer_name ?? 'Unknown' }}</div>
                    <div class="summary-label">Customer</div>
                </div>
                <div class="summary-item">
                    <div class="summary-value">${{ number_format($order->total_price ?? 0, 2) }}</div>
                    <div class="summary-label">Total Amount</div>
                </div>
                <div class="summary-item">
                    <div class="summary-value">{{ $order->created_at ? $order->created_at->format('M j, Y') : 'N/A' }}</div>
                    <div class="summary-label">Order Date</div>
                </div>
                <div class="summary-item">
                    <div class="summary-value">
                        <span class="status-badge {{ getStatusColorClass($order->order_status) }}">
                            {{ getStatusIcon($order->order_status) }} {{ ucfirst(str_replace('_', ' ', $order->order_status ?? 'unknown')) }}
                        </span>
                    </div>
                    <div class="summary-label">Current Status</div>
                </div>
            </div>
        </div>

        <!-- Status History Timeline -->
        <div class="history-card p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-semibold text-gray-900">Status History</h2>
                <div class="text-sm text-gray-500">
                    {{ $order->statusHistory->count() }} status changes
                </div>
            </div>

            @if($order->statusHistory->count() > 0)
                <div class="timeline">
                    @foreach($order->statusHistory->sortByDesc('changed_at') as $index => $history)
                        <div class="timeline-item">
                            <div class="timeline-marker {{ $history->is_current ? 'current' : '' }}" 
                                 style="background-color: {{ getStatusColor($history->status_code) }}; box-shadow-color: {{ getStatusColor($history->status_code) }};">
                                {{ $history->status->icon ?? '?' }}
                            </div>
                            
                            <div class="timeline-content" style="border-left-color: {{ getStatusColor($history->status_code) }};">
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex-1">
                                        <h3 class="font-semibold text-gray-900">
                                            {{ $history->status->status_name ?? ucfirst(str_replace('_', ' ', $history->status_code)) }}
                                            @if($history->is_current)
                                                <span class="ml-2 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    Current
                                                </span>
                                            @endif
                                        </h3>
                                        <div class="flex items-center gap-3 mt-1">
                                            <p class="text-sm text-gray-600">
                                                <span id="timestamp-{{ $history->id }}">{{ $history->changed_at ? $history->changed_at->format('M j, Y g:i A') : 'Unknown time' }}</span>
                                                @if($history->changedBy)
                                                    • by {{ $history->changedBy->name }}
                                                @else
                                                    • by System
                                                @endif
                                            </p>
                                            @if($order->external_source !== 'shopify')
                                                <button onclick="openEditTimestampModal({{ $history->id }}, '{{ $history->changed_at ? $history->changed_at->format('Y-m-d\TH:i') : '' }}')" 
                                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 hover:text-blue-700 rounded border border-blue-200 transition-colors"
                                                        title="Edit timestamp">
                                                    <i class="ki-filled ki-pencil"></i>
                                                    <span>Edit</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                    
                                    @if($index === 0)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            Latest
                                        </span>
                                    @endif
                                </div>
                                
                                @if($history->notes)
                                    <div class="mt-3 p-3 bg-white rounded-md border border-gray-200">
                                        <p class="text-sm text-gray-700">
                                            <i class="ki-filled ki-note text-gray-400 mr-2"></i>
                                            {{ $history->notes }}
                                        </p>
                                    </div>
                                @endif
                                
                                @if($history->status && $history->status->description)
                                    <div class="mt-2 text-xs text-gray-500">
                                        {{ $history->status->description }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-12">
                    <div class="text-gray-400 mb-4">
                        <i class="ki-filled ki-time text-4xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No Status History</h3>
                    <p class="text-gray-500">This order doesn't have any status change history yet.</p>
                </div>
            @endif
        </div>

        <!-- Quick Actions -->
        @if($order->external_source !== 'shopify')
            <div class="history-card p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
                <div class="flex flex-wrap gap-3">
                    <button onclick="openStatusChangeModal()" class="btn btn-primary">
                        <i class="ki-filled ki-edit"></i>
                        Change Status
                    </button>
                    <a href="/orders/{{ $order->id }}/edit-tab" class="btn btn-outline">
                        <i class="ki-filled ki-pencil"></i>
                        Edit Order
                    </a>
                    <a href="/orders/{{ $order->id }}/invoice" class="btn btn-outline">
                        <i class="ki-filled ki-document"></i>
                        View Invoice
                    </a>
                </div>
            </div>
        @endif
    </div>
</div>

<!-- Status Change Modal -->
<div id="statusChangeModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        
        <!-- Modal Header -->
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Change Order Status</h3>
            <button onclick="closeStatusChangeModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 24px;">
            <div id="statusChangeAlert" style="display: none; margin-bottom: 16px;"></div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">New Status</label>
                <select id="newStatusSelect" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    <option value="">Select new status...</option>
                </select>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Notes (Optional)</label>
                <textarea id="statusChangeNotes" placeholder="Reason for status change..." style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical; min-height: 80px;"></textarea>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div style="padding: 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <button onclick="closeStatusChangeModal()" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                Cancel
            </button>
            <button id="changeStatusBtn" onclick="changeOrderStatus()" style="padding: 8px 24px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">
                Change Status
            </button>
        </div>
    </div>
</div>

<!-- Edit Timestamp Modal -->
<div id="editTimestampModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        
        <!-- Modal Header -->
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">
                <i class="ki-filled ki-calendar text-blue-600 mr-2"></i>
                Edit Status Timestamp
            </h3>
            <button onclick="closeEditTimestampModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 24px;">
            <div id="editTimestampAlert" style="display: none; margin-bottom: 16px;"></div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">
                    <i class="ki-filled ki-time mr-1"></i>
                    New Date & Time
                </label>
                <input type="datetime-local" 
                       id="editTimestampInput" 
                       style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; color: #111827;">
            </div>
            
            <div style="padding: 12px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; margin-bottom: 16px;">
                <p style="font-size: 13px; color: #92400e; margin: 0;">
                    <i class="ki-filled ki-information-2 mr-1"></i>
                    <strong>Note:</strong> If this becomes the latest timestamp, it will automatically become the current status and update the main order.
                </p>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div style="padding: 16px 24px; background-color: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 12px; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
            <button onclick="closeEditTimestampModal()" style="padding: 10px 20px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: 14px;">
                Cancel
            </button>
            <button id="saveTimestampBtn" onclick="saveTimestamp()" style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: 14px;">
                <i class="ki-filled ki-check mr-1"></i>
                Save Timestamp
            </button>
        </div>
    </div>
</div>
@endsection

@push('page_js')
<script>
const orderId = {{ $order->id }};

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    loadAvailableStatuses();
});

// Load available statuses
async function loadAvailableStatuses() {
    try {
        const response = await fetch('/order-status/api/statuses');
        const data = await response.json();
        
        if (data.success) {
            const select = document.getElementById('newStatusSelect');
            select.innerHTML = '<option value="">Select new status...</option>';
            
            data.data.forEach(status => {
                const option = document.createElement('option');
                option.value = status.status_code;
                option.textContent = `${status.icon} ${status.status_name}`;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading statuses:', error);
    }
}

// Open status change modal
function openStatusChangeModal() {
    document.getElementById('statusChangeModal').style.display = 'flex';
    document.getElementById('newStatusSelect').value = '';
    document.getElementById('statusChangeNotes').value = '';
    hideStatusChangeAlert();
}

// Close status change modal
function closeStatusChangeModal() {
    document.getElementById('statusChangeModal').style.display = 'none';
}

// Change order status
async function changeOrderStatus() {
    const newStatus = document.getElementById('newStatusSelect').value;
    const notes = document.getElementById('statusChangeNotes').value;
    
    if (!newStatus) {
        showStatusChangeAlert('Please select a new status', 'error');
        return;
    }
    
    const changeBtn = document.getElementById('changeStatusBtn');
    const originalText = changeBtn.textContent;
    
    try {
        changeBtn.disabled = true;
        changeBtn.textContent = 'Changing...';
        
        const response = await fetch('/order-status/api/change-status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                order_id: orderId,
                status_code: newStatus,
                notes: notes
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showStatusChangeAlert('Status changed successfully!', 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showStatusChangeAlert(data.message || 'Failed to change status', 'error');
        }
    } catch (error) {
        console.error('Error changing status:', error);
        showStatusChangeAlert('An error occurred while changing status', 'error');
    } finally {
        changeBtn.disabled = false;
        changeBtn.textContent = originalText;
    }
}

// Show alert in status change modal
function showStatusChangeAlert(message, type) {
    const alertContainer = document.getElementById('statusChangeAlert');
    const alertClass = type === 'success' ? 'background: #d1fae5; color: #065f46; border: 1px solid #34d399;' : 'background: #fee2e2; color: #dc2626; border: 1px solid #f87171;';
    
    alertContainer.innerHTML = `
        <div style="padding: 12px 16px; border-radius: 8px; font-size: 14px; ${alertClass}">
            ${message}
        </div>
    `;
    alertContainer.style.display = 'block';
}

// Hide alert
function hideStatusChangeAlert() {
    document.getElementById('statusChangeAlert').style.display = 'none';
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.id === 'statusChangeModal') {
        closeStatusChangeModal();
    }
    if (e.target.id === 'editTimestampModal') {
        closeEditTimestampModal();
    }
});

// Edit Timestamp Modal Functions
let currentEditHistoryId = null;

function openEditTimestampModal(historyId, currentTimestamp) {
    currentEditHistoryId = historyId;
    document.getElementById('editTimestampInput').value = currentTimestamp;
    document.getElementById('editTimestampModal').style.display = 'flex';
    hideEditTimestampAlert();
}

function closeEditTimestampModal() {
    document.getElementById('editTimestampModal').style.display = 'none';
    currentEditHistoryId = null;
}

async function saveTimestamp() {
    const newTimestamp = document.getElementById('editTimestampInput').value;
    
    if (!newTimestamp) {
        showEditTimestampAlert('Please select a date and time', 'error');
        return;
    }
    
    const saveBtn = document.getElementById('saveTimestampBtn');
    const originalText = saveBtn.textContent;
    
    try {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';
        
        const response = await fetch(`/order-status/api/history/${currentEditHistoryId}/update-timestamp`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                changed_at: newTimestamp
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showEditTimestampAlert('Timestamp updated successfully! Page will reload...', 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showEditTimestampAlert(data.message || 'Failed to update timestamp', 'error');
        }
    } catch (error) {
        console.error('Error updating timestamp:', error);
        showEditTimestampAlert('An error occurred while updating timestamp', 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = originalText;
    }
}

function showEditTimestampAlert(message, type) {
    const alertContainer = document.getElementById('editTimestampAlert');
    const alertClass = type === 'success' ? 'background: #d1fae5; color: #065f46; border: 1px solid #34d399;' : 'background: #fee2e2; color: #dc2626; border: 1px solid #f87171;';
    
    alertContainer.innerHTML = `
        <div style="padding: 12px 16px; border-radius: 8px; font-size: 14px; ${alertClass}">
            ${message}
        </div>
    `;
    alertContainer.style.display = 'block';
}

function hideEditTimestampAlert() {
    document.getElementById('editTimestampAlert').style.display = 'none';
}
</script>
@endpush

@php
function getStatusColor($status) {
    $colorMap = [
        'new' => '#eab308',
        'on_hold' => '#f97316', 
        'processing' => '#3b82f6',
        'out_for_delivery' => '#8b5cf6',
        'delivered' => '#10b981',
        'cancelled' => '#ef4444',
        'refunded' => '#8b5cf6'
    ];
    return $colorMap[$status] ?? '#6b7280';
}

function getStatusColorClass($status) {
    $colorMap = [
        'new' => 'yellow',
        'on_hold' => 'orange',
        'processing' => 'blue', 
        'out_for_delivery' => 'purple',
        'delivered' => 'green',
        'cancelled' => 'red',
        'refunded' => 'purple'
    ];
    return $colorMap[$status] ?? 'gray';
}

function getStatusIcon($status) {
    $iconMap = [
        'new' => '⏳',
        'on_hold' => '⏸',
        'processing' => '⚡',
        'out_for_delivery' => '🚚',
        'delivered' => '✓',
        'cancelled' => '✕',
        'refunded' => '↩'
    ];
    return $iconMap[$status] ?? '?';
}
@endphp
