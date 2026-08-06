@extends('layouts.app')

@section('title', 'Edit Asset')

@section('content')
<div class="max-w-3xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Edit Asset</h1>
            <p class="text-sm text-gray-600 mt-1">{{ $asset->asset_code }} - {{ $asset->asset_name }}</p>
        </div>
        <a href="{{ route('fin.assets.show', $asset->id) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Asset
        </a>
    </div>

    <!-- Info Banner -->
    <div class="mb-6 p-4 bg-blue-50 border-l-4 border-blue-400 rounded-md">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-blue-700">
                    <strong>Note:</strong> Financial details (purchase amount, date, payment account) cannot be changed after creation to maintain ledger integrity.
                </p>
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
    <form action="{{ route('fin.assets.update', $asset->id) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <!-- Purchase Summary (Read Only) -->
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-medium text-gray-700 mb-4 flex items-center gap-2">
                <span class="text-xl">💰</span> Purchase Summary (Read Only)
            </h2>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <p class="text-xs text-gray-500">Purchase Date</p>
                    <p class="text-sm font-medium text-gray-900">{{ $asset->purchase_date->format('d M Y') }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Amount</p>
                    <p class="text-sm font-medium text-emerald-600">Rs. {{ number_format($asset->purchase_amount, 0) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Business Unit</p>
                    <p class="text-sm font-medium text-gray-900">{{ $asset->businessUnit->name }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Category</p>
                    <p class="text-sm font-medium text-gray-900">{{ $asset->category->name }}</p>
                </div>
            </div>
        </div>

        <!-- Editable Fields -->
        <div class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4 flex items-center gap-2">
                <span class="text-xl">📝</span> Editable Details
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Asset Name -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Asset Name <span class="text-red-500">*</span></label>
                    <input type="text" name="asset_name" value="{{ old('asset_name', $asset->asset_name) }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" 
                           required>
                </div>

                <!-- Serial Number -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Serial Number</label>
                    <input type="text" name="serial_number" value="{{ old('serial_number', $asset->serial_number) }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500">
                </div>

                <!-- Model Number -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Model Number</label>
                    <input type="text" name="model_number" value="{{ old('model_number', $asset->model_number) }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500">
                </div>

                <!-- Location -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Location</label>
                    <input type="text" name="location" value="{{ old('location', $asset->location) }}" 
                           class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500">
                </div>

                <!-- Condition -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Condition <span class="text-red-500">*</span></label>
                    <select name="condition" class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500" required>
                        <option value="new" {{ old('condition', $asset->condition) == 'new' ? 'selected' : '' }}>New</option>
                        <option value="good" {{ old('condition', $asset->condition) == 'good' ? 'selected' : '' }}>Good</option>
                        <option value="fair" {{ old('condition', $asset->condition) == 'fair' ? 'selected' : '' }}>Fair</option>
                        <option value="poor" {{ old('condition', $asset->condition) == 'poor' ? 'selected' : '' }}>Poor</option>
                        <option value="disposed" {{ old('condition', $asset->condition) == 'disposed' ? 'selected' : '' }}>Disposed</option>
                    </select>
                </div>

                <!-- Description -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea name="description" rows="2" 
                              class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500">{{ old('description', $asset->description) }}</textarea>
                </div>

                <!-- Notes -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                    <textarea name="notes" rows="2" 
                              class="block w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:ring-2 focus:ring-emerald-500">{{ old('notes', $asset->notes) }}</textarea>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex gap-3">
            <button type="submit" class="inline-flex items-center px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md">
                <i class="ki-filled ki-check mr-2"></i> Update Asset
            </button>
            <a href="{{ route('fin.assets.show', $asset->id) }}" class="inline-flex items-center px-6 py-3 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
