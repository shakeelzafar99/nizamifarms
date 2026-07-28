@extends('layouts.app')

@section('title', '🌿 Khaas Dashboard')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-5">
        <div>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center text-xl" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20;">
                    🌿
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $khaasBU->name }} Dashboard</h1>
                    <p class="text-sm text-gray-600 mt-0.5">Business Unit Overview & Quick Actions</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                {{ $khaasBU->short_code ?? 'KH' }} · {{ $khaasBU->code }}
            </span>
        </div>
    </div>

    <!-- KPI Cards - 2 compact rows of 4 -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 1.25rem;">
        <!-- Row 1 -->
        <a href="{{ route('khaas.products') }}" class="bg-white border border-gray-200 rounded-xl px-4 py-3 hover:shadow-md transition-shadow group flex items-center gap-3" style="text-decoration:none;">
            <span class="text-xl">📦</span>
            <div class="flex-1 min-w-0">
                <div class="text-lg font-bold text-gray-900 leading-tight">{{ $productCount }}</div>
                <div class="text-[11px] text-gray-500">Active Products</div>
            </div>
            <i class="ki-filled ki-arrow-right text-gray-300 group-hover:text-amber-500 text-sm"></i>
        </a>

        <a href="{{ route('khaas.operations', ['tab' => 'vendors']) }}" class="bg-white border border-gray-200 rounded-xl px-4 py-3 hover:shadow-md transition-shadow group flex items-center gap-3" style="text-decoration:none;">
            <span class="text-xl">🏭</span>
            <div class="flex-1 min-w-0">
                <div class="text-lg font-bold text-gray-900 leading-tight">{{ $vendorCount }}</div>
                <div class="text-[11px] text-gray-500">Active Vendors</div>
            </div>
            <i class="ki-filled ki-arrow-right text-gray-300 group-hover:text-amber-500 text-sm"></i>
        </a>

        <div class="bg-white border border-gray-200 rounded-xl px-4 py-3 flex items-center gap-3">
            <span class="text-xl">💰</span>
            <div class="flex-1 min-w-0">
                <div class="text-lg font-bold {{ $totalVendorBalance >= 0 ? 'text-red-600' : 'text-green-600' }} leading-tight">
                    Rs. {{ number_format(abs($totalVendorBalance)) }}
                </div>
                <div class="text-[11px] text-gray-500">{{ $totalVendorBalance >= 0 ? 'Vendor Payable' : 'Vendor Receivable' }}</div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl px-4 py-3 flex items-center gap-3">
            <span class="text-xl">🏭</span>
            <div class="flex-1 min-w-0">
                <div class="text-lg font-bold text-gray-900 leading-tight">{{ $totalWarehouseQty }}</div>
                <div class="text-[11px] text-gray-500">Warehouse Stock ({{ $warehouseItems }} items)</div>
            </div>
        </div>

        <!-- Row 2 -->
        <a href="{{ route('khaas.operations', ['tab' => 'expenses']) }}" class="bg-white border border-gray-200 rounded-xl px-4 py-3 hover:shadow-md transition-shadow group flex items-center gap-3" style="text-decoration:none;">
            <span class="text-xl">📊</span>
            <div class="flex-1 min-w-0">
                <div class="text-lg font-bold text-gray-900 leading-tight">Rs. {{ number_format($monthlyExpenses) }}</div>
                <div class="text-[11px] text-gray-500">{{ date('M Y') }} Expenses</div>
            </div>
            <i class="ki-filled ki-arrow-right text-gray-300 group-hover:text-amber-500 text-sm"></i>
        </a>

        <a href="{{ route('khaas.operations', ['tab' => 'transfers']) }}" class="bg-white border {{ $pendingTransfers > 0 ? 'border-amber-300' : 'border-gray-200' }} rounded-xl px-4 py-3 hover:shadow-md transition-shadow group flex items-center gap-3" style="text-decoration:none; {{ $pendingTransfers > 0 ? 'background-color:#fffbeb;' : '' }}">
            <span class="text-xl">🔄</span>
            <div class="flex-1 min-w-0">
                <div class="text-lg font-bold {{ $pendingTransfers > 0 ? 'text-amber-600' : 'text-gray-900' }} leading-tight">{{ $pendingTransfers }}</div>
                <div class="text-[11px] text-gray-500">Pending Transfers</div>
            </div>
            <i class="ki-filled ki-arrow-right text-gray-300 group-hover:text-amber-500 text-sm"></i>
        </a>

        <!-- Sales KPIs - CLICKABLE to sales report -->
        <a href="{{ route('khaas.sales-report') }}" class="bg-white border border-green-200 rounded-xl px-4 py-3 hover:shadow-md transition-shadow group flex items-center gap-3" style="text-decoration:none; background-color: #f0fdf4;">
            <span class="text-xl">🧾</span>
            <div class="flex-1 min-w-0">
                <div class="text-lg font-bold text-green-700 leading-tight">Rs. {{ number_format($monthlySalesRevenue) }}</div>
                <div class="text-[11px] text-green-600">{{ date('M Y') }} Sales</div>
            </div>
            <i class="ki-filled ki-arrow-right text-gray-300 group-hover:text-green-500 text-sm"></i>
        </a>

        <a href="{{ route('khaas.sales-report') }}" class="bg-white border border-green-200 rounded-xl px-4 py-3 hover:shadow-md transition-shadow group flex items-center gap-3" style="text-decoration:none; background-color: #f0fdf4;">
            <span class="text-xl">📋</span>
            <div class="flex-1 min-w-0">
                <div class="text-lg font-bold text-green-700 leading-tight">{{ $monthlySalesOrders }}</div>
                <div class="text-[11px] text-green-600">{{ date('M Y') }} Orders ({{ round($monthlySalesUnits) }} units)</div>
            </div>
            <i class="ki-filled ki-arrow-right text-gray-300 group-hover:text-green-500 text-sm"></i>
        </a>
    </div>

    <!-- Alerts -->
    @if($lowStockItems > 0)
    <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
        <span class="text-xl">⚠️</span>
        <div class="flex-1">
            <div class="font-semibold text-red-800 text-sm">Low Stock Alert</div>
            <div class="text-xs text-red-600">{{ $lowStockItems }} product{{ $lowStockItems > 1 ? 's are' : ' is' }} at or below minimum stock level in warehouse.</div>
        </div>
        <a href="{{ route('khaas.products') }}?status=active" class="px-3 py-1.5 bg-red-600 text-white text-xs rounded-lg hover:bg-red-700">View</a>
    </div>
    @endif

    @if($pendingTransfers > 0)
    <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-xl flex items-center gap-3">
        <span class="text-xl">🔄</span>
        <div class="flex-1">
            <div class="font-semibold text-amber-800 text-sm">Pending Warehouse Transfers</div>
            <div class="text-xs text-amber-600">{{ $pendingTransfers }} transfer{{ $pendingTransfers > 1 ? 's need' : ' needs' }} your approval.</div>
        </div>
        <a href="{{ route('khaas.operations', ['tab' => 'transfers']) }}" class="px-3 py-1.5 bg-amber-600 text-white text-xs rounded-lg hover:bg-amber-700">Review</a>
    </div>
    @endif

    <!-- Main Content Grid: 3 columns -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Recent Sales (NEW) -->
        <div class="bg-white border border-gray-200 rounded-xl">
            <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 text-sm">🧾 Recent Khaas Sales</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($recentSales as $orderId => $lineItems)
                @php
                    $order = $lineItems->first()->order;
                    $customer = $order?->customer;
                    $khaasTotal = $lineItems->sum('line_total');
                    $khaasQty = $lineItems->sum('quantity');
                    $itemNames = $lineItems->pluck('name')->unique()->implode(', ');
                @endphp
                <div class="px-4 py-2.5 flex items-center gap-3 hover:bg-gray-50">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-blue-700">{{ $order->order_number ?? '#' . $orderId }}</span>
                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase
                                {{ $order->order_status === 'delivered' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $order->order_status === 'new' ? 'bg-blue-100 text-blue-700' : '' }}
                                {{ in_array($order->order_status, ['in_transit', 'out_for_delivery']) ? 'bg-yellow-100 text-yellow-700' : '' }}
                            ">{{ str_replace('_', ' ', $order->order_status ?? 'unknown') }}</span>
                        </div>
                        <div class="text-[11px] text-gray-500 truncate mt-0.5">
                            {{ $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Walk-in' }}
                            · {{ $itemNames }}
                        </div>
                    </div>
                    <div class="text-right whitespace-nowrap">
                        <div class="text-xs font-bold text-gray-900">Rs. {{ number_format($khaasTotal) }}</div>
                        <div class="text-[10px] text-gray-400">{{ round($khaasQty) }} units</div>
                    </div>
                </div>
                @empty
                <div class="px-5 py-8 text-center text-sm text-gray-400">
                    No Khaas product sales yet this month.
                </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Warehouse Activity -->
        <div class="bg-white border border-gray-200 rounded-xl">
            <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 text-sm">📋 Warehouse Activity</h3>
                {{-- Lands on Products, where each Warehouse count now opens that product's full ledger. --}}
                <a href="{{ route('khaas.products') }}" class="text-xs text-amber-600 hover:text-amber-700">Open Products →</a>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($recentActivity as $log)
                @php
                    // A rejected transfer returns stock and is logged as 'adjustment' (the ENUM
                    // has no reversal type) — without this it reads as a manual correction.
                    $isRejectReturn = $log->reference_type === 'transfer_rejected';
                    $isBatchIn = $log->reference_type === 'batch';
                    $actLabel = $isRejectReturn ? 'Transfer rejected — returned'
                        : ($isBatchIn ? 'Batch production'
                        : ucfirst(str_replace('_', ' ', $log->change_type)));
                @endphp
                <div class="px-4 py-2.5 flex items-center gap-2.5">
                    <span class="text-base">
                        @if($isRejectReturn) ↩️
                        @elseif($isBatchIn) 🏭
                        @else
                            @switch($log->change_type)
                                @case('stock_in') 📥 @break
                                @case('stock_out') 📤 @break
                                @case('transfer') 🔄 @break
                                @case('count') 📊 @break
                                @default ➕
                            @endswitch
                        @endif
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-medium text-gray-900 truncate">{{ $log->product?->title ?? 'Unknown Product' }}</div>
                        <div class="text-[10px] text-gray-500">{{ $actLabel }} · {{ $log->quantity_before }} → {{ $log->quantity_after }} ({{ $log->quantity_change >= 0 ? '+' : '' }}{{ $log->quantity_change }})</div>
                    </div>
                    <div class="text-[10px] text-gray-400 whitespace-nowrap">{{ $log->created_at ? $log->created_at->diffForHumans() : '' }}</div>
                </div>
                @empty
                <div class="px-5 py-8 text-center text-sm text-gray-400">
                    No warehouse activity yet.
                </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Transfers -->
        <div class="bg-white border border-gray-200 rounded-xl">
            <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 text-sm">🔄 Recent Transfers</h3>
                <a href="{{ route('khaas.operations', ['tab' => 'transfers', 'transfer_status' => 'all']) }}" class="text-xs text-amber-600 hover:text-amber-700">View All</a>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($recentTransfers as $transfer)
                <div class="px-4 py-2.5 flex items-center gap-2.5">
                    <span class="text-base">
                        @if($transfer->status === 'pending') ⏳
                        @elseif($transfer->status === 'approved') ✅
                        @else ❌
                        @endif
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-medium text-gray-900 truncate">{{ $transfer->product?->title ?? 'Unknown' }}</div>
                        <div class="text-[10px] text-gray-500">
                            {{ $transfer->quantity }} units · {{ ucfirst($transfer->from_location) }} → {{ ucfirst($transfer->to_location) }}
                            @if($transfer->requester) · by {{ $transfer->requester->fullname }} @endif
                        </div>
                    </div>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-medium 
                        {{ $transfer->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : '' }}
                        {{ $transfer->status === 'approved' ? 'bg-green-100 text-green-700' : '' }}
                        {{ $transfer->status === 'rejected' ? 'bg-red-100 text-red-700' : '' }}
                    ">{{ ucfirst($transfer->status) }}</span>
                </div>
                @empty
                <div class="px-5 py-8 text-center text-sm text-gray-400">
                    No transfers yet.
                </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Quick Navigation -->
    <div class="mt-5" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;">
        <a href="{{ route('khaas.products') }}" class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-md hover:border-amber-300 transition-all group text-center">
            <div class="text-2xl mb-1">📦</div>
            <div class="font-semibold text-gray-900 group-hover:text-amber-700 text-sm">Products & Inventory</div>
            <div class="text-[10px] text-gray-500 mt-0.5">Store vs Warehouse</div>
        </a>
        <a href="{{ route('khaas.operations', ['tab' => 'vendors']) }}" class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-md hover:border-amber-300 transition-all group text-center">
            <div class="text-2xl mb-1">🏭</div>
            <div class="font-semibold text-gray-900 group-hover:text-amber-700 text-sm">Vendors</div>
            <div class="text-[10px] text-gray-500 mt-0.5">Khaas vendor management</div>
        </a>
        <a href="{{ route('khaas.operations', ['tab' => 'expenses']) }}" class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-md hover:border-amber-300 transition-all group text-center">
            <div class="text-2xl mb-1">💰</div>
            <div class="font-semibold text-gray-900 group-hover:text-amber-700 text-sm">Expenses</div>
            <div class="text-[10px] text-gray-500 mt-0.5">Khaas expense tracking</div>
        </a>
        <a href="{{ route('khaas.operations', ['tab' => 'transfers']) }}" class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-md hover:border-amber-300 transition-all group text-center">
            <div class="text-2xl mb-1">🔄</div>
            <div class="font-semibold text-gray-900 group-hover:text-amber-700 text-sm">Warehouse Transfers</div>
            <div class="text-[10px] text-gray-500 mt-0.5">Approve stock movements</div>
        </a>
    </div>
</div>
@endsection
