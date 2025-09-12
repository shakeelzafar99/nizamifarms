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
            @if($product->shopify_product_id)
                <button onclick="syncProduct({{ $product->id }})" class="kt-btn kt-btn-info">
                    <i class="ki-filled ki-arrows-circle"></i>
                    Sync from Shopify
                </button>
            @endif
        </div>
    </div>
</div>

@if($product->shopify_product_id)
<div class="container-fixed">
    <div class="alert alert-warning">
        <div class="alert-icon">
            <i class="ki-filled ki-information-2"></i>
        </div>
        <div class="alert-content">
            <strong>Shopify Product:</strong> This product is synced from Shopify and cannot be edited manually. 
            Use the "Sync from Shopify" button to update it with the latest data from your Shopify store.
        </div>
    </div>
</div>
@endif

<div class="container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Product Information</h3>
            </div>
            
            <div class="card-body">
                <form action="{{ route('products.update', $product->id) }}" method="POST" id="productForm">
                    @csrf
                    @method('PUT')
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-7">
                        <!-- Basic Information -->
                        <div class="flex flex-col gap-5">
                            <div class="flex flex-col gap-2">
                                <label class="form-label required">Product Title</label>
                                <input type="text" name="title" class="input @error('title') input-error @enderror" 
                                       placeholder="Enter product title" value="{{ old('title', $product->title) }}" 
                                       {{ $product->shopify_product_id ? 'readonly' : 'required' }}>
                                @error('title')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="input @error('description') input-error @enderror" 
                                          placeholder="Enter product description" rows="4" 
                                          {{ $product->shopify_product_id ? 'readonly' : '' }}>{{ old('description', $product->description) }}</textarea>
                                @error('description')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">Vendor</label>
                                <input type="text" name="vendor" class="input @error('vendor') input-error @enderror" 
                                       placeholder="Enter vendor name" value="{{ old('vendor', $product->vendor) }}"
                                       {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                @error('vendor')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">Product Type</label>
                                <input type="text" name="product_type" class="input @error('product_type') input-error @enderror" 
                                       placeholder="e.g., Electronics, Clothing" value="{{ old('product_type', $product->product_type) }}"
                                       {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                @error('product_type')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>

                        <!-- Settings -->
                        <div class="flex flex-col gap-5">
                            <div class="flex flex-col gap-2">
                                <label class="form-label required">Status</label>
                                <select name="status" class="select @error('status') select-error @enderror" 
                                        {{ $product->shopify_product_id ? 'disabled' : 'required' }}>
                                    <option value="">Select status</option>
                                    <option value="active" {{ old('status', $product->status) == 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="draft" {{ old('status', $product->status) == 'draft' ? 'selected' : '' }}>Draft</option>
                                    <option value="archived" {{ old('status', $product->status) == 'archived' ? 'selected' : '' }}>Archived</option>
                                </select>
                                @error('status')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">Tags</label>
                                <input type="text" name="tags" class="input @error('tags') input-error @enderror" 
                                       placeholder="Enter tags separated by commas" 
                                       value="{{ old('tags', is_array($product->tags) ? implode(', ', $product->tags) : '') }}"
                                       {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                <span class="form-hint">Separate multiple tags with commas</span>
                                @error('tags')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">SEO Title</label>
                                <input type="text" name="seo_title" class="input @error('seo_title') input-error @enderror" 
                                       placeholder="Enter SEO title" value="{{ old('seo_title', $product->seo_title) }}"
                                       {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                @error('seo_title')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">SEO Description</label>
                                <textarea name="seo_description" class="input @error('seo_description') input-error @enderror" 
                                          placeholder="Enter SEO description" rows="4" 
                                          {{ $product->shopify_product_id ? 'readonly' : '' }}>{{ old('seo_description', $product->seo_description) }}</textarea>
                                @error('seo_description')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex items-center gap-2">
                                <label class="switch">
                                    <input type="checkbox" name="track_inventory" value="1" 
                                           {{ old('track_inventory', $product->track_inventory) ? 'checked' : '' }}
                                           {{ $product->shopify_product_id ? 'disabled' : '' }}>
                                    <span class="switch-slider"></span>
                                </label>
                                <label class="form-label">Track Inventory</label>
                            </div>

                            <div class="flex items-center gap-2">
                                <label class="switch">
                                    <input type="checkbox" name="is_active" value="1" 
                                           {{ old('is_active', $product->is_active) ? 'checked' : '' }}
                                           {{ $product->shopify_product_id ? 'disabled' : '' }}>
                                    <span class="switch-slider"></span>
                                </label>
                                <label class="form-label">Active</label>
                            </div>
                        </div>
                    </div>

                    <!-- Variants Section -->
                    <div class="separator my-7"></div>
                    
                    <div class="flex items-center justify-between mb-5">
                        <h3 class="text-lg font-semibold">Product Variants</h3>
                        @if(!$product->shopify_product_id)
                            <button type="button" onclick="addVariant()" class="kt-btn kt-btn-light kt-btn-sm">
                                <i class="ki-filled ki-plus"></i>
                                Add Variant
                            </button>
                        @endif
                    </div>

                    <div id="variantsContainer">
                        @foreach($product->variants as $index => $variant)
                            <div class="variant-item border border-gray-200 rounded-lg p-5 mb-5" id="variant-{{ $index + 1 }}">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="text-md font-semibold">{{ $variant->title }}</h4>
                                    @if(!$product->shopify_product_id && count($product->variants) > 1)
                                        <button type="button" onclick="removeVariant({{ $index + 1 }})" class="kt-btn kt-btn-light kt-btn-sm text-danger">
                                            <i class="ki-filled ki-trash"></i>
                                            Remove
                                        </button>
                                    @endif
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <input type="hidden" name="variants[{{ $index }}][id]" value="{{ $variant->id }}">
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label required">Variant Title</label>
                                        <input type="text" name="variants[{{ $index }}][title]" class="input" 
                                               value="{{ $variant->title }}" 
                                               {{ $product->shopify_product_id ? 'readonly' : 'required' }}>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label">SKU</label>
                                        <input type="text" name="variants[{{ $index }}][sku]" class="input" 
                                               value="{{ $variant->sku }}"
                                               {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label required">Price</label>
                                        <input type="number" name="variants[{{ $index }}][price]" class="input" 
                                               value="{{ $variant->price }}" step="0.01" min="0" 
                                               {{ $product->shopify_product_id ? 'readonly' : 'required' }}>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label">Compare at Price</label>
                                        <input type="number" name="variants[{{ $index }}][compare_at_price]" class="input" 
                                               value="{{ $variant->compare_at_price }}" step="0.01" min="0"
                                               {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label">Cost Price</label>
                                        <input type="number" name="variants[{{ $index }}][cost_price]" class="input" 
                                               value="{{ $variant->cost_price }}" step="0.01" min="0"
                                               {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label required">Inventory Quantity</label>
                                        <input type="number" name="variants[{{ $index }}][inventory_quantity]" class="input" 
                                               value="{{ $variant->inventory_quantity }}" min="0" 
                                               {{ $product->shopify_product_id ? 'readonly' : 'required' }}>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label">Weight</label>
                                        <input type="number" name="variants[{{ $index }}][weight]" class="input" 
                                               value="{{ $variant->weight }}" step="0.01" min="0"
                                               {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label">Weight Unit</label>
                                        <select name="variants[{{ $index }}][weight_unit]" class="select"
                                                {{ $product->shopify_product_id ? 'disabled' : '' }}>
                                            <option value="g" {{ $variant->weight_unit == 'g' ? 'selected' : '' }}>Grams (g)</option>
                                            <option value="kg" {{ $variant->weight_unit == 'kg' ? 'selected' : '' }}>Kilograms (kg)</option>
                                            <option value="oz" {{ $variant->weight_unit == 'oz' ? 'selected' : '' }}>Ounces (oz)</option>
                                            <option value="lb" {{ $variant->weight_unit == 'lb' ? 'selected' : '' }}>Pounds (lb)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="form-label">Barcode</label>
                                        <input type="text" name="variants[{{ $index }}][barcode]" class="input" 
                                               value="{{ $variant->barcode }}"
                                               {{ $product->shopify_product_id ? 'readonly' : '' }}>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Form Actions -->
                    <div class="flex items-center justify-end gap-5 mt-7">
                        <a href="{{ route('products.index') }}" class="kt-btn kt-btn-light">
                            Cancel
                        </a>
                        @if(!$product->shopify_product_id)
                            <button type="submit" class="kt-btn kt-btn-primary">
                                <i class="ki-filled ki-check"></i>
                                Update Product
                            </button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let variantCount = {{ count($product->variants) }};

function addVariant() {
    variantCount++;
    const container = document.getElementById('variantsContainer');
    
    const variantHtml = `
        <div class="variant-item border border-gray-200 rounded-lg p-5 mb-5" id="variant-${variantCount}">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-md font-semibold">Variant ${variantCount}</h4>
                <button type="button" onclick="removeVariant(${variantCount})" class="kt-btn kt-btn-light kt-btn-sm text-danger">
                    <i class="ki-filled ki-trash"></i>
                    Remove
                </button>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="flex flex-col gap-2">
                    <label class="form-label required">Variant Title</label>
                    <input type="text" name="variants[${variantCount - 1}][title]" class="input" 
                           placeholder="e.g., Default Title, Small, Large" required>
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label">SKU</label>
                    <input type="text" name="variants[${variantCount - 1}][sku]" class="input" 
                           placeholder="Enter SKU">
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label required">Price</label>
                    <input type="number" name="variants[${variantCount - 1}][price]" class="input" 
                           placeholder="0.00" step="0.01" min="0" required>
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label">Compare at Price</label>
                    <input type="number" name="variants[${variantCount - 1}][compare_at_price]" class="input" 
                           placeholder="0.00" step="0.01" min="0">
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label">Cost Price</label>
                    <input type="number" name="variants[${variantCount - 1}][cost_price]" class="input" 
                           placeholder="0.00" step="0.01" min="0">
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label required">Inventory Quantity</label>
                    <input type="number" name="variants[${variantCount - 1}][inventory_quantity]" class="input" 
                           placeholder="0" min="0" required>
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label">Weight</label>
                    <input type="number" name="variants[${variantCount - 1}][weight]" class="input" 
                           placeholder="0" step="0.01" min="0">
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label">Weight Unit</label>
                    <select name="variants[${variantCount - 1}][weight_unit]" class="select">
                        <option value="g">Grams (g)</option>
                        <option value="kg">Kilograms (kg)</option>
                        <option value="oz">Ounces (oz)</option>
                        <option value="lb">Pounds (lb)</option>
                    </select>
                </div>
                
                <div class="flex flex-col gap-2">
                    <label class="form-label">Barcode</label>
                    <input type="text" name="variants[${variantCount - 1}][barcode]" class="input" 
                           placeholder="Enter barcode">
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', variantHtml);
}

function removeVariant(variantId) {
    if (document.querySelectorAll('.variant-item').length > 1) {
        document.getElementById(`variant-${variantId}`).remove();
    } else {
        alert('At least one variant is required.');
    }
}

function syncProduct(productId) {
    if (confirm('This will sync the product with the latest data from Shopify. Continue?')) {
        fetch(`/products/${productId}/sync`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
            },
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Product synced successfully!');
                location.reload();
            } else {
                alert('Error syncing product: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while syncing the product.');
        });
    }
}

// Form validation (only for manual products)
@if(!$product->shopify_product_id)
document.getElementById('productForm').addEventListener('submit', function(e) {
    const variants = document.querySelectorAll('.variant-item');
    if (variants.length === 0) {
        e.preventDefault();
        alert('At least one variant is required.');
        return false;
    }
    
    // Check if all required variant fields are filled
    let isValid = true;
    variants.forEach(function(variant) {
        const requiredFields = variant.querySelectorAll('input[required]');
        requiredFields.forEach(function(field) {
            if (!field.value.trim()) {
                isValid = false;
                field.classList.add('input-error');
            } else {
                field.classList.remove('input-error');
            }
        });
    });
    
    if (!isValid) {
        e.preventDefault();
        alert('Please fill in all required variant fields.');
        return false;
    }
});
@endif
</script>
@endsection
