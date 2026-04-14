@extends('layouts.app')

@section('title', 'Customers')

@push('styles')
<style>
/* Enhanced line item styling for customers page - consistent with orders */
.line-item input[name*="[name]"] {
    font-weight: 500;
    color: #374151;
}

.line-item input[name*="[name]"]:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.line-item input[name*="[quantity]"] {
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
}

.line-item input[name*="[unit_price]"] {
    text-align: right;
    font-size: 13px;
    font-weight: 500;
    color: #059669;
}

.line-item .line-total {
    font-weight: 600;
    color: #1f2937;
    text-align: right;
}
</style>
@endpush

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
            const mergedCustomers = data.merged_customers || [];
            
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
                                <p style="margin: 4px 0 0 0;">
                                    ${customer.phone_original || customer.phone || 'N/A'}
                                    ${(customer.phone_original || customer.phone) ? `<button onclick="openCustomerWhatsApp('${escapeForJs(customer.first_name + ' ' + customer.last_name)}', '${escapeForJs(customer.phone_original || customer.phone)}', ${customer.id})" style="padding: 2px 8px; background: #25D366; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer; margin-left: 6px;" title="Send WhatsApp Message">💬 WhatsApp</button>` : ''}
                                </p>
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
            `;
            
            // Delivery Region section
            html += `
                <div style="margin-top: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h4 style="font-weight: 600; color: #374151; margin: 0;">🚚 Delivery Region</h4>
                    </div>
                    <div style="background-color: ${data.delivery_region_name ? '#eef2ff' : '#fef2f2'}; padding: 16px; border-radius: 8px; border: 1px solid ${data.delivery_region_name ? '#6366f1' : '#fca5a5'};">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="flex:1;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Current Region</label>
                                <p style="margin: 4px 0 0 0; font-weight: 600; color: ${data.delivery_region_name ? '#4338ca' : '#dc2626'};">
                                    ${data.delivery_region_name || 'Not assigned'}
                                    ${customer.delivery_region_source ? '<span style="font-size:11px;font-weight:400;color:#9ca3af;margin-left:6px;">(' + customer.delivery_region_source + ')</span>' : ''}
                                </p>
                            </div>
                            <div>
                                <select id="custRegionSelect_${customer.id}" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                                    <option value="">-- No Region --</option>
                                </select>
                            </div>
                            <button onclick="saveCustomerRegion(${customer.id})" style="padding:6px 16px;background:#4f46e5;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:500;cursor:pointer;">
                                Save
                            </button>
                        </div>
                    </div>
                </div>
            `;
            // Populate region dropdown after render
            setTimeout(async () => {
                try {
                    const regResp = await fetch('/regions/list');
                    const regData = await regResp.json();
                    if (regData.success) {
                        const sel = document.getElementById('custRegionSelect_' + customer.id);
                        if (sel) {
                            sel.innerHTML = '<option value="">-- No Region --</option>' +
                                regData.regions.map(r => '<option value="' + r.id + '"' + (r.id == customer.delivery_region_id ? ' selected' : '') + '>' + r.name + '</option>').join('');
                        }
                    }
                } catch(e) { console.error(e); }
            }, 100);

            // Add Verified Location section
            if (data.verified_location) {
                html += `
                <div style="margin-top: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h4 style="font-weight: 600; color: #374151; margin: 0;">✅ Verified Location</h4>
                        <button onclick="updateVerifiedLocation(${customer.id})" style="padding: 6px 12px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer;">
                            <i class="fas fa-edit"></i> Update
                        </button>
                    </div>
                    <div style="background-color: #f0fdf4; padding: 16px; border-radius: 8px; border: 1px solid #10b981;">
                `;
                
                if (data.verified_location.url) {
                    html += `
                        <div style="margin-bottom: 12px;">
                            <label style="font-size: 12px; color: #059669; text-transform: uppercase; font-weight: 500;">Google Maps Link</label>
                            <p style="margin: 4px 0 0 0;">
                                <a href="${data.verified_location.url}" target="_blank" style="color: #3b82f6; text-decoration: none; font-weight: 500;">
                                    <i class="fas fa-external-link-alt"></i> Open in Google Maps
                                </a>
                            </p>
                        </div>
                    `;
                } else if (data.verified_location.latitude && data.verified_location.longitude) {
                    html += `
                        <div style="margin-bottom: 12px;">
                            <label style="font-size: 12px; color: #059669; text-transform: uppercase; font-weight: 500;">Coordinates</label>
                            <p style="margin: 4px 0 0 0; font-family: monospace;">${data.verified_location.latitude}, ${data.verified_location.longitude}</p>
                            <p style="margin: 4px 0 0 0;">
                                <a href="${data.verified_location.google_maps_url}" target="_blank" style="color: #3b82f6; text-decoration: none; font-weight: 500;">
                                    <i class="fas fa-external-link-alt"></i> Open in Google Maps
                                </a>
                            </p>
                        </div>
                    `;
                }
                
                if (data.verified_location.saved_by) {
                    html += `
                        <div style="padding-top: 12px; border-top: 1px solid #bbf7d0;">
                            <p style="margin: 0; font-size: 12px; color: #059669;">
                                <i class="fas fa-user"></i> Saved by: <strong>${data.verified_location.saved_by}</strong>
                            </p>
                            <p style="margin: 4px 0 0 0; font-size: 12px; color: #059669;">
                                <i class="fas fa-clock"></i> ${new Date(data.verified_location.saved_at).toLocaleString()}
                            </p>
                        </div>
                    `;
                }
                
                html += `
                    </div>
                </div>
                `;
            } else {
                html += `
                <div style="margin-top: 24px;">
                    <div style="background-color: #eff6ff; padding: 16px; border-radius: 8px; border: 1px solid #3b82f6; text-align: center;">
                        <p style="margin: 0 0 12px 0; color: #1e40af; font-weight: 500;">No verified location set</p>
                        <button onclick="setVerifiedLocation(${customer.id})" style="padding: 8px 16px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;">
                            <i class="fas fa-map-marker-alt"></i> Set Verified Location
                        </button>
                    </div>
                </div>
                `;
            }
            
            html += `
                <div style="margin-top: 24px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Statistics <span style="font-size: 11px; font-weight: 400; color: #9ca3af;">(delivered orders only)</span></h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Delivered Orders</label>
                                <p style="margin: 4px 0 0 0; font-size: 18px; font-weight: 600; color: #2563eb;">${customer.total_orders || 0}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Total Revenue</label>
                                <p style="margin: 4px 0 0 0; font-size: 18px; font-weight: 600; color: #059669;">PKR ${Math.round(customer.total_spent || 0).toLocaleString()}</p>
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
                
                ${mergedCustomers && mergedCustomers.length > 0 ? `
                <!-- Merged/Linked Customers Section -->
                <div style="margin-top: 24px;">
                    <div style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); padding: 16px; border-radius: 8px; border: 1px solid #3b82f6;">
                        <h4 style="font-weight: 600; color: #1e40af; margin: 0 0 12px 0;">🔗 Linked Customers (${mergedCustomers.length})</h4>
                        <p style="margin: 0 0 12px 0; font-size: 12px; color: #1e40af;">The following customer records were merged into this customer:</p>
                        <div style="background: white; border-radius: 6px; overflow: hidden;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                <thead>
                                    <tr style="background: #eff6ff; border-bottom: 1px solid #dbeafe;">
                                        <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;">ID</th>
                                        <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;">Name</th>
                                        <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;">Phone</th>
                                        <th style="padding: 8px 12px; text-align: left; font-weight: 600; color: #374151;">Merged</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${mergedCustomers.map(m => `
                                        <tr style="border-bottom: 1px solid #f3f4f6;">
                                            <td style="padding: 8px 12px; color: #6b7280;">#${m.id}</td>
                                            <td style="padding: 8px 12px; color: #374151; font-weight: 500;">${m.name || 'N/A'}</td>
                                            <td style="padding: 8px 12px; color: #6b7280;">${m.phone || m.phone_normalized || 'N/A'}</td>
                                            <td style="padding: 8px 12px; color: #6b7280; font-size: 11px;">
                                                ${m.merged_at ? new Date(m.merged_at).toLocaleDateString() : ''} 
                                                ${m.merged_by_name ? 'by ' + m.merged_by_name : ''}
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                ` : ''}
                
                <!-- Merge Customer Section -->
                <div style="margin-top: 24px;">
                    <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 16px; border-radius: 8px; border: 1px solid #fbbf24;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h4 style="font-weight: 600; color: #92400e; margin: 0 0 4px 0;">🔄 Merge with Another Customer</h4>
                                <p style="margin: 0; font-size: 12px; color: #b45309;">Transfer all orders from another customer into this one</p>
                            </div>
                            <button onclick="openMergeIntoModal(${customer.id}, '${(customer.first_name || '').replace(/'/g, "\\'")} ${(customer.last_name || '').replace(/'/g, "\\'")}')" 
                                    style="padding: 8px 16px; background: #f59e0b; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer;">
                                <i class="fas fa-compress-arrows-alt"></i> Merge Into This
                            </button>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h4 style="font-weight: 600; color: #374151; margin: 0;">Recent Orders ${customer.orders && customer.orders.length >= 10 ? '(Last 10)' : ''}</h4>
                        <button onclick="viewCustomerOrders(${customer.id}, '${(customer.first_name || '').replace(/'/g, "\\'")} ${(customer.last_name || '').replace(/'/g, "\\'")}')" 
                                style="padding: 6px 12px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                                onmouseover="this.style.background='#1d4ed8'" 
                                onmouseout="this.style.background='#2563eb'">
                            📋 View All Orders
                        </button>
                    </div>
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
                                                              order.order_status === 'delivered' ? '#059669' :
                                                              order.order_status === 'pending' ? '#d97706' : 
                                                              order.order_status === 'cancelled' ? '#dc2626' : '#6b7280';
                                            // Handle both source_type (history/production) and external_source
                                            const isHistoryOrder = order.source_type === 'history';
                                            const sourceColor = isHistoryOrder ? '#9333ea' : // Purple for history
                                                              order.external_source === 'shopify' ? '#7c3aed' :
                                                              order.external_source === 'woocommerce' ? '#2563eb' :
                                                              order.external_source === 'webapp' ? '#059669' : '#6b7280';
                                            const sourceLabel = isHistoryOrder ? 'history' : (order.external_source || 'direct');
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
                                                            ${sourceLabel}
                                                        </span>
                                                    </td>
                                                    <td style="padding: 10px 8px; text-align: right; font-weight: 600; color: #1f2937; font-size: 13px;">PKR ${Math.round(order.total_price || 0).toLocaleString()}</td>
                                                    <td style="padding: 10px 8px; text-align: center;">
                                                        ${isHistoryOrder ? `
                                                            <button onclick="viewHistoryOrderDetails(${order.id}, '${order.order_number}')" 
                                                                    style="padding: 4px 8px; background: #9333ea; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 11px;"
                                                                    onmouseover="this.style.background='#7c3aed'" 
                                                                    onmouseout="this.style.background='#9333ea'">
                                                                View
                                                            </button>
                                                        ` : `
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
                                                        `}
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
    { id: 'region', visible: true, fixed: false },
    { id: 'total_orders', visible: true, fixed: false },
    { id: 'total_spent', visible: true, fixed: false },
    { id: 'notes', visible: true, fixed: false },
    { id: 'first_order_date', visible: true, fixed: false },
    { id: 'last_order_date', visible: true, fixed: false },
    { id: 'actions', visible: true, fixed: true }
];

let currentCustomerColumns = JSON.parse(localStorage.getItem('customerTableColumns')) || defaultCustomerColumns;

// Clean up any corrupted data on initialization
currentCustomerColumns = currentCustomerColumns.filter(col => col && col.id && typeof col.id === 'string');

// Migrate old 'status' column to 'notes' for existing users
currentCustomerColumns = currentCustomerColumns.map(col => {
    if (col && col.id === 'status') {
        return { ...col, id: 'notes' };
    }
    return col;
});
// Ensure 'notes' exists (in case it was missing)
if (!currentCustomerColumns.find(col => col.id === 'notes')) {
    const actionsIdx = currentCustomerColumns.findIndex(col => col.id === 'actions');
    const notesCol = { id: 'notes', visible: true, fixed: false };
    if (actionsIdx >= 0) {
        currentCustomerColumns.splice(actionsIdx, 0, notesCol);
    } else {
        currentCustomerColumns.push(notesCol);
    }
}
localStorage.setItem('customerTableColumns', JSON.stringify(currentCustomerColumns));

const availableCustomerColumns = {
    'id': { label: 'ID', fixed: true },
    'name': { label: 'Customer', fixed: true },
    'contact': { label: 'Contact', fixed: false },
    'location': { label: 'Location', fixed: false },
    'region': { label: 'Region', fixed: false },
    'total_orders': { label: 'Orders', fixed: false },
    'total_spent': { label: 'Total Spent', fixed: false },
    'notes': { label: 'Notes', fixed: false },
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

window.openModal = function(modalId) {
    console.log('openModal called with:', modalId);
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
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
    
    // Add _method field for Laravel to recognize PUT request
    formData.append('_method', 'PUT');
    
    fetch(`/customers/${customerId}`, {
        method: 'POST',  // Use POST with _method override for FormData
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
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
    // Reload page to clear all filters
    window.location.href = window.location.pathname;
};

// New AJAX-based customer search functionality
let customerSearchTimeout;
window.allCustomers = @json($customers->items());
window.filteredCustomers = [...window.allCustomers];

function clearCustomerFilters() {
    // Clear all filters and reload from server (to reset pagination too)
    window.location.href = window.location.pathname;
}

function parseSortValue(sortValue) {
    if (!sortValue) return { sortBy: 'last_order_date', sortDir: 'desc' };
    const lastUnderscore = sortValue.lastIndexOf('_');
    return {
        sortBy: sortValue.substring(0, lastUnderscore),
        sortDir: sortValue.substring(lastUnderscore + 1)
    };
}

function fetchFilteredCustomers() {
    const searchTerm = document.getElementById('customerSearchInput').value.trim();
    const regionFilter = document.getElementById('customerRegionFilter').value;
    const activityFilter = document.getElementById('customerActivityFilter').value;
    const { sortBy, sortDir } = parseSortValue(document.getElementById('customerSortFilter').value);
    
    // Show loading state
    showCustomerLoadingState();
    
    // Build query parameters
    const params = new URLSearchParams();
    if (searchTerm) params.append('search', searchTerm);
    if (regionFilter) params.append('region', regionFilter);
    if (activityFilter) params.append('activity', activityFilter);
    if (sortBy) params.append('sort_by', sortBy);
    if (sortDir) params.append('sort_dir', sortDir);
    
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
            
        case 'region':
            if (customer.delivery_region_name) {
                return '<span style="display:inline-block;padding:2px 8px;background:#eef2ff;color:#4338ca;border-radius:4px;font-size:12px;font-weight:600;">' + customer.delivery_region_name + '</span>';
            }
            return '<span style="font-size:12px;color:#9ca3af;">Not set</span>';
            
        case 'total_orders':
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 cursor-pointer hover:bg-blue-200 transition-colors" onclick="viewCustomerOrders(' + customer.id + ', \'' + customer.first_name + ' ' + customer.last_name + '\')" title="Click to view customer orders">' + (customer.total_orders || 0) + '</span>';
            
        case 'total_spent':
            return '<span class="text-sm font-medium text-gray-900">PKR ' + Math.round(customer.total_spent || 0).toLocaleString() + '</span>';
            
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
            const custPhone = customer.phone_original || customer.phone;
            if (custPhone) {
                actionsHtml += '<button onclick="openCustomerWhatsApp(\'' + escapeForJs(customer.first_name + ' ' + customer.last_name) + '\', \'' + escapeForJs(custPhone) + '\', ' + customer.id + ')" class="inline-flex items-center p-1.5 border border-green-300 rounded-md text-green-600 hover:text-green-700 hover:bg-green-50 transition-colors duration-150" title="WhatsApp"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></button>';
            }
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
        case 'region': return 'w-32';
        case 'total_orders': return 'w-20';
        case 'total_spent': return 'w-32';
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
    const regionFilter = document.getElementById('customerRegionFilter');
    const activityFilter = document.getElementById('customerActivityFilter');
    const sortFilter = document.getElementById('customerSortFilter');
    
    // Search functionality with debouncing
    searchInput.addEventListener('input', function() {
        clearTimeout(customerSearchTimeout);
        customerSearchTimeout = setTimeout(() => {
            const searchTerm = searchInput.value.trim();
            if (searchTerm.length > 2) {
                fetchFilteredCustomers();
            } else if (searchTerm.length === 0) {
                // Reload without search when cleared
                navigateWithFilters();
            } else {
                // Reset to current page data if search is too short but not empty
                window.filteredCustomers = [...window.allCustomers];
                renderCustomersTable();
            }
        }, 300);
    });
    
    // Region filter - navigate with URL params for pagination support
    regionFilter.addEventListener('change', function() {
        navigateWithFilters();
    });
    
    // Activity filter - navigate with URL params for pagination support
    activityFilter.addEventListener('change', function() {
        navigateWithFilters();
    });
    
    // Sort filter - navigate with URL params for pagination support
    sortFilter.addEventListener('change', function() {
        navigateWithFilters();
    });
});

// Navigate to the same page with updated URL params (server-side for proper pagination)
function navigateWithFilters() {
    const params = new URLSearchParams();
    
    const search = document.getElementById('customerSearchInput').value.trim();
    const region = document.getElementById('customerRegionFilter').value;
    const activity = document.getElementById('customerActivityFilter').value;
    const { sortBy, sortDir } = parseSortValue(document.getElementById('customerSortFilter').value);
    
    if (search) params.set('search', search);
    if (region) params.set('region', region);
    if (activity) params.set('activity', activity);
    
    // Only add sort params if not the default
    if (sortBy !== 'last_order_date' || sortDir !== 'desc') {
        params.set('sort_by', sortBy);
        params.set('sort_dir', sortDir);
    }
    
    const queryString = params.toString();
    window.location.href = window.location.pathname + (queryString ? '?' + queryString : '');
}

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

window.saveCustomerRegion = async function(customerId) {
    const sel = document.getElementById('custRegionSelect_' + customerId);
    if (!sel) return;
    const regionId = sel.value || null;
    try {
        const resp = await fetch('/regions/set-customer-region', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
            body: JSON.stringify({ customer_id: customerId, delivery_region_id: regionId })
        });
        const d = await resp.json();
        if (d.success) {
            alert('Region updated to: ' + (d.region_name || 'None'));
            window.viewCustomer(customerId);
        } else alert(d.message || 'Error');
    } catch(e) { alert('Error: ' + e.message); }
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
                                      order.order_status === 'delivered' ? '#059669' :
                                      order.order_status === 'pending' ? '#d97706' : 
                                      order.order_status === 'cancelled' ? '#dc2626' : '#6b7280';
                    
                    // Check if this is a history order
                    const isHistoryOrder = order.source_type === 'history';
                    const sourceColor = isHistoryOrder ? '#9333ea' : '#3b82f6';
                    const sourceLabel = isHistoryOrder ? 'Legacy' : 'Production';
                    
                    // Different action buttons for history vs production orders
                    const actionButtons = isHistoryOrder ? `
                        <button onclick="viewHistoryOrderDetails(${order.id}, '${order.order_number}')" 
                                style="padding: 6px 12px; background: #9333ea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;"
                                onmouseover="this.style.background='#7c3aed'" 
                                onmouseout="this.style.background='#9333ea'">
                            View
                        </button>
                    ` : `
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
                    `;
                    
                    html += `
                        <tr style="border-bottom: 1px solid #f3f4f6; hover:background-color: #f9fafb;">
                            <td style="padding: 12px; font-weight: 600; color: #1f2937;">
                                #${order.order_number || order.id}
                                ${isHistoryOrder ? '<span style="margin-left: 6px; padding: 2px 6px; background: #f3e8ff; color: #9333ea; font-size: 10px; border-radius: 4px;">Legacy</span>' : ''}
                            </td>
                            <td style="padding: 12px; color: #6b7280;">${window.formatDateLocal(order.order_date)}</td>
                            <td style="padding: 12px;">
                                <span style="display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; background-color: ${statusColor}20; color: ${statusColor};">
                                    ${order.order_status ? order.order_status.charAt(0).toUpperCase() + order.order_status.slice(1) : 'N/A'}
                                </span>
                            </td>
                            <td style="padding: 12px; color: #6b7280;">${order.line_items_count || 0} items</td>
                            <td style="padding: 12px; text-align: right; font-weight: 600; color: #1f2937;">PKR ${Math.round(order.total_price || 0).toLocaleString()}</td>
                            <td style="padding: 12px; text-align: center;">
                                ${actionButtons}
                            </td>
                        </tr>
                    `;
                });
                
                // Calculate totals
                const totalSpent = orders.reduce((sum, order) => sum + (order.total_price || 0), 0);
                const prodOrders = orders.filter(o => o.source_type !== 'history').length;
                const historyOrders = orders.filter(o => o.source_type === 'history').length;
                
                html += `
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div style="display: flex; gap: 16px; font-size: 13px;">
                            <span style="color: #6b7280;">
                                <strong style="color: #1f2937;">${orders.length}</strong> total orders
                            </span>
                            ${prodOrders > 0 ? `
                            <span style="color: #3b82f6;">
                                <strong>${prodOrders}</strong> production
                            </span>
                            ` : ''}
                            ${historyOrders > 0 ? `
                            <span style="color: #9333ea;">
                                <strong>${historyOrders}</strong> legacy
                            </span>
                            ` : ''}
                        </div>
                        <div style="font-size: 14px; font-weight: 600; color: #059669;">
                            Total: PKR ${totalSpent.toLocaleString()}
                        </div>
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

// ============================================
// HISTORY ORDER DETAILS VIEWER
// ============================================
window.viewHistoryOrderDetails = function(historyOrderId, orderNumber) {
    // Close customer modal if open
    window.closeModal('viewCustomerModal');
    
    // Show loading in a new modal
    const modal = document.getElementById('historyOrderModal');
    const content = document.getElementById('historyOrderContent');
    
    if (!modal || !content) {
        console.error('History order modal not found');
        alert('Unable to view history order details');
        return;
    }
    
    content.innerHTML = `
        <div style="text-align: center; padding: 40px;">
            <div style="display: inline-block; width: 40px; height: 40px; border: 3px solid #e5e7eb; border-top-color: #9333ea; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <p style="margin-top: 16px; color: #6b7280;">Loading order #${orderNumber}...</p>
        </div>
    `;
    
    window.openModal('historyOrderModal');
    
    // Fetch history order details
    fetch(`/customers/history-order/${historyOrderId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const order = data.order;
                const lineItems = data.line_items;
                const customer = data.customer;
                
                // Format date
                const orderDate = order.order_date ? new Date(order.order_date).toLocaleDateString('en-US', {
                    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                }) : 'N/A';
                
                const deliveryDate = order.delivered_at ? new Date(order.delivered_at).toLocaleDateString('en-US', {
                    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                }) : 'N/A';
                
                // Status badge color
                const statusColor = order.order_status === 'delivered' ? '#059669' : 
                                   order.order_status === 'cancelled' ? '#dc2626' : '#6b7280';
                
                // Build line items table
                let lineItemsHtml = '';
                if (lineItems && lineItems.length > 0) {
                    lineItemsHtml = `
                        <table style="width: 100%; border-collapse: collapse; margin-top: 12px;">
                            <thead>
                                <tr style="border-bottom: 2px solid #e5e7eb; background: #f9fafb;">
                                    <th style="padding: 10px; text-align: left; font-size: 12px; font-weight: 600; color: #374151;">ITEM</th>
                                    <th style="padding: 10px; text-align: left; font-size: 12px; font-weight: 600; color: #374151;">SKU</th>
                                    <th style="padding: 10px; text-align: center; font-size: 12px; font-weight: 600; color: #374151;">QTY</th>
                                    <th style="padding: 10px; text-align: right; font-size: 12px; font-weight: 600; color: #374151;">UNIT PRICE</th>
                                    <th style="padding: 10px; text-align: right; font-size: 12px; font-weight: 600; color: #374151;">TOTAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${lineItems.map(item => `
                                    <tr style="border-bottom: 1px solid #e5e7eb;">
                                        <td style="padding: 10px; color: #1f2937; font-size: 13px;">${item.name || 'Unknown Item'}</td>
                                        <td style="padding: 10px; color: #6b7280; font-size: 12px;">${item.sku || '-'}</td>
                                        <td style="padding: 10px; text-align: center; color: #1f2937; font-size: 13px;">${item.quantity || 1}</td>
                                        <td style="padding: 10px; text-align: right; color: #6b7280; font-size: 13px;">PKR ${Math.round(item.unit_price || 0).toLocaleString()}</td>
                                        <td style="padding: 10px; text-align: right; color: #1f2937; font-weight: 500; font-size: 13px;">PKR ${Math.round(item.line_total || item.line_subtotal || 0).toLocaleString()}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                } else {
                    lineItemsHtml = '<p style="color: #6b7280; padding: 20px; text-align: center;">No line items found</p>';
                }
                
                content.innerHTML = `
                    <div style="padding: 24px;">
                        <!-- Header -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #e5e7eb;">
                            <div>
                                <h3 style="font-size: 20px; font-weight: 600; color: #1f2937; margin: 0;">
                                    <span style="color: #9333ea;">Legacy Order</span> #${order.order_number}
                                </h3>
                                <p style="color: #6b7280; margin: 4px 0 0 0; font-size: 13px;">Imported from historical data</p>
                            </div>
                            <span style="padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; background-color: ${statusColor}20; color: ${statusColor};">
                                ${order.order_status ? order.order_status.charAt(0).toUpperCase() + order.order_status.slice(1) : 'Unknown'}
                            </span>
                        </div>
                        
                        <!-- Order Info Grid -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                            <!-- Customer & Dates -->
                            <div style="background: #f9fafb; padding: 16px; border-radius: 8px;">
                                <h4 style="font-size: 14px; font-weight: 600; color: #374151; margin: 0 0 12px 0;">📋 Order Details</h4>
                                <div style="display: grid; gap: 8px;">
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: #6b7280; font-size: 13px;">Order Date:</span>
                                        <span style="color: #1f2937; font-size: 13px; font-weight: 500;">${orderDate}</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: #6b7280; font-size: 13px;">Delivered:</span>
                                        <span style="color: #1f2937; font-size: 13px; font-weight: 500;">${deliveryDate}</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: #6b7280; font-size: 13px;">Payment:</span>
                                        <span style="color: #1f2937; font-size: 13px; font-weight: 500;">${order.payment_method || 'N/A'}</span>
                                    </div>
                                    ${order.coupon_code ? `
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: #6b7280; font-size: 13px;">Coupon:</span>
                                        <span style="color: #9333ea; font-size: 13px; font-weight: 500;">${order.coupon_code}</span>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                            
                            <!-- Customer Info -->
                            <div style="background: #f9fafb; padding: 16px; border-radius: 8px;">
                                <h4 style="font-size: 14px; font-weight: 600; color: #374151; margin: 0 0 12px 0;">👤 Customer</h4>
                                <div style="display: grid; gap: 8px;">
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: #6b7280; font-size: 13px;">Name:</span>
                                        <span style="color: #1f2937; font-size: 13px; font-weight: 500;">${order.name || (order.address_first_name + ' ' + order.address_last_name) || 'N/A'}</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: #6b7280; font-size: 13px;">Phone:</span>
                                        <span style="color: #1f2937; font-size: 13px; font-weight: 500;">${order.address_phone || 'N/A'}</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: #6b7280; font-size: 13px;">City:</span>
                                        <span style="color: #1f2937; font-size: 13px; font-weight: 500;">${order.address_city || 'N/A'}</span>
                                    </div>
                                    ${order.address_line1 ? `
                                    <div style="margin-top: 4px;">
                                        <span style="color: #6b7280; font-size: 12px;">Address:</span>
                                        <p style="color: #1f2937; font-size: 12px; margin: 2px 0 0 0;">${order.address_line1}</p>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                        
                        <!-- Line Items -->
                        <div style="margin-bottom: 24px;">
                            <h4 style="font-size: 14px; font-weight: 600; color: #374151; margin: 0 0 12px 0;">📦 Line Items (${lineItems.length})</h4>
                            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                                ${lineItemsHtml}
                            </div>
                        </div>
                        
                        <!-- Order Totals -->
                        <div style="background: #f0fdf4; padding: 16px; border-radius: 8px; border: 1px solid #86efac;">
                            <div style="display: grid; gap: 8px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #6b7280; font-size: 13px;">Subtotal:</span>
                                    <span style="color: #1f2937; font-size: 13px;">PKR ${Math.round(order.subtotal_price || 0).toLocaleString()}</span>
                                </div>
                                ${order.discount_total > 0 ? `
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #dc2626; font-size: 13px;">Discount:</span>
                                    <span style="color: #dc2626; font-size: 13px;">- PKR ${order.discount_total.toLocaleString()}</span>
                                </div>
                                ` : ''}
                                ${order.shipping_total > 0 ? `
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #6b7280; font-size: 13px;">Shipping:</span>
                                    <span style="color: #1f2937; font-size: 13px;">PKR ${order.shipping_total.toLocaleString()}</span>
                                </div>
                                ` : ''}
                                <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #86efac; margin-top: 4px;">
                                    <span style="color: #059669; font-size: 16px; font-weight: 600;">Total:</span>
                                    <span style="color: #059669; font-size: 16px; font-weight: 600;">PKR ${Math.round(order.total_price || 0).toLocaleString()}</span>
                                </div>
                            </div>
                        </div>
                        
                        ${order.note ? `
                        <!-- Notes -->
                        <div style="margin-top: 16px; background: #fef3c7; padding: 12px 16px; border-radius: 8px; border: 1px solid #fcd34d;">
                            <h4 style="font-size: 12px; font-weight: 600; color: #92400e; margin: 0 0 4px 0;">📝 Order Notes</h4>
                            <p style="color: #78350f; font-size: 13px; margin: 0;">${order.note}</p>
                        </div>
                        ` : ''}
                        
                        <!-- Footer -->
                        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e5e7eb; text-align: center;">
                            <p style="color: #9ca3af; font-size: 11px; margin: 0;">
                                Legacy order imported on ${order.created_at ? new Date(order.created_at).toLocaleDateString() : 'N/A'}
                                ${order.import_batch_id ? ` • Batch: ${order.import_batch_id}` : ''}
                            </p>
                        </div>
                    </div>
                `;
            } else {
                content.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #ef4444;">
                        <p>Error loading history order details</p>
                        <p style="font-size: 13px; color: #6b7280;">${data.message || 'Unknown error'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error fetching history order:', error);
            content.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #ef4444;">
                    <p>Error loading history order details</p>
                    <p style="font-size: 13px; color: #6b7280;">${error.message}</p>
                </div>
            `;
        });
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
                            <td style="padding: 12px; text-align: right; color: #6b7280;">PKR ${Math.round(item.unit_price || item.price || 0).toLocaleString()}</td>
                            <td style="padding: 12px; text-align: right; font-weight: 600; color: #1f2937;">PKR ${Math.round(item.line_total || ((item.quantity || 0) * (item.unit_price || item.price || 0))).toLocaleString()}</td>
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
                                <span style="font-weight: 500;">PKR ${Math.round(order.subtotal_price || order.total_price || 0).toLocaleString()}</span>
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
                                <span style="font-weight: 600; color: #1f2937; font-size: 18px;">PKR ${Math.round(order.total_price || 0).toLocaleString()}</span>
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

// ========================================
// WhatsApp Messaging Functions for Customers
// ========================================
let customerWhatsappData = { customerName: '', phone: '' };

// Pinned message storage key
const PINNED_MSG_KEY = 'customerWhatsappPinnedMessage';

function escapeForJs(text) {
    if (!text) return '';
    return String(text).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}

function getPinnedMessage() {
    try {
        const stored = localStorage.getItem(PINNED_MSG_KEY);
        if (stored) return JSON.parse(stored);
    } catch (e) {}
    return null;
}

function savePinnedMessage(text, imageUrl, imagePath) {
    localStorage.setItem(PINNED_MSG_KEY, JSON.stringify({ text, imageUrl: imageUrl || '', imagePath: imagePath || '' }));
}

function clearPinnedMessage() {
    const pinned = getPinnedMessage();
    // Delete image from server if exists
    if (pinned && pinned.imagePath) {
        fetch('/customers/delete-promo-image', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ path: pinned.imagePath })
        }).catch(() => {});
    }
    localStorage.removeItem(PINNED_MSG_KEY);
}

function updatePinnedUI() {
    const pinned = getPinnedMessage();
    const pinnedBanner = document.getElementById('pinnedMessageBanner');
    const pinnedText = document.getElementById('pinnedMessageText');
    const pinnedImgPreview = document.getElementById('pinnedImagePreview');
    const textarea = document.getElementById('customerWhatsappCustomMessage');
    const pinBtn = document.getElementById('pinMessageBtn');
    const unpinBtn = document.getElementById('unpinMessageBtn');
    const imgUploadArea = document.getElementById('promoImageUploadArea');
    const imgPreviewArea = document.getElementById('promoImagePreviewArea');
    const imgPreview = document.getElementById('promoImagePreview');
    
    // Only treat imageUrl as valid if it actually points to a storage path
    var hasValidImage = pinned && pinned.imageUrl && pinned.imageUrl.indexOf('/storage/') !== -1;
    
    if (pinned && pinned.text) {
        // Show pinned banner
        pinnedBanner.style.display = 'block';
        pinnedText.textContent = pinned.text.length > 80 ? pinned.text.substring(0, 80) + '...' : pinned.text;
        
        // Pre-fill textarea
        textarea.value = pinned.text;
        
        // Show pinned image in banner
        if (hasValidImage) {
            pinnedImgPreview.src = pinned.imageUrl;
            pinnedImgPreview.style.display = 'block';
        } else {
            pinnedImgPreview.style.display = 'none';
        }
        
        // Show/hide buttons
        pinBtn.style.display = 'none';
        unpinBtn.style.display = 'flex';
        
        // Show image preview if exists
        if (hasValidImage) {
            imgUploadArea.style.display = 'none';
            imgPreviewArea.style.display = 'flex';
            imgPreview.src = pinned.imageUrl;
        } else {
            imgUploadArea.style.display = '';
            imgPreviewArea.style.display = 'none';
        }
    } else {
        // No pinned message
        pinnedBanner.style.display = 'none';
        textarea.value = '';
        pinBtn.style.display = 'flex';
        unpinBtn.style.display = 'none';
        imgUploadArea.style.display = '';
        imgPreviewArea.style.display = 'none';
    }
}

function pinCurrentMessage() {
    const text = document.getElementById('customerWhatsappCustomMessage').value.trim();
    if (!text) {
        alert('Please type a message first before pinning');
        return;
    }
    
    const pinned = getPinnedMessage();
    var rawSrc = pinned ? pinned.imageUrl : (document.getElementById('promoImagePreview').getAttribute('src') || '');
    var imageUrl = (rawSrc && rawSrc.indexOf('/storage/') !== -1) ? rawSrc : '';
    const imagePath = pinned ? pinned.imagePath : '';
    
    savePinnedMessage(text, imageUrl, imagePath);
    updatePinnedUI();
    
    // Show success feedback
    const pinBtn = document.getElementById('pinMessageBtn');
    const origText = pinBtn.innerHTML;
    pinBtn.innerHTML = '✅ Pinned!';
    setTimeout(() => { pinBtn.innerHTML = origText; }, 1500);
}

function unpinMessage() {
    if (confirm('Unpin this message? The image will also be removed.')) {
        clearPinnedMessage();
        document.getElementById('customerWhatsappCustomMessage').value = '';
        document.getElementById('promoImageInput').value = '';
        updatePinnedUI();
    }
}

function usePinnedMessage() {
    const pinned = getPinnedMessage();
    if (pinned && pinned.text) {
        document.getElementById('customerWhatsappCustomMessage').value = pinned.text;
    }
}

function openCustomerWhatsApp(customerName, phone, customerId) {
    customerWhatsappData = { customerName, phone };
    window._currentCustomerId = customerId || null;
    document.getElementById('customerWhatsappRecipient').textContent = 'To: ' + customerName + ' (' + phone + ')';
    
    // Load API templates
    loadApiTemplatesForCustomer(customerName);
    
    // Reset file input
    const fileInput = document.getElementById('promoImageInput');
    if (fileInput) fileInput.value = '';
    
    // Update pinned UI (pre-fills textarea if pinned)
    updatePinnedUI();
    
    document.getElementById('customerWhatsappModal').style.display = 'block';
}

function closeCustomerWhatsAppModal() {
    document.getElementById('customerWhatsappModal').style.display = 'none';
    customerWhatsappData = { customerName: '', phone: '' };
}

function formatPhoneForWhatsApp(phone) {
    if (!phone) return null;
    
    // Remove all non-digit characters
    let cleaned = phone.replace(/\D/g, '');
    
    // If starts with 92, already has country code
    if (cleaned.startsWith('92') && cleaned.length >= 11) {
        return cleaned;
    }
    
    // If starts with 0, remove it
    if (cleaned.startsWith('0')) {
        cleaned = cleaned.substring(1);
    }
    
    // Take last 10 digits
    if (cleaned.length > 10) {
        cleaned = cleaned.slice(-10);
    }
    
    // Add Pakistan country code
    return '92' + cleaned;
}

var _whatsappWindow = null;
function openWhatsAppWeb(phone, message) {
    var formattedPhone = formatPhoneForWhatsApp(phone);
    if (!formattedPhone) {
        alert('Invalid phone number');
        return;
    }
    
    var url = 'https://web.whatsapp.com/send?phone=' + formattedPhone;
    if (message) {
        url += '&text=' + encodeURIComponent(message);
    }
    
    // Try to reuse existing WhatsApp window by navigating it
    if (_whatsappWindow && !_whatsappWindow.closed) {
        try {
            _whatsappWindow.location.href = url;
            _whatsappWindow.focus();
            return;
        } catch(e) {
            // location assignment blocked - fall through to window.open
        }
    }
    
    _whatsappWindow = window.open(url, 'whatsapp_web');
    if (_whatsappWindow) _whatsappWindow.focus();
}

function sendCustomerWhatsAppDefault() {
    openWhatsAppWeb(customerWhatsappData.phone);
    closeCustomerWhatsAppModal();
}

function sendCustomerWhatsAppGreeting() {
    const message = `Assalam-o-Alaikum ${customerWhatsappData.customerName},

This is Nizami Farms. How can we help you today?

Best regards,
Nizami Farms Team`;
    
    sendViaApiOrManual(customerWhatsappData.phone, message, 'customer_greeting', [customerWhatsappData.customerName]);
    closeCustomerWhatsAppModal();
}

function sendViaApiOrManual(phone, fallbackMessage, templateName, bodyParams) {
    fetch('/messages/send-template', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ phone: phone, template_name: templateName, body_params: bodyParams || [] })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('Message sent via WhatsApp API', 'success');
        } else {
            openWhatsAppWeb(phone, fallbackMessage);
        }
    })
    .catch(() => {
        openWhatsAppWeb(phone, fallbackMessage);
    });
}

