@extends('layouts.app')

@section('title', 'Coupon Details')

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-foreground">{{ $coupon->title }}</h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                <span class="badge {{ $coupon->status == 'active' ? 'badge-success' : ($coupon->status == 'expired' ? 'badge-danger' : 'badge-warning') }}">
                    {{ ucfirst($coupon->status) }}
                </span>
                @if($coupon->shopify_discount_id)
                    <span class="text-xs">Shopify ID: {{ $coupon->shopify_discount_id }}</span>
                @endif
            </div>
        </div>
        
        <div class="flex items-center gap-2.5">
            <a href="{{ route('coupons.index') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-arrow-left"></i>
                Back to Coupons
            </a>
            <a href="{{ route('coupons.edit', $coupon->id) }}" class="kt-btn kt-btn-primary">
                <i class="ki-filled ki-notepad-edit"></i>
                Edit Coupon
            </a>
        </div>
    </div>
</div>

<div class="container-fixed">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Details -->
        <div class="lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Coupon Details</h3>
                </div>
                
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="form-label">Title</label>
                            <div class="form-control">{{ $coupon->title }}</div>
                        </div>
                        
                        <div>
                            <label class="form-label">Coupon Code</label>
                            <div class="form-control">
                                @if($coupon->code)
                                    <span class="font-mono bg-gray-100 px-2 py-1 rounded">{{ $coupon->code }}</span>
                                @else
                                    <span class="text-gray-500">No specific code</span>
                                @endif
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Discount Type</label>
                            <div class="form-control">
                                <span class="badge badge-info">{{ ucwords(str_replace('_', ' ', $coupon->discount_type)) }}</span>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Discount Value</label>
                            <div class="form-control">
                                <span class="text-lg font-semibold text-primary">
                                    {{ $coupon->value }}{{ $coupon->value_type == 'percentage' ? '%' : ' PKR' }}
                                </span>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Minimum Amount</label>
                            <div class="form-control">
                                {{ $coupon->minimum_amount ? 'PKR ' . number_format($coupon->minimum_amount, 2) : 'No minimum required' }}
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Usage</label>
                            <div class="form-control">
                                {{ $coupon->usage_count ?? 0 }} used
                                @if($coupon->usage_limit)
                                    / {{ $coupon->usage_limit }} limit
                                    <div class="progress mt-2">
                                        <div class="progress-bar" style="width: {{ $coupon->usage_limit > 0 ? (($coupon->usage_count ?? 0) / $coupon->usage_limit) * 100 : 0 }}%"></div>
                                    </div>
                                @else
                                    (unlimited)
                                @endif
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Valid Period</label>
                            <div class="form-control">
                                <div class="flex flex-col gap-1">
                                    <div>
                                        <strong>Starts:</strong> 
                                        {{ $coupon->starts_at ? $coupon->starts_at->format('M j, Y g:i A') : 'Immediately' }}
                                    </div>
                                    <div>
                                        <strong>Ends:</strong> 
                                        {{ $coupon->ends_at ? $coupon->ends_at->format('M j, Y g:i A') : 'No expiry' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Customer Usage</label>
                            <div class="form-control">
                                {{ $coupon->once_per_customer ? 'Once per customer' : 'Multiple uses per customer allowed' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            @if($coupon->prerequisite_product_ids || $coupon->prerequisite_variant_ids || $coupon->prerequisite_collection_ids || $coupon->prerequisite_customer_ids)
            <div class="card mt-6">
                <div class="card-header">
                    <h3 class="card-title">Prerequisites</h3>
                </div>
                
                <div class="card-body">
                    @if($coupon->prerequisite_product_ids)
                        <div class="mb-4">
                            <label class="form-label">Required Products</label>
                            <div class="form-control">
                                <span class="text-sm text-gray-600">Product IDs: {{ implode(', ', $coupon->prerequisite_product_ids) }}</span>
                            </div>
                        </div>
                    @endif
                    
                    @if($coupon->prerequisite_variant_ids)
                        <div class="mb-4">
                            <label class="form-label">Required Variants</label>
                            <div class="form-control">
                                <span class="text-sm text-gray-600">Variant IDs: {{ implode(', ', $coupon->prerequisite_variant_ids) }}</span>
                            </div>
                        </div>
                    @endif
                    
                    @if($coupon->prerequisite_collection_ids)
                        <div class="mb-4">
                            <label class="form-label">Required Collections</label>
                            <div class="form-control">
                                <span class="text-sm text-gray-600">Collection IDs: {{ implode(', ', $coupon->prerequisite_collection_ids) }}</span>
                            </div>
                        </div>
                    @endif
                    
                    @if($coupon->prerequisite_customer_ids)
                        <div class="mb-4">
                            <label class="form-label">Eligible Customers</label>
                            <div class="form-control">
                                <span class="text-sm text-gray-600">Customer IDs: {{ implode(', ', $coupon->prerequisite_customer_ids) }}</span>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            @endif
        </div>
        
        <!-- Sidebar -->
        <div>
            <!-- Status Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Status & Settings</h3>
                </div>
                
                <div class="card-body">
                    <div class="space-y-4">
                        <div>
                            <label class="form-label">Current Status</label>
                            <div class="form-control">
                                @php
                                    $statusClass = match($coupon->status) {
                                        'active' => 'badge-success',
                                        'scheduled' => 'badge-info',
                                        'expired' => 'badge-danger',
                                        'disabled' => 'badge-secondary',
                                        default => 'badge-secondary'
                                    };
                                @endphp
                                <span class="badge {{ $statusClass }}">{{ ucfirst($coupon->status) }}</span>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Active</label>
                            <div class="form-control">
                                <span class="badge {{ $coupon->is_active ? 'badge-success' : 'badge-secondary' }}">
                                    {{ $coupon->is_active ? 'Yes' : 'No' }}
                                </span>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Target Type</label>
                            <div class="form-control">{{ ucwords(str_replace('_', ' ', $coupon->target_type)) }}</div>
                        </div>
                        
                        <div>
                            <label class="form-label">Target Selection</label>
                            <div class="form-control">{{ ucwords(str_replace('_', ' ', $coupon->target_selection)) }}</div>
                        </div>
                        
                        <div>
                            <label class="form-label">Customer Selection</label>
                            <div class="form-control">{{ ucwords(str_replace('_', ' ', $coupon->customer_selection)) }}</div>
                        </div>
                        
                        <div>
                            <label class="form-label">Allocation Method</label>
                            <div class="form-control">{{ ucfirst($coupon->allocation_method) }}</div>
                        </div>
                        
                        @if($coupon->allocation_limit)
                        <div>
                            <label class="form-label">Allocation Limit</label>
                            <div class="form-control">{{ $coupon->allocation_limit }}</div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            
            <!-- Sync Info -->
            @if($coupon->shopify_discount_id)
            <div class="card mt-6">
                <div class="card-header">
                    <h3 class="card-title">Shopify Sync</h3>
                </div>
                
                <div class="card-body">
                    <div class="space-y-3">
                        <div>
                            <label class="form-label">Shopify ID</label>
                            <div class="form-control">{{ $coupon->shopify_discount_id }}</div>
                        </div>
                        
                        <div>
                            <label class="form-label">Sync Status</label>
                            <div class="form-control">
                                @php
                                    $syncClass = match($coupon->sync_status) {
                                        'synced' => 'badge-success',
                                        'pending' => 'badge-warning',
                                        'error' => 'badge-danger',
                                        default => 'badge-secondary'
                                    };
                                @endphp
                                <span class="badge {{ $syncClass }}">{{ ucfirst($coupon->sync_status) }}</span>
                            </div>
                        </div>
                        
                        <div>
                            <label class="form-label">Last Synced</label>
                            <div class="form-control">
                                {{ $coupon->last_synced_at ? $coupon->last_synced_at->format('M j, Y g:i A') : 'Never' }}
                            </div>
                        </div>
                        
                        @if($coupon->shopify_created_at)
                        <div>
                            <label class="form-label">Created in Shopify</label>
                            <div class="form-control">{{ $coupon->shopify_created_at->format('M j, Y g:i A') }}</div>
                        </div>
                        @endif
                        
                        @if($coupon->shopify_updated_at)
                        <div>
                            <label class="form-label">Updated in Shopify</label>
                            <div class="form-control">{{ $coupon->shopify_updated_at->format('M j, Y g:i A') }}</div>
                        </div>
                        @endif
                        
                        @if($coupon->sync_error)
                        <div>
                            <label class="form-label">Sync Error</label>
                            <div class="form-control">
                                <span class="text-danger text-sm">{{ $coupon->sync_error }}</span>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif
            
            <!-- Timestamps -->
            <div class="card mt-6">
                <div class="card-header">
                    <h3 class="card-title">Timestamps</h3>
                </div>
                
                <div class="card-body">
                    <div class="space-y-3">
                        <div>
                            <label class="form-label">Created</label>
                            <div class="form-control">{{ $coupon->created_at->format('M j, Y g:i A') }}</div>
                        </div>
                        
                        <div>
                            <label class="form-label">Updated</label>
                            <div class="form-control">{{ $coupon->updated_at->format('M j, Y g:i A') }}</div>
                        </div>
                        
                        @if($coupon->created_by)
                        <div>
                            <label class="form-label">Created By</label>
                            <div class="form-control">User ID: {{ $coupon->created_by }}</div>
                        </div>
                        @endif
                        
                        @if($coupon->updated_by)
                        <div>
                            <label class="form-label">Updated By</label>
                            <div class="form-control">User ID: {{ $coupon->updated_by }}</div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
