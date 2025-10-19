{{-- resources/views/fin/adjustments/show.blade.php --}}

@extends('layouts.app')

@section('title', 'Ledger Adjustment Details')

@section('content')

<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        
        <!-- Adjustment Details Card -->
        <div class="kt-card">
            <div class="kt-card-header">
                <div class="flex items-center gap-4">
                    <a href="{{ route('approvals.index') }}" class="kt-btn kt-btn-sm kt-btn-light">
                        <i class="ki-filled ki-left"></i>
                    </a>
                    <h3 class="kt-card-title">Ledger Adjustment #ADJ-{{ $adjustment->id }}</h3>
                </div>
                <div class="kt-card-toolbar flex gap-2">
                    @php
                        $statusClasses = [
                            'pending' => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger'
                        ];
                        $statusClass = $statusClasses[$adjustment->adjustment_status] ?? 'secondary';
                    @endphp
                    <span class="kt-badge kt-badge-{{ $statusClass }}">
                        {{ ucfirst($adjustment->adjustment_status) }}
                    </span>
                </div>
            </div>
            
            <div class="kt-card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Order Number</label>
                        <p class="text-base mt-1">
                            @if($adjustment->order)
                                <a href="{{ route('orders.show', $adjustment->order->id) }}" class="text-blue-600 hover:underline">
                                    {{ $adjustment->order->order_number }}
                                </a>
                            @else
                                N/A
                            @endif
                        </p>
                    </div>
                    
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Customer</label>
                        <p class="text-base mt-1">
                            @if($adjustment->order && $adjustment->order->customer)
                                {{ $adjustment->order->customer->company_name }}
                            @else
                                N/A
                            @endif
                        </p>
                    </div>
                    
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Old Amount</label>
                        <p class="text-base mt-1 font-semibold text-red-600">
                            Rs. {{ number_format($adjustment->old_amount, 2) }}
                        </p>
                    </div>
                    
                    <div>
                        <label class="text-sm font-semibold text-gray-700">New Amount</label>
                        <p class="text-base mt-1 font-semibold text-green-600">
                            Rs. {{ number_format($adjustment->new_amount, 2) }}
                        </p>
                    </div>
                    
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Adjustment Amount</label>
                        <p class="text-base mt-1 font-semibold {{ $adjustment->adjustment_amount >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ $adjustment->adjustment_amount >= 0 ? '+' : '' }}Rs. {{ number_format($adjustment->adjustment_amount, 2) }}
                        </p>
                    </div>
                    
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Requested By</label>
                        <p class="text-base mt-1">
                            {{ $adjustment->requestedBy->fullname ?? 'System' }}
                        </p>
                    </div>
                    
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Requested At</label>
                        <p class="text-base mt-1">{{ $adjustment->requested_at->format('Y-m-d H:i') }}</p>
                    </div>
                    
                    @if($adjustment->completed_at)
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Completed At</label>
                        <p class="text-base mt-1">{{ $adjustment->completed_at->format('Y-m-d H:i') }}</p>
                    </div>
                    @endif
                    
                    <div class="md:col-span-2">
                        <label class="text-sm font-semibold text-gray-700">Reason</label>
                        <p class="text-base mt-1 whitespace-pre-wrap">{{ $adjustment->adjustment_reason ?? 'No reason provided' }}</p>
                    </div>

                    @if($adjustment->rejection_reason)
                    <div class="md:col-span-2">
                        <label class="text-sm font-semibold text-red-700">Rejection Reason</label>
                        <p class="text-base mt-1 text-red-600">{{ $adjustment->rejection_reason }}</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Approval Timeline -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Approval Timeline</h3>
            </div>
            
            <div class="kt-card-body">
                <!-- Level 1 Approval -->
                <div class="flex items-start gap-4 mb-6">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold
                        {{ $adjustment->level_1_status === 'approved' ? 'bg-green-500' : ($adjustment->level_1_status === 'rejected' ? 'bg-red-500' : 'bg-gray-300') }}">
                        1
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-lg">Level 1 Approval</p>
                        <p class="text-sm text-gray-600">
                            Status: <span class="font-semibold">{{ ucfirst($adjustment->level_1_status ?? 'pending') }}</span>
                        </p>
                        @if($adjustment->level1Approver)
                            <p class="text-sm mt-2">
                                <strong>{{ $adjustment->level_1_status === 'approved' ? 'Approved' : 'Rejected' }} by:</strong> 
                                {{ $adjustment->level1Approver->fullname }}
                            </p>
                            @if($adjustment->level_1_approved_at)
                            <p class="text-sm text-gray-600">{{ $adjustment->level_1_approved_at->format('Y-m-d H:i') }}</p>
                            @endif
                        @endif
                    </div>
                </div>

                <!-- Level 2 Approval -->
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold
                        {{ $adjustment->level_2_status === 'approved' ? 'bg-green-500' : ($adjustment->level_2_status === 'rejected' ? 'bg-red-500' : 'bg-gray-300') }}">
                        2
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-lg">Level 2 Approval</p>
                        <p class="text-sm text-gray-600">
                            Status: <span class="font-semibold">{{ ucfirst($adjustment->level_2_status ?? 'pending') }}</span>
                        </p>
                        @if($adjustment->level2Approver)
                            <p class="text-sm mt-2">
                                <strong>{{ $adjustment->level_2_status === 'approved' ? 'Approved' : 'Rejected' }} by:</strong> 
                                {{ $adjustment->level2Approver->fullname }}
                            </p>
                            @if($adjustment->level_2_approved_at)
                            <p class="text-sm text-gray-600">{{ $adjustment->level_2_approved_at->format('Y-m-d H:i') }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Take Action (if pending) -->
        @if($adjustment->adjustment_status === 'pending')
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Take Action</h3>
            </div>
            
            <div class="kt-card-body">
                @php
                    $user = auth()->user();
                    $hasLevel1Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
                    $hasLevel2Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
                    
                    $canApproveL1 = $hasLevel1Rights && $adjustment->level_1_status === 'pending';
                    $canApproveL2 = $hasLevel2Rights && $adjustment->level_1_status === 'approved' && $adjustment->level_2_status === 'pending';
                @endphp

                @if($canApproveL1 || $canApproveL2)
                    <form method="POST" action="{{ route('fin.ledger.adjustments.approve', $adjustment->id) }}" class="mb-4">
                        @csrf
                        <input type="hidden" name="level" value="{{ $canApproveL1 ? 1 : 2 }}">
                        
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-gray-700">Comments (Optional)</label>
                            <textarea name="comments" rows="3" class="kt-input mt-2" placeholder="Add any comments..."></textarea>
                        </div>
                        
                        <div class="flex gap-3">
                            <button type="submit" class="kt-btn kt-btn-primary">
                                <i class="ki-filled ki-check"></i>
                                Approve (Level {{ $canApproveL1 ? 1 : 2 }})
                            </button>
                            
                            <button type="button" onclick="showRejectForm()" class="kt-btn kt-btn-danger">
                                <i class="ki-filled ki-cross"></i>
                                Reject
                            </button>
                        </div>
                    </form>

                    <!-- Reject Form (Hidden by default) -->
                    <form id="rejectForm" method="POST" action="{{ route('fin.ledger.adjustments.reject', $adjustment->id) }}" style="display: none;" class="mt-4 p-4 bg-red-50 border border-red-200 rounded">
                        @csrf
                        <input type="hidden" name="level" value="{{ $canApproveL1 ? 1 : 2 }}">
                        
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-red-700">Rejection Reason (Required)</label>
                            <textarea name="comments" rows="3" class="kt-input mt-2" placeholder="Please provide a reason for rejection..." required></textarea>
                        </div>
                        
                        <div class="flex gap-3">
                            <button type="submit" class="kt-btn kt-btn-danger">
                                <i class="ki-filled ki-cross"></i>
                                Confirm Rejection
                            </button>
                            
                            <button type="button" onclick="hideRejectForm()" class="kt-btn kt-btn-light">
                                Cancel
                            </button>
                        </div>
                    </form>
                @else
                    <p class="text-gray-600">
                        @if(!$hasLevel1Rights && !$hasLevel2Rights)
                            You don't have approval rights for this adjustment.
                        @elseif($adjustment->level_1_status === 'pending' && !$hasLevel1Rights)
                            Waiting for Level 1 approval.
                        @elseif($adjustment->level_1_status === 'approved' && $adjustment->level_2_status === 'pending' && !$hasLevel2Rights)
                            Waiting for Level 2 approval.
                        @else
                            No action required.
                        @endif
                    </p>
                @endif
            </div>
        </div>
        @endif

    </div>
</div>

<script>
function showRejectForm() {
    document.getElementById('rejectForm').style.display = 'block';
}

function hideRejectForm() {
    document.getElementById('rejectForm').style.display = 'none';
}

function goBackSmart() {
    if (document.referrer && document.referrer.includes(window.location.host)) {
        window.history.back();
    } else {
        window.location.href = '{{ route("approvals.index") }}';
    }
}
</script>

@endsection

