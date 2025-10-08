@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $account->account_name }}</h1>
        <a href="{{ route('fin.employee.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Employees
        </a>
    </div>

    <!-- Success Message -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <!-- Error Message -->
    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Employee Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Opening Balance</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">Rs. {{ number_format($summary['opening_balance'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Total Invoices</div>
            <div class="text-2xl font-bold text-green-600 mt-1">Rs. {{ number_format($summary['total_invoices'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Total Expenses</div>
            <div class="text-2xl font-bold text-red-600 mt-1">Rs. {{ number_format($summary['total_expenses'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4 {{ $summary['current_balance'] > 0 ? 'bg-green-50' : ($summary['current_balance'] < 0 ? 'bg-red-50' : '') }}">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Current Cash Balance</div>
            <div class="text-2xl font-bold {{ $summary['current_balance'] > 0 ? 'text-green-600' : ($summary['current_balance'] < 0 ? 'text-red-600' : 'text-gray-900') }} mt-1">
                Rs. {{ number_format($summary['current_balance'], 2) }}
            </div>
        </div>
    </div>

    <!-- Additional Stats Row -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="text-xs text-blue-600 uppercase tracking-wider font-medium">Total Deposits to NF Cash</div>
            <div class="text-xl font-bold text-blue-900 mt-1">Rs. {{ number_format($summary['total_deposits'], 2) }}</div>
            <div class="text-xs text-blue-600 mt-1">Cash handed in to main till</div>
        </div>
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-600 uppercase tracking-wider font-medium">Account Code</div>
            <div class="text-lg font-mono font-bold text-gray-900 mt-1">{{ $account->account_code }}</div>
            @if($account->user)
                <div class="text-xs text-gray-600 mt-1">Linked to: {{ $account->user->fullname ?? $account->user->name }}</div>
            @endif
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-4 mb-6">
        <button onclick="openDepositModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
            💵 Record Deposit to NF Cash
        </button>
        <button onclick="openAdjustmentModal()" class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-md">
            ⚖️ Make Adjustment
        </button>
    </div>

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
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cash In</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cash Out</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($ledger as $transaction)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->transaction_date ? $transaction->transaction_date->format('M j, Y') : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $typeColors = [
                                        'invoice' => 'bg-green-100 text-green-800',
                                        'expense' => 'bg-red-100 text-red-800',
                                        'employee_deposit' => 'bg-blue-100 text-blue-800',
                                    ];
                                    $color = $typeColors[$transaction->transaction_type] ?? 'bg-gray-100 text-gray-800';
                                @endphp
                                <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $color }}">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                {{ $transaction->description }}
                                @if($transaction->comments)
                                    <div class="text-xs text-gray-500 mt-1">{{ $transaction->comments }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-green-600">
                                @if($transaction->to_account_id === $account->id)
                                    Rs. {{ number_format($transaction->amount, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                @if($transaction->from_account_id === $account->id)
                                    Rs. {{ number_format($transaction->amount, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold {{ $transaction->running_balance > 0 ? 'text-green-600' : ($transaction->running_balance < 0 ? 'text-red-600' : 'text-gray-900') }}">
                                Rs. {{ number_format($transaction->running_balance, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">
                                No transactions found for this employee.
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

<!-- Record Deposit Modal -->
<div id="depositModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Record Deposit to NF Cash</h3>
            <button onclick="closeDepositModal()" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <form action="{{ route('fin.employee.deposit', $account->id) }}" method="POST">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" max="{{ $account->current_balance }}" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Current balance: Rs. {{ number_format($account->current_balance, 2) }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Short/Over Amount (optional)</label>
                    <input type="number" name="short_over" step="0.01" placeholder="0.00"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Negative for short, positive for over</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description (optional)</label>
                    <textarea name="description" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
                        Record Deposit
                    </button>
                    <button type="button" onclick="closeDepositModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Manual Adjustment Modal -->
<div id="adjustmentModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Manual Adjustment</h3>
            <button onclick="closeAdjustmentModal()" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <form action="{{ route('fin.employee.adjustment', $account->id) }}" method="POST">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="increase">Increase Balance</option>
                        <option value="decrease">Decrease Balance</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason (required)</label>
                    <textarea name="reason" rows="3" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-md">
                        Make Adjustment
                    </button>
                    <button type="button" onclick="closeAdjustmentModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openDepositModal() {
    document.getElementById('depositModal').classList.remove('hidden');
}
function closeDepositModal() {
    document.getElementById('depositModal').classList.add('hidden');
}
function openAdjustmentModal() {
    document.getElementById('adjustmentModal').classList.remove('hidden');
}
function closeAdjustmentModal() {
    document.getElementById('adjustmentModal').classList.add('hidden');
}
</script>

@endsection

