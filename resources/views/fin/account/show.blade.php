@extends('layouts.app')

@section('title', e($account->account_name))

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $account->account_name }}</h1>
            <p class="text-sm text-gray-600 mt-1">
                <span class="font-mono">{{ $account->account_code }}</span> 
                <span class="mx-2">•</span> 
                <span class="capitalize">{{ $account->account_type }}</span>
                <span class="mx-2">•</span>
                <span class="capitalize">{{ str_replace('_', ' ', $account->account_category) }}</span>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('fin.accounts.edit', $account->id) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
                <i class="ki-filled ki-pencil mr-2"></i> Edit
            </a>
            <a href="{{ route('fin.accounts.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                ← Back to Accounts
            </a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Opening Balance</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">Rs. {{ number_format($summary['opening_balance'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Total Debits</div>
            <div class="text-2xl font-bold text-green-600 mt-1">Rs. {{ number_format($summary['total_debits'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Total Credits</div>
            <div class="text-2xl font-bold text-red-600 mt-1">Rs. {{ number_format($summary['total_credits'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4 {{ $summary['current_balance'] > 0 ? 'bg-green-50' : ($summary['current_balance'] < 0 ? 'bg-red-50' : '') }}">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Current Balance</div>
            <div class="text-2xl font-bold {{ $summary['current_balance'] > 0 ? 'text-green-600' : ($summary['current_balance'] < 0 ? 'text-red-600' : 'text-gray-900') }} mt-1">
                Rs. {{ number_format($summary['current_balance'], 2) }}
            </div>
        </div>
    </div>

    <!-- Additional Details -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="text-xs text-blue-600 uppercase tracking-wider font-medium">Total Transactions</div>
            <div class="text-xl font-bold text-blue-900 mt-1">{{ $summary['transaction_count'] }}</div>
        </div>
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-600 uppercase tracking-wider font-medium">Status</div>
            <div class="mt-1">
                @if($account->is_active)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                        <i class="ki-filled ki-check-circle mr-1"></i> Active
                    </span>
                @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-600">
                        <i class="ki-filled ki-cross-circle mr-1"></i> Inactive
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Description (if exists) -->
    @if($account->description)
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
            <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Description</div>
            <p class="text-sm text-gray-700">{{ $account->description }}</p>
        </div>
    @endif

    <!-- Ledger Transactions -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-medium text-gray-900">Transaction History</h2>
            <div class="text-sm text-gray-500">
                Showing {{ $ledger->firstItem() ?? 0 }}-{{ $ledger->lastItem() ?? 0 }} of {{ $ledger->total() }}
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Debit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Credit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($ledger as $transaction)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->transaction_date ? $transaction->transaction_date->format('M j, Y') : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                {{ $transaction->description }}
                                @if($transaction->createdBy)
                                    <div class="text-xs text-gray-500 mt-1">By: {{ $transaction->createdBy->fullname ?? $transaction->createdBy->name }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-green-600">
                                {{ $transaction->debit > 0 ? 'Rs. ' . number_format($transaction->debit, 2) : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                {{ $transaction->credit > 0 ? 'Rs. ' . number_format($transaction->credit, 2) : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                                Rs. {{ number_format($transaction->running_balance, 2) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($transaction->approval_status === 'approved')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                        Approved
                                    </span>
                                @elseif($transaction->approval_status === 'pending')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                                        Pending
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                        Rejected
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">
                                No transactions found for this account.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($ledger->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $ledger->links() }}
            </div>
        @endif
    </div>
</div>
@endsection

