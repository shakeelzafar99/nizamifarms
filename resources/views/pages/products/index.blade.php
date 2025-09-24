@extends('layouts.app')

@section('title', 'Products')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/products-tweaks.css') }}">
@endpush

@section('content')
<div class="products-index">
<!-- Enhanced Header Section -->
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-6 pb-6">
        <div class="flex flex-col justify-center gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                    <i class="ki-filled ki-shop text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold leading-tight text-gray-900">Products</h1>
                    <div class="flex items-center gap-2 text-sm font-medium text-gray-600 mt-1">
                        <i class="ki-filled ki-information-2 text-blue-500"></i>
                        {{ $products->total() }} products found
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Action Buttons -->
        <div class="products-toolbar flex items-center gap-3">
            <div class="flex items-center gap-2 bg-white rounded-xl shadow-sm border border-gray-200 p-1">
                <a href="{{ route('products.create') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-700 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-all duration-200">
                    <i class="ki-filled ki-plus text-blue-500"></i>
                Create Product
            </a>
                <button onclick="openColumnSettings()" class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-700 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-all duration-200">
                    <i class="ki-filled ki-setting-2 text-gray-500"></i>
                Columns
            </button>
                <a href="{{ route('products.attributes') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-700 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-all duration-200">
                    <i class="ki-filled ki-category text-gray-500"></i>
                Attributes
            </a>
                <button onclick="openBulkAdjustPricesModal()" class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-700 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-all duration-200">
                    <i class="ki-filled ki-price-tag text-gray-500"></i>
                Adjust Prices
            </button>
            </div>
            <button onclick="openImportModal()" class="flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-semibold rounded-xl shadow-lg hover:shadow-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-200 transform hover:-translate-y-0.5">
                <i class="ki-filled ki-cloud-download"></i>
                Import Products
            </button>
        </div>
    </div>
</div>

