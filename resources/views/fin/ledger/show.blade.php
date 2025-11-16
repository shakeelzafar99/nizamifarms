@extends('layouts.app')

@section('title', 'Transaction Details')

@push('demo1_css')
<style>
/* Hide sidebar and adjust layout when in iframe */
body.in-iframe .kt-sidebar,
body.in-iframe .kt-header {
    display: none !important;
}
body.in-iframe .kt-wrapper {
    padding-left: 0 !important;
}
body.in-iframe .kt-main {
    margin-left: 0 !important;
}
</style>
@endpush

@section('content')
<div class="container mx-auto px-4 py-6 max-w-4xl">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Transaction Details</h1>
            <p class="text-sm text-gray-600 mt-1">Review and approve/reject this transaction</p>
        </div>
        <a href="{{ route('approvals.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Approvals
        </a>
    </div>

    <!-- Success/Error Messages -->
    @if(session('success'))
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Transaction Card -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden mb-6">
        <!-- Status Header -->
        <div class="px-6 py-4 {{ $transaction->approval_status === 'pending' ? 'bg-yellow-50 border-b-2 border-yellow-300' : ($transaction->approval_status === 'approved' ? 'bg-green-50 border-b-2 border-green-300' : 'bg-red-50 border-b-2 border-red-300') }}">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    @if($transaction->approval_status === 'pending')
                        <svg class="w-6 h-6 text-yellow-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-lg font-semibold text-yellow-900">⏳ Pending Approval</span>
                    @elseif($transaction->approval_status === 'approved')
                        <svg class="w-6 h-6 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-lg font-semibold text-green-900">✅ Approved</span>
                    @else
                        <svg class="w-6 h-6 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-lg font-semibold text-red-900">❌ Rejected</span>
                    @endif
                </div>
                <span class="px-3 py-1 {{ $transaction->approval_status === 'pending' ? 'bg-yellow-200 text-yellow-900' : ($transaction->approval_status === 'approved' ? 'bg-green-200 text-green-900' : 'bg-red-200 text-red-900') }} text-sm font-bold rounded-full">
                    {{ strtoupper($transaction->approval_status) }}
                </span>
            </div>
        </div>

        <!-- Transaction Details -->
        <div class="px-6 py-6 space-y-6">
            <!-- Amount (Large Display) -->
            <div class="text-center py-6 bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg border-2 border-blue-200">
                <div class="text-sm font-medium text-gray-600 uppercase mb-2">Transaction Amount</div>
                <div class="text-4xl font-bold text-gray-900">Rs. {{ number_format($transaction->amount, 2) }}</div>
                <div class="text-sm text-gray-600 mt-2">
                    {{ \Carbon\Carbon::parse($transaction->transaction_date)->format('M j, Y') }}
                    @if($transaction->transaction_type === 'vendor_payment' && $transaction->posted_date)
                        <span class="ml-2 text-xs text-gray-500">• Posted: {{ \Carbon\Carbon::parse($transaction->posted_date)->format('M j, Y') }}</span>
                    @endif
                </div>
            </div>

            <!-- Flow Diagram -->
            <div class="border-2 border-gray-200 rounded-lg p-6 bg-gray-50">
                <div class="flex items-center justify-between">
                    <!-- From Account -->
                    <div class="flex-1 text-center">
                        <div class="text-xs font-medium text-gray-500 uppercase mb-2">From Account</div>
                        <div class="bg-white border-2 border-red-300 rounded-lg p-4 shadow-sm">
                            <div class="text-sm font-bold text-gray-900">{{ $transaction->fromAccount->account_name ?? 'N/A' }}</div>
                            <div class="text-xs text-gray-600 mt-1">{{ $transaction->fromAccount->account_code ?? 'N/A' }}</div>
                            <div class="text-xs text-gray-500 mt-2">Type: {{ $transaction->fromAccount->account_type ?? 'N/A' }}</div>
                        </div>
                    </div>

                    <!-- Arrow -->
                    <div class="flex-shrink-0 mx-6">
                        <svg class="w-12 h-12 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </div>

                    <!-- To Account -->
                    <div class="flex-1 text-center">
                        <div class="text-xs font-medium text-gray-500 uppercase mb-2">To Account</div>
                        <div class="bg-white border-2 border-green-300 rounded-lg p-4 shadow-sm">
                            <div class="text-sm font-bold text-gray-900">{{ $transaction->toAccount->account_name ?? 'N/A' }}</div>
                            <div class="text-xs text-gray-600 mt-1">{{ $transaction->toAccount->account_code ?? 'N/A' }}</div>
                            <div class="text-xs text-gray-500 mt-2">Type: {{ $transaction->toAccount->account_type ?? 'N/A' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description & Details -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                @php
                    $displayDescription = $transaction->description ?? 'No description';
                    // For invoices, show customer name and order number instead of "Delivered"
                    if ($transaction->transaction_type === 'invoice' && $transaction->order) {
                        // Use order's customer_name attribute which handles name priority correctly
                        $customerName = $transaction->order->customer_name ?? 'Unknown Customer';
                        $orderNumber = $transaction->order->order_number ?? '#' . $transaction->order->id;
                        $displayDescription = "Invoice {$orderNumber} - {$customerName}";
                    }
                @endphp
                <div class="text-sm font-medium text-gray-700 mb-2">{{ $displayDescription }}</div>
                <div class="flex items-center gap-4 text-xs text-gray-600">
                    @if($transaction->mode)
                        <span class="inline-flex items-center gap-1">
                            <span class="font-medium">Mode:</span> {{ ucfirst($transaction->mode) }}
                        </span>
                    @endif
                    @if($transaction->createdBy)
                        <span class="inline-flex items-center gap-1">
                            <span class="font-medium">By:</span> {{ $transaction->createdBy->fullname ?? $transaction->createdBy->name ?? 'Unknown' }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- Settlement Invoice Details (if this is a settlement) -->
            @if($transaction->settlement_metadata && isset($transaction->settlement_metadata['invoice_ids']) && is_array($transaction->settlement_metadata['invoice_ids']))
            @php
                $invoiceIds = $transaction->settlement_metadata['invoice_ids'];
                $invoices = \App\Models\FIN\LedgerModel::whereIn('id', $invoiceIds)
                    ->with('order')
                    ->orderBy('transaction_date', 'asc')
                    ->get();
            @endphp
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/>
                        <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-blue-900">📦 Invoices Included in Settlement</h3>
                    <span class="ml-auto px-2 py-1 bg-blue-200 text-blue-900 text-xs font-bold rounded-full">{{ $invoices->count() }} invoice(s)</span>
                </div>
                <div class="bg-white border border-blue-200 rounded-lg overflow-hidden">
                    <table class="min-w-full divide-y divide-blue-200">
                        <thead class="bg-blue-100">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-blue-900">#</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-blue-900">Invoice/Order #</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-blue-900">Date</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-blue-900">Amount</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-blue-900">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-blue-100">
                            @foreach($invoices as $index => $invoice)
                            <tr class="hover:bg-blue-50">
                                <td class="px-3 py-2 text-xs text-gray-600">{{ $index + 1 }}</td>
                                <td class="px-3 py-2 text-sm font-medium text-gray-900">
                                    @if($invoice->order)
                                        <a href="{{ route('orders.show', $invoice->order->id) }}" class="text-blue-600 hover:text-blue-800 hover:underline">
                                            {{ $invoice->order->order_number }}
                                        </a>
                                    @else
                                        Invoice #{{ $invoice->id }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-600">{{ \Carbon\Carbon::parse($invoice->transaction_date)->format('M j, Y') }}</td>
                                <td class="px-3 py-2 text-sm text-right font-medium text-gray-900">Rs. {{ number_format($invoice->amount, 2) }}</td>
                                <td class="px-3 py-2 text-xs text-right">
                                    @if($invoice->settlement_status === 'settled')
                                        <span class="inline-flex items-center px-2 py-1 bg-green-100 text-green-800 rounded-full font-semibold">✓ Settled</span>
                                    @elseif($invoice->settled_amount > 0)
                                        <span class="inline-flex items-center px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full font-semibold">⚡ Partial</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-800 rounded-full font-semibold">○ Open</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-blue-100">
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-sm font-semibold text-blue-900">Total</td>
                                <td class="px-3 py-2 text-sm text-right font-bold text-blue-900">Rs. {{ number_format($invoices->sum('amount'), 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if($transaction->settlement_metadata['total_outstanding'] ?? false)
                <div class="mt-3 text-xs text-blue-800">
                    <span class="font-medium">Total Outstanding at Settlement:</span> Rs. {{ number_format($transaction->settlement_metadata['total_outstanding'], 2) }}
                </div>
                @endif
            </div>
            @endif

            <!-- Approval Details (if approved/rejected) -->
            @if($transaction->approval_status !== 'pending')
            <div class="border-t border-gray-200 pt-4 mt-4">
                <div class="flex items-center justify-between text-sm">
                    @if($transaction->approvedBy)
                    <div>
                        <span class="text-gray-600">{{ $transaction->approval_status === 'approved' ? 'Approved by' : 'Rejected by' }}:</span>
                        <span class="font-medium text-gray-900 ml-1">{{ $transaction->approvedBy->fullname ?? $transaction->approvedBy->name ?? 'Unknown' }}</span>
                    </div>
                    @endif

                    @if($transaction->approval_date)
                    <div class="text-gray-600">
                        {{ \Carbon\Carbon::parse($transaction->approval_date)->format('M d, Y') }}
                    </div>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>

    <!-- Approval Actions (Only if pending at any level) -->
    @if(in_array($transaction->approval_status, ['pending', 'pending_l1', 'pending_l2']))
    <div class="bg-white border-2 border-blue-300 rounded-lg shadow-md p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">⚡ Take Action</h2>
        
        <form id="approvalForm" method="POST" action="{{ route('fin.ledger.approve', $transaction->id) }}">
            @csrf
            @if(request('origin') === 'approvals')
                <input type="hidden" name="_origin" value="approvals">
            @endif
            
            <div class="mb-6">
                <label for="approval_notes" class="block text-sm font-medium text-gray-700 mb-2">
                    Approval Notes (Optional)
                </label>
                <textarea id="approval_notes" name="approval_notes" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                          placeholder="Add any notes about this approval..."></textarea>
            </div>

            <div class="flex gap-4">
                <button type="submit" 
                        id="approveBtn"
                        class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-green-600 hover:bg-green-700 text-white text-base font-semibold rounded-lg transition shadow-md hover:shadow-lg">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    <span id="approveBtnText">✅ Approve Transaction</span>
                    <span id="approveBtnLoading" style="display: none;">
                        <svg class="animate-spin h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Processing...
                    </span>
                </button>
                
                <button type="button" onclick="rejectTransaction()"
                        id="rejectBtn"
                        class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-red-600 hover:bg-red-700 text-white text-base font-semibold rounded-lg transition shadow-md hover:shadow-lg">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                    </svg>
                    <span id="rejectBtnText">❌ Reject Transaction</span>
                    <span id="rejectBtnLoading" style="display: none;">
                        <svg class="animate-spin h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Processing...
                    </span>
                </button>
            </div>
        </form>
    </div>
    @endif
</div>

<script>
// Check if we're in an iframe (from approvals dashboard)
const isInIframe = window.parent && window.parent !== window;

// Add class to body if in iframe to hide sidebar/header
if (isInIframe) {
    document.body.classList.add('in-iframe');
}

// Handle form submission
document.getElementById('approvalForm').addEventListener('submit', function(e) {
    if (isInIframe) {
        e.preventDefault(); // Prevent normal form submission
        
        const form = this;
        const formData = new FormData(form);
        const approveBtn = document.getElementById('approveBtn');
        const approveBtnText = document.getElementById('approveBtnText');
        const approveBtnLoading = document.getElementById('approveBtnLoading');
        const rejectBtn = document.getElementById('rejectBtn');
        
        // Show loading state
        approveBtn.disabled = true;
        rejectBtn.disabled = true;
        approveBtnText.style.display = 'none';
        approveBtnLoading.style.display = 'inline-flex';
        
        // Submit via AJAX
        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            // Notify parent to close modal and refresh
            window.parent.postMessage({ 
                type: 'approval_complete',
                success: true,
                message: data.message || 'Transaction approved successfully!'
            }, '*');
        })
        .catch(error => {
            console.error('Error:', error);
            // Reset button state
            approveBtn.disabled = false;
            rejectBtn.disabled = false;
            approveBtnText.style.display = 'inline';
            approveBtnLoading.style.display = 'none';
            alert('An error occurred. Please try again.');
        });
    }
    // If not in iframe, let form submit normally
});

function rejectTransaction() {
    if (!confirm('Are you sure you want to REJECT this transaction? This action cannot be undone.')) {
        return;
    }
    
    const form = document.getElementById('approvalForm');
    form.action = '{{ route('fin.ledger.reject', $transaction->id) }}';
    
    if (isInIframe) {
        const formData = new FormData(form);
        const rejectBtn = document.getElementById('rejectBtn');
        const rejectBtnText = document.getElementById('rejectBtnText');
        const rejectBtnLoading = document.getElementById('rejectBtnLoading');
        const approveBtn = document.getElementById('approveBtn');
        
        // Show loading state
        rejectBtn.disabled = true;
        approveBtn.disabled = true;
        rejectBtnText.style.display = 'none';
        rejectBtnLoading.style.display = 'inline-flex';
        
        // Submit via AJAX
        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            // Notify parent to close modal and refresh
            window.parent.postMessage({ 
                type: 'approval_complete',
                success: true,
                message: data.message || 'Transaction rejected successfully!'
            }, '*');
        })
        .catch(error => {
            console.error('Error:', error);
            // Reset button state
            rejectBtn.disabled = false;
            approveBtn.disabled = false;
            rejectBtnText.style.display = 'inline';
            rejectBtnLoading.style.display = 'none';
            alert('An error occurred. Please try again.');
        });
    } else {
        form.submit();
    }
}

// If this page is loaded normally (not iframe) and has success message, handle redirect
@if(session('success'))
if (!isInIframe) {
    // Normal redirect handling for non-iframe loads
}
@endif
</script>
@endsection

