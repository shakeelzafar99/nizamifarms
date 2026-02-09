@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Create New Account</h1>
            <p class="text-sm text-gray-600 mt-1">Add a new account to your chart of accounts</p>
        </div>
        <a href="{{ route('fin.accounts.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Accounts
        </a>
    </div>

    <!-- Quick Setup Warning -->
    <div class="mb-6 p-4 bg-blue-50 border-l-4 border-blue-400 rounded-md">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800">💡 Need a simpler way?</h3>
                <div class="mt-2 text-sm text-blue-700">
                    <p class="mb-2">This form is for <strong>advanced users</strong> who need custom accounts. For common tasks, use these simple options:</p>
                    <ul class="list-disc list-inside space-y-1 ml-2">
                        <li><strong>Employee Cash Account:</strong> Go to <a href="{{ route('users.index') }}" class="underline font-semibold">Users</a> → Click "Create" button next to employee name</li>
                        <li><strong>Vendor:</strong> Go to <a href="{{ route('fin.vendors.index') }}" class="underline font-semibold">Vendors</a> → Click "Add New Vendor" button</li>
                        <li><strong>Expense Category:</strong> Go to <a href="{{ route('admin.operations') }}" class="underline font-semibold">Operations</a> → "Manage Expense Categories" → Click "Add New"</li>
                    </ul>
                    <p class="mt-2 text-xs italic">These options automatically create accounts with correct settings!</p>
                </div>
            </div>
        </div>
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
    <form action="{{ route('fin.accounts.store') }}" method="POST" class="bg-white border border-gray-200 rounded-lg p-6">
        @csrf

        <!-- Account Name -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Account Name <span class="text-red-500">*</span></label>
            <input type="text" name="account_name" value="{{ old('account_name') }}" 
                   class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500" 
                   placeholder="e.g., Expense - Office Rent" required>
            <p class="text-xs text-gray-500 mt-1">Descriptive name for the account</p>
        </div>

        <!-- Account Code -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Account Code <span class="text-red-500">*</span></label>
            <input type="text" name="account_code" value="{{ old('account_code') }}" 
                   class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500 uppercase" 
                   placeholder="e.g., EXP_RENT" required>
            <p class="text-xs text-gray-500 mt-1">Unique identifier (uppercase, use underscores)</p>
        </div>

        <!-- Account Type -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Account Type <span class="text-red-500">*</span></label>
            <select name="account_type" id="account_type" 
                    class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500" 
                    required onchange="updateCategories()">
                <option value="">Select Account Type</option>
                @foreach($accountTypes as $key => $typeData)
                    <option value="{{ $key }}" {{ old('account_type') == $key ? 'selected' : '' }}>
                        {{ $typeData['label'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <!-- Account Category -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Account Category <span class="text-red-500">*</span></label>
            <select name="account_category" id="account_category" 
                    class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500" 
                    required>
                <option value="">Select Account Type First</option>
            </select>
        </div>

        <!-- Opening Balance -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Opening Balance (Rs.)</label>
            <input type="number" name="opening_balance" value="{{ old('opening_balance', 0) }}" 
                   step="0.01" 
                   class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500" 
                   placeholder="0.00">
            <p class="text-xs text-gray-500 mt-1">Initial balance for this account (optional, defaults to 0)</p>
        </div>

        <!-- Description -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
            <textarea name="description" rows="3" 
                      class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500" 
                      placeholder="Optional description or notes about this account">{{ old('description') }}</textarea>
        </div>

        <!-- Business Unit -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Business Unit</label>
            <select name="business_unit_id" 
                    class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-blue-500">
                @foreach($businessUnits as $unit)
                    <option value="{{ $unit->id }}" 
                            {{ old('business_unit_id', 1) == $unit->id ? 'selected' : '' }}
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
                       {{ old('is_active', '1') == '1' ? 'checked' : '' }}
                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="ml-2 text-sm text-gray-700">Active (account can be used in transactions)</span>
            </label>
        </div>

        <!-- Buttons -->
        <div class="flex gap-3">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
                <i class="ki-filled ki-check mr-2"></i> Create Account
            </button>
            <a href="{{ route('fin.accounts.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                Cancel
            </a>
        </div>
    </form>
</div>

<script>
// Account types with categories (from backend)
const accountTypes = @json($accountTypes);

function updateCategories() {
    const typeSelect = document.getElementById('account_type');
    const categorySelect = document.getElementById('account_category');
    const selectedType = typeSelect.value;
    
    // Clear existing options
    categorySelect.innerHTML = '<option value="">Select Category</option>';
    
    if (selectedType && accountTypes[selectedType]) {
        const categories = accountTypes[selectedType].categories;
        
        categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category;
            option.textContent = category.split('_').map(word => 
                word.charAt(0).toUpperCase() + word.slice(1)
            ).join(' ');
            categorySelect.appendChild(option);
        });
    }
}

// Initialize categories if account type is pre-selected
document.addEventListener('DOMContentLoaded', function() {
    const accountType = document.getElementById('account_type').value;
    if (accountType) {
        updateCategories();
        
        // Restore old category value if exists
        const oldCategory = '{{ old("account_category") }}';
        if (oldCategory) {
            document.getElementById('account_category').value = oldCategory;
        }
    }
});
</script>
@endsection

