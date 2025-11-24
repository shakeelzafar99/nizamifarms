@extends('layouts.app')

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-primary">
                Create Product
            </h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                <span class="badge badge-light badge-outline">
                    <i class="ki-filled ki-plus text-success"></i>
                    New Manual Product
                </span>
            </div>
        </div>
        
        <div class="flex items-center gap-2.5">
            <a href="{{ session('products_return_url', route('products.index')) }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-black-left"></i>
                Back to Products
            </a>
        </div>
    </div>
</div>

<div class="container-fixed">
    <!-- Error Alert Banner -->
    @if ($errors->any() || session('error'))
    <div style="background: #fef2f2; border: 2px solid #dc2626; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);">
        <div style="display: flex; align-items: flex-start; gap: 16px;">
            <div style="background: #dc2626; color: white; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0;">
                ❌
            </div>
            <div style="flex: 1;">
                <h4 style="color: #991b1b; font-size: 18px; font-weight: 700; margin: 0 0 12px 0;">Unable to Save Product</h4>
                <ul style="color: #991b1b; font-size: 14px; line-height: 1.6; margin: 0; padding-left: 20px;">
                    @if(session('error'))
                        <li style="margin-bottom: 6px;"><strong>{{ session('error') }}</strong></li>
                    @endif
                    @foreach ($errors->all() as $error)
                        <li style="margin-bottom: 6px;">{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            <button type="button" onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: #991b1b; font-size: 24px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.15s;">
                ✕
            </button>
        </div>
    </div>
    @endif
    
    <form action="{{ route('products.store') }}" method="POST" id="productForm">
        @csrf
        
        <!-- Main Product Information Card -->
        <div class="card mb-5">
            <div class="card-header">
                <h3 class="card-title text-lg font-semibold flex items-center gap-2">
                    📦 Product Information
                </h3>
            </div>
            
            <div class="card-body">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <!-- Product Title -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label required text-sm font-medium">Product Title</label>
                        <input type="text" name="title" class="form-control" 
                               value="{{ old('title') }}" required
                               placeholder="Enter product name" id="product_title_input">
                        @error('title')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Status -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label required text-sm font-medium">Status</label>
                        <select name="status" class="form-select" required>
                            <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>🟢 Active</option>
                            <option value="draft" {{ old('status') === 'draft' ? 'selected' : '' }}>🟡 Draft</option>
                            <option value="archived" {{ old('status') === 'archived' ? 'selected' : '' }}>🔴 Archived</option>
                        </select>
                        @error('status')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <!-- Description -->
                <div class="flex flex-col gap-2 mt-5">
                    <label class="form-label text-sm font-medium">Description</label>
                    <textarea name="description" class="form-control" rows="4" 
                              placeholder="Describe your product...">{{ old('description') }}</textarea>
                    @error('description')
                        <span class="form-hint text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Settings Checkboxes -->
                <div class="flex items-center gap-8 mt-5 p-4 bg-gray-50 rounded-lg">
                    <label class="checkbox flex items-center gap-2">
                        <input type="hidden" name="track_inventory" value="0">
                        <input type="checkbox" name="track_inventory" value="1" 
                               {{ old('track_inventory', true) ? 'checked' : '' }}>
                        <span class="checkbox-indicator"></span>
                        <span class="text-sm font-medium">📊 Track Inventory</span>
                    </label>
                    
                    <label class="checkbox flex items-center gap-2">
                        <input type="hidden" name="is_lean" value="0">
                        <input type="checkbox" name="is_lean" value="1" 
                               id="is_lean_checkbox"
                               {{ old('is_lean', false) ? 'checked' : '' }}>
                        <span class="checkbox-indicator"></span>
                        <span class="text-sm font-medium">🥩 Lean Product</span>
                    </label>
                    
                    <label class="checkbox flex items-center gap-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" 
                               {{ old('is_active', true) ? 'checked' : '' }}>
                        <span class="checkbox-indicator"></span>
                        <span class="text-sm font-medium">✅ Active Product</span>
                    </label>
                </div>
                
                <!-- Weight Factor Field -->
                <div class="flex flex-col gap-2 mt-5 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <label class="form-label text-sm font-medium flex items-center gap-2">
                        ⚖️ Weight Factor
                        <span class="text-xs text-gray-500 font-normal">(For invoice quantity adjustment)</span>
                    </label>
                    <input type="number" step="0.01" min="0.01" name="weight_factor" class="form-control" 
                           value="{{ old('weight_factor', '1.00') }}" 
                           style="max-width: 200px;"
                           placeholder="1.00">
                    <div class="text-xs text-gray-600 mt-1">
                        When editing invoices, entered quantities will be divided by this factor. Default is 1.00 (no change).
                        Example: If weight factor is 0.75 and you enter 2, actual quantity will be 2.67 (2 ÷ 0.75).
                    </div>
                    @error('weight_factor')
                        <span class="form-hint text-danger">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Categorization & Organization Card -->
        <div class="card mb-5">
            <div class="card-header">
                <h3 class="card-title text-lg font-semibold flex items-center gap-2">
                    🏷️ Categorization & Organization
                </h3>
                <div class="text-xs text-gray-500">Help customers find your product</div>
            </div>
            
            <div class="card-body">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <!-- Vendor -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">🏪 Vendor/Brand</label>
                        <select name="vendor_select" id="vendor_select" class="form-select" onchange="handleSelectChange('vendor')">
                            <option value="">-- Select Vendor --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor }}" {{ old('vendor') == $vendor ? 'selected' : '' }}>{{ $vendor }}</option>
                            @endforeach
                            <option value="__custom__">✏️ Add New Vendor...</option>
                        </select>
                        <input type="text" name="vendor" id="vendor_input" class="form-control mt-2" 
                               value="{{ old('vendor') }}" 
                               placeholder="Enter new vendor name"
                               style="display: none;">
                        @error('vendor')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Product Type / Category -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">📂 Product Type/Category</label>
                        <select name="product_type_select" id="product_type_select" class="form-select" onchange="handleSelectChange('product_type')">
                            <option value="">-- Select Category --</option>
                            @foreach($productTypes as $type)
                                <option value="{{ $type }}" {{ old('product_type') == $type ? 'selected' : '' }}>{{ $type }}</option>
                            @endforeach
                            <option value="__custom__">✏️ Add New Category...</option>
                        </select>
                        <input type="text" name="product_type" id="product_type_input" class="form-control mt-2" 
                               value="{{ old('product_type') }}" 
                               placeholder="Enter new category name"
                               style="display: none;">
                        @error('product_type')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                    
                    <!-- Attribute 1 (Category Level 1) -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">📌 {{ $attributeLabels['1'] ?? 'Category Level 1' }}</label>
                        <select name="attribute_1_select" id="attribute_1_select" class="form-select" onchange="handleSelectChange('attribute_1')">
                            <option value="">-- Select --</option>
                            @foreach($attribute1s as $attr)
                                <option value="{{ $attr }}" {{ old('attribute_1') == $attr ? 'selected' : '' }}>{{ $attr }}</option>
                            @endforeach
                            <option value="__custom__">✏️ Add New...</option>
                        </select>
                        <input type="text" name="attribute_1" id="attribute_1_input" class="form-control mt-2" 
                               value="{{ old('attribute_1') }}" 
                               placeholder="Enter new value"
                               style="display: none;">
                        @error('attribute_1')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                    
                    <!-- Attribute 2 (Category Level 2) -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">📌 {{ $attributeLabels['2'] ?? 'Category Level 2' }}</label>
                        <select name="attribute_2_select" id="attribute_2_select" class="form-select" onchange="handleSelectChange('attribute_2')">
                            <option value="">-- Select --</option>
                            @foreach($attribute2s as $attr)
                                <option value="{{ $attr }}" {{ old('attribute_2') == $attr ? 'selected' : '' }}>{{ $attr }}</option>
                            @endforeach
                            <option value="__custom__">✏️ Add New...</option>
                        </select>
                        <input type="text" name="attribute_2" id="attribute_2_input" class="form-control mt-2" 
                               value="{{ old('attribute_2') }}" 
                               placeholder="Enter new value"
                               style="display: none;">
                        @error('attribute_2')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                    
                    <!-- Attribute 3 (Category Level 3) -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">📌 {{ $attributeLabels['3'] ?? 'Category Level 3' }}</label>
                        <select name="attribute_3_select" id="attribute_3_select" class="form-select" onchange="handleSelectChange('attribute_3')">
                            <option value="">-- Select --</option>
                            @foreach($attribute3s as $attr)
                                <option value="{{ $attr }}" {{ old('attribute_3') == $attr ? 'selected' : '' }}>{{ $attr }}</option>
                            @endforeach
                            <option value="__custom__">✏️ Add New...</option>
                        </select>
                        <input type="text" name="attribute_3" id="attribute_3_input" class="form-control mt-2" 
                               value="{{ old('attribute_3') }}" 
                               placeholder="Enter new value"
                               style="display: none;">
                        @error('attribute_3')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- SEO & Advanced (Collapsible) -->
        <div class="card mb-5">
            <div class="card-header cursor-pointer" onclick="toggleSection('seoSection')">
                <h3 class="card-title text-lg font-semibold flex items-center gap-2">
                    <span id="seoSectionIcon">▶️</span>
                    🔍 SEO & Advanced Settings
                    <span class="text-xs text-gray-500 ml-2">(Optional - Click to expand)</span>
                </h3>
            </div>
            
            <div id="seoSection" class="card-body" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <!-- Tags -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">🏷️ Tags</label>
                        <input type="text" name="tags" class="form-control" 
                               value="{{ old('tags') }}" 
                               placeholder="organic, premium, bestseller (separate with commas)">
                        <div class="form-hint text-xs text-gray-500">💡 Use tags to help customers find your product</div>
                        @error('tags')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                    
                    <!-- SEO Title -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">🔍 SEO Title</label>
                        <input type="text" name="seo_title" class="form-control" 
                               value="{{ old('seo_title') }}" 
                               placeholder="Title for search engines">
                        @error('seo_title')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <!-- SEO Description -->
                <div class="flex flex-col gap-2 mt-5">
                    <label class="form-label text-sm font-medium">📝 SEO Description</label>
                    <textarea name="seo_description" class="form-control" rows="3" 
                              placeholder="Description for search engines">{{ old('seo_description') }}</textarea>
                    @error('seo_description')
                        <span class="form-hint text-danger">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Product Variants Card -->
        <div class="card mb-5">
            <div class="card-header">
                <h3 class="card-title text-lg font-semibold flex items-center gap-2">
                    💰 Product Variants (Price, SKU, Inventory)
                </h3>
                <button type="button" onclick="addVariant()" class="kt-btn kt-btn-sm kt-btn-primary">
                    <i class="ki-filled ki-plus"></i>
                    Add Variant
                </button>
            </div>
            
            <div class="card-body">
                <div id="variantsContainer">
                    <!-- Variant #1 (Default) -->
                    <div class="variant-row mb-4 p-4 border border-gray-200 rounded-lg bg-gray-50" data-variant-index="0">
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="text-md font-semibold">Variant #1</h4>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="flex flex-col gap-2">
                                <label class="form-label text-sm font-medium">Variant Title (optional - defaults to product title)</label>
                                <input type="text" name="variants[0][title]" class="form-control" 
                                       placeholder="Leave empty to use product title">
                            </div>
                            
                            <div class="flex flex-col gap-2">
                                <label class="form-label text-sm font-medium">SKU</label>
                                <input type="text" name="variants[0][sku]" class="form-control" 
                                       placeholder="e.g., PROD-001">
                            </div>
                            
                            <div class="flex flex-col gap-2">
                                <label class="form-label required text-sm font-medium">💰 Price (PKR)</label>
                                <input type="number" name="variants[0][price]" class="form-control" 
                                       step="0.01" min="0" required>
                            </div>
                            
                            <div class="flex flex-col gap-2">
                                <label class="form-label required text-sm font-medium">📦 Stock</label>
                                <input type="number" name="variants[0][inventory_quantity]" class="form-control" 
                                       min="0" required value="0">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex justify-end gap-3 pb-10">
            <a href="{{ session('products_return_url', route('products.index')) }}" class="kt-btn kt-btn-light">
                Cancel
            </a>
            <button type="submit" class="kt-btn kt-btn-primary">
                <i class="ki-filled ki-check"></i>
                Create Product
            </button>
        </div>
    </form>
