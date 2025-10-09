@extends('layouts.app')

@section('title', 'Approvals Dashboard')

@section('content')
<div class="container mx-auto px-4 py-6">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">📋 Approvals Dashboard</h1>
        <p class="text-gray-600 mt-2">All pending approvals in one place</p>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Expense Requests Card -->
        <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 border-2 border-yellow-300 rounded-xl p-6 shadow-md">
            <div class="flex items-center justify-between mb-3">
                <div class="text-yellow-700">
                    <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/>
                        <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <span class="px-3 py-1 bg-yellow-200 text-yellow-800 text-xs font-bold rounded-full">
                    {{ $requestSummary['count'] }}
                </span>
            </div>
            <h3 class="text-lg font-semibold text-gray-900">Expense Requests</h3>
            <p class="text-3xl font-bold text-yellow-700 mt-2">Rs. {{ number_format($requestSummary['total_amount'], 2) }}</p>
            <p class="text-xs text-gray-600 mt-2">L1/L2 Approval Workflow</p>
        </div>

        <!-- Financial Transactions Card -->
        <div class="bg-gradient-to-br from-blue-50 to-blue-100 border-2 border-blue-300 rounded-xl p-6 shadow-md">
            <div class="flex items-center justify-between mb-3">
                <div class="text-blue-700">
                    <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"/>
                        <path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <span class="px-3 py-1 bg-blue-200 text-blue-800 text-xs font-bold rounded-full">
                    {{ $ledgerSummary['count'] }}
                </span>
            </div>
            <h3 class="text-lg font-semibold text-gray-900">Financial Transactions</h3>
            <p class="text-3xl font-bold text-blue-700 mt-2">Rs. {{ number_format($ledgerSummary['total_amount'], 2) }}</p>
            <p class="text-xs text-gray-600 mt-2">Invoices, Deposits, Payments</p>
        </div>

        <!-- Grand Total Card -->
        <div class="bg-gradient-to-br from-red-50 to-red-100 border-2 border-red-300 rounded-xl p-6 shadow-md">
            <div class="flex items-center justify-between mb-3">
                <div class="text-red-700">
                    <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 0l-2 2a1 1 0 101.414 1.414L8 10.414l1.293 1.293a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <span class="px-3 py-1 bg-red-200 text-red-800 text-xs font-bold rounded-full">
                    {{ $grandTotal['count'] }}
                </span>
            </div>
            <h3 class="text-lg font-semibold text-gray-900">Total Pending</h3>
            <p class="text-3xl font-bold text-red-700 mt-2">Rs. {{ number_format($grandTotal['amount'], 2) }}</p>
            <p class="text-xs text-gray-600 mt-2">All Approvals Combined</p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-lg shadow-md border border-gray-200">
        <div class="border-b border-gray-200">
            <ul class="flex flex-wrap -mb-px text-sm font-medium text-center" role="tablist">
                <li class="mr-2" role="presentation">
                    <button class="inline-flex items-center gap-2 px-6 py-4 border-b-2 border-yellow-500 text-yellow-600 font-bold hover:text-yellow-700" 
                            id="requests-tab" 
                            data-tabs-target="#requests" 
                            type="button" 
                            role="tab" 
                            aria-controls="requests" 
                            aria-selected="true"
                            onclick="switchTab('requests')">
                        📝 Expense Requests
                        <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs font-bold rounded-full">
                            {{ $requestSummary['count'] }}
                        </span>
                    </button>
                </li>
                <li class="mr-2" role="presentation">
                    <button class="inline-flex items-center gap-2 px-6 py-4 border-b-2 border-transparent text-gray-500 hover:text-gray-600 hover:border-gray-300"
                            id="financial-tab" 
                            data-tabs-target="#financial" 
                            type="button" 
                            role="tab" 
                            aria-controls="financial" 
                            aria-selected="false"
                            onclick="switchTab('financial')">
                        💰 Financial Transactions
                        <span class="px-2 py-1 bg-gray-100 text-gray-700 text-xs font-bold rounded-full">
                            {{ $ledgerSummary['count'] }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>

        <!-- Tab Content -->
        <div id="tabContent">
            <!-- Expense Requests Tab -->
            <div id="requests" role="tabpanel" aria-labelledby="requests-tab">
                <div class="p-6">
                    @if($pendingRequests->count() > 0)
                        <div class="mb-4 text-sm text-gray-600">
                            You have <strong class="text-gray-900">{{ $pendingRequests->count() }}</strong> pending expense requests
                            @if($hasLevel1Rights) (Level 1 Approver) @endif
                            @if($hasLevel2Rights) (Level 2 Approver) @endif
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Request #</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requester</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Level</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($pendingRequests as $request)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $request->request_number }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $request->requester->fullname ?? 'Unknown' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $request->category->category_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-900">Rs. {{ number_format($request->amount, 2) }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            @if($request->level_1_status === 'pending')
                                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs font-medium rounded">L1 Pending</span>
                                            @elseif($request->level_2_status === 'pending')
                                                <span class="px-2 py-1 bg-orange-100 text-orange-800 text-xs font-medium rounded">L2 Pending</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ $request->submitted_at ? $request->submitted_at->format('M d, Y') : 'N/A' }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <a href="{{ route('requests.show', $request->id) }}" 
                                               class="inline-flex items-center px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition">
                                                View & Approve
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-12">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No pending expense requests</h3>
                            <p class="mt-1 text-sm text-gray-500">All expense requests have been approved!</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Financial Transactions Tab -->
            <div id="financial" class="hidden" role="tabpanel" aria-labelledby="financial-tab">
                <div class="p-6">
                    @if($pendingLedger->count() > 0)
                        <!-- Type Breakdown -->
                        <div class="mb-6 grid grid-cols-2 md:grid-cols-4 gap-3">
                            @foreach($ledgerSummary['by_type'] as $type => $data)
                                <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                                    <div class="text-xs font-medium text-gray-600 mb-1">{{ ucfirst(str_replace('_', ' ', $type)) }}</div>
                                    <div class="text-lg font-bold text-gray-900">{{ $data['count'] }}</div>
                                    <div class="text-xs text-gray-600 mt-1">Rs. {{ number_format($data['amount'], 0) }}</div>
                                </div>
                            @endforeach
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($pendingLedger as $ledger)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ \Carbon\Carbon::parse($ledger->transaction_date)->format('M d, Y') }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded">
                                                {{ ucfirst(str_replace('_', ' ', $ledger->transaction_type)) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $ledger->description }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $ledger->fromAccount->account_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $ledger->toAccount->account_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-900">Rs. {{ number_format($ledger->amount, 2) }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <a href="{{ route('fin.ledger.show', $ledger->id) }}" 
                                               class="inline-flex items-center px-3 py-1 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded transition">
                                                View & Approve
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-12">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No pending financial transactions</h3>
                            <p class="mt-1 text-sm text-gray-500">All transactions have been approved!</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(tabName) {
    // Hide all tabs
    document.getElementById('requests').classList.add('hidden');
    document.getElementById('financial').classList.add('hidden');
    
    // Remove active styles from all buttons
    document.getElementById('requests-tab').classList.remove('border-yellow-500', 'text-yellow-600', 'font-bold');
    document.getElementById('requests-tab').classList.add('border-transparent', 'text-gray-500');
    
    document.getElementById('financial-tab').classList.remove('border-blue-500', 'text-blue-600', 'font-bold');
    document.getElementById('financial-tab').classList.add('border-transparent', 'text-gray-500');
    
    // Show selected tab and update button styles
    if (tabName === 'requests') {
        document.getElementById('requests').classList.remove('hidden');
        document.getElementById('requests-tab').classList.add('border-yellow-500', 'text-yellow-600', 'font-bold');
        document.getElementById('requests-tab').classList.remove('border-transparent', 'text-gray-500');
    } else {
        document.getElementById('financial').classList.remove('hidden');
        document.getElementById('financial-tab').classList.add('border-blue-500', 'text-blue-600', 'font-bold');
        document.getElementById('financial-tab').classList.remove('border-transparent', 'text-gray-500');
    }
}
</script>
@endsection

