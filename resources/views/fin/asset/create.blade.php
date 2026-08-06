@extends('layouts.app')

@section('title', 'Add New Asset')

@section('content')
<div class="max-w-3xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Add New Asset</h1>
            <p class="text-sm text-gray-600 mt-1">Record a new company asset purchase</p>
        </div>
        <a href="{{ route('fin.assets.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Assets
        </a>
    </div>

    <!-- Error Messages -->
    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    @if($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm font-semibold text-red-800 mb-2">Please fix the following errors:</p>
            <ul class="list-disc list-inside text-sm text-red-700">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Form -->
    <form action="{{ route('fin.assets.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
        @csrf

        <!-- Basic Information -->
        <div class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center gap-2">
                <span class="text-xl">📦</span> Basic Information
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Asset Name -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Asset Name <span class="text-red-500">*</span></label>
                    <input type="text" name="asset_name" value="{{ old('asset_name') }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                           placeholder="e.g., Samsung Chiller Unit 500L" required>
                </div>

                <!-- Business Unit -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Business Unit <span class="text-red-500">*</span></label>
                    <select name="business_unit_id" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" required>
                        <option value="">Select Business Unit</option>
                        @foreach($businessUnits as $unit)
                            <option value="{{ $unit->id }}" {{ old('business_unit_id', 1) == $unit->id ? 'selected' : '' }}>
                                {{ $unit->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Category -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Category <span class="text-red-500">*</span></label>
                    <div class="flex gap-2">
                        <select name="category_id" id="category_id" class="flex-1 text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" required>
                            <option value="">Select Category</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                        @if(auth()->user()->hasPermission('manage_asset_categories'))
                        <button type="button" onclick="openAddCategoryModal()" 
                                class="px-3 py-2 bg-emerald-100 hover:bg-emerald-200 text-emerald-700 text-sm font-medium rounded-md whitespace-nowrap"
                                title="Add New Category">
                            ➕ New
                        </button>
                        @endif
                    </div>
                </div>

                <!-- Description -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea name="description" rows="2" 
                              class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                              placeholder="Additional details about the asset">{{ old('description') }}</textarea>
                </div>
            </div>
        </div>

        <!-- Purchase Details -->
        <div class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center gap-2">
                <span class="text-xl">💰</span> Purchase Details
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Purchase Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Purchase Date <span class="text-red-500">*</span></label>
                    <input type="date" name="purchase_date" value="{{ old('purchase_date', date('Y-m-d')) }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                           required>
                </div>

                <!-- Purchase Amount -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Purchase Amount (Rs.) <span class="text-red-500">*</span></label>
                    <input type="number" name="purchase_amount" value="{{ old('purchase_amount') }}" 
                           step="0.01" min="0"
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                           placeholder="0.00" required>
                </div>

                <!-- Payment Account -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Payment From <span class="text-red-500">*</span></label>
                    <select name="payment_account_id" id="payment_account_id" onchange="updatePaymentMode()" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" required>
                        <option value="">Select Payment Account</option>
                        @foreach($paymentAccounts as $acc)
                            <option value="{{ $acc->id }}"
                                    data-mode="{{ ($acc->account_category ?? null) === 'bank' ? 'online' : 'cash' }}"
                                    {{ old('payment_account_id') == $acc->id ? 'selected' : '' }}>
                                {{ $acc->account_name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">💡 NF Cash = Cash payment, Online = Online transfer</p>
                    <input type="hidden" name="payment_mode" id="payment_mode" value="{{ old('payment_mode', 'cash') }}">
                </div>

                <!-- ⭐ Receiving bank — mandatory when paying online -->
                <div class="md:col-span-2" id="assetBankField" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">🏦 Paid from Bank <span class="text-red-500">*</span></label>
                    <div id="assetBankChips" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                    <input type="hidden" name="receiving_account_id" id="asset_receiving_account_id" value="{{ old('receiving_account_id') }}">
                    <p class="text-xs text-gray-500 mt-1">Which bank this online purchase leaves from — keeps per-bank balances correct.</p>
                </div>

                <!-- Purchased By -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Purchased By <span class="text-red-500">*</span></label>
                    <select name="purchased_by" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" required>
                        <option value="">Select Person</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('purchased_by', auth()->id()) == $user->id ? 'selected' : '' }}>
                                {{ $user->fullname }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Vendor (Optional) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Vendor (Optional)</label>
                    <select name="vendor_id" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500">
                        <option value="">No vendor / Direct purchase</option>
                        @foreach($vendors as $vendor)
                            <option value="{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>
                                {{ $vendor->vendor_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <!-- Physical Details (Collapsible) -->
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <button type="button" onclick="togglePhysicalDetails()" class="w-full p-4 text-left flex items-center justify-between hover:bg-gray-50 transition-colors">
                <h2 class="text-lg font-medium text-gray-900 flex items-center gap-2">
                    <span class="text-xl">🔧</span> Physical Details <span class="text-sm font-normal text-gray-500">(Optional)</span>
                </h2>
                <span id="physicalDetailsArrow" class="text-gray-400 text-xl transition-transform duration-200">▼</span>
            </button>
            
            <div id="physicalDetailsSection" class="hidden px-6 pb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Serial Number -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Serial Number</label>
                    <input type="text" name="serial_number" value="{{ old('serial_number') }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                           placeholder="e.g., ABC123XYZ">
                </div>

                <!-- Model Number -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Model Number</label>
                    <input type="text" name="model_number" value="{{ old('model_number') }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                           placeholder="e.g., SAMSUNG-RF-500">
                </div>

                <!-- Location -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Location</label>
                    <input type="text" name="location" value="{{ old('location') }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                           placeholder="e.g., Main Warehouse / Store A">
                </div>

                <!-- Condition -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Condition <span class="text-red-500">*</span></label>
                    <select name="condition" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" required>
                        <option value="new" {{ old('condition', 'new') == 'new' ? 'selected' : '' }}>New</option>
                        <option value="good" {{ old('condition') == 'good' ? 'selected' : '' }}>Good</option>
                        <option value="fair" {{ old('condition') == 'fair' ? 'selected' : '' }}>Fair</option>
                        <option value="poor" {{ old('condition') == 'poor' ? 'selected' : '' }}>Poor</option>
                    </select>
                </div>

                <!-- Notes -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                    <textarea name="notes" rows="2" 
                              class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                              placeholder="Any additional notes">{{ old('notes') }}</textarea>
                </div>

                <!-- Bill Image -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Bill/Invoice Image</label>
                    <input type="file" name="bill_image" accept="image/*"
                           class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                    <p class="text-xs text-gray-500 mt-1">Upload a photo of the bill/invoice (JPEG, PNG, max 5MB)</p>
                </div>
            </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div style="display: flex; gap: 12px; margin-top: 24px; padding: 16px; background: #F0FDF4; border-radius: 8px; border: 1px solid #BBF7D0;">
            <button type="submit" style="display: inline-flex; align-items: center; padding: 12px 24px; background: linear-gradient(135deg, #059669, #047857); color: white; font-size: 14px; font-weight: 600; border-radius: 8px; border: none; cursor: pointer; box-shadow: 0 4px 6px rgba(5, 150, 105, 0.3);">
                ✓ Create Asset
            </button>
            <a href="{{ route('fin.assets.index') }}" style="display: inline-flex; align-items: center; padding: 12px 24px; background: white; color: #374151; font-size: 14px; font-weight: 500; border-radius: 8px; border: 1px solid #D1D5DB; text-decoration: none;">
                Cancel
            </a>
        </div>
    </form>
</div>

@if(auth()->user()->hasPermission('manage_asset_categories'))
<!-- Add Category Modal -->
<div id="addCategoryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">➕ Add New Asset Category</h2>
                <button type="button" onclick="closeAddCategoryModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category Name <span class="text-red-500">*</span></label>
                    <input type="text" id="newCategoryName" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500"
                           placeholder="e.g., Machinery, Display Units">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Useful Life (Years)</label>
                    <input type="number" id="newCategoryUsefulLife" min="1" max="50" value="5"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-emerald-500"
                           placeholder="e.g., 5">
                    <p class="text-xs text-gray-500 mt-1">For depreciation calculation (optional)</p>
                </div>
                
                <div id="categoryFeedback" class="hidden p-3 rounded-md"></div>
                
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeAddCategoryModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="button" onclick="submitNewCategory()" id="submitCategoryBtn" class="flex-1 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md">
                        ✓ Create
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<script>
// Auto-set payment mode based on payment account
function updatePaymentMode() {
    const select = document.getElementById('payment_account_id');
    const modeInput = document.getElementById('payment_mode');
    const selectedOption = select.options[select.selectedIndex];

    if (selectedOption && selectedOption.value) {
        modeInput.value = selectedOption.dataset.mode || 'cash';
    }
    // ⭐ Show the mandatory bank picker only for online payments.
    renderAssetBankPicker();
}

// ⭐ Receiving-bank picker for ONLINE asset purchases (chips show balances).
const assetReceivingBanks = @json($receivingBanks ?? []);

function selectAssetBank(id) {
    document.getElementById('asset_receiving_account_id').value = id;
    renderAssetBankPicker();
}

function renderAssetBankPicker() {
    const field = document.getElementById('assetBankField');
    const chips = document.getElementById('assetBankChips');
    const hidden = document.getElementById('asset_receiving_account_id');
    if (!field || !chips || !hidden) return;

    const isOnline = document.getElementById('payment_mode').value === 'online';
    if (!isOnline || assetReceivingBanks.length === 0) {
        field.style.display = 'none';
        hidden.value = '';
        return;
    }
    field.style.display = 'block';
    const current = hidden.value;
    chips.innerHTML = assetReceivingBanks.map(b => {
        const active = String(current) === String(b.id);
        const color = b.color_hex || '#3B82F6';
        const bal = (b.balance !== undefined && b.balance !== null)
            ? ` · Rs ${Math.round(Number(b.balance)).toLocaleString()}`
            : '';
        return `<button type="button" onclick="selectAssetBank(${b.id})" style="padding:6px 14px; border-radius:16px; border:1px solid ${active ? color : '#CBD5E1'}; background:${active ? color : '#F1F5F9'}; color:${active ? '#fff' : '#475569'}; font-size:13px; font-weight:600; cursor:pointer;">${(b.short_code || b.name)}<span style="font-weight:500; opacity:0.85;">${bal}</span></button>`;
    }).join('');
}

// Toggle Physical Details section
function togglePhysicalDetails() {
    const section = document.getElementById('physicalDetailsSection');
    const arrow = document.getElementById('physicalDetailsArrow');
    
    if (section.classList.contains('hidden')) {
        section.classList.remove('hidden');
        arrow.style.transform = 'rotate(180deg)';
    } else {
        section.classList.add('hidden');
        arrow.style.transform = 'rotate(0deg)';
    }
}

// Category Modal Functions
function openAddCategoryModal() {
    document.getElementById('addCategoryModal').classList.remove('hidden');
    document.getElementById('addCategoryModal').style.display = 'flex';
    document.getElementById('newCategoryName').focus();
}

function closeAddCategoryModal() {
    document.getElementById('addCategoryModal').classList.add('hidden');
    document.getElementById('addCategoryModal').style.display = 'none';
    document.getElementById('newCategoryName').value = '';
    document.getElementById('newCategoryUsefulLife').value = '5';
    document.getElementById('categoryFeedback').classList.add('hidden');
}

function submitNewCategory() {
    const name = document.getElementById('newCategoryName').value.trim();
    const usefulLife = document.getElementById('newCategoryUsefulLife').value;
    const feedback = document.getElementById('categoryFeedback');
    const submitBtn = document.getElementById('submitCategoryBtn');
    
    if (!name) {
        feedback.className = 'p-3 rounded-md bg-red-50 border border-red-200';
        feedback.innerHTML = '<p class="text-sm text-red-700">❌ Category name is required</p>';
        feedback.classList.remove('hidden');
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ Creating...';
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                     document.querySelector('input[name="_token"]')?.value || '';
    
    fetch('{{ route("fin.asset-category.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({
            _token: csrfToken,
            category_name: name,
            useful_life_years: usefulLife || null
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Add new option to the dropdown
            const select = document.getElementById('category_id');
            const option = document.createElement('option');
            option.value = data.category.id;
            option.text = data.category.name;
            option.selected = true;
            select.add(option);
            
            feedback.className = 'p-3 rounded-md bg-green-50 border border-green-200';
            feedback.innerHTML = `<p class="text-sm text-green-700">✅ ${data.message}</p>`;
            feedback.classList.remove('hidden');
            
            // Close modal after 1 second
            setTimeout(() => {
                closeAddCategoryModal();
            }, 1000);
        } else {
            feedback.className = 'p-3 rounded-md bg-red-50 border border-red-200';
            feedback.innerHTML = `<p class="text-sm text-red-700">❌ ${data.message || 'Error creating category'}</p>`;
            feedback.classList.remove('hidden');
        }
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = '✓ Create';
    })
    .catch(error => {
        console.error('Error:', error);
        feedback.className = 'p-3 rounded-md bg-red-50 border border-red-200';
        feedback.innerHTML = '<p class="text-sm text-red-700">❌ An error occurred. Please try again.</p>';
        feedback.classList.remove('hidden');
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = '✓ Create';
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updatePaymentMode();

    // ⭐ Block submit when paying online without a bank picked (server also
    // enforces via required_if — this is just friendlier).
    const assetForm = document.querySelector('form[action*="assets"]');
    if (assetForm) {
        assetForm.addEventListener('submit', function(e) {
            const isOnline = document.getElementById('payment_mode').value === 'online';
            const bankVal = document.getElementById('asset_receiving_account_id')?.value;
            if (isOnline && !bankVal) {
                e.preventDefault();
                alert('Select which bank this online purchase is paid from.');
            }
        });
    }
});
</script>
@endsection