</div>

<script>
let variantIndex = 1;

// Toggle collapsible sections
function toggleSection(sectionId) {
    const section = document.getElementById(sectionId);
    const icon = document.getElementById(sectionId + 'Icon');
    
    if (section.style.display === 'none') {
        section.style.display = 'block';
        icon.textContent = '🔽';
    } else {
        section.style.display = 'none';
        icon.textContent = '▶️';
    }
}

// Add new variant
function addVariant() {
    const container = document.getElementById('variantsContainer');
    const newVariant = document.createElement('div');
    newVariant.className = 'variant-row mb-4 p-4 border border-gray-200 rounded-lg bg-gray-50';
    newVariant.dataset.variantIndex = variantIndex;
    
    newVariant.innerHTML = `
        <div class="flex justify-between items-center mb-3">
            <h4 class="text-md font-semibold">Variant #${variantIndex + 1}</h4>
            <button type="button" onclick="removeVariant(this)" class="kt-btn kt-btn-sm kt-btn-light">
                <i class="ki-filled ki-trash"></i>
                Remove
            </button>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="flex flex-col gap-2">
                <label class="form-label text-sm font-medium">Variant Title (optional)</label>
                <input type="text" name="variants[${variantIndex}][title]" class="form-control" 
                       placeholder="Leave empty to use product title">
            </div>
            
            <div class="flex flex-col gap-2">
                <label class="form-label text-sm font-medium">SKU</label>
                <input type="text" name="variants[${variantIndex}][sku]" class="form-control" 
                       placeholder="e.g., PROD-00${variantIndex + 1}">
            </div>
            
            <div class="flex flex-col gap-2">
                <label class="form-label required text-sm font-medium">💰 Price (PKR)</label>
                <input type="number" name="variants[${variantIndex}][price]" class="form-control" 
                       step="0.01" min="0" required>
            </div>
            
            <div class="flex flex-col gap-2">
                <label class="form-label required text-sm font-medium">📦 Stock</label>
                <input type="number" name="variants[${variantIndex}][inventory_quantity]" class="form-control" 
                       min="0" required value="0">
            </div>
        </div>
    `;
    
    container.appendChild(newVariant);
    variantIndex++;
}

