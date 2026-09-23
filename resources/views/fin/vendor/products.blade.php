@extends('layouts.app')

@section('title', e($vendor->vendor_name) . ' — Products')

@section('content')
@php
    /*
     * ⭐ Which ingredients this vendor ALREADY has a product for. A list of 13 where 11 are
     *   on the shelf hides the 2 that still need adding, so the not-yet-added ones go
     *   first and the rest sit under a heading.
     *
     * ⚠⚠ MARKED, NEVER REMOVED. A vendor legitimately stocks two products for one
     *    ingredient — oil in a 1-litre bottle and a 5-litre tin, cheese in a 400 g pack
     *    and a 1 kg block. Drop the name the moment the first one exists and whoever adds
     *    the second invents their own spelling, which is the exact drift this whole
     *    feature exists to prevent.
     */
    $ingAdded = [];
    foreach ($products as $vp) {
        $iid = (int) ($vp->ingredient_id ?? 0);
        if ($iid && !isset($ingAdded[$iid])) {
            $ingAdded[$iid] = trim((string) $vp->product_name);
        }
    }
    $ingFresh = array_values(array_filter($ingredients, fn ($i) => !isset($ingAdded[(int) $i['id']])));
    $ingOld   = array_values(array_filter($ingredients, fn ($i) =>  isset($ingAdded[(int) $i['id']])));
