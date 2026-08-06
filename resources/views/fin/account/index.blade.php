@extends('layouts.app')

@section('title', 'Accounts')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Chart of Accounts</h1>
            <p class="text-sm text-gray-600 mt-1">Manage all financial accounts</p>
        </div>
        <a href="{{ route('fin.accounts.create') }}" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
            <i class="ki-filled ki-plus mr-2"></i> Create New Account
        </a>
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
            <div class="text-xs text-gray-500 uppercase tracking-wider">Total Accounts</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $summary['total_accounts'] }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Active</div>
            <div class="text-2xl font-bold text-green-600 mt-1">{{ $summary['active_accounts'] }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Total Assets</div>
            <div class="text-2xl font-bold text-blue-600 mt-1">Rs. {{ number_format($summary['total_assets'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Total Expenses</div>
            <div class="text-2xl font-bold text-red-600 mt-1">Rs. {{ number_format($summary['total_expenses'], 2) }}</div>
        </div>
    </div>

    <!-- Accounts Grouped by Type -->
    @foreach($accounts as $type => $accountList)
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden mb-6">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900 capitalize flex items-center">
                    @if($type === 'asset')
                        <i class="ki-filled ki-wallet text-blue-600 mr-2"></i>
                    @elseif($type === 'liability')
                        <i class="ki-filled ki-chart-simple text-red-600 mr-2"></i>
                    @elseif($type === 'revenue')
                        <i class="ki-filled ki-dollar text-green-600 mr-2"></i>
                    @elseif($type === 'expense')
                        <i class="ki-filled ki-graph-up text-orange-600 mr-2"></i>
                    @else
                        <i class="ki-filled ki-percentage text-purple-600 mr-2"></i>
                    @endif
                    {{ ucfirst($type) }} Accounts
                    <span class="ml-2 text-sm font-normal text-gray-500">({{ $accountList->count() }})</span>
                </h2>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Balance</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($accountList as $account)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-sm font-mono text-gray-900">{{ $account->account_code }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">{{ $account->account_name }}</div>
                                    @if($account->description)
                                        <div class="text-xs text-gray-500 mt-1">{{ \Str::limit($account->description, 60) }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        {{ ucfirst(str_replace('_', ' ', $account->account_category)) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <span class="text-sm font-semibold {{ $account->current_balance > 0 ? 'text-green-600' : ($account->current_balance < 0 ? 'text-red-600' : 'text-gray-900') }}">
                                        Rs. {{ number_format($account->current_balance, 2) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    @if($account->is_active)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                            Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex items-center justify-end space-x-2">
                                        <a href="{{ route('fin.accounts.show', $account->id) }}" class="text-blue-600 hover:text-blue-900" title="View Details">
                                            <i class="ki-filled ki-eye text-lg"></i>
                                        </a>
                                        <a href="{{ route('fin.accounts.edit', $account->id) }}" class="text-gray-600 hover:text-gray-900" title="Edit">
                                            <i class="ki-filled ki-pencil text-lg"></i>
                                        </a>
                                        <form action="{{ route('fin.accounts.toggle-status', $account->id) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="text-{{ $account->is_active ? 'red' : 'green' }}-600 hover:text-{{ $account->is_active ? 'red' : 'green' }}-900" 
                                                    title="{{ $account->is_active ? 'Deactivate' : 'Activate' }}"
                                                    onclick="return confirm('Are you sure you want to {{ $account->is_active ? 'deactivate' : 'activate' }} this account?')">
                                                <i class="ki-filled ki-{{ $account->is_active ? 'eye-slash' : 'check-circle' }} text-lg"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    @if($accounts->isEmpty())
        <div class="bg-white border border-gray-200 rounded-lg p-12 text-center">
            <i class="ki-filled ki-information-2 text-gray-400 text-5xl mb-4"></i>
            <p class="text-gray-600">No accounts found. Create your first account to get started.</p>
            <a href="{{ route('fin.accounts.create') }}" class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
                <i class="ki-filled ki-plus mr-2"></i> Create Account
            </a>
        </div>
    @endif
</div>
@endsection