// Remove variant
function removeVariant(button) {
    const variantRow = button.closest('.variant-row');
    variantRow.remove();
}

// Handle dropdown selection change
function handleSelectChange(fieldName) {
    const selectElement = document.getElementById(fieldName + '_select');
    const inputElement = document.getElementById(fieldName + '_input');
    
    if (selectElement.value === '__custom__') {
        // Show input field, hide select
        selectElement.style.display = 'none';
        inputElement.style.display = 'block';
        inputElement.focus();
        
        // Add a button to go back to select
        if (!inputElement.nextElementSibling || !inputElement.nextElementSibling.classList.contains('back-to-select-btn')) {
            const backBtn = document.createElement('button');
            backBtn.type = 'button';
            backBtn.className = 'back-to-select-btn kt-btn kt-btn-sm kt-btn-light mt-2';
            backBtn.textContent = '← Back to List';
            backBtn.onclick = function() {
                selectElement.style.display = 'block';
                inputElement.style.display = 'none';
                inputElement.value = '';
                selectElement.value = '';
                backBtn.remove();
            };
            inputElement.parentNode.insertBefore(backBtn, inputElement.nextSibling);
        }
    } else if (selectElement.value !== '') {
        // Copy selected value to the actual input field
        inputElement.value = selectElement.value;
    } else {
        // Clear the input if nothing selected
        inputElement.value = '';
    }
}