<!-- Enhanced Main Content -->
<div class="container-fixed">
    <div class="grid gap-4">
        <!-- Modern Card with Enhanced Styling -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <!-- Enhanced Filter Section -->
            <div class="bg-gradient-to-r from-gray-50 to-white border-b border-gray-100 px-6 py-4">
                    <form method="GET" id="productSearchForm">
                        <!-- Search Bar Row -->
                        <div class="flex items-center gap-4 mb-4">
                            <div class="search-input flex-1 relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                        <input type="text" name="search" value="{{ request('search') }}" 
                                       placeholder="Search products, SKUs, vendors..." 
                                       class="w-full pl-12 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white shadow-sm text-gray-900 placeholder-gray-500"
                               id="productSearchInput"
                               autocomplete="off">
                            </div>
                            <button type="submit" onclick="event.preventDefault(); performSearch();" 
                                    class="flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all duration-200 shadow-sm hover:shadow-md">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                Search
                            </button>
                        </div>
                        
                        <!-- Filter Pills Row -->
                        <div class="filters-bar flex flex-wrap items-center gap-3">
                        <!-- Status Filter -->
                            <div class="relative min-w-[120px]">
                                <select name="status" class="w-full bg-white border border-gray-200 rounded-lg px-3 py-2.5 pr-10 text-sm font-medium text-gray-700 hover:border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 cursor-pointer" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                            <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                            <option value="archived" {{ request('status') == 'archived' ? 'selected' : '' }}>Archived</option>
                        </select>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>

                        <!-- Category Filter -->
                            <div class="relative min-w-[140px]">
                                <select name="product_type" class="w-full bg-white border border-gray-200 rounded-lg px-3 py-2.5 pr-10 text-sm font-medium text-gray-700 hover:border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 cursor-pointer" id="categoryFilter">
                            <option value="">All Categories</option>
                            @foreach($productTypes as $type)
                                <option value="{{ $type }}" {{ request('product_type') == $type ? 'selected' : '' }}>
                                    {{ $type }}
                                </option>
                            @endforeach
                        </select>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>

                        <!-- Vendor Filter -->
                            <div class="relative min-w-[130px]">
                                <select name="vendor" class="w-full bg-white border border-gray-200 rounded-lg px-3 py-2.5 pr-10 text-sm font-medium text-gray-700 hover:border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 cursor-pointer" id="vendorFilter">
                            <option value="">All Vendors</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor }}" {{ request('vendor') == $vendor ? 'selected' : '' }}>
                                    {{ $vendor }}
                                </option>
                            @endforeach
                        </select>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>

                        <!-- Category Level 1 Filter -->
                            <div class="relative min-w-[160px]">
                                <select name="attribute_1" class="w-full bg-white border border-gray-200 rounded-lg px-3 py-2.5 pr-10 text-sm font-medium text-gray-700 hover:border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 cursor-pointer" id="attr1Filter">
                            <option value="">All {{ $attributeLabels['1'] ?? 'Category Level 1' }}</option>
                            @foreach($attribute1s as $val)
                                <option value="{{ $val }}" {{ request('attribute_1') == $val ? 'selected' : '' }}>
                                    {{ $val }}
                                </option>
                            @endforeach
                        </select>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                            
                            <!-- Hidden Attribute Filters -->
                        <select name="attribute_2" class="select select-sm" id="attr2Filter" style="display: none;">
                            <option value="">All {{ $attributeLabels['2'] ?? 'Category Level 2' }}</option>
                            @foreach($attribute2s as $val)
                                <option value="{{ $val }}" {{ request('attribute_2') == $val ? 'selected' : '' }}>
                                    {{ $val }}
                                </option>
                            @endforeach
                        </select>
                        <select name="attribute_3" class="select select-sm" id="attr3Filter" style="display: none;">
                            <option value="">All {{ $attributeLabels['3'] ?? 'Category Level 3' }}</option>
                            @foreach($attribute3s as $val)
                                <option value="{{ $val }}" {{ request('attribute_3') == $val ? 'selected' : '' }}>
                                    {{ $val }}
                                </option>
                            @endforeach
                        </select>
                        
                        <!-- Sync Status Filter -->
                            <div class="relative min-w-[130px]">
                                <select name="sync_status" class="w-full bg-white border border-gray-200 rounded-lg px-3 py-2.5 pr-10 text-sm font-medium text-gray-700 hover:border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 cursor-pointer" id="syncStatusFilter">
                            <option value="">All Sources</option>
                            @foreach($syncStatuses as $syncStatus)
                                <option value="{{ $syncStatus }}" {{ request('sync_status') == $syncStatus ? 'selected' : '' }}>
                                    {{ ucfirst($syncStatus) }}
                                </option>
                            @endforeach
                        </select>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                            
                        @if(request()->hasAny(['search', 'status', 'sync_status','product_type','vendor','attribute_1','attribute_2','attribute_3']))
                                <button type="button" onclick="event.preventDefault(); clearAllFilters();" 
                                        class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-all duration-200" id="clearFiltersBtn">
                                    <i class="ki-filled ki-cross-circle text-red-500"></i>
                                    Clear Filters
                                </button>
                        @endif
                        </div>
                    </form>
                </div>
            </div>

            <!-- Enhanced Table Section -->
            <div class="px-6 pb-6">
                <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table-products w-full" id="productsTable">
                            <thead id="tableHead" class="bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200">
                            <!-- Dynamic headers will be inserted here -->
                        </thead>
                            <tbody id="tableBody" class="divide-y divide-gray-100">
                            <!-- Dynamic rows will be inserted here -->
                        </tbody>
                    </table>
                    </div>
                </div>

                <!-- Enhanced Pagination -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-6 pt-6 border-t border-gray-100">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <div class="flex items-center gap-1">
                            <i class="ki-filled ki-information-2 text-blue-500"></i>
                            <span class="font-medium">Showing {{ $products->firstItem() ?? 0 }} to {{ $products->lastItem() ?? 0 }}</span>
                    </div>
                        <span>of</span>
                        <span class="font-semibold text-gray-900">{{ $products->total() }} products</span>
                    </div>
                    <div class="flex items-center gap-2">
                    {{ $products->appends(request()->query())->links() }}
                    </div>
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
                <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;">Import Products</h3>
            </div>
            
            <div id="importProductsContent" style="padding: 24px;">
                <!-- Source Selection -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">
                        Import Source
                    </label>
                    <select id="importSource" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="updateImportOptions()">
                        <option value="shopify">Shopify Store</option>
                        <option value="woocommerce">WooCommerce Store</option>
                    </select>
                </div>

                <!-- Import Type -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">
                        Import Type
                    </label>
                    <select id="importType" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="updateImportOptions()">
                        <option value="limited">Limited Import (50-250 products)</option>
                        <option value="all">Import All Products</option>
                    </select>
                </div>

                <!-- Limit Input (shown only for limited import) -->
                <div id="limitContainer" style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">
                        Number of Products to Import
                    </label>
                    <input type="number" id="productLimit" value="50" min="1" max="250" 
                           style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    <p style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                        Maximum 250 products per import.
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
                <button onclick="executeSelectedImport()" 
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

