@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto p-6">
    <!-- Header -->
    <div class="mb-6">
        <a href="{{ route('fin.business-units.index') }}" class="text-blue-600 hover:text-blue-800 text-sm mb-2 inline-flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to Business Units
        </a>
        <h1 class="text-2xl font-semibold text-gray-900">Edit Business Unit</h1>
        <p class="text-sm text-gray-600 mt-1">Update business unit: <strong>{{ $businessUnit->name }}</strong></p>
    </div>

    <!-- Error Messages -->
    @if($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <ul class="text-sm text-red-800 list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Form -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
        <form action="{{ route('fin.business-units.update', $businessUnit->id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="space-y-6">
                <!-- Code -->
                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700 mb-1">
                        Code <span class="text-red-500">*</span>
                    </label>
                    <input type="text" 
                           name="code" 
                           id="code" 
                           value="{{ old('code', $businessUnit->code) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono uppercase"
                           placeholder="e.g., NF_FOODS"
                           required
                           maxlength="20">
                    <p class="mt-1 text-xs text-gray-500">Unique identifier (uppercase, no spaces)</p>
                </div>

                <!-- Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                        Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" 
                           name="name" 
                           id="name" 
                           value="{{ old('name', $businessUnit->name) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                           placeholder="e.g., NF Foods"
                           required
                           maxlength="100">
                    <p class="mt-1 text-xs text-gray-500">Display name - changing this will update everywhere automatically</p>
                </div>

                <!-- Short Code -->
                <div>
                    <label for="short_code" class="block text-sm font-medium text-gray-700 mb-1">
                        Short Code
                    </label>
                    <input type="text" 
                           name="short_code" 
                           id="short_code" 
                           value="{{ old('short_code', $businessUnit->short_code) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono uppercase"
                           placeholder="e.g., NFF"
                           maxlength="10">
                    <p class="mt-1 text-xs text-gray-500">Short abbreviation for badges (2-4 characters)</p>
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                        Description
                    </label>
                    <textarea name="description" 
                              id="description" 
                              rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Brief description of this business unit...">{{ old('description', $businessUnit->description) }}</textarea>
                </div>

                <!-- Color -->
                <div>
                    <label for="color_hex" class="block text-sm font-medium text-gray-700 mb-1">
                        Color
                    </label>
                    <div class="flex items-center gap-3">
                        <input type="color" 
                               name="color_hex" 
                               id="color_hex" 
                               value="{{ old('color_hex', $businessUnit->color_hex ?? '#3B82F6') }}"
                               class="w-12 h-10 border border-gray-300 rounded cursor-pointer">
                        <input type="text" 
                               id="color_hex_text" 
                               value="{{ old('color_hex', $businessUnit->color_hex ?? '#3B82F6') }}"
                               class="w-24 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                               placeholder="#3B82F6"
                               maxlength="7"
                               onchange="document.getElementById('color_hex').value = this.value">
                        <span class="text-xs text-gray-500">Used for badges and visual identification</span>
                    </div>
                </div>

                <!-- Sort Order -->
                <div>
                    <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-1">
                        Display Order
                    </label>
                    <input type="number" 
                           name="sort_order" 
                           id="sort_order" 
                           value="{{ old('sort_order', $businessUnit->sort_order ?? $businessUnit->display_order ?? 0) }}"
                           class="w-24 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                           min="0">
                    <p class="mt-1 text-xs text-gray-500">Lower numbers appear first in dropdowns</p>
                </div>

                <!-- Active Status -->
                <div>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" 
                               name="is_active" 
                               value="1"
                               {{ old('is_active', $businessUnit->is_active) ? 'checked' : '' }}
                               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <span class="text-sm font-medium text-gray-700">Active</span>
                    </label>
                    <p class="mt-1 text-xs text-gray-500 ml-7">Inactive business units won't appear in dropdowns</p>
                </div>
            </div>

            <!-- Submit -->
            <div class="mt-8 flex items-center justify-end gap-3">
                <a href="{{ route('fin.business-units.index') }}" 
                   class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" 
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
                    Update Business Unit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Sync color picker with text input
    document.getElementById('color_hex').addEventListener('input', function() {
        document.getElementById('color_hex_text').value = this.value;
    });
    
    // Auto-uppercase code field
    document.getElementById('code').addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, '');
    });
    
    document.getElementById('short_code').addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });
</script>
@endsection
