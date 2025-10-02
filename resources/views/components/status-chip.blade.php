{{-- Status Chip Component - Usage: <x-status-chip :status="$order->order_status" /> --}}
@php
    $statusMap = [
        'new' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'dot' => 'bg-amber-500', 'label' => 'New'],
        'processing' => ['bg' => 'bg-blue-50', 'text' => 'text-blue-700', 'dot' => 'bg-blue-500', 'label' => 'Processing'],
        'out_for_delivery' => ['bg' => 'bg-violet-50', 'text' => 'text-violet-700', 'dot' => 'bg-violet-500', 'label' => 'Out for Delivery'],
        'delivered' => ['bg' => 'bg-green-50', 'text' => 'text-green-700', 'dot' => 'bg-green-500', 'label' => 'Delivered'],
        'on_hold' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'dot' => 'bg-orange-500', 'label' => 'On Hold'],
        'cancelled' => ['bg' => 'bg-red-50', 'text' => 'text-red-700', 'dot' => 'bg-red-500', 'label' => 'Cancelled'],
        'refunded' => ['bg' => 'bg-purple-50', 'text' => 'text-purple-700', 'dot' => 'bg-purple-500', 'label' => 'Refunded'],
        'completed' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'dot' => 'bg-emerald-500', 'label' => 'Completed'],
    ];
    
    $statusKey = strtolower($status ?? 'new');
    $config = $statusMap[$statusKey] ?? ['bg' => 'bg-gray-50', 'text' => 'text-gray-700', 'dot' => 'bg-gray-500', 'label' => ucwords(str_replace('_', ' ', $statusKey))];
@endphp

<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $config['bg'] }} {{ $config['text'] }}">
    <span class="w-1.5 h-1.5 rounded-full {{ $config['dot'] }}"></span>
    {{ $config['label'] }}
</span>

