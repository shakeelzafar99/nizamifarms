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

window.openColumnSettings = function() {
    console.log('Opening column settings...');
    const modal = document.getElementById('columnSettingsModal');
    const columnList = document.getElementById('columnList');
    
    if (!modal) {
        console.error('Column settings modal not found');
        return;
    }
    
    if (!columnList) {
        console.error('Column list element not found');
        return;
    }
    
    // Complete column list with all available fields
    const availableColumns = {
        'id': { label: 'ID', width: 'w-16' },
        'name': { label: 'Customer', width: 'min-w-[200px]' },
        'contact': { label: 'Contact', width: 'w-40' },
        'location': { label: 'Location', width: 'w-32' },
        'total_orders': { label: 'Orders', width: 'w-20' },
        'total_spent': { label: 'Total Spent', width: 'w-32' },
        'status': { label: 'Status', width: 'w-24' },
        'first_order_date': { label: 'First Order', width: 'w-32' },
        'last_order_date': { label: 'Last Order', width: 'w-32' },
        'phone_original': { label: 'Original Phone', width: 'w-32' },
        'phone_normalized': { label: 'Normalized Phone', width: 'w-32' },
        'email': { label: 'Email', width: 'w-40' },
        'company': { label: 'Company', width: 'w-32' },
        'address1': { label: 'Address', width: 'w-48' },
        'address2': { label: 'Address 2', width: 'w-32' },
        'city': { label: 'City', width: 'w-24' },
        'province': { label: 'Province', width: 'w-24' },
        'postal_code': { label: 'Postal Code', width: 'w-24' },
        'created_at': { label: 'Created', width: 'w-32' },
        'updated_at': { label: 'Updated', width: 'w-32' }
    };
    
    let html = '';
    Object.keys(availableColumns).forEach(columnId => {
        const column = availableColumns[columnId];
        html += `
            <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg bg-white hover:bg-gray-50 transition-colors">
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm font-medium">${column.label}</span>
                    </label>
                </div>
            </div>
        `;
    });
    
    columnList.innerHTML = html;
    modal.style.display = 'block';
    console.log('Column settings modal opened');
};

window.toggleColumn = function(columnId) {
    console.log('toggleColumn called with:', columnId);
    // TODO: Implement column toggling
};

window.closeModal = function(modalId) {
    console.log('closeModal called with:', modalId);
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
};

window.editCustomer = function(id) {
    console.log('editCustomer called with:', id);
    // TODO: Implement edit functionality
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
window.viewOrderDetails = function(orderId) {
    console.log('View order details clicked for order:', orderId);
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
                    <span>{{ $stats['active_customers'] }} Active</span>
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
                            <i class="ki-filled ki-check-circle text-green-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">Active</span>
                            <span class="text-base font-bold text-gray-900 ml-1">{{ number_format($stats['active_customers']) }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-dollar text-orange-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">Revenue</span>
                            <span class="text-base font-bold text-gray-900 ml-1">PKR {{ number_format($stats['total_revenue'], 0) }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-shopping-cart text-purple-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">Orders</span>
                            <span class="text-base font-bold text-gray-900 ml-1">{{ number_format($stats['total_orders']) }}</span>
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
                <form method="GET" class="flex items-center gap-2" id="customerSearchForm">
                    <div class="flex-1 min-w-48">
                        <div class="relative">
                            <input type="text" name="search" value="{{ request('search') }}" 
                                   placeholder="Search customers..." 
                                   class="input input-sm w-full pl-8"
                                   id="customerSearchInput">
                            <div class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                <i class="ki-filled ki-magnifier text-gray-400 text-sm"></i>
                            </div>
                        </div>
                    </div>
                    
                    <select name="city" class="select select-sm w-32 text-xs">
                        <option value="">All Cities</option>
                        @foreach($cities as $city)
                            <option value="{{ $city }}" {{ request('city') == $city ? 'selected' : '' }}>
                                {{ $city }}
                            </option>
                        @endforeach
                    </select>
                    
                    <select name="status" class="select select-sm w-28 text-xs">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    
                    <button type="submit" class="kt-btn kt-btn-sm kt-btn-primary text-xs px-3">
                        <i class="ki-filled ki-magnifier text-sm"></i>
                    </button>
                    
                    @if(request()->hasAny(['search', 'city', 'status']))
                        <a href="{{ route('customers.index') }}" class="kt-btn kt-btn-sm kt-btn-outline text-xs px-3">
                            <i class="ki-filled ki-cross text-sm"></i>
                        </a>
                    @endif
                </form>
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
                                <tr class="hover:bg-gray-50 transition-colors duration-150">
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
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-1">
                                            <button onclick="viewCustomer({{ $customer->id }})" 
                                                    class="inline-flex items-center p-1.5 border border-gray-300 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-50 transition-colors duration-150" 
                                                    title="View Details">
                                                <i class="ki-filled ki-eye text-sm"></i>
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

<!-- Order Details Modal (from orders page) -->
<div id="viewOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1002;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Invoice Details</h3>
            <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div id="viewOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>
@endsection