@endphp
<div class="max-w-7xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $vendor->vendor_name }} - Products</h1>
            <p class="text-sm text-gray-600 mt-1">Manage vendor-specific products for weighted purchases</p>
        </div>
        <a href="{{ route('fin.vendors.show', $vendor->id) }}" 
           class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Vendor
        </a>
    </div>

    <!-- Success/Error Messages -->
    <div id="messageContainer"></div>

    <!-- Add Product Form -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-lg font-medium text-gray-900 mb-4">➕ Add New Product</h2>
        <form id="addProductForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product Name <span class="text-red-500">*</span></label>
                {{-- ❄ list="ingNames": the Frozen ingredient names suggest themselves as you type,
                     so "salt" here is spelled the way the recipe spells it. Picking one also
                     fills the ingredient field below (see the JS). --}}
                <input type="text" id="product_name" required placeholder="e.g., Chicken Breast" list="ingNames" autocomplete="off"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p id="product_name_hint" class="text-xs mt-1 hidden" style="color:#4338CA;"></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unit <span class="text-red-500">*</span></label>
                <select id="unit" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Select Unit --</option>
                    <option value="kg">Kilogram (kg)</option>
                    <option value="liter">Liter</option>
                    <option value="piece">Piece</option>
                    <option value="dozen">Dozen</option>
                    <option value="pack">Pack</option>
                    <option value="box">Box</option>
                    <option value="ton">Ton</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rate per Unit (Rs.) <span class="text-red-500">*</span></label>
                <input type="number" id="rate_per_unit" step="0.01" min="0.01" required placeholder="0.00"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            @if(!empty($ingredients))
            <div>
                {{-- ❄ What this product IS, for Frozen. Optional; an untagged product behaves
                     exactly as it always has. Once tagged, every purchase of it counts
                     towards that ingredient's stock and its cost per pack. --}}
                <label class="block text-sm font-medium text-gray-700 mb-1">Frozen ingredient</label>
                <select id="ingredient_id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">— not an ingredient —</option>
                    {{-- ⭐ The ones this vendor has no product for come first. The rest are
                         grouped, not dropped — a second pack size needs the same name. --}}
                    @foreach($ingFresh as $ing)
                        <option value="{{ $ing['id'] }}" data-unit="{{ $ing['base_unit'] }}">{{ $ing['name'] }}</option>
                    @endforeach
                    @if(!empty($ingOld))
                    <optgroup label="Already on this vendor's list">
                        @foreach($ingOld as $ing)
                            <option value="{{ $ing['id'] }}" data-unit="{{ $ing['base_unit'] }}">{{ $ing['name'] }} — already “{{ $ingAdded[(int) $ing['id']] }}”</option>
                        @endforeach
                    </optgroup>
                    @endif
                </select>
            </div>
            <div id="pack_qty_wrap" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">How much in one <span id="pack_qty_unit">unit</span>?</label>
                <input type="number" id="pack_qty_base" step="0.001" min="0.001" placeholder="e.g. 400"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="text-xs text-gray-500 mt-1" id="pack_qty_help">A pack could be any size, so say how many <span class="pq-base">grams</span> one holds.</p>
            </div>
            @endif
            <div>
                {{-- Feeds the Category Report (Products → Sales vs Purchase). --}}
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select id="category_level_1"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">— untagged —</option>
                    @foreach(($categories ?? []) as $cat)
                        <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="inline-flex items-center cursor-pointer mb-2">
                    <input type="checkbox" id="is_default" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <span class="ml-2 text-sm font-medium text-gray-700">⭐ Set as Default</span>
                </label>
                <button type="submit" 
                        class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md"
                        style="background-color: #2563eb !important; color: white !important;">
                    <span style="color: white !important;">✓ Add Product</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Products List -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Product Catalog ({{ count($products) }} items)</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="productsTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                        @if(!empty($ingredients))
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Frozen ingredient</th>
                        @endif
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Rate per Unit</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Default</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="productsTableBody">
                    @forelse($products as $product)
                        <tr class="hover:bg-gray-50" data-product-id="{{ $product->id }}">
                            <td class="px-6 py-4 text-sm text-gray-900 font-medium">
                                {{ $product->product_name }}
                                @if($product->is_default)
                                    <span class="ml-2 text-yellow-500" title="Default Product">⭐</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm">
                                @if(trim((string) $product->category_level_1) !== '')
                                    <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#F3F4F6;color:#374151;font-size:11px;font-weight:600;">{{ $product->category_level_1 }}</span>
                                @else
                                    <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#FEF3C7;color:#92400E;font-size:11px;font-weight:600;">untagged</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $product->unit }}</td>
                            @if(!empty($ingredients))
                            <td class="px-6 py-4 text-sm">
                                @php $ingName = collect($ingredients)->firstWhere('id', (int) ($product->ingredient_id ?? 0))['name'] ?? null; @endphp
                                @if($ingName)
                                    <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#EEF2FF;color:#3730A3;font-size:11px;font-weight:600;" title="one {{ $product->unit }} = {{ rtrim(rtrim(number_format((float) $product->pack_qty_base, 3, '.', ','), '0'), '.') }} base units">{{ $ingName }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            @endif
                            <td class="px-6 py-4 text-sm text-gray-900 text-right font-semibold">Rs. {{ number_format($product->rate_per_unit, 2) }}</td>
                            <td class="px-6 py-4 text-center">
                                <button onclick="setAsDefault({{ $product->id }}, {{ $product->is_default ? 'true' : 'false' }})"
                                        class="px-3 py-1 text-xs rounded-md default-badge-{{ $product->id }}
                                            {{ $product->is_default ? 'bg-yellow-100 text-yellow-800 cursor-not-allowed' : 'bg-gray-100 text-gray-600 hover:bg-yellow-50' }}">
                                    {{ $product->is_default ? '⭐ Default' : 'Set Default' }}
                                </button>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full status-badge-{{ $product->id }}
                                    {{ $product->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $product->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex justify-center gap-2">
                                    <button onclick='editProduct({{ $product->id }}, {{ json_encode($product->product_name) }}, "{{ $product->unit }}", {{ $product->rate_per_unit }}, {{ $product->is_default ? 'true' : 'false' }}, {{ json_encode((string) $product->category_level_1) }}, {{ (int) ($product->ingredient_id ?? 0) }}, {{ (float) ($product->pack_qty_base ?? 0) }})'
                                            class="px-3 py-1 text-xs bg-blue-100 text-blue-700 rounded-md hover:bg-blue-200">
                                        ✏️ Edit
                                    </button>
                                    <button onclick="toggleStatus({{ $product->id }}, {{ $product->is_active ? 'true' : 'false' }})"
                                            class="px-3 py-1 text-xs bg-yellow-100 text-yellow-700 rounded-md hover:bg-yellow-200">
                                        {{ $product->is_active ? '🔒 Disable' : '✅ Enable' }}
                                    </button>
                                    <button onclick="deleteProduct({{ $product->id }})"
                                            class="px-3 py-1 text-xs bg-red-100 text-red-700 rounded-md hover:bg-red-200">
                                        🗑️ Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                                No products added yet. Add your first product above.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-800">✏️ Edit Product</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <form id="editProductForm">
                <input type="hidden" id="edit_product_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Product Name <span class="text-red-500">*</span></label>
                        <input type="text" id="edit_product_name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Unit <span class="text-red-500">*</span></label>
                        <select id="edit_unit" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="kg">Kilogram (kg)</option>
                            <option value="liter">Liter</option>
                            <option value="piece">Piece</option>
                            <option value="dozen">Dozen</option>
                            <option value="pack">Pack</option>
                            <option value="box">Box</option>
                            <option value="ton">Ton</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate per Unit (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" id="edit_rate_per_unit" step="0.01" min="0.01" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select id="edit_category_level_1"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">— untagged —</option>
                            @foreach(($categories ?? []) as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Groups this vendor's purchases in the Sales vs Purchase report.</p>
                    </div>
                    @if(!empty($ingredients))
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Frozen ingredient</label>
                        <select id="edit_ingredient_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">— not an ingredient —</option>
                            {{-- Same grouping as the add form. ⚠ The product being edited is
                                 itself "already added", so its own ingredient sits in the second
                                 group — which is correct, and it still selects normally. --}}
                            @foreach($ingFresh as $ing)
                                <option value="{{ $ing['id'] }}" data-unit="{{ $ing['base_unit'] }}">{{ $ing['name'] }}</option>
                            @endforeach
                            @if(!empty($ingOld))
                            <optgroup label="Already on this vendor's list">
                                @foreach($ingOld as $ing)
                                    <option value="{{ $ing['id'] }}" data-unit="{{ $ing['base_unit'] }}">{{ $ing['name'] }} — already “{{ $ingAdded[(int) $ing['id']] }}”</option>
                                @endforeach
                            </optgroup>
                            @endif
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Purchases of a tagged product count towards that ingredient's stock and cost per pack.</p>
                    </div>
                    <div id="edit_pack_qty_wrap" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">How much in one <span id="edit_pack_qty_unit">unit</span>?</label>
                        <input type="number" id="edit_pack_qty_base" step="0.001" min="0.001" placeholder="e.g. 400"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-500 mt-1">A pack could be any size, so say how many <span class="pq-base">grams</span> one holds.</p>
                    </div>
                    @endif
                    <div>
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="edit_is_default" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <span class="ml-2 text-sm font-medium text-gray-700">⭐ Set as Default</span>
                        </label>
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeEditModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md"
                                style="background-color: #2563eb !important; color: white !important;">
                            <span style="color: white !important;">✓ Update Product</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ❄ The Frozen ingredient names, offered as you type a product name. The point is
     ONE spelling: the recipe says "Cheese", so the vendor product is "Cheese" — and then
     the purchase and the recipe meet. --}}
@if(!empty($ingredients))
<datalist id="ingNames">
    {{-- Not-yet-added first; the browser keeps this order. An already-added name still
         appears, labelled, so a second pack size keeps the same spelling. --}}
    @foreach($ingFresh as $ing)
        <option value="{{ $ing['name'] }}"></option>
    @endforeach
    @foreach($ingOld as $ing)
        <option value="{{ $ing['name'] }}" label="already added"></option>
    @endforeach
</datalist>
@endif

<script>
const vendorId = {{ $vendor->id }};

// ❄ Frozen ingredients on this page (empty when the feature has not reached this DB).
const INGREDIENTS = @json($ingredients ?? []);

// Units whose size is obvious — the server works the pack size out from these itself.
const OBVIOUS_UNITS = {kg: 'g', gram: 'g', grams: 'g', g: 'g', ton: 'g', liter: 'ml', litre: 'ml', l: 'ml', ml: 'ml', piece: 'pcs', pcs: 'pcs', dozen: 'pcs'};

function ingredientById(id) {
    return INGREDIENTS.filter(function (i) { return String(i.id) === String(id); })[0] || null;
}

/**
 * Show the "how much in one pack?" box only when the unit does not answer it — a kg of
 * onions is obviously 1000 g, a "pack" of cheese is whatever size the pack is.
 */
function syncPackQty(selId, unitId, wrapId, unitLabelId) {
    var sel = document.getElementById(selId), wrap = document.getElementById(wrapId);
    if (!sel || !wrap) { return; }
    var ing  = ingredientById(sel.value);
    var unit = (document.getElementById(unitId).value || '').toLowerCase();
    var obvious = OBVIOUS_UNITS[unit];
    var needs = !!ing && (!obvious || obvious !== ing.base_unit);
    wrap.classList.toggle('hidden', !needs);
    if (needs) {
        document.getElementById(unitLabelId).textContent = unit || 'unit';
        var baseWord = ing.base_unit === 'pcs' ? 'pieces' : (ing.base_unit === 'ml' ? 'millilitres' : 'grams');
        wrap.querySelectorAll('.pq-base').forEach(function (el) { el.textContent = baseWord; });
    }
}

function ingredientPayload(selId, pqId) {
    var sel = document.getElementById(selId);
    if (!sel) { return {}; }                       // page has no Frozen field
    var out = {ingredient_id: sel.value ? Number(sel.value) : 0};
    var pq  = document.getElementById(pqId);
    if (pq && !pq.closest('.hidden') && pq.value) { out.pack_qty_base = Number(pq.value); }
    return out;
}

/**
 * ⭐ Typing a product name that IS an ingredient name selects that ingredient — the
 * whole point of the suggestion list. Case-insensitive, trimmed; a near miss is left
 * alone rather than guessed.
 */
function matchNameToIngredient(nameId, selId, hintId) {
    var name = (document.getElementById(nameId).value || '').trim().toLowerCase();
    var sel  = document.getElementById(selId);
    var hint = document.getElementById(hintId);
    if (!sel) { return; }
    var hit = INGREDIENTS.filter(function (i) { return i.name.toLowerCase() === name; })[0];
    if (hit) {
        sel.value = String(hit.id);
        if (hint) { hint.textContent = '✓ Matched the Frozen ingredient “' + hit.name + '” — purchases of this will count towards it.'; hint.classList.remove('hidden'); }
    } else if (hint) {
        hint.classList.add('hidden');
    }
    sel.dispatchEvent(new Event('change'));
}

(function () {
    var name = document.getElementById('product_name');
    if (name && document.getElementById('ingredient_id')) {
        name.addEventListener('input',  function () { matchNameToIngredient('product_name', 'ingredient_id', 'product_name_hint'); });
        name.addEventListener('change', function () { matchNameToIngredient('product_name', 'ingredient_id', 'product_name_hint'); });
        document.getElementById('ingredient_id').addEventListener('change', function () {
            // Picking an ingredient with the name still blank fills the name with the standard spelling.
            var ing = ingredientById(this.value);
            if (ing && !name.value.trim()) { name.value = ing.name; }
            syncPackQty('ingredient_id', 'unit', 'pack_qty_wrap', 'pack_qty_unit');
        });
        document.getElementById('unit').addEventListener('change', function () {
            syncPackQty('ingredient_id', 'unit', 'pack_qty_wrap', 'pack_qty_unit');
        });
    }
    var editName = document.getElementById('edit_product_name');
    if (editName && document.getElementById('edit_ingredient_id')) {
        editName.addEventListener('input', function () { matchNameToIngredient('edit_product_name', 'edit_ingredient_id', null); });
        document.getElementById('edit_ingredient_id').addEventListener('change', function () {
            syncPackQty('edit_ingredient_id', 'edit_unit', 'edit_pack_qty_wrap', 'edit_pack_qty_unit');
        });
        document.getElementById('edit_unit').addEventListener('change', function () {
            syncPackQty('edit_ingredient_id', 'edit_unit', 'edit_pack_qty_wrap', 'edit_pack_qty_unit');
        });
    }
})();

// Add Product
document.getElementById('addProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = {
        product_name: document.getElementById('product_name').value,
        category_level_1: document.getElementById('category_level_1').value,
        unit: document.getElementById('unit').value,
        rate_per_unit: document.getElementById('rate_per_unit').value,
        is_default: document.getElementById('is_default').checked ? 1 : 0,
        ...ingredientPayload('ingredient_id', 'pack_qty_base')
    };
    
    fetch(`/finance/vendors/${vendorId}/products`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            document.getElementById('addProductForm').reset();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error adding product');
        console.error('Error:', error);
    });
});

// Edit Product
function editProduct(id, name, unit, rate, isDefault, category, ingredientId, packQty) {
    document.getElementById('edit_product_id').value = id;
    document.getElementById('edit_product_name').value = name;
    document.getElementById('edit_unit').value = unit;
    document.getElementById('edit_rate_per_unit').value = rate;
    document.getElementById('edit_is_default').checked = isDefault;
    document.getElementById('edit_category_level_1').value = category || '';
    // ❄ the tag, when the field exists on this page
    var ingSel = document.getElementById('edit_ingredient_id');
    if (ingSel) {
        ingSel.value = ingredientId ? String(ingredientId) : '';
        var pq = document.getElementById('edit_pack_qty_base');
        if (pq) { pq.value = packQty ? packQty : ''; }
        syncPackQty('edit_ingredient_id', 'edit_unit', 'edit_pack_qty_wrap', 'edit_pack_qty_unit');
    }
    
    const modal = document.getElementById('editModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

document.getElementById('editProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const productId = document.getElementById('edit_product_id').value;
    const formData = {
        product_name: document.getElementById('edit_product_name').value,
        category_level_1: document.getElementById('edit_category_level_1').value,
        unit: document.getElementById('edit_unit').value,
        rate_per_unit: document.getElementById('edit_rate_per_unit').value,
        is_default: document.getElementById('edit_is_default').checked ? 1 : 0,
        ...ingredientPayload('edit_ingredient_id', 'edit_pack_qty_base')
    };
    
    fetch(`/finance/vendors/${vendorId}/products/${productId}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            closeEditModal();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error updating product');
        console.error('Error:', error);
    });
});

// Toggle Status
function toggleStatus(id, currentStatus) {
    if (!confirm(`Are you sure you want to ${currentStatus ? 'disable' : 'enable'} this product?`)) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/products/${id}/toggle`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error updating status');
        console.error('Error:', error);
    });
}

// Set as Default
function setAsDefault(productId, isCurrentlyDefault) {
    if (isCurrentlyDefault) {
        showMessage('info', 'This product is already set as default');
        return;
    }
    
    if (!confirm('Set this product as default? This will unset any other default product.')) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/products/${productId}/set-default`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', 'Default product updated!');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error setting default product');
        console.error('Error:', error);
    });
}

// Delete Product
function deleteProduct(id) {
    if (!confirm('Are you sure you want to delete this product? If it has purchase history, it will be deactivated instead.')) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/products/${id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error deleting product');
        console.error('Error:', error);
    });
}

// Show Message
function showMessage(type, message) {
    const container = document.getElementById('messageContainer');
    const bgColor = type === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800';
    
    container.innerHTML = `
        <div class="mb-4 p-4 ${bgColor} border rounded-md">
            <p class="text-sm">${message}</p>
        </div>
    `;
    
    setTimeout(() => {
        container.innerHTML = '';
    }, 5000);
}

// Close modal on outside click
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

@endsection

