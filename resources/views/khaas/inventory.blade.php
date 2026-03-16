@extends('layouts.app')

@section('title', '📋 Khaas Planning')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-gray-700 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">📋 {{ $khaasBU->name }} Planning</h1>
                <p class="text-sm text-gray-600 mt-0.5">Stock levels, production planning, recipes & configuration</p>
            </div>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
            🌿 {{ $khaasBU->name }}
        </span>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 border rounded-lg flex items-center gap-2" style="background-color: #f0fdf4; border-color: #bbf7d0;">
            <span>✅</span>
            <p class="text-sm" style="color: #166534;">{{ session('success') }}</p>
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 border rounded-lg flex items-center gap-2" style="background-color: #fef2f2; border-color: #fecaca;">
            <span>❌</span>
            <p class="text-sm" style="color: #991b1b;">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Tab Navigation -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="flex gap-1 -mb-px flex-wrap" aria-label="Tabs">
            @php
                $tabs = [
                    'stock' => ['icon' => '📦', 'label' => 'Current Stock', 'count' => $stockItems->count()],
                    'production' => ['icon' => '📋', 'label' => 'Production Plan', 'count' => null],
                    'recipes' => ['icon' => '🔗', 'label' => 'Recipes', 'count' => null],
                    'config' => ['icon' => '⚙️', 'label' => 'Configure Products', 'count' => null],
                    'history' => ['icon' => '📜', 'label' => 'History', 'count' => $demandHistory->count()],
                ];
            @endphp
            @foreach($tabs as $tabKey => $tabInfo)
            <a href="{{ route('khaas.inventory', ['tab' => $tabKey]) }}"
               class="inline-flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors"
               style="{{ $activeTab === $tabKey ? 'border-color: #D97706; color: #B45309;' : 'border-color: transparent; color: #6B7280;' }}">
                {{ $tabInfo['icon'] }} {{ $tabInfo['label'] }}
                @if($tabKey === 'production' && $pendingDemandCount > 0)
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs font-bold" style="background-color: #D97706; color: white;">{{ $pendingDemandCount }}</span>
                @elseif($tabInfo['count'] !== null)
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs" style="{{ $activeTab === $tabKey ? 'background-color: #FEF3C7; color: #B45309;' : 'background-color: #F3F4F6; color: #6B7280;' }}">{{ $tabInfo['count'] }}</span>
                @endif
            </a>
            @endforeach
        </nav>
    </div>

    {{-- ====================== CURRENT STOCK TAB ====================== --}}
    @if($activeTab === 'stock')
    <div>
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Available</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Processing</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Received</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($stockItems as $item)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold text-white" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                                        {{ strtoupper(substr($item->name, 0, 2)) }}
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-900 text-sm">{{ $item->name }}</div>
                                        @if($item->variant_title)
                                            <div class="text-xs text-gray-400">{{ $item->variant_title }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="text-sm font-bold" style="color: {{ $item->quantity > 0 ? '#059669' : '#9CA3AF' }};">
                                    {{ $item->quantity }} {{ $item->unit }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                @if($item->processing_qty > 0)
                                    <span class="text-sm font-medium" style="color: #D97706;">{{ $item->processing_qty }} {{ $item->unit }}</span>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="text-sm font-bold text-gray-900">{{ round($item->quantity + $item->processing_qty, 3) }} {{ $item->unit }}</span>
                            </td>
                            <td class="px-5 py-3.5 whitespace-nowrap">
                                @if($item->last_received)
                                    <span class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($item->last_received)->format('M d, Y') }}</span>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center">
                                <div class="text-3xl mb-2">📦</div>
                                <p class="text-sm text-gray-500">No storage products configured.</p>
                                <a href="{{ route('khaas.inventory', ['tab' => 'config']) }}" class="text-xs hover:underline mt-1 inline-block" style="color: #D97706;">Configure products →</a>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- ====================== PRODUCTION PLAN TAB ====================== --}}
    @if($activeTab === 'production')
    <div>
        <!-- Action Bar with New Plan Button -->
        <div class="bg-white border border-gray-200 rounded-xl p-4 mb-5 flex items-center justify-between">
            <div class="text-sm text-gray-600">
                <span class="font-medium text-gray-900">{{ $demands->count() }}</span> active production plan{{ $demands->count() !== 1 ? 's' : '' }}
            </div>
            <button onclick="document.getElementById('createDemandModal').classList.remove('hidden')"
                class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg shadow-sm transition-colors"
                style="background-color: #D97706; color: white;"
                onmouseover="this.style.backgroundColor='#B45309'"
                onmouseout="this.style.backgroundColor='#D97706'">
                + New Production Plan
            </button>
        </div>

        @if($demands->count() === 0)
        <div class="bg-white border border-gray-200 rounded-xl p-10 text-center">
            <div class="text-3xl mb-2">📋</div>
            <p class="text-sm text-gray-500">No active production demands.</p>
            <p class="text-xs text-gray-400 mt-1">Click "New Production Plan" above to create one.</p>
        </div>
        @else
        <div class="space-y-4">
            @foreach($demands as $demand)
            @php
                $statusStyles = [
                    'submitted' => ['bg' => '#FEF3C7', 'text' => '#B45309', 'icon' => '⏳'],
                    'accepted' => ['bg' => '#DBEAFE', 'text' => '#1D4ED8', 'icon' => '✅'],
                    'in_progress' => ['bg' => '#F3E8FF', 'text' => '#7E22CE', 'icon' => '🔥'],
                    'completed' => ['bg' => '#DCFCE7', 'text' => '#166534', 'icon' => '✅'],
                    'cancelled' => ['bg' => '#FEE2E2', 'text' => '#991B1B', 'icon' => '❌'],
                ];
                $dStyle = $statusStyles[$demand->status] ?? $statusStyles['submitted'];
                $totalKg = $demand->items->sum('quantity_kg');
            @endphp
            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-sm transition-shadow">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm" style="background-color: {{ $dStyle['bg'] }}; color: {{ $dStyle['text'] }};">
                            {{ $dStyle['icon'] }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="font-semibold text-gray-900 text-sm">Demand for {{ \Carbon\Carbon::parse($demand->demand_date)->format('M d, Y') }}</h4>
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $dStyle['bg'] }}; color: {{ $dStyle['text'] }};">
                                    {{ ucfirst(str_replace('_', ' ', $demand->status)) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-gray-500 mt-0.5">
                                <span>Created {{ \Carbon\Carbon::parse($demand->created_at)->format('M d, h:i A') }}</span>
                                @if($demand->created_by_name)
                                    <span>by {{ $demand->created_by_name }}</span>
                                @endif
                                <span class="font-medium text-gray-700">{{ round($totalKg, 2) }} kg total</span>
                            </div>
                        </div>
                    </div>
                    {{-- Action Buttons --}}
                    <div class="flex items-center gap-2">
                        @if($demand->status === 'submitted')
                        <form method="POST" action="{{ route('khaas.inventory.demand.accept', $demand->id) }}" onsubmit="return confirm('Accept this demand? This will deduct raw materials from storage and start production batches.');">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                                style="background-color: #2563EB; color: white;"
                                onmouseover="this.style.backgroundColor='#1D4ED8'"
                                onmouseout="this.style.backgroundColor='#2563EB'">
                                ✅ Accept & Start
                            </button>
                        </form>
                        <form method="POST" action="{{ route('khaas.inventory.demand.cancel', $demand->id) }}" onsubmit="return confirm('Cancel this demand?');">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                                style="background-color: #F3F4F6; color: #4B5563; border-color: #D1D5DB;"
                                onmouseover="this.style.backgroundColor='#FEF2F2'; this.style.color='#DC2626';"
                                onmouseout="this.style.backgroundColor='#F3F4F6'; this.style.color='#4B5563';">
                                ✕ Cancel
                            </button>
                        </form>
                        @endif
                    </div>
                </div>

                {{-- Items Table --}}
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                <th class="px-5 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty (kg)</th>
                                <th class="px-5 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($demand->items as $item)
                            @php
                                $itemStyles = [
                                    'pending' => ['bg' => '#F3F4F6', 'text' => '#4B5563'],
                                    'accepted' => ['bg' => '#DBEAFE', 'text' => '#1D4ED8'],
                                    'in_progress' => ['bg' => '#F3E8FF', 'text' => '#7E22CE'],
                                    'completed' => ['bg' => '#DCFCE7', 'text' => '#166534'],
                                    'cancelled' => ['bg' => '#FEE2E2', 'text' => '#991B1B'],
                                ];
                                $iStyle = $itemStyles[$item->status] ?? $itemStyles['pending'];
                            @endphp
                            <tr>
                                <td class="px-5 py-2.5 text-sm text-gray-900">{{ $item->product_name ?? 'Product #' . $item->khaas_product_id }}</td>
                                <td class="px-5 py-2.5 text-right text-sm font-medium text-gray-900">{{ round($item->quantity_kg, 2) }}</td>
                                <td class="px-5 py-2.5 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $iStyle['bg'] }}; color: {{ $iStyle['text'] }};">
                                        {{ ucfirst(str_replace('_', ' ', $item->status)) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($demand->notes)
                <div class="px-5 py-2 border-t border-gray-100 bg-gray-50">
                    <p class="text-xs text-gray-500">📝 {{ $demand->notes }}</p>
                </div>
                @endif
            </div>
            @endforeach
        </div>
        @endif

    </div>
    @endif

    {{-- ====================== RECIPES TAB ====================== --}}
    @if($activeTab === 'recipes')
    <div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Existing Recipes (grouped by Khaas product) --}}
            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                    <h3 class="font-semibold text-gray-900 text-sm">🔗 Product Recipe Mappings</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Each finished product can use multiple raw materials</p>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse($recipes as $group)
                    <div class="px-5 py-3">
                        <div class="text-xs font-bold mb-2" style="color: #B45309;">🍴 {{ $group['khaas_product_name'] }}</div>
                        @foreach($group['materials'] as $m)
                        <div class="flex items-center justify-between py-1.5 pl-4 border-b border-gray-50 last:border-0">
                            @if($m['is_custom'] ?? false)
                            <span class="text-sm text-gray-700">← 🧂 {{ $m['storage_product_name'] }} <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-600 font-medium ml-1">manual</span></span>
                            @else
                            <span class="text-sm text-gray-700">← 🥩 {{ $m['storage_product_name'] }}</span>
                            @endif
                            <form method="POST" action="{{ route('khaas.inventory.recipe.delete', $m['recipe_id']) }}" onsubmit="return confirm('Remove this mapping?');" class="inline">
                                @csrf
                                <button type="submit" class="text-sm px-2 py-0.5 rounded transition-colors" style="color: #F87171;" onmouseover="this.style.color='#DC2626'" onmouseout="this.style.color='#F87171'">
                                    ✕
                                </button>
                            </form>
                        </div>
                        @endforeach
                        @php
                            $invCount = collect($group['materials'])->where('is_custom', false)->count();
                        @endphp
                        @if($invCount > 1)
                        <div class="text-xs text-gray-400 mt-1 pl-4">{{ $invCount }} inventory materials — full qty deducted from each on production</div>
                        @endif
                    </div>
                    @empty
                    <div class="px-5 py-10 text-center">
                        <div class="text-2xl mb-1">🔗</div>
                        <p class="text-sm text-gray-400">No recipes configured yet</p>
                        <p class="text-xs text-gray-400 mt-0.5">Add mappings using the form on the right</p>
                    </div>
                    @endforelse
                </div>
            </div>

            {{-- Add New Recipe --}}
            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                    <h3 class="font-semibold text-gray-900 text-sm">➕ Add New Mapping</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Select a Khaas product, then tick the raw materials it needs</p>
                </div>
                <div class="px-5 py-4">
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Finished Product (Khaas)</label>
                        <select id="recipeKhaasProduct" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white" onchange="updateRecipeMaterialList()">
                            <option value="">Select a product...</option>
                            @foreach($khaasProducts as $kp)
                            <option value="{{ $kp->id }}">{{ $kp->title }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div id="recipeMaterialSection" class="mb-4 hidden">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Raw Materials (from storage)</label>
                        <p class="text-xs text-gray-400 mb-2">Tick all raw materials this product needs. Full qty deducted from each on production.</p>
                        <div class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-48 overflow-y-auto">
                            @foreach($storageProductsForRecipe as $sp)
                            <label class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors recipe-material-item" data-product-id="{{ $sp->product_id }}">
                                <input type="checkbox" class="recipe-material-checkbox rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="{{ $sp->product_id }}" style="accent-color: #D97706;">
                                <span class="text-sm text-gray-700">🥩 {{ $sp->name }}</span>
                                <span class="recipe-material-badge hidden ml-auto text-xs px-1.5 py-0.5 rounded-full" style="background-color: #DCFCE7; color: #166534;">already mapped</span>
                            </label>
                            @endforeach
                        </div>

                        {{-- Custom Materials --}}
                        <label class="block text-xs font-medium text-gray-600 mt-4 mb-1.5">🧂 Other Materials (not in inventory)</label>
                        <p class="text-xs text-gray-400 mb-2">Add materials like Aloo, Cheese etc. These won't be deducted from inventory.</p>
                        <div id="customMaterialsList" class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-32 overflow-y-auto mb-2">
                            @foreach($customMaterials as $cm)
                            <label class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-amber-50 transition-colors custom-material-item" data-cm-id="{{ $cm->id }}">
                                <input type="checkbox" class="custom-material-checkbox rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="{{ $cm->id }}" style="accent-color: #D97706;">
                                <span class="text-sm text-gray-700">🧂 {{ $cm->name }} <span class="text-[10px] text-gray-400">({{ $cm->unit }})</span></span>
                                <span class="custom-material-badge hidden ml-auto text-xs px-1.5 py-0.5 rounded-full" style="background-color: #DCFCE7; color: #166534;">mapped</span>
                            </label>
                            @endforeach
                        </div>
                        <div class="flex gap-2">
                            <input type="text" id="newCustomMaterialName" placeholder="New material name (e.g. Aloo)" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                            <select id="newCustomMaterialUnit" class="px-2 py-1.5 border border-gray-300 rounded-lg text-sm">
                                <option value="kg">kg</option>
                                <option value="pcs">pcs</option>
                                <option value="litre">litre</option>
                                <option value="grams">grams</option>
                                <option value="pack">pack</option>
                            </select>
                            <button type="button" onclick="addCustomMaterial()" class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors" style="background-color: #fffbeb; color: #B45309; border-color: #fde68a;">
                                + Add
                            </button>
                        </div>
                    </div>

                    <button type="button" id="saveRecipeMappingsBtn" onclick="saveRecipeMappings()" disabled
                        class="w-full px-4 py-2.5 text-sm font-medium rounded-lg shadow-sm transition-colors"
                        style="background-color: #D1D5DB; color: #6B7280; cursor: not-allowed;">
                        💾 Save Mapping
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ====================== CONFIGURE PRODUCTS TAB ====================== --}}
    @if($activeTab === 'config')
    <div>
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900 text-sm">⚙️ Configure Storage Products</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Select NF products to track in storage for meat delivery</p>
                </div>
                <div class="relative">
                    <input type="text" id="configSearch" placeholder="Search products..." onkeyup="filterConfigProducts()" class="w-60 px-3 py-1.5 border border-gray-300 rounded-lg text-sm pl-8">
                    <span class="absolute left-2.5 top-2 text-gray-400 text-sm">🔍</span>
                </div>
            </div>
            <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto" id="configProductList">
                @forelse($availableProducts as $product)
                @php
                    $isConfigured = in_array($product['id'], $configuredProductIds);
                @endphp
                <div class="config-product-item flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition-colors" data-name="{{ strtolower($product['title']) }}">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-900">{{ $product['title'] }}</div>
                        <div class="text-xs text-gray-400">{{ $product['vendor'] ?? '' }} {{ !empty($product['product_type']) ? '· ' . $product['product_type'] : '' }}</div>
                    </div>
                    <form method="POST" action="{{ route('khaas.inventory.storage-config') }}">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product['id'] }}">
                        <input type="hidden" name="display_name" value="{{ $product['title'] }}">
                        <input type="hidden" name="default_unit" value="kg">
                        @if($isConfigured)
                        <input type="hidden" name="action" value="remove">
                        <button type="submit" onclick="return confirm('Remove {{ addslashes($product['title']) }} from storage?')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                            style="background-color: #f0fdf4; color: #15803d; border-color: #bbf7d0;">
                            ✅ Configured
                        </button>
                        @else
                        <input type="hidden" name="action" value="add">
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                            style="background-color: #F9FAFB; color: #6B7280; border-color: #E5E7EB;">
                            ➕ Add
                        </button>
                        @endif
                    </form>
                </div>
                @empty
                <div class="px-5 py-10 text-center">
                    <div class="text-2xl mb-1">⚙️</div>
                    <p class="text-sm text-gray-400">No products available</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
    @endif

    {{-- ====================== HISTORY TAB ====================== --}}
    @if($activeTab === 'history')
    <div>
        @if($demandHistory->count() === 0)
        <div class="bg-white border border-gray-200 rounded-xl p-10 text-center">
            <div class="text-3xl mb-2">📜</div>
            <p class="text-sm text-gray-500">No completed or cancelled production plans yet.</p>
        </div>
        @else
        <div class="space-y-3">
            @foreach($demandHistory as $demand)
            @php
                $isCompleted = $demand->status === 'completed';
                $totalKg = $demand->items->sum('quantity_kg');
            @endphp
            <div class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-sm transition-shadow" style="{{ $isCompleted ? '' : 'opacity: 0.6;' }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3 flex-1">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center text-sm shrink-0" style="background-color: {{ $isCompleted ? '#DCFCE7' : '#FEE2E2' }}; color: {{ $isCompleted ? '#059669' : '#DC2626' }};">
                            {{ $isCompleted ? '✅' : '❌' }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h4 class="font-semibold text-gray-900 text-sm">Demand for {{ \Carbon\Carbon::parse($demand->demand_date)->format('M d, Y') }}</h4>
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $isCompleted ? '#DCFCE7' : '#FEE2E2' }}; color: {{ $isCompleted ? '#166534' : '#991B1B' }};">
                                    {{ ucfirst($demand->status) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 mt-1 text-xs text-gray-500">
                                <span>{{ \Carbon\Carbon::parse($demand->created_at)->format('M d, Y') }}</span>
                                @if($demand->created_by_name)
                                    <span>by {{ $demand->created_by_name }}</span>
                                @endif
                                <span class="font-medium text-gray-700">{{ round($totalKg, 2) }} kg</span>
                            </div>
                            <div class="mt-1.5 flex flex-wrap gap-1">
                                @foreach($demand->items as $item)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-600">
                                    {{ $item->product_name ?? 'Product' }} · {{ round($item->quantity_kg, 2) }}kg
                                </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif
</div>

{{-- ============ CREATE DEMAND MODAL (outside container for proper fixed positioning) ============ --}}
@if($activeTab === 'production')
<div id="createDemandModal" class="hidden" style="position:fixed;inset:0;z-index:9999;">
    <div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);" onclick="document.getElementById('createDemandModal').classList.add('hidden')"></div>
    <div style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:1rem;">
        <div class="bg-white rounded-xl shadow-2xl w-full flex flex-col" style="max-width:32rem;max-height:calc(100vh - 4rem);z-index:10;position:relative;">
            <div class="px-6 py-4 border-b border-gray-200 shrink-0">
                <h3 class="text-lg font-bold text-gray-900">📋 New Production Plan</h3>
                <p class="text-xs text-gray-500 mt-0.5">Enter weight (kg) for each product to produce</p>
            </div>
            <form method="POST" action="{{ route('khaas.inventory.demand.create') }}" onsubmit="return validateDemand()" class="flex flex-col min-h-0 flex-1">
                @csrf
                <div class="px-6 py-4 overflow-y-auto flex-1 min-h-0">
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Production Date</label>
                        <input type="date" name="demand_date" value="{{ now()->addDay()->toDateString() }}" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    @if($demandProducts->count() > 0)
                    <div class="space-y-3">
                        @foreach($demandProducts as $idx => $dp)
                        <div class="flex items-center gap-3 py-2 border-b border-gray-100">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold text-gray-900 truncate">{{ $dp['product_name'] }}</div>
                                <div class="mt-0.5">
                                    @foreach(($dp['raw_materials'] ?? []) as $rm)
                                    <div class="flex items-center gap-2">
                                        @if($rm['is_custom'] ?? false)
                                        <span class="text-xs text-amber-500">← 🧂 {{ $rm['raw_material_name'] }}</span>
                                        <span class="text-[10px] text-amber-400">(manual)</span>
                                        @else
                                        <span class="text-xs text-gray-400">← {{ $rm['raw_material_name'] }}</span>
                                        <span class="text-xs font-semibold" style="color: {{ ($rm['raw_material_available'] ?? 0) > 0 ? '#059669' : '#DC2626' }};">
                                            ({{ $rm['raw_material_available'] ?? 0 }}kg)
                                        </span>
                                        @endif
                                    </div>
                                    @endforeach
                                    @if(empty($dp['raw_materials']))
                                    <span class="text-xs text-gray-400 italic">No recipe</span>
                                    <a href="{{ route('khaas.inventory', ['tab' => 'recipes']) }}" class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold transition-colors" style="background-color: #FEF3C7; color: #B45309; border: 1px solid #FDE68A;" onmouseover="this.style.backgroundColor='#FDE68A'" onmouseout="this.style.backgroundColor='#FEF3C7'">+ Add Recipe</a>
                                    @endif
                                </div>
                            </div>
                            <input type="hidden" name="items[{{ $idx }}][khaas_product_id]" value="{{ $dp['khaas_product_id'] }}">
                            <input type="number" step="0.1" min="0" name="items[{{ $idx }}][quantity_kg]"
                                class="demand-qty w-20 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-center font-bold"
                                placeholder="0">
                            <span class="text-xs text-gray-400 w-5">kg</span>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="text-center py-8">
                        <p class="text-sm text-gray-400">No active products found for this business unit.</p>
                    </div>
                    @endif

                    <div class="mt-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notes (optional)</label>
                        <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none" placeholder="Any notes..."></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-end gap-3 rounded-b-xl shrink-0">
                    <button type="button" onclick="document.getElementById('createDemandModal').classList.add('hidden')" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 text-sm font-medium rounded-lg shadow-sm transition-colors"
                        style="background-color: #D97706; color: white;"
                        onmouseover="this.style.backgroundColor='#B45309'"
                        onmouseout="this.style.backgroundColor='#D97706'">
                        Submit Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection

@push('demo1_js')
<script>
function validateDemand() {
    var inputs = document.querySelectorAll('.demand-qty');
    var hasQty = false;
    inputs.forEach(function(input) { if (parseFloat(input.value) > 0) hasQty = true; });
    if (!hasQty) {
        alert('Enter weight for at least one product.');
        return false;
    }
    return confirm('Submit this production plan?');
}

function filterConfigProducts() {
    var search = document.getElementById('configSearch').value.toLowerCase();
    var items = document.querySelectorAll('.config-product-item');
    items.forEach(function(item) {
        var name = item.getAttribute('data-name');
        item.style.display = name.includes(search) ? '' : 'none';
    });
}

// Existing recipe data for highlighting already-mapped materials
var existingRecipes = @json($activeTab === 'recipes' ? $recipes : []);

function updateRecipeMaterialList() {
    var khaasId = document.getElementById('recipeKhaasProduct').value;
    var section = document.getElementById('recipeMaterialSection');
    var btn = document.getElementById('saveRecipeMappingsBtn');

    if (!khaasId) {
        section.classList.add('hidden');
        btn.disabled = true;
        btn.style.backgroundColor = '#D1D5DB';
        btn.style.color = '#6B7280';
        btn.style.cursor = 'not-allowed';
        return;
    }

    section.classList.remove('hidden');

    // Find existing mappings for this product
    var mappedStorage = [];
    var mappedCustom = [];
    existingRecipes.forEach(function(group) {
        if (String(group.khaas_product_id) === String(khaasId)) {
            (group.materials || []).forEach(function(m) {
                if (m.is_custom) {
                    mappedCustom.push(String(m.custom_material_id));
                } else {
                    mappedStorage.push(String(m.storage_product_id));
                }
            });
        }
    });

    // Update storage checkboxes and badges
    document.querySelectorAll('.recipe-material-item').forEach(function(item) {
        var productId = item.getAttribute('data-product-id');
        var checkbox = item.querySelector('.recipe-material-checkbox');
        var badge = item.querySelector('.recipe-material-badge');
        var isMapped = mappedStorage.includes(productId);

        checkbox.checked = isMapped;
        checkbox.dataset.wasMapped = isMapped ? '1' : '0';
        badge.classList.toggle('hidden', !isMapped);
    });

    // Update custom material checkboxes and badges
    document.querySelectorAll('.custom-material-item').forEach(function(item) {
        var cmId = item.getAttribute('data-cm-id');
        var checkbox = item.querySelector('.custom-material-checkbox');
        var badge = item.querySelector('.custom-material-badge');
        var isMapped = mappedCustom.includes(cmId);

        checkbox.checked = isMapped;
        checkbox.dataset.wasMapped = isMapped ? '1' : '0';
        badge.classList.toggle('hidden', !isMapped);
    });

    updateSaveBtn();
}

function updateSaveBtn() {
    var allCbs = document.querySelectorAll('.recipe-material-checkbox, .custom-material-checkbox');
    var hasNew = false;
    var hasAny = false;
    allCbs.forEach(function(cb) {
        if (cb.checked) hasAny = true;
        if (cb.checked && cb.dataset.wasMapped !== '1') hasNew = true;
    });

    var btn = document.getElementById('saveRecipeMappingsBtn');
    var khaasId = document.getElementById('recipeKhaasProduct').value;

    if (khaasId && (hasNew || hasAny)) {
        btn.disabled = false;
        btn.style.backgroundColor = '#D97706';
        btn.style.color = 'white';
        btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true;
        btn.style.backgroundColor = '#D1D5DB';
        btn.style.color = '#6B7280';
        btn.style.cursor = 'not-allowed';
    }
}

// Attach change listeners to checkboxes
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.recipe-material-checkbox, .custom-material-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateSaveBtn);
    });
});

