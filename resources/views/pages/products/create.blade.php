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
            <a href="{{ route('products.index') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-black-left"></i>
                Back to Products
            </a>
        </div>
    </div>
</div>

<div class="container-fixed">
    <form action="{{ route('products.store') }}" method="POST">
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
                               placeholder="Enter product name">
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
                        <input type="checkbox" name="track_inventory" value="1" 
                               {{ old('track_inventory', true) ? 'checked' : '' }}>
                        <span class="checkbox-indicator"></span>
                        <span class="text-sm font-medium">📊 Track Inventory</span>
                    </label>
                    
                    <label class="checkbox flex items-center gap-2">
                        <input type="checkbox" name="is_active" value="1" 
                               {{ old('is_active', true) ? 'checked' : '' }}>
                        <span class="checkbox-indicator"></span>
                        <span class="text-sm font-medium">✅ Active Product</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Additional Information - Collapsible -->
        <div class="card mb-5">
            <div class="card-header cursor-pointer" onclick="toggleSection('additionalInfo')">
                <h3 class="card-title text-lg font-semibold flex items-center gap-2">
                    <span id="additionalInfoIcon">▶️</span>
                    ⚙️ Additional Information
                    <span class="text-xs text-gray-500 ml-2">(Optional - Click to expand)</span>
                </h3>
            </div>
            
            <div id="additionalInfo" class="card-body" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <!-- Vendor -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">🏪 Vendor/Brand</label>
                        <input type="text" name="vendor" class="form-control" 
                               value="{{ old('vendor') }}" 
                               placeholder="e.g., Nike, Apple, Local Supplier">
                        @error('vendor')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Product Type -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">📂 Product Category</label>
                        <input type="text" name="product_type" class="form-control" 
                               value="{{ old('product_type') }}" 
                               placeholder="e.g., Electronics, Clothing, Food">
                        @error('product_type')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <!-- Tags -->
                <div class="flex flex-col gap-2 mt-5">
                    <label class="form-label text-sm font-medium">🏷️ Tags</label>
                    <input type="text" name="tags" class="form-control" 
                           value="{{ old('tags') }}" 
                           placeholder="organic, premium, bestseller (separate with commas)">
                    <div class="form-hint text-xs text-gray-500">💡 Use tags to help customers find your product</div>
                    @error('tags')
                        <span class="form-hint text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
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

                    <!-- SEO Description -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">📝 SEO Description</label>
                        <textarea name="seo_description" class="form-control" rows="3" 
                                  placeholder="Brief description for search results...">{{ old('seo_description') }}</textarea>
                        @error('seo_description')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Variants -->
        <div class="card mb-5">
            <div class="card-header">
                <h3 class="card-title text-lg font-semibold flex items-center gap-2">
                    📋 Product Variants
                    <span class="text-xs text-gray-500 ml-2">(Price, SKU, Inventory)</span>
                </h3>
                <button type="button" onclick="addVariant()" class="kt-btn kt-btn-sm kt-btn-primary">
                    <i class="ki-filled ki-plus"></i>
                    Add Variant
                </button>
            </div>
            
            <div class="card-body">
                <div id="variantsContainer">
                    <div class="variant-row p-4 border border-gray-200 rounded-lg mb-4" data-index="0">
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="text-sm font-semibold text-gray-700">Variant #1</h4>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="flex flex-col gap-2">
                                <label class="form-label text-sm font-medium">
                                    Variant Title
                                    <span class="text-xs text-gray-500">(optional - defaults to product title)</span>
                                </label>
                                <input type="text" name="variants[0][title]" class="form-control" 
                                       value="{{ old('variants.0.title') }}"
                                       placeholder="Leave empty to use product title">
                                @error('variants.0.title')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>
                            
                            <div class="flex flex-col gap-2">
                                <label class="form-label text-sm font-medium">SKU</label>
                                <input type="text" name="variants[0][sku]" class="form-control" 
                                       value="{{ old('variants.0.sku') }}"
                                       placeholder="e.g., PROD-001">
                                @error('variants.0.sku')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>
                            
                            <div class="flex flex-col gap-2">
                                <label class="form-label required text-sm font-medium">💰 Price (PKR)</label>
                                <input type="number" name="variants[0][price]" class="form-control" 
                                       value="{{ old('variants.0.price', 0) }}" 
                                       step="0.01" min="0" required>
                                @error('variants.0.price')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>
                            
                            <div class="flex flex-col gap-2">
                                <label class="form-label required text-sm font-medium">📦 Stock</label>
                                <input type="number" name="variants[0][inventory_quantity]" class="form-control" 
                                       value="{{ old('variants.0.inventory_quantity', 0) }}" 
                                       min="0" required>
                                @error('variants.0.inventory_quantity')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                        
                        <!-- Advanced variant options (collapsible) -->
                        <div class="mt-3">
                            <button type="button" onclick="toggleVariantAdvanced(0)" 
                                    class="text-xs text-blue-600 hover:text-blue-800">
                                <span id="variantAdvancedIcon0">▶️</span> Advanced Options
                            </button>
                            
                            <div id="variantAdvanced0" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-4" style="display: none;">
                                <div class="flex flex-col gap-2">
                                    <label class="form-label text-sm font-medium">Compare Price</label>
                                    <input type="number" name="variants[0][compare_at_price]" class="form-control" 
                                           value="{{ old('variants.0.compare_at_price') }}" 
                                           step="0.01" min="0" placeholder="Original price">
                                </div>
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label text-sm font-medium">Cost Price</label>
                                    <input type="number" name="variants[0][cost_price]" class="form-control" 
                                           value="{{ old('variants.0.cost_price') }}" 
                                           step="0.01" min="0" placeholder="Your cost">
                                </div>
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label text-sm font-medium">Barcode</label>
                                    <input type="text" name="variants[0][barcode]" class="form-control" 
                                           value="{{ old('variants.0.barcode') }}"
                                           placeholder="Product barcode">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="card">
            <div class="card-body">
                <div class="flex justify-between items-center">
                    <div class="text-sm text-gray-600">
                        💡 <strong>Tip:</strong> Use the "Additional Information" section for SEO and categorization.
                    </div>
                    
                    <div class="flex gap-3">
                        <a href="{{ route('products.index') }}" class="kt-btn kt-btn-light">
                            Cancel
                        </a>
                        <button type="submit" class="kt-btn kt-btn-primary">
                            <i class="ki-filled ki-plus"></i>
                            Create Product
                        </button>
                    </div>
                </div>
            </div>
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

