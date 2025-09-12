@extends('layouts.app')

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-primary">
                Create Product
            </h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                Add a new product manually to your inventory
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
    <div class="grid gap-5 lg:gap-7.5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Product Information</h3>
            </div>
            
            <div class="card-body">
                <form action="{{ route('products.store') }}" method="POST" id="productForm">
                    @csrf
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-7">
                        <!-- Basic Information -->
                        <div class="flex flex-col gap-5">
                            <div class="flex flex-col gap-2">
                                <label class="form-label required">Product Title</label>
                                <input type="text" name="title" class="input @error('title') input-error @enderror" 
                                       placeholder="Enter product title" value="{{ old('title') }}" required>
                                @error('title')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="input @error('description') input-error @enderror" 
                                          placeholder="Enter product description" rows="4">{{ old('description') }}</textarea>
                                @error('description')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">Vendor</label>
                                <input type="text" name="vendor" class="input @error('vendor') input-error @enderror" 
                                       placeholder="Enter vendor name" value="{{ old('vendor') }}">
                                @error('vendor')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">Product Type</label>
                                <input type="text" name="product_type" class="input @error('product_type') input-error @enderror" 
                                       placeholder="e.g., Electronics, Clothing" value="{{ old('product_type') }}">
                                @error('product_type')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>

                        <!-- Settings -->
                        <div class="flex flex-col gap-5">
                            <div class="flex flex-col gap-2">
                                <label class="form-label required">Status</label>
                                <select name="status" class="select @error('status') select-error @enderror" required>
                                    <option value="">Select status</option>
                                    <option value="active" {{ old('status') == 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="draft" {{ old('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                                    <option value="archived" {{ old('status') == 'archived' ? 'selected' : '' }}>Archived</option>
                                </select>
                                @error('status')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">Tags</label>
                                <input type="text" name="tags" class="input @error('tags') input-error @enderror" 
                                       placeholder="Enter tags separated by commas" value="{{ old('tags') }}">
                                <span class="form-hint">Separate multiple tags with commas</span>
                                @error('tags')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">SEO Title</label>
                                <input type="text" name="seo_title" class="input @error('seo_title') input-error @enderror" 
                                       placeholder="Enter SEO title" value="{{ old('seo_title') }}">
                                @error('seo_title')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="form-label">SEO Description</label>
                                <textarea name="seo_description" class="input @error('seo_description') input-error @enderror" 
                                          placeholder="Enter SEO description" rows="4">{{ old('seo_description') }}</textarea>
                                @error('seo_description')
                                    <span class="form-hint text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex items-center gap-2">
                                <label class="switch">
                                    <input type="checkbox" name="track_inventory" value="1" 
                                           {{ old('track_inventory', '1') ? 'checked' : '' }}>
                                    <span class="switch-slider"></span>
                                </label>
                                <label class="form-label">Track Inventory</label>
                            </div>

                            <div class="flex items-center gap-2">
                                <label class="switch">
                                    <input type="checkbox" name="is_active" value="1" 
                                           {{ old('is_active', '1') ? 'checked' : '' }}>
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
                        <button type="button" onclick="addVariant()" class="kt-btn kt-btn-light kt-btn-sm">
                            <i class="ki-filled ki-plus"></i>
                            Add Variant
                        </button>
                    </div>

                    <div id="variantsContainer">
                        <!-- Default variant will be added by JavaScript -->
                    </div>

                    <!-- Form Actions -->
                    <div class="flex items-center justify-end gap-5 mt-7">
                        <a href="{{ route('products.index') }}" class="kt-btn kt-btn-light">
                            Cancel
                        </a>
                        <button type="submit" class="kt-btn kt-btn-primary">
                            <i class="ki-filled ki-check"></i>
                            Create Product
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let variantCount = 0;

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

// Add default variant on page load
document.addEventListener('DOMContentLoaded', function() {
    addVariant();
});

// Form validation
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
</script>
@endsection