function showToast(msg, type) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:500;color:#fff;background:' + (type === 'success' ? '#16a34a' : '#ef4444') + ';box-shadow:0 4px 12px rgba(0,0,0,0.15);transition:opacity 0.3s;';
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity = '0'; setTimeout(function(){ t.remove(); }, 300); }, 3000);
}

var _cachedApiTemplates = null;
function loadApiTemplatesForCustomer(customerName) {
    var container = document.getElementById('waApiTemplateButtons');
    if (!container) return;
    container.innerHTML = '<span style="font-size:12px;color:#9ca3af;">Loading templates...</span>';
    
    if (_cachedApiTemplates) {
        renderApiTemplateButtons(_cachedApiTemplates, customerName);
        return;
    }
    
    fetch('/messages/templates?context=customers', { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        _cachedApiTemplates = d.templates || [];
        renderApiTemplateButtons(_cachedApiTemplates, customerName);
    })
    .catch(function() {
        container.innerHTML = '<span style="font-size:12px;color:#ef4444;">Could not load templates</span>';
    });
}

function renderApiTemplateButtons(templates, customerName) {
    var container = document.getElementById('waApiTemplateButtons');
    if (!container) return;
    if (!templates || templates.length === 0) {
        container.innerHTML = '<span style="font-size:12px;color:#9ca3af;">No approved templates yet</span>';
        return;
    }
    container.innerHTML = '';
    templates.forEach(function(tpl) {
        var btn = document.createElement('button');
        btn.style.cssText = 'padding:8px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;cursor:pointer;font-size:12px;font-weight:500;color:#166534;transition:all 0.2s;';
        btn.textContent = (tpl.display_name || tpl.name || 'Template').replace(/_/g, ' ');
        btn.title = 'Send via WhatsApp API';
        btn.onmouseover = function() { btn.style.background = '#dcfce7'; btn.style.borderColor = '#25D366'; };
        btn.onmouseout = function() { btn.style.background = '#f0fdf4'; btn.style.borderColor = '#bbf7d0'; };
        btn.onclick = function() { sendApiTemplate(tpl, customerName); };
        container.appendChild(btn);
    });
}

