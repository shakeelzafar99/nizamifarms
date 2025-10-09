@extends('layouts.app')

@section('title', 'Ledger Action Items')

@section('content')
<div class="container-fluid px-6 py-4 mx-auto max-w-7xl">
    <!-- Header -->
    <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between mb-6">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold text-gray-900">Ledger Action Items</h1>
            <p class="text-sm text-gray-600">Track and resolve issues preventing ledger postings</p>
        </div>
    </div>

    <!-- Success/Error Messages -->
    @if(session('success'))
        <div class="alert alert-success mb-4 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger mb-4 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-4 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase">Open Issues</p>
                    <p class="text-2xl font-bold text-red-600">{{ $statusCounts['open'] }}</p>
                </div>
                <div class="p-3 bg-red-100 rounded-full">
                    <i class="ki-filled ki-information-2 text-xl text-red-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase">Resolved</p>
                    <p class="text-2xl font-bold text-green-600">{{ $statusCounts['resolved'] }}</p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <i class="ki-filled ki-check-circle text-xl text-green-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase">Dismissed</p>
                    <p class="text-2xl font-bold text-gray-600">{{ $statusCounts['ignored'] }}</p>
                </div>
                <div class="p-3 bg-gray-100 rounded-full">
                    <i class="ki-filled ki-cross-circle text-xl text-gray-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase">Total</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $statusCounts['all'] }}</p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <i class="ki-filled ki-menu text-xl text-blue-600"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Actions -->
    <div class="bg-white p-4 rounded-lg border border-gray-200 mb-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <!-- Status Filter -->
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-gray-700">Status:</label>
                <select id="statusFilter" class="form-select px-3 py-2 border border-gray-300 rounded-md text-sm" onchange="filterByStatus(this.value)">
                    <option value="open" {{ $status === 'open' ? 'selected' : '' }}>Open</option>
                    <option value="resolved" {{ $status === 'resolved' ? 'selected' : '' }}>Resolved</option>
                    <option value="ignored" {{ $status === 'ignored' ? 'selected' : '' }}>Dismissed</option>
                    <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All</option>
                </select>
            </div>

            <!-- Auto-Post Toggle (now just a display indicator) -->
            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-gray-700">Auto-Post Invoices:</span>
                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $autoPostEnabled === '1' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                    {{ $autoPostEnabled === '1' ? 'ENABLED' : 'DISABLED' }}
                </span>
                <a href="{{ route('admin.operations') }}" class="text-sm text-blue-600 hover:text-blue-800">
                    <i class="ki-filled ki-setting-2"></i> Manage in Operations
                </a>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        @if($items->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Severity</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Issue</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Related</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($items as $item)
                        <tr class="hover:bg-gray-50">
                            <!-- Severity -->
                            <td class="px-4 py-3">
                                @php
                                    $severityColors = [
                                        'critical' => 'bg-red-100 text-red-800',
                                        'high' => 'bg-orange-100 text-orange-800',
                                        'medium' => 'bg-yellow-100 text-yellow-800',
                                        'low' => 'bg-blue-100 text-blue-800'
                                    ];
                                @endphp
                                <span class="px-2 py-1 text-xs font-semibold rounded {{ $severityColors[$item->severity] ?? 'bg-gray-100 text-gray-800' }}">
                                    {{ strtoupper($item->severity) }}
                                </span>
                            </td>

                            <!-- Type -->
                            <td class="px-4 py-3 text-sm text-gray-900">
                                {{ ucwords(str_replace('_', ' ', $item->item_type)) }}
                            </td>

                            <!-- Title -->
                            <td class="px-4 py-3">
                                <a href="{{ route('fin.action-items.show', $item->id) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">
                                    {{ $item->title }}
                                </a>
                                @if($item->description)
                                    <p class="text-xs text-gray-500 mt-1">{{ Str::limit($item->description, 60) }}</p>
                                @endif
                            </td>

                            <!-- Related Entity -->
                            <td class="px-4 py-3 text-sm text-gray-600">
                                @if($item->order_id && $item->order)
                                    <a href="#" class="text-blue-600 hover:text-blue-800">Order #{{ $item->order->order_number }}</a>
                                @elseif($item->import_log_id && $item->importLog)
                                    <span class="text-gray-600">Import #{{ $item->import_log_id }}</span>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>

                            <!-- Created -->
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ $item->created_at->format('M d, Y H:i') }}
                                @if($item->createdBy)
                                    <p class="text-xs text-gray-500">by {{ $item->createdBy->name }}</p>
                                @endif
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3">
                                @php
                                    $statusColors = [
                                        'open' => 'bg-red-100 text-red-800',
                                        'resolved' => 'bg-green-100 text-green-800',
                                        'ignored' => 'bg-gray-100 text-gray-800'
                                    ];
                                @endphp
                                <span class="px-2 py-1 text-xs font-semibold rounded {{ $statusColors[$item->status] ?? 'bg-gray-100 text-gray-800' }}">
                                    {{ strtoupper($item->status) }}
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('fin.action-items.show', $item->id) }}" class="text-blue-600 hover:text-blue-800" title="View Details">
                                        <i class="ki-filled ki-eye text-lg"></i>
                                    </a>
                                    
                                    @if($item->status === 'open' && $item->item_type === 'missing_rider' && $item->order)
                                        <form method="POST" action="{{ route('fin.action-items.retry', $item->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-green-600 hover:text-green-800" title="Retry Posting" onclick="return confirm('Retry posting this order to ledger?')">
                                                <i class="ki-filled ki-arrow-circle-right text-lg"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 py-3 border-t border-gray-200">
                {{ $items->appends(['status' => $status])->links() }}
            </div>
        @else
            <div class="p-8 text-center">
                <i class="ki-filled ki-check-circle text-6xl text-green-500 mb-3"></i>
                <p class="text-gray-600 font-medium">No {{ $status !== 'all' ? $status : '' }} action items found!</p>
                <p class="text-sm text-gray-500 mt-1">All systems are running smoothly.</p>
            </div>
        @endif
    </div>
</div>

<script>
function filterByStatus(status) {
    window.location.href = "{{ route('fin.action-items.index') }}?status=" + status;
}
</script>
@endsection