function addCustomMaterial() {
    var nameEl = document.getElementById('newCustomMaterialName');
    var unitEl = document.getElementById('newCustomMaterialUnit');
    var name = nameEl.value.trim();
    var unit = unitEl.value;
    if (!name) { alert('Enter a material name.'); return; }

    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    fetch('{{ route("khaas.inventory.custom-material.save") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
        body: JSON.stringify({ business_unit_id: {{ $khaasBU->id }}, name: name, unit: unit })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.material) {
            var list = document.getElementById('customMaterialsList');
            var existing = list.querySelector('[data-cm-id="' + data.material.id + '"]');
            if (!existing) {
                var label = document.createElement('label');
                label.className = 'flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-amber-50 transition-colors custom-material-item';
                label.setAttribute('data-cm-id', data.material.id);
                label.innerHTML = '<input type="checkbox" class="custom-material-checkbox rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="' + data.material.id + '" style="accent-color: #D97706;" checked>'
                    + '<span class="text-sm text-gray-700">🧂 ' + data.material.name + ' <span class="text-[10px] text-gray-400">(' + data.material.unit + ')</span></span>'
                    + '<span class="custom-material-badge hidden ml-auto text-xs px-1.5 py-0.5 rounded-full" style="background-color: #DCFCE7; color: #166534;">mapped</span>';
                list.appendChild(label);
                label.querySelector('.custom-material-checkbox').addEventListener('change', updateSaveBtn);
            } else {
                existing.querySelector('.custom-material-checkbox').checked = true;
            }
            nameEl.value = '';
            updateSaveBtn();
        } else {
            alert(data.message || 'Failed to create material.');
        }
    })
    .catch(function(e) { alert('Error: ' + e.message); });
}

