@extends('layouts.app')

@section('title', 'Customers')

<script>
// Define functions immediately to avoid "not defined" errors
window.viewCustomer = function(id) {
    console.log('Opening customer details for ID:', id);
    const modal = document.getElementById('viewCustomerModal');
    const content = document.getElementById('viewCustomerContent');
    
    if (!modal) {
        console.error('View customer modal not found');
        return;
    }
    
    if (!content) {
        console.error('View customer content element not found');
        return;
    }
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch customer details
    fetch(`/customers/${id}`)
    .then(response => {
        console.log('Customer fetch response:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Customer data received:', data);
        if (data.success) {
            const customer = data.customer;
            
            let html = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Personal Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Full Name</label>
                                <p style="margin: 4px 0 0 0; font-weight: 500;">${customer.first_name} ${customer.last_name}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Email</label>
                                <p style="margin: 4px 0 0 0;">${customer.email || 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Phone (Original)</label>
                                <p style="margin: 4px 0 0 0;">${customer.phone_original || customer.phone || 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Phone (Normalized)</label>
                                <p style="margin: 4px 0 0 0;">${customer.phone_normalized || 'N/A'}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Company</label>
                                <p style="margin: 4px 0 0 0;">${customer.company || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Address Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Address</label>
                                <p style="margin: 4px 0 0 0;">${customer.address1 || 'N/A'}</p>
                                ${customer.address2 ? `<p style="margin: 4px 0 0 0;">${customer.address2}</p>` : ''}
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">City</label>
                                <p style="margin: 4px 0 0 0;">${customer.city || 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Province</label>
                                <p style="margin: 4px 0 0 0;">${customer.province || 'N/A'}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Postal Code</label>
                                <p style="margin: 4px 0 0 0;">${customer.postal_code || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Statistics</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Total Orders</label>
                                <p style="margin: 4px 0 0 0; font-size: 18px; font-weight: 600; color: #2563eb;">${customer.total_orders || 0}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Total Spent</label>
                                <p style="margin: 4px 0 0 0; font-size: 18px; font-weight: 600; color: #059669;">PKR ${(customer.total_spent || 0).toLocaleString()}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Last Order</label>
                                <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 500;">${customer.last_order_date ? window.formatDateLocal(customer.last_order_date) : 'Never'}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Notes</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                        <p style="margin: 0; color: #374151; white-space: pre-wrap;">${customer.notes || 'No notes available'}</p>
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Recent Orders (Last 10)</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        ${customer.orders && customer.orders.length > 0 ? `
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="border-bottom: 2px solid #e5e7eb;">
                                            <th style="padding: 8px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Order #</th>
                                            <th style="padding: 8px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Date</th>
                                            <th style="padding: 8px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Status</th>
                                            <th style="padding: 8px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Source</th>
                                            <th style="padding: 8px; text-align: right; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Total</th>
                                            <th style="padding: 8px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${customer.orders.map(order => {
                                            const statusColor = order.order_status === 'completed' ? '#059669' : 
                                                              order.order_status === 'pending' ? '#d97706' : 
                                                              order.order_status === 'cancelled' ? '#dc2626' : '#6b7280';
                                            const sourceColor = order.external_source === 'shopify' ? '#7c3aed' :
                                                              order.external_source === 'woocommerce' ? '#2563eb' :
                                                              order.external_source === 'webapp' ? '#059669' : '#6b7280';
                                            return `
                                                <tr style="border-bottom: 1px solid #e5e7eb; hover:background-color: #ffffff;">
                                                    <td style="padding: 10px 8px; font-weight: 600; color: #1f2937; font-size: 13px;">#${order.order_number || order.id}</td>
                                                    <td style="padding: 10px 8px; color: #6b7280; font-size: 13px;">${window.formatDateLocal(order.order_date)}</td>
                                                    <td style="padding: 10px 8px; font-size: 13px;">
                                                        <span style="display: inline-flex; align-items: center; padding: 2px 6px; border-radius: 12px; font-size: 11px; font-weight: 500; background-color: ${statusColor}20; color: ${statusColor};">
                                                            ${order.order_status ? order.order_status.charAt(0).toUpperCase() + order.order_status.slice(1) : 'N/A'}
                                                        </span>
                                                    </td>
                                                    <td style="padding: 10px 8px; font-size: 13px;">
                                                        <span style="display: inline-flex; align-items: center; padding: 2px 6px; border-radius: 12px; font-size: 11px; font-weight: 500; background-color: ${sourceColor}20; color: ${sourceColor};">
                                                            ${order.external_source || 'Direct'}
                                                        </span>
                                                    </td>
                                                    <td style="padding: 10px 8px; text-align: right; font-weight: 600; color: #1f2937; font-size: 13px;">PKR ${(order.total_price || 0).toLocaleString()}</td>
                                                    <td style="padding: 10px 8px; text-align: center;">
                                                        <button onclick="viewOrderDetailsFromCustomer(${order.id})" 
                                                                style="padding: 4px 8px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 11px; margin-right: 4px;"
                                                                onmouseover="this.style.background='#2563eb'" 
                                                                onmouseout="this.style.background='#3b82f6'">
                                                            View
                                                        </button>
                                                        <button onclick="window.open('/orders/${order.id}/invoice', '_blank')" 
                                                                style="padding: 4px 8px; background: #059669; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 11px;"
                                                                onmouseover="this.style.background='#047857'" 
                                                                onmouseout="this.style.background='#059669'">
                                                            Invoice
                                                        </button>
                                                    </td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : `
                            <p style="text-align: center; color: #6b7280; padding: 20px; font-size: 14px;">No orders found for this customer.</p>
                        `}
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
        }
    })
    .catch(error => {
        console.error('Error fetching customer:', error);
        content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
    });
};

// Customer column management (reusing existing pattern)
const defaultCustomerColumns = [
    { id: 'id', visible: true, fixed: true },
    { id: 'name', visible: true, fixed: true },
    { id: 'contact', visible: true, fixed: false },
    { id: 'location', visible: true, fixed: false },
    { id: 'total_orders', visible: true, fixed: false },
    { id: 'total_spent', visible: true, fixed: false },
    { id: 'status', visible: true, fixed: false },
    { id: 'first_order_date', visible: true, fixed: false },
    { id: 'last_order_date', visible: true, fixed: false },
    { id: 'actions', visible: true, fixed: true }
];

let currentCustomerColumns = JSON.parse(localStorage.getItem('customerTableColumns')) || defaultCustomerColumns;

// Clean up any corrupted data on initialization
currentCustomerColumns = currentCustomerColumns.filter(col => col && col.id && typeof col.id === 'string');

const availableCustomerColumns = {
    'id': { label: 'ID', fixed: true },
    'name': { label: 'Customer', fixed: true },
    'contact': { label: 'Contact', fixed: false },
    'location': { label: 'Location', fixed: false },
    'total_orders': { label: 'Orders', fixed: false },
    'total_spent': { label: 'Total Spent', fixed: false },
    'status': { label: 'Status', fixed: false },
    'first_order_date': { label: 'First Order', fixed: false },
    'last_order_date': { label: 'Last Order', fixed: false },
    'first_name': { label: 'First Name', fixed: false },
    'last_name': { label: 'Last Name', fixed: false },
    'email': { label: 'Email', fixed: false },
    'company': { label: 'Company', fixed: false },
    'phone': { label: 'Phone', fixed: false },
    'phone_original': { label: 'Original Phone', fixed: false },
    'phone_normalized': { label: 'Normalized Phone', fixed: false },
    'address1': { label: 'Address Line 1', fixed: false },
    'address2': { label: 'Address Line 2', fixed: false },
    'city': { label: 'City', fixed: false },
    'province': { label: 'Province', fixed: false },
    'postal_code': { label: 'Postal Code', fixed: false },
    'country': { label: 'Country', fixed: false },
    'notes': { label: 'Notes', fixed: false },
    'latitude': { label: 'Latitude', fixed: false },
    'longitude': { label: 'Longitude', fixed: false },
    'external_customer_ids': { label: 'External IDs', fixed: false },
    'is_active': { label: 'Active Status', fixed: false },
    'created_at': { label: 'Created Date', fixed: false },
    'updated_at': { label: 'Updated Date', fixed: false },
    'created_by': { label: 'Created By', fixed: false },
    'updated_by': { label: 'Updated By', fixed: false },
    'actions': { label: 'Actions', fixed: true }
};

window.openColumnSettings = function() {
    const modal = document.getElementById('columnSettingsModal');
    const columnList = document.getElementById('columnList');
    
    if (!modal || !columnList) {
        console.error('Column settings modal elements not found');
        return;
    }
    
    renderCustomerColumnSettings();
    modal.style.display = 'block';
};

function renderCustomerColumnSettings() {
    const columnList = document.getElementById('columnList');
    columnList.innerHTML = '';
    
    // First render columns in the order they appear in currentCustomerColumns
    currentCustomerColumns.forEach(column => {
        if (!column || !column.id) return;
        
        const columnConfig = availableCustomerColumns[column.id];
        if (!columnConfig) return;
        
        renderColumnItem(column.id, columnConfig, column.visible, columnList);
    });
    
    // Then render any remaining columns that aren't in currentCustomerColumns yet
    Object.keys(availableCustomerColumns).forEach(columnId => {
        const columnConfig = availableCustomerColumns[columnId];
        if (!columnConfig) return;
        
        // Skip if already rendered above
        const alreadyRendered = currentCustomerColumns.find(col => col && col.id === columnId);
        if (alreadyRendered) return;
        
        renderColumnItem(columnId, columnConfig, false, columnList);
    });
}

function renderColumnItem(columnId, columnConfig, isVisible, columnList) {
    const item = document.createElement('div');
    item.className = 'column-item';
    item.draggable = !columnConfig.fixed;
    item.dataset.columnId = columnId;
    item.style.cssText = `
        display: flex; 
        align-items: center; 
        padding: 12px; 
        margin-bottom: 8px; 
        background: white; 
        border: 1px solid #e5e7eb; 
        border-radius: 6px; 
        cursor: ${columnConfig.fixed ? 'default' : 'grab'};
        user-select: none;
    `;
    
    item.innerHTML = `
        <div style="display: flex; align-items: center; width: 100%;">
            ${!columnConfig.fixed ? '<div style="margin-right: 12px; color: #9ca3af;"><svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg></div>' : ''}
            <input type="checkbox" ${isVisible ? 'checked' : ''} ${columnConfig.fixed ? 'disabled' : ''} 
                   onchange="toggleCustomerColumnVisibility('${columnId}')" 
                   style="margin-right: 12px;">
            <label style="flex: 1; font-weight: 500; color: ${columnConfig.fixed ? '#9ca3af' : '#374151'};">
                ${columnConfig.label} ${columnConfig.fixed ? '(Fixed)' : ''}
            </label>
        </div>
    `;
    
    if (!columnConfig.fixed) {
        item.addEventListener('dragstart', handleCustomerDragStart);
        item.addEventListener('dragover', handleCustomerDragOver);
        item.addEventListener('drop', handleCustomerDrop);
        item.addEventListener('dragend', handleCustomerDragEnd);
    }
    
    columnList.appendChild(item);
}

function toggleCustomerColumnVisibility(columnId) {
    // Don't allow toggling fixed columns
    if (availableCustomerColumns[columnId] && availableCustomerColumns[columnId].fixed) return;
    
    // Clean up any null entries first
    currentCustomerColumns = currentCustomerColumns.filter(col => col && col.id);
    
    const column = currentCustomerColumns.find(col => col && col.id === columnId);
    
    if (column) {
        // Column exists, toggle visibility
        column.visible = !column.visible;
    } else {
        // Column doesn't exist in current settings, add it as visible
        currentCustomerColumns.push({ id: columnId, visible: true, fixed: availableCustomerColumns[columnId] ? availableCustomerColumns[columnId].fixed : false });
    }
    
    saveCustomerColumnSettings();
}

function saveCustomerColumnSettings() {
    // Ensure we're saving valid data
    const validColumns = currentCustomerColumns.filter(col => col && col.id && typeof col.id === 'string');
    localStorage.setItem('customerTableColumns', JSON.stringify(validColumns));
    console.log('Customer column settings saved');
}

function resetCustomerColumnSettings() {
    currentCustomerColumns = [...defaultCustomerColumns];
    localStorage.removeItem('customerTableColumns');
    console.log('Customer column settings reset to default');
}

function applyCustomerColumnChanges() {
    saveCustomerColumnSettings();
    closeModal('columnSettingsModal');
    // Apply changes immediately without page reload
    renderCustomersTable();
}

// Drag and drop handlers for customer columns
let draggedCustomerItem = null;

function handleCustomerDragStart(e) {
    draggedCustomerItem = this;
    this.style.opacity = '0.5';
}

function handleCustomerDragOver(e) {
    e.preventDefault();
}

function handleCustomerDrop(e) {
    e.preventDefault();
    if (this !== draggedCustomerItem) {
        const allItems = Array.from(document.querySelectorAll('.column-item'));
        const draggedIndex = allItems.indexOf(draggedCustomerItem);
        const targetIndex = allItems.indexOf(this);
        
        // Reorder in currentCustomerColumns array
        const draggedColumn = currentCustomerColumns[draggedIndex];
        currentCustomerColumns.splice(draggedIndex, 1);
        currentCustomerColumns.splice(targetIndex, 0, draggedColumn);
        
        // Re-render
        renderCustomerColumnSettings();
        saveCustomerColumnSettings();
    }
}

function handleCustomerDragEnd(e) {
    this.style.opacity = '';
    draggedCustomerItem = null;
}

// Legacy function - now handled by toggleCustomerColumnVisibility

window.closeModal = function(modalId) {
    console.log('closeModal called with:', modalId);
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
};

window.addCustomerNote = function(id) {
    console.log('addCustomerNote called with:', id);
    const modal = document.getElementById('addNoteModal');
    const content = document.getElementById('addNoteContent');
    
    if (!modal) {
        console.error('Add note modal not found');
        return;
    }
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch customer details to get current notes
    fetch(`/customers/${id}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const customer = data.customer;
            
            let html = `
                <form id="addNoteForm" onsubmit="saveCustomerNote(event, ${id})">
                    <div style="margin-bottom: 16px;">
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Add Note for ${customer.first_name} ${customer.last_name}</h4>
                        <div style="background-color: #f9fafb; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                            <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Current Notes</label>
                            <p style="margin: 4px 0 0 0; color: #374151;">${customer.notes || 'No notes yet'}</p>
                        </div>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Add New Note</label>
                        <textarea name="notes" rows="4" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical;" placeholder="Enter your note here..." required></textarea>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 12px;">
                        <button type="button" onclick="closeModal('addNoteModal')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                            Cancel
                        </button>
                        <button type="submit" style="padding: 8px 16px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                            Save Note
                        </button>
                    </div>
                </form>
            `;
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
        }
    })
    .catch(error => {
        console.error('Error fetching customer:', error);
        content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
    });
};

window.saveCustomerNote = function(event, customerId) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    
    fetch(`/customers/${customerId}/notes`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('addNoteModal');
            // Refresh the page to show updated data
            window.location.reload();
        } else {
            alert('Error saving note: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error saving note:', error);
        alert('Error saving note');
    });
};

window.editCustomer = function(id) {
    console.log('editCustomer called with:', id);
    const modal = document.getElementById('editCustomerModal');
    const content = document.getElementById('editCustomerContent');
    
    if (!modal) {
        console.error('Edit customer modal not found');
        return;
    }
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch customer details
    fetch(`/customers/${id}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const customer = data.customer;
            
            let html = `
                <form id="editCustomerForm" onsubmit="saveCustomer(event, ${id})">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">First Name</label>
                            <input type="text" name="first_name" value="${customer.first_name || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" required>
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Last Name</label>
                            <input type="text" name="last_name" value="${customer.last_name || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" required>
                        </div>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Email</label>
                        <input type="email" name="email" value="${customer.email || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Phone</label>
                        <input type="text" name="phone" value="${customer.phone_original || customer.phone || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Company</label>
                        <input type="text" name="company" value="${customer.company || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Address Line 1</label>
                        <input type="text" name="address1" value="${customer.address1 || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Address Line 2</label>
                        <input type="text" name="address2" value="${customer.address2 || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">City</label>
                            <input type="text" name="city" value="${customer.city || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Province</label>
                            <input type="text" name="province" value="${customer.province || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Postal Code</label>
                            <input type="text" name="postal_code" value="${customer.postal_code || ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Notes</label>
                        <textarea name="notes" rows="3" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; resize: vertical;" placeholder="Customer notes...">${customer.notes || ''}</textarea>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 12px;">
                        <button type="button" onclick="closeModal('editCustomerModal')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                            Cancel
                        </button>
                        <button type="submit" style="padding: 8px 16px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                            Save Changes
                        </button>
                    </div>
                </form>
            `;
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
        }
    })
    .catch(error => {
        console.error('Error fetching customer:', error);
        content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
    });
};

window.saveCustomer = function(event, customerId) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    
    fetch(`/customers/${customerId}`, {
        method: 'PUT',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('editCustomerModal');
            // Refresh the page to show updated data
            window.location.reload();
        } else {
            alert('Error saving customer: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error saving customer:', error);
        alert('Error saving customer');
    });
};

window.deleteCustomer = function(id) {
    console.log('deleteCustomer called with:', id);
    // TODO: Implement delete functionality
};

window.clearFilters = function() {
    console.log('clearFilters called');
    document.getElementById('customerSearchInput').value = '';
    document.querySelector('select[name="city"]').value = '';
    document.querySelector('select[name="status"]').value = '';
    // Reload page to clear filters
    window.location.href = window.location.pathname;
};

// New AJAX-based customer search functionality
let customerSearchTimeout;
window.allCustomers = @json($customers->items());
window.filteredCustomers = [...window.allCustomers];

function clearCustomerFilters() {
    document.getElementById('customerSearchInput').value = '';
    document.getElementById('customerCityFilter').value = '';
    document.getElementById('customerStatusFilter').value = '';
    
    // Reset to original data
    window.filteredCustomers = [...window.allCustomers];
    renderCustomersTable();
}

function fetchFilteredCustomers() {
    const searchTerm = document.getElementById('customerSearchInput').value.trim();
    const cityFilter = document.getElementById('customerCityFilter').value;
    const statusFilter = document.getElementById('customerStatusFilter').value;
    
    // Show loading state
    showCustomerLoadingState();
    
    // Build query parameters
    const params = new URLSearchParams();
    if (searchTerm) params.append('search', searchTerm);
    if (cityFilter) params.append('city', cityFilter);
    if (statusFilter) params.append('status', statusFilter);
    
    // Make API call
    fetch(`/customers/filter?${params.toString()}`, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.filteredCustomers = data.customers;
            renderCustomersTable();
        } else {
            console.error('Filter error:', data.message);
            // Show empty state when search fails
            window.filteredCustomers = [];
            renderCustomersTable();
        }
    })
    .catch(error => {
        console.error('Filter request failed:', error);
        // Show empty state when search fails
        window.filteredCustomers = [];
        renderCustomersTable();
    })
    .finally(() => {
        hideCustomerLoadingState();
    });
}

