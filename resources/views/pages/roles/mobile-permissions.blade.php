{{-- resources/views/pages/roles/mobile-permissions.blade.php --}}

@extends('layouts.app')

@section('title', 'Mobile App Permissions')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">📱 Mobile App Permissions</h2>
            <p class="mt-1 text-sm text-gray-600">Role: <span class="font-semibold">{{ $role->urole_name }}</span> ({{ ucfirst($role->type) }})</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('roles.permissions.manage', $role->id) }}" 
               class="px-4 py-2 bg-gray-100 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-200">
                🌐 Web Permissions
            </a>
            <a href="{{ route('roles.index') }}" 
               class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Back to Roles
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-6 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
            <strong>✓ Success:</strong> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
            <strong>✗ Error:</strong> {{ session('error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('roles.mobile-permissions.update', $role->id) }}">
        @csrf
        @method('PUT')

        <div class="bg-white shadow overflow-hidden sm:rounded-lg">
            <div class="px-6 py-4 bg-blue-50 border-b border-blue-100">
                <p class="text-sm text-blue-800">
                    <strong>💡 Tip:</strong> These permissions control what features users can access in the mobile app.
                    Grant "Access Store Mode" to allow managers/admins to use the store management features.
                </p>
            </div>

            <div class="p-6 space-y-8">

                @php
                    $groupConfig = [
                        'store_mode' => [
                            'title' => '📱 Store Mode Access',
                            'icon' => '🏪',
                            'color' => 'purple'
                        ],
                        'store_mode_orders' => [
                            'title' => '📦 Store Mode - Open Orders',
                            'icon' => '📋',
                            'color' => 'blue'
                        ],
                        'store_mode_quantities' => [
                            'title' => '📊 Store Mode - Open Order Quantities',
                            'icon' => '📈',
                            'color' => 'green'
                        ],
                        'store_mode_future' => [
                            'title' => '🚀 Store Mode - Future Features',
                            'icon' => '⏳',
                            'color' => 'gray'
                        ]
                    ];
                @endphp

                @foreach($permissionsGrouped as $group => $permissions)
                    @php
                        $config = $groupConfig[$group] ?? [
                            'title' => ucwords(str_replace('_', ' ', $group)),
                            'icon' => '📱',
                            'color' => 'gray'
                        ];
                        $borderColor = 'border-' . $config['color'] . '-200';
                    @endphp

                    <div class="space-y-3">
                        <h3 class="text-lg font-semibold text-gray-900 border-b-2 {{ $borderColor }} pb-2">
                            {{ $config['icon'] }} {{ $config['title'] }}
                        </h3>
                        
                        @foreach($permissions as $permission)
                            @php
                                $isChecked = in_array($permission->permission_code, $currentPermissions);
                                $isStoreMode = $permission->permission_code === 'access_store_mode';
                                $isFuture = $group === 'store_mode_future';
                            @endphp
                            
                            <label class="flex items-start hover:bg-gray-50 p-2 rounded {{ $isStoreMode ? 'bg-purple-50 border border-purple-200' : '' }} {{ $isFuture ? 'opacity-60' : '' }}">
                                <input type="checkbox" 
                                       name="permissions[{{ $permission->permission_code }}]" 
                                       value="1" 
                                       {{ $isChecked ? 'checked' : '' }}
                                       {{ $isFuture ? 'disabled' : '' }}
                                       class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                <div class="ml-3 flex-1">
                                    <span class="text-sm font-medium text-gray-900">
                                        {{ $permission->permission_name }}
                                        @if($isFuture)
                                            <span class="text-xs text-gray-500 italic">(Coming Soon)</span>
                                        @endif
                                    </span>
                                    @if($permission->description)
                                        <p class="text-xs {{ $isStoreMode ? 'text-purple-700 font-medium' : 'text-gray-500' }}">
                                            {{ $permission->description }}
                                        </p>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endforeach

            </div>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-between items-center">
                <div class="text-sm text-gray-600">
                    <strong>Note:</strong> Users must have "Access Store Mode" to use any store features.
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('roles.index') }}" 
                       class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        Cancel
                    </a>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        💾 Save Mobile Permissions
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