function sendApiTemplate(tpl, customerName) {
    var phone = customerWhatsappData.phone;
    if (!phone) return;

    var params = [];
    var varCount = tpl.variable_count || 0;
    if (varCount > 0) {
        params.push(customerName || 'Customer');
        for (var i = 1; i < varCount; i++) {
            var val = prompt('Enter value for variable {{' + (i + 1) + '}}:');
            if (val === null) return;
            params.push(val);
        }
    }
    
    fetch('/messages/send-template', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ phone: phone, template_name: tpl.name, body_params: params })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            showToast('Template "' + (tpl.display_name || tpl.name) + '" sent via API!', 'success');
            closeCustomerWhatsAppModal();
        } else {
            showToast(d.error || 'Failed to send template', 'error');
        }
    })
    .catch(function() {
        showToast('Network error - try again', 'error');
    });
}

function openCustomerInvoicePicker() {
    var area = document.getElementById('custInvPickerArea');
    if (!area) return;
    area.style.display = 'block';
    area.innerHTML = '<span style="font-size:12px;color:#9ca3af;">Loading orders...</span>';

    var phone = customerWhatsappData.phone;
    var name = customerWhatsappData.customerName;

    var custId = window._currentCustomerId;
    if (!custId) {
        area.innerHTML = '<span style="font-size:12px;color:#ef4444;">No customer ID available. Open customer details first.</span>';
        return;
    }

    fetch('/messages/customer-orders/' + custId, { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success || !d.orders || !d.orders.length) {
            area.innerHTML = '<span style="font-size:12px;color:#9ca3af;">No orders found.</span>';
            return;
        }
        var html = '';
        d.orders.forEach(function(o) {
            var dt = o.order_date ? new Date(o.order_date).toLocaleDateString() : '';
            html += '<div onclick="selectCustInvoice(' + o.id + ',\'' + (o.order_number||'').replace(/'/g,'') + '\',' + parseFloat(o.total||0) + ')" style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:6px;cursor:pointer;transition:background 0.15s;font-size:13px;" onmouseover="this.style.background=\'#fff7ed\'" onmouseout="this.style.background=\'#fff\'">';
            html += '<b>#' + (o.order_number||'') + '</b> · ' + dt + ' · Rs. ' + parseFloat(o.total||0).toLocaleString() + ' · ' + o.items_count + ' items';
            html += '</div>';
        });
        area.innerHTML = '<div style="font-size:11px;color:#6b7280;margin-bottom:6px;">Select order:</div>' + html;
    })
    .catch(function() { area.innerHTML = '<span style="font-size:12px;color:#ef4444;">Failed to load orders.</span>'; });
}

