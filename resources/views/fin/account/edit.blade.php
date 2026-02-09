@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Edit Account</h1>
            <p class="text-sm text-gray-600 mt-1">{{ $account->account_name }}</p>
        </div>
        <a href="{{ route('fin.accounts.show', $account->id) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Account
        </a>
    </div>

    <!-- Error Messages -->
    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    @if($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm font-semibold text-red-800 mb-2">Please fix the following errors:</p>
            <ul class="list-disc list-inside text-sm text-red-700">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Form -->
    <form action="{{ route('fin.accounts.update', $account->id) }}" method="POST" class="bg-white border border-gray-200 rounded-lg p-6">
        @csrf
        @method('PUT')

        <!-- Account Name -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Account Name <span class="text-red-500">*</span></label>
            <input type="text" name="account_name" value="{{ old('account_name', $account->account_name) }}" 
                   class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500" 
                   placeholder="e.g., Expense - Office Rent" required>
        </div>

        <!-- Account Code -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Account Code <span class="text-red-500">*</span></label>
            <input type="text" name="account_code" value="{{ old('account_code', $account->account_code) }}" 
                   class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500 uppercase" 
                   placeholder="e.g., EXP_RENT" required>
            <p class="text-xs text-gray-500 mt-1">Unique identifier (uppercase, use underscores)</p>
        </div>

        <!-- Account Type (Read-Only) -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Account Type</label>
            <input type="text" value="{{ ucfirst($account->account_type) }}" 
                   class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 bg-gray-100" 
                   readonly>
            <p class="text-xs text-gray-500 mt-1">Account type cannot be changed after creation</p>
        </div>

        <!-- Account Category -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Account Category <span class="text-red-500">*</span></label>
            <select name="account_category" id="account_category" 
                    class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500" 
                    required>
                @if(isset($accountTypes[$account->account_type]))
                    @foreach($accountTypes[$account->account_type]['categories'] as $category)
                        <option value="{{ $category }}" 
                                {{ old('account_category', $account->account_category) == $category ? 'selected' : '' }}>
                            {{ ucfirst(str_replace('_', ' ', $category)) }}
                        </option>
                    @endforeach
                @endif
            </select>
        </div>

        <!-- Current Balance (Read-Only) -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Current Balance</label>
            <div class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 bg-gray-100 font-semibold">
                Rs. {{ number_format($account->current_balance, 2) }}
            </div>
            <p class="text-xs text-gray-500 mt-1">Balance is updated through transactions and cannot be edited directly</p>
        </div>

        <!-- Description -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
            <textarea name="description" rows="3" 
                      class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500" 
                      placeholder="Optional description or notes about this account">{{ old('description', $account->description) }}</textarea>
        </div>

        <!-- Business Unit -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Business Unit</label>
            <select name="business_unit_id" 
                    class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500">
                @foreach($businessUnits as $unit)
                    <option value="{{ $unit->id }}" 
                            {{ old('business_unit_id', $account->business_unit_id ?? 1) == $unit->id ? 'selected' : '' }}
                            style="color: {{ $unit->color_hex ?? '#374151' }}">
                        {{ $unit->name }} {{ $unit->short_code ? '(' . $unit->short_code . ')' : '' }}
                    </option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500 mt-1">Tag this account to a business unit for expense tracking and reporting</p>
        </div>

        <!-- Active Status -->
        <div class="mb-6">
            <label class="flex items-center">
                <input type="checkbox" name="is_active" value="1" 
                       {{ old('is_active', $account->is_active) == '1' ? 'checked' : '' }}
                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="ml-2 text-sm text-gray-700">Active (account can be used in transactions)</span>
            </label>
        </div>

        <!-- Buttons -->
        <div class="flex gap-3">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
                <i class="ki-filled ki-check mr-2"></i> Update Account
            </button>
            <a href="{{ route('fin.accounts.show', $account->id) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection

