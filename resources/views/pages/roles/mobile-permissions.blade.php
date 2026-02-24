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

            {{-- ⭐ MODE ACCESS SUMMARY BANNER --}}
            @php
                $hasRiderMode = true; // Rider mode always available
                $hasStoreMode = in_array('access_store_mode', $currentPermissions);
                $hasKhaasMode = in_array('access_khaas_mode', $currentPermissions);
            @endphp
            <div class="px-6 py-4 border-b border-gray-200" style="background: linear-gradient(to right, #f8fafc, #f1f5f9);">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-gray-800 mb-2">📱 Mode Access for "{{ $role->urole_name }}"</h3>
                        <div class="flex items-center gap-3">
                            {{-- Rider Mode - Always ON --}}
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold" style="background-color: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd;">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e;"></span>
                                🏍️ Rider Mode
                                <span class="text-[10px] opacity-70">(always on)</span>
                            </span>

                            {{-- Store Mode --}}
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold" style="background-color: {{ $hasStoreMode ? '#f3e8ff' : '#f9fafb' }}; color: {{ $hasStoreMode ? '#7c3aed' : '#9ca3af' }}; border: 1px solid {{ $hasStoreMode ? '#c4b5fd' : '#e5e7eb' }};">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $hasStoreMode ? '#22c55e' : '#d1d5db' }};"></span>
                                🏪 Store Mode
                                <span class="text-[10px] opacity-70">{{ $hasStoreMode ? '(enabled)' : '(disabled)' }}</span>
                            </span>

                            {{-- Khaas Mode --}}
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold" style="background-color: {{ $hasKhaasMode ? '#fffbeb' : '#f9fafb' }}; color: {{ $hasKhaasMode ? '#d97706' : '#9ca3af' }}; border: 1px solid {{ $hasKhaasMode ? '#fcd34d' : '#e5e7eb' }};">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $hasKhaasMode ? '#22c55e' : '#d1d5db' }};"></span>
                                🌿 Khaas Mode
                                <span class="text-[10px] opacity-70">{{ $hasKhaasMode ? '(enabled)' : '(disabled)' }}</span>
                            </span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">
                            {{ count($currentPermissions) }} permission{{ count($currentPermissions) !== 1 ? 's' : '' }} granted
                        </div>
                    </div>
                </div>
            </div>

            <div class="px-6 py-3 bg-blue-50 border-b border-blue-100">
                <p class="text-sm text-blue-800">
                    <strong>💡 Tip:</strong> Use the checkboxes below to control which modes and features this role can access in the mobile app.
                    Rider mode is always available. Enable "Access Store Mode" or "Access Khaas Mode" to give additional access.
                    <strong>These same permissions also control web Khaas access.</strong>
                </p>
            </div>

            <div class="p-6 space-y-8">

                @php
                    $groupConfig = [
                        'store_mode' => [
                            'title' => '🏪 Store Mode Access',
                            'icon' => '🏪',
                            'color' => 'purple',
                            'description' => 'Controls access to Store Mode — inventory, orders, and store management features'
                        ],
                        'khaas_mode' => [
                            'title' => '🌿 Khaas Mode Access',
                            'icon' => '🌿',
                            'color' => 'amber',
                            'description' => 'Controls access to Khaas Mode — Khaas products, warehouse, expenses & vendors (web + mobile)'
                        ],
                        'store_mode_orders' => [
                            'title' => '📦 Store Mode - Open Orders',
                            'icon' => '📋',
                            'color' => 'blue',
                            'description' => null
                        ],
                        'store_mode_quantities' => [
                            'title' => '📊 Store Mode - Open Order Quantities',
                            'icon' => '📈',
                            'color' => 'green',
                            'description' => null
                        ],
                        'store_mode_expenses' => [
                            'title' => '💰 Store Mode - Expenses',
                            'icon' => '💵',
                            'color' => 'yellow',
                            'description' => null
                        ],
                        'store_mode_vendors' => [
                            'title' => '🏭 Store Mode - Vendors',
                            'icon' => '🏭',
                            'color' => 'indigo',
                            'description' => null
                        ],
                        'store_mode_future' => [
                            'title' => '🚀 Store Mode - Future Features',
                            'icon' => '⏳',
                            'color' => 'gray',
                            'description' => null
                        ]
                    ];
                @endphp

                @foreach($permissionsGrouped as $group => $permissions)
                    @php
                        $config = $groupConfig[$group] ?? [
                            'title' => ucwords(str_replace('_', ' ', $group)),
                            'icon' => '📱',
                            'color' => 'gray',
                            'description' => null
                        ];
                        // Use inline styles for border colors since Tailwind may not include dynamic class names
                        $borderColors = [
                            'purple' => '#c4b5fd',
                            'amber' => '#fcd34d',
                            'blue' => '#93c5fd',
                            'green' => '#86efac',
                            'yellow' => '#fde68a',
                            'indigo' => '#a5b4fc',
                            'gray' => '#d1d5db',
                        ];
                        $borderHex = $borderColors[$config['color']] ?? '#d1d5db';
                        
                        $isModeAccessGroup = in_array($group, ['store_mode', 'khaas_mode']);
                    @endphp

                    <div class="space-y-3" style="{{ $isModeAccessGroup ? 'padding: 12px; border: 2px solid ' . $borderHex . '; border-radius: 12px; background-color: ' . $borderHex . '10;' : '' }}">
                        <h3 class="text-lg font-semibold text-gray-900 pb-2" style="border-bottom: 2px solid {{ $borderHex }};">
                            {{ $config['icon'] }} {{ $config['title'] }}
                        </h3>
                        
                        @if($config['description'])
                            <p class="text-xs text-gray-500 -mt-1">{{ $config['description'] }}</p>
                        @endif
                        
                        @foreach($permissions as $permission)
                            @php
                                $isChecked = in_array($permission->permission_code, $currentPermissions);
                                $isModeToggle = in_array($permission->permission_code, ['access_store_mode', 'access_khaas_mode']);
                                $isFuture = $group === 'store_mode_future';
                                
                                // Highlight colors for mode toggle permissions
                                if ($permission->permission_code === 'access_store_mode') {
                                    $highlightBg = '#f3e8ff';
                                    $highlightBorder = '#c4b5fd';
                                    $highlightTextColor = '#7c3aed';
                                } elseif ($permission->permission_code === 'access_khaas_mode') {
                                    $highlightBg = '#fffbeb';
                                    $highlightBorder = '#fcd34d';
                                    $highlightTextColor = '#d97706';
                                } else {
                                    $highlightBg = '';
                                    $highlightBorder = '';
                                    $highlightTextColor = '';
                                }
                            @endphp
                            
                            <label class="flex items-start hover:bg-gray-50 p-2 rounded {{ $isFuture ? 'opacity-60' : '' }}" style="{{ $isModeToggle ? 'background-color: ' . $highlightBg . '; border: 1px solid ' . $highlightBorder . '; border-radius: 8px; padding: 10px 12px;' : '' }}">
                                <input type="checkbox" 
                                       name="permissions[{{ $permission->permission_code }}]" 
                                       value="1" 
                                       {{ $isChecked ? 'checked' : '' }}
                                       {{ $isFuture ? 'disabled' : '' }}
                                       class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50"
                                       style="{{ $isModeToggle ? 'width: 18px; height: 18px;' : '' }}">
                                <div class="ml-3 flex-1">
                                    <span class="text-sm font-medium text-gray-900" style="{{ $isModeToggle ? 'font-size: 14px; font-weight: 700; color: ' . $highlightTextColor . ';' : '' }}">
                                        {{ $permission->permission_name }}
                                        @if($isFuture)
                                            <span class="text-xs text-gray-500 italic">(Coming Soon)</span>
                                        @endif
                                        @if($isModeToggle && $isChecked)
                                            <span style="display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 999px; font-size: 10px; font-weight: 700; background-color: #dcfce7; color: #15803d;">ENABLED</span>
                                        @endif
                                    </span>
                                    @if($permission->description)
                                        <p class="text-xs" style="color: {{ $isModeToggle ? $highlightTextColor : '#6b7280' }}; {{ $isModeToggle ? 'font-weight: 500;' : '' }}">
                                            {{ $permission->description }}
                                        </p>
                                    @endif
                                    @if($permission->permission_code === 'access_khaas_mode')
                                        <p class="text-[10px] mt-1" style="color: #92400e;">
                                            ⚠️ This also controls access to the <strong>Khaas section on the web dashboard</strong> (sidebar, dashboard, products, operations, sales report).
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
                    <strong>Note:</strong> Rider mode is always available. Store/Khaas features require their respective mode permissions.
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('roles.index') }}" 
                       class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        Cancel
                    </a>
                    <button type="submit" 
                            class="px-4 py-2 border border-transparent rounded-md text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                            style="background-color: #2563eb;"
                            onmouseover="this.style.backgroundColor='#1d4ed8'"
                            onmouseout="this.style.backgroundColor='#2563eb'">
                        💾 Save Mobile Permissions
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
