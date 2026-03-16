@extends('layouts.app')

@section('title', '🥩 Khaas Meat Order')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-gray-700 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">🥩 {{ $khaasBU->name }} Meat Order</h1>
                <p class="text-sm text-gray-600 mt-0.5">Order meat from Nizami Farms & track deliveries</p>
            </div>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
            🌿 {{ $khaasBU->name }}
        </span>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 border rounded-lg flex items-center gap-2" style="background-color: #f0fdf4; border-color: #bbf7d0;">
            <span>✅</span>
            <p class="text-sm" style="color: #166534;">{{ session('success') }}</p>
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 border rounded-lg flex items-center gap-2" style="background-color: #fef2f2; border-color: #fecaca;">
            <span>❌</span>
            <p class="text-sm" style="color: #991b1b;">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Tab Navigation -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="flex gap-1 -mb-px" aria-label="Tabs">
            <a href="{{ route('khaas.meat-order', ['tab' => 'orders']) }}"
               class="inline-flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors"
               style="{{ $activeTab === 'orders' ? 'border-color: #D97706; color: #B45309;' : 'border-color: transparent; color: #6B7280;' }}">
                🛒 Track Orders
                @if($pendingReceive->count() > 0)
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs font-bold" style="background-color: #EF4444; color: white;">{{ $pendingReceive->count() }}</span>
                @else
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs" style="{{ $activeTab === 'orders' ? 'background-color: #FEF3C7; color: #B45309;' : 'background-color: #F3F4F6; color: #6B7280;' }}">{{ $orders->count() }}</span>
                @endif
            </a>
            <a href="{{ route('khaas.meat-order', ['tab' => 'new_order']) }}"
               class="inline-flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors"
               style="{{ $activeTab === 'new_order' ? 'border-color: #D97706; color: #B45309;' : 'border-color: transparent; color: #6B7280;' }}">
                📝 New Order
            </a>
        </nav>
    </div>

    {{-- ====================== TRACK ORDERS TAB ====================== --}}
    @if($activeTab === 'orders')
    <div>
        {{-- Pending Receive Banner --}}
        @if($pendingReceive->count() > 0)
        <div class="rounded-xl p-4 mb-5 flex items-center gap-3 border" style="background-color: #FFFBEB; border-color: #FCD34D;">
            <span class="text-xl">⚠️</span>
            <div>
                <p class="text-sm font-bold" style="color: #92400E;">{{ $pendingReceive->count() }} order{{ $pendingReceive->count() > 1 ? 's' : '' }} ready to receive into storage</p>
                <p class="text-xs mt-0.5" style="color: #D97706;">These orders have been delivered by NF and are waiting to be received.</p>
            </div>
        </div>

        {{-- Pending Receive Orders --}}
        <div class="space-y-3 mb-6">
            @foreach($pendingReceive as $order)
            @include('khaas.partials.meat-order-card', ['order' => $order, 'canReceive' => true])
            @endforeach
        </div>
        @endif

        {{-- Other Orders --}}
        @if($otherOrders->count() > 0)
        <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">
            @if($pendingReceive->count() > 0) Other Orders @else All Orders @endif
        </h3>
        <div class="space-y-3">
            @foreach($otherOrders as $order)
            @include('khaas.partials.meat-order-card', ['order' => $order, 'canReceive' => false])
            @endforeach
        </div>
        @endif

        @if($orders->count() === 0)
        <div class="bg-white border border-gray-200 rounded-xl p-10 text-center">
            <div class="text-3xl mb-2">🥩</div>
            <p class="text-sm text-gray-500">No meat orders yet. Place your first order!</p>
        </div>
        @endif
    </div>
    @endif

    {{-- ====================== NEW ORDER TAB ====================== --}}
    @if($activeTab === 'new_order')
    <div class="max-w-2xl">
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                <h3 class="font-semibold text-gray-900">📝 New Meat Order to {{ $settings?->vendor_name ?? 'Nizami Farms' }}</h3>
                <p class="text-xs text-gray-500 mt-0.5">Enter quantities for each product. Only items with a quantity will be included.</p>
            </div>
            <form method="POST" action="{{ route('khaas.meat-order.place') }}" id="newOrderForm" onsubmit="return validateOrder()">
                @csrf
                <div class="divide-y divide-gray-100">
                    @foreach($storageProducts as $idx => $product)
                    <div class="flex items-center justify-between px-5 py-3 hover:bg-gray-50">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-gray-900">{{ $product->display_name ?: $product->product_title }}</span>
                            @if($product->variant_title)
                                <span class="text-xs text-gray-400 ml-1">· {{ $product->variant_title }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="hidden" name="items[{{ $idx }}][product_id]" value="{{ $product->source_product_id }}">
                            <input type="hidden" name="items[{{ $idx }}][variant_id]" value="{{ $product->source_variant_id }}">
                            <input type="hidden" name="items[{{ $idx }}][name]" value="{{ $product->display_name ?: $product->product_title }}">
                            <input type="number" step="0.1" min="0" name="items[{{ $idx }}][quantity]"
                                class="order-qty w-20 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-center"
                                placeholder="0">
                            <span class="text-xs text-gray-400 w-6">{{ $product->default_unit ?: 'kg' }}</span>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div class="px-5 py-4 border-t border-gray-100 bg-gray-50">
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Notes (optional)</label>
                    <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none" placeholder="Any special instructions..."></textarea>
                    <div class="flex justify-end mt-3">
                        <button type="submit" class="px-5 py-2.5 text-sm font-medium rounded-lg shadow-sm transition-colors"
                            style="background-color: #D97706; color: white;"
                            onmouseover="this.style.backgroundColor='#B45309'"
                            onmouseout="this.style.backgroundColor='#D97706'">
                            🛒 Place Order
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
@endsection

@push('demo1_js')
<script>
function validateOrder() {
    var inputs = document.querySelectorAll('.order-qty');
    var hasQty = false;
    inputs.forEach(function(input) { if (parseFloat(input.value) > 0) hasQty = true; });
    if (!hasQty) {
        alert('Please enter quantity for at least one item.');
        return false;
    }
    return confirm('Place this meat order?');
}
</script>
@endpush