function renderCustomersTable() {
    renderCustomerTableHeader();
    renderCustomerTableBody();
}

function renderCustomerTableHeader() {
    const thead = document.getElementById('table-header');
    if (!thead) return;
    
    let html = '<tr>';
    
    currentCustomerColumns.forEach(column => {
        if (!column.visible) return;
        
        const columnConfig = availableCustomerColumns[column.id];
        if (!columnConfig) return;
        
        const widthClass = getCustomerColumnWidth(column.id);
        html += '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider ' + widthClass + '">' + columnConfig.label + '</th>';
    });
    
    html += '</tr>';
    thead.innerHTML = html;
}

function renderCustomerTableBody() {
    const tbody = document.getElementById('table-body');
    const noResultsState = document.getElementById('no-results-state');
    
    if (window.filteredCustomers.length === 0) {
        tbody.style.display = 'none';
        noResultsState.classList.remove('hidden');
    } else {
        noResultsState.classList.add('hidden');
        tbody.style.display = '';
        
        let html = '';
        window.filteredCustomers.forEach(customer => {
            html += '<tr class="hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="viewCustomer(' + customer.id + ')">';
            
            currentCustomerColumns.forEach(column => {
                if (!column.visible) return;
                
                html += '<td class="px-6 py-4 whitespace-nowrap">';
                html += getCustomerCellContent(customer, column.id);
                html += '</td>';
            });
            
            html += '</tr>';
        });
        
        tbody.innerHTML = html;
    }
}

