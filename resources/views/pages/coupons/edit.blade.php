@extends('layouts.app')

@section('title', 'Edit Coupon')

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-foreground">Edit Coupon</h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                Update coupon: {{ $coupon->title }}
                @if($coupon->shopify_discount_id)
                    <span class="text-xs">Shopify ID: {{ $coupon->shopify_discount_id }}</span>
                @endif
            </div>
        </div>
        
        <div class="flex items-center gap-2.5">
            <a href="{{ route('coupons.show', $coupon->id) }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-eye"></i>
                View Details
            </a>
            <a href="{{ route('coupons.index') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-arrow-left"></i>
                Back to Coupons
            </a>
        </div>
    </div>
</div>

<div class="container-fixed">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Edit Coupon Information</h3>
            @if($coupon->shopify_discount_id)
                <div class="card-toolbar">
                    <span class="badge badge-info">Synced from Shopify</span>
                </div>
            @endif
        </div>
        
        <div class="card-body">
            <form action="{{ route('coupons.update', $coupon->id) }}" method="POST">
                @csrf
                @method('PUT')
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Basic Information -->
                    <div class="space-y-4">
                        <h4 class="text-lg font-medium text-gray-900">Basic Information</h4>
                        
                        <div>
                            <label class="form-label required">Title</label>
                            <input type="text" name="title" value="{{ old('title', $coupon->title) }}" 
                                   class="input @error('title') input-error @enderror" 
                                   placeholder="e.g., Holiday Sale 2024" required>
                            @error('title')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div>
                            <label class="form-label">Coupon Code</label>
                            <input type="text" name="code" value="{{ old('code', $coupon->code) }}" 
                                   class="input @error('code') input-error @enderror" 
                                   placeholder="e.g., SAVE20 (optional)">
                            <div class="form-hint">Leave empty if not using a specific code</div>
                            @error('code')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div>
                            <label class="form-label required">Discount Type</label>
                            <select name="discount_type" class="select @error('discount_type') select-error @enderror" required>
                                <option value="percentage" {{ old('discount_type', $coupon->discount_type) == 'percentage' ? 'selected' : '' }}>Percentage</option>
                                <option value="fixed_amount" {{ old('discount_type', $coupon->discount_type) == 'fixed_amount' ? 'selected' : '' }}>Fixed Amount</option>
                                <option value="shipping" {{ old('discount_type', $coupon->discount_type) == 'shipping' ? 'selected' : '' }}>Free Shipping</option>
                            </select>
                            @error('discount_type')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div>
                            <label class="form-label required">Value Type</label>
                            <select name="value_type" class="select @error('value_type') select-error @enderror" required>
                                <option value="percentage" {{ old('value_type', $coupon->value_type) == 'percentage' ? 'selected' : '' }}>Percentage</option>
                                <option value="fixed_amount" {{ old('value_type', $coupon->value_type) == 'fixed_amount' ? 'selected' : '' }}>Fixed Amount</option>
                            </select>
                            @error('value_type')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div>
                            <label class="form-label required">Discount Value</label>
                            <input type="number" name="value" value="{{ old('value', $coupon->value) }}" 
                                   class="input @error('value') input-error @enderror" 
                                   placeholder="e.g., 20 (for 20% or PKR 20)" 
                                   step="0.01" min="0" required>
                            @error('value')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    
                    <!-- Conditions & Limits -->
                    <div class="space-y-4">
                        <h4 class="text-lg font-medium text-gray-900">Conditions & Limits</h4>
                        
                        <div>
                            <label class="form-label">Minimum Amount</label>
                            <input type="number" name="minimum_amount" value="{{ old('minimum_amount', $coupon->minimum_amount) }}" 
                                   class="input @error('minimum_amount') input-error @enderror" 
                                   placeholder="e.g., 100 (PKR)" step="0.01" min="0">
                            <div class="form-hint">Minimum order amount required to use this coupon</div>
                            @error('minimum_amount')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div>
                            <label class="form-label">Usage Limit</label>
                            <input type="number" name="usage_limit" value="{{ old('usage_limit', $coupon->usage_limit) }}" 
                                   class="input @error('usage_limit') input-error @enderror" 
                                   placeholder="e.g., 100 (leave empty for unlimited)" min="1">
                            <div class="form-hint">Total number of times this coupon can be used</div>
                            @error('usage_limit')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div class="flex items-center gap-3">
                            <label class="switch">
                                <input type="checkbox" name="once_per_customer" value="1" 
                                       {{ old('once_per_customer', $coupon->once_per_customer) ? 'checked' : '' }}>
                                <span class="switch-slider"></span>
                            </label>
                            <label class="form-label">Once per customer</label>
                        </div>
                        
                        <div>
                            <label class="form-label">Start Date</label>
                            <input type="datetime-local" name="starts_at" 
                                   value="{{ old('starts_at', $coupon->starts_at ? $coupon->starts_at->format('Y-m-d\TH:i') : '') }}" 
                                   class="input @error('starts_at') input-error @enderror">
                            <div class="form-hint">When this coupon becomes active (leave empty for immediate)</div>
                            @error('starts_at')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div>
                            <label class="form-label">End Date</label>
                            <input type="datetime-local" name="ends_at" 
                                   value="{{ old('ends_at', $coupon->ends_at ? $coupon->ends_at->format('Y-m-d\TH:i') : '') }}" 
                                   class="input @error('ends_at') input-error @enderror">
                            <div class="form-hint">When this coupon expires (leave empty for no expiry)</div>
                            @error('ends_at')
                                <div class="form-hint text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div class="flex items-center gap-3">
                            <label class="switch">
                                <input type="checkbox" name="is_active" value="1" 
                                       {{ old('is_active', $coupon->is_active) ? 'checked' : '' }}>
                                <span class="switch-slider"></span>
                            </label>
                            <label class="form-label">Active</label>
                        </div>
                    </div>
                </div>
                
                <!-- Current Usage Stats -->
                <div class="mt-8 p-4 bg-gray-50 rounded-lg">
                    <h4 class="text-lg font-medium text-gray-900 mb-4">Current Usage Statistics</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="form-label">Times Used</label>
                            <div class="text-2xl font-bold text-primary">{{ $coupon->usage_count ?? 0 }}</div>
                        </div>
                        <div>
                            <label class="form-label">Usage Limit</label>
                            <div class="text-2xl font-bold">{{ $coupon->usage_limit ?? 'Unlimited' }}</div>
                        </div>
                        <div>
                            <label class="form-label">Remaining Uses</label>
                            <div class="text-2xl font-bold text-success">
                                {{ $coupon->usage_limit ? max(0, $coupon->usage_limit - ($coupon->usage_count ?? 0)) : '∞' }}
                            </div>
                        </div>
                    </div>
                    
                    @if($coupon->usage_limit)
                    <div class="mt-4">
                        <label class="form-label">Usage Progress</label>
                        <div class="progress">
                            <div class="progress-bar" style="width: {{ $coupon->usage_limit > 0 ? min(100, (($coupon->usage_count ?? 0) / $coupon->usage_limit) * 100) : 0 }}%"></div>
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            {{ number_format($coupon->usage_limit > 0 ? (($coupon->usage_count ?? 0) / $coupon->usage_limit) * 100 : 0, 1) }}% used
                        </div>
                    </div>
                    @endif
                </div>
                
                <div class="flex justify-end gap-3 mt-8">
                    <a href="{{ route('coupons.show', $coupon->id) }}" class="kt-btn kt-btn-light">Cancel</a>
                    <button type="submit" class="kt-btn kt-btn-primary">Update Coupon</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update value type based on discount type
    const discountType = document.querySelector('select[name="discount_type"]');
    const valueType = document.querySelector('select[name="value_type"]');
    
    discountType.addEventListener('change', function() {
        if (this.value === 'shipping') {
            valueType.value = 'fixed_amount';
            valueType.disabled = true;
            document.querySelector('input[name="value"]').value = '0';
            document.querySelector('input[name="value"]').disabled = true;
        } else {
            valueType.disabled = false;
            document.querySelector('input[name="value"]').disabled = false;
        }
    });
    
    // Trigger initial state
    discountType.dispatchEvent(new Event('change'));
});
</script>
@endsection
