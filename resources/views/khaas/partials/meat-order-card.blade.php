@php
    $nfStatus = $order->nf_order_status ?? 'new';
    $storageStatus = $order->status ?? 'pending';
    $nfStatusColors = [
        'new' => ['bg' => '#F3F4F6', 'text' => '#374151'],
        'processing' => ['bg' => '#DBEAFE', 'text' => '#1D4ED8'],
        'out_for_delivery' => ['bg' => '#FEF3C7', 'text' => '#92400E'],
        'delivered' => ['bg' => '#D1FAE5', 'text' => '#065F46'],
        'completed' => ['bg' => '#D1FAE5', 'text' => '#065F46'],
        'cancelled' => ['bg' => '#FEE2E2', 'text' => '#991B1B'],
    ];
    $nfColor = $nfStatusColors[$nfStatus] ?? $nfStatusColors['new'];
    $storageStatusLabels = [
        'pending' => ['label' => '⏳ Pending', 'bg' => '#FEF3C7', 'text' => '#B45309'],
        'received' => ['label' => '✅ Received', 'bg' => '#DCFCE7', 'text' => '#166534'],
        'cancelled' => ['label' => '❌ Cancelled', 'bg' => '#FEE2E2', 'text' => '#991B1B'],
    ];
    $sStatus = $storageStatusLabels[$storageStatus] ?? $storageStatusLabels['pending'];
@endphp

<div class="bg-white border rounded-xl p-4 hover:shadow-sm transition-shadow" style="border-color: {{ $canReceive ? '#86efac' : '#E5E7EB' }};">
    <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <h4 class="font-semibold text-gray-900 text-sm">{{ $order->order_number ?? 'Order #' . $order->order_id }}</h4>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $nfColor['bg'] }}; color: {{ $nfColor['text'] }};">
                    NF: {{ ucfirst(str_replace('_', ' ', $nfStatus)) }}
                </span>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $sStatus['bg'] }}; color: {{ $sStatus['text'] }};">
                    {{ $sStatus['label'] }}
                </span>
            </div>
            <div class="flex items-center gap-3 mt-1.5 text-xs text-gray-500">
                <span>{{ \Carbon\Carbon::parse($order->created_at)->format('M d, Y h:i A') }}</span>
                @if($order->created_by_name)
                    <span>by {{ $order->created_by_name }}</span>
                @endif
                @if($order->total_amount)
                    <span class="font-semibold text-gray-700">Rs. {{ number_format($order->total_amount, 0) }}</span>
                @endif
            </div>

            {{-- Line items --}}
            @if($order->items && count($order->items) > 0)
            <div class="mt-2 flex flex-wrap gap-1.5">
                @foreach($order->items as $item)
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-700">
                    {{ $item->product_name ?? 'Product' }}
                    <strong>{{ round($item->quantity, 2) }}</strong>
                </span>
                @endforeach
            </div>
            @endif

            @if($order->notes)
                <p class="text-xs text-gray-400 mt-1.5">📝 {{ $order->notes }}</p>
            @endif

            @if($canReceive)
            <div class="mt-2 flex items-center gap-2">
                <span class="text-xs text-green-600 font-medium">📦 Ready to receive into storage</span>
            </div>
            @endif
        </div>

        @if($canReceive)
        <form method="POST" action="{{ route('khaas.meat-order.receive', $order->id) }}" class="shrink-0">
            @csrf
            <button type="submit" onclick="return confirm('Confirm receipt of all items into storage?')"
                class="px-4 py-2 text-xs font-medium rounded-lg shadow-sm text-white" style="background-color: #059669;" onmouseover="this.style.backgroundColor='#047857'" onmouseout="this.style.backgroundColor='#059669'">
                ✅ Receive
            </button>
        </form>
        @endif
    </div>
</div>