function selectCustInvoice(orderId, orderNum, total) {
    var area = document.getElementById('custInvPickerArea');
    var name = customerWhatsappData.customerName || '';
    area.innerHTML = '<div style="margin-bottom:8px;font-weight:600;font-size:13px;">Invoice for #' + orderNum + ' — Rs. ' + total.toLocaleString() + '</div>' +
        '<div style="margin-bottom:8px;"><label style="font-size:11px;color:#6b7280;">Template Name</label><input id="custInvTpl" type="text" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;" placeholder="e.g. send_invoice" /></div>' +
        '<div style="margin-bottom:8px;"><label style="font-size:11px;color:#6b7280;">Body Variables</label><input id="custInvParams" type="text" value="' + name + ', ' + orderNum + '" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;" /></div>' +
        '<div id="custInvPreviewArea" style="display:none;margin-bottom:8px;"><img id="custInvPreviewImg" style="max-width:100%;max-height:200px;border-radius:6px;border:1px solid #e5e7eb;cursor:pointer;" onclick="openFullscreenImg(this.src)" title="Click to view full size" /></div>' +
        '<div style="display:flex;gap:6px;">' +
            '<button onclick="previewCustInvoice(' + orderId + ')" id="custInvPrevBtn" style="flex:1;padding:8px;border:1px solid #d97706;color:#d97706;background:#fff;border-radius:6px;font-weight:600;cursor:pointer;font-size:12px;">Preview</button>' +
            '<button onclick="sendCustInvoice(' + orderId + ')" id="custInvSendBtn" style="flex:1;padding:8px;background:#25D366;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:12px;" disabled>Send</button>' +
        '</div>' +
        '<div id="custInvStatus" style="margin-top:6px;font-size:12px;text-align:center;display:none;"></div>';

    fetch('/messages/templates?context=invoice', {
        headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''}
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success && d.templates && d.templates.length) {
            var el = document.getElementById('custInvTpl');
            if (el && !el.value) el.value = d.templates[0].name;
        }
    }).catch(function() {});
}

function openFullscreenImg(src) {
    if (!src) return;
    var overlay = document.getElementById('waImgOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'waImgOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:99999;display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
    overlay.innerHTML = '<img src="' + src + '" style="max-width:92vw;max-height:92vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.5);" /><button style="position:absolute;top:16px;right:24px;background:rgba(255,255,255,0.15);border:none;color:#fff;font-size:28px;cursor:pointer;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;">&times;</button>';
    overlay.addEventListener('click', function() { overlay.remove(); });
    document.body.appendChild(overlay);
}

function captureInvoiceImageCust(invoiceUrl, orderId) {
    return new Promise(function(resolve, reject) {
        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:900px;height:1400px;border:none;opacity:0;';
        document.body.appendChild(iframe);
        iframe.src = invoiceUrl;
        iframe.onload = function() {
            var addScript = function(doc, src) { return new Promise(function(r) { var s = doc.createElement('script'); s.src = src; s.onload = r; doc.head.appendChild(s); }); };
            var iDoc = iframe.contentDocument || iframe.contentWindow.document;
            addScript(iDoc, 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js').then(function() {
                var node = iDoc.querySelector('.invoice-container');
                if (!node) { iframe.remove(); reject(new Error('Invoice container not found')); return; }
                iframe.contentWindow.html2canvas(node, {scale: 2, useCORS: true, allowTaint: true}).then(function(canvas) {
                    var dataUrl = canvas.toDataURL('image/png');
                    iframe.remove();
                    var csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                    fetch('/messages/upload-invoice-image', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                        body: JSON.stringify({ order_id: orderId, image_data: dataUrl })
                    }).then(function(r) { return r.json(); }).then(function(res) {
                        if (res.success) resolve(res); else reject(new Error(res.message || 'Upload failed'));
                    }).catch(reject);
                }).catch(function(err) { iframe.remove(); reject(err); });
            }).catch(function(err) { iframe.remove(); reject(err); });
        };
        iframe.onerror = function() { iframe.remove(); reject(new Error('Failed to load invoice')); };
    });
}

function previewCustInvoice(orderId) {
    var btn = document.getElementById('custInvPrevBtn');
    btn.textContent = 'Loading...'; btn.disabled = true;
    fetch('/messages/invoice-image/' + orderId, { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) { alert(d.message || 'Failed'); btn.textContent = 'Preview'; btn.disabled = false; return; }

        if (d.needs_capture) {
            captureInvoiceImageCust(d.invoice_url, orderId).then(function(uploadRes) {
                document.getElementById('custInvPreviewImg').src = uploadRes.image_url;
                document.getElementById('custInvPreviewArea').style.display = 'block';
                document.getElementById('custInvSendBtn').disabled = false;
                btn.textContent = 'Refresh'; btn.disabled = false;
            }).catch(function(err) { alert('Failed to capture: ' + err.message); btn.textContent = 'Preview'; btn.disabled = false; });
        } else {
            document.getElementById('custInvPreviewImg').src = d.image_url;
            document.getElementById('custInvPreviewArea').style.display = 'block';
            document.getElementById('custInvSendBtn').disabled = false;
            btn.textContent = 'Refresh'; btn.disabled = false;
        }
    })
    .catch(function() { btn.textContent = 'Preview'; btn.disabled = false; });
}

function sendCustInvoice(orderId) {
    var tplName = document.getElementById('custInvTpl').value.trim();
    if (!tplName) { alert('Enter template name'); return; }
    var paramsStr = document.getElementById('custInvParams').value.trim();
    var bodyParams = paramsStr ? paramsStr.split(',').map(function(s) { return s.trim(); }) : [];
    var phone = customerWhatsappData.phone;
    if (!phone) { alert('No phone'); return; }

    var btn = document.getElementById('custInvSendBtn');
    var status = document.getElementById('custInvStatus');
    btn.textContent = 'Sending...'; btn.disabled = true; status.style.display = 'none';

    fetch('/messages/send-invoice', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json' },
        body: JSON.stringify({ order_id: orderId, phone: phone, template_name: tplName, body_params: bodyParams })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            status.style.display = 'block'; status.style.color = '#16a34a'; status.textContent = 'Invoice sent!';
            btn.textContent = 'Sent!';
            setTimeout(function() { closeCustomerWhatsAppModal(); }, 2000);
        } else { status.style.display = 'block'; status.style.color = '#dc2626'; status.textContent = d.message || 'Failed'; btn.textContent = 'Send'; btn.disabled = false; }
    })
    .catch(function(e) { status.style.display = 'block'; status.style.color = '#dc2626'; status.textContent = 'Error'; btn.textContent = 'Send'; btn.disabled = false; });
}

function sendCustomerWhatsAppCustom() {
    var customMessage = document.getElementById('customerWhatsappCustomMessage').value.trim();
    if (!customMessage) {
        alert('Please type a message first');
        return;
    }
    
    // Only append image URL if it's a valid storage image (not a page URL)
    var pinned = getPinnedMessage();
    if (pinned && pinned.imageUrl && pinned.imageUrl.indexOf('/storage/') !== -1) {
        var imgUrl = pinned.imageUrl.replace('://app.nizamifarms.com', '://www.nizamifarms.com');
        customMessage += '\n\n' + imgUrl;
    }
    
    openWhatsAppWeb(customerWhatsappData.phone, customMessage);
    closeCustomerWhatsAppModal();
}

function handlePromoImageUpload(input) {
    if (!input.files || !input.files[0]) return;
    
    const file = input.files[0];
    
    // Validate file size (5MB)
    if (file.size > 5 * 1024 * 1024) {
        alert('Image must be less than 5MB');
        input.value = '';
        return;
    }
    
    // Show uploading state
    const uploadArea = document.getElementById('promoImageUploadArea');
    const origContent = uploadArea.innerHTML;
    uploadArea.innerHTML = '<div style="text-align: center; padding: 8px; color: #6b7280;"><div style="display: inline-block; width: 20px; height: 20px; border: 2px solid #e5e7eb; border-top: 2px solid #25D366; border-radius: 50%; animation: spin 1s linear infinite;"></div> Uploading...</div>';
    
    const formData = new FormData();
    formData.append('image', file);
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    fetch('/customers/upload-promo-image', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Update pinned message with image
            const text = document.getElementById('customerWhatsappCustomMessage').value.trim();
            const pinned = getPinnedMessage();
            
            // Delete old image if replacing
            if (pinned && pinned.imagePath && pinned.imagePath !== data.path) {
                fetch('/customers/delete-promo-image', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ path: pinned.imagePath })
                }).catch(() => {});
            }
            
            savePinnedMessage(text || (pinned ? pinned.text : ''), data.url, data.path);
            updatePinnedUI();
        } else {
            alert('Upload failed: ' + (data.message || 'Unknown error'));
            uploadArea.innerHTML = origContent;
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        alert('Upload failed. Please try again.');
        uploadArea.innerHTML = origContent;
    });
}

function removePromoImage() {
    const pinned = getPinnedMessage();
    if (pinned) {
        // Delete from server
        if (pinned.imagePath) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch('/customers/delete-promo-image', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ path: pinned.imagePath })
            }).catch(() => {});
        }
        savePinnedMessage(pinned.text, '', '');
    }
    document.getElementById('promoImageInput').value = '';
    updatePinnedUI();
}
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
            <div class="flex items-center gap-3 text-sm">
                <a href="{{ route('customers.index', array_merge(request()->except(['activity', 'page']), ['activity' => '30day'])) }}"
                   class="flex items-center gap-2 px-3 py-1.5 rounded-full transition-all cursor-pointer {{ request('activity') === '30day' ? 'bg-green-100 ring-2 ring-green-400 font-semibold' : 'hover:bg-green-50' }}">
                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                    <span>{{ $stats['active_30_days'] }} 30-Day Active</span>
                </a>
                <a href="{{ route('customers.index', array_merge(request()->except(['activity', 'page']), ['activity' => '90day'])) }}"
                   class="flex items-center gap-2 px-3 py-1.5 rounded-full transition-all cursor-pointer {{ request('activity') === '90day' ? 'bg-orange-100 ring-2 ring-orange-400 font-semibold' : 'hover:bg-orange-50' }}">
                    <div class="w-3 h-3 bg-orange-500 rounded-full"></div>
                    <span>{{ $stats['active_90_days'] }} 90-Day Active</span>
                </a>
                <a href="{{ route('customers.index', request()->except(['activity', 'page'])) }}"
                   class="flex items-center gap-2 px-3 py-1.5 rounded-full transition-all cursor-pointer {{ !request('activity') ? 'bg-blue-100 ring-2 ring-blue-400 font-semibold' : 'hover:bg-blue-50' }}">
                    <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                    <span>{{ $stats['total_customers'] }} Total</span>
                </a>
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
                    <a href="{{ route('customers.index', request()->except(['activity', 'page'])) }}" class="flex items-center gap-2 cursor-pointer rounded-lg px-2 py-1 transition-all {{ !request('activity') ? 'bg-blue-50 ring-1 ring-blue-300' : 'hover:bg-blue-50' }}">
                        <div class="w-7 h-7 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-people text-blue-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">Total</span>
                            <span class="text-base font-bold text-gray-900 ml-1">{{ number_format($stats['total_customers']) }}</span>
                        </div>
                    </a>
                    <a href="{{ route('customers.index', array_merge(request()->except(['activity', 'page']), ['activity' => '30day'])) }}" class="flex items-center gap-2 cursor-pointer rounded-lg px-2 py-1 transition-all {{ request('activity') === '30day' ? 'bg-green-50 ring-1 ring-green-300' : 'hover:bg-green-50' }}">
                        <div class="w-7 h-7 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-calendar text-green-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">30-Day Active</span>
                            <span class="text-base font-bold text-green-700 ml-1">{{ number_format($stats['active_30_days']) }}</span>
                        </div>
                    </a>
                    <a href="{{ route('customers.index', array_merge(request()->except(['activity', 'page']), ['activity' => '90day'])) }}" class="flex items-center gap-2 cursor-pointer rounded-lg px-2 py-1 transition-all {{ request('activity') === '90day' ? 'bg-orange-50 ring-1 ring-orange-300' : 'hover:bg-orange-50' }}">
                        <div class="w-7 h-7 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-timer text-orange-600 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">90-Day Active</span>
                            <span class="text-base font-bold text-orange-700 ml-1">{{ number_format($stats['active_90_days']) }}</span>
                        </div>
                    </a>
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
                    <button onclick="openDuplicatesModal()" class="kt-btn kt-btn-sm kt-btn-outline" title="Find and merge duplicate customers">
                        <i class="ki-filled ki-people"></i> Manage Duplicates
                    </button>
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
                    
                    <select name="region" class="select select-sm w-36 text-xs" id="customerRegionFilter">
                        <option value="">All Regions</option>
                        @foreach($regions as $region)
                            <option value="{{ $region->id }}" {{ request('region') == $region->id ? 'selected' : '' }}>
                                {{ $region->name }}
                            </option>
                        @endforeach
                        <option value="none" {{ request('region') == 'none' ? 'selected' : '' }}>No Region</option>
                    </select>
                    
                    <select name="activity" class="select select-sm w-36 text-xs" id="customerActivityFilter">
                        <option value="" {{ !request('activity') ? 'selected' : '' }}>All Customers</option>
                        <option value="30day" {{ request('activity') == '30day' ? 'selected' : '' }}>30-Day Active</option>
                        <option value="90day" {{ request('activity') == '90day' ? 'selected' : '' }}>90-Day Active</option>
                    </select>
                    
                    <select name="sort" class="select select-sm w-44 text-xs" id="customerSortFilter">
                        <option value="last_order_date_desc" {{ (request('sort_by', 'last_order_date') == 'last_order_date' && request('sort_dir', 'desc') == 'desc') ? 'selected' : '' }}>Last Order ↓ Newest</option>
                        <option value="last_order_date_asc" {{ (request('sort_by') == 'last_order_date' && request('sort_dir') == 'asc') ? 'selected' : '' }}>Last Order ↑ Oldest</option>
                        <option value="total_spent_desc" {{ (request('sort_by') == 'total_spent' && request('sort_dir', 'desc') == 'desc') ? 'selected' : '' }}>Spent ↓ Highest</option>
                        <option value="total_spent_asc" {{ (request('sort_by') == 'total_spent' && request('sort_dir') == 'asc') ? 'selected' : '' }}>Spent ↑ Lowest</option>
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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Notes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">First Order</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Last Order</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Actions</th>
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
                                        @if($customer->notes)
                                            <span class="text-sm text-gray-600" title="{{ $customer->notes }}">{{ Str::limit($customer->notes, 30) }}</span>
                                        @else
                                            <span class="text-sm text-gray-400">No notes</span>
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
                                            @if($customer->phone_original || $customer->phone)
                                                <button onclick="openCustomerWhatsApp('{{ addslashes($customer->first_name . ' ' . $customer->last_name) }}', '{{ addslashes($customer->phone_original ?: $customer->phone) }}', {{ $customer->id }})" 
                                                        class="inline-flex items-center p-1.5 border border-green-300 rounded-md text-green-600 hover:text-green-700 hover:bg-green-50 transition-colors duration-150" 
                                                        title="WhatsApp">
                                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                                </button>
                                            @endif
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

