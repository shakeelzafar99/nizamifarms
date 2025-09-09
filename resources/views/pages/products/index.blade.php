@extends('layouts.app')

@section('title', 'Products')

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-foreground">Products</h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                Manage your Shopify products and inventory
            </div>
        </div>
        
        <div class="flex items-center gap-2.5">
            <button onclick="openColumnSettings()" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-setting-2"></i>
                Columns
            </button>
            <button onclick="importProducts()" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-cloud-download"></i>
                Import Limited
            </button>
            <button onclick="importAllProducts()" class="kt-btn kt-btn-primary">
                <i class="ki-filled ki-cloud-download"></i>
                Import All Products
            </button>
        </div>
    </div>
</div>

<div class="container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <div class="card card-grid min-w-full">
            <div class="card-header flex-wrap gap-2">
                <h3 class="card-title font-medium text-sm">All Products</h3>
                
                <div class="flex flex-wrap gap-2 lg:gap-5">
                    <!-- Search -->
                    <form method="GET" class="flex items-center gap-2">
                        <input type="text" name="search" value="{{ request('search') }}" 
                               placeholder="Search products..." 
                               class="input input-sm w-48">
                        
                        <!-- Status Filter -->
                        <select name="status" class="select select-sm">
                            <option value="">All Status</option>
                            <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                            <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                            <option value="archived" {{ request('status') == 'archived' ? 'selected' : '' }}>Archived</option>
                        </select>
                        
                        <!-- Vendor Filter -->
                        <select name="vendor" class="select select-sm">
                            <option value="">All Vendors</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor }}" {{ request('vendor') == $vendor ? 'selected' : '' }}>
                                    {{ $vendor }}
                                </option>
                            @endforeach
                        </select>
                        
                        <button type="submit" class="kt-btn kt-btn-sm kt-btn-light">Filter</button>
                        @if(request()->hasAny(['search', 'status', 'vendor']))
                            <a href="{{ route('products.index') }}" class="kt-btn kt-btn-sm kt-btn-light">Clear</a>
                        @endif
                    </form>
                </div>
            </div>

            <div class="card-body">
                <div class="scrollable-x-auto">
                    <table class="table table-auto table-border" data-datatable="true" id="productsTable">
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
                        Showing {{ $products->firstItem() ?? 0 }} to {{ $products->lastItem() ?? 0 }} 
                        of {{ $products->total() }} products
                    </div>
                    {{ $products->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Import Products Modal -->
<div id="importProductsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000;">
    <div style="display: flex; min-height: 100%; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: white; border-radius: 8px; width: 100%; max-width: 500px; position: relative;">
            <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
                <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;">Import Products from Shopify</h3>
            </div>
            
            <div id="importProductsContent" style="padding: 24px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">
                        Number of Products to Import
                    </label>
                    <input type="number" id="productLimit" value="50" min="1" max="250" 
                           style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    <p style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                        Maximum 250 products per import. Leave empty to import 50 products.
                    </p>
                </div>

                <div style="background-color: #f3f4f6; padding: 16px; border-radius: 6px; margin-bottom: 20px;">
                    <h4 style="font-size: 14px; font-weight: 600; color: #374151; margin: 0 0 8px 0;">What will be imported:</h4>
                    <ul style="font-size: 12px; color: #6b7280; margin: 0; padding-left: 16px;">
                        <li>Product details (title, description, vendor, type)</li>
                        <li>All product variants with pricing and inventory</li>
                        <li>Product images and SEO information</li>
                        <li>Tags and product options</li>
                        <li>Current inventory levels</li>
                    </ul>
                </div>
            </div>
            
            <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 12px;">
                <button onclick="closeModal('importProductsModal')" 
                        style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; font-size: 14px; cursor: pointer;">
                    Cancel
                </button>
                <button onclick="executeImport()" 
                        style="padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                    Import Products
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Product View Modal -->
<div id="productViewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); max-width: 90vw; max-height: 90vh; overflow-y: auto; width: 800px;">
        <div id="productViewContent">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Column Settings Modal -->
<div id="columnSettingsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); max-width: 90vw; max-height: 90vh; width: 600px;">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #111827;">Customize Columns</h3>
            <p style="margin: 8px 0 0 0; color: #6b7280; font-size: 14px;">Choose which columns to display and drag to reorder them.</p>
        </div>
        
        <div style="padding: 24px; max-height: 60vh; overflow-y: auto;">
            <div id="columnSettingsContent">
                <!-- Column settings will be loaded here -->
            </div>
        </div>
        
        <div style="padding: 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 12px;">
            <button onclick="closeModal('columnSettingsModal')" 
                    style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; font-size: 14px; cursor: pointer;">
                Cancel
            </button>
            <button onclick="saveColumnSettings()" 
                    style="padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                Save Settings
            </button>
        </div>
    </div>
</div>

<script>
function importProducts() {
    document.getElementById('importProductsModal').style.display = 'block';
}

function importAllProducts() {
    if (confirm('This will import ALL products from your Shopify store. This may take several minutes depending on the number of products. Continue?')) {
        executeImportAll();
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function executeImportAll() {
    // Show loading overlay
    const loadingOverlay = document.createElement('div');
    loadingOverlay.id = 'importAllOverlay';
    loadingOverlay.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
        background: rgba(0,0,0,0.8); z-index: 9999; display: flex; 
        align-items: center; justify-content: center; color: white;
    `;
    loadingOverlay.innerHTML = `
        <div style="text-align: center; background: white; padding: 40px; border-radius: 12px; color: #111827;">
            <div style="display: inline-block; width: 48px; height: 48px; border: 4px solid #e5e7eb; border-top: 4px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 16px;"></div>
            <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600;">Importing All Products</h3>
            <p style="margin: 0; color: #6b7280;">This may take several minutes. Please don't close this window...</p>
        </div>
    `;
    document.body.appendChild(loadingOverlay);
    
    // Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                     document.querySelector('input[name="_token"]')?.value || '';
    
    // Make API call
    fetch('/products/import-all', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({
            _token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        // Remove loading overlay
        document.body.removeChild(loadingOverlay);
        
        if (data.success) {
            alert(`Import completed!\n\n${data.message}\n\nTotal Products: ${data.total_products}\nNew: ${data.imported_count}\nUpdated: ${data.updated_count}\nErrors: ${data.error_count}`);
            // Reload the page to show new products
            window.location.reload();
        } else {
            alert('Import failed: ' + data.message);
        }
    })
    .catch(error => {
        // Remove loading overlay
        document.body.removeChild(loadingOverlay);
        console.error('Error:', error);
        alert('An error occurred during import: ' + error.message);
    });
}

function executeImport() {
    const limit = document.getElementById('productLimit').value || 50;
    const content = document.getElementById('importProductsContent');
    
    // Show loading
    content.innerHTML = `
        <div style="text-align: center; padding: 40px;">
            <div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <p style="margin-top: 16px; color: #6b7280;">Importing products from Shopify...</p>
        </div>
    `;
    
    // Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                     document.querySelector('input[name="_token"]')?.value || '';
    
    // Make API call
    fetch('/products/import', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({
            limit: parseInt(limit),
            _token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            content.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div style="width: 48px; height: 48px; background-color: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                        <i class="ki-filled ki-check text-white text-xl"></i>
                    </div>
                    <h4 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 8px 0;">Import Successful!</h4>
                    <p style="color: #6b7280; margin: 0 0 16px 0;">${data.message}</p>
                    <button onclick="location.reload()" 
                            style="padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                        Refresh Page
                    </button>
                </div>
            `;
        } else {
            content.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div style="width: 48px; height: 48px; background-color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                        <i class="ki-filled ki-cross text-white text-xl"></i>
                    </div>
                    <h4 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 8px 0;">Import Failed</h4>
                    <p style="color: #6b7280; margin: 0;">${data.message || 'Unknown error occurred'}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Import error:', error);
        content.innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <div style="width: 48px; height: 48px; background-color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                    <i class="ki-filled ki-cross text-white text-xl"></i>
                </div>
                <h4 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 8px 0;">Network Error</h4>
                <p style="color: #6b7280; margin: 0;">Please check your connection and try again.</p>
            </div>
        `;
    });
}

// Product data from server
const productsData = @json($products->items());

// Available columns configuration
const availableColumns = {
    'image': { label: 'Image', width: 'w-[60px]', order: 1 },
    'title': { label: 'Product', width: 'min-w-[200px]', order: 2 },
    'skus': { label: 'SKUs', width: 'w-[150px]', order: 3 },
    'status': { label: 'Status', width: 'w-[100px]', order: 4 },
    'vendor': { label: 'Vendor', width: 'w-[120px]', order: 5 },
    'product_type': { label: 'Type', width: 'w-[120px]', order: 6 },
    'price_range': { label: 'Price Range', width: 'w-[120px]', order: 7 },
    'variants_count': { label: 'Variants', width: 'w-[80px]', order: 8 },
    'total_inventory': { label: 'Inventory', width: 'w-[100px]', order: 9 },
    'last_synced_at': { label: 'Last Sync', width: 'w-[100px]', order: 10 },
    'actions': { label: 'Actions', width: 'w-[120px]', order: 11, fixed: true }
};

// Default visible columns
const defaultColumns = ['image', 'title', 'skus', 'status', 'vendor', 'price_range', 'variants_count', 'total_inventory', 'last_synced_at', 'actions'];

// Load column settings from localStorage
let visibleColumns = JSON.parse(localStorage.getItem('products_visible_columns') || JSON.stringify(defaultColumns));
let columnOrder = JSON.parse(localStorage.getItem('products_column_order') || JSON.stringify(defaultColumns));

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
    const headerRow = document.createElement('tr');
    
    columnOrder.forEach(columnKey => {
        if (visibleColumns.includes(columnKey) && availableColumns[columnKey]) {
            const column = availableColumns[columnKey];
            const th = document.createElement('th');
            th.className = column.width;
            th.textContent = column.label;
            headerRow.appendChild(th);
        }
    });
    
    thead.innerHTML = '';
    thead.appendChild(headerRow);
}

function renderTableBody() {
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '';
    
    productsData.forEach(product => {
        const row = document.createElement('tr');
        row.className = 'hover:bg-gray-50';
        
        columnOrder.forEach(columnKey => {
            if (visibleColumns.includes(columnKey) && availableColumns[columnKey]) {
                const cell = document.createElement('td');
                cell.innerHTML = getCellContent(columnKey, product);
                row.appendChild(cell);
            }
        });
        
        tbody.appendChild(row);
    });
}

function getCellContent(columnKey, product) {
    switch(columnKey) {
        case 'image':
            if (product.featured_image) {
                return `<img src="${product.featured_image}" alt="${product.title}" class="w-12 h-12 object-cover rounded">`;
            } else {
                return `<div class="w-12 h-12 bg-gray-200 rounded flex items-center justify-center">
                    <i class="ki-filled ki-picture text-gray-400"></i>
                </div>`;
            }
            
        case 'title':
            return `<div class="flex flex-col">
                <span class="font-medium text-gray-900">${product.title}</span>
                ${product.product_type ? `<span class="text-xs text-gray-500">${product.product_type}</span>` : ''}
            </div>`;
            
        case 'skus':
            const skus = product.variants ? product.variants.map(v => v.sku).filter(sku => sku).join(', ') : '';
            return skus ? `<span class="text-sm text-gray-600" title="${skus}">${skus.length > 30 ? skus.substring(0, 30) + '...' : skus}</span>` : '<span class="text-gray-400">-</span>';
            
        case 'status':
            const statusClass = product.status === 'active' ? 'bg-green-100 text-green-800' : 
                              (product.status === 'draft' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800');
            return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${statusClass}">
                ${product.status.charAt(0).toUpperCase() + product.status.slice(1)}
            </span>`;
            
        case 'vendor':
            return `<span class="text-sm text-gray-600">${product.vendor || '-'}</span>`;
            
        case 'product_type':
            return `<span class="text-sm text-gray-600">${product.product_type || '-'}</span>`;
            
        case 'price_range':
            if (product.price_min && product.price_max) {
                const priceRange = product.price_min === product.price_max ? 
                    `PKR ${parseFloat(product.price_min).toFixed(2)}` : 
                    `PKR ${parseFloat(product.price_min).toFixed(2)} - ${parseFloat(product.price_max).toFixed(2)}`;
                return `<span class="font-medium">${priceRange}</span>`;
            }
            return '<span class="text-gray-500">-</span>';
            
        case 'variants_count':
            const variantCount = product.variants ? product.variants.length : 0;
            return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                ${variantCount}
            </span>`;
            
        case 'total_inventory':
            const inventoryClass = product.total_inventory > 0 ? 'text-green-600' : 'text-red-600';
            return `<span class="font-medium ${inventoryClass}">${product.total_inventory || 0}</span>`;
            
        case 'last_synced_at':
            if (product.last_synced_at) {
                const date = new Date(product.last_synced_at);
                return `<span class="text-xs text-gray-500">${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>`;
            }
            return '<span class="text-xs text-red-500">Never</span>';
            
        case 'actions':
            return `<div class="flex gap-2">
                <button onclick="viewProduct(${product.id})" 
                        class="kt-btn kt-btn-sm kt-btn-light" title="View Details">
                    <i class="ki-filled ki-eye text-sm"></i>
                </button>
                ${product.shopify_product_id ? `<button onclick="syncProduct(${product.id})" 
                        class="kt-btn kt-btn-sm kt-btn-success" title="Sync with Shopify">
                    <i class="ki-filled ki-arrows-circle text-sm"></i>
                </button>` : ''}
            </div>`;
            
        default:
            return '-';
    }
}

function openColumnSettings() {
    const content = document.getElementById('columnSettingsContent');
    content.innerHTML = '';
    
    // Create draggable list
    const list = document.createElement('div');
    list.id = 'columnList';
    list.style.cssText = 'display: flex; flex-direction: column; gap: 8px;';
    
    columnOrder.forEach(columnKey => {
        if (availableColumns[columnKey]) {
            const column = availableColumns[columnKey];
            const item = document.createElement('div');
            item.className = 'column-item';
            item.draggable = true;
            item.dataset.column = columnKey;
            item.style.cssText = `
                display: flex; align-items: center; gap: 12px; padding: 12px; 
                border: 1px solid #e5e7eb; border-radius: 8px; cursor: move;
                background: white; transition: all 0.2s;
            `;
            
            item.innerHTML = `
                <div style="display: flex; align-items: center; gap: 8px; flex: 1;">
                    <i class="ki-filled ki-menu text-gray-400"></i>
                    <span style="font-weight: 500; color: #111827;">${column.label}</span>
                </div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" ${visibleColumns.includes(columnKey) ? 'checked' : ''} 
                           onchange="toggleColumn('${columnKey}')" style="margin: 0;">
                    <span style="font-size: 14px; color: #6b7280;">Show</span>
                </label>
            `;
            
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragover', handleDragOver);
            item.addEventListener('drop', handleDrop);
            item.addEventListener('dragend', handleDragEnd);
            
            list.appendChild(item);
        }
    });
    
    content.appendChild(list);
    document.getElementById('columnSettingsModal').style.display = 'block';
}

function toggleColumn(columnKey) {
    if (visibleColumns.includes(columnKey)) {
        visibleColumns = visibleColumns.filter(col => col !== columnKey);
    } else {
        visibleColumns.push(columnKey);
    }
}

function saveColumnSettings() {
    localStorage.setItem('products_visible_columns', JSON.stringify(visibleColumns));
    localStorage.setItem('products_column_order', JSON.stringify(columnOrder));
    renderTable();
    closeModal('columnSettingsModal');
}

// Drag and drop functionality
let draggedElement = null;

function handleDragStart(e) {
    draggedElement = this;
    this.style.opacity = '0.5';
}

function handleDragOver(e) {
    e.preventDefault();
}

function handleDrop(e) {
    e.preventDefault();
    if (draggedElement !== this) {
        const draggedColumn = draggedElement.dataset.column;
        const targetColumn = this.dataset.column;
        
        const draggedIndex = columnOrder.indexOf(draggedColumn);
        const targetIndex = columnOrder.indexOf(targetColumn);
        
        columnOrder.splice(draggedIndex, 1);
        columnOrder.splice(targetIndex, 0, draggedColumn);
        
        // Re-render the list
        openColumnSettings();
    }
}

function handleDragEnd(e) {
    this.style.opacity = '1';
    draggedElement = null;
}

function viewProduct(productId) {
    const modal = document.getElementById('productViewModal');
    const content = document.getElementById('productViewContent');
    
    // Show loading
    content.innerHTML = `
        <div style="padding: 40px; text-align: center;">
            <div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <p style="margin-top: 16px; color: #6b7280;">Loading product details...</p>
        </div>
    `;
    
    modal.style.display = 'block';
    
    // Fetch product details
    fetch(`/products/${productId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderProductDetails(data.product);
            } else {
                content.innerHTML = `
                    <div style="padding: 40px; text-align: center;">
                        <div style="width: 48px; height: 48px; background-color: #fef2f2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                            <i class="ki-filled ki-cross text-red-500 text-xl"></i>
                        </div>
                        <h4 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 8px 0;">Error</h4>
                        <p style="color: #6b7280; margin: 0;">${data.message || 'Failed to load product details'}</p>
                        <button onclick="closeModal('productViewModal')" 
                                style="margin-top: 16px; padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer;">
                            Close
                        </button>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = `
                <div style="padding: 40px; text-align: center;">
                    <div style="width: 48px; height: 48px; background-color: #fef2f2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                        <i class="ki-filled ki-cross text-red-500 text-xl"></i>
                    </div>
                    <h4 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 8px 0;">Network Error</h4>
                    <p style="color: #6b7280; margin: 0;">Failed to load product details. Please try again.</p>
                    <button onclick="closeModal('productViewModal')" 
                            style="margin-top: 16px; padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        Close
                    </button>
                </div>
            `;
        });
}

function renderProductDetails(product) {
    const content = document.getElementById('productViewContent');
    
    content.innerHTML = `
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #111827;">${product.title}</h3>
            <button onclick="closeModal('productViewModal')" 
                    style="width: 32px; height: 32px; border: none; background: #f3f4f6; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                <i class="ki-filled ki-cross text-gray-500"></i>
            </button>
        </div>
        
        <div style="padding: 24px; max-height: 60vh; overflow-y: auto;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                <div>
                    <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #374151;">Product Information</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #6b7280; font-size: 14px;">Status:</span>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${product.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'}">
                                ${product.status.charAt(0).toUpperCase() + product.status.slice(1)}
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #6b7280; font-size: 14px;">Vendor:</span>
                            <span style="color: #111827; font-size: 14px;">${product.vendor || '-'}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #6b7280; font-size: 14px;">Type:</span>
                            <span style="color: #111827; font-size: 14px;">${product.product_type || '-'}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #6b7280; font-size: 14px;">Handle:</span>
                            <span style="color: #111827; font-size: 14px;">${product.shopify_handle || '-'}</span>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #374151;">Pricing & Inventory</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #6b7280; font-size: 14px;">Price Range:</span>
                            <span style="color: #111827; font-size: 14px; font-weight: 500;">
                                ${product.price_min && product.price_max ? 
                                    (product.price_min === product.price_max ? 
                                        `PKR ${parseFloat(product.price_min).toFixed(2)}` : 
                                        `PKR ${parseFloat(product.price_min).toFixed(2)} - ${parseFloat(product.price_max).toFixed(2)}`) : 
                                    '-'}
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #6b7280; font-size: 14px;">Total Inventory:</span>
                            <span style="color: #111827; font-size: 14px; font-weight: 500;">${product.total_inventory || 0}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #6b7280; font-size: 14px;">Variants:</span>
                            <span style="color: #111827; font-size: 14px; font-weight: 500;">${product.variants ? product.variants.length : 0}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #6b7280; font-size: 14px;">Last Sync:</span>
                            <span style="color: #111827; font-size: 14px;">
                                ${product.last_synced_at ? 
                                    new Date(product.last_synced_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 
                                    'Never'}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            ${product.description ? `
                <div style="margin-bottom: 24px;">
                    <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #374151;">Description</h4>
                    <div style="color: #6b7280; font-size: 14px; line-height: 1.5;">${product.description}</div>
                </div>
            ` : ''}
            
            ${product.variants && product.variants.length > 0 ? `
                <div>
                    <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #374151;">Variants</h4>
                    <div style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead style="background: #f9fafb;">
                                <tr>
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">Title</th>
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">SKU</th>
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">Price</th>
                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">Inventory</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${product.variants.map(variant => `
                                    <tr style="border-bottom: 1px solid #f3f4f6;">
                                        <td style="padding: 12px; font-size: 14px; color: #111827;">${variant.title || 'Default'}</td>
                                        <td style="padding: 12px; font-size: 14px; color: #6b7280;">${variant.sku || '-'}</td>
                                        <td style="padding: 12px; font-size: 14px; color: #111827; font-weight: 500;">PKR ${parseFloat(variant.price || 0).toFixed(2)}</td>
                                        <td style="padding: 12px; font-size: 14px; color: ${variant.inventory_quantity > 0 ? '#059669' : '#dc2626'}; font-weight: 500;">${variant.inventory_quantity || 0}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            ` : ''}
        </div>
    `;
}

function syncProduct(productId) {
    if (!confirm('Sync this product with Shopify? This will update all product information and variants.')) {
        return;
    }
    
    // Get CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                     document.querySelector('input[name="_token"]')?.value || '';
    
    fetch(`/products/${productId}/sync`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({
            _token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Product synced successfully!');
            location.reload();
        } else {
            alert('Sync failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Sync error:', error);
        alert('Network error occurred');
    });
}

// Add CSS for spinner animation
const style = document.createElement('style');
style.textContent = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);
</script>
@endsection
