@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Employee Cash</h1>
        <a href="{{ route('admin.operations') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Operations
        </a>
    </div>

    <!-- Summary Card -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-600 border border-blue-600 rounded-lg p-6 mb-6 text-white">
        <div class="flex justify-between items-center">
            <div>
                <div class="text-sm font-medium opacity-90">Total Company Cash with Employees</div>
                <div class="text-4xl font-bold mt-2">Rs. {{ number_format($totalCash, 2) }}</div>
                <div class="text-sm mt-2 opacity-75">Across {{ $employees->total() }} employee accounts</div>
            </div>
            <div class="text-right">
                <svg class="w-16 h-16 opacity-75" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
        </div>
    </div>

    <!-- Search and Filter -->
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
        <form method="GET" action="{{ route('fin.employee.index') }}" class="flex gap-4">
            <div class="flex-1">
                <input type="text" name="search" value="{{ request('search') }}" 
                       placeholder="Search employees..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <select name="balance_filter" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">All Balances</option>
                <option value="positive" {{ request('balance_filter') == 'positive' ? 'selected' : '' }}>Positive Balance</option>
                <option value="zero" {{ request('balance_filter') == 'zero' ? 'selected' : '' }}>Zero Balance</option>
                <option value="negative" {{ request('balance_filter') == 'negative' ? 'selected' : '' }}>Negative Balance</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                Search
            </button>
        </form>
    </div>

    <!-- Success Message -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <!-- Employees Table -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Code</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Balance</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Pending Expenses</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($employees as $account)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $account->account_name }}</div>
                            @if($account->user)
                                <div class="text-xs text-gray-500">{{ $account->user->username }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-xs font-mono text-gray-600 bg-gray-100 px-2 py-1 rounded">{{ $account->account_code }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-sm font-semibold {{ $account->current_balance > 0 ? 'text-green-600' : ($account->current_balance < 0 ? 'text-red-600' : 'text-gray-900') }}">
                                Rs. {{ number_format($account->current_balance, 2) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-sm font-semibold {{ $account->pending_expenses > 0 ? 'text-yellow-600' : 'text-gray-400' }}">
                                Rs. {{ number_format($account->pending_expenses, 2) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {{ $account->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                {{ $account->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <a href="{{ route('fin.employee.show', $account->id) }}" class="text-blue-600 hover:text-blue-900">View Ledger</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">
                            No employee cash accounts found. Import your legacy data to get started.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($employees->hasPages())
        <div class="mt-4">
            {{ $employees->links() }}
        </div>
    @endif
</div>
@endsection