<!-- WhatsApp Modal for Customers -->
<div id="customerWhatsappModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 10200;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 480px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Header -->
        <div style="padding: 16px 20px; background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); border-radius: 12px 12px 0 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: white;">💬 WhatsApp Message</h3>
                <button onclick="closeCustomerWhatsAppModal()" style="background: none; border: none; font-size: 24px; color: white; cursor: pointer; line-height: 1;">&times;</button>
            </div>
            <p id="customerWhatsappRecipient" style="margin: 6px 0 0 0; font-size: 14px; color: rgba(255,255,255,0.85);"></p>
        </div>
        
        <div style="padding: 20px;">
            <!-- Pinned Message Banner -->
            <div id="pinnedMessageBanner" style="display: none; margin-bottom: 14px; padding: 10px 14px; background: #fefce8; border: 1px solid #fde68a; border-radius: 8px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                    <span style="font-size: 14px;">📌</span>
                    <span style="font-size: 12px; font-weight: 600; color: #92400e;">PINNED PROMO MESSAGE</span>
                </div>
                <p id="pinnedMessageText" style="margin: 0; font-size: 13px; color: #78350f; line-height: 1.4;"></p>
                <img id="pinnedImagePreview" src="" alt="" style="display: none; margin-top: 8px; max-height: 60px; border-radius: 6px; border: 1px solid #fde68a;">
            </div>
        
            <!-- Quick Actions -->
            <div style="display: flex; gap: 8px; margin-bottom: 14px;">
                <button onclick="sendCustomerWhatsAppDefault()" style="flex: 1; padding: 10px 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s;" onmouseover="this.style.background='#f3f4f6';this.style.borderColor='#25D366'" onmouseout="this.style.background='#f9fafb';this.style.borderColor='#e5e7eb'">
                    <span style="font-size: 18px;">💬</span>
                <div style="text-align: left;">
                        <div style="font-weight: 600; color: #111827; font-size: 13px;">Open Chat</div>
                        <div style="font-size: 11px; color: #6b7280;">Blank message</div>
                </div>
            </button>
                <button onclick="sendCustomerWhatsAppGreeting()" style="flex: 1; padding: 10px 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s;" onmouseover="this.style.background='#f3f4f6';this.style.borderColor='#25D366'" onmouseout="this.style.background='#f9fafb';this.style.borderColor='#e5e7eb'">
                    <span style="font-size: 18px;">👋</span>
                <div style="text-align: left;">
                        <div style="font-weight: 600; color: #111827; font-size: 13px;">Greeting</div>
                        <div style="font-size: 11px; color: #6b7280;">Hello message</div>
                </div>
            </button>
            </div>
            
            <!-- API Templates Section -->
            <div id="waApiTemplatesSection" style="margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                    <div style="flex: 1; height: 1px; background: #e5e7eb;"></div>
                    <span style="font-size: 11px; color: #9ca3af; font-weight: 500;">SEND VIA WHATSAPP API</span>
                    <div style="flex: 1; height: 1px; background: #e5e7eb;"></div>
                </div>
                <div id="waApiTemplateButtons" style="display: flex; flex-wrap: wrap; gap: 8px;"></div>
            </div>

            <!-- Send Invoice Section -->
            <div style="margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                    <div style="flex: 1; height: 1px; background: #e5e7eb;"></div>
                    <span style="font-size: 11px; color: #9ca3af; font-weight: 500;">SEND INVOICE</span>
                    <div style="flex: 1; height: 1px; background: #e5e7eb;"></div>
                </div>
                <button onclick="openCustomerInvoicePicker()" style="padding:8px 16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;cursor:pointer;font-size:12px;font-weight:500;color:#9a3412;transition:all 0.2s;display:flex;align-items:center;gap:6px;" onmouseover="this.style.background='#ffedd5';this.style.borderColor='#f59e0b'" onmouseout="this.style.background='#fff7ed';this.style.borderColor='#fed7aa'">
                    <span>📄</span> Send Invoice via WhatsApp
                </button>
                <div id="custInvPickerArea" style="display:none;margin-top:10px;"></div>
            </div>

            <!-- Divider -->
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                <div style="flex: 1; height: 1px; background: #e5e7eb;"></div>
                <span style="font-size: 11px; color: #9ca3af; font-weight: 500;">CUSTOM / PROMO MESSAGE</span>
                <div style="flex: 1; height: 1px; background: #e5e7eb;"></div>
            </div>
            
            <!-- Custom Message Textarea -->
            <div style="margin-bottom: 10px;">
                <textarea id="customerWhatsappCustomMessage" rows="3" 
                    style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; resize: vertical; font-family: inherit; outline: none; transition: border-color 0.2s; box-sizing: border-box;"
                    onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#d1d5db'"
                    placeholder="Type your custom or promo message here..."></textarea>
            </div>
            
            <!-- Image Upload Area -->
            <div id="promoImageUploadArea" style="margin-bottom: 12px;">
                <label style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px dashed #d1d5db; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: #fafafa;" onmouseover="this.style.borderColor='#25D366';this.style.background='#f0fdf4'" onmouseout="this.style.borderColor='#d1d5db';this.style.background='#fafafa'">
                    <svg style="width: 20px; height: 20px; color: #9ca3af; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span style="font-size: 13px; color: #6b7280;">Attach promo image (optional) — will be sent as link</span>
                    <input type="file" id="promoImageInput" accept="image/*" style="display: none;" onchange="handlePromoImageUpload(this)">
                </label>
            </div>
            
            <!-- Image Preview Area (shown when image uploaded) -->
            <div id="promoImagePreviewArea" style="display: none; margin-bottom: 12px; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #f9fafb; align-items: center; gap: 12px;">
                <img id="promoImagePreview" src="" alt="Promo" style="height: 60px; max-width: 100px; border-radius: 6px; object-fit: cover; border: 1px solid #e5e7eb;">
                <div style="flex: 1; min-width: 0;">
                    <div style="font-size: 12px; font-weight: 500; color: #374151;">Promo image attached</div>
                    <div style="font-size: 11px; color: #6b7280;">Image link will be included in message</div>
                </div>
                <button onclick="removePromoImage()" style="padding: 4px 8px; background: none; border: 1px solid #fca5a5; border-radius: 6px; cursor: pointer; color: #dc2626; font-size: 12px; flex-shrink: 0;" title="Remove image">✕</button>
            </div>
            
            <!-- Pin / Send / Cancel -->
            <div style="display: flex; gap: 8px; margin-bottom: 8px;">
                <button onclick="sendCustomerWhatsAppCustom()" style="flex: 1; padding: 11px 16px; background: #25D366; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.background='#128C7E'" onmouseout="this.style.background='#25D366'">
                    <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                    Send
                </button>
                <button id="pinMessageBtn" onclick="pinCurrentMessage()" style="padding: 11px 14px; background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; color: #92400e; display: flex; align-items: center; gap: 6px; transition: all 0.2s; white-space: nowrap;" onmouseover="this.style.background='#fef08a'" onmouseout="this.style.background='#fefce8'" title="Pin this message so it stays for all customers">
                    📌 Pin
                </button>
                <button id="unpinMessageBtn" onclick="unpinMessage()" style="display: none; padding: 11px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; color: #dc2626; align-items: center; gap: 6px; transition: all 0.2s; white-space: nowrap;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'" title="Unpin this message">
                    📌 Unpin
                </button>
                <button onclick="closeCustomerWhatsAppModal()" style="padding: 11px 14px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px; cursor: pointer; font-weight: 500; color: #374151; font-size: 13px; transition: all 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                    Close
            </button>
            </div>
            
            <div style="font-size: 11px; color: #9ca3af; text-align: center;">
                💡 <b>Pin</b> a message to auto-fill it for every customer. Great for promos!
            </div>
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

<!-- History Order Details Modal -->
<div id="historyOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1002; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; width: 95%; max-width: 900px; max-height: 90vh; overflow-y: auto; margin: auto;">
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #9333ea 0%, #7c3aed 100%); border-radius: 12px 12px 0 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: white;">📜 Legacy Order Details</h3>
                <button onclick="closeModal('historyOrderModal')" 
                        style="background: rgba(255,255,255,0.2); border: none; font-size: 20px; color: white; cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">&times;</button>
            </div>
        </div>
        <div id="historyOrderContent" style="max-height: calc(90vh - 80px); overflow-y: auto;">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<style>
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

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
                dropdown.innerHTML = data.map(customer => {
                    // Build address display for consistency
                    const addressParts = [];
                    if (customer.address1) addressParts.push(customer.address1);
                    if (customer.city) addressParts.push(customer.city);
                    if (customer.province) addressParts.push(customer.province);
                    const addressDisplay = addressParts.length > 0 ? addressParts.join(', ') : 'No address';
                    
                    return `
                        <div onclick="selectCustomer(${customer.id}, '${customer.first_name} ${customer.last_name}', '${customer.phone_original || customer.phone || ''}')" 
                             style="padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #e5e7eb; transition: background-color 0.15s ease;"
                             onmouseover="this.style.backgroundColor='#f8fafc'" 
                             onmouseout="this.style.backgroundColor='white'">
                            <div style="font-weight: 500; color: #374151; font-size: 14px; margin-bottom: 2px;">
                                ${customer.first_name} ${customer.last_name}
                            </div>
                            <div style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">
                                📞 ${customer.phone_original || customer.phone || 'No phone'} ${customer.email ? '• ✉️ ' + customer.email : ''}
                            </div>
                            <div style="font-size: 12px; color: #9ca3af;">
                                📍 ${addressDisplay}
                            </div>
                        </div>
                    `;
                }).join('');
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

// Helper function to build customer address display (reusing logic from orders page)
function buildCustomerAddress(customer) {
    if (!customer) return 'No address provided';
    
    const addressParts = [];
    if (customer.address1) addressParts.push(customer.address1);
    if (customer.address2) addressParts.push(customer.address2);
    if (customer.city) addressParts.push(customer.city);
    if (customer.province) addressParts.push(customer.province);
    if (customer.postal_code) addressParts.push(customer.postal_code);
    if (customer.country) addressParts.push(customer.country);
    
    return addressParts.length > 0 ? addressParts.join(', ') : 'No address provided';
}

