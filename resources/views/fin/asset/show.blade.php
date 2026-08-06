@extends('layouts.app')

@section('title', e($asset->asset_name))

@section('content')
<div class="max-w-4xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-semibold text-gray-900">{{ $asset->asset_name }}</h1>
                @php $statusBadge = $asset->status_badge; @endphp
                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $statusBadge['class'] }}">
                    {{ $statusBadge['label'] }}
                </span>
            </div>
            <p class="text-sm text-gray-600 mt-1">{{ $asset->asset_code }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('fin.assets.edit', $asset->id) }}" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-md">
                <i class="ki-filled ki-pencil mr-2"></i> Edit
            </a>
            <a href="{{ route('fin.assets.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                ← Back to Assets
            </a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Basic Information -->
            <div class="bg-white border border-gray-200 rounded-lg p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center gap-2">
                    <span class="text-xl">📦</span> Asset Details
                </h2>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Asset Code</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->asset_code }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Business Unit</p>
                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full mt-1
                            {{ $asset->businessUnit->code === 'NF' ? 'bg-emerald-100 text-emerald-800' : 'bg-orange-100 text-orange-800' }}">
                            {{ $asset->businessUnit->name }}
                        </span>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Category</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->category->name ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Condition</p>
                        @php $conditionBadge = $asset->condition_badge; @endphp
                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full mt-1 {{ $conditionBadge['class'] }}">
                            {{ $conditionBadge['label'] }}
                        </span>
                    </div>
                    @if($asset->description)
                    <div class="col-span-2">
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Description</p>
                        <p class="text-sm text-gray-700 mt-1">{{ $asset->description }}</p>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Physical Details -->
            <div class="bg-white border border-gray-200 rounded-lg p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center gap-2">
                    <span class="text-xl">🔧</span> Physical Details
                </h2>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Serial Number</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->serial_number ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Model Number</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->model_number ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Location</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->location ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Useful Life</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->useful_life_years ? $asset->useful_life_years . ' years' : '-' }}</p>
                    </div>
                    @if($asset->notes)
                    <div class="col-span-2">
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Notes</p>
                        <p class="text-sm text-gray-700 mt-1">{{ $asset->notes }}</p>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Purchase Details -->
            <div class="bg-white border border-gray-200 rounded-lg p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center gap-2">
                    <span class="text-xl">💰</span> Purchase Details
                </h2>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Purchase Date</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->purchase_date->format('d M Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Purchase Amount</p>
                        <p class="text-lg font-bold text-emerald-600 mt-1">Rs. {{ number_format($asset->purchase_amount, 0) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Purchased By</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->purchasedBy->fullname ?? 'Unknown' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Payment Account</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">{{ $asset->paymentAccount->account_name ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Payment Mode</p>
                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full mt-1
                            {{ $asset->payment_mode === 'online' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' }}">
                            {{ ucfirst($asset->payment_mode) }}
                        </span>
                    </div>
                    @if($asset->vendor || $asset->vendor_name_snapshot)
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Vendor</p>
                        <p class="text-sm font-medium text-gray-900 mt-1">
                            {{ $asset->vendor->vendor_name ?? $asset->vendor_name_snapshot ?? '-' }}
                        </p>
                    </div>
                    @endif
                </div>

                @if($asset->ledgerTransaction)
                <div class="mt-4 pt-4 border-t border-gray-100">
                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">Linked Ledger Entry</p>
                    <div class="bg-gray-50 rounded-md p-3">
                        <p class="text-sm text-gray-700">{{ $asset->ledgerTransaction->description }}</p>
                        <p class="text-xs text-gray-500 mt-1">
                            Ledger #{{ $asset->ledgerTransaction->id }} • 
                            {{ $asset->ledgerTransaction->transaction_date->format('d M Y') }}
                        </p>
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Quick Info Card -->
            <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 border border-emerald-200 rounded-lg p-6">
                <div class="text-center">
                    <p class="text-sm text-emerald-700">Current Book Value</p>
                    <p class="text-3xl font-bold text-emerald-900 mt-2">
                        Rs. {{ number_format($asset->current_book_value ?? $asset->purchase_amount, 0) }}
                    </p>
                    @if($asset->depreciation_method !== 'none')
                    <p class="text-xs text-emerald-600 mt-2">
                        ({{ ucfirst(str_replace('_', ' ', $asset->depreciation_method)) }} depreciation)
                    </p>
                    @endif
                </div>
            </div>

            <!-- Bill Image -->
            @if($asset->bill_image)
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Bill/Invoice</h3>
                <a href="{{ Storage::url($asset->bill_image) }}" target="_blank" class="block">
                    <img src="{{ Storage::url($asset->bill_image) }}" alt="Bill Image" class="rounded-lg w-full">
                </a>
            </div>
            @endif

            <!-- Audit Info -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Audit Trail</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs text-gray-500">Created</p>
                        <p class="text-gray-700">{{ $asset->created_at->format('d M Y, h:i A') }}</p>
                        @if($asset->createdBy)
                        <p class="text-xs text-gray-500">by {{ $asset->createdBy->fullname }}</p>
                        @endif
                    </div>
                    @if($asset->updated_at && $asset->updated_at != $asset->created_at)
                    <div>
                        <p class="text-xs text-gray-500">Last Updated</p>
                        <p class="text-gray-700">{{ $asset->updated_at->format('d M Y, h:i A') }}</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