function saveRecipeMappings() {
    var khaasId = document.getElementById('recipeKhaasProduct').value;
    if (!khaasId) return;

    var selectedIds = [];
    document.querySelectorAll('.recipe-material-checkbox').forEach(function(cb) {
        if (cb.checked) selectedIds.push(cb.value);
    });

    var selectedCustomIds = [];
    document.querySelectorAll('.custom-material-checkbox').forEach(function(cb) {
        if (cb.checked) selectedCustomIds.push(cb.value);
    });

    if (selectedIds.length === 0 && selectedCustomIds.length === 0) {
        alert('Select at least one raw material.');
        return;
    }

    var btn = document.getElementById('saveRecipeMappingsBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    fetch('{{ route("khaas.inventory.recipe.save") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
        body: JSON.stringify({
            khaas_product_id: khaasId,
            storage_product_id: selectedIds,
            custom_material_ids: selectedCustomIds
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            window.location.href = '{{ route("khaas.inventory", ["tab" => "recipes"]) }}';
        } else {
            alert(data.message || 'Failed to save mappings.');
            btn.disabled = false;
            btn.textContent = '💾 Save Mapping';
        }
    })
    .catch(function(e) {
        alert('Error saving mappings: ' + e.message);
        btn.disabled = false;
        btn.textContent = '💾 Save Mapping';
    });
}
</script>
@endpush
