@extends('layouts.app')

@section('title', '🌿 Khaas Products & Inventory')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-amber-600 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">📦 {{ $khaasBU->name }} Products & Inventory</h1>
                <p class="text-sm text-gray-600 mt-0.5">Store vs Warehouse inventory comparison · {{ $products->total() }} products</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                🌿 {{ $khaasBU->name }}
            </span>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
            <p class="text-sm text-green-800">✅ {{ session('success') }}</p>
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="text-sm text-red-800">❌ {{ session('error') }}</p>
        </div>
    @endif

    <!-- Filters -->
    <div class="bg-white border border-gray-200 rounded-xl p-4 mb-6">
        <form method="GET" action="{{ route('khaas.products') }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Product name, SKU..." 
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
            </div>
            <div class="min-w-[140px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                    <option value="">All</option>
                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="archived" {{ request('status') == 'archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat }}" {{ request('category') == $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors">
                <i class="ki-filled ki-magnifier mr-1"></i> Filter
            </button>
            @if(request()->hasAny(['search', 'status', 'category']))
                <a href="{{ route('khaas.products') }}" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                    Clear
                </a>
            @endif
        </form>
    </div>

    <!-- ⭐ Pending Transfer Approvals (inline on products page) -->
    @if($pendingTransferRecords->count() > 0)
    <div class="bg-white border border-amber-300 rounded-xl mb-6 overflow-hidden" style="background-color: #fffbeb;">
        <div class="px-5 py-3 border-b border-amber-200 flex items-center justify-between" style="background: linear-gradient(to right, #fffbeb, #fef3c7);">
            <div class="flex items-center gap-2">
                <span class="text-lg">⏳</span>
                <h3 class="font-semibold text-gray-900 text-sm">Pending Transfer Approvals</h3>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold" style="background-color: #f59e0b; color: #ffffff;">{{ $pendingTransferRecords->count() }}</span>
            </div>
            <a href="{{ route('khaas.operations', ['tab' => 'transfers']) }}" class="text-xs font-medium" style="color: #d97706;">View All →</a>
        </div>
        <div class="divide-y divide-amber-100">
            @foreach($pendingTransferRecords as $ptr)
            <div class="px-5 py-3 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center text-sm font-bold shrink-0" style="background-color: #fef3c7; color: #92400e;">
                        {{ strtoupper(substr($ptr->product ? $ptr->product->title : '?', 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-semibold text-gray-900 truncate">
                            {{ $ptr->product ? $ptr->product->title : 'Product #' . $ptr->product_id }}
                            @if($ptr->variant)
                                <span class="text-[10px] px-1 py-0.5 bg-gray-100 text-gray-500 rounded ml-1">{{ $ptr->variant->title }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                            <span class="font-medium" style="color: #92400e;">{{ $ptr->quantity }} units</span>
                            <span>🏭→🏪</span>
                            <span>by {{ $ptr->requester ? $ptr->requester->fullname : '—' }}</span>
                            <span>· {{ $ptr->created_at->format('M d, h:i A') }}</span>
                        </div>
                        @if($ptr->notes)
                            <div class="text-[10px] text-gray-400 mt-0.5 truncate">📝 {{ $ptr->notes }}</div>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <form method="POST" action="{{ route('khaas.transfers.approve', $ptr->id) }}" class="inline">
                        @csrf
                        <button type="submit" onclick="return confirm('Approve transfer of {{ $ptr->quantity }} units of {{ $ptr->product ? addslashes($ptr->product->title) : '' }} to store?')"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg shadow-sm" style="background-color: #16a34a; color: #ffffff;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">✓ Approve</button>
                    </form>
                    <button type="button" onclick="openProductRejectModal({{ $ptr->id }}, '{{ $ptr->product ? addslashes($ptr->product->title) : '' }}', {{ $ptr->quantity }})"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg" style="background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca;" onmouseover="this.style.backgroundColor='#fee2e2'" onmouseout="this.style.backgroundColor='#fef2f2'">✕ Reject</button>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Products Grid with Store vs Warehouse Comparison -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($products as $product)
        @php
            $firstVariant = $product->variants->first();
            $storeQty = $product->variants->sum('inventory_quantity') ?: ($product->total_inventory ?? 0);
            $price = $firstVariant ? $firstVariant->price : 0;
            $whData = $warehouseInventory[$product->id] ?? null;
            $warehouseQty = $whData['warehouse_qty'] ?? 0;
            $pendingQty = $pendingTransfers[$product->id] ?? 0;
            $unit = $whData['unit'] ?? 'pcs';
            $minStock = $whData['min_stock_level'] ?? 0;
            $isLowStock = $minStock > 0 && $warehouseQty <= $minStock;
        @endphp
        <div class="bg-white border {{ $isLowStock ? 'border-red-300' : 'border-gray-200' }} rounded-xl overflow-hidden hover:shadow-md transition-shadow">
            <!-- Product Header -->
            <div class="px-4 py-3 border-b {{ $isLowStock ? 'border-red-200 bg-red-50' : 'border-gray-100' }}">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <h3 class="font-semibold text-gray-900 text-sm truncate">{{ $product->title }}</h3>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs text-gray-500">SKU: {{ $firstVariant->sku ?? 'N/A' }}</span>
                            <span class="text-xs font-medium text-amber-700">PKR {{ number_format($price) }}</span>
                        </div>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                        {{ $product->status === 'active' ? 'bg-green-100 text-green-700' : '' }}
                        {{ $product->status === 'draft' ? 'bg-yellow-100 text-yellow-700' : '' }}
                        {{ $product->status === 'archived' ? 'bg-gray-100 text-gray-500' : '' }}
                    ">{{ $product->status }}</span>
                </div>
                @if($product->attribute_1)
                    <div class="mt-1">
                        <span class="text-[10px] px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded">{{ $product->attribute_1 }}</span>
                    </div>
                @endif
            </div>

            <!-- ⭐ Store vs Warehouse Comparison -->
            <div class="px-4 py-3">
                <div class="grid grid-cols-2 gap-3">
                    <!-- Store Inventory (clickable for transaction log) -->
                    <div class="bg-blue-50 rounded-lg p-3 text-center cursor-pointer hover:bg-blue-100 transition-colors group"
                         onclick="openStoreLogModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $storeQty }})"
                         title="Click to see inventory transaction log">
                        <div class="text-xs text-blue-600 font-medium mb-1">🏪 Store <span class="opacity-0 group-hover:opacity-100 transition-opacity text-[9px]">📋</span></div>
                        <div class="text-xl font-bold text-blue-800">{{ $storeQty }}</div>
                        <div class="text-[10px] text-blue-500">{{ $unit }}</div>
                        @if($pendingQty > 0)
                            <div class="mt-1 text-[10px] text-amber-600 font-medium">⏳ +{{ $pendingQty }} pending</div>
                        @endif
                    </div>
                    <!-- Warehouse Inventory -->
                    <div class="rounded-lg p-3 text-center {{ $isLowStock ? 'bg-red-50' : 'bg-amber-50' }}">
                        <div class="text-xs {{ $isLowStock ? 'text-red-600' : 'text-amber-600' }} font-medium mb-1">🏭 Warehouse</div>
                        <div class="text-xl font-bold {{ $isLowStock ? 'text-red-800' : 'text-amber-800' }}">{{ $warehouseQty }}</div>
                        <div class="text-[10px] {{ $isLowStock ? 'text-red-500' : 'text-amber-500' }}">{{ $unit }}</div>
                        @if($isLowStock)
                            <div class="mt-1 text-[10px] text-red-600 font-bold">⚠️ Low Stock</div>
                        @endif
                    </div>
                </div>

                <!-- Total Combined -->
                <div class="mt-2 bg-gray-50 rounded-lg px-3 py-2 flex items-center justify-between">
                    <span class="text-xs text-gray-600">Combined Total</span>
                    <span class="text-sm font-bold text-gray-800">{{ $storeQty + $warehouseQty }} {{ $unit }}</span>
                </div>
            </div>

            <!-- Actions -->
            <div class="px-4 py-3 border-t border-gray-100 bg-gray-50 flex items-center gap-2">
                <button onclick="openStockModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $firstVariant->id ?? 'null' }}, {{ $warehouseQty }})"
                    class="flex-1 px-2 py-1.5 bg-white border border-gray-300 text-gray-700 text-xs font-medium rounded-lg hover:bg-gray-100 transition-colors text-center">
                    📥 Warehouse
                </button>
                <button onclick="openStoreStockModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $firstVariant->id ?? 'null' }}, {{ $storeQty }})"
                    class="flex-1 px-2 py-1.5 bg-blue-50 border border-blue-300 text-blue-700 text-xs font-medium rounded-lg hover:bg-blue-100 transition-colors text-center">
                    🏪 Store Adjust
                </button>
                <button onclick="openTransferModal({{ $product->id }}, '{{ addslashes($product->title) }}', {{ $firstVariant->id ?? 'null' }}, {{ $warehouseQty }})"
                    class="flex-1 px-2 py-1.5 border text-xs font-medium rounded-lg transition-colors text-center" style="background-color: #fef3c7; color: #92400e; border-color: #fbbf24;" onmouseover="this.style.backgroundColor='#fde68a'" onmouseout="this.style.backgroundColor='#fef3c7'">
                    🔄 Transfer
                </button>
            </div>
        </div>
        @empty
        <div class="col-span-full bg-white border border-gray-200 rounded-xl p-12 text-center">
            <div class="text-4xl mb-3">📦</div>
            <h3 class="text-lg font-semibold text-gray-700">No Khaas Products Found</h3>
            <p class="text-sm text-gray-500 mt-1">Products assigned to the {{ $khaasBU->name }} business unit will appear here.</p>
        </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if($products->hasPages())
    <div class="mt-6">
        {{ $products->appends(request()->query())->links() }}
    </div>
    @endif
</div>
@endsection

{{-- ⭐ MODALS: Pushed to @stack('modals') so they render at <body> level for proper fixed positioning --}}
@push('modals')
<!-- Stock In/Adjust Modal -->
<div id="stockModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999;" onclick="if(event.target===this)closeStockModal()">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl text-left overflow-hidden" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #fffbeb, #fff7ed);">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center">
                        <span class="text-xl">📥</span>
                    </div>
                    <div>
                        <h3 id="stock-modal-title" class="text-lg font-bold text-gray-900">Warehouse Stock Update</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Adjust inventory levels for this product</p>
                    </div>
                </div>
                <button onclick="closeStockModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <form method="POST" action="{{ route('khaas.warehouse.stock') }}">
            @csrf
            <input type="hidden" name="product_id" id="stock_product_id">
            <input type="hidden" name="product_variant_id" id="stock_variant_id">
            <input type="hidden" name="business_unit_id" value="{{ $khaasBU->id }}">
            <div class="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
                <!-- Product Info Card -->
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-lg font-bold text-amber-700" id="stock_product_initial">—</div>
                    <div class="flex-1">
                        <div id="stock_product_name" class="text-sm font-semibold text-gray-900"></div>
                        <div id="stock_current_qty" class="text-xs text-gray-500 mt-0.5"></div>
                    </div>
                </div>
                <!-- Action Type -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Action Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="relative flex items-center gap-2 p-2.5 border-2 rounded-xl cursor-pointer transition-all hover:border-amber-300" style="border-color: #f59e0b; background-color: #fffbeb;" id="stock_radio_stock_in">
                            <input type="radio" name="change_type" value="stock_in" class="sr-only" checked onchange="updateStockRadioStyles()">
                            <span class="text-lg">📥</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Stock In</div>
                                <div class="text-[10px] text-gray-500">Add inventory</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-amber-300" id="stock_radio_stock_out">
                            <input type="radio" name="change_type" value="stock_out" class="sr-only" onchange="updateStockRadioStyles()">
                            <span class="text-lg">📤</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Stock Out</div>
                                <div class="text-[10px] text-gray-500">Remove inventory</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-amber-300" id="stock_radio_count">
                            <input type="radio" name="change_type" value="count" class="sr-only" onchange="updateStockRadioStyles()">
                            <span class="text-lg">📊</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Count</div>
                                <div class="text-[10px] text-gray-500">Set exact qty</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-amber-300" id="stock_radio_adjustment">
                            <input type="radio" name="change_type" value="adjustment" class="sr-only" onchange="updateStockRadioStyles()">
                            <span class="text-lg">🔧</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Adjust</div>
                                <div class="text-[10px] text-gray-500">Correction</div>
                            </div>
                        </label>
                    </div>
                </div>
                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity</label>
                    <input type="number" name="quantity_change" min="0" required placeholder="Enter quantity..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-colors">
                </div>
                <!-- Notes -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Notes <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea name="notes" rows="2" placeholder="Reason for this update..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-colors resize-none"></textarea>
                </div>
            </div>
            <!-- Footer -->
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3">
                <button type="button" onclick="closeStockModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm transition-colors" style="background-color: #d97706;" onmouseover="this.style.backgroundColor='#b45309'" onmouseout="this.style.backgroundColor='#d97706'">
                    Update Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Store Stock Adjust Modal -->
<div id="storeStockModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999;" onclick="if(event.target===this)closeStoreStockModal()">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl text-left overflow-hidden" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #eff6ff, #e0f2fe);">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center">
                        <span class="text-xl">🏪</span>
                    </div>
                    <div>
                        <h3 id="store-stock-modal-title" class="text-lg font-bold text-gray-900">Store Stock Adjust</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Adjust store inventory levels for this product</p>
                    </div>
                </div>
                <button onclick="closeStoreStockModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <form method="POST" action="{{ route('khaas.store.stock') }}">
            @csrf
            <input type="hidden" name="product_id" id="store_stock_product_id">
            <input type="hidden" name="product_variant_id" id="store_stock_variant_id">
            <input type="hidden" name="business_unit_id" value="{{ $khaasBU->id }}">
            <div class="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
                <!-- Product Info Card -->
                <div class="flex items-center gap-3 p-3 bg-blue-50 rounded-xl border border-blue-100">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-lg font-bold text-blue-700" id="store_stock_product_initial">—</div>
                    <div class="flex-1">
                        <div id="store_stock_product_name" class="text-sm font-semibold text-gray-900"></div>
                        <div id="store_stock_current_qty" class="text-xs text-blue-600 mt-0.5"></div>
                    </div>
                </div>
                <!-- Action Type -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Action Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="relative flex items-center gap-2 p-2.5 border-2 rounded-xl cursor-pointer transition-all hover:border-blue-300" style="border-color: #3b82f6; background-color: #eff6ff;" id="store_stock_radio_store_stock_in">
                            <input type="radio" name="change_type" value="store_stock_in" class="sr-only" checked onchange="updateStoreStockRadioStyles()">
                            <span class="text-lg">📥</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Stock In</div>
                                <div class="text-[10px] text-gray-500">Add to store</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-blue-300" id="store_stock_radio_store_stock_out">
                            <input type="radio" name="change_type" value="store_stock_out" class="sr-only" onchange="updateStoreStockRadioStyles()">
                            <span class="text-lg">📤</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Stock Out</div>
                                <div class="text-[10px] text-gray-500">Remove from store</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-blue-300" id="store_stock_radio_store_count">
                            <input type="radio" name="change_type" value="store_count" class="sr-only" onchange="updateStoreStockRadioStyles()">
                            <span class="text-lg">📊</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Count</div>
                                <div class="text-[10px] text-gray-500">Set exact qty</div>
                            </div>
                        </label>
                        <label class="relative flex items-center gap-2 p-2.5 border-2 border-gray-200 rounded-xl cursor-pointer transition-all hover:border-blue-300" id="store_stock_radio_store_adjustment">
                            <input type="radio" name="change_type" value="store_adjustment" class="sr-only" onchange="updateStoreStockRadioStyles()">
                            <span class="text-lg">🔧</span>
                            <div>
                                <div class="text-sm font-medium text-gray-900">Adjust</div>
                                <div class="text-[10px] text-gray-500">Correction</div>
                            </div>
                        </label>
                    </div>
                </div>
                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity</label>
                    <input type="number" name="quantity_change" min="0" required placeholder="Enter quantity..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>
                <!-- Notes -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Notes <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea name="notes" rows="2" placeholder="Reason for this store adjustment..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none"></textarea>
                </div>
            </div>
            <!-- Footer -->
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3">
                <button type="button" onclick="closeStoreStockModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm transition-colors" style="background-color: #2563eb;" onmouseover="this.style.backgroundColor='#1d4ed8'" onmouseout="this.style.backgroundColor='#2563eb'">
                    Update Store Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Transfer Modal (for pending approvals) -->
<div id="productRejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999;" onclick="if(event.target===this)closeProductRejectModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden" onclick="event.stopPropagation()">
        <div class="px-6 py-5 border-b border-gray-100" style="background: linear-gradient(to right, #fef2f2, #fff7ed);">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center text-xl">❌</div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Reject Transfer</h3>
                    <p class="text-xs text-gray-500" id="productRejectModalInfo"></p>
                </div>
            </div>
        </div>
        <form id="productRejectForm" method="POST">
            @csrf
            <div class="px-6 py-5">
                <label class="block text-sm font-semibold text-gray-700 mb-2">Reason for Rejection</label>
                <textarea name="reason" rows="3" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500 resize-none" placeholder="Provide a reason..." required></textarea>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3">
                <button type="button" onclick="closeProductRejectModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm" style="background-color: #dc2626;" onmouseover="this.style.backgroundColor='#b91c1c'" onmouseout="this.style.backgroundColor='#dc2626'">Reject Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer to Store Modal -->
<div id="transferModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999;" onclick="if(event.target===this)closeTransferModal()">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl text-left overflow-hidden" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #eff6ff, #eef2ff);">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center">
                        <span class="text-xl">🔄</span>
                    </div>
                    <div>
                        <h3 id="transfer-modal-title" class="text-lg font-bold text-gray-900">Transfer to Store</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Move stock from warehouse → store</p>
                    </div>
                </div>
                <button onclick="closeTransferModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <form method="POST" action="{{ route('khaas.warehouse.transfer') }}">
            @csrf
            <input type="hidden" name="product_id" id="transfer_product_id">
            <input type="hidden" name="product_variant_id" id="transfer_variant_id">
            <input type="hidden" name="business_unit_id" value="{{ $khaasBU->id }}">
            <div class="px-6 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
                <!-- Product Info Card -->
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-lg font-bold text-blue-700" id="transfer_product_initial">—</div>
                    <div class="flex-1">
                        <div id="transfer_product_name" class="text-sm font-semibold text-gray-900"></div>
                        <div id="transfer_current_qty" class="text-xs text-gray-500 mt-0.5"></div>
                    </div>
                </div>
                <!-- Flow Diagram -->
                <div class="flex items-center justify-center gap-3 py-2">
                    <div class="flex flex-col items-center gap-1">
                        <div class="w-11 h-11 rounded-xl bg-amber-100 flex items-center justify-center text-lg">🏭</div>
                        <span class="text-[10px] font-medium text-gray-600">Warehouse</span>
                    </div>
                    <div class="flex flex-col items-center">
                        <svg class="w-7 h-7 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                        <span class="text-[10px] text-blue-500 font-medium">Transfer</span>
                    </div>
                    <div class="flex flex-col items-center gap-1">
                        <div class="w-11 h-11 rounded-xl bg-blue-100 flex items-center justify-center text-lg">🏪</div>
                        <span class="text-[10px] font-medium text-gray-600">Store</span>
                    </div>
                </div>
                <!-- Info Banner -->
                <div class="flex items-start gap-2 p-2.5 bg-blue-50 rounded-xl border border-blue-100">
                    <span class="text-sm mt-0.5">💡</span>
                    <p class="text-[11px] text-blue-700 leading-relaxed">
                        Stock will be <strong>deducted from warehouse immediately</strong>. A transfer request will be created pending admin approval. Once approved, stock is added to the store.
                    </p>
                </div>
                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity to Transfer</label>
                    <input type="number" name="quantity" id="transfer_qty" min="1" required placeholder="Enter quantity..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <p class="text-xs text-gray-400 mt-1" id="transfer_max_hint"></p>
                </div>
                <!-- Notes -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Notes <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea name="notes" rows="2" placeholder="Reason for transfer..."
                        class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none"></textarea>
                </div>
            </div>
            <!-- Footer -->
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3">
                <button type="button" onclick="closeTransferModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm transition-colors" style="background-color: #2563eb;" onmouseover="this.style.backgroundColor='#1d4ed8'" onmouseout="this.style.backgroundColor='#2563eb'">
                    🔄 Initiate Transfer
                </button>
            </div>
        </form>
    </div>
</div>
<!-- Store Inventory Transaction Log Modal -->
<div id="storeLogModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999;" onclick="if(event.target===this)closeStoreLogModal()">
    <div class="w-full max-w-xl bg-white rounded-2xl shadow-2xl text-left overflow-hidden" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #eff6ff, #e0f2fe);">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center">
                        <span class="text-xl">📋</span>
                    </div>
                    <div>
                        <h3 id="store-log-title" class="text-lg font-bold text-gray-900">Store Inventory Log</h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Current balance: <span id="store-log-balance" class="font-bold text-blue-700">—</span> units
                        </p>
                    </div>
                </div>
                <button onclick="closeStoreLogModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <!-- Body -->
        <div class="max-h-[70vh] overflow-y-auto" id="store-log-body">
            <div class="flex items-center justify-center py-12 text-gray-400">
                <svg class="animate-spin w-6 h-6 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Loading transactions...
            </div>
        </div>
        <!-- Footer -->
        <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
            <p class="text-[10px] text-gray-400">Showing recent transactions that affected store inventory</p>
            <button onclick="closeStoreLogModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Close</button>
        </div>
    </div>
</div>
@endpush

@push('demo1_js')
<script>
function openStockModal(productId, productName, variantId, currentQty) {
    document.getElementById('stock_product_id').value = productId;
    document.getElementById('stock_variant_id').value = variantId || '';
    document.getElementById('stock_product_name').textContent = productName;
    document.getElementById('stock_product_initial').textContent = productName.charAt(0).toUpperCase();
    document.getElementById('stock_current_qty').textContent = 'Current warehouse stock: ' + currentQty + ' units';
    // Reset radio to stock_in
    var stockInRadio = document.querySelector('input[name="change_type"][value="stock_in"]');
    if (stockInRadio) stockInRadio.checked = true;
    updateStockRadioStyles();
    var modal = document.getElementById('stockModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeStockModal() {
    var modal = document.getElementById('stockModal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}
function openTransferModal(productId, productName, variantId, currentQty) {
    document.getElementById('transfer_product_id').value = productId;
    document.getElementById('transfer_variant_id').value = variantId || '';
    document.getElementById('transfer_product_name').textContent = productName;
    document.getElementById('transfer_product_initial').textContent = productName.charAt(0).toUpperCase();
    document.getElementById('transfer_current_qty').textContent = 'Available warehouse stock: ' + currentQty + ' units';
    document.getElementById('transfer_qty').max = currentQty;
    document.getElementById('transfer_max_hint').textContent = 'Maximum: ' + currentQty + ' units available';
    var modal = document.getElementById('transferModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeTransferModal() {
    var modal = document.getElementById('transferModal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}
function updateStockRadioStyles() {
    var labels = ['stock_in', 'stock_out', 'count', 'adjustment'];
    labels.forEach(function(val) {
        var label = document.getElementById('stock_radio_' + val);
        var radio = label ? label.querySelector('input[type="radio"]') : null;
        if (label && radio) {
            if (radio.checked) {
                label.style.borderColor = '#f59e0b';
                label.style.backgroundColor = '#fffbeb';
            } else {
                label.style.borderColor = '#e5e7eb';
                label.style.backgroundColor = '';
            }
        }
    });
}

// === Store Stock Adjust Modal ===
function openStoreStockModal(productId, productName, variantId, currentQty) {
    document.getElementById('store_stock_product_id').value = productId;
    document.getElementById('store_stock_variant_id').value = variantId || '';
    document.getElementById('store_stock_product_name').textContent = productName;
    document.getElementById('store_stock_product_initial').textContent = productName.charAt(0).toUpperCase();
    document.getElementById('store_stock_current_qty').textContent = 'Current store stock: ' + currentQty + ' units';
    // Reset radio to store_stock_in
    var storeStockInRadio = document.querySelector('#storeStockModal input[name="change_type"][value="store_stock_in"]');
    if (storeStockInRadio) storeStockInRadio.checked = true;
    updateStoreStockRadioStyles();
    var modal = document.getElementById('storeStockModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeStoreStockModal() {
    var modal = document.getElementById('storeStockModal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}
function updateStoreStockRadioStyles() {
    var labels = ['store_stock_in', 'store_stock_out', 'store_count', 'store_adjustment'];
    labels.forEach(function(val) {
        var label = document.getElementById('store_stock_radio_' + val);
        var radio = label ? label.querySelector('input[type="radio"]') : null;
        if (label && radio) {
            if (radio.checked) {
                label.style.borderColor = '#3b82f6';
                label.style.backgroundColor = '#eff6ff';
            } else {
                label.style.borderColor = '#e5e7eb';
                label.style.backgroundColor = '';
            }
        }
    });
}

// === Reject Transfer Modal (for pending approvals on products page) ===
function openProductRejectModal(transferId, productName, quantity) {
    document.getElementById('productRejectModalInfo').textContent = quantity + ' units of "' + productName + '" — stock returns to warehouse';
    document.getElementById('productRejectForm').action = '{{ url("khaas/transfers") }}/' + transferId + '/reject';
    document.getElementById('productRejectModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeProductRejectModal() {
    document.getElementById('productRejectModal').classList.add('hidden');
    document.body.style.overflow = '';
}

// ═══════════════════════════════════════════════════════════════
// ⭐ Store Inventory Transaction Log
// ═══════════════════════════════════════════════════════════════
function openStoreLogModal(productId, productName, currentQty) {
    document.getElementById('store-log-title').textContent = productName + ' — Store Log';
    document.getElementById('store-log-balance').textContent = currentQty;
    document.getElementById('store-log-body').innerHTML =
        '<div class="flex items-center justify-center py-12 text-gray-400">' +
        '<svg class="animate-spin w-6 h-6 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>' +
        'Loading transactions...</div>';

    var modal = document.getElementById('storeLogModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    // Fetch transaction log
    fetch('{{ url("khaas/products") }}/' + productId + '/store-log?limit=30')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.events || data.events.length === 0) {
                document.getElementById('store-log-body').innerHTML =
                    '<div class="text-center py-12 text-gray-400">' +
                    '<div class="text-3xl mb-2">📭</div>' +
                    '<p class="text-sm">No store inventory transactions found yet.</p></div>';
                return;
            }
            renderStoreLog(data.days || [], data.current_store_qty);
        })
        .catch(function(err) {
            document.getElementById('store-log-body').innerHTML =
                '<div class="text-center py-12 text-red-400">' +
                '<div class="text-3xl mb-2">⚠️</div>' +
                '<p class="text-sm">Failed to load transactions.</p></div>';
            console.error('Store log error:', err);
        });
}

function closeStoreLogModal() {
    document.getElementById('storeLogModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function renderStoreLog(days, currentQty) {
    var html = '';

    html += '<div class="px-5 py-3 bg-blue-50 border-b border-blue-100 flex items-center justify-between">';
    html += '<span class="text-xs font-semibold text-blue-700">Current Store Balance</span>';
    html += '<span class="text-lg font-bold text-blue-800">' + currentQty + ' units</span>';
    html += '</div>';

    for (var d = 0; d < days.length; d++) {
        var day = days[d];
        var netSign = day.net_change > 0 ? '+' : '';
        var netColor = day.net_change > 0 ? '#16a34a' : day.net_change < 0 ? '#dc2626' : '#6b7280';

        // Day separator header
        html += '<div class="px-5 py-2 bg-gray-100 border-y border-gray-200 flex items-center justify-between">';
        html += '<span class="text-xs font-bold text-gray-700">📅 ' + escStoreLog(day.label) + '</span>';
        html += '<div class="flex items-center gap-3 text-[10px]">';
        html += '<span class="text-gray-500">Open: <b>' + day.opening_balance + '</b></span>';
        html += '<span class="text-gray-500">Close: <b>' + day.closing_balance + '</b></span>';
        html += '<span style="color:' + netColor + ';font-weight:700;">' + netSign + day.net_change + '</span>';
        html += '</div></div>';

        // Events for this day
        html += '<div class="divide-y divide-gray-50">';
        for (var i = 0; i < day.events.length; i++) {
            var ev = day.events[i];
            var isPositive = ev.change > 0;
            var changeColor, borderColor, iconBg;
            if (ev.type === 'transfer_in') {
                changeColor = 'color: #16a34a;';
                borderColor = 'border-left: 3px solid #22c55e;';
                iconBg = 'background-color: #dcfce7;';
            } else if (ev.type === 'order_deduction') {
                changeColor = 'color: #dc2626;';
                borderColor = 'border-left: 3px solid #ef4444;';
                iconBg = 'background-color: #fee2e2;';
            } else if (ev.type === 'cancellation_restore') {
                changeColor = 'color: #2563eb;';
                borderColor = 'border-left: 3px solid #3b82f6;';
                iconBg = 'background-color: #dbeafe;';
            } else if (ev.type === 'store_adjustment') {
                changeColor = isPositive ? 'color: #7c3aed;' : 'color: #ea580c;';
                borderColor = isPositive ? 'border-left: 3px solid #8b5cf6;' : 'border-left: 3px solid #f97316;';
                iconBg = isPositive ? 'background-color: #ede9fe;' : 'background-color: #fff7ed;';
            } else {
                changeColor = isPositive ? 'color: #16a34a;' : 'color: #dc2626;';
                borderColor = 'border-left: 3px solid #9ca3af;';
                iconBg = 'background-color: #f3f4f6;';
            }
            html += '<div class="px-5 py-2.5 hover:bg-gray-50 transition-colors" style="' + borderColor + '">';
            html += '<div class="flex items-start gap-3">';
            html += '<div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="' + iconBg + '">';
            html += '<span class="text-xs">' + ev.icon + '</span></div>';
            html += '<div class="flex-1 min-w-0">';
            html += '<div class="flex items-center justify-between">';
            html += '<span class="text-xs font-semibold text-gray-900">' + escStoreLog(ev.label) + '</span>';
            html += '<span class="text-xs font-bold" style="' + changeColor + '">' + (isPositive ? '+' : '') + ev.change + '</span>';
            html += '</div>';
            html += '<div class="text-[11px] text-gray-600 mt-0.5">' + escStoreLog(ev.detail) + '</div>';
            if (ev.sub_detail) {
                html += '<div class="text-[10px] text-gray-400 mt-0.5">' + escStoreLog(ev.sub_detail) + '</div>';
            }
            html += '<div class="flex items-center justify-between mt-1">';
            html += '<span class="text-[10px] text-gray-400">' + escStoreLog(ev.date_formatted) + '</span>';
            html += '<span class="text-[10px] font-medium text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded">' + ev.balance_before + ' → ' + ev.balance_after + '</span>';
            html += '</div></div></div></div>';
        }
        html += '</div>';
    }

    // Legend
    html += '<div class="px-5 py-3 bg-gray-50 border-t border-gray-100">';
    html += '<div class="flex flex-wrap items-center gap-3 text-[10px] text-gray-500">';
    html += '<span><span style="display:inline-block;width:8px;height:8px;background:#22c55e;border-radius:2px;margin-right:3px;"></span>Transfer In</span>';
    html += '<span><span style="display:inline-block;width:8px;height:8px;background:#ef4444;border-radius:2px;margin-right:3px;"></span>Order Deduction</span>';
    html += '<span><span style="display:inline-block;width:8px;height:8px;background:#3b82f6;border-radius:2px;margin-right:3px;"></span>Cancelled Restore</span>';
    html += '<span><span style="display:inline-block;width:8px;height:8px;background:#8b5cf6;border-radius:2px;margin-right:3px;"></span>Manual Adjust</span>';
    html += '</div></div>';

    document.getElementById('store-log-body').innerHTML = html;
}

function escStoreLog(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStockModal();
        closeStoreStockModal();
        closeTransferModal();
        closeProductRejectModal();
        closeStoreLogModal();
    }
});
</script>
@endpush
