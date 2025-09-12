@extends('layouts.app')

@section('title', 'Coupons')

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-foreground">Coupons</h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                Manage your Shopify coupons and discount codes
            </div>
        </div>
        
        <div class="flex items-center gap-2.5">
            <a href="{{ route('coupons.create') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-plus"></i>
                Create Coupon
            </a>
            <button onclick="importCoupons()" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-cloud-download"></i>
                Import Limited
            </button>
            <button onclick="importAllCoupons()" class="kt-btn kt-btn-primary">
                <i class="ki-filled ki-cloud-download"></i>
                Import All Coupons
            </button>
        </div>
    </div>
</div>

<div class="container-fixed">
    <div class="card card-grid min-w-full">
        <div class="card-header flex-wrap gap-2">
            <h3 class="card-title font-medium text-sm">All Coupons</h3>
            
            <div class="flex flex-wrap gap-2 lg:gap-5">
                <!-- Search -->
                <form method="GET" class="flex items-center gap-2">
                    <input type="text" name="search" value="{{ request('search') }}" 
                           placeholder="Search coupons..." 
                           class="input input-sm w-48">
                    
                    <!-- Status Filter -->
                    <select name="status" class="select select-sm">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="scheduled" {{ request('status') == 'scheduled' ? 'selected' : '' }}>Scheduled</option>
                        <option value="expired" {{ request('status') == 'expired' ? 'selected' : '' }}>Expired</option>
                        <option value="disabled" {{ request('status') == 'disabled' ? 'selected' : '' }}>Disabled</option>
                    </select>
                    
                    <!-- Active Filter -->
                    <select name="is_active" class="select select-sm">
                        <option value="">All</option>
                        <option value="1" {{ request('is_active') == '1' ? 'selected' : '' }}>Active</option>
                        <option value="0" {{ request('is_active') == '0' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    
                    <button type="submit" class="kt-btn kt-btn-sm kt-btn-light">Filter</button>
                    @if(request()->hasAny(['search', 'status', 'is_active']))
                        <a href="{{ route('coupons.index') }}" class="kt-btn kt-btn-sm kt-btn-light">Clear</a>
                    @endif
                </form>
            </div>
        </div>

        <div class="card-body">
            <div class="scrollable-x-auto">
                <table class="table table-auto table-border" data-datatable="true" id="couponsTable">
                    <thead id="tableHead">
                        <!-- Dynamic headers will be inserted here -->
                    </thead>
                    <tbody id="tableBody">
                        <!-- Dynamic rows will be inserted here -->
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="flex items-center justify-between mt-5">
                <div class="text-sm text-gray-700">
                    Showing {{ $coupons->firstItem() ?? 0 }} to {{ $coupons->lastItem() ?? 0 }} 
                    of {{ $coupons->total() }} coupons
                </div>
                {{ $coupons->appends(request()->query())->links() }}
            </div>
        </div>
    </div>
</div>

<!-- Import Coupons Modal -->
<div id="importCouponsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 500px;">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-lg font-semibold">Import Coupons from Shopify</h3>
            <button onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600">
                <i class="ki-filled ki-cross text-xl"></i>
            </button>
        </div>
        
        <div class="mb-4">
            <label class="form-label">Import Limit</label>
            <input type="number" id="importLimit" value="50" min="1" max="250" class="input">
            <div class="form-hint">Maximum 250 coupons per import</div>
        </div>
        
        <div class="flex gap-3">
            <button onclick="executeImport()" class="kt-btn kt-btn-primary flex-1">
                <span id="importBtnText">Import Coupons</span>
                <span id="importSpinner" style="display: none;">
                    <i class="ki-filled ki-arrows-circle animate-spin"></i>
                </span>
            </button>
            <button onclick="closeImportModal()" class="kt-btn kt-btn-light">Cancel</button>
        </div>
        
        <div id="importResults" class="mt-4" style="display: none;">
            <div class="alert alert-success">
                <div id="importMessage"></div>
            </div>
        </div>
    </div>
</div>

<!-- Coupon Details Modal -->
<div id="couponDetailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto;">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-lg font-semibold">Coupon Details</h3>
            <button onclick="closeCouponModal()" class="text-gray-400 hover:text-gray-600">
                <i class="ki-filled ki-cross text-xl"></i>
            </button>
        </div>
        
        <div id="couponDetailsContent">
            <!-- Dynamic content will be inserted here -->
        </div>
        
        <div class="flex gap-3 mt-6">
            <button onclick="closeCouponModal()" class="kt-btn kt-btn-light flex-1">Close</button>
        </div>
    </div>
</div>

<script>
// Show loading state
function showLoadingState() {
    document.getElementById('tableBody').innerHTML = `
        <tr>
            <td colspan="100%" class="text-center py-8">
                <div class="flex items-center justify-center">
                    <i class="ki-filled ki-arrows-circle animate-spin text-2xl text-primary mr-3"></i>
                    <span>Loading coupons...</span>
                </div>
            </td>
        </tr>
    `;
}

// Hide loading state
function hideLoadingState() {
    renderTable();
}

// Import coupons (limited)
function importCoupons() {
    document.getElementById('importCouponsModal').style.display = 'block';
}

// Import all coupons
function importAllCoupons() {
    if (!confirm('This will import ALL coupons from your Shopify store. This may take several minutes. Continue?')) {
        return;
    }
    
    showLoadingState();
    
    fetch('/coupons/import-all', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        hideLoadingState();
        
        if (data.success) {
            alert(`Import completed!\nImported: ${data.data.imported}\nUpdated: ${data.data.updated}\nErrors: ${data.data.errors}`);
            location.reload();
        } else {
            alert('Import failed: ' + data.message);
        }
    })
    .catch(error => {
        hideLoadingState();
        console.error('Import error:', error);
        alert('Import failed. Please check the console for details.');
    });
}

// Execute limited import
function executeImport() {
    const limit = document.getElementById('importLimit').value;
    const btn = document.getElementById('importBtnText');
    const spinner = document.getElementById('importSpinner');
    
    btn.style.display = 'none';
    spinner.style.display = 'inline-block';
    
    fetch('/coupons/import', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({ limit: parseInt(limit) })
    })
    .then(response => response.json())
    .then(data => {
        btn.style.display = 'inline-block';
        spinner.style.display = 'none';
        
        const resultsDiv = document.getElementById('importResults');
        const messageDiv = document.getElementById('importMessage');
        
        if (data.success) {
            messageDiv.innerHTML = `
                <strong>Import Successful!</strong><br>
                Imported: ${data.data.imported}<br>
                Updated: ${data.data.updated}<br>
                Errors: ${data.data.errors}
            `;
            resultsDiv.style.display = 'block';
            
            setTimeout(() => {
                closeImportModal();
                location.reload();
            }, 3000);
        } else {
            resultsDiv.className = 'mt-4 alert alert-danger';
            messageDiv.innerHTML = `<strong>Import Failed:</strong> ${data.message}`;
            resultsDiv.style.display = 'block';
        }
    })
    .catch(error => {
        btn.style.display = 'inline-block';
        spinner.style.display = 'none';
        
        console.error('Import error:', error);
        alert('Import failed. Please check the console for details.');
    });
}

