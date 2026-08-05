{{-- resources/views/pages/requests/show.blade.php --}}

@extends('layouts.app')

@section('title', 'Request Details')

@section('content')

<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        
        <!-- Request Details Card -->
        <div class="kt-card">
            <div class="kt-card-header">
                <div class="flex items-center gap-4">
                    <a href="#" onclick="goBackSmart(); return false;" class="kt-btn kt-btn-sm kt-btn-light">
                        <i class="ki-filled ki-left"></i>
                    </a>
                    <h3 class="kt-card-title">Request #{{ $request->request_number }}</h3>
                </div>
                <div class="kt-card-toolbar flex gap-2">
                    @php
                        $statusClasses = [
                            'pending' => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            'cancelled' => 'secondary'
                        ];
                        $statusClass = $statusClasses[$request->status] ?? 'secondary';
                    @endphp
                    <span class="kt-badge kt-badge-{{ $statusClass }}">
                        {{ ucfirst($request->status) }}
                    </span>
                    
                    @if($request->priority === 'urgent')
                    <span class="kt-badge kt-badge-danger">
                        <i class="ki-filled ki-notification-on"></i> Urgent
                    </span>
                    @elseif($request->priority === 'high')
                    <span class="kt-badge kt-badge-warning">
                        High Priority
                    </span>
                    @endif
                </div>
            </div>
            
            <div class="kt-card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Category</label>
                        <p class="text-base mt-1">{{ $request->category->category_name }}</p>
                    </div>
                    
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Requester (Employee)</label>
                        <p class="text-base mt-1">{{ $request->requester->fullname }}</p>
                        @if($request->created_by !== $request->requester_user_id)
                        <p class="text-xs text-blue-600 mt-1">
                            <i class="ki-filled ki-information-2"></i>
                            Created by {{ $request->createdBy->fullname ?? 'Manager' }} on behalf of employee
                        </p>
                        @endif
                    </div>
                    
                    @if($request->submitted_at)
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Submitted</label>
                        <p class="text-base mt-1">{{ $request->submitted_at->format('Y-m-d H:i') }}</p>
                    </div>
                    @endif
                    
                    @if($request->completed_at)
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Completed</label>
                        <p class="text-base mt-1">{{ $request->completed_at->format('Y-m-d H:i') }}</p>
                    </div>
                    @endif

                    @if($request->leave_start_date)
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Leave Period</label>
                        <p class="text-base mt-1">
                            {{ $request->leave_start_date->format('Y-m-d') }} to {{ $request->leave_end_date->format('Y-m-d') }}
                        </p>
                        <p class="text-sm text-gray-600">{{ $request->leave_days }} day(s)</p>
                    </div>
                    
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Leave Type</label>
                        <p class="text-base mt-1">{{ ucfirst($request->leave_type) }}</p>
                    </div>
                    @endif

                    @if($request->amount)
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Amount</label>
                        <p class="text-base mt-1 font-semibold">Rs. {{ number_format($request->amount, 2) }}</p>
                    </div>
                    @endif
                    
                    @if($request->expense_category)
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Expense Category</label>
                        <p class="text-base mt-1">{{ $request->expense_category }}</p>
                    </div>
                    @endif
                    
                    @if($request->payment_source_account_id && $request->paymentSourceAccount)
                    <div>
                        <label class="text-sm font-semibold text-gray-700">💳 Payment Source</label>
                        <p class="text-base mt-1">
                            {{ $request->paymentSourceAccount->account_name }}
                            <span class="text-xs text-gray-600">(Selected by requester)</span>
                        </p>
                    </div>
                    @endif
                    
                    <div class="md:col-span-2">
                        <label class="text-sm font-semibold text-gray-700">Title</label>
                        <p class="text-base mt-1">{{ $request->title }}</p>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="text-sm font-semibold text-gray-700">Description</label>
                        <p class="text-base mt-1 whitespace-pre-wrap">{{ $request->description }}</p>
                    </div>

                    {{-- Fuel / expense details captured on the mobile request (Jul-2026).
                         Shown here because this page is ALSO what the Approvals screen
                         loads in its frame — before this, an approver could never see
                         the odometer or the receipt they were approving. --}}
                    @if($request->meter_distance || $request->meter_at_fill || $request->litres)
                    <div class="md:col-span-2">
                        <label class="text-sm font-semibold text-gray-700">⛽ Fuel details</label>
                        <p class="text-base mt-1">
                            @if($request->meter_distance)
                                <span>{{ number_format($request->meter_distance) }} km ridden
                                @if($request->petrol_rate) × Rs {{ $request->petrol_rate }}/km @endif</span>
                            @endif
                            @if($request->meter_at_fill)
                                <span class="ml-3">Meter at fill: <strong>{{ number_format($request->meter_at_fill) }} km</strong></span>
                            @endif
                            @if($request->litres)
                                <span class="ml-3">{{ $request->litres }} litres</span>
                            @endif
                        </p>
                    </div>
                    @endif

                    @php
                        // attachments is a JSON array of storage-relative paths; the
                        // /public-storage/{path} route streams them.
                        $reqAttachments = collect((array) ($request->attachments ?? []))
                            ->filter()->map(fn ($p) => '/public-storage/' . ltrim($p, '/'))->values();
                    @endphp
                    @if($reqAttachments->isNotEmpty())
                    <div class="md:col-span-2">
                        <label class="text-sm font-semibold text-gray-700">📷 Attached photo</label>
                        <div class="mt-2" style="display:flex; gap:10px; flex-wrap:wrap;">
                            @foreach($reqAttachments as $url)
                            <img src="{{ $url }}" alt="Attachment"
                                 onclick="reqOpenPhoto('{{ $url }}')"
                                 style="width:130px; height:130px; object-fit:cover; border:1px solid #d1d5db; border-radius:8px; cursor:zoom-in; background:#f3f4f6;">
                            @endforeach
                        </div>
                        <p class="text-xs mt-1" style="color:#6b7280;">Click to enlarge.</p>
                    </div>
                    @endif

                    @if($request->rejection_reason)
                    <div class="md:col-span-2">
                        <label class="text-sm font-semibold text-red-700">Rejection Reason</label>
                        <p class="text-base mt-1 text-red-600">{{ $request->rejection_reason }}</p>
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
                @if($request->requires_level_1)
                <div class="flex items-start gap-4 mb-6">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold
                        {{ $request->level_1_status === 'approved' ? 'bg-green-500' : ($request->level_1_status === 'rejected' ? 'bg-red-500' : 'bg-gray-300') }}">
                        1
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-lg">Level 1 Approval</p>
                        <p class="text-sm text-gray-600">
                            Status: <span class="font-semibold">{{ ucfirst($request->level_1_status ?? 'pending') }}</span>
                        </p>
                        @if($request->getLevel1Approver())
                            @php $l1Approval = $request->getLevel1Approver(); @endphp
                            <p class="text-sm mt-2">
                                <strong>{{ $l1Approval->status === 'approved' ? 'Approved' : 'Rejected' }} by:</strong> 
                                {{ $l1Approval->approver->fullname }}
                            </p>
                            <p class="text-sm text-gray-600">{{ $l1Approval->action_date->format('Y-m-d H:i') }}</p>
                            @if($l1Approval->comments)
                            <p class="text-sm mt-1 italic text-gray-700">"{{ $l1Approval->comments }}"</p>
                            @endif
                        @endif
                    </div>
                </div>
                @endif

                @if($request->requires_level_2)
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold
                        {{ $request->level_2_status === 'approved' ? 'bg-green-500' : ($request->level_2_status === 'rejected' ? 'bg-red-500' : 'bg-gray-300') }}">
                        2
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-lg">Level 2 Approval</p>
                        <p class="text-sm text-gray-600">
                            Status: <span class="font-semibold">{{ ucfirst($request->level_2_status ?? 'pending') }}</span>
                        </p>
                        @if($request->getLevel2Approver())
                            @php $l2Approval = $request->getLevel2Approver(); @endphp
                            <p class="text-sm mt-2">
                                <strong>{{ $l2Approval->status === 'approved' ? 'Approved' : 'Rejected' }} by:</strong> 
                                {{ $l2Approval->approver->fullname }}
                            </p>
                            <p class="text-sm text-gray-600">{{ $l2Approval->action_date->format('Y-m-d H:i') }}</p>
                            @if($l2Approval->comments)
                            <p class="text-sm mt-1 italic text-gray-700">"{{ $l2Approval->comments }}"</p>
                            @endif
                        @endif
                    </div>
                </div>
                @endif

                @if(!$request->requires_level_1 && !$request->requires_level_2)
                <p class="text-gray-600">No approval required for this request.</p>
                @endif
            </div>
        </div>

        <!-- Approval Actions -->
        @if($canApproveLevel1 || $canApproveLevel2)
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Take Action</h3>
            </div>
            
            <div class="kt-card-body">
                <form id="approval-form">
                    @csrf
                    <input type="hidden" name="level" value="{{ $canApproveLevel1 ? 1 : 2 }}">
                    
                    @php
                        // Check if this is an expense or salary advance request and if user is final approver
                        $isExpenseRequest = in_array($request->category->category_code, ['expense', 'salary_advance']) && $request->amount > 0;
                        $isFinalApproval = ($canApproveLevel2 && $request->requires_level_2) || 
                                          ($canApproveLevel1 && !$request->requires_level_2);
                        
                        // 💳 Where the money comes out of.
                        // ⭐ Aug-2026: this used to be its own hardcoded list — three
                        // account codes PLUS every employee-cash account in the
                        // company, offered to any final approver. It now comes from
                        // PaymentSourceService, so the approver sees exactly what he
                        // is tagged for in THIS request's business unit, and an
                        // employee-cash account only if it is his own and tagged.
                        $paymentSources = collect();
                        $payBanks = [];
                        if ($isExpenseRequest && $isFinalApproval) {
                            $paySvc = app(\App\Services\FIN\PaymentSourceService::class);
                            $paymentSources = collect($paySvc->sourcesFor(
                                auth()->user(),
                                $request->business_unit_id ?: 1,
                                ($request->category->category_code ?? null) === 'salary_advance'
                                    ? \App\Services\FIN\PaymentSourceService::PURPOSE_ADVANCE
                                    : \App\Services\FIN\PaymentSourceService::PURPOSE_EXPENSE
                            ));
                            $payBanks = $paySvc->banks();
                        }
                    @endphp
                    
                    @if($isExpenseRequest && $isFinalApproval && $paymentSources->count() > 0)
                    @php
                        // Pre-select what was FILED (if this approver may use it),
                        // else this approver's own default. Your pick still wins —
                        // you just start from what the filer said, not from
                        // whatever happens to be first in the list.
                        $filedId    = $request->payment_source_account_id;
                        $filedUsable = $filedId && $paymentSources->firstWhere('id', (int) $filedId);
                        $preselectId = $filedUsable
                            ? (int) $filedId
                            : (optional($paymentSources->firstWhere('is_default', true))['id'] ?? null);
                    @endphp
                    <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded">
                        <label class="kt-label font-semibold text-blue-900">💰 Payment Source</label>
                        <select name="payment_source_account_id" id="approve_payment_source" class="kt-select mt-2" onchange="approveSourceChanged()">
                            @foreach($paymentSources as $account)
                                <option value="{{ $account['id'] }}"
                                    data-online="{{ $account['is_online'] ? '1' : '0' }}"
                                    data-bank="{{ $account['preferred_bank_id'] ?? '' }}"
                                    {{ (int) $account['id'] === $preselectId ? 'selected' : '' }}>
                                    {{ $account['display_name'] ?: $account['name'] }}
                                    (Balance: Rs. {{ number_format($account['balance'], 2) }})
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-blue-700 mt-2">
                            ℹ️ Select which account to pay this expense from.
                            @if($filedId && $request->paymentSourceAccount)
                                <strong>Filed as: {{ $request->paymentSourceAccount->account_name }}</strong>
                                @unless($filedUsable) <span class="text-red-600">— you are not set up to use that account, so pick another.</span>@endunless
                            @else
                                Nothing was chosen when this was filed, so your pick decides.
                            @endif
                        </p>

                        {{-- A bank source must name the bank it left from, or the
                             per-bank balances drift. The create side has demanded
                             this since Jul-2026; approval is now the main place the
                             account gets chosen, so it demands it too. --}}
                        <div id="approve-bank-wrap" class="mt-3" style="display:none;">
                            <label class="kt-label font-semibold text-blue-900">🏦 From which bank</label>
                            <select name="receiving_account_id" id="approve_receiving_account" class="kt-select mt-1" disabled>
                                <option value="">Select the bank…</option>
                                @foreach($payBanks as $bank)
                                    <option value="{{ $bank['id'] }}"
                                        {{ (int) ($request->receiving_account_id ?? 0) === (int) $bank['id'] ? 'selected' : '' }}>
                                        {{ $bank['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <script>
                    // Disabled (not merely hidden) so a cash-funded approval never
                    // submits a bank id at all.
                    function approveSourceChanged() {
                        var sel  = document.getElementById('approve_payment_source');
                        var wrap = document.getElementById('approve-bank-wrap');
                        var bank = document.getElementById('approve_receiving_account');
                        if (!sel || !wrap || !bank) return;
                        var opt = sel.options[sel.selectedIndex];
                        var need = !!(opt && opt.dataset.online === '1') && bank.options.length > 1;
                        wrap.style.display = need ? 'block' : 'none';
                        bank.disabled = !need;
                        bank.required = need;
                        if (!need) { bank.value = ''; return; }
                        if (!bank.value && opt.dataset.bank) { bank.value = opt.dataset.bank; }
                    }
                    approveSourceChanged();
                    </script>
                    @endif
                    
                    <div class="mb-4">
                        <label class="kt-label">Comments</label>
                        <textarea name="comments" class="kt-input" rows="3" placeholder="Enter your comments (required for rejection)"></textarea>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="button" onclick="approveRequest()" class="kt-btn kt-btn-success">
                            <i class="ki-filled ki-check"></i> Approve
                        </button>
                        <button type="button" onclick="rejectRequest()" class="kt-btn kt-btn-danger">
                            <i class="ki-filled ki-cross"></i> Reject
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @endif

        <!-- Cancel Button (for requester on pending requests) -->
        @if($request->requester_user_id === auth()->id() && $request->isPending())
        <div class="kt-card">
            <div class="kt-card-body">
                <button onclick="cancelRequest()" class="kt-btn kt-btn-light">
                    <i class="ki-filled ki-cross-circle"></i> Cancel This Request
                </button>
            </div>
        </div>
        @endif
    </div>
</div>

<script>
function approveRequest() {
    const form = document.getElementById('approval-form');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    if (!confirm('Are you sure you want to approve this request?')) {
        return;
    }
    
    fetch('{{ route("requests.approve", $request->id) }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Request approved successfully!');
            goBackSmart();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

function rejectRequest() {
    const comments = document.querySelector('[name="comments"]').value.trim();
    
    if (!comments) {
        alert('Please enter comments explaining the rejection.');
        document.querySelector('[name="comments"]').focus();
        return;
    }
    
    if (!confirm('Are you sure you want to reject this request?')) {
        return;
    }
    
    const form = document.getElementById('approval-form');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    fetch('{{ route("requests.reject", $request->id) }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Request rejected.');
            goBackSmart();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

function cancelRequest() {
    if (!confirm('Are you sure you want to cancel this request?')) {
        return;
    }
    
    fetch('{{ route("requests.cancel", $request->id) }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Request cancelled successfully.');
            window.location.href = '{{ route("requests.index") }}';
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

function goBackSmart() {
    const referrer = document.referrer;
    
    // Check if coming from approvals page
    if (referrer && referrer.includes('/approvals')) {
        window.location.href = '{{ session("approvals_return_url", route("approvals.index")) }}';
    }
    // Check if coming from employee cash page
    else if (referrer && referrer.includes('/finance/employee/')) {
        window.history.back();
    }
    // Check if coming from expense management page
    else if (referrer && referrer.includes('/finance/expenses')) {
        window.location.href = '{{ route("fin.expenses.index") }}';
    }
    // Default: go to requests index
    else {
        window.location.href = '{{ route("requests.index") }}';
    }
}

// If this page is loaded in an iframe (from approvals dashboard), notify parent on successful action
@if(session('success'))
if (window.parent && window.parent !== window) {
    // Give a small delay to ensure user sees the success message
    setTimeout(function() {
        window.parent.postMessage({ type: 'approval_complete' }, '*');
    }, 1500);
}
@endif

// Attachment lightbox. The shell is inline-styled on purpose: the utility
// classes this project's CSS build once provided (inset-0, max-h-*, flex-*)
// were purged, so a class-based overlay renders in the top-left corner and
// cannot scroll. Inline styles are the reliable option here.
function reqOpenPhoto(url) {
    var box = document.getElementById('reqPhotoLightbox');
    document.getElementById('reqPhotoLightboxImg').src = url;
    box.style.display = 'flex';
}
function reqClosePhoto() {
    var box = document.getElementById('reqPhotoLightbox');
    box.style.display = 'none';
    document.getElementById('reqPhotoLightboxImg').src = '';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') reqClosePhoto();
});
</script>

<div id="reqPhotoLightbox" onclick="reqClosePhoto()"
     style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:5000;
            background:rgba(0,0,0,.78); align-items:center; justify-content:center;">
    <button onclick="reqClosePhoto()" title="Close"
            style="position:absolute; top:14px; right:20px; background:none; border:none;
                   color:#fff; font-size:30px; line-height:1; cursor:pointer;">&times;</button>
    <img id="reqPhotoLightboxImg" src="" alt="Attachment"
         style="max-width:92vw; max-height:88vh; border-radius:8px; background:#fff;
                box-shadow:0 12px 44px rgba(0,0,0,.5);">
</div>

@endsection