function openCreateOrderModal(customer = null) {
    const modal = document.getElementById('createOrderModal');
    const content = document.getElementById('createOrderContent');
    
    // Load the order creation form (simplified version)
    content.innerHTML = `
        <form id="createOrderForm">
            <!-- Enhanced Customer pre-filled section -->
            <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 1px solid #bae6fd; border-radius: 8px; margin-bottom: 20px; padding: 16px;">
                <div style="display: flex; align-items: center; margin-bottom: 12px;">
                    <span style="font-size: 18px; margin-right: 8px;">👤</span>
                    <h4 style="color: #0369a1; margin: 0; font-weight: 600;">Selected Customer</h4>
                </div>
                ${customer ? `
                    <div style="margin-bottom: 8px;">
                        <strong style="color: #374151; font-size: 16px;">${(customer.first_name + ' ' + customer.last_name).trim()}</strong>
                    </div>
                    <div style="margin-bottom: 6px; color: #6b7280; font-size: 14px;">
                        📞 ${customer.phone_original || customer.phone || 'No phone'} ${customer.email ? '• ✉️ ' + customer.email : ''}
                    </div>
                    <div style="color: #6b7280; font-size: 14px; line-height: 1.4;">
                        📍 ${buildCustomerAddress(customer)}
                    </div>
                ` : '<p style="margin: 0; color: #6b7280; font-style: italic;">No customer selected</p>'}
                <input type="hidden" name="customer_id" value="${customer ? customer.id : ''}">
            </div>

            <!-- Order Information -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                <div>
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Details</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Order Status</label>
                            <select name="order_status" id="customerCreateOrderStatus" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                <option value="">Loading statuses...</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Order Date & Time</label>
                            <input type="datetime-local" name="order_date" required value="${getCurrentLocalDateTime()}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Contact Email</label>
                            <input type="email" name="contact_email" value="${customer ? (customer.email || '') : ''}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Payment Method</label>
                            <select name="payment_method" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                <option value="">Select Payment Method</option>
                                <option value="cash" selected>Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="card">Card</option>
                                <option value="online">Online Payment</option>
                            </select>
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
                            </div>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 6px;">Discounts</label>
                            <div id="customerOrderDiscountsContainer" style="display: flex; flex-direction: column; gap: 6px;">
                                <!-- Discount rows will be populated here -->
                            </div>
                            <button type="button" onclick="addCustomerOrderDiscountRow()" 
                                    style="margin-top: 6px; padding: 5px 10px; background: #10b981; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;">
                                + Add Discount
                            </button>
                            <div style="margin-top: 6px; padding: 6px; background: #f3f4f6; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-weight: 600; color: #374151; font-size: 12px;">Total Discount:</span>
                                <span id="customerOrderTotalDiscountDisplay" style="font-weight: 700; color: #ef4444; font-size: 14px;">Rs. 0.00</span>
                            </div>
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

    // Load status options from master table and default to 'new'
    (async function(){
        try {
            const resp = await fetch('/order-status/api/statuses', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                credentials: 'same-origin'
            });
            const data = await resp.json();
            const sel = document.getElementById('customerCreateOrderStatus');
            if (data && data.success && sel) {
                sel.innerHTML = data.data.map(s => `<option value="${s.status_code}">${s.icon} ${s.status_name}</option>`).join('');
                if (data.data.find(s => s.status_code === 'new')) sel.value = 'new';
            }
        } catch (e) {
            const sel = document.getElementById('customerCreateOrderStatus');
            if (sel) sel.innerHTML = `
                <option value="new" selected>⏳ New</option>
                <option value="processing">⚡ Processing</option>
                <option value="out_for_delivery">🚚 Out for Delivery</option>
                <option value="delivered">✓ Delivered</option>
                <option value="on_hold">⏸ On Hold</option>
                <option value="cancelled">✕ Cancelled</option>
                <option value="refunded">↩ Refunded</option>`;
        }
    })();
    
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

// ⭐ Handle Tab navigation from Qty field - skip to next row's product name
function handleQtyTabNavigation(event, currentIndex) {
    if (event.key === 'Tab' && !event.shiftKey) {
        event.preventDefault();
        
        const container = document.getElementById('lineItemsContainer');
        if (!container) return;
        
        const lineItems = Array.from(container.querySelectorAll('.line-item'));
        
        // Find current line item position
        let currentPosition = -1;
        lineItems.forEach((item, idx) => {
            const qtyInput = item.querySelector(`input[name="items[${currentIndex}][quantity]"]`);
            if (qtyInput) currentPosition = idx;
        });
        
        // Find next line item
        if (currentPosition >= 0 && currentPosition < lineItems.length - 1) {
            const nextItem = lineItems[currentPosition + 1];
            const nextNameInput = nextItem.querySelector('input[name*="[name]"]');
            if (nextNameInput && !nextNameInput.readOnly) {
                nextNameInput.focus();
                return;
            }
        }
        
        // If we're on the last row or next row's name is readonly, add new row and focus it
        addLineItem();
        setTimeout(() => {
            const newLineItems = container.querySelectorAll('.line-item');
            if (newLineItems.length > 0) {
                const newItem = newLineItems[newLineItems.length - 1];
                const newNameInput = newItem.querySelector('input[name*="[name]"]');
                if (newNameInput) {
                    newNameInput.focus();
                }
            }
        }, 50);
    }
}

function addLineItem() {
    const container = document.getElementById('lineItemsContainer');
    
    // Remove "no items" message if it exists
    if (container.innerHTML.includes('No line items')) {
        container.innerHTML = '';
    }
    
    const itemHtml = `
        <div class="line-item" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 12px; background-color: #fefefe;">
            <div style="display: grid; grid-template-columns: 3fr 70px 90px 110px 32px; gap: 12px; align-items: end;">
                <div style="position: relative;">
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Product Name</label>
                    <input type="text" name="items[${lineItemIndex}][name]" 
                           style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                           placeholder="Type to search products..."
                           onkeyup="searchProducts(this, ${lineItemIndex})" 
                           onfocus="showProductDropdown(${lineItemIndex})"
                           onblur="hideProductDropdown(${lineItemIndex})">
                    <div id="productDropdown_${lineItemIndex}" class="product-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                    <span id="skuDisplay_${lineItemIndex}" style="display: none; margin-top: 4px; font-size: 11px; color: #0369a1; background: #dbeafe; padding: 2px 6px; border-radius: 3px;"></span>
                    <input type="hidden" name="items[${lineItemIndex}][id]" value="">
                    <input type="hidden" name="items[${lineItemIndex}][sku]" value="">
                    <input type="hidden" name="items[${lineItemIndex}][variant_id]" value="">
                    <input type="hidden" name="items[${lineItemIndex}][product_id]" value="">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
                    <input type="number" name="items[${lineItemIndex}][quantity]" step="0.01" min="0" value="1" 
                           style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                           onchange="updateLineTotal(${lineItemIndex})"
                           onkeydown="handleQtyTabNavigation(event, ${lineItemIndex})">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price</label>
                    <input type="number" name="items[${lineItemIndex}][unit_price]" step="0.01" min="0" value="0" tabindex="-1"
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
    
    // Calculate total discount from all discount rows
    let totalDiscount = 0;
    document.querySelectorAll('#customerOrderDiscountsContainer .discount-row').forEach(row => {
        const amount = parseFloat(row.querySelector('[name$="[amount]"]')?.value) || 0;
        totalDiscount += amount;
    });
    
    const shipping = parseFloat(document.querySelector('input[name="shipping_total"]')?.value) || 0;
    
    // Update discount display
    const discountDisplay = document.getElementById('customerOrderTotalDiscountDisplay');
    if (discountDisplay) {
        discountDisplay.textContent = 'Rs. ' + totalDiscount.toFixed(2);
    }
    
    // Calculate final total
    const total = subtotal - totalDiscount + shipping;
    const totalInput = document.querySelector('input[name="total_price"]');
    if (totalInput) {
        totalInput.value = total.toFixed(2);
    }
}

// Customer order discount management functions
let customerOrderDiscountRowIndex = 0;

function addCustomerOrderDiscountRow(title = '', amount = 0) {
    const container = document.getElementById('customerOrderDiscountsContainer');
    if (!container) return;
    
    const index = customerOrderDiscountRowIndex++;
    
    const row = document.createElement('div');
    row.className = 'discount-row';
    row.setAttribute('data-index', index);
    row.style.cssText = 'display:flex;gap:6px;align-items:center;background:#f9fafb;padding:6px;border-radius:4px;border:1px solid #e5e7eb;position:relative;';
    
    row.innerHTML = `
        <div style="flex:2;position:relative;">
            <input type="text" 
                   name="discounts[${index}][title]" 
                   id="discountTitle_${index}"
                   placeholder="Discount title (e.g. Member Discount)" 
                   value="${title}"
                   onkeyup="searchDiscountCoupons(${index}, this.value)"
                   onfocus="showDiscountDropdown(${index})"
                   onblur="hideDiscountDropdown(${index})"
                   style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
            <div id="discountDropdown_${index}" 
                 style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #d1d5db;border-radius:4px;max-height:150px;overflow-y:auto;z-index:1000;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);margin-top:2px;">
            </div>
        </div>
        <input type="number" 
               step="0.01" 
               name="discounts[${index}][amount]" 
               id="discountAmount_${index}"
               placeholder="Amount" 
               value="${amount}"
               onchange="updateOrderTotal()"
               style="flex:1;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
        <button type="button" 
                onclick="removeCustomerOrderDiscountRow(${index})" 
                style="padding:4px 8px;background:#ef4444;color:#fff;border:0;border-radius:4px;cursor:pointer;font-size:13px;font-weight:600;">
            ×
        </button>
    `;
    
    container.appendChild(row);
    updateOrderTotal();
}