function getCustomerCellContent(customer, columnId) {
    switch (columnId) {
        case 'id':
            return '<span class="text-sm font-medium text-gray-500">#' + customer.id + '</span>';
            
        case 'name':
            let nameHtml = '<div class="flex flex-col">';
            nameHtml += '<span class="text-sm font-medium text-gray-900">' + customer.first_name + ' ' + customer.last_name + '</span>';
            if (customer.company) {
                nameHtml += '<span class="text-xs text-gray-500">' + customer.company + '</span>';
            }
            nameHtml += '</div>';
            return nameHtml;
            
        case 'contact':
            let contactHtml = '<div class="flex flex-col text-sm">';
            if (customer.email) {
                contactHtml += '<span class="text-gray-600 truncate max-w-[150px]" title="' + customer.email + '">' + customer.email + '</span>';
            }
            if (customer.phone_original || customer.phone) {
                contactHtml += '<span class="text-gray-500">' + (customer.phone_original || customer.phone) + '</span>';
            }
            contactHtml += '</div>';
            return contactHtml;
            
        case 'location':
            let locationHtml = '<div class="flex flex-col text-sm">';
            if (customer.city) {
                locationHtml += '<span class="text-gray-600">' + customer.city + '</span>';
            }
            if (customer.province) {
                locationHtml += '<span class="text-gray-500">' + customer.province + '</span>';
            }
            locationHtml += '</div>';
            return locationHtml || '<span class="text-sm text-gray-500">N/A</span>';
            
        case 'total_orders':
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 cursor-pointer hover:bg-blue-200 transition-colors" onclick="viewCustomerOrders(' + customer.id + ', \'' + customer.first_name + ' ' + customer.last_name + '\')" title="Click to view customer orders">' + (customer.total_orders || 0) + '</span>';
            
        case 'total_spent':
            return '<span class="text-sm font-medium text-gray-900">PKR ' + (customer.total_spent || 0).toLocaleString() + '</span>';
            
        case 'status':
            const isActive = customer.is_active;
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + (isActive ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800') + '"><div class="w-1.5 h-1.5 ' + (isActive ? 'bg-green-400' : 'bg-red-400') + ' rounded-full mr-1.5"></div>' + (isActive ? 'Active' : 'Inactive') + '</span>';
            
        case 'first_order_date':
            return customer.first_order_date ? '<span class="text-sm text-gray-600">' + window.formatDateLocal(customer.first_order_date) + '</span>' : '<span class="text-sm text-gray-400">Never</span>';
            
        case 'last_order_date':
            return customer.last_order_date ? '<span class="text-sm text-gray-600">' + window.formatDateLocal(customer.last_order_date) + '</span>' : '<span class="text-sm text-gray-400">Never</span>';
            
        case 'first_name':
            return '<span class="text-sm text-gray-900">' + (customer.first_name || 'N/A') + '</span>';
            
        case 'last_name':
            return '<span class="text-sm text-gray-900">' + (customer.last_name || 'N/A') + '</span>';
            
        case 'email':
            return '<span class="text-sm text-gray-600">' + (customer.email || 'N/A') + '</span>';
            
        case 'company':
            return '<span class="text-sm text-gray-900">' + (customer.company || 'N/A') + '</span>';
            
        case 'phone':
            return '<span class="text-sm text-gray-500">' + (customer.phone || 'N/A') + '</span>';
            
        case 'phone_original':
            return '<span class="text-sm text-gray-500">' + (customer.phone_original || 'N/A') + '</span>';
            
        case 'phone_normalized':
            return '<span class="text-sm text-gray-500">' + (customer.phone_normalized || 'N/A') + '</span>';
            
        case 'address1':
            return '<span class="text-sm text-gray-900">' + (customer.address1 || 'N/A') + '</span>';
            
        case 'address2':
            return '<span class="text-sm text-gray-900">' + (customer.address2 || 'N/A') + '</span>';
            
        case 'city':
            return '<span class="text-sm text-gray-900">' + (customer.city || 'N/A') + '</span>';
            
        case 'province':
            return '<span class="text-sm text-gray-900">' + (customer.province || 'N/A') + '</span>';
            
        case 'postal_code':
            return '<span class="text-sm text-gray-900">' + (customer.postal_code || 'N/A') + '</span>';
            
        case 'country':
            return '<span class="text-sm text-gray-900">' + (customer.country || 'N/A') + '</span>';
            
        case 'notes':
            const notes = customer.notes || '';
            const truncatedNotes = notes.length > 50 ? notes.substring(0, 50) + '...' : notes;
            return '<span class="text-sm text-gray-600" title="' + notes + '">' + (truncatedNotes || 'No notes') + '</span>';
            
        case 'latitude':
            return '<span class="text-sm text-gray-500">' + (customer.latitude || 'N/A') + '</span>';
            
        case 'longitude':
            return '<span class="text-sm text-gray-500">' + (customer.longitude || 'N/A') + '</span>';
            
        case 'external_customer_ids':
            return '<span class="text-sm text-gray-500">' + (customer.external_customer_ids || 'N/A') + '</span>';
            
        case 'is_active':
            return '<span class="text-sm text-gray-900">' + (customer.is_active ? 'Yes' : 'No') + '</span>';
            
        case 'created_at':
            return '<span class="text-sm text-gray-500">' + (customer.created_at ? window.formatDateLocal(customer.created_at) : 'N/A') + '</span>';
            
        case 'updated_at':
            return '<span class="text-sm text-gray-500">' + (customer.updated_at ? window.formatDateLocal(customer.updated_at) : 'N/A') + '</span>';
            
        case 'created_by':
            return '<span class="text-sm text-gray-500">' + (customer.created_by || 'N/A') + '</span>';
            
        case 'updated_by':
            return '<span class="text-sm text-gray-500">' + (customer.updated_by || 'N/A') + '</span>';
            
        case 'actions':
            let actionsHtml = '<div class="flex items-center gap-1" onclick="event.stopPropagation()">';
            actionsHtml += '<button onclick="addCustomerNote(' + customer.id + ')" class="inline-flex items-center p-1.5 border border-gray-300 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-50 transition-colors duration-150" title="Add Notes"><i class="ki-filled ki-note text-sm"></i></button>';
            actionsHtml += '<button onclick="createOrderForCustomer(' + customer.id + ')" class="inline-flex items-center p-1.5 border border-emerald-300 rounded-md text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors duration-150" title="Create Order"><i class="ki-filled ki-plus text-sm"></i></button>';
            actionsHtml += '<button onclick="editCustomer(' + customer.id + ')" class="inline-flex items-center p-1.5 border border-gray-300 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-50 transition-colors duration-150" title="Edit"><i class="ki-filled ki-pencil text-sm"></i></button>';
            if (customer.total_orders == 0) {
                actionsHtml += '<button onclick="deleteCustomer(' + customer.id + ')" class="inline-flex items-center p-1.5 border border-gray-300 rounded-md text-red-400 hover:text-red-500 hover:bg-red-50 transition-colors duration-150" title="Delete"><i class="ki-filled ki-trash text-sm"></i></button>';
            }
            actionsHtml += '</div>';
            return actionsHtml;
            
        default:
            return '<span class="text-sm text-gray-500">N/A</span>';
    }
}

function getCustomerColumnWidth(columnId) {
    switch (columnId) {
        case 'id': return 'w-16';
        case 'name': return 'min-w-[200px]';
        case 'contact': return 'w-40';
        case 'location': return 'w-32';
        case 'total_orders': return 'w-20';
        case 'total_spent': return 'w-32';
        case 'status': return 'w-24';
        case 'first_order_date': return 'w-32';
        case 'last_order_date': return 'w-32';
        case 'notes': return 'w-64';
        case 'address1': return 'w-48';
        case 'actions': return 'w-24';
        default: return 'w-32';
    }
}

function showCustomerLoadingState() {
    document.getElementById('table-body').style.display = 'none';
    document.getElementById('no-results-state').classList.add('hidden');
    document.getElementById('loading-state').classList.remove('hidden');
}

function hideCustomerLoadingState() {
    document.getElementById('loading-state').classList.add('hidden');
    document.getElementById('table-body').style.display = '';
}

// Initialize customer search functionality
document.addEventListener('DOMContentLoaded', function() {
    // Apply saved column settings on page load
    renderCustomersTable();
    
    // Check if we need to open a specific customer modal from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const viewCustomerId = urlParams.get('view_customer');
    if (viewCustomerId) {
        // Wait a moment for the table to render, then open the customer modal
        setTimeout(function() {
            viewCustomer(viewCustomerId);
            // Clean up the URL parameter
            const newUrl = window.location.pathname + (window.location.search.replace(/[?&]view_customer=\d+/, '').replace(/^&/, '?') || '');
            window.history.replaceState({}, '', newUrl);
        }, 500);
    }
    
    const searchInput = document.getElementById('customerSearchInput');
    const cityFilter = document.getElementById('customerCityFilter');
    const statusFilter = document.getElementById('customerStatusFilter');
    
    // Search functionality with debouncing
    searchInput.addEventListener('input', function() {
        clearTimeout(customerSearchTimeout);
        customerSearchTimeout = setTimeout(() => {
            const searchTerm = searchInput.value.trim();
            if (searchTerm.length > 2) {
                fetchFilteredCustomers();
            } else if (searchTerm.length === 0) {
                // Auto-clear when search box is empty
                clearCustomerFilters();
            } else {
                // Reset to current page data if search is too short but not empty
                window.filteredCustomers = [...window.allCustomers];
                renderCustomersTable();
            }
        }, 300);
    });
    
    // Filter functionality
    cityFilter.addEventListener('change', function() {
        fetchFilteredCustomers();
    });
    
    statusFilter.addEventListener('change', function() {
        fetchFilteredCustomers();
    });
});

window.formatDateLocal = function(dateString) {
    if (!dateString) return 'N/A';
    
    try {
        // Handle different date formats
        let date;
        if (dateString.includes('T')) {
            // ISO format
            date = new Date(dateString);
        } else if (dateString.includes(' ')) {
            // MySQL datetime format
            const [datePart, timePart] = dateString.split(' ');
            const [year, month, day] = datePart.split('-');
            const [hour, minute, second] = timePart.split(':');
            date = new Date(year, month - 1, day, hour, minute, second);
        } else {
            // Fallback
            date = new Date(dateString);
        }
        
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    } catch (error) {
        console.error('Date formatting error:', error);
        return dateString;
    }
};

window.viewCustomerOrders = function(customerId, customerName) {
    console.log('Opening orders for customer ID:', customerId);
    const modal = document.getElementById('customerOrdersModal');
    const content = document.getElementById('customerOrdersContent');
    const title = document.getElementById('customerOrdersTitle');
    
    if (!modal) {
        console.error('Customer orders modal not found');
        return;
    }
    
    if (!content) {
        console.error('Customer orders content element not found');
        return;
    }
    
    // Set title
    if (title) {
        title.textContent = `Orders for ${customerName}`;
    }
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin-top: 12px; color: #6b7280;">Loading orders...</p></div>';
    modal.style.display = 'block';
    
    // Fetch customer orders
    fetch(`/customers/${customerId}/orders`)
        .then(response => {
            console.log('Customer orders fetch response:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Customer orders data received:', data);
            if (data.success) {
                const orders = data.orders;
                
                if (orders.length === 0) {
                    content.innerHTML = '<div style="text-align: center; padding: 40px; color: #6b7280;">No orders found for this customer.</div>';
                    return;
                }
                
                let html = `
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background-color: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Order #</th>
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Date</th>
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Status</th>
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Items</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Total</th>
                                    <th style="padding: 12px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                orders.forEach(order => {
                    const statusColor = order.order_status === 'completed' ? '#059669' : 
                                      order.order_status === 'pending' ? '#d97706' : 
                                      order.order_status === 'cancelled' ? '#dc2626' : '#6b7280';
                    
                    html += `
                        <tr style="border-bottom: 1px solid #f3f4f6; hover:background-color: #f9fafb;">
                            <td style="padding: 12px; font-weight: 600; color: #1f2937;">#${order.order_number || order.id}</td>
                            <td style="padding: 12px; color: #6b7280;">${window.formatDateLocal(order.order_date)}</td>
                            <td style="padding: 12px;">
                                <span style="display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; background-color: ${statusColor}20; color: ${statusColor};">
                                    ${order.order_status ? order.order_status.charAt(0).toUpperCase() + order.order_status.slice(1) : 'N/A'}
                                </span>
                            </td>
                            <td style="padding: 12px; color: #6b7280;">${order.line_items_count || 0} items</td>
                            <td style="padding: 12px; text-align: right; font-weight: 600; color: #1f2937;">PKR ${(order.total_price || 0).toLocaleString()}</td>
                            <td style="padding: 12px; text-align: center;">
                                <button onclick="viewOrderDetailsFromCustomer(${order.id})" 
                                        style="padding: 6px 12px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; margin-right: 4px;"
                                        onmouseover="this.style.background='#2563eb'" 
                                        onmouseout="this.style.background='#3b82f6'">
                                    View
                                </button>
                                <button onclick="editOrderFromCustomer(${order.id})" 
                                        style="padding: 6px 12px; background: #059669; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;"
                                        onmouseover="this.style.background='#047857'" 
                                        onmouseout="this.style.background='#059669'">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 14px;">
                        Total: ${orders.length} orders • Total Spent: PKR ${orders.reduce((sum, order) => sum + (order.total_price || 0), 0).toLocaleString()}
                    </div>
                `;
                
                content.innerHTML = html;
            } else {
                content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer orders</div>';
            }
        })
        .catch(error => {
            console.error('Error fetching customer orders:', error);
            content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer orders</div>';
        });
};

// Functions to integrate with existing order modals (these will call the same functions used in orders page)
window.viewOrderDetailsFromCustomer = function(orderId) {
    // Close customer orders modal first
    window.closeModal('customerOrdersModal');
    
    // Check if the main order modal functions exist (from orders page)
    if (typeof window.viewOrderDetails === 'function') {
        window.viewOrderDetails(orderId);
    } else {
        // Fallback: redirect to orders page with specific order
        window.location.href = `/orders?search=${orderId}`;
    }
};

window.editOrderFromCustomer = function(orderId) {
    // Close customer orders modal first
    window.closeModal('customerOrdersModal');
    
    // Check if the main order edit functions exist (from orders page)
    if (typeof window.editOrder === 'function') {
        window.editOrder(orderId);
    } else if (typeof window.viewOrderDetails === 'function') {
        // Fallback to view details if edit doesn't exist
        window.viewOrderDetails(orderId);
    } else {
        // Fallback: redirect to orders page
        window.location.href = `/orders?search=${orderId}`;
    }
};

// Add the viewOrderDetails function from orders page
window.currentOrderId = null;

window.viewInvoice = function() {
    if (window.currentOrderId) {
        window.open(`/orders/${window.currentOrderId}/invoice`, '_blank');
    } else {
        console.error('No order ID available for invoice');
    }
};

window.viewOrderDetails = function(orderId) {
    console.log('View order details clicked for order:', orderId);
    window.currentOrderId = orderId; // Store the order ID for invoice viewing
    const modal = document.getElementById('viewOrderModal');
    const content = document.getElementById('viewOrderContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch order details via AJAX
    fetch(`/orders/${orderId}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const order = data.order;
            const lineItems = data.lineItems || [];
            
            let html = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Order Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 8px;">
                                <span style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Order Number</span>
                                <p style="margin: 2px 0 0 0; font-weight: 500;">#${order.order_number || order.id}</p>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <span style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Date</span>
                                <p style="margin: 2px 0 0 0;">${window.formatDateLocal(order.order_date)}</p>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <span style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Status</span>
                                <p style="margin: 2px 0 0 0;">
                                    <span style="display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; background-color: #e0f2fe; color: #0277bd;">
                                        ${order.order_status ? order.order_status.charAt(0).toUpperCase() + order.order_status.slice(1) : 'N/A'}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <span style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Source</span>
                                <p style="margin: 2px 0 0 0;">${order.external_source || 'Direct'}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Customer Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 8px;">
                                <span style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Name</span>
                                <p style="margin: 2px 0 0 0; font-weight: 500;">${order.customer ? order.customer.first_name + ' ' + order.customer.last_name : 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <span style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Phone</span>
                                <p style="margin: 2px 0 0 0;">${order.customer ? order.customer.phone : 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <span style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Email</span>
                                <p style="margin: 2px 0 0 0;">${order.customer ? order.customer.email || 'N/A' : 'N/A'}</p>
                            </div>
                            <div>
                                <span style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Payment Method</span>
                                <p style="margin: 2px 0 0 0;">${order.payment_method || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Order Items</h4>
                    <div style="overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background-color: #f9fafb;">
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; border-bottom: 1px solid #e5e7eb;">Item</th>
                                    <th style="padding: 12px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; border-bottom: 1px solid #e5e7eb;">Qty</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; border-bottom: 1px solid #e5e7eb;">Price</th>
                                    <th style="padding: 12px; text-align: right; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; border-bottom: 1px solid #e5e7eb;">Total</th>
                                </tr>
                            </thead>
                            <tbody>`;
                            
            if (lineItems.length > 0) {
                lineItems.forEach(item => {
                    html += `
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px;">
                                <div>
                                    <p style="margin: 0; font-weight: 500; color: #1f2937;">${item.name || item.title || 'N/A'}</p>
                                    ${item.variant_title ? `<p style="margin: 2px 0 0 0; font-size: 12px; color: #6b7280;">${item.variant_title}</p>` : ''}
                                    ${item.sku ? `<p style="margin: 2px 0 0 0; font-size: 12px; color: #6b7280;">SKU: ${item.sku}</p>` : ''}
                                    ${item.vendor ? `<p style="margin: 2px 0 0 0; font-size: 12px; color: #6b7280;">Vendor: ${item.vendor}</p>` : ''}
                                </div>
                            </td>
                            <td style="padding: 12px; text-align: center; color: #6b7280;">${item.quantity || 0}</td>
                            <td style="padding: 12px; text-align: right; color: #6b7280;">PKR ${((item.unit_price || item.price || 0)).toLocaleString()}</td>
                            <td style="padding: 12px; text-align: right; font-weight: 600; color: #1f2937;">PKR ${(item.line_total || ((item.quantity || 0) * (item.unit_price || item.price || 0))).toLocaleString()}</td>
                        </tr>
                    `;
                });
            } else {
                html += `
                    <tr>
                        <td colspan="4" style="padding: 24px; text-align: center; color: #6b7280;">No items found</td>
                    </tr>
                `;
            }
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 300px; gap: 20px;">
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Notes</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <p style="margin: 0; color: #6b7280;">${order.notes || 'No notes available'}</p>
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Order Summary</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #6b7280;">Subtotal:</span>
                                <span style="font-weight: 500;">PKR ${(order.subtotal_price || order.total_price || 0).toLocaleString()}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #6b7280;">Tax:</span>
                                <span style="font-weight: 500;">PKR ${(order.total_tax || 0).toLocaleString()}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #6b7280;">Shipping:</span>
                                <span style="font-weight: 500;">PKR ${(order.total_shipping || 0).toLocaleString()}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #e5e7eb;">
                                <span style="font-weight: 600; color: #1f2937;">Total:</span>
                                <span style="font-weight: 600; color: #1f2937; font-size: 18px;">PKR ${(order.total_price || 0).toLocaleString()}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading order details</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading order details</div>';
    });
};
</script>

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-foreground">Customers</h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                Manage your customer database and relationships
            </div>
        </div>
        
        <div class="flex items-center gap-2.5">
            <div class="flex items-center gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                    <span>{{ $stats['active_30_days'] }} 30-Day Active</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-orange-500 rounded-full"></div>
                    <span>{{ $stats['active_90_days'] }} 90-Day Active</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                    <span>{{ $stats['total_customers'] }} Total</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fixed">
    <div class="grid gap-3">
        <!-- Ultra-Compact Statistics Row -->
        <div class="bg-white rounded-lg border border-gray-200 p-3 mb-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-people text-blue-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">Total</span>
                            <span class="text-base font-bold text-gray-900 ml-1">{{ number_format($stats['total_customers']) }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-calendar text-green-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">30-Day Active</span>
                            <span class="text-base font-bold text-gray-900 ml-1">{{ number_format($stats['active_30_days']) }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-timer text-orange-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">90-Day Active</span>
                            <span class="text-base font-bold text-gray-900 ml-1">{{ number_format($stats['active_90_days']) }}</span>
                        </div>
                    </div>
                </div>
                <div class="text-xs text-gray-400">
                    {{ now()->format('M d, H:i') }}
                </div>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="card card-grid min-w-full">
            <div class="card-header flex-wrap gap-2">
                <h3 class="card-title font-medium text-sm">All Customers</h3>
                
                <div class="flex items-center gap-2 ml-auto">
                    <button onclick="openColumnSettings()" class="kt-btn kt-btn-sm kt-btn-outline">
                        <i class="ki-filled ki-setting-2"></i> Columns
                    </button>
                </div>
            </div>

            <!-- Compact Search and Filters Section -->
            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <div class="flex-1 min-w-48">
                        <div class="relative">
                            <input type="text" name="search" value="{{ request('search') }}" 
                                   placeholder="Search customers (name, phone, email)..." 
                                   class="input input-sm w-full pl-8"
                                   id="customerSearchInput">
                            <div class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                <i class="ki-filled ki-magnifier text-gray-400 text-sm"></i>
                            </div>
                        </div>
                    </div>
                    
                    <select name="city" class="select select-sm w-32 text-xs" id="customerCityFilter">
                        <option value="">All Cities</option>
                        @foreach($cities as $city)
                            <option value="{{ $city }}" {{ request('city') == $city ? 'selected' : '' }}>
                                {{ $city }}
                            </option>
                        @endforeach
                    </select>
                    
                    <select name="status" class="select select-sm w-28 text-xs" id="customerStatusFilter">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    
                    <button type="button" onclick="clearCustomerFilters()" class="kt-btn kt-btn-sm kt-btn-outline text-xs px-3" title="Clear all filters">
                        <i class="ki-filled ki-cross text-sm"></i>
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <!-- Loading State -->
                <div id="loading-state" class="hidden text-center py-12">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                    <p class="mt-2 text-sm text-gray-500">Loading customers...</p>
                </div>

                <!-- No Results State -->
                <div id="no-results-state" class="hidden text-center py-12">
                    <div class="text-gray-400 mb-4">
                        <i class="ki-filled ki-search text-4xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No customers found</h3>
                    <p class="text-gray-500 mb-4">Try adjusting your search or filter criteria.</p>
                    <button onclick="clearFilters()" class="kt-btn kt-btn-sm kt-btn-primary">
                        <i class="ki-filled ki-cross"></i> Clear Filters
                    </button>
                </div>

                <!-- Dynamic Table -->
                <div class="overflow-x-auto" id="table-container">
                    <table class="w-full text-sm" id="customers-table">
                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100 border-b" id="table-header">
                            <!-- Fallback headers -->
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">#</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[200px]">Customer</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-40">Contact</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Location</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">Orders</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Total Spent</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">First Order</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Last Order</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="table-body">
                            <!-- Fallback: Show static table if JavaScript fails -->
                            @if($customers->count() > 0)
                                @foreach ($customers as $customer)
                                <tr class="hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="viewCustomer({{ $customer->id }})">
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <span class="text-sm font-medium text-gray-500">#{{ $customer->id }}</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <span class="text-sm font-medium text-gray-900">
                                                {{ $customer->first_name }} {{ $customer->last_name }}
                                            </span>
                                            @if($customer->company)
                                                <span class="text-xs text-gray-500">{{ $customer->company }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col text-sm">
                                            @if($customer->email)
                                                <span class="text-gray-600 truncate max-w-[150px]" title="{{ $customer->email }}">{{ $customer->email }}</span>
                                            @endif
                                            @if($customer->phone)
                                                <span class="text-gray-500">{{ $customer->phone }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col text-sm">
                                            @if($customer->city)
                                                <span class="text-gray-600">{{ $customer->city }}</span>
                                            @endif
                                            @if($customer->province)
                                                <span class="text-gray-500">{{ $customer->province }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 cursor-pointer hover:bg-blue-200 transition-colors" onclick="viewCustomerOrders({{ $customer->id }}, '{{ $customer->first_name }} {{ $customer->last_name }}')" title="Click to view customer orders">{{ $customer->total_orders }}</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <span class="text-sm font-medium text-gray-900">
                                            PKR {{ number_format($customer->total_spent, 0) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($customer->is_active)
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <div class="w-1.5 h-1.5 bg-green-400 rounded-full mr-1.5"></div>
                                                Active
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <div class="w-1.5 h-1.5 bg-red-400 rounded-full mr-1.5"></div>
                                                Inactive
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($customer->first_order_date)
                                            <span class="text-sm text-gray-600">
                                                {{ $customer->first_order_date->format('M d, Y') }}
                                            </span>
                                        @else
                                            <span class="text-sm text-gray-400">Never</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($customer->last_order_date)
                                            <span class="text-sm text-gray-600">
                                                {{ $customer->last_order_date->format('M d, Y') }}
                                            </span>
                                        @else
                                            <span class="text-sm text-gray-400">Never</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap" onclick="event.stopPropagation()">
                                        <div class="flex items-center gap-1">
                                            <button onclick="addCustomerNote({{ $customer->id }})" 
                                                    class="inline-flex items-center p-1.5 border border-gray-300 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-50 transition-colors duration-150" 
                                                    title="Add Notes">
                                                <i class="ki-filled ki-note text-sm"></i>
                                            </button>
                                            <button onclick="createOrderForCustomer({{ $customer->id }})" 
                                                    class="inline-flex items-center p-1.5 border border-emerald-300 rounded-md text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors duration-150" 
                                                    title="Create Order">
                                                <i class="ki-filled ki-plus text-sm"></i>
                                            </button>
                                            <button onclick="editCustomer({{ $customer->id }})" 
                                                    class="inline-flex items-center p-1.5 border border-gray-300 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-50 transition-colors duration-150" 
                                                    title="Edit">
                                                <i class="ki-filled ki-pencil text-sm"></i>
                                            </button>
                                            @if($customer->total_orders == 0)
                                                <button onclick="deleteCustomer({{ $customer->id }})" 
                                                        class="inline-flex items-center p-1.5 border border-gray-300 rounded-md text-red-400 hover:text-red-500 hover:bg-red-50 transition-colors duration-150" 
                                                        title="Delete">
                                                    <i class="ki-filled ki-trash text-sm"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            @else
                                <tr>
                                    <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                                        No customers found
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $customers->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Column Settings Modal -->
<div id="columnSettingsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999; overflow-y: auto;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 600px; max-height: 80vh; display: flex; flex-direction: column;">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Customize Columns</h3>
                <button onclick="closeModal('columnSettingsModal')" style="padding: 4px; border: none; background: none; cursor: pointer; color: #6b7280;">
                    <i class="ki-filled ki-cross text-xl"></i>
                </button>
            </div>
        </div>
        
        <!-- Modal Content -->
        <div style="padding: 20px; flex: 1; overflow-y: auto;">
            <div id="columnList" class="space-y-2">
                <!-- Column list will be populated by JavaScript -->
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div style="padding: 20px; border-top: 1px solid #e5e7eb; flex-shrink: 0; display: flex; justify-content: flex-end; gap: 12px;">
            <button onclick="closeModal('columnSettingsModal')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                Cancel
            </button>
            <button onclick="applyCustomerColumnChanges()" style="padding: 8px 16px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                Apply Changes
            </button>
        </div>
    </div>
</div>

<!-- View Customer Modal -->
<div id="viewCustomerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 20px; font-weight: 600; margin: 0;">Customer Details</h3>
                <button onclick="closeModal('viewCustomerModal')" 
                        style="background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer;">&times;</button>
            </div>
        </div>
        <div id="viewCustomerContent" style="padding: 24px;">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<!-- Customer Orders Modal -->
<div id="customerOrdersModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1001;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 95%; max-width: 1200px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 id="customerOrdersTitle" style="font-size: 20px; font-weight: 600; margin: 0;">Customer Orders</h3>
                <button onclick="closeModal('customerOrdersModal')" 
                        style="background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer;">&times;</button>
            </div>
        </div>
        <div id="customerOrdersContent" style="padding: 24px;">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<!-- Add Note Modal -->
<div id="addNoteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 20px; font-weight: 600; margin: 0;">Add Customer Note</h3>
                <button onclick="closeModal('addNoteModal')" 
                        style="background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer;">&times;</button>
            </div>
        </div>
        <div id="addNoteContent" style="padding: 24px;">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div id="editCustomerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 20px; font-weight: 600; margin: 0;">Edit Customer</h3>
                <button onclick="closeModal('editCustomerModal')" 
                        style="background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer;">&times;</button>
            </div>
        </div>
        <div id="editCustomerContent" style="padding: 24px;">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<!-- Order Details Modal (from orders page) -->
<div id="viewOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1002;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Invoice Details</h3>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button id="viewInvoiceBtn" onclick="window.viewInvoice()" style="background-color: #2563eb; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                        <polyline points="14,2 14,8 20,8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10,9 9,9 8,9"/>
                    </svg>
                    View Invoice
                </button>
                <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div id="viewOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Create Order Modal -->
<div id="createOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Create New Order</h3>
            <button onclick="closeCreateOrderModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div id="createOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<script>
// ==================== ORDER CREATION FUNCTIONALITY ====================
// Define order creation functions first to avoid "not defined" errors

// Global variables for order creation
let lineItemIndex = 0;

// Create order for specific customer - now opens modal instead of redirecting
window.createOrderForCustomer = function(customerId) {
    console.log('Creating order for customer ID:', customerId);
    
    // Fetch customer details first
    fetch(`/customers/${customerId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            openCreateOrderModal(data.customer);
        } else {
            console.error('Failed to fetch customer details');
            openCreateOrderModal();
        }
    })
    .catch(error => {
        console.error('Error fetching customer:', error);
        openCreateOrderModal();
    });
};

// Customer search functionality (for the modal) - reusing existing customerSearchTimeout variable
function searchCustomers(input) {
    const query = input.value;
    if (query.length < 2) {
        const dropdown = document.getElementById('customerDropdown');
        if (dropdown) dropdown.style.display = 'none';
        return;
    }
    
    clearTimeout(customerSearchTimeout);
    customerSearchTimeout = setTimeout(() => {
        fetch(`/customers/search?q=${encodeURIComponent(query)}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            const dropdown = document.getElementById('customerDropdown');
            if (!dropdown) {
                console.error('Customer dropdown element not found');
                return;
            }
            
            if (data.length > 0) {
                dropdown.innerHTML = data.map(customer => `
                    <div onclick="selectCustomer(${customer.id}, '${customer.first_name} ${customer.last_name}', '${customer.phone_original || customer.phone || ''}')" 
                         style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #e5e7eb;"
                         onmouseover="this.style.backgroundColor='#f3f4f6'" 
                         onmouseout="this.style.backgroundColor='white'">
                        <div style="font-weight: 500;">${customer.first_name} ${customer.last_name}</div>
                        <div style="font-size: 12px; color: #6b7280;">${customer.phone_original || customer.phone || ''} • ${customer.email || 'No email'}</div>
                    </div>
                `).join('');
                dropdown.style.display = 'block';
            } else {
                dropdown.innerHTML = '<div style="padding: 8px 12px; color: #6b7280;">No customers found</div>';
                dropdown.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error searching customers:', error);
            const dropdown = document.getElementById('customerDropdown');
            if (dropdown) {
                dropdown.innerHTML = '<div style="padding: 8px 12px; color: #dc2626;">Error loading customers</div>';
                dropdown.style.display = 'block';
            }
        });
    }, 300);
}

function showCustomerDropdown() {
    const input = document.getElementById('customerSearch');
    if (input && input.value.length >= 2) {
        searchCustomers(input);
    }
}

function selectCustomer(id, name, phone) {
    const searchInput = document.getElementById('customerSearch');
    const hiddenInput = document.getElementById('selectedCustomerId');
    
    if (searchInput) searchInput.value = `${name} (${phone})`;
    if (hiddenInput) hiddenInput.value = id;
    
    const dropdown = document.getElementById('customerDropdown');
    if (dropdown) dropdown.style.display = 'none';
}

function openCreateOrderModal(customer = null) {
    const modal = document.getElementById('createOrderModal');
    const content = document.getElementById('createOrderContent');
    
    // Load the order creation form (simplified version)
    content.innerHTML = `
        <form id="createOrderForm">
            <!-- Customer pre-filled section -->
            <div style="background-color: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; margin-bottom: 20px; padding: 16px;">
                <h4 style="color: #0369a1; margin: 0 0 12px 0;">Selected Customer</h4>
                <p style="margin: 0; font-weight: 500;">${customer ? customer.first_name + ' ' + customer.last_name : 'No customer selected'}</p>
                ${customer ? `<p style="margin: 4px 0 0 0; color: #6b7280; font-size: 14px;">${customer.phone_original || customer.phone || ''} • ${customer.email || 'No email'}</p>` : ''}
                <input type="hidden" name="customer_id" value="${customer ? customer.id : ''}">
            </div>

            <!-- Order Information -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                <div>
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Details</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Order Status</label>
                            <select name="order_status" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="completed">Completed</option>
                                <option value="on-hold">On Hold</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Order Date & Time</label>
                            <input type="datetime-local" name="order_date" required value="${getCurrentLocalDateTime()}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Contact Email</label>
                            <input type="email" name="contact_email" value="${customer ? (customer.email || '') : ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Pricing</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Subtotal</label>
                            <input type="number" step="0.01" name="subtotal_price" value="0" readonly style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background-color: #f3f4f6;">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Discount</label>
                            <div style="display: flex; gap: 8px;">
                                <div style="flex: 1; position: relative;">
                                    <input type="text" id="newOrderCouponSearch" name="coupon_code" value="" 
                                           style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" 
                                           placeholder="Search coupon code..." onkeyup="searchNewOrderCoupons(this.value)" onfocus="showNewOrderCouponDropdown()" onblur="hideNewOrderCouponDropdown()">
                                    <div id="newOrderCouponDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                                </div>
                                <input type="number" step="0.01" name="discount_total" value="0" onchange="updateOrderTotal()" placeholder="Discount amount" style="flex: 1; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                            </div>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Shipping</label>
                            <input type="number" step="0.01" name="shipping_total" value="0" onchange="updateOrderTotal()" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
                            <input type="number" step="0.01" name="total_price" value="0" readonly style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background-color: #f3f4f6; font-weight: 600;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Line Items Section -->
            <div style="background-color: #fefefe; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0;">Line Items</h4>
                    <button type="button" onclick="addLineItem()" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                        + Add Item
                    </button>
                </div>
                <div id="lineItemsContainer" style="padding: 16px;">
                    <div style="text-align: center; color: #6b7280; padding: 20px;">No line items. Click "Add Item" to add items.</div>
                </div>
            </div>

            <!-- Notes Section -->
            <div style="margin-bottom: 24px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Notes</label>
                <textarea name="note" rows="3" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical;" placeholder="Order notes..."></textarea>
            </div>

            <!-- Form Actions -->
            <div style="display: flex; justify-content: flex-end; gap: 12px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <button type="button" onclick="closeCreateOrderModal()" style="padding: 10px 20px; border: 1px solid #d1d5db; background-color: white; color: #374151; border-radius: 6px; font-size: 14px; cursor: pointer;">
                    Cancel
                </button>
                <button type="submit" style="padding: 10px 20px; background-color: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                    Create Order
                </button>
            </div>
        </form>
    `;
    
    // Reset line item index for new order
    lineItemIndex = 0;
    
    // Load default shipping price
    loadDefaultShippingPrice();
    
    // Set up form submission for new order
    document.getElementById('createOrderForm').onsubmit = function(e) {
        e.preventDefault();
        saveNewOrder();
    };
    
    modal.style.display = 'block';
}

function closeCreateOrderModal() {
    document.getElementById('createOrderModal').style.display = 'none';
}

// Get current local datetime in format suitable for datetime-local input
function getCurrentLocalDateTime() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Load default shipping price for order forms
function loadDefaultShippingPrice() {
    fetch('/api/shipping/price')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.shipping_price) {
                const shippingInput = document.querySelector('input[name="shipping_total"]');
                if (shippingInput) {
                    shippingInput.value = data.shipping_price;
                    // Try to update total if function exists
                    if (typeof updateOrderTotal === 'function') {
                        updateOrderTotal();
                    }
                }
            }
        })
        .catch(error => {
            console.log('Could not load default shipping price:', error);
        });
}

// Customer selection mode switching (for when implementing full modal)
function selectCustomerMode(mode) {
    const existingSection = document.getElementById('existingCustomerSection');
    const newSection = document.getElementById('newCustomerSection');
    const existingBtn = document.getElementById('existingCustomerBtn');
    const newBtn = document.getElementById('newCustomerBtn');

    if (!existingSection || !newSection || !existingBtn || !newBtn) return;

    if (mode === 'existing') {
        existingSection.style.display = '';
        newSection.style.display = 'none';
        existingBtn.style.backgroundColor = '#10b981';
        existingBtn.style.color = '#ffffff';
        existingBtn.style.borderColor = '#10b981';
        newBtn.style.backgroundColor = '#f9fafb';
        newBtn.style.color = '#374151';
        newBtn.style.borderColor = '#d1d5db';
    } else {
        existingSection.style.display = 'none';
        newSection.style.display = '';
        newBtn.style.backgroundColor = '#10b981';
        newBtn.style.color = '#ffffff';
        newBtn.style.borderColor = '#10b981';
        existingBtn.style.backgroundColor = '#f9fafb';
        existingBtn.style.color = '#374151';
        existingBtn.style.borderColor = '#d1d5db';
    }
}

// Line item management
function addLineItem() {
    const container = document.getElementById('lineItemsContainer');
    
    // Remove "no items" message if it exists
    if (container.innerHTML.includes('No line items')) {
        container.innerHTML = '';
    }
    
    const itemHtml = `
        <div class="line-item" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 12px; background-color: #fefefe;">
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end;">
                <div style="position: relative;">
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Product Name</label>
                    <input type="text" name="items[${lineItemIndex}][name]" 
                           style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                           placeholder="Type to search products..."
                           onkeyup="searchProducts(this, ${lineItemIndex})" 
                           onfocus="showProductDropdown(${lineItemIndex})"
                           onblur="hideProductDropdown(${lineItemIndex})">
                    <div id="productDropdown_${lineItemIndex}" class="product-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                    <input type="hidden" name="items[${lineItemIndex}][id]" value="">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
                    <input type="number" name="items[${lineItemIndex}][quantity]" step="0.01" min="0" value="1" 
                           style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                           onchange="updateLineTotal(${lineItemIndex})">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price</label>
                    <input type="number" name="items[${lineItemIndex}][unit_price]" step="0.01" min="0" value="0" 
                           style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                           onchange="updateLineTotal(${lineItemIndex})">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Line Total</label>
                    <input type="number" step="0.01" readonly value="0" 
                           style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background-color: #f3f4f6; font-weight: 500;"
                           id="lineTotal_${lineItemIndex}">
                </div>
                <div>
                    <button type="button" onclick="removeLineItem(this)" 
                            style="padding: 8px; background-color: #ef4444; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                        ✕
                    </button>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', itemHtml);
    lineItemIndex++;
}

function removeLineItem(button) {
    button.closest('.line-item').remove();
    updateOrderSubtotal();
    
    // If no line items left, show the "no items" message
    const container = document.getElementById('lineItemsContainer');
    if (!container.querySelector('.line-item')) {
        container.innerHTML = '<div style="text-align: center; color: #6b7280; padding: 20px;">No line items. Click "Add Item" to add items.</div>';
    }
}

function updateLineTotal(index) {
    const quantityInput = document.querySelector(`input[name="items[${index}][quantity]"]`);
    const priceInput = document.querySelector(`input[name="items[${index}][unit_price]"]`);
    const totalInput = document.getElementById(`lineTotal_${index}`);
    
    if (quantityInput && priceInput && totalInput) {
        const quantity = parseFloat(quantityInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        const total = quantity * price;
        
        totalInput.value = total.toFixed(2);
        updateOrderSubtotal();
    }
}

function updateOrderSubtotal() {
    let subtotal = 0;
    document.querySelectorAll('.line-item').forEach((item, index) => {
        const totalInput = item.querySelector('input[readonly]');
        if (totalInput) {
            subtotal += parseFloat(totalInput.value) || 0;
        }
    });
    
    const subtotalInput = document.querySelector('input[name="subtotal_price"]');
    if (subtotalInput) {
        subtotalInput.value = subtotal.toFixed(2);
        updateOrderTotal();
    }
}

function updateOrderTotal() {
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
    const discount = parseFloat(document.querySelector('input[name="discount_total"]')?.value) || 0;
    const shipping = parseFloat(document.querySelector('input[name="shipping_total"]')?.value) || 0;
    
    const total = subtotal - discount + shipping;
    const totalInput = document.querySelector('input[name="total_price"]');
    if (totalInput) {
        totalInput.value = total.toFixed(2);
    }
}

// Save new order
function saveNewOrder() {
    const form = document.getElementById('createOrderForm');
    const formData = new FormData(form);
    
    // Collect line items
    const items = [];
    document.querySelectorAll('.line-item').forEach((item, index) => {
        const name = item.querySelector(`input[name*="[name]"]`)?.value;
        const quantity = parseFloat(item.querySelector(`input[name*="[quantity]"]`)?.value) || 0;
        const unitPrice = parseFloat(item.querySelector(`input[name*="[unit_price]"]`)?.value) || 0;
        
        if (name && quantity > 0 && unitPrice >= 0) {
            items.push({
                name: name,
                quantity: quantity,
                unit_price: unitPrice,
                line_total: quantity * unitPrice
            });
        }
    });
    
    if (items.length === 0) {
        alert('Please add at least one line item');
        return;
    }
    
    // Prepare data
    const orderData = {
        customer_id: formData.get('customer_id'),
        order_status: formData.get('order_status'),
        order_date: formData.get('order_date') ? formData.get('order_date').replace('T', ' ') + ':00' : getCurrentLocalDateTime().replace('T', ' ') + ':00',
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        discount_total: parseFloat(formData.get('discount_total')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
        payment_method: formData.get('payment_method'),
        note: formData.get('note'),
        items: items
    };
    
    // Submit to server
    fetch('/orders', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify(orderData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Order created successfully!');
            closeCreateOrderModal();
            // Refresh the customers page to show updated customer stats
            location.reload();
        } else {
            alert('Error creating order: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error creating order');
    });
}

// Product search functionality
let productSearchTimeout = null;

function searchProducts(input, index) {
    clearTimeout(productSearchTimeout);
    const query = input.value.trim();
    
    if (query.length < 2) {
        hideProductDropdown(index);
        return;
    }
    
    productSearchTimeout = setTimeout(() => {
        fetch(`/products/search?q=${encodeURIComponent(query)}&limit=10`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showProductResults(data.products, index);
            }
        })
        .catch(error => {
            console.error('Product search error:', error);
        });
    }, 300);
}

function showProductResults(products, index) {
    const dropdown = document.getElementById(`productDropdown_${index}`);
    if (!dropdown) return;
    
    if (!Array.isArray(products) || products.length === 0) {
        dropdown.innerHTML = '<div style="padding: 8px; color: #6b7280; font-size: 12px;">No products found</div>';
    } else {
        dropdown.innerHTML = products.map(product => {
            const displayName = (product.name || product.title || '').toString();
            const safeName = displayName.replace(/'/g, "\\'");
            const price = (product.price ?? product.price_min ?? 0);
            const inventory = (product.inventory ?? product.total_inventory ?? 0);
            return `
            <div onclick="selectProduct(${index}, '${product.id}', '${safeName}', ${price})" 
                 style="padding: 8px; cursor: pointer; border-bottom: 1px solid #f3f4f6;"
                 onmouseover="this.style.backgroundColor='#f9fafb'" 
                 onmouseout="this.style.backgroundColor='white'">
                <div style="font-weight: 500; font-size: 13px;">${displayName}</div>
                <div style="font-size: 11px; color: #6b7280;">Price: PKR ${price} | Stock: ${inventory}</div>
            </div>`;
        }).join('');
    }
    
    dropdown.style.display = 'block';
}

function showProductDropdown(index) {
    // Hide other dropdowns
    document.querySelectorAll('.product-dropdown').forEach(dropdown => {
        if (dropdown.id !== `productDropdown_${index}`) {
            dropdown.style.display = 'none';
        }
    });
}

function hideProductDropdown(index) {
    const dropdown = document.getElementById(`productDropdown_${index}`);
    if (dropdown) {
        setTimeout(() => {
            dropdown.style.display = 'none';
        }, 200);
    }
}

function selectProduct(index, productId, productName, price) {
    // Fill in the product details
    const nameInput = document.querySelector(`input[name="items[${index}][name]"]`);
    const priceInput = document.querySelector(`input[name="items[${index}][unit_price]"]`);
    const hiddenInput = document.querySelector(`input[name="items[${index}][id]"]`);
    
    if (nameInput) nameInput.value = productName;
    if (priceInput) {
        priceInput.value = price;
        // Make price readonly when selected from product dropdown
        priceInput.readOnly = true;
        priceInput.style.backgroundColor = '#f3f4f6';
        priceInput.style.cursor = 'not-allowed';
        priceInput.setAttribute('data-from-product', 'true');
        priceInput.title = 'Price is set from product catalog and cannot be edited';
    }
    if (hiddenInput) hiddenInput.value = productId;
    
    // Update the line total
    updateLineTotal(index);
    
    // Hide dropdown
    hideProductDropdown(index);
}

// Coupon search functionality
let couponSearchTimeout;
function searchNewOrderCoupons(query) {
    clearTimeout(couponSearchTimeout);
    
    if (query.length < 2) {
        document.getElementById('newOrderCouponDropdown').style.display = 'none';
        return;
    }
    
    couponSearchTimeout = setTimeout(() => {
        fetch(`/coupons/search?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                const dropdown = document.getElementById('newOrderCouponDropdown');
                if (data.length > 0) {
                    dropdown.innerHTML = data.map(coupon => `
                        <div onclick="selectNewOrderCoupon('${coupon.code}', ${coupon.value}, '${coupon.value_type}', ${coupon.minimum_amount})" 
                             style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #e5e7eb;"
                             onmouseover="this.style.backgroundColor='#f3f4f6'" 
                             onmouseout="this.style.backgroundColor='white'">
                            <div style="font-weight: 500;">${coupon.code}</div>
                            <div style="font-size: 12px; color: #6b7280;">
                                ${coupon.value_type === 'percentage' ? coupon.value + '%' : 'PKR ' + coupon.value} off
                                ${coupon.minimum_amount > 0 ? ' (Min: PKR ' + coupon.minimum_amount + ')' : ''}
                            </div>
                        </div>
                    `).join('');
                    dropdown.style.display = 'block';
                } else {
                    dropdown.innerHTML = '<div style="padding: 8px 12px; color: #6b7280;">No coupons found</div>';
                    dropdown.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error searching coupons:', error);
            });
    }, 300);
}

function showNewOrderCouponDropdown() {
    const query = document.getElementById('newOrderCouponSearch').value;
    if (query.length > 0) {
        searchNewOrderCoupons(query);
    }
}

function hideNewOrderCouponDropdown() {
    setTimeout(() => {
        document.getElementById('newOrderCouponDropdown').style.display = 'none';
    }, 200);
}

function selectNewOrderCoupon(code, value, valueType, minimumAmount) {
    // Set coupon code
    document.getElementById('newOrderCouponSearch').value = code;
    
    // Calculate discount based on subtotal
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
    let discountAmount = 0;
    
    if (subtotal >= minimumAmount) {
        if (valueType === 'percentage') {
            discountAmount = (subtotal * value) / 100;
        } else {
            discountAmount = value;
        }
    }
    
    // Set discount amount
    document.querySelector('input[name="discount_total"]').value = discountAmount.toFixed(2);
    
    // Update total
    updateOrderTotal();
    
    // Hide dropdown
    document.getElementById('newOrderCouponDropdown').style.display = 'none';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCreateOrderModal();
    }
});
</script>

@endsection