// Auto-fill is_lean checkbox based on product title
document.addEventListener('DOMContentLoaded', function() {
    const titleInput = document.getElementById('product_title_input');
    const isLeanCheckbox = document.getElementById('is_lean_checkbox');
    
    if (titleInput && isLeanCheckbox) {
        titleInput.addEventListener('input', function() {
            const title = this.value.toLowerCase();
            // Auto-check if title contains "lean"
            if (title.includes('lean')) {
                isLeanCheckbox.checked = true;
            } else {
                isLeanCheckbox.checked = false;
            }
        });
    }
    
    // Initialize: If there's an old value that doesn't match any option, show custom input
    ['vendor', 'product_type', 'attribute_1', 'attribute_2', 'attribute_3'].forEach(fieldName => {
        const selectElement = document.getElementById(fieldName + '_select');
        const inputElement = document.getElementById(fieldName + '_input');
        
        if (inputElement && inputElement.value && selectElement) {
            const optionExists = Array.from(selectElement.options).some(option => option.value === inputElement.value);
            if (!optionExists && inputElement.value !== '') {
                // Value exists but not in dropdown, show custom input
                selectElement.value = '__custom__';
                selectElement.style.display = 'none';
                inputElement.style.display = 'block';
            }
        }
    });
    
    // SKU Validation - Add event listeners to all SKU fields
    setupSkuValidation();
});