// Toggle variant advanced options
function toggleVariantAdvanced(index) {
    const section = document.getElementById('variantAdvanced' + index);
    const icon = document.getElementById('variantAdvancedIcon' + index);
    
    if (section.style.display === 'none') {
        section.style.display = 'grid';
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
    newVariant.className = 'variant-row p-4 border border-gray-200 rounded-lg mb-4';
    newVariant.setAttribute('data-index', variantIndex);
    
    newVariant.innerHTML = `
        <div class="flex justify-between items-center mb-3">
            <h4 class="text-sm font-semibold text-gray-700">Variant #${variantIndex + 1}</h4>
            <button type="button" onclick="removeVariant(this)" 
                    class="kt-btn kt-btn-sm kt-btn-light text-red-600 hover:bg-red-50">
                <i class="ki-filled ki-trash"></i>
            </button>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="flex flex-col gap-2">
                <label class="form-label required text-sm font-medium">Variant Title</label>
                <input type="text" name="variants[${variantIndex}][title]" class="form-control" 
                       placeholder="e.g., Medium Blue" required>
            </div>
            
            <div class="flex flex-col gap-2">
                <label class="form-label text-sm font-medium">SKU</label>
                <input type="text" name="variants[${variantIndex}][sku]" class="form-control" 
                       placeholder="e.g., PROD-MD-BLUE">
            </div>
            
            <div class="flex flex-col gap-2">
                <label class="form-label required text-sm font-medium">💰 Price (PKR)</label>
                <input type="number" name="variants[${variantIndex}][price]" class="form-control" 
                       step="0.01" min="0" required>
            </div>
            
            <div class="flex flex-col gap-2">
                <label class="form-label required text-sm font-medium">📦 Stock</label>
                <input type="number" name="variants[${variantIndex}][inventory_quantity]" class="form-control" 
                       min="0" required>
            </div>
        </div>
        
        <div class="mt-3">
            <button type="button" onclick="toggleVariantAdvanced(${variantIndex})" 
                    class="text-xs text-blue-600 hover:text-blue-800">
                <span id="variantAdvancedIcon${variantIndex}">▶️</span> Advanced Options
            </button>
            
            <div id="variantAdvanced${variantIndex}" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-4" style="display: none;">
                <div class="flex flex-col gap-2">
                    <label class="form-label text-sm font-medium">Compare Price</label>
                    <input type="number" name="variants[${variantIndex}][compare_at_price]" class="form-control" 
                           step="0.01" min="0" placeholder="Original price">
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label text-sm font-medium">Cost Price</label>
                    <input type="number" name="variants[${variantIndex}][cost_price]" class="form-control" 
                           step="0.01" min="0" placeholder="Your cost">
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label text-sm font-medium">Barcode</label>
                    <input type="text" name="variants[${variantIndex}][barcode]" class="form-control" 
                           placeholder="Product barcode">
                </div>
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
</script>
@endsection