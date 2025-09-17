@extends('layouts.app')

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-primary">
                Edit Product: {{ $product->title }}
            </h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                @if($product->shopify_product_id)
                    <span class="badge badge-light badge-outline">
                        <i class="ki-filled ki-shop text-info"></i>
                        Shopify Product
                    </span>
                @elseif($product->external_source === 'woocommerce')
                    <span class="badge badge-light badge-outline">
                        <i class="ki-filled ki-shop text-purple-600"></i>
                        WooCommerce Product
                    </span>
                @else
                    <span class="badge badge-light badge-outline">
                        <i class="ki-filled ki-pencil text-success"></i>
                        Manual Product
                    </span>
                @endif
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
    <form action="{{ route('products.update', $product->id) }}" method="POST">
        @csrf
        @method('PUT')
        
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
                               value="{{ old('title', $product->title) }}" required
                               placeholder="Enter product name">
                        @error('title')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Status -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label required text-sm font-medium">Status</label>
                        <select name="status" class="form-select" required>
                            <option value="active" {{ old('status', $product->status) === 'active' ? 'selected' : '' }}>🟢 Active</option>
                            <option value="draft" {{ old('status', $product->status) === 'draft' ? 'selected' : '' }}>🟡 Draft</option>
                            <option value="archived" {{ old('status', $product->status) === 'archived' ? 'selected' : '' }}>🔴 Archived</option>
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
                              placeholder="Describe your product...">{{ old('description', $product->description) }}</textarea>
                    @error('description')
                        <span class="form-hint text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Settings Checkboxes -->
                <div class="flex items-center gap-8 mt-5 p-4 bg-gray-50 rounded-lg">
                    <label class="checkbox flex items-center gap-2">
                        <input type="checkbox" name="track_inventory" value="1" 
                               {{ old('track_inventory', $product->track_inventory) ? 'checked' : '' }}>
                        <span class="checkbox-indicator"></span>
                        <span class="text-sm font-medium">📊 Track Inventory</span>
                    </label>
                    
                    <label class="checkbox flex items-center gap-2">
                        <input type="checkbox" name="is_active" value="1" 
                               {{ old('is_active', $product->is_active) ? 'checked' : '' }}>
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
                               value="{{ old('vendor', $product->vendor) }}" 
                               placeholder="e.g., Nike, Apple, Local Supplier">
                        @error('vendor')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Product Type -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">📂 Product Category</label>
                        <input type="text" name="product_type" class="form-control" 
                               value="{{ old('product_type', $product->product_type) }}" 
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
                           value="{{ old('tags', is_array($product->tags) ? implode(', ', $product->tags) : $product->tags) }}" 
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
                               value="{{ old('seo_title', $product->seo_title) }}" 
                               placeholder="Title for search engines">
                        @error('seo_title')
                            <span class="form-hint text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- SEO Description -->
                    <div class="flex flex-col gap-2">
                        <label class="form-label text-sm font-medium">📝 SEO Description</label>
                        <textarea name="seo_description" class="form-control" rows="3" 
                                  placeholder="Brief description for search results...">{{ old('seo_description', $product->seo_description) }}</textarea>
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
                    @forelse($product->variants as $index => $variant)
                        <div class="variant-row p-4 border border-gray-200 rounded-lg mb-4" data-index="{{ $index }}">
                            <div class="flex justify-between items-center mb-3">
                                <h4 class="text-sm font-semibold text-gray-700">Variant #{{ $index + 1 }}</h4>
                                @if(count($product->variants) > 1)
                                    <button type="button" onclick="removeVariant(this)" 
                                            class="kt-btn kt-btn-sm kt-btn-light text-red-600 hover:bg-red-50">
                                        <i class="ki-filled ki-trash"></i>
                                    </button>
                                @endif
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <input type="hidden" name="variants[{{ $index }}][id]" value="{{ $variant->id }}">
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label text-sm font-medium">
                                        Variant Title
                                        @if(count($product->variants) == 1)
                                            <span class="text-xs text-gray-500">(defaults to product title)</span>
                                        @else
                                            <span class="text-red-500">*</span>
                                        @endif
                                    </label>
                                    <input type="text" name="variants[{{ $index }}][title]" class="form-control" 
                                           value="{{ old('variants.'.$index.'.title', $variant->title) }}" 
                                           {{ count($product->variants) > 1 ? 'required' : '' }}
                                           placeholder="{{ count($product->variants) == 1 ? $product->title : 'e.g., Small Red' }}">
                                </div>
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label text-sm font-medium">SKU</label>
                                    <input type="text" name="variants[{{ $index }}][sku]" class="form-control" 
                                           value="{{ old('variants.'.$index.'.sku', $variant->sku) }}"
                                           placeholder="e.g., PROD-SM-RED">
                                </div>
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label required text-sm font-medium">💰 Price (PKR)</label>
                                    <input type="number" name="variants[{{ $index }}][price]" class="form-control" 
                                           value="{{ old('variants.'.$index.'.price', $variant->price) }}" 
                                           step="0.01" min="0" required>
                                </div>
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label required text-sm font-medium">📦 Stock</label>
                                    <input type="number" name="variants[{{ $index }}][inventory_quantity]" class="form-control" 
                                           value="{{ old('variants.'.$index.'.inventory_quantity', $variant->inventory_quantity) }}" 
                                           min="0" required>
                                </div>
                            </div>
                            
                            <!-- Advanced variant options (collapsible) -->
                            <div class="mt-3">
                                <button type="button" onclick="toggleVariantAdvanced({{ $index }})" 
                                        class="text-xs text-blue-600 hover:text-blue-800">
                                    <span id="variantAdvancedIcon{{ $index }}">▶️</span> Advanced Options
                                </button>
                                
                                <div id="variantAdvanced{{ $index }}" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-4" style="display: none;">
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label text-sm font-medium">Compare Price</label>
                                        <input type="number" name="variants[{{ $index }}][compare_at_price]" class="form-control" 
                                               value="{{ old('variants.'.$index.'.compare_at_price', $variant->compare_at_price) }}" 
                                               step="0.01" min="0" placeholder="Original price">
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label text-sm font-medium">Cost Price</label>
                                        <input type="number" name="variants[{{ $index }}][cost_price]" class="form-control" 
                                               value="{{ old('variants.'.$index.'.cost_price', $variant->cost_price) }}" 
                                               step="0.01" min="0" placeholder="Your cost">
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label text-sm font-medium">Barcode</label>
                                        <input type="text" name="variants[{{ $index }}][barcode]" class="form-control" 
                                               value="{{ old('variants.'.$index.'.barcode', $variant->barcode) }}"
                                               placeholder="Product barcode">
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="variant-row p-4 border border-gray-200 rounded-lg mb-4" data-index="0">
                            <div class="flex justify-between items-center mb-3">
                                <h4 class="text-sm font-semibold text-gray-700">Variant #1</h4>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div class="flex flex-col gap-2">
                                    <label class="form-label required text-sm font-medium">Variant Title</label>
                                    <input type="text" name="variants[0][title]" class="form-control" 
                                           value="{{ old('variants.0.title', $product->title) }}" required>
                                </div>
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label text-sm font-medium">SKU</label>
                                    <input type="text" name="variants[0][sku]" class="form-control" 
                                           value="{{ old('variants.0.sku') }}">
                                </div>
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label required text-sm font-medium">💰 Price (PKR)</label>
                                    <input type="number" name="variants[0][price]" class="form-control" 
                                           value="{{ old('variants.0.price', 0) }}" step="0.01" min="0" required>
                                </div>
                                
                                <div class="flex flex-col gap-2">
                                    <label class="form-label required text-sm font-medium">📦 Stock</label>
                                    <input type="number" name="variants[0][inventory_quantity]" class="form-control" 
                                           value="{{ old('variants.0.inventory_quantity', 0) }}" min="0" required>
                                </div>
                            </div>
                        </div>
                    @endforelse
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
                            <i class="ki-filled ki-check"></i>
                            Update Product
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let variantIndex = {{ count($product->variants) }};

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