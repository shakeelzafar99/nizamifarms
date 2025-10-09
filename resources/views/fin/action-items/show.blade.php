@extends('layouts.app')

@section('title', 'Action Item Details')

@section('content')
<div class="container-fluid px-6 py-4 mx-auto max-w-5xl">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('fin.action-items.index') }}" class="text-gray-600 hover:text-gray-900">
                <i class="ki-filled ki-arrow-left text-xl"></i>
            </a>
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Action Item Details</h1>
                <p class="text-sm text-gray-600">Review and resolve ledger issues</p>
            </div>
        </div>
        
        @php
            $statusColors = [
                'open' => 'bg-red-100 text-red-800',
                'resolved' => 'bg-green-100 text-green-800',
                'ignored' => 'bg-gray-100 text-gray-800'
            ];
        @endphp
        <span class="px-3 py-1 text-sm font-semibold rounded {{ $statusColors[$item->status] ?? 'bg-gray-100 text-gray-800' }}">
            {{ strtoupper($item->status) }}
        </span>
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

    <!-- Issue Details Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Left Column -->
            <div class="space-y-4">
                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase">Issue Type</label>
                    <p class="text-sm text-gray-900 mt-1">{{ ucwords(str_replace('_', ' ', $item->item_type)) }}</p>
                </div>

                <div>
                    @php
                        $severityColors = [
                            'critical' => 'bg-red-100 text-red-800',
                            'high' => 'bg-orange-100 text-orange-800',
                            'medium' => 'bg-yellow-100 text-yellow-800',
                            'low' => 'bg-blue-100 text-blue-800'
                        ];
                    @endphp
                    <label class="text-xs font-medium text-gray-500 uppercase">Severity</label>
                    <p class="mt-1">
                        <span class="px-2 py-1 text-xs font-semibold rounded {{ $severityColors[$item->severity] ?? 'bg-gray-100 text-gray-800' }}">
                            {{ strtoupper($item->severity) }}
                        </span>
                    </p>
                </div>

                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase">Created</label>
                    <p class="text-sm text-gray-900 mt-1">
                        {{ $item->created_at->format('M d, Y H:i:s') }}
                        @if($item->createdBy)
                            <span class="text-gray-500">by {{ $item->createdBy->name }}</span>
                        @endif
                    </p>
                </div>

                @if($item->status !== 'open')
                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase">
                        {{ $item->status === 'resolved' ? 'Resolved' : 'Dismissed' }}
                    </label>
                    <p class="text-sm text-gray-900 mt-1">
                        {{ $item->resolved_at ? $item->resolved_at->format('M d, Y H:i:s') : '-' }}
                        @if($item->resolvedBy)
                            <span class="text-gray-500">by {{ $item->resolvedBy->name }}</span>
                        @endif
                    </p>
                </div>
                @endif
            </div>

            <!-- Right Column -->
            <div class="space-y-4">
                @if($item->order_id && $item->order)
                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase">Related Order</label>
                    <p class="text-sm text-gray-900 mt-1">
                        <a href="#" class="text-blue-600 hover:text-blue-800 font-medium">
                            Order #{{ $item->order->order_number }}
                        </a>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        Status: {{ ucfirst($item->order->order_status) }} | 
                        Amount: Rs {{ number_format($item->order->total_price, 2) }}
                    </p>
                </div>
                @endif

                @if($item->import_log_id && $item->importLog)
                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase">Related Import</label>
                    <p class="text-sm text-gray-900 mt-1">
                        <a href="{{ route('fin.import.show', $item->import_log_id) }}" class="text-blue-600 hover:text-blue-800 font-medium">
                            Import #{{ $item->import_log_id }}
                        </a>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        Imported: {{ $item->importLog->created_at->format('M d, Y H:i') }}
                    </p>
                </div>
                @endif

                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase">Entity Type</label>
                    <p class="text-sm text-gray-900 mt-1">{{ ucfirst($item->related_entity_type ?? '-') }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Title & Description -->
    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-4">
        <h3 class="text-lg font-semibold text-gray-900 mb-3">{{ $item->title }}</h3>
        
        @if($item->description)
        <div class="bg-gray-50 p-4 rounded-lg mb-4">
            <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $item->description }}</p>
        </div>
        @endif

        @if($item->suggested_action)
        <div class="border-l-4 border-blue-500 bg-blue-50 p-4 rounded-r-lg">
            <p class="text-xs font-semibold text-blue-800 uppercase mb-1">Suggested Action</p>
            <p class="text-sm text-blue-900">{{ $item->suggested_action }}</p>
        </div>
        @endif
    </div>

    <!-- Resolution Notes (if resolved) -->
    @if($item->resolution_notes)
    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-4">
        <h3 class="text-sm font-semibold text-gray-700 uppercase mb-2">Resolution Notes</h3>
        <p class="text-sm text-gray-900 whitespace-pre-wrap">{{ $item->resolution_notes }}</p>
    </div>
    @endif

    <!-- Actions (only for open items) -->
    @if($item->status === 'open')
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Actions</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Retry Posting (only for missing_rider type) -->
            @if($item->item_type === 'missing_rider' && $item->order)
            <div class="border border-gray-200 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 mb-2">Retry Ledger Posting</h4>
                <p class="text-sm text-gray-600 mb-4">If you've assigned a rider to the order, retry posting to the ledger.</p>
                <form method="POST" action="{{ route('fin.action-items.retry', $item->id) }}" onsubmit="return confirm('Retry posting this order to ledger?')">
                    @csrf
                    <button type="submit" class="btn btn-primary w-full">
                        <i class="ki-filled ki-arrow-circle-right"></i>
                        Retry Posting
                    </button>
                </form>
            </div>
            @endif

            <!-- Mark as Resolved -->
            <div class="border border-gray-200 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 mb-2">Mark as Resolved</h4>
                <p class="text-sm text-gray-600 mb-4">If you've fixed the issue manually, mark it as resolved.</p>
                <button onclick="showResolveModal()" class="btn btn-success w-full">
                    <i class="ki-filled ki-check-circle"></i>
                    Mark Resolved
                </button>
            </div>

            <!-- Dismiss -->
            <div class="border border-gray-200 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 mb-2">Dismiss Issue</h4>
                <p class="text-sm text-gray-600 mb-4">If this issue can be ignored, dismiss it.</p>
                <button onclick="showDismissModal()" class="btn btn-secondary w-full">
                    <i class="ki-filled ki-cross-circle"></i>
                    Dismiss
                </button>
            </div>
        </div>
    </div>
    @endif
</div>

<!-- Resolve Modal -->
<div id="resolveModal" class="modal fade" tabindex="-1" style="display:none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('fin.action-items.resolve', $item->id) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Mark as Resolved</h5>
                    <button type="button" class="btn-close" onclick="closeModal('resolveModal')"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Resolution Notes *</label>
                        <textarea name="resolution_notes" class="form-control" rows="4" required placeholder="Describe how you resolved this issue..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('resolveModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark Resolved</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Dismiss Modal -->
<div id="dismissModal" class="modal fade" tabindex="-1" style="display:none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('fin.action-items.dismiss', $item->id) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Dismiss Issue</h5>
                    <button type="button" class="btn-close" onclick="closeModal('dismissModal')"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Reason (optional)</label>
                        <textarea name="resolution_notes" class="form-control" rows="3" placeholder="Why are you dismissing this issue?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('dismissModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Dismiss</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showResolveModal() {
    document.getElementById('resolveModal').style.display = 'block';
    document.getElementById('resolveModal').classList.add('show');
}

function showDismissModal() {
    document.getElementById('dismissModal').style.display = 'block';
    document.getElementById('dismissModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.getElementById(modalId).classList.remove('show');
}
</script>
@endsection