// Discount coupon autocomplete functionality
let discountSearchTimeouts = {};
function searchDiscountCoupons(rowIndex, query) {
    clearTimeout(discountSearchTimeouts[rowIndex]);
    
    if (query.length < 2) {
        const dropdown = document.getElementById(`discountDropdown_${rowIndex}`);
        if (dropdown) dropdown.style.display = 'none';
        return;
    }
    
    discountSearchTimeouts[rowIndex] = setTimeout(() => {
        fetch(`/coupons/search?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                const dropdown = document.getElementById(`discountDropdown_${rowIndex}`);
                if (!dropdown) return;
                
                if (data.success && data.data && data.data.length > 0) {
                    dropdown.innerHTML = data.data.map(coupon => `
                        <div onmousedown="selectDiscountCoupon(${rowIndex}, '${escapeHtml(coupon.title)}', ${coupon.value}, '${coupon.value_type}')" 
                             style="padding: 6px 10px; cursor: pointer; border-bottom: 1px solid #f3f4f6;"
                             onmouseover="this.style.backgroundColor='#f3f4f6'" 
                             onmouseout="this.style.backgroundColor='white'">
                            <div style="font-weight: 500; font-size: 12px;">${escapeHtml(coupon.display || coupon.title)}</div>
                            <div style="font-size: 11px; color: #6b7280;">
                                ${coupon.value_type === 'percentage' ? coupon.value + '%' : 'PKR ' + coupon.value} off
                            </div>
                        </div>
                    `).join('');
                    dropdown.style.display = 'block';
                } else {
                    dropdown.innerHTML = '<div style="padding: 6px 10px; color: #6b7280; font-size: 11px;">No coupons found</div>';
                    dropdown.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error searching discount coupons:', error);
            });
    }, 300);
}

function showDiscountDropdown(rowIndex) {
    const input = document.getElementById(`discountTitle_${rowIndex}`);
    if (input && input.value.length >= 2) {
        searchDiscountCoupons(rowIndex, input.value);
    }
}

function hideDiscountDropdown(rowIndex) {
    setTimeout(() => {
        const dropdown = document.getElementById(`discountDropdown_${rowIndex}`);
        if (dropdown) dropdown.style.display = 'none';
    }, 200);
}

function selectDiscountCoupon(rowIndex, title, value, valueType) {
    const titleInput = document.getElementById(`discountTitle_${rowIndex}`);
    const amountInput = document.getElementById(`discountAmount_${rowIndex}`);
    
    if (titleInput) titleInput.value = title;
    
    // Auto-calculate discount based on subtotal if it's percentage
    if (amountInput) {
        if (valueType === 'percentage') {
            const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
            const discountAmount = (subtotal * value) / 100;
            amountInput.value = discountAmount.toFixed(2);
        } else {
            amountInput.value = value.toFixed(2);
        }
    }
    
    updateOrderTotal();
    
    // Hide dropdown
    const dropdown = document.getElementById(`discountDropdown_${rowIndex}`);
    if (dropdown) dropdown.style.display = 'none';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function removeCustomerOrderDiscountRow(index) {
    const row = document.querySelector(`#customerOrderDiscountsContainer .discount-row[data-index="${index}"]`);
    if (row) {
        row.remove();
        updateOrderTotal();
    }
}

// Initialize with one empty discount row when modal opens
document.addEventListener('DOMContentLoaded', function() {
    // Add initial discount row when create order modal is shown
    const createOrderModal = document.getElementById('createOrderModal');
    if (createOrderModal) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'style' && createOrderModal.style.display === 'block') {
                    const container = document.getElementById('customerOrderDiscountsContainer');
                    if (container && container.children.length === 0) {
                        addCustomerOrderDiscountRow();
                    }
                }
            });
        });
        observer.observe(createOrderModal, { attributes: true });
    }
});

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
        const sku = item.querySelector(`input[name*="[sku]"]`)?.value || null;
        const variantId = item.querySelector(`input[name*="[variant_id]"]`)?.value || null;
        const productId = item.querySelector(`input[name*="[product_id]"]`)?.value || null;
        
        if (name && quantity > 0 && unitPrice >= 0) {
            items.push({
                name: name,
                quantity: quantity,
                unit_price: unitPrice,
                line_total: quantity * unitPrice,
                sku: sku,
                variant_id: variantId,
                product_id: productId
            });
        }
    });
    
    if (items.length === 0) {
        alert('Please add at least one line item');
        return;
    }
    
    // Collect discounts
    const discounts = [];
    document.querySelectorAll('#customerOrderDiscountsContainer .discount-row').forEach((row) => {
        const title = row.querySelector('[name$="[title]"]')?.value;
        const amount = parseFloat(row.querySelector('[name$="[amount]"]')?.value) || 0;
        
        if (title && amount > 0) {
            discounts.push({
                title: title,
                amount: amount,
                type: 'fixed'
            });
        }
    });
    
    // Prepare data
    const orderData = {
        customer_id: formData.get('customer_id'),
        order_status: formData.get('order_status'),
        order_date: formData.get('order_date') ? formData.get('order_date').replace('T', ' ') + ':00' : getCurrentLocalDateTime().replace('T', ' ') + ':00',
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
        payment_method: formData.get('payment_method'),
        note: formData.get('note'),
        items: items,
        discounts: discounts // NEW: Include discounts array
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
            // Extract variant_id and product_id from the product.id (format: "variant_123")
            const variantId = product.id && product.id.toString().startsWith('variant_') ? product.id.toString().replace('variant_', '') : (product.variant_id || '');
            const productId = product.product_id || '';
            const sku = (product.sku || '').replace(/'/g, "\\'");
            return `
            <div onclick="selectProduct(${index}, '${product.id}', '${safeName}', ${price}, '${sku}', '${variantId}', '${productId}')" 
                 style="padding: 8px; cursor: pointer; border-bottom: 1px solid #f3f4f6;"
                 onmouseover="this.style.backgroundColor='#f9fafb'" 
                 onmouseout="this.style.backgroundColor='white'">
                <div style="font-weight: 500; font-size: 13px;">${displayName}</div>
                <div style="font-size: 11px; color: #6b7280;">
                    ${product.sku ? 'SKU: ' + product.sku + ' | ' : ''}Price: PKR ${price}${product.sku ? '' : ' | Stock: ' + inventory}
                </div>
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

function selectProduct(index, productId, productName, price, sku = '', variantId = '', productIdFromSearch = '') {
    // Fill in the product details
    const nameInput = document.querySelector(`input[name="items[${index}][name]"]`);
    const priceInput = document.querySelector(`input[name="items[${index}][unit_price]"]`);
    const qtyInput = document.querySelector(`input[name="items[${index}][quantity]"]`);
    const hiddenInput = document.querySelector(`input[name="items[${index}][id]"]`);
    const skuInput = document.querySelector(`input[name="items[${index}][sku]"]`);
    const variantIdInput = document.querySelector(`input[name="items[${index}][variant_id]"]`);
    const productIdInput = document.querySelector(`input[name="items[${index}][product_id]"]`);
    const skuDisplay = document.getElementById(`skuDisplay_${index}`);
    
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
    if (skuInput) skuInput.value = sku || '';
    if (variantIdInput) variantIdInput.value = variantId || '';
    if (productIdInput) productIdInput.value = productIdFromSearch || '';
    
    // ⭐ Display SKU if available
    if (skuDisplay && sku) {
        skuDisplay.textContent = `SKU: ${sku}`;
        skuDisplay.style.display = 'inline-block';
    } else if (skuDisplay) {
        skuDisplay.style.display = 'none';
    }
    
    // Update the line total
    updateLineTotal(index);
    
    // Hide dropdown
    hideProductDropdown(index);
    
    // ⭐ Focus on quantity field after product selection
    setTimeout(() => {
        if (qtyInput) {
            qtyInput.focus();
            qtyInput.select(); // Select the value so user can type new qty directly
        }
    }, 60);
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
                // Fix: API returns data.success and data.data structure
                if (data.success && data.data && data.data.length > 0) {
                    dropdown.innerHTML = data.data.map(coupon => `
                        <div onclick="selectNewOrderCoupon('${coupon.code}', ${coupon.value}, '${coupon.value_type}', ${coupon.minimum_amount || 0})" 
                             style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #e5e7eb;"
                             onmouseover="this.style.backgroundColor='#f3f4f6'" 
                             onmouseout="this.style.backgroundColor='white'">
                            <div style="font-weight: 500;">${coupon.display || coupon.code}</div>
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

// ==================== ENHANCED PRODUCT SEARCH WITH KEYBOARD NAVIGATION ====================
// Global variables for keyboard navigation
let currentDropdownIndex = -1;
let currentProducts = [];

// Enhance existing selectProduct to add auto-add functionality
const originalSelectProduct = selectProduct;
selectProduct = function(index, productId, productName, price) {
    // Call original function
    originalSelectProduct(index, productId, productName, price);
    
    // Auto-add new line item
    setTimeout(() => {
        autoAddNextLineItem();
    }, 100);
};

// ⭐ Auto-add new line item silently - does NOT steal focus from qty field
function autoAddNextLineItem() {
    const container = document.getElementById('lineItemsContainer');
    if (!container) return;
    
    const lineItems = container.querySelectorAll('.line-item');
    if (lineItems.length === 0) return;
    
    const lastItem = lineItems[lineItems.length - 1];
    const lastNameInput = lastItem.querySelector('input[name*="[name]"]');
    
    if (lastNameInput && lastNameInput.value.trim()) {
        addLineItem();
        // ⭐ No focus change - user stays on quantity field and can Tab when ready
    }
}

function handleProductKeydown(input, index, event) {
    const dropdown = document.getElementById(`productDropdown_${index}`);
    if (!dropdown || dropdown.style.display === 'none') {
        return;
    }
    
    const items = dropdown.querySelectorAll('[data-product-index]');
    
    switch(event.key) {
        case 'ArrowDown':
            event.preventDefault();
            currentDropdownIndex = Math.min(currentDropdownIndex + 1, items.length - 1);
            updateDropdownHighlight(items);
            break;
            
        case 'ArrowUp':
            event.preventDefault();
            currentDropdownIndex = Math.max(currentDropdownIndex - 1, -1);
            updateDropdownHighlight(items);
            break;
            
        case 'Enter':
            event.preventDefault();
            if (currentDropdownIndex >= 0 && items[currentDropdownIndex]) {
                items[currentDropdownIndex].click();
            }
            break;
            
        case 'Escape':
            event.preventDefault();
            hideProductDropdown(index);
            break;
    }
}

function updateDropdownHighlight(items) {
    items.forEach((item, idx) => {
        if (idx === currentDropdownIndex) {
            item.style.backgroundColor = '#3b82f6';
            item.style.color = 'white';
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.style.backgroundColor = 'white';
            item.style.color = 'inherit';
        }
    });
}

// Enhance existing showProductResults to add keyboard navigation support
const originalShowProductResults = showProductResults;
showProductResults = function(products, index) {
    const dropdown = document.getElementById(`productDropdown_${index}`);
    if (!dropdown) return;
    
    currentDropdownIndex = -1;
    currentProducts = products;
    
    if (!Array.isArray(products) || products.length === 0) {
        dropdown.innerHTML = '<div style="padding: 8px; color: #6b7280; font-size: 12px;">No products found</div>';
    } else {
        dropdown.innerHTML = products.map((product, idx) => {
            const displayName = (product.name || product.title || '').toString();
            const safeName = displayName.replace(/'/g, "\\'");
            const price = (product.price ?? product.price_min ?? 0);
            return `
            <div onclick="selectProduct(${index}, '${product.id}', '${safeName}', ${price})" 
                 data-product-index="${idx}"
                 style="padding: 8px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background-color 0.1s;"
                 onmouseover="this.style.backgroundColor='#f9fafb'; currentDropdownIndex=${idx};" 
                 onmouseout="this.style.backgroundColor='white';">
                <div style="font-weight: 500; font-size: 13px;">${displayName}</div>
                <div style="font-size: 11px; color: #6b7280;">
                    ${product.sku ? 'SKU: ' + product.sku + ' | ' : ''}Price: PKR ${price}
                </div>
            </div>
        `;
        }).join('');
    }
    
    dropdown.style.display = 'block';
};

// Update the addLineItem function to include keyboard event handlers
const originalAddLineItem = addLineItem;
addLineItem = function() {
    originalAddLineItem();
    
    // Update the last added line item to include keyboard handlers
    const container = document.getElementById('lineItemsContainer');
    if (container) {
        const lineItems = container.querySelectorAll('.line-item');
        const lastItem = lineItems[lineItems.length - 1];
        if (lastItem) {
            const input = lastItem.querySelector('input[name*="[name]"]');
            if (input) {
                const currentIndex = lineItemIndex - 1;
                input.setAttribute('onkeydown', `handleProductKeydown(this, ${currentIndex}, event)`);
            }
        }
    }
};

// ============================================
// Verified Location Functions
// ============================================
let currentCustomerId = null;

function setVerifiedLocation(customerId) {
    currentCustomerId = customerId;
    document.getElementById('verifiedLocationUrl').value = '';
    document.getElementById('verifiedLocationModal').style.display = 'block';
}

function updateVerifiedLocation(customerId) {
    currentCustomerId = customerId;
    document.getElementById('verifiedLocationUrl').value = '';
    document.getElementById('verifiedLocationModal').style.display = 'block';
}

function closeVerifiedLocationModal() {
    document.getElementById('verifiedLocationModal').style.display = 'none';
    currentCustomerId = null;
}

function saveVerifiedLocation() {
    const url = document.getElementById('verifiedLocationUrl').value.trim();
    
    if (!url) {
        alert('Please enter a Google Maps URL');
        return;
    }
    
    // Show loading state
    const saveBtn = event.target;
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    saveBtn.disabled = true;
    
    fetch(`/customers/${currentCustomerId}/set-verified-location`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ url: url })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Verified location saved successfully!');
            closeVerifiedLocationModal();
            // Refresh customer view if currently viewing
            if (document.getElementById('viewCustomerModal').style.display === 'block') {
                viewCustomer(currentCustomerId);
            }
        } else {
            alert('Error: ' + (data.message || 'Failed to save location'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to save location. Please try again.');
    })
    .finally(() => {
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
    });
}
</script>

<!-- Verified Location Modal -->
<div id="verifiedLocationModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
    <div style="background-color: #fefefe; margin: 10% auto; padding: 0; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 20px; border-radius: 12px 12px 0 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 20px; font-weight: 600;">
                    <i class="fas fa-map-marker-alt"></i> Set Verified Location
                </h3>
                <button onclick="closeVerifiedLocationModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
            </div>
        </div>
        <div style="padding: 24px;">
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #374151;">
                    <i class="fas fa-link"></i> Google Maps URL
                </label>
                <input type="text" id="verifiedLocationUrl" placeholder="https://maps.app.goo.gl/..." style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                <small style="display: block; margin-top: 8px; color: #6b7280;">
                    Paste a Google Maps link (works with any format: short links, place URLs, etc.)
                </small>
            </div>
            <div style="background-color: #eff6ff; padding: 16px; border-radius: 8px; border-left: 4px solid #3b82f6; margin-bottom: 20px;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #1e40af;">
                    <i class="fas fa-info-circle"></i> How to get the link:
                </p>
                <ol style="margin: 0; padding-left: 20px; color: #1e40af;">
                    <li>Open Google Maps</li>
                    <li>Find the location</li>
                    <li>Tap "Share" → Copy link</li>
                    <li>Paste here</li>
                </ol>
            </div>
            <div style="display: flex; gap: 12px;">
                <button onclick="closeVerifiedLocationModal()" style="flex: 1; padding: 12px; background-color: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button onclick="saveVerifiedLocation()" style="flex: 2; padding: 12px; background-color: #3b82f6; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer;">
                    <i class="fas fa-save"></i> Save Location
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Merge Into Customer Modal -->
<div id="mergeIntoModal" style="display: none; position: fixed; z-index: 1003; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div style="background-color: #fefefe; margin: 10% auto; padding: 0; border-radius: 12px; width: 95%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 20px; border-radius: 12px 12px 0 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 20px; font-weight: 600;">
                    <i class="fas fa-compress-arrows-alt"></i> Merge Into Customer
                </h3>
                <button onclick="closeMergeIntoModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
            </div>
            <p id="mergeIntoPrimaryName" style="margin: 8px 0 0 0; font-size: 13px; opacity: 0.9;">Primary Customer: Loading...</p>
        </div>
        <div style="padding: 20px;">
            <p style="margin: 0 0 16px 0; color: #6b7280; font-size: 14px;">
                Search for a customer to merge INTO the primary customer. All orders from the selected customer will be transferred.
            </p>
            
            <!-- Search Box -->
            <div style="position: relative; margin-bottom: 16px;">
                <input type="text" id="mergeSearchInput" placeholder="Search by name, phone, or email..." 
                       oninput="searchCustomersForMerge(this.value)"
                       style="width: 100%; padding: 12px 12px 12px 40px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;">
                <i class="fas fa-search" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
            </div>
            
            <!-- Search Results -->
            <div id="mergeSearchResults" style="max-height: 300px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; display: none;">
                <!-- Results will be populated here -->
            </div>
            
            <!-- Selected Customer -->
            <div id="selectedMergeCustomer" style="display: none; margin-top: 16px; padding: 16px; background: #f0fdf4; border: 1px solid #10b981; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong id="selectedMergeCustomerName" style="color: #065f46;"></strong>
                        <p id="selectedMergeCustomerDetails" style="margin: 4px 0 0 0; font-size: 12px; color: #059669;"></p>
                    </div>
                    <button onclick="clearSelectedMergeCustomer()" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 16px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div style="margin-top: 20px; display: flex; gap: 12px;">
                <button onclick="closeMergeIntoModal()" style="flex: 1; padding: 12px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer;">
                    Cancel
                </button>
                <button id="confirmMergeBtn" onclick="confirmMergeInto()" disabled 
                        style="flex: 2; padding: 12px; background: #9ca3af; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: not-allowed;">
                    <i class="fas fa-compress-arrows-alt"></i> Merge Selected Into Primary
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Manage Duplicates Modal -->
<div id="duplicatesModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
    <div style="background-color: #fefefe; margin: 5% auto; padding: 0; border-radius: 12px; width: 95%; max-width: 900px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;">
        <div style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: white; padding: 20px; border-radius: 12px 12px 0 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 20px; font-weight: 600;">
                    <i class="fas fa-users"></i> Manage Duplicate Customers
                </h3>
                <button onclick="closeDuplicatesModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
            </div>
            <p style="margin: 8px 0 0 0; font-size: 13px; opacity: 0.9;">Find and merge customers with same name but different phone numbers</p>
        </div>
        <div id="duplicatesContent" style="padding: 20px; overflow-y: auto; flex: 1;">
            <div style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #8b5cf6;"></i>
                <p style="margin-top: 12px; color: #6b7280;">Loading potential duplicates...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Duplicates Management - Store grouped data globally for search filtering
window.duplicatesGroupedData = {};

window.openDuplicatesModal = function() {
    const modal = document.getElementById('duplicatesModal');
    const content = document.getElementById('duplicatesContent');
    modal.style.display = 'block';
    
    // Fetch duplicates
    fetch('/customers/find-duplicates')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.count === 0) {
                    content.innerHTML = `
                        <div style="text-align: center; padding: 60px 20px;">
                            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981;"></i>
                            <h4 style="margin: 16px 0 8px 0; font-size: 18px; color: #374151;">No Duplicates Found</h4>
                            <p style="color: #6b7280;">All customers have unique name-phone combinations.</p>
                        </div>
                    `;
                } else {
                    // Group by customer name
                    const grouped = {};
                    data.duplicates.forEach(d => {
                        const key = `${d.first_name} ${d.last_name}`;
                        if (!grouped[key]) {
                            grouped[key] = [];
                        }
                        // Add both customers with last order date
                        if (!grouped[key].find(c => c.id === d.customer1_id)) {
                            grouped[key].push({
                                id: d.customer1_id,
                                phone: d.phone1,
                                city: d.city1,
                                orders: d.orders1,
                                lastOrder: d.last_order1
                            });
                        }
                        if (!grouped[key].find(c => c.id === d.customer2_id)) {
                            grouped[key].push({
                                id: d.customer2_id,
                                phone: d.phone2,
                                city: d.city2,
                                orders: d.orders2,
                                lastOrder: d.last_order2
                            });
                        }
                    });
                    
                    // Store for search filtering
                    window.duplicatesGroupedData = grouped;
                    
                    // Render with search box
                    let html = `
                        <div style="margin-bottom: 16px; padding: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;">
                            <strong style="color: #16a34a;">📋 Found ${data.count} potential duplicate groups</strong>
                            <p style="margin: 4px 0 0 0; font-size: 13px; color: #15803d;">Select a primary customer to keep, then click "Merge" to combine records.</p>
                        </div>
                        <div style="margin-bottom: 16px; position: sticky; top: 0; background: white; padding: 8px 0; z-index: 10;">
                            <div style="position: relative;">
                                <input type="text" id="duplicateSearchInput" placeholder="Search by name, phone, or city..." 
                                       oninput="filterDuplicates(this.value)"
                                       style="width: 100%; padding: 10px 12px 10px 36px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;">
                                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
                            </div>
                        </div>
                        <div id="duplicatesListContainer" style="display: flex; flex-direction: column; gap: 16px;">
                    `;
                    
                    html += renderDuplicateGroups(grouped);
                    html += '</div>';
                    content.innerHTML = html;
                }
            } else {
                content.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc2626;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 32px;"></i>
                        <p style="margin-top: 12px;">Error: ${data.message}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            content.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #dc2626;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 32px;"></i>
                    <p style="margin-top: 12px;">Failed to load duplicates: ${error.message}</p>
                </div>
            `;
        });
};

// Render duplicate groups as HTML
window.renderDuplicateGroups = function(grouped, searchTerm = '') {
    let html = '';
    let idx = 0;
    
    Object.entries(grouped).forEach(([name, customers]) => {
        // Filter by search term
        if (searchTerm) {
            const term = searchTerm.toLowerCase();
            const nameMatch = name.toLowerCase().includes(term);
            const phoneMatch = customers.some(c => (c.phone || '').includes(term));
            const cityMatch = customers.some(c => (c.city || '').toLowerCase().includes(term));
            
            if (!nameMatch && !phoneMatch && !cityMatch) {
                return; // Skip this group
            }
        }
        
        // Sort customers by last order date (most recent first) to help selection
        customers.sort((a, b) => {
            if (!a.lastOrder && !b.lastOrder) return 0;
            if (!a.lastOrder) return 1;
            if (!b.lastOrder) return -1;
            return new Date(b.lastOrder) - new Date(a.lastOrder);
        });
        
        html += `
            <div class="duplicate-group" data-name="${name.toLowerCase()}" style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                <div style="background: #f9fafb; padding: 12px 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="font-size: 16px; color: #374151;">${name}</strong>
                        <span style="margin-left: 8px; background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 12px; font-size: 12px;">${customers.length} records</span>
                    </div>
                </div>
                <div style="padding: 16px;">
                    <table style="width: 100%; font-size: 13px;">
                        <thead>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <th style="text-align: left; padding: 8px; color: #6b7280; font-weight: 500;">Primary</th>
                                <th style="text-align: left; padding: 8px; color: #6b7280; font-weight: 500;">ID</th>
                                <th style="text-align: left; padding: 8px; color: #6b7280; font-weight: 500;">Phone</th>
                                <th style="text-align: left; padding: 8px; color: #6b7280; font-weight: 500;">City</th>
                                <th style="text-align: right; padding: 8px; color: #6b7280; font-weight: 500;">Orders</th>
                                <th style="text-align: right; padding: 8px; color: #6b7280; font-weight: 500;">Last Order</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        customers.forEach((c, i) => {
            const radioName = `primary_${idx}`;
            const lastOrderFormatted = c.lastOrder ? new Date(c.lastOrder).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
            const isRecent = c.lastOrder && (new Date() - new Date(c.lastOrder)) < (90 * 24 * 60 * 60 * 1000); // Within 90 days
            html += `
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 8px;">
                        <input type="radio" name="${radioName}" value="${c.id}" data-group="${idx}" ${i === 0 ? 'checked' : ''}>
                    </td>
                    <td style="padding: 8px; font-family: monospace; color: #6b7280;">#${c.id}</td>
                    <td style="padding: 8px; font-weight: 500;">${c.phone || '-'}</td>
                    <td style="padding: 8px;">${c.city || '-'}</td>
                    <td style="padding: 8px; text-align: right; font-weight: 600; color: #3b82f6;">${c.orders || 0}</td>
                    <td style="padding: 8px; text-align: right; font-weight: 500; color: ${isRecent ? '#059669' : '#6b7280'};">${lastOrderFormatted}</td>
                </tr>
            `;
        });
        
        const customerIds = customers.map(c => c.id).join(',');
        html += `
                        </tbody>
                    </table>
                    <div style="margin-top: 12px; text-align: right;">
                        <button onclick="mergeCustomerGroup(${idx}, '${customerIds}')" 
                                id="mergeBtn_${idx}"
                                style="padding: 8px 16px; background: #8b5cf6; color: white; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; font-weight: 500;">
                            <i class="fas fa-compress-arrows-alt"></i> Merge into Primary
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        idx++;
    });
    
    return html;
};

window.closeDuplicatesModal = function() {
    document.getElementById('duplicatesModal').style.display = 'none';
};

// Filter duplicates by search term
window.filterDuplicates = function(searchTerm) {
    const container = document.getElementById('duplicatesListContainer');
    if (!container) return;
    
    // Re-render with filtered data
    container.innerHTML = renderDuplicateGroups(window.duplicatesGroupedData, searchTerm);
    
    // Show message if no results
    if (container.innerHTML.trim() === '') {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #6b7280;">
                <i class="fas fa-search" style="font-size: 32px; opacity: 0.5;"></i>
                <p style="margin-top: 12px;">No duplicates found matching "${searchTerm}"</p>
            </div>
        `;
    }
};