<!-- Bulk Adjust Prices Modal -->
<div id="bulkAdjustPricesModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); max-width: 90vw; max-height: 90vh; overflow-y: auto; width: 520px;">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #111827;">Bulk Adjust Prices</h3>
            <p style="margin: 8px 0 0 0; color: #6b7280; font-size: 13px;">Choose a filter and apply an increase or decrease to variant prices.</p>
        </div>
        <div style="padding: 20px; display: grid; gap: 12px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label class="form-label">Category</label>
                    <select id="bulkCategory" class="select select-sm">
                        <option value="">Any</option>
                        @foreach($productTypes as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Vendor</label>
                    <select id="bulkVendor" class="select select-sm">
                        <option value="">Any</option>
                        @foreach($vendors as $vendor)
                            <option value="{{ $vendor }}">{{ $vendor }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                <div>
                    <label class="form-label">{{ $attributeLabels['1'] ?? 'Category Level 1' }}</label>
                    <select id="bulkAttr1" class="select select-sm">
                        <option value="">Any</option>
                        @foreach($attribute1s as $val)
                            <option value="{{ $val }}">{{ $val }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">{{ $attributeLabels['2'] ?? 'Category Level 2' }}</label>
                    <select id="bulkAttr2" class="select select-sm">
                        <option value="">Any</option>
                        @foreach($attribute2s as $val)
                            <option value="{{ $val }}">{{ $val }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">{{ $attributeLabels['3'] ?? 'Category Level 3' }}</label>
                    <select id="bulkAttr3" class="select select-sm">
                        <option value="">Any</option>
                        @foreach($attribute3s as $val)
                            <option value="{{ $val }}">{{ $val }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label class="form-label">Operation</label>
                    <select id="bulkOperation" class="select select-sm">
                        <option value="increase">Increase</option>
                        <option value="decrease">Decrease</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Mode</label>
                    <select id="bulkMode" class="select select-sm">
                        <option value="percent">Percentage (%)</option>
                        <option value="fixed">Fixed (PKR)</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="form-label">Amount</label>
                <input type="number" id="bulkAmount" class="input input-sm" placeholder="e.g., 10 for 10% or 100 for PKR 100" step="0.01" min="0">
            </div>
        </div>
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 12px;">
            <button onclick="closeModal('bulkAdjustPricesModal')" class="kt-btn kt-btn-light">Cancel</button>
            <button onclick="submitBulkAdjustPrices()" class="kt-btn kt-btn-primary">Apply</button>
        </div>
    </div>
 </div>

<script>
// Open unified import modal
function openImportModal() {
    document.getElementById('importProductsModal').style.display = 'block';
    updateImportOptions(); // Set initial state
}

// Update UI based on selected options
function updateImportOptions() {
    const importType = document.getElementById('importType').value;
    const limitContainer = document.getElementById('limitContainer');
    
    if (importType === 'limited') {
        limitContainer.style.display = 'block';
    } else {
        limitContainer.style.display = 'none';
    }
}

// Execute the selected import option
function executeSelectedImport() {
    const source = document.getElementById('importSource').value;
    const importType = document.getElementById('importType').value;
    
    closeModal('importProductsModal');
    
    if (importType === 'limited') {
        executeImport(source);
    } else {
        const sourceName = source === 'woocommerce' ? 'WooCommerce' : 'Shopify';
        if (confirm(`This will import ALL products from your ${sourceName} store. This may take several minutes depending on the number of products. Continue?`)) {
            executeImportAll(sourceName);
        }
    }
}

// Reuse existing import functions
function importProducts() {
    openImportModal();
}

function importAllProducts() {
    executeImportAll('Shopify');
}

function importAllProductsFromWoo() {
    executeImportAll('WooCommerce');
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function executeImportAll(source = 'Shopify') {
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
    const url = '/products/import-all' + (source ? ('?source=' + encodeURIComponent(source)) : '');
    fetch(url, {
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

function executeImport(source = 'shopify') {
    const limit = document.getElementById('productLimit').value || 50;
    const content = document.getElementById('importProductsContent');
    const sourceName = source === 'woocommerce' ? 'WooCommerce' : 'Shopify';
    
    // Show loading
    content.innerHTML = `
        <div style="text-align: center; padding: 40px;">
            <div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <p style="margin-top: 16px; color: #6b7280;">Importing products from ${sourceName}...</p>
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

// Format date as local time (not UTC)
function formatDateLocal(dateString) {
    if (!dateString) return '';
    try {
        // Handle different date formats more robustly
        let date;
        if (dateString.includes(' ')) {
            // Format: "2025-09-09 14:59:48"
            const [datePart, timePart] = dateString.split(' ');
            const [year, month, day] = datePart.split('-');
            const [hour, minute, second] = timePart.split(':');
            date = new Date(year, month - 1, day, hour, minute, second);
        } else if (dateString.includes('T')) {
            // Format: "2025-09-09T14:59:48" (ISO format)
            date = new Date(dateString);
        } else {
            // Fallback to standard parsing
            date = new Date(dateString);
        }
        
        // Check if date is valid
        if (isNaN(date.getTime())) {
            return dateString; // Return original string if parsing fails
        }
        
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (error) {
        console.error('Date parsing error:', error, 'for date:', dateString);
        return dateString; // Return original string if parsing fails
    }
}

// Product data from server (make it globally accessible)
window.productsData = @json($products->items());

// Available columns configuration
const availableColumns = {
    'image': { label: 'Image', width: 'w-[60px]', order: 1, cssClass: 'col-image' },
    'title': { label: 'Product', width: 'min-w-[200px]', order: 2, cssClass: 'col-name' },
    'skus': { label: 'SKUs', width: 'w-[150px]', order: 3, cssClass: 'col-sku' },
    'status': { label: 'Status', width: 'w-[100px]', order: 4, cssClass: 'col-status' },
    'vendor': { label: 'Vendor', width: 'w-[120px]', order: 5, cssClass: 'col-vendor' },
    'product_type': { label: 'Type', width: 'w-[120px]', order: 6, cssClass: 'col-type' },
    'attribute_1': { label: '{{ $attributeLabels["1"] ?? "Category Level 1" }}', width: 'w-[140px]', order: 7, cssClass: 'col-attr1' },
    'attribute_2': { label: '{{ $attributeLabels["2"] ?? "Category Level 2" }}', width: 'w-[140px]', order: 8, cssClass: 'col-attr2' },
    'attribute_3': { label: '{{ $attributeLabels["3"] ?? "Category Level 3" }}', width: 'w-[140px]', order: 9, cssClass: 'col-attr3' },
    'price_range': { label: 'Price Range', width: 'w-[120px]', order: 10, cssClass: 'col-price' },
    'variants_count': { label: 'Variants', width: 'w-[80px]', order: 11, cssClass: 'col-variants' },
    'total_inventory': { label: 'Inventory', width: 'w-[100px]', order: 12, cssClass: 'col-inventory' },
    'last_synced_at': { label: 'Last sync', width: 'w-[100px]', order: 13, cssClass: 'col-sync' },
    'actions': { label: 'Actions', width: 'w-[120px]', order: 14, fixed: true, cssClass: 'col-actions' }
};

// Default visible columns
const defaultColumns = ['image', 'title', 'skus', 'status', 'vendor', 'price_range', 'variants_count', 'total_inventory', 'last_synced_at', 'actions'];

// All available columns (including attributes for column selector)
const allColumns = ['image', 'title', 'skus', 'status', 'vendor', 'product_type', 'attribute_1', 'attribute_2', 'attribute_3', 'price_range', 'variants_count', 'total_inventory', 'last_synced_at', 'actions'];

// Load column settings from localStorage
let visibleColumns = JSON.parse(localStorage.getItem('products_visible_columns') || JSON.stringify(defaultColumns));
let columnOrder = JSON.parse(localStorage.getItem('products_column_order') || JSON.stringify(allColumns));

// Initialize table on page load
document.addEventListener('DOMContentLoaded', function() {
    renderTable();
    initializeRealTimeSearch();
});

// Real-time search functionality
let searchTimeout = null;

function initializeRealTimeSearch() {
    const searchInput = document.getElementById('productSearchInput');
    const statusFilter = document.getElementById('statusFilter');
    const syncStatusFilter = document.getElementById('syncStatusFilter');
    const categoryFilter = document.getElementById('categoryFilter');
    const vendorFilter = document.getElementById('vendorFilter');
    const attr1Filter = document.getElementById('attr1Filter');
    const attr2Filter = document.getElementById('attr2Filter');
    const attr3Filter = document.getElementById('attr3Filter');
    
    if (!searchInput) return;
    
    // Search as user types
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            performSearch();
        }, 300); // Wait 300ms after user stops typing
    });
    
    // Also trigger search on filter changes
    if (statusFilter) {
        statusFilter.addEventListener('change', performSearch);
    }
    if (syncStatusFilter) {
        syncStatusFilter.addEventListener('change', performSearch);
    }
    if (categoryFilter) categoryFilter.addEventListener('change', performSearch);
    if (vendorFilter) vendorFilter.addEventListener('change', performSearch);
    if (attr1Filter) attr1Filter.addEventListener('change', performSearch);
    if (attr2Filter) attr2Filter.addEventListener('change', performSearch);
    if (attr3Filter) attr3Filter.addEventListener('change', performSearch);
}

function performSearch() {
    const searchValue = document.getElementById('productSearchInput').value;
    const statusValue = document.getElementById('statusFilter').value;
    const syncStatusValue = document.getElementById('syncStatusFilter').value;
    const categoryValue = document.getElementById('categoryFilter').value;
    const vendorValue = document.getElementById('vendorFilter').value;
    const attr1Value = document.getElementById('attr1Filter').value;
    const attr2Value = document.getElementById('attr2Filter').value;
    const attr3Value = document.getElementById('attr3Filter').value;
    
    // Build query parameters
    const params = new URLSearchParams();
    if (searchValue.trim()) params.set('search', searchValue.trim());
    if (statusValue) params.set('status', statusValue);
    if (syncStatusValue) params.set('sync_status', syncStatusValue);
    if (categoryValue) params.set('product_type', categoryValue);
    if (vendorValue) params.set('vendor', vendorValue);
    if (attr1Value) params.set('attribute_1', attr1Value);
    if (attr2Value) params.set('attribute_2', attr2Value);
    if (attr3Value) params.set('attribute_3', attr3Value);
    
    // Show loading state
    showLoadingState();
    
    // Make AJAX request to get filtered results
    fetch(`${window.location.pathname}?${params.toString()}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the products data
            window.productsData = data.products;
            
            // Re-render the table with new data
            renderTable();
            
            // Update pagination info
            updatePaginationInfo(data.pagination);
            
            // Update URL without page refresh
            const newUrl = params.toString() ? `${window.location.pathname}?${params.toString()}` : window.location.pathname;
            window.history.replaceState({}, '', newUrl);
            
            // Show/hide clear button
            updateClearButton();
        }
        hideLoadingState();
    })
    .catch(error => {
        console.error('Search error:', error);
        hideLoadingState();
    });
}

function showLoadingState() {
    const tableBody = document.getElementById('tableBody');
    if (tableBody) {
        tableBody.innerHTML = '<tr><td colspan="100%" class="text-center py-8"><div class="flex items-center justify-center gap-2"><div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>Searching...</div></td></tr>';
    }
}

function hideLoadingState() {
    // Loading state will be hidden when renderTable() is called
}

function updatePaginationInfo(pagination) {
    const paginationInfo = document.querySelector('.text-sm.text-gray-700');
    if (paginationInfo && pagination) {
        paginationInfo.textContent = `Showing ${pagination.from || 0} to ${pagination.to || 0} of ${pagination.total || 0} products`;
    }
}

function updateClearButton() {
    const searchValue = document.getElementById('productSearchInput').value;
    const statusValue = document.getElementById('statusFilter').value;
    const syncStatusValue = document.getElementById('syncStatusFilter').value;
    const categoryValue = document.getElementById('categoryFilter').value;
    const vendorValue = document.getElementById('vendorFilter').value;
    const attr1Value = document.getElementById('attr1Filter').value;
    const attr2Value = document.getElementById('attr2Filter').value;
    const attr3Value = document.getElementById('attr3Filter').value;
    
    const clearBtn = document.getElementById('clearFiltersBtn');
    const hasFilters = searchValue.trim() || statusValue || syncStatusValue || categoryValue || vendorValue || attr1Value || attr2Value || attr3Value;
    
    if (hasFilters && !clearBtn) {
        // Add clear button
        const filterButton = document.querySelector('button[type="submit"]');
        const clearButton = document.createElement('a');
        clearButton.href = window.location.pathname;
        clearButton.className = 'kt-btn kt-btn-sm kt-btn-light';
        clearButton.id = 'clearFiltersBtn';
        clearButton.textContent = 'Clear';
        clearButton.onclick = function(e) {
            e.preventDefault();
            clearAllFilters();
        };
        filterButton.parentNode.insertBefore(clearButton, filterButton.nextSibling);
    } else if (!hasFilters && clearBtn) {
        // Remove clear button
        clearBtn.remove();
    }
}

function clearAllFilters() {
    document.getElementById('productSearchInput').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('syncStatusFilter').value = '';
    const categoryFilter = document.getElementById('categoryFilter');
    const vendorFilter = document.getElementById('vendorFilter');
    const attr1Filter = document.getElementById('attr1Filter');
    const attr2Filter = document.getElementById('attr2Filter');
    const attr3Filter = document.getElementById('attr3Filter');
    if (categoryFilter) categoryFilter.value = '';
    if (vendorFilter) vendorFilter.value = '';
    if (attr1Filter) attr1Filter.value = '';
    if (attr2Filter) attr2Filter.value = '';
    if (attr3Filter) attr3Filter.value = '';
    performSearch();
}

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
            th.className = `${column.cssClass} ${column.width} px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider`;
            th.innerHTML = `<span class="hdr">${column.label}</span>`;
            headerRow.appendChild(th);
        }
    });
    
    thead.innerHTML = '';
    thead.appendChild(headerRow);
}

function renderTableBody() {
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '';

    window.productsData.forEach((product, index) => {
        const row = document.createElement('tr');
        row.className = 'product-row hover:bg-blue-50 transition-colors duration-150 cursor-pointer group';
        
        columnOrder.forEach(columnKey => {
            if (visibleColumns.includes(columnKey) && availableColumns[columnKey]) {
                const column = availableColumns[columnKey];
                const cell = document.createElement('td');
                cell.className = `${column.cssClass} px-6 py-4 whitespace-nowrap`;
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
                return `<img src="${product.featured_image}" alt="${product.title}" class="thumb">`;
            } else {
                return `<div class="thumb flex items-center justify-center">
                    <i class="ki-filled ki-picture text-gray-400 text-sm"></i>
                </div>`;
            }
            
        case 'title':
            return `<div>
                <div class="product-title" title="${product.title}">${product.title}</div>
                ${product.product_type ? `<div class="product-sub">${product.product_type}</div>` : ''}
            </div>`;
            
        case 'skus':
            const skus = product.variants ? product.variants.map(v => v.sku).filter(sku => sku).join(', ') : '';
            return skus ? `<div class="flex items-center">
                <span class="text-sm font-mono text-gray-700 bg-gray-50 px-2 py-1 rounded-lg border border-gray-200" title="${skus}">
                    ${skus.length > 25 ? skus.substring(0, 25) + '...' : skus}
                </span>
            </div>` : '<span class="text-gray-400 text-sm">No SKU</span>';
            
        case 'status':
            const statusConfig = {
                'active': { 
                    bg: 'bg-green-50', 
                    text: 'text-green-700', 
                    border: 'border-green-200',
                    icon: 'ki-check-circle',
                    iconColor: 'text-green-500'
                },
                'draft': { 
                    bg: 'bg-yellow-50', 
                    text: 'text-yellow-700', 
                    border: 'border-yellow-200',
                    icon: 'ki-time',
                    iconColor: 'text-yellow-500'
                },
                'archived': { 
                    bg: 'bg-gray-50', 
                    text: 'text-gray-700', 
                    border: 'border-gray-200',
                    icon: 'ki-archive',
                    iconColor: 'text-gray-500'
                }
            };
            const config = statusConfig[product.status] || statusConfig['archived'];
            const statusText = product.status ? product.status.charAt(0).toUpperCase() + product.status.slice(1) : 'Unknown';
            const statusPillClass = product.status === 'active' ? 'pill success' : 'pill neutral';
            return `<span class="${statusPillClass}">${statusText}</span>`;
            
        case 'vendor':
            return product.vendor ? 
                `<div class="flex items-center gap-2">
                    <div class="w-6 h-6 bg-gradient-to-br from-purple-100 to-purple-200 rounded-lg flex items-center justify-center">
                        <i class="ki-filled ki-shop text-purple-600 text-xs"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-700">${product.vendor}</span>
                </div>` : 
                '<span class="text-gray-400 text-sm">No vendor</span>';
            
        case 'product_type':
            return product.product_type ? 
                `<span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">${product.product_type}</span>` : 
                '<span class="text-gray-400 text-sm">No type</span>';
            
        case 'attribute_1':
            return product.attribute_1 ? 
                `<span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-purple-50 text-purple-700 border border-purple-200">${product.attribute_1}</span>` : 
                '<span class="text-gray-400 text-sm">-</span>';
                
        case 'attribute_2':
            return product.attribute_2 ? 
                `<span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-200">${product.attribute_2}</span>` : 
                '<span class="text-gray-400 text-sm">-</span>';
                
        case 'attribute_3':
            return product.attribute_3 ? 
                `<span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-teal-50 text-teal-700 border border-teal-200">${product.attribute_3}</span>` : 
                '<span class="text-gray-400 text-sm">-</span>';
            
        case 'price_range':
            if (product.price_min && product.price_max) {
                const priceRange = product.price_min === product.price_max ? 
                    `PKR ${parseFloat(product.price_min).toFixed(2)}` : 
                    `PKR ${parseFloat(product.price_min).toFixed(2)} - ${parseFloat(product.price_max).toFixed(2)}`;
                return `<span class="price">${priceRange}</span>`;
            }
            return '<span class="text-gray-400 text-sm">No price</span>';
            
        case 'variants_count':
            const variantCount = product.variants ? product.variants.length : 0;
            if (variantCount > 1) {
                return `<span class="pill neutral">${variantCount} variants</span>`;
            } else {
                return `<span class="pill neutral">${variantCount}</span>`;
            }
            
        case 'total_inventory':
            const inventory = product.total_inventory || 0;
            const inventoryPillClass = inventory > 10 ? 'pill success' : inventory > 0 ? 'pill warn' : 'pill neutral';
            return `<span class="${inventoryPillClass}">${inventory}</span>`;
            
        case 'last_synced_at':
            if (product.last_synced_at) {
                const date = new Date(product.last_synced_at);
                const timeAgo = getTimeAgo(date);
                return `<div class="flex items-center gap-1.5">
                    <i class="ki-filled ki-time text-blue-500 text-xs"></i>
                    <span class="text-xs text-gray-600" title="${date.toLocaleString()}">${timeAgo}</span>
                </div>`;
            }
            return `<div class="flex items-center gap-1.5">
                <i class="ki-filled ki-information-2 text-red-500 text-xs"></i>
                <span class="text-xs font-medium text-red-600">Never</span>
            </div>`;
            
        case 'actions':
            return `<div class="actions">
                <button onclick="viewProduct(${product.id})" 
                        class="btn flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors duration-200 border border-blue-200" 
                        title="View Details">
                    <i class="ki-filled ki-eye text-sm"></i>
                </button>
                <button onclick="editProduct(${product.id})" 
                        class="btn flex items-center justify-center w-8 h-8 rounded-lg bg-green-50 text-green-600 hover:bg-green-100 transition-colors duration-200 border border-green-200" 
                        title="Edit Product">
                    <i class="ki-filled ki-pencil text-sm"></i>
                </button>
                ${product.shopify_product_id ? `<button onclick="syncProduct(${product.id})" 
                        class="btn flex items-center justify-center w-8 h-8 rounded-lg bg-purple-50 text-purple-600 hover:bg-purple-100 transition-colors duration-200 border border-purple-200" 
                        title="Sync with Shopify">
                    <i class="ki-filled ki-arrows-circle text-sm"></i>
                </button>` : ''}
            </div>`;
            
        default:
            return '-';
    }
}

// Helper function for time ago display
function getTimeAgo(date) {
    const now = new Date();
    const diffInSeconds = Math.floor((now - date) / 1000);
    
    if (diffInSeconds < 60) return 'Just now';
    if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`;
    if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`;
    if (diffInSeconds < 2592000) return `${Math.floor(diffInSeconds / 86400)}d ago`;
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
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

function openBulkAdjustPricesModal() {
    document.getElementById('bulkAdjustPricesModal').style.display = 'block';
}

function submitBulkAdjustPrices() {
    const payload = {
        mode: document.getElementById('bulkMode').value,
        operation: document.getElementById('bulkOperation').value,
        amount: parseFloat(document.getElementById('bulkAmount').value || '0'),
        product_type: document.getElementById('bulkCategory').value || '',
        vendor: document.getElementById('bulkVendor').value || '',
        attribute_1: document.getElementById('bulkAttr1').value || '',
        attribute_2: document.getElementById('bulkAttr2').value || '',
        attribute_3: document.getElementById('bulkAttr3').value || ''
    };

    if (!payload.amount || payload.amount <= 0) {
        alert('Please enter a valid amount.');
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                      document.querySelector('input[name="_token"]').value || '';

    fetch('/products/bulk-adjust-prices', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeModal('bulkAdjustPricesModal');
            performSearch(); // refresh table
        } else {
            alert('Bulk update failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Bulk adjust error', err);
        alert('Network error.');
    });
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

// Edit product - redirect to edit page
function editProduct(productId) {
    window.location.href = `/products/${productId}/edit`;
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
                                    formatDateLocal(product.last_synced_at) : 
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
</div> <!-- End products-index wrapper -->
@endsection
