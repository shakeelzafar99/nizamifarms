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
            <button onclick="importProducts()" class="kt-btn kt-btn-primary">
                <i class="ki-filled ki-cloud-download"></i>
                Import from Shopify
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
                    <table class="table table-auto table-border" data-datatable="true">
                        <thead>
                            <tr>
                                <th class="w-[60px]">Image</th>
                                <th class="min-w-[200px]">Product</th>
                                <th class="w-[100px]">Status</th>
                                <th class="w-[120px]">Vendor</th>
                                <th class="w-[100px]">Price Range</th>
                                <th class="w-[80px]">Variants</th>
                                <th class="w-[100px]">Inventory</th>
                                <th class="w-[100px]">Last Sync</th>
                                <th class="w-[120px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($products as $product)
                            <tr class="hover:bg-gray-50">
                                <td>
                                    @if($product->featured_image)
                                        <img src="{{ $product->featured_image }}" alt="{{ $product->title }}" 
                                             class="w-12 h-12 object-cover rounded">
                                    @else
                                        <div class="w-12 h-12 bg-gray-200 rounded flex items-center justify-center">
                                            <i class="ki-filled ki-picture text-gray-400"></i>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-col">
                                        <span class="font-medium text-gray-900">{{ $product->title }}</span>
                                        @if($product->product_type)
                                            <span class="text-xs text-gray-500">{{ $product->product_type }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                                        {{ $product->status === 'active' ? 'bg-green-100 text-green-800' : 
                                           ($product->status === 'draft' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800') }}">
                                        {{ ucfirst($product->status) }}
                                    </span>
                                </td>
                                <td>{{ $product->vendor ?: '-' }}</td>
                                <td>
                                    @if($product->price_min && $product->price_max)
                                        <span class="font-medium">PKR {{ $product->price_range }}</span>
                                    @else
                                        <span class="text-gray-500">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                                        {{ $product->variants->count() }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="font-medium {{ $product->total_inventory > 0 ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $product->total_inventory }}
                                    </span>
                                </td>
                                <td>
                                    @if($product->last_synced_at)
                                        <span class="text-xs text-gray-500">
                                            {{ $product->last_synced_at->format('M j, Y') }}
                                        </span>
                                    @else
                                        <span class="text-xs text-red-500">Never</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex gap-2">
                                        <button onclick="viewProduct({{ $product->id }})" 
                                                class="kt-btn kt-btn-sm kt-btn-light" title="View Details">
                                            <i class="ki-filled ki-eye text-sm"></i>
                                        </button>
                                        @if($product->shopify_product_id)
                                            <button onclick="syncProduct({{ $product->id }})" 
                                                    class="kt-btn kt-btn-sm kt-btn-success" title="Sync with Shopify">
                                                <i class="ki-filled ki-arrows-circle text-sm"></i>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
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

<script>
function importProducts() {
    document.getElementById('importProductsModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
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

function viewProduct(productId) {
    // Implement product view modal
    alert('Product view modal - to be implemented');
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
