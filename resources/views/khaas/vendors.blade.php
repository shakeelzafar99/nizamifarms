@extends('layouts.app')

@section('title', '🌿 Khaas Vendors')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-amber-600 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">🏭 {{ $khaasBU->name }} Vendors</h1>
                <p class="text-sm text-gray-600 mt-0.5">Manage vendors for {{ $khaasBU->name }} business unit</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right">
                <div class="text-xs text-gray-500">Total Balance</div>
                <div class="text-lg font-bold {{ $totalBalance >= 0 ? 'text-red-600' : 'text-green-600' }}">
                    Rs. {{ number_format(abs($totalBalance)) }}
                </div>
            </div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                🌿 {{ $khaasBU->name }}
            </span>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white border border-gray-200 rounded-xl p-4 mb-6">
        <form method="GET" action="{{ route('khaas.vendors') }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Vendor name, contact..." 
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
            </div>
            <div class="min-w-[140px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                    <option value="active" {{ $status == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ $status == 'inactive' ? 'selected' : '' }}>Inactive</option>
                    <option value="all" {{ $status == 'all' ? 'selected' : '' }}>All</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors">
                <i class="ki-filled ki-magnifier mr-1"></i> Filter
            </button>
            @if(request()->hasAny(['search']))
                <a href="{{ route('khaas.vendors') }}" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                    Clear
                </a>
            @endif
        </form>
    </div>

    <!-- Vendors Table -->
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purchase Method</th>
                        @if($canSeeCosts ?? false)
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cost Type</th>
                        @endif
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($vendors as $vendor)
                    @php
                        $balance = $vendor->account ? $vendor->account->current_balance : 0;
                    @endphp
                    <tr class="hover:bg-amber-50/50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg flex items-center justify-center text-xs font-bold text-white" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                                    {{ strtoupper(substr($vendor->vendor_name, 0, 2)) }}
                                </div>
                                <div>
                                    <div class="font-semibold text-gray-900 text-sm">{{ $vendor->vendor_name }}</div>
                                    @if($vendor->contact_person)
                                        <div class="text-xs text-gray-500">{{ $vendor->contact_person }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            @if($vendor->contact_phone)
                                <div class="text-sm text-gray-700">📱 {{ $vendor->contact_phone }}</div>
                            @endif
                            @if($vendor->contact_email)
                                <div class="text-xs text-gray-500">{{ $vendor->contact_email }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 rounded text-xs font-medium {{ $vendor->default_purchase_method === 'by_weight' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                                {{ $vendor->default_purchase_method === 'by_weight' ? '⚖️ By Weight' : '💵 By Total' }}
                            </span>
                        </td>
                        @if($canSeeCosts ?? false)
                        {{-- Decides which bucket this vendor's bills land in on the Month
                             Review. Read at display time, so a change re-files every past
                             bill too. --}}
                        <td class="px-6 py-4">
                            @php $vct = $vendorCostTypes[(string) $vendor->id] ?? ''; @endphp
                            <select class="px-2 py-1 border border-gray-300 rounded text-xs bg-white"
                                    onchange="khaasSetVendorCostType(this)" data-key="{{ $vendor->id }}">
                                <option value="" {{ $vct === '' ? 'selected' : '' }} disabled>Not set</option>
                                @foreach(($costTypes ?? []) as $t)
                                    <option value="{{ $t }}" {{ $vct === $t ? 'selected' : '' }}>
                                        {{ $t === 'product' ? 'Product' : ($t === 'fixed' ? 'Fixed' : 'One-time') }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        @endif
                        <td class="px-6 py-4 text-right">
                            <span class="font-semibold text-sm {{ $balance > 0 ? 'text-red-600' : ($balance < 0 ? 'text-green-600' : 'text-gray-500') }}">
                                Rs. {{ number_format(abs($balance)) }}
                            </span>
                            @if($balance > 0)
                                <div class="text-[10px] text-red-500">Payable</div>
                            @elseif($balance < 0)
                                <div class="text-[10px] text-green-500">Receivable</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $vendor->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('fin.vendors.show', $vendor->id) }}" class="inline-flex items-center px-3 py-1.5 bg-amber-50 text-amber-700 text-xs font-medium rounded-lg hover:bg-amber-100 transition-colors">
                                <i class="ki-filled ki-eye mr-1"></i> View
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ ($canSeeCosts ?? false) ? 7 : 6 }}" class="px-6 py-12 text-center">
                            <div class="text-4xl mb-3">🏭</div>
                            <h3 class="text-lg font-semibold text-gray-700">No Khaas Vendors Found</h3>
                            <p class="text-sm text-gray-500 mt-1">Vendors assigned to the {{ $khaasBU->name }} business unit will appear here.</p>
                            <a href="{{ route('fin.vendors.create') }}" class="inline-flex items-center mt-3 px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700">
                                <i class="ki-filled ki-plus mr-1"></i> Add Vendor
                            </a>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    @if($vendors->hasPages())
    <div class="mt-6">
        {{ $vendors->appends(request()->query())->links() }}
    </div>
    @endif
</div>

@if($canSeeCosts ?? false)
<script>
(function () {
    'use strict';
    var CSRF = @json(csrf_token());
    var URL_SET = @json(route('khaas.month-review.cost-type'));

    window.khaasSetVendorCostType = function (sel) {
        var key = sel.getAttribute('data-key');
        var type = sel.value;
        if (!key || !type) { return; }

        sel.disabled = true;
        var body = new URLSearchParams();
        body.append('source_kind', 'vendor');
        body.append('source_key', key);
        body.append('cost_type', type);

        fetch(URL_SET, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        })
        .then(function (r) { return r.json().catch(function () { return {success: false}; }); })
        .then(function (d) {
            sel.disabled = false;
            if (!d || !d.success) { alert((d && d.message) ? d.message : 'Could not save that change.'); }
        })
        .catch(function () {
            sel.disabled = false;
            alert('Could not save that change. Check your connection and try again.');
        });
    };
})();
</script>
@endif
@endsection