window.updateMergeSelection = function(groupIdx) {
    // Just updates UI if needed
};

window.mergeCustomerGroup = function(groupIdx, customerIdsStr) {
    const customerIds = customerIdsStr.split(',').map(id => parseInt(id));
    const selectedRadio = document.querySelector(`input[name="primary_${groupIdx}"]:checked`);
    
    if (!selectedRadio) {
        alert('Please select a primary customer');
        return;
    }
    
    const primaryId = parseInt(selectedRadio.value);
    const duplicateIds = customerIds.filter(id => id !== primaryId);
    
    if (duplicateIds.length === 0) {
        alert('No duplicates to merge');
        return;
    }
    
    if (!confirm(`Merge ${duplicateIds.length} duplicate customer(s) into #${primaryId}?\n\nThis will:\n• Move all orders to the primary customer\n• Hide duplicate records\n• Recalculate statistics`)) {
        return;
    }
    
    const btn = document.getElementById(`mergeBtn_${groupIdx}`);
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Merging...';
    
    fetch('/customers/merge', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            primary_customer_id: primaryId,
            duplicate_customer_ids: duplicateIds
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success and remove this group from the list
            btn.closest('[style*="border: 1px solid"]').innerHTML = `
                <div style="padding: 20px; text-align: center; background: #f0fdf4;">
                    <i class="fas fa-check-circle" style="font-size: 24px; color: #10b981;"></i>
                    <p style="margin: 8px 0 0 0; color: #16a34a; font-weight: 500;">${data.message}</p>
                </div>
            `;
            
            // Refresh the page after a short delay to show updated customer list
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-compress-arrows-alt"></i> Merge into Primary';
        }
    })
    .catch(error => {
        alert('Failed to merge: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-compress-arrows-alt"></i> Merge into Primary';
    });
};

// Close modal on outside click
window.addEventListener('click', function(event) {
    if (event.target.id === 'duplicatesModal') {
        closeDuplicatesModal();
    }
    if (event.target.id === 'mergeIntoModal') {
        closeMergeIntoModal();
    }
});

// ========================================
// Merge Into Customer Functions
// ========================================
window.mergeIntoPrimaryId = null;
window.selectedMergeCustomerId = null;
let mergeSearchTimeout = null;

window.openMergeIntoModal = function(primaryId, primaryName) {
    window.mergeIntoPrimaryId = primaryId;
    window.selectedMergeCustomerId = null;
    
    document.getElementById('mergeIntoPrimaryName').textContent = `Primary Customer: ${primaryName} (#${primaryId})`;
    document.getElementById('mergeSearchInput').value = '';
    document.getElementById('mergeSearchResults').style.display = 'none';
    document.getElementById('mergeSearchResults').innerHTML = '';
    document.getElementById('selectedMergeCustomer').style.display = 'none';
    
    const btn = document.getElementById('confirmMergeBtn');
    btn.disabled = true;
    btn.style.background = '#9ca3af';
    btn.style.cursor = 'not-allowed';
    
    document.getElementById('mergeIntoModal').style.display = 'block';
    document.getElementById('mergeSearchInput').focus();
};

window.closeMergeIntoModal = function() {
    document.getElementById('mergeIntoModal').style.display = 'none';
    window.mergeIntoPrimaryId = null;
    window.selectedMergeCustomerId = null;
};

window.searchCustomersForMerge = function(searchTerm) {
    clearTimeout(mergeSearchTimeout);
    
    const resultsDiv = document.getElementById('mergeSearchResults');
    
    if (searchTerm.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    mergeSearchTimeout = setTimeout(() => {
        resultsDiv.style.display = 'block';
        resultsDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
        
        fetch(`/customers/search-for-merge?q=${encodeURIComponent(searchTerm)}&current_customer_id=${window.mergeIntoPrimaryId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.customers.length > 0) {
                    let html = '';
                    data.customers.forEach(c => {
                        html += `
                            <div onclick="selectCustomerForMerge(${c.id}, '${c.name.replace(/'/g, "\\'")}', '${(c.phone || '').replace(/'/g, "\\'")}', '${(c.city || '').replace(/'/g, "\\'")}', ${c.orders})" 
                                 style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; cursor: pointer; transition: background 0.2s;"
                                 onmouseover="this.style.background='#f3f4f6'" 
                                 onmouseout="this.style.background='white'">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong style="color: #374151;">${c.name}</strong>
                                        <span style="margin-left: 8px; font-size: 12px; color: #6b7280;">#${c.id}</span>
                                    </div>
                                    <span style="background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 500;">
                                        ${c.orders} orders
                                    </span>
                                </div>
                                <div style="margin-top: 4px; font-size: 12px; color: #6b7280;">
                                    📞 ${c.phone || 'No phone'} ${c.city ? ' • 📍 ' + c.city : ''}
                                </div>
                            </div>
                        `;
                    });
                    resultsDiv.innerHTML = html;
                } else {
                    resultsDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">No customers found</div>';
                }
            })
            .catch(error => {
                resultsDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: #dc2626;">Error searching customers</div>';
            });
    }, 300);
};

window.selectCustomerForMerge = function(id, name, phone, city, orders) {
    window.selectedMergeCustomerId = id;
    
    document.getElementById('mergeSearchResults').style.display = 'none';
    document.getElementById('mergeSearchInput').value = '';
    
    document.getElementById('selectedMergeCustomerName').textContent = `${name} (#${id})`;
    document.getElementById('selectedMergeCustomerDetails').textContent = `📞 ${phone || 'No phone'} • 📍 ${city || 'Unknown'} • 📦 ${orders} orders`;
    document.getElementById('selectedMergeCustomer').style.display = 'block';
    
    const btn = document.getElementById('confirmMergeBtn');
    btn.disabled = false;
    btn.style.background = '#f59e0b';
    btn.style.cursor = 'pointer';
};

window.clearSelectedMergeCustomer = function() {
    window.selectedMergeCustomerId = null;
    document.getElementById('selectedMergeCustomer').style.display = 'none';
    
    const btn = document.getElementById('confirmMergeBtn');
    btn.disabled = true;
    btn.style.background = '#9ca3af';
    btn.style.cursor = 'not-allowed';
};

window.confirmMergeInto = function() {
    if (!window.mergeIntoPrimaryId || !window.selectedMergeCustomerId) {
        alert('Please select a customer to merge');
        return;
    }
    
    if (!confirm(`Are you sure you want to merge customer #${window.selectedMergeCustomerId} into #${window.mergeIntoPrimaryId}?\n\nThis will:\n• Move all orders to the primary customer\n• Hide the duplicate customer record\n• This action can be undone by admin`)) {
        return;
    }
    
    const btn = document.getElementById('confirmMergeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Merging...';
    
    fetch('/customers/merge', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            primary_customer_id: window.mergeIntoPrimaryId,
            duplicate_customer_ids: [window.selectedMergeCustomerId]
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeMergeIntoModal();
            // Refresh to show updated data
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-compress-arrows-alt"></i> Merge Selected Into Primary';
            btn.style.background = '#f59e0b';
            btn.style.cursor = 'pointer';
        }
    })
    .catch(error => {
        alert('Failed to merge: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-compress-arrows-alt"></i> Merge Selected Into Primary';
        btn.style.background = '#f59e0b';
        btn.style.cursor = 'pointer';
    });
};
</script>

@endsection
