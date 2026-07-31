@extends('layouts.app')

@section('title', '🌿 Khaas Warehouse Transfers')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-amber-600 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">🚚 {{ $khaasBU->name }} Warehouse Transfers</h1>
                <p class="text-sm text-gray-600 mt-0.5">Approve or reject warehouse-to-store transfers</p>
            </div>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
            🌿 {{ $khaasBU->name }}
        </span>
    </div>

    <!-- Status Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <a href="{{ route('khaas.transfers', ['status' => 'pending']) }}" 
           class="bg-white border-2 {{ $status === 'pending' ? 'border-amber-400 ring-2 ring-amber-100' : 'border-gray-200' }} rounded-xl p-5 hover:shadow-md transition-all">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Pending</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ $pendingCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-amber-50 text-amber-500">
                    <i class="ki-filled ki-time text-2xl"></i>
                </div>
            </div>
        </a>
        <a href="{{ route('khaas.transfers', ['status' => 'approved']) }}" 
           class="bg-white border-2 {{ $status === 'approved' ? 'border-green-400 ring-2 ring-green-100' : 'border-gray-200' }} rounded-xl p-5 hover:shadow-md transition-all">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Approved</p>
                    <p class="text-2xl font-bold text-green-600 mt-1">{{ $approvedCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-green-50 text-green-500">
                    <i class="ki-filled ki-check-circle text-2xl"></i>
                </div>
            </div>
        </a>
        <a href="{{ route('khaas.transfers', ['status' => 'rejected']) }}" 
           class="bg-white border-2 {{ $status === 'rejected' ? 'border-red-400 ring-2 ring-red-100' : 'border-gray-200' }} rounded-xl p-5 hover:shadow-md transition-all">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Rejected</p>
                    <p class="text-2xl font-bold text-red-600 mt-1">{{ $rejectedCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-red-50 text-red-500">
                    <i class="ki-filled ki-cross-circle text-2xl"></i>
                </div>
            </div>
        </a>
    </div>

    <!-- Status Filter Tabs -->
    <div class="flex gap-2 mb-4">
        <a href="{{ route('khaas.transfers', ['status' => 'all']) }}" 
           class="px-3 py-1.5 text-sm rounded-lg font-medium {{ $status === 'all' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }} transition-colors">
            All
        </a>
        <a href="{{ route('khaas.transfers', ['status' => 'pending']) }}" 
           class="px-3 py-1.5 text-sm rounded-lg font-medium {{ $status === 'pending' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100' }} transition-colors">
            ⏳ Pending ({{ $pendingCount }})
        </a>
        <a href="{{ route('khaas.transfers', ['status' => 'approved']) }}" 
           class="px-3 py-1.5 text-sm rounded-lg font-medium {{ $status === 'approved' ? 'bg-green-600 text-white' : 'bg-green-50 text-green-700 hover:bg-green-100' }} transition-colors">
            ✅ Approved
        </a>
        <a href="{{ route('khaas.transfers', ['status' => 'rejected']) }}" 
           class="px-3 py-1.5 text-sm rounded-lg font-medium {{ $status === 'rejected' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700 hover:bg-red-100' }} transition-colors">
            ❌ Rejected
        </a>
    </div>

    <!-- Transfers List -->
    <div class="space-y-4">
        @forelse($transfers as $transfer)
        <div class="bg-white border border-gray-200 rounded-xl p-5 hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between gap-4">
                <!-- Product Info -->
                <div class="flex items-start gap-4 flex-1">
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center {{ $transfer->status === 'pending' ? 'bg-amber-100 text-amber-600' : ($transfer->status === 'approved' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600') }}">
                        @if($transfer->status === 'pending')
                            <i class="ki-filled ki-time text-xl"></i>
                        @elseif($transfer->status === 'approved')
                            <i class="ki-filled ki-check text-xl"></i>
                        @else
                            <i class="ki-filled ki-cross text-xl"></i>
                        @endif
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-semibold text-gray-900">
                                {{ $transfer->product ? $transfer->product->title : 'Product #' . $transfer->product_id }}
                            </h3>
                            @if($transfer->variant)
                                <span class="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-600">{{ $transfer->variant->title }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-4 mt-1">
                            <span class="text-sm text-gray-600">
                                <span class="font-medium">{{ $transfer->quantity }}</span> units
                            </span>
                            <span class="text-xs text-gray-400">
                                🏭 {{ ucfirst($transfer->from_location) }} → 🏪 {{ ucfirst($transfer->to_location) }}
                            </span>
                        </div>
                        @if($transfer->notes)
                            <p class="text-sm text-gray-500 mt-1">📝 {{ $transfer->notes }}</p>
                        @endif
                        <div class="flex items-center gap-3 mt-2 text-xs text-gray-400">
                            <span>Requested by: <span class="font-medium text-gray-600">{{ $transfer->requester ? $transfer->requester->fullname : '—' }}</span></span>
                            <span>{{ $transfer->created_at->format('M d, Y h:i A') }}</span>
                        </div>
                        @if($transfer->status === 'approved' && $transfer->approver)
                            <div class="text-xs text-green-600 mt-1">
                                {{-- approver may be null if the user record was removed; the
                                     rejecter line below is already guarded the same way. --}}
                                ✅ Approved by {{ $transfer->approver->fullname ?? 'Unknown' }} on {{ $transfer->approved_at ? $transfer->approved_at->format('M d, Y h:i A') : 'N/A' }}
                            </div>
                        @elseif($transfer->status === 'rejected')
                            <div class="text-xs text-red-600 mt-1">
                                ❌ Rejected{{ $transfer->rejecter ? ' by ' . $transfer->rejecter->fullname : '' }}{{ $transfer->rejected_at ? ' on ' . $transfer->rejected_at->format('M d, Y h:i A') : '' }}
                                @if($transfer->rejection_reason)
                                    <span class="block mt-0.5 text-red-500">Reason: {{ $transfer->rejection_reason }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Actions (only for pending) -->
                @if($transfer->status === 'pending')
                <div class="flex items-center gap-2 shrink-0">
                    <!-- Approve Button -->
                    <form method="POST" action="{{ route('khaas.transfers.approve', $transfer->id) }}" class="inline">
                        @csrf
                        <button type="submit" onclick="return confirm('Approve transfer of {{ $transfer->quantity }} units to store?')"
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition-colors shadow-sm">
                            <i class="ki-filled ki-check mr-1.5"></i> Approve
                        </button>
                    </form>
                    
                    <!-- Reject Button -->
                    <button type="button" onclick="openRejectModal({{ $transfer->id }}, '{{ $transfer->product ? addslashes($transfer->product->title) : 'Product #' . $transfer->product_id }}', {{ $transfer->quantity }})"
                        class="inline-flex items-center px-4 py-2 bg-red-50 text-red-600 text-sm font-medium rounded-lg hover:bg-red-100 border border-red-200 transition-colors">
                        <i class="ki-filled ki-cross mr-1.5"></i> Reject
                    </button>
                </div>
                @else
                <div class="shrink-0">
                    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider
                        {{ $transfer->status === 'approved' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                        {{ $transfer->status }}
                    </span>
                </div>
                @endif
            </div>
        </div>
        @empty
        <div class="bg-white border border-gray-200 rounded-xl p-12 text-center">
            <div class="text-4xl mb-3">🚚</div>
            <h3 class="text-lg font-semibold text-gray-700">No {{ $status !== 'all' ? ucfirst($status) : '' }} Transfers</h3>
            <p class="text-sm text-gray-500 mt-1">
                @if($status === 'pending')
                    No transfers pending approval. All caught up! 🎉
                @else
                    {{ ucfirst($status) }} warehouse transfers will appear here.
                @endif
            </p>
        </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if($transfers->hasPages())
    <div class="mt-6">
        {{ $transfers->appends(request()->query())->links() }}
    </div>
    @endif
</div>

@endsection

@push('modals')
<!-- Reject Modal -->
{{-- ⚠️ Shell inline-styled deliberately: inset-0, flex and max-w-md are ALL purged from the
     built styles.css, so a class-only shell renders un-positioned with no backdrop. --}}
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeRejectModal()">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6" style="width:100%; max-width:28rem; max-height:90vh; overflow-y:auto; background:#fff; border-radius:0.75rem; padding:1.5rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <h3 class="text-lg font-semibold text-gray-900 mb-1">Reject Transfer</h3>
        <p class="text-sm text-gray-500 mb-4" id="rejectModalInfo"></p>
        <form id="rejectForm" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Reason for Rejection</label>
                <textarea name="reason" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-red-500 focus:border-red-500" placeholder="Provide a reason for rejecting this transfer..." required></textarea>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-white text-sm font-medium rounded-lg transition-colors" style="background-color: #dc2626;" onmouseover="this.style.backgroundColor='#b91c1c'" onmouseout="this.style.backgroundColor='#dc2626'">
                    Reject Transfer
                </button>
            </div>
        </form>
    </div>
</div>
@endpush

@push('demo1_js')
<script>
    function openRejectModal(transferId, productName, quantity) {
        const modal = document.getElementById('rejectModal');
        const info = document.getElementById('rejectModalInfo');
        const form = document.getElementById('rejectForm');
        
        info.textContent = `Rejecting transfer of ${quantity} units of "${productName}". Stock will be returned to warehouse.`;
        form.action = `{{ url('khaas/transfers') }}/${transferId}/reject`;
        
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    
    function closeRejectModal() {
        const modal = document.getElementById('rejectModal');
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
</script>
@endpush
