@extends('layouts.app')

@section('title', 'Overall Ledger')

@section('content')
<div class="container mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Overall Ledger</h1>
        <a href="{{ route('fin.ledger.transfer') }}" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
            🔄 New Transfer
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <!-- KPI Summary Cards (Clickable for filtering) -->
    <x-fin.kpi-cards :kpis="$summaryKPIs" :clickable="true" />

    <!-- Filters (Optimized to single row) -->
    <div class="bg-white border border-gray-200 rounded-lg p-3 mb-6">
        <form method="GET" action="{{ route('fin.ledger.index') }}" id="filterForm" class="flex flex-wrap items-end gap-2">
            <div class="flex-1 min-w-[120px]">
                <label class="block text-xs font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" value="{{ $startDate }}" 
                       class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs">
            </div>
            
            <div class="flex-1 min-w-[120px]">
                <label class="block text-xs font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" value="{{ $endDate }}" 
                       class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs">
            </div>

            <div class="flex-1 min-w-[140px]">
                <label class="block text-xs font-medium text-gray-700 mb-1">Type</label>
                <select name="type" class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs">
                    <option value="">All Types</option>
                    @foreach($transactionTypes as $key => $label)
                        <option value="{{ $key }}" {{ request('type') == $key ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex-1 min-w-[100px]">
                <label class="block text-xs font-medium text-gray-700 mb-1">Mode</label>
                <select name="mode" class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs">
                    <option value="">All</option>
                    <option value="cash" {{ request('mode') == 'cash' ? 'selected' : '' }}>Cash</option>
                    <option value="online" {{ request('mode') == 'online' ? 'selected' : '' }}>Online</option>
                </select>
            </div>

            <div class="flex-1 min-w-[110px]">
                <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs">
                    <option value="">All</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                    <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>

            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-medium text-gray-700 mb-1">Account</label>
                <select name="account_id" class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs">
                    <option value="">All Accounts</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" {{ request('account_id') == $account->id ? 'selected' : '' }}>
                            {{ $account->account_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex-1 min-w-[140px]">
                <label class="block text-xs font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Description..."
                       class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs">
            </div>

            <div class="flex gap-1">
                <button type="submit" class="px-3 py-1.5 bg-gray-900 hover:bg-gray-800 text-white text-xs font-medium rounded">
                    🔍 Filter
                </button>
                <a href="{{ route('fin.ledger.index') }}" class="px-3 py-1.5 border border-gray-300 text-gray-700 text-xs font-medium rounded hover:bg-gray-50">
                    Clear
                </a>
                <button type="button" onclick="openAuditModal()" class="px-3 py-1.5 text-white text-xs font-semibold rounded shadow-md" style="background: linear-gradient(to right, #7c3aed, #4f46e5) !important;">
                    <span style="color: white !important;">🔍 Audit</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Audit Modal - Elegant Design (Matching Vendor Modal Style) -->
    <div id="auditModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
        <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
            <!-- Fixed Header -->
            <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #60a5fa, #818cf8); display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 2px 8px rgba(96, 165, 250, 0.3);">
                        🔍
                    </div>
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Ledger Audit Report</h3>
                        <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Comprehensive integrity and consistency checks</p>
                    </div>
                </div>
                <button type="button" onclick="closeAuditModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
            </div>
            
            <!-- Date Filter Section -->
            <div style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0;">
                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151;">
                        <input type="checkbox" id="auditIncludeLegacy" onchange="toggleAuditDateFilter()" style="width: 16px; height: 16px; border-radius: 4px; border: 1px solid #d1d5db; cursor: pointer;">
                        <span>Include records before Nov 1, 2025</span>
                    </label>
                    <div id="auditCustomDateRange" style="display: none; align-items: center; gap: 8px; font-size: 13px;">
                        <span style="color: #6b7280;">From:</span>
                        <input type="date" id="auditStartDate" value="2025-11-01" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; outline: none;">
                        <span style="color: #6b7280;">To:</span>
                        <input type="date" id="auditEndDate" value="{{ date('Y-m-d') }}" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; outline: none;">
                    </div>
                    <button onclick="refreshAuditReport()" style="margin-left: auto; padding: 8px 16px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; font-weight: 600; font-size: 13px; border: none; border-radius: 8px; cursor: pointer; box-shadow: 0 2px 4px rgba(99, 102, 241, 0.3); transition: all 0.15s;">
                        🔄 Refresh
                    </button>
                </div>
            </div>

            <!-- Scrollable Content -->
            <div style="overflow-y: auto; flex: 1; padding: 24px;">
                <!-- Loading State -->
                <div id="auditLoading" style="text-center; padding: 48px 0;">
                    <div style="display: inline-block; width: 48px; height: 48px; border: 4px solid #e5e7eb; border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
                    <p style="margin-top: 16px; font-size: 16px; font-weight: 500; color: #6b7280;">Running audit checks...</p>
                </div>

                <!-- Summary Section -->
                <div id="auditSummary" style="display: none; margin-bottom: 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                    <div style="background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); border: 2px solid #fecaca; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 36px; font-weight: 700; color: #dc2626;" id="totalIssues">0</div>
                        <div style="font-size: 12px; font-weight: 600; color: #991b1b; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Total Issues</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%); border: 2px solid #fdba74; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 36px; font-weight: 700; color: #ea580c;" id="criticalIssues">0</div>
                        <div style="font-size: 12px; font-weight: 600; color: #9a3412; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Critical Issues</div>
                    </div>
                    <div style="background: linear-gradient(135deg, #f0fdf4 0%, #bbf7d0 100%); border: 2px solid #86efac; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 36px; font-weight: 700; color: #059669;" id="issueTypes">0</div>
                        <div style="font-size: 12px; font-weight: 600; color: #065f46; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Issue Types</div>
                    </div>
                </div>

                <!-- Issues List -->
                <div id="auditIssues" style="display: none;">
                    <!-- Will be populated by JavaScript -->
                </div>

                <!-- No Issues State -->
                <div id="auditNoIssues" style="display: none; text-center; padding: 64px 0;">
                    <div style="font-size: 80px; margin-bottom: 16px;">✅</div>
                    <h3 style="font-size: 28px; font-weight: 700; color: #059669; margin-bottom: 8px;">All Clear!</h3>
                    <p style="font-size: 16px; color: #6b7280;">No ledger integrity issues detected.</p>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>

    <!-- Ledger Table -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-medium text-gray-900">All Transactions</h2>
            <div class="text-sm text-gray-500">
                Showing {{ $ledger->firstItem() ?? 0 }}-{{ $ledger->lastItem() ?? 0 }} of {{ $ledger->total() }}
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Mode</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($ledger as $transaction)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->transaction_date->format('Y-m-d') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs">
                                <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-800">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                {{ $transaction->fromAccount->account_name ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                {{ $transaction->toAccount->account_name ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate">
                                {{ $transaction->description }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                                Rs. {{ number_format($transaction->amount, 2) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-xs">
                                @if($transaction->mode)
                                    <span class="px-2 py-1 rounded-full {{ $transaction->mode === 'cash' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' }}">
                                        {{ ucfirst($transaction->mode) }}
                                    </span>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-xs">
                                @if($transaction->approval_status === 'pending')
                                    <span class="px-2 py-1 rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                                @elseif($transaction->approval_status === 'approved')
                                    <span class="px-2 py-1 rounded-full bg-green-100 text-green-800">Approved</span>
                                @else
                                    <span class="px-2 py-1 rounded-full bg-red-100 text-red-800">Rejected</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                @if($transaction->approval_status === 'pending')
                                    <button onclick="approveTransaction({{ $transaction->id }})" class="text-green-600 hover:text-green-900 mr-2" title="Approve">
                                        ✅
                                    </button>
                                    <button onclick="rejectTransaction({{ $transaction->id }})" class="text-red-600 hover:text-red-900" title="Reject">
                                        ❌
                                    </button>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-8 text-center text-sm text-gray-500">
                                No transactions found. Try adjusting your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    @if($ledger->hasPages())
        <div class="mt-4">
            {{ $ledger->links() }}
        </div>
    @endif
</div>

<!-- Approve Modal -->
<div id="approveModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Approve Transaction</h3>
            <button onclick="closeApproveModal()" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <form id="approveForm" method="POST">
            @csrf
            <div class="space-y-4">
                <!-- Transaction Details (readonly) -->
                <div id="txnDetails" class="p-3 bg-gray-50 border border-gray-200 rounded-md text-xs">
                    <div class="font-semibold text-gray-900 mb-2">Transaction Details:</div>
                    <div class="space-y-1 text-gray-700">
                        <div>From: <span id="txnFrom" class="font-medium"></span></div>
                        <div>To: <span id="txnTo" class="font-medium"></span></div>
                        <div>Amount: <span id="txnAmount" class="font-medium text-green-600"></span></div>
                    </div>
                </div>
                
                <!-- Account Override Options -->
                <div class="p-3 bg-blue-50 border border-blue-200 rounded-md">
                    <div class="text-sm font-semibold text-blue-900 mb-2">💡 Override Accounts (Optional)</div>
                    
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-blue-900 mb-1">Change Source Account:</label>
                            <select name="override_source_account_id" 
                                    class="w-full px-2 py-1.5 border border-blue-300 rounded text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Keep Original</option>
                                @php
                                    $allAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                                        ->orderBy('account_name')
                                        ->get();
                                @endphp
                                @foreach($allAccounts as $acc)
                                    <option value="{{ $acc->id }}">
                                        {{ $acc->account_name }} (Rs. {{ number_format($acc->current_balance, 2) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-medium text-blue-900 mb-1">Change Destination Account:</label>
                            <select name="override_destination_account_id" 
                                    class="w-full px-2 py-1.5 border border-blue-300 rounded text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Keep Original</option>
                                @foreach($allAccounts as $acc)
                                    <option value="{{ $acc->id }}">
                                        {{ $acc->account_name }} (Rs. {{ number_format($acc->current_balance, 2) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    
                    <p class="text-xs text-blue-700 mt-2">Leave as "Keep Original" if no changes needed</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Approval Notes (optional)</label>
                    <textarea name="approval_notes" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                </div>
                
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md">
                        ✅ Approve
                    </button>
                    <button type="button" onclick="closeApproveModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Reject Transaction</h3>
            <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <form id="rejectForm" method="POST">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rejection Reason (required)</label>
                    <textarea name="rejection_reason" rows="3" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md">
                        ❌ Reject
                    </button>
                    <button type="button" onclick="closeRejectModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let ledgerData = @json($ledger->items());

function approveTransaction(id) {
    // Find transaction in data
    const txn = ledgerData.find(t => t.id === id);
    
    if (txn) {
        // Populate transaction details
        document.getElementById('txnFrom').textContent = txn.from_account ? txn.from_account.account_name : 'N/A';
        document.getElementById('txnTo').textContent = txn.to_account ? txn.to_account.account_name : 'N/A';
        document.getElementById('txnAmount').textContent = 'Rs. ' + parseFloat(txn.amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
    
    document.getElementById('approveForm').action = `/finance/ledger/${id}/approve`;
    document.getElementById('approveModal').classList.remove('hidden');
}

function closeApproveModal() {
    document.getElementById('approveModal').classList.add('hidden');
    // Reset form
    document.getElementById('approveForm').reset();
}

function rejectTransaction(id) {
    document.getElementById('rejectForm').action = `/finance/ledger/${id}/reject`;
    document.getElementById('rejectModal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    // Reset form
    document.getElementById('rejectForm').reset();
}

// Card filtering functionality
function filterByCard(cardType) {
    const form = document.getElementById('filterForm');
    
    // For vendor filter, we need to add a hidden input and redirect
    if (cardType === 'vendor') {
        const currentUrl = new URL(window.location.href);
        // Preserve date filters
        const startDate = form.querySelector('input[name="start_date"]').value;
        const endDate = form.querySelector('input[name="end_date"]').value;
        
        currentUrl.searchParams.set('vendor_filter', '1');
        if (startDate) currentUrl.searchParams.set('start_date', startDate);
        if (endDate) currentUrl.searchParams.set('end_date', endDate);
        
        // Clear other filters
        currentUrl.searchParams.delete('type');
        currentUrl.searchParams.delete('mode');
        currentUrl.searchParams.delete('status');
        currentUrl.searchParams.delete('account_id');
        currentUrl.searchParams.delete('search');
        
        window.location.href = currentUrl.toString();
        return;
    }
    
    const typeSelect = form.querySelector('select[name="type"]');
    const statusSelect = form.querySelector('select[name="status"]');
    
    // Clear existing filters except dates
    typeSelect.value = '';
    statusSelect.value = '';
    
    // Apply filter based on card type
    switch(cardType) {
        case 'invoices':
            typeSelect.value = 'invoice';
            break;
        case 'expenses':
            typeSelect.value = 'expense';
            break;
        case 'profit':
            // Show all approved transactions
            statusSelect.value = 'approved';
            break;
    }
    
    // Submit the form
    form.submit();
}

// ================================================================
// AUDIT MODAL FUNCTIONS
// ================================================================
let auditData = null;

async function openAuditModal() {
    const modal = document.getElementById('auditModal');
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
    
    await refreshAuditReport();
}

function closeAuditModal() {
    const modal = document.getElementById('auditModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

function toggleAuditDateFilter() {
    const checkbox = document.getElementById('auditIncludeLegacy');
    const customRange = document.getElementById('auditCustomDateRange');
    
    if (checkbox.checked) {
        customRange.style.display = 'flex';
    } else {
        customRange.style.display = 'none';
    }
}

async function refreshAuditReport() {
    document.getElementById('auditLoading').style.display = 'block';
    document.getElementById('auditSummary').style.display = 'none';
    document.getElementById('auditIssues').style.display = 'none';
    document.getElementById('auditNoIssues').style.display = 'none';
    
    // Build query params
    let url = '{{ route("fin.ledger.audit.report") }}';
    const includeLegacy = document.getElementById('auditIncludeLegacy').checked;
    
    if (includeLegacy) {
        const startDate = document.getElementById('auditStartDate').value;
        const endDate = document.getElementById('auditEndDate').value;
        url += `?start_date=${startDate}&end_date=${endDate}`;
    } else {
        // Default: only from Nov 1, 2025 onwards
        url += '?start_date=2025-11-01';
    }
    
    // Fetch audit report
    try {
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success) {
            auditData = data;
            displayAuditReport(data);
        } else {
            alert('Failed to load audit report: ' + (data.message || 'Unknown error'));
            closeAuditModal();
        }
    } catch (error) {
        console.error('Audit report error:', error);
        alert('Failed to load audit report. Check console for details.');
        closeAuditModal();
    }
}

function displayAuditReport(data) {
    // Hide loading
    document.getElementById('auditLoading').style.display = 'none';
    
    if (data.issues.length === 0) {
        // Show no issues state
        document.getElementById('auditNoIssues').style.display = 'block';
        return;
    }
    
    // Show summary
    document.getElementById('totalIssues').textContent = data.summary.total_issues;
    document.getElementById('criticalIssues').textContent = data.summary.critical_issues;
    document.getElementById('issueTypes').textContent = data.summary.issue_types;
    document.getElementById('auditSummary').style.display = 'grid';
    
    // Render issues
    const issuesContainer = document.getElementById('auditIssues');
    issuesContainer.innerHTML = '';
    
    data.issues.forEach((issue, index) => {
        const issueCard = createIssueCard(issue, index);
        issuesContainer.appendChild(issueCard);
    });
    
    document.getElementById('auditIssues').style.display = 'block';
}

function createIssueCard(issue, index) {
    const card = document.createElement('div');
    const severityColors = {
        high: 'border-red-300 bg-red-50',
        medium: 'border-orange-300 bg-orange-50',
        low: 'border-yellow-300 bg-yellow-50'
    };
    const severityBadgeColors = {
        high: 'bg-red-200 text-red-800',
        medium: 'bg-orange-200 text-orange-800',
        low: 'bg-yellow-200 text-yellow-800'
    };
    
    card.className = `border-2 ${severityColors[issue.severity]} rounded-lg p-4`;
    
    let itemsHtml = '';
    if (issue.type === 'missing_invoice_ledger') {
        itemsHtml = `
            <div class="bg-white rounded-lg overflow-hidden border border-red-200 mt-3">
                <table class="min-w-full divide-y divide-red-200 text-sm">
                    <thead class="bg-red-100">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">
                                <input type="checkbox" id="selectAll_${index}" onchange="toggleSelectAll(${index})" class="rounded">
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Order #</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Customer</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Rider</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-red-900">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-red-100" id="issueItems_${index}">
                        ${issue.items.map(item => `
                            <tr class="hover:bg-red-50">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="issue_${index}_items" value="${item.order_id}" class="rounded issue-checkbox-${index}">
                                </td>
                                <td class="px-3 py-2 font-medium text-gray-900">${item.order_number}</td>
                                <td class="px-3 py-2 text-gray-600">${item.order_date}</td>
                                <td class="px-3 py-2 text-gray-600">${item.customer_name}</td>
                                <td class="px-3 py-2 text-gray-600">${item.rider_name}</td>
                                <td class="px-3 py-2 text-right font-medium text-gray-900">Rs. ${parseFloat(item.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex gap-2">
                <button onclick="fixMissingInvoices(${index})" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    ✓ Fix Selected
                </button>
                <button onclick="fixMissingInvoices(${index}, true)" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    ✓ Fix All
                </button>
            </div>
        `;
    } else if (issue.type === 'missing_expense_ledger') {
        itemsHtml = `
            <div class="bg-white rounded-lg overflow-hidden border border-red-200 mt-3">
                <table class="min-w-full divide-y divide-red-200 text-sm">
                    <thead class="bg-red-100">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">
                                <input type="checkbox" id="selectAll_${index}" onchange="toggleSelectAll(${index})" class="rounded">
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Request #</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Requester</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Category</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Approved Date</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-red-900">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-red-100" id="issueItems_${index}">
                        ${issue.items.map(item => `
                            <tr class="hover:bg-red-50">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="issue_${index}_items" value="${item.request_id}" class="rounded issue-checkbox-${index}">
                                </td>
                                <td class="px-3 py-2 font-medium text-gray-900">${item.request_number}</td>
                                <td class="px-3 py-2 text-gray-600">${item.requester_name}</td>
                                <td class="px-3 py-2 text-gray-600 text-xs">${item.expense_category}</td>
                                <td class="px-3 py-2 text-gray-600">${item.approved_at}</td>
                                <td class="px-3 py-2 text-right font-medium text-gray-900">Rs. ${parseFloat(item.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex gap-2">
                <button onclick="fixMissingExpenses(${index})" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    ✓ Fix Selected
                </button>
                <button onclick="fixMissingExpenses(${index}, true)" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    ✓ Fix All
                </button>
            </div>
        `;
    } else if (issue.type === 'incomplete_settlement') {
        itemsHtml = `
            <div class="bg-white rounded-lg overflow-hidden border border-red-200 mt-3">
                <table class="min-w-full divide-y divide-red-200 text-sm">
                    <thead class="bg-red-100">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">
                                <input type="checkbox" id="selectAll_${index}" onchange="toggleSelectAll(${index})" class="rounded">
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Deposit Description</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-red-900">Date</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-red-900">Amount</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-red-900">Unsettled Invoices</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-red-100" id="issueItems_${index}">
                        ${issue.items.map(item => `
                            <tr class="hover:bg-red-50">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="issue_${index}_items" value="${item.deposit_id}" class="rounded issue-checkbox-${index}">
                                </td>
                                <td class="px-3 py-2 font-medium text-gray-900 text-xs">${item.deposit_description}</td>
                                <td class="px-3 py-2 text-gray-600">${item.deposit_date}</td>
                                <td class="px-3 py-2 text-right font-medium text-gray-900">Rs. ${parseFloat(item.deposit_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                <td class="px-3 py-2 text-center text-gray-900">${item.unsettled_count}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex gap-2">
                <button onclick="fixIncompleteSettlements(${index})" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    ✓ Fix Selected
                </button>
                <button onclick="fixIncompleteSettlements(${index}, true)" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    ✓ Fix All
                </button>
            </div>
        `;
    } else {
        // Generic issue display
        itemsHtml = `<div class="mt-2 text-sm text-gray-600">${issue.count} item(s) affected</div>`;
    }
    
    card.innerHTML = `
        <div class="flex items-start justify-between mb-2">
            <div class="flex items-center gap-2">
                <span class="text-2xl">⚠️</span>
                <h3 class="text-lg font-bold text-gray-900">${issue.title}</h3>
            </div>
            <span class="px-2 py-1 ${severityBadgeColors[issue.severity]} text-xs font-bold rounded-full uppercase">${issue.severity}</span>
        </div>
        <p class="text-sm text-gray-700 mb-2">${issue.description}</p>
        ${itemsHtml}
    `;
    
    return card;
}

function toggleSelectAll(issueIndex) {
    const selectAll = document.getElementById(`selectAll_${issueIndex}`);
    const checkboxes = document.querySelectorAll(`.issue-checkbox-${issueIndex}`);
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
}

async function fixMissingInvoices(issueIndex, fixAll = false) {
    const issue = auditData.issues[issueIndex];
    let orderIds;
    
    if (fixAll) {
        orderIds = issue.items.map(item => item.order_id);
    } else {
        const checked = document.querySelectorAll(`input[name="issue_${issueIndex}_items"]:checked`);
        orderIds = Array.from(checked).map(cb => parseInt(cb.value));
    }
    
    if (orderIds.length === 0) {
        alert('Please select at least one order to fix.');
        return;
    }
    
    if (!confirm(`Fix ${orderIds.length} missing invoice(s)? This will create ledger entries for these orders.`)) {
        return;
    }
    
    try {
        const response = await fetch('{{ route("fin.ledger.audit.fix-missing-invoices") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ order_ids: orderIds })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message);
            // Refresh audit report
            openAuditModal();
        } else {
            alert('Failed to fix invoices: ' + data.message);
        }
    } catch (error) {
        console.error('Fix error:', error);
        alert('Failed to fix invoices. Check console for details.');
    }
}

async function fixMissingExpenses(issueIndex, fixAll = false) {
    const issue = auditData.issues[issueIndex];
    let requestIds;
    
    if (fixAll) {
        requestIds = issue.items.map(item => item.request_id);
    } else {
        const checked = document.querySelectorAll(`input[name="issue_${issueIndex}_items"]:checked`);
        requestIds = Array.from(checked).map(cb => parseInt(cb.value));
    }
    
    if (requestIds.length === 0) {
        alert('Please select at least one expense request to fix.');
        return;
    }
    
    if (!confirm(`Fix ${requestIds.length} missing expense ledger(s)? This will create ledger entries for these approved expense requests.`)) {
        return;
    }
    
    try {
        const response = await fetch('{{ route("fin.ledger.audit.fix-missing-expenses") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ request_ids: requestIds })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message);
            // Refresh audit report
            openAuditModal();
        } else {
            alert('Failed to fix expenses: ' + data.message);
        }
    } catch (error) {
        console.error('Fix error:', error);
        alert('Failed to fix expenses. Check console for details.');
    }
}

async function fixIncompleteSettlements(issueIndex, fixAll = false) {
    const issue = auditData.issues[issueIndex];
    let depositIds;
    
    if (fixAll) {
        depositIds = issue.items.map(item => item.deposit_id);
    } else {
        const checked = document.querySelectorAll(`input[name="issue_${issueIndex}_items"]:checked`);
        depositIds = Array.from(checked).map(cb => parseInt(cb.value));
    }
    
    if (depositIds.length === 0) {
        alert('Please select at least one settlement to fix.');
        return;
    }
    
    if (!confirm(`Fix ${depositIds.length} incomplete settlement(s)? This will re-process invoice settlement for these deposits.`)) {
        return;
    }
    
    try {
        const response = await fetch('{{ route("fin.ledger.audit.fix-incomplete-settlements") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ deposit_ids: depositIds })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message);
            // Refresh audit report
            openAuditModal();
        } else {
            alert('Failed to fix settlements: ' + data.message);
        }
    } catch (error) {
        console.error('Fix error:', error);
        alert('Failed to fix settlements. Check console for details.');
    }
}
</script>

@endsection