// SKU Validation Functions
let skuCheckTimers = {};

function setupSkuValidation() {
    // Find all SKU input fields
    document.querySelectorAll('input[name*="[sku]"]').forEach(input => {
        addSkuValidation(input);
    });
}

function addSkuValidation(skuInput) {
    if (!skuInput || skuInput.dataset.skuValidationAdded) return;
    skuInput.dataset.skuValidationAdded = 'true';
    
    // Create indicator container
    const wrapper = skuInput.parentElement;
    wrapper.style.position = 'relative';
    
    const indicator = document.createElement('div');
    indicator.className = 'sku-indicator';
    indicator.style.cssText = 'position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 20px; display: none; pointer-events: none;';
    wrapper.style.position = 'relative';
    wrapper.appendChild(indicator);
    
    skuInput.addEventListener('input', function() {
        const sku = this.value.trim();
        const inputId = this.name;
        
        // Clear previous timer
        if (skuCheckTimers[inputId]) {
            clearTimeout(skuCheckTimers[inputId]);
        }
        
        // Hide indicator while typing
        indicator.style.display = 'none';
        skuInput.style.borderColor = '';
        skuInput.style.paddingRight = '12px';
        
        if (sku.length === 0) {
            return;
        }
        
        // Set new timer (debounce)
        skuCheckTimers[inputId] = setTimeout(() => {
            checkSkuAvailability(sku, skuInput, indicator);
        }, 600); // Wait 600ms after user stops typing
    });
}

async function checkSkuAvailability(sku, inputElement, indicatorElement) {
    try {
        const response = await fetch('{{ route('products.check_sku') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ sku: sku })
        });
        
        const data = await response.json();
        
        if (data.exists) {
            // SKU already exists - show error
            indicatorElement.innerHTML = '❌';
            indicatorElement.style.display = 'block';
            indicatorElement.title = data.message;
            inputElement.style.borderColor = '#dc2626';
            inputElement.style.borderWidth = '2px';
            inputElement.style.paddingRight = '40px';
        } else {
            // SKU is available - show success
            indicatorElement.innerHTML = '✅';
            indicatorElement.style.display = 'block';
            indicatorElement.title = data.message;
            inputElement.style.borderColor = '#10b981';
            inputElement.style.borderWidth = '2px';
            inputElement.style.paddingRight = '40px';
        }
    } catch (error) {
        console.error('SKU check failed:', error);
    }
}

// Override addVariant function to include SKU validation for new variants
const originalAddVariant = window.addVariant;
window.addVariant = function() {
    if (originalAddVariant) {
        originalAddVariant();
    }
    // Small delay to ensure DOM is updated
    setTimeout(() => {
        setupSkuValidation();
    }, 100);
};
</script>
@endsection
