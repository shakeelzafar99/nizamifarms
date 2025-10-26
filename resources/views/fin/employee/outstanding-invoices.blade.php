@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    <!-- Compact Header & Filters in One Row -->
    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 rounded-lg shadow-lg p-4 mb-4">
        <form method="GET" action="{{ route('fin.employee.all-outstanding-invoices') }}" class="flex flex-wrap items-center gap-3">
            <div class="flex-shrink-0">
                <h1 class="text-lg font-bold text-white">📊 Invoice Tracker</h1>
            </div>
            <select name="rider" style="color: #1f2937 !important; background-color: white !important;" class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
                <option value="all">All Riders</option>
                @foreach($allRiders as $rider)
                <option value="{{ $rider->id }}" {{ $filters['rider'] == $rider->id ? 'selected' : '' }}>{{ $rider->account_name }}</option>
                @endforeach
            </select>
            <input type="date" name="date_from" value="{{ $filters['date_from'] }}" 
                   style="color: #1f2937 !important; background-color: white !important;" 
                   class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
            <input type="date" name="date_to" value="{{ $filters['date_to'] }}"
                   style="color: #1f2937 !important; background-color: white !important;" 
                   class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
            <button type="submit" style="background-color: white !important; color: #7c3aed !important;" class="px-3 py-1.5 font-medium text-xs rounded-md hover:opacity-90 transition-opacity">
                Apply
            </button>
            <input type="hidden" name="status" id="status-filter" value="{{ $filters['status'] }}">
            <div class="ml-auto">
                <a href="{{ route('fin.employee.index') }}" style="background-color: rgba(255, 255, 255, 0.2) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md hover:opacity-90 transition-opacity">
                    ← Back
                </a>
            </div>
        </form>
    </div>

    {{-- Flash messages for approve/reject actions --}}
    @if(session('success'))
    <div class="mb-3 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @elseif(session('error'))
    <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
        {{ session('error') }}
    </div>
    @endif

    <!-- Compact Statistics Cards -->
    <div class="grid grid-cols-3 gap-2 mb-4">
        <!-- Row 1: Invoice Cards -->
        <div class="grid grid-cols-4 gap-2 col-span-3">
            <!-- Open Invoices Card -->
            <button type="button" onclick="filterByStatus('open')" 
                    class="stat-card text-left p-3 rounded-md shadow border-2 transition-all {{ $filters['status'] == 'open' ? 'border-red-500 bg-red-50' : 'border-gray-200 bg-white hover:border-red-300' }}">
                <div class="text-xs font-bold text-red-700 mb-1">🔴 OPEN</div>
                <div class="text-xl font-bold text-red-900">{{ $stats['open_count'] }}</div>
                <div class="text-xs font-semibold text-red-600">Rs. {{ number_format($stats['open_total'], 2) }}</div>
            </button>

            <!-- Partial Invoices Card -->
            <button type="button" onclick="filterByStatus('partial')" 
                    class="stat-card text-left p-3 rounded-md shadow border-2 transition-all {{ $filters['status'] == 'partial' ? 'border-yellow-500 bg-yellow-50' : 'border-gray-200 bg-white hover:border-yellow-300' }}">
                <div class="text-xs font-bold text-yellow-700 mb-1">🟡 PARTIAL</div>
                <div class="text-xl font-bold text-yellow-900">{{ $stats['partial_count'] }}</div>
                <div class="text-xs font-semibold text-yellow-600">Rs. {{ number_format($stats['partial_total'], 2) }}</div>
            </button>

            <!-- Pending Settlements Card -->
            <button type="button" onclick="togglePendingSettlements()" 
                    class="stat-card text-left p-3 rounded-md shadow border-2 transition-all border-gray-200 bg-white hover:border-blue-300">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs font-bold text-blue-700">⏳ PENDING</div>
                    @if($stats['pending_settlement_count'] > 0)
                    <span class="animate-pulse text-xs bg-blue-600 text-white px-1.5 py-0.5 rounded-full">{{ $stats['pending_settlement_count'] }}</span>
                    @endif
                </div>
                <div class="text-xl font-bold text-blue-900">{{ $stats['pending_settlement_count'] }}</div>
                <div class="text-xs font-semibold text-blue-600">Rs. {{ number_format($stats['pending_settlement_total'], 2) }}</div>
            </button>

            <!-- Total Outstanding Card -->
            <button type="button" onclick="filterByStatus('all')" 
                    class="stat-card text-left p-3 rounded-md shadow border-2 transition-all {{ $filters['status'] == 'all' ? 'border-purple-500 bg-purple-50' : 'border-gray-200 bg-white hover:border-purple-300' }}">
                <div class="text-xs font-bold text-purple-700 mb-1">📊 TOTAL</div>
                <div class="text-xl font-bold text-purple-900">{{ $stats['open_count'] + $stats['partial_count'] }}</div>
                <div class="text-xs font-semibold text-purple-600">Rs. {{ number_format($stats['total_outstanding'], 2) }}</div>
            </button>
        </div>
        
        <!-- Row 2: NEW Expense Management Cards -->
        <div class="grid grid-cols-2 gap-2 col-span-3">
            <!-- Pending Approvals Card (Awaiting Approval) -->
            <div class="stat-card text-left p-3 rounded-md shadow border-2 border-gray-200 bg-yellow-50 transition-all">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs font-bold text-yellow-700">⏳ PENDING</div>
                    @if($stats['pending_approvals_count'] > 0)
                    <span class="text-xs bg-yellow-600 text-white px-1.5 py-0.5 rounded-full">{{ $stats['pending_approvals_count'] }}</span>
                    @endif
                </div>
                <div class="text-sm text-gray-600 mb-1">Awaiting approval</div>
                <div class="text-xl font-bold text-yellow-900">Rs. {{ number_format($stats['pending_approvals_amount'], 2) }}</div>
            </div>

            <!-- Short Cash Card (Unsettled) -->
            <div class="stat-card text-left p-3 rounded-md shadow border-2 border-gray-200 bg-green-50 transition-all">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs font-bold text-green-700">💸 SHORT CASH</div>
                    @if($stats['short_cash_count'] > 0)
                    <span class="text-xs bg-green-600 text-white px-1.5 py-0.5 rounded-full">{{ $stats['short_cash_count'] }}</span>
                    @endif
                </div>
                <div class="text-sm text-gray-600 mb-1">Unsettled</div>
                <div class="text-xl font-bold text-green-900">Rs. {{ number_format($stats['short_cash_amount'], 2) }}</div>
            </div>
        </div>
    </div>

    <!-- No separate pending settlements section - they'll be shown inline with invoices -->

    <!-- Invoices Section -->
    @if($invoicesByRider->isEmpty())
    <!-- No Invoices State -->
    <div class="bg-white rounded-lg shadow-sm p-16 text-center">
        <div class="text-6xl mb-4">
            @if($filters['status'] == 'settled')
                📦
            @else
                ✅
            @endif
        </div>
        <h2 class="text-2xl font-bold text-gray-800 mb-2">
            @if($filters['status'] == 'settled')
                No Settled Invoices
            @else
                No Outstanding Invoices
            @endif
        </h2>
        <p class="text-gray-600 mb-4">
            @if($filters['status'] == 'settled')
                No invoices have been settled in the selected period.
            @else
                All invoices are settled! Great job keeping up with payments.
            @endif
        </p>
        <button onclick="filterByStatus('all')" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-md">
            View All Invoices
        </button>
    </div>
    @else
    <!-- Invoices by Rider -->
    <div class="space-y-3">
        @foreach($invoicesByRider as $riderData)
        <div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
            <!-- Rider Header -->
            <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center border-2 border-white">
                        <span class="text-white font-bold text-lg">
                            {{ substr($riderData['account']->account_name, 0, 1) }}
                        </span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $riderData['account']->account_name }}</h3>
                        <p class="text-xs text-purple-100">{{ $riderData['account']->account_code }} • {{ $riderData['invoice_count'] }} invoice(s)</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-xs text-purple-100">Total Outstanding</p>
                    <p class="text-2xl font-bold text-white">Rs. {{ number_format($riderData['total_outstanding'], 2) }}</p>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="overflow-x-auto">
                @if($filters['status'] == 'settled' && $riderData['invoices_by_date'])
                    <!-- Day-Grouped View for Settled Invoices -->
                    @foreach($riderData['invoices_by_date'] as $date => $dayData)
                    <div class="mb-2 last:mb-0">
                        <!-- Day Header with Total -->
                        <div style="background: linear-gradient(to right, #dcfce7, #bbf7d0) !important;" class="px-4 py-2 border-b-2 border-green-300 flex justify-between items-center">
                            <div>
                                <span class="text-sm font-bold text-green-900">📅 {{ \Carbon\Carbon::parse($date)->format('l, M j, Y') }}</span>
                                <span class="text-xs text-green-700 ml-2">({{ $dayData['count'] }} invoice{{ $dayData['count'] > 1 ? 's' : '' }})</span>
                            </div>
                            <div class="text-sm font-bold text-green-900">
                                Day Total: Rs. {{ number_format($dayData['day_total'], 2) }}
                            </div>
                        </div>
                        
                        <!-- Invoices for this day -->
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead style="background-color: #f9fafb !important;">
                                <tr>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Order #</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Invoice Date</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Description</th>
                                    <th class="px-3 py-1.5 text-right text-xs font-semibold text-gray-600 uppercase">Amount</th>
                                    <th class="px-3 py-1.5 text-right text-xs font-semibold text-gray-600 uppercase">Settled</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-50">
                                @foreach($dayData['invoices'] as $invoice)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="text-xs font-bold text-purple-700">{{ $invoice->order ? $invoice->order->order_number : 'N/A' }}</span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                        {{ $invoice->transaction_date->format('M j') }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600 max-w-xs truncate">
                                        {{ $invoice->description }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-right font-semibold text-gray-900">
                                        Rs. {{ number_format($invoice->amount, 2) }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                        <span class="text-green-700 font-medium">Rs. {{ number_format($invoice->settled_amount, 2) }}</span>
                                        @if(isset($invoice->settlement_breakdown) && $invoice->settlement_breakdown)
                                            <div class="text-xs text-blue-600 mt-1" style="white-space: nowrap;">
                                                💸 Rs. {{ number_format($invoice->settlement_breakdown['deposit_amount'], 0) }} + 
                                                Rs. {{ number_format($invoice->settlement_breakdown['expense_amount'], 0) }} ({{ $invoice->settlement_breakdown['expense_category'] }})
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endforeach
                @else
                    <!-- Standard View for Open/Partial Invoices -->
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Order #</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Description</th>
                                <th class="px-3 py-2 text-center text-xs font-semibold text-gray-700 uppercase">Status</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Amount</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Settled</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($riderData['invoices'] as $invoice)
                            <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-3 py-2 whitespace-nowrap">
                                <span class="text-xs font-bold text-purple-700">{{ $invoice['order_number'] }}</span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                {{ $invoice['transaction_date']->format('M j, Y') }}
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-600 max-w-xs truncate">
                                {{ $invoice['description'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-center">
                                @if($invoice['is_pending_approval'])
                                    <span class="px-2 py-0.5 text-xs font-bold bg-amber-100 text-amber-800 rounded-full animate-pulse">
                                        💰 Deposit Pending
                                    </span>
                                @elseif($invoice['settlement_status'] === 'settled')
                                    <span class="px-2 py-0.5 text-xs font-bold bg-green-100 text-green-800 rounded-full">
                                        ✅ Settled
                                    </span>
                                @elseif($invoice['settled_amount'] > 0)
                                    <span class="px-2 py-0.5 text-xs font-bold bg-yellow-100 text-yellow-800 rounded-full">
                                        🟡 Partial
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-bold bg-red-100 text-red-800 rounded-full">
                                        🔴 Open
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right font-semibold text-gray-900">
                                Rs. {{ number_format($invoice['amount'], 2) }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                @if($invoice['settled_amount'] > 0)
                                    <span class="text-green-700 font-medium">Rs. {{ number_format($invoice['settled_amount'], 2) }}</span>
                                    @if($invoice['settled_at'])
                                        <div class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($invoice['settled_at'])->format('M j') }}</div>
                                    @endif
                                    @if(isset($invoice['settlement_breakdown']) && $invoice['settlement_breakdown'])
                                        <div class="text-xs text-blue-600 mt-1" style="white-space: nowrap;">
                                            💸 Rs. {{ number_format($invoice['settlement_breakdown']['deposit_amount'], 0) }} + 
                                            Rs. {{ number_format($invoice['settlement_breakdown']['expense_amount'], 0) }} ({{ $invoice['settlement_breakdown']['expense_category'] }})
                                        </div>
                                    @endif
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                @if($invoice['outstanding_amount'] > 0)
                                    <span class="font-bold text-red-700">Rs. {{ number_format($invoice['outstanding_amount'], 2) }}</span>
                                @else
                                    <span class="text-green-600 font-medium">✓ Paid</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    
                    <!-- Pending Settlement Deposit Rows (Inline) -->
                    @if($riderData['pending_settlements']->count() > 0)
                        @foreach($riderData['pending_settlements'] as $settlement)
                        <tbody style="background: linear-gradient(to right, #fef3c7, #fde68a) !important;" class="border-t-2 border-amber-400">
                            <tr>
                                <td colspan="2" class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg">💰</span>
                                        <div>
                                            <p class="text-xs font-bold text-amber-900">Settlement Deposit</p>
                                            <p class="text-xs text-amber-700">{{ $settlement->created_at->format('M j, Y g:i A') }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td colspan="2" class="px-3 py-3">
                                    <p class="text-xs text-amber-800">{{ $settlement->description }}</p>
                                    @if($settlement->comments)
                                    <p class="text-xs text-amber-600 mt-1">{{ $settlement->comments }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <span class="px-2 py-1 text-xs font-bold bg-amber-200 text-amber-900 rounded-full">
                                        ⏳ PENDING APPROVAL
                                    </span>
                                </td>
                                <td colspan="2" class="px-3 py-3 text-right">
                                    <p class="text-lg font-bold text-amber-900">Rs. {{ number_format($settlement->amount, 2) }}</p>
                                    <p class="text-xs text-amber-700">
                                        {{ $settlement->invoices->count() }} invoice(s) • 
                                        @if($settlement->amount >= $settlement->total_outstanding)
                                            <span class="text-green-700">Full Payment</span>
                                        @else
                                            <span class="text-red-700">Short Rs. {{ number_format($settlement->total_outstanding - $settlement->amount, 2) }}</span>
                                        @endif
                                    </p>
                                </td>
                            </tr>
                            <tr style="background-color: #fffbeb !important;">
                                <td colspan="7" class="px-3 py-2">
                                    <div class="flex items-center justify-between">
                                        <a href="{{ route('fin.ledger.show', $settlement->id) }}" class="text-xs text-amber-700 hover:text-amber-900 font-medium">
                                            View in Approvals →
                                        </a>
                                        <div class="flex gap-2">
                                            <form method="POST" action="{{ route('fin.ledger.approve', $settlement->id) }}" class="inline" onsubmit="return confirm('Approve this settlement deposit of Rs. {{ number_format($settlement->amount, 2) }}?');">
                                                @csrf
                                                <input type="hidden" name="_origin" value="outstanding-invoices">
                                                <button type="submit" style="background: linear-gradient(to right, #16a34a, #15803d) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-md shadow-sm hover:opacity-90">
                                                    ✓ Approve
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('fin.ledger.reject', $settlement->id) }}" class="inline" onsubmit="return confirm('Reject this settlement?');">
                                                @csrf
                                                <input type="hidden" name="_origin" value="outstanding-invoices">
                                                <button type="submit" style="background: linear-gradient(to right, #dc2626, #b91c1c) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-md shadow-sm hover:opacity-90">
                                                    ✗ Reject
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        @endforeach
                    @endif
                    
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="6" class="px-3 py-2 text-right text-xs font-bold text-gray-700">
                                Subtotal:
                            </td>
                            <td class="px-3 py-2 text-right">
                                <span class="text-sm font-bold text-purple-700">Rs. {{ number_format($riderData['total_outstanding'], 2) }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                @endif
            </div>

            <!-- Quick Actions -->
            <div class="bg-gray-50 px-4 py-2 border-t border-gray-200 flex justify-between items-center">
                <span class="text-xs text-gray-500">{{ $riderData['invoice_count'] }} invoice(s)</span>
                <a href="{{ route('fin.employee.show', $riderData['account']->id) }}" 
                   class="inline-flex items-center px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded-md transition-colors">
                    View Ledger →
                </a>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    <!-- View Settled Invoices Section -->
    @if($filters['status'] != 'settled' && $stats['settled_count'] > 0)
    <div class="mt-4">
        <div style="background: linear-gradient(to right, #ecfdf5, #d1fae5); border: 2px solid #86efac;" class="rounded-lg p-4 flex items-center justify-between">
            <div>
                <h3 style="color: #166534 !important;" class="text-sm font-bold mb-1">✅ Settled Invoices Available</h3>
                <p style="color: #15803d !important;" class="text-xs">{{ $stats['settled_count'] }} invoice(s) totaling Rs. {{ number_format($stats['settled_total'], 2) }} have been settled.</p>
            </div>
            <button onclick="filterByStatus('settled')" style="background: linear-gradient(to right, #16a34a, #15803d) !important; color: white !important;" class="inline-flex items-center px-5 py-2.5 text-sm font-semibold rounded-lg shadow-md hover:opacity-90 transition-opacity">
                View Settled Invoices →
            </button>
        </div>
    </div>
    @endif

</div>

<!-- JavaScript for Interactive Filters -->
<script>
function filterByStatus(status) {
    document.getElementById('status-filter').value = status;
    document.querySelector('form').submit();
}

function togglePendingSettlements() {
    const section = document.getElementById('pending-settlements-section');
    if (section) {
        section.classList.toggle('hidden');
    }
}

// Auto-show pending settlements if there are any
@if($stats['pending_settlement_count'] > 0 && $filters['status'] == 'all')
    // Optionally auto-show on page load
    // togglePendingSettlements();
@endif
</script>

<!-- CSS for animations -->
<style>
.stat-card {
    cursor: pointer;
}
.stat-card:active {
    transform: scale(0.98);
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fadeIn {
    animation: fadeIn 0.3s ease-in-out;
}
</style>

@endsection