// Close import modal
function closeImportModal() {
    document.getElementById('importCouponsModal').style.display = 'none';
    document.getElementById('importResults').style.display = 'none';
}

// Show coupon details
function showCouponDetails(couponId) {
    fetch(`/coupons/${couponId}`)
    .then(response => {
        if (!response.ok) {
            throw new Error('Failed to fetch coupon details');
        }
        return response.text();
    })
    .then(html => {
        // Extract the content from the response (assuming it returns a view)
        // For now, we'll make an AJAX call to get JSON data
        return fetch(`/coupons/${couponId}`, {
            headers: {
                'Accept': 'application/json'
            }
        });
    })
    .then(response => response.json())
    .then(coupon => {
        const content = `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Title</label>
                    <div class="form-control">${coupon.title}</div>
                </div>
                <div>
                    <label class="form-label">Code</label>
                    <div class="form-control">${coupon.code || 'N/A'}</div>
                </div>
                <div>
                    <label class="form-label">Discount Type</label>
                    <div class="form-control">${coupon.discount_type}</div>
                </div>
                <div>
                    <label class="form-label">Value</label>
                    <div class="form-control">${coupon.value}${coupon.value_type === 'percentage' ? '%' : ' PKR'}</div>
                </div>
                <div>
                    <label class="form-label">Minimum Amount</label>
                    <div class="form-control">${coupon.minimum_amount ? 'PKR ' + coupon.minimum_amount : 'No minimum'}</div>
                </div>
                <div>
                    <label class="form-label">Usage Limit</label>
                    <div class="form-control">${coupon.usage_limit || 'Unlimited'}</div>
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <div class="form-control">
                        <span class="badge ${getStatusBadgeClass(coupon.status)}">${coupon.status}</span>
                    </div>
                </div>
                <div>
                    <label class="form-label">Valid Period</label>
                    <div class="form-control">
                        ${coupon.starts_at ? new Date(coupon.starts_at).toLocaleDateString() : 'No start date'} - 
                        ${coupon.ends_at ? new Date(coupon.ends_at).toLocaleDateString() : 'No end date'}
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('couponDetailsContent').innerHTML = content;
        document.getElementById('couponDetailsModal').style.display = 'block';
    })
    .catch(error => {
        console.error('Error fetching coupon details:', error);
        alert('Failed to load coupon details');
    });
}

// Close coupon modal
function closeCouponModal() {
    document.getElementById('couponDetailsModal').style.display = 'none';
}

// Get status badge class
function getStatusBadgeClass(status) {
    switch(status) {
        case 'active': return 'badge-success';
        case 'scheduled': return 'badge-info';
        case 'expired': return 'badge-warning';
        case 'disabled': return 'badge-danger';
        default: return 'badge-secondary';
    }
}

// Coupon data from server
const couponsData = @json($coupons->items());

// Available columns configuration
const availableColumns = {
    'title': { label: 'Title', width: 'min-w-[200px]', order: 1 },
    'code': { label: 'Code', width: 'w-[120px]', order: 2 },
    'discount_type': { label: 'Type', width: 'w-[100px]', order: 3 },
    'value': { label: 'Value', width: 'w-[100px]', order: 4 },
    'minimum_amount': { label: 'Min Amount', width: 'w-[100px]', order: 5 },
    'usage_limit': { label: 'Usage Limit', width: 'w-[100px]', order: 6 },
    'usage_count': { label: 'Used', width: 'w-[80px]', order: 7 },
    'status': { label: 'Status', width: 'w-[100px]', order: 8 },
    'starts_at': { label: 'Starts', width: 'w-[100px]', order: 9 },
    'ends_at': { label: 'Ends', width: 'w-[100px]', order: 10 },
    'last_synced_at': { label: 'Last Sync', width: 'w-[100px]', order: 11 },
    'actions': { label: 'Actions', width: 'w-[120px]', order: 12, fixed: true }
};

// Default visible columns
const defaultColumns = ['title', 'code', 'discount_type', 'value', 'status', 'starts_at', 'ends_at', 'actions'];

// Load column settings from localStorage
let visibleColumns = JSON.parse(localStorage.getItem('coupons_visible_columns') || JSON.stringify(defaultColumns));

// Initialize table on page load
document.addEventListener('DOMContentLoaded', function() {
    renderTable();
});

function renderTable() {
    renderTableHeaders();
    renderTableBody();
}

function renderTableHeaders() {
    const thead = document.getElementById('tableHead');
    thead.innerHTML = '';
    
    const headerRow = document.createElement('tr');
    
    visibleColumns.forEach(columnKey => {
        if (availableColumns[columnKey]) {
            const th = document.createElement('th');
            th.className = `table-th ${availableColumns[columnKey].width}`;
            th.textContent = availableColumns[columnKey].label;
            headerRow.appendChild(th);
        }
    });
    
    thead.appendChild(headerRow);
}

function renderTableBody() {
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '';
    
    if (!couponsData || couponsData.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = visibleColumns.length;
        cell.className = 'text-center py-8 text-gray-500';
        cell.innerHTML = 'No coupons found';
        row.appendChild(cell);
        tbody.appendChild(row);
        return;
    }
    
    couponsData.forEach(coupon => {
        const row = document.createElement('tr');
        
        visibleColumns.forEach(columnKey => {
            if (availableColumns[columnKey]) {
                const cell = document.createElement('td');
                cell.className = 'table-td';
                cell.innerHTML = getCellContent(columnKey, coupon);
                row.appendChild(cell);
            }
        });
        
        tbody.appendChild(row);
    });
}

function getCellContent(columnKey, coupon) {
    switch(columnKey) {
        case 'title':
            return `<div class="flex flex-col">
                <span class="font-medium text-gray-900">${coupon.title}</span>
                ${coupon.shopify_discount_id ? `<span class="text-xs text-gray-500">Shopify ID: ${coupon.shopify_discount_id}</span>` : ''}
            </div>`;
            
        case 'code':
            return coupon.code ? 
                `<span class="font-mono text-sm bg-gray-100 px-2 py-1 rounded">${coupon.code}</span>` : 
                '<span class="text-gray-400">No code</span>';
            
        case 'discount_type':
            const typeIcons = {
                'percentage': '📊',
                'fixed_amount': '💰',
                'shipping': '🚚'
            };
            return `<span class="text-sm">${typeIcons[coupon.discount_type] || ''} ${coupon.discount_type.replace('_', ' ')}</span>`;
            
        case 'value':
            const suffix = coupon.value_type === 'percentage' ? '%' : ' PKR';
            return `<span class="font-medium">${coupon.value}${suffix}</span>`;
            
        case 'minimum_amount':
            return coupon.minimum_amount ? 
                `<span class="text-sm">PKR ${coupon.minimum_amount}</span>` : 
                '<span class="text-gray-400">No min</span>';
            
        case 'usage_limit':
            return coupon.usage_limit ? 
                `<span class="text-sm">${coupon.usage_limit}</span>` : 
                '<span class="text-gray-400">Unlimited</span>';
                
        case 'usage_count':
            return `<span class="text-sm">${coupon.usage_count || 0}</span>`;
            
        case 'status':
            const statusClasses = {
                'active': 'bg-green-100 text-green-800',
                'scheduled': 'bg-blue-100 text-blue-800',
                'expired': 'bg-red-100 text-red-800',
                'disabled': 'bg-gray-100 text-gray-800'
            };
            const statusClass = statusClasses[coupon.status] || 'bg-gray-100 text-gray-800';
            return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${statusClass}">
                ${coupon.status.charAt(0).toUpperCase() + coupon.status.slice(1)}
            </span>`;
            
        case 'starts_at':
            return coupon.starts_at ? 
                `<span class="text-sm">${new Date(coupon.starts_at).toLocaleDateString()}</span>` : 
                '<span class="text-gray-400">No start</span>';
                
        case 'ends_at':
            return coupon.ends_at ? 
                `<span class="text-sm">${new Date(coupon.ends_at).toLocaleDateString()}</span>` : 
                '<span class="text-gray-400">No end</span>';
                
        case 'last_synced_at':
            return coupon.last_synced_at ? 
                `<span class="text-xs text-gray-500">${new Date(coupon.last_synced_at).toLocaleDateString()}</span>` : 
                '<span class="text-gray-400">Never</span>';
            
        case 'actions':
            return `<div class="flex items-center gap-2">
                <button onclick="showCouponDetails(${coupon.id})" class="kt-btn kt-btn-sm kt-btn-light" title="View Details">
                    <i class="ki-filled ki-eye"></i>
                </button>
                <a href="/coupons/${coupon.id}/edit" class="kt-btn kt-btn-sm kt-btn-light" title="Edit">
                    <i class="ki-filled ki-notepad-edit"></i>
                </a>
                ${coupon.is_active ? 
                    `<button onclick="disableCoupon(${coupon.id})" class="kt-btn kt-btn-sm kt-btn-danger" title="Disable">
                        <i class="ki-filled ki-trash"></i>
                    </button>` : 
                    `<button onclick="enableCoupon(${coupon.id})" class="kt-btn kt-btn-sm kt-btn-success" title="Enable">
                        <i class="ki-filled ki-check"></i>
                    </button>`
                }
            </div>`;
            
        default:
            return '';
    }
}

// Disable coupon
function disableCoupon(couponId) {
    if (!confirm('Are you sure you want to disable this coupon?')) {
        return;
    }
    
    fetch(`/coupons/${couponId}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to disable coupon');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to disable coupon');
    });
}

// Enable coupon (placeholder for future implementation)
function enableCoupon(couponId) {
    alert('Enable coupon functionality coming soon');
}
</script>
@endsection
