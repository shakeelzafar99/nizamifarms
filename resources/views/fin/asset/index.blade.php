@extends('layouts.app')

@section('title', 'Company Assets')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <!-- Back to Ledger Link -->
    <div style="margin-bottom: 16px;">
        <a href="{{ route('fin.employee.index') }}" style="display: inline-flex; align-items: center; color: #6B7280; font-size: 14px; text-decoration: none;">
            ← Back to NF Ledger
        </a>
    </div>

    <!-- Header with Add Button -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 600; color: #111827; margin: 0;">Company Assets</h1>
            <p style="font-size: 14px; color: #6b7280; margin-top: 4px;">Track and manage company fixed assets</p>
        </div>
        <a href="{{ route('fin.assets.create') }}" style="display: inline-flex; align-items: center; padding: 10px 20px; background: linear-gradient(135deg, #059669, #047857); color: white; font-size: 14px; font-weight: 600; border-radius: 8px; text-decoration: none; box-shadow: 0 2px 4px rgba(5, 150, 105, 0.3);">
            ➕ Add New Asset
        </a>
    </div>

    <!-- Summary Cards - Horizontal -->
    <div style="display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 140px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 20px;">📦</span>
            <div>
                <p style="font-size: 20px; font-weight: 700; color: #111827; margin: 0;">{{ $summary['total_assets'] }}</p>
                <p style="font-size: 12px; color: #6b7280; margin: 0;">Total</p>
            </div>
        </div>
        <div style="flex: 1; min-width: 140px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 20px;">✅</span>
            <div>
                <p style="font-size: 20px; font-weight: 700; color: #059669; margin: 0;">{{ $summary['active_assets'] }}</p>
                <p style="font-size: 12px; color: #6b7280; margin: 0;">Active</p>
            </div>
        </div>
        <div style="flex: 1; min-width: 180px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 20px;">💰</span>
            <div>
                <p style="font-size: 20px; font-weight: 700; color: #059669; margin: 0;">Rs. {{ number_format($summary['total_value'], 0) }}</p>
                <p style="font-size: 12px; color: #6b7280; margin: 0;">Total Value</p>
            </div>
        </div>
        <div style="flex: 1; min-width: 140px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 20px;">📊</span>
            <div>
                <p style="font-size: 20px; font-weight: 700; color: #111827; margin: 0;">{{ count($summary['by_category']) }}</p>
                <p style="font-size: 12px; color: #6b7280; margin: 0;">Categories</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="{{ route('fin.assets.index') }}" class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" 
                       class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2" 
                       placeholder="Name, code, serial...">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Business Unit</label>
                <select name="business_unit" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Business Units</option>
                    @foreach($businessUnits as $unit)
                        <option value="{{ $unit->id }}" {{ request('business_unit') == $unit->id ? 'selected' : '' }}>
                            {{ $unit->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Category</label>
                <select name="category" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2">
                    <option value="">All Statuses</option>
                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="disposed" {{ request('status') == 'disposed' ? 'selected' : '' }}>Disposed</option>
                    <option value="sold" {{ request('status') == 'sold' ? 'selected' : '' }}>Sold</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-md">
                    Filter
                </button>
                <a href="{{ route('fin.assets.index') }}" class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm">
                    Clear
                </a>
            </div>
        </div>
    </form>

    <!-- Success/Error Messages -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Assets Table -->
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Asset</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Business Unit</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Purchased</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($assets as $asset)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div>
                                <p class="font-medium text-gray-900">{{ $asset->asset_name }}</p>
                                <p class="text-xs text-gray-500">{{ $asset->asset_code }}</p>
                                @if($asset->serial_number)
                                    <p class="text-xs text-gray-400">S/N: {{ $asset->serial_number }}</p>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full 
                                {{ $asset->businessUnit->code === 'NF' ? 'bg-emerald-100 text-emerald-800' : 'bg-orange-100 text-orange-800' }}">
                                {{ $asset->businessUnit->name }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm text-gray-700">{{ $asset->category->name ?? '-' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div>
                                <p class="text-sm text-gray-900">{{ $asset->purchase_date->format('d M Y') }}</p>
                                <p class="text-xs text-gray-500">by {{ $asset->purchasedBy->fullname ?? 'Unknown' }}</p>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span class="font-medium text-gray-900">Rs. {{ number_format($asset->purchase_amount, 0) }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @php $statusBadge = $asset->status_badge; @endphp
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $statusBadge['class'] }}">
                                {{ $statusBadge['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('fin.assets.show', $asset->id) }}" 
                               class="text-blue-600 hover:text-blue-800 text-sm mr-2">View</a>
                            <a href="{{ route('fin.assets.edit', $asset->id) }}" 
                               class="text-gray-600 hover:text-gray-800 text-sm">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="padding: 48px 16px; text-align: center;">
                            <div style="font-size: 48px; margin-bottom: 16px;">📦</div>
                            <p style="font-size: 18px; font-weight: 500; color: #374151; margin-bottom: 8px;">No Assets Found</p>
                            <p style="font-size: 14px; color: #6b7280; margin-bottom: 16px;">Start tracking your company assets like equipment, vehicles, and furniture.</p>
                            <a href="{{ route('fin.assets.create') }}" style="display: inline-flex; align-items: center; padding: 12px 24px; background: linear-gradient(135deg, #059669, #047857); color: white; font-size: 14px; font-weight: 600; border-radius: 8px; text-decoration: none; box-shadow: 0 4px 6px rgba(5, 150, 105, 0.3);">
                                ➕ Add Your First Asset
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($assets->hasPages())
        <div class="mt-4">
            {{ $assets->withQueryString()->links() }}
        </div>
    @endif
</div>
@endsection
