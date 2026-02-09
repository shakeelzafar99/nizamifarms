{{-- resources/views/pages/roles/permissions.blade.php --}}

@extends('layouts.app')

@section('title', 'Manage Permissions')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">Manage Permissions</h2>
            <p class="mt-1 text-sm text-gray-600">Role: <span class="font-semibold">{{ $role->urole_name }}</span> ({{ ucfirst($role->type) }})</p>
        </div>
        <div class="flex gap-3">
            <button type="button" 
                    onclick="if(confirm('Reset to default permissions for {{ $role->type }} role?')) { window.location='{{ route('roles.permissions.defaults', $role->id) }}' }"
                    class="px-4 py-2 bg-gray-100 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-200">
                Set Defaults for {{ ucfirst($role->type) }}
            </button>
            <a href="{{ route('roles.index') }}" 
               class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Back to Roles
            </a>
        </div>
    </div>

    <form method="POST" action="{{ route('roles.permissions.update', $role->id) }}">
        @csrf
        @method('PUT')

        <div class="bg-white shadow overflow-hidden sm:rounded-lg">
            <div class="px-6 py-4 bg-blue-50 border-b border-blue-100">
                <p class="text-sm text-blue-800">
                    <strong>💡 Tip:</strong> Permissions with "<em>(vs own)</em>" control whether users see all records or only their own data.
                    Uncheck these for riders to restrict them to their own records.
                </p>
            </div>

            <div class="p-6 space-y-8">

                <!-- Core Access -->
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-gray-200 pb-2">📊 Core Access</h3>
                    @foreach([
                        'view_dashboard' => ['label' => 'View Dashboard', 'help' => 'Access to main dashboard'],
                    ] as $key => $config)
                    <label class="flex items-start hover:bg-gray-50 p-2 rounded">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <div class="ml-3">
                            <span class="text-sm font-medium text-gray-900">{{ $config['label'] }}</span>
                            <p class="text-xs text-gray-500">{{ $config['help'] }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Orders & Deliveries -->
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-blue-200 pb-2">📦 Orders & Deliveries</h3>
                    @foreach([
                        'view_orders' => ['label' => 'View Orders', 'help' => 'Access to orders page'],
                        'view_all_orders' => ['label' => 'View All Orders (vs own assigned)', 'help' => 'See all orders. Uncheck = riders see only assigned orders', 'highlight' => true],
                        'edit_orders' => ['label' => 'Edit Orders', 'help' => 'Show edit button and allow modifications'],
                        'view_shopify_orders' => ['label' => 'View Shopify Orders', 'help' => 'See orders from Shopify. Uncheck to hide Shopify orders', 'highlight' => true],
                        'view_order_status' => ['label' => 'Manage Order Status', 'help' => 'Change order statuses'],
                        'view_status_history' => ['label' => 'View Status History', 'help' => 'See order status change history'],
                        'assign_riders' => ['label' => 'Assign Riders to Orders', 'help' => 'Assign delivery riders'],
                        'bulk_operations' => ['label' => 'Bulk Operations', 'help' => 'Bulk status changes and assignments'],
                    ] as $key => $config)
                    <label class="flex items-start hover:bg-gray-50 p-2 rounded {{ ($config['highlight'] ?? false) ? 'bg-yellow-50' : '' }}">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-gray-900">{{ $config['label'] }}</span>
                            <p class="text-xs {{ ($config['highlight'] ?? false) ? 'text-yellow-700 font-medium' : 'text-gray-500' }}">{{ $config['help'] }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Invoices & Quantities -->
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-green-200 pb-2">💰 Invoices & Quantities</h3>
                    @foreach([
                        'view_invoices' => ['label' => 'View Invoices', 'help' => 'Access to invoices page'],
                        'view_all_invoices' => ['label' => 'View All Invoices (vs own orders)', 'help' => 'See all invoices. Uncheck = riders see only invoices for their assigned orders', 'highlight' => true],
                        'view_open_quantities' => ['label' => 'View Open Order Quantities', 'help' => 'Access to open quantities page. Uncheck to hide page from riders', 'highlight' => true],
                    ] as $key => $config)
                    <label class="flex items-start hover:bg-gray-50 p-2 rounded {{ ($config['highlight'] ?? false) ? 'bg-yellow-50' : '' }}">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-gray-900">{{ $config['label'] }}</span>
                            <p class="text-xs {{ ($config['highlight'] ?? false) ? 'text-yellow-700 font-medium' : 'text-gray-500' }}">{{ $config['help'] }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Riders -->
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-purple-200 pb-2">🏍️ Riders</h3>
                    @foreach([
                        'view_riders' => ['label' => 'View Riders List', 'help' => 'Access to riders page'],
                        'view_all_riders' => ['label' => 'View All Riders (vs only self)', 'help' => 'See all riders. Uncheck = riders see only themselves', 'highlight' => true],
                        'edit_riders' => ['label' => 'Edit Rider Profiles', 'help' => 'Modify rider information and shifts'],
                    ] as $key => $config)
                    <label class="flex items-start hover:bg-gray-50 p-2 rounded {{ ($config['highlight'] ?? false) ? 'bg-yellow-50' : '' }}">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-gray-900">{{ $config['label'] }}</span>
                            <p class="text-xs {{ ($config['highlight'] ?? false) ? 'text-yellow-700 font-medium' : 'text-gray-500' }}">{{ $config['help'] }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Customers & Products -->
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-pink-200 pb-2">👥 Customers & Products</h3>
                    @foreach([
                        'view_customers' => ['label' => 'View Customers', 'help' => 'Access to customers page'],
                        'edit_customers' => ['label' => 'Edit Customers', 'help' => 'Modify customer information'],
                        'view_products' => ['label' => 'View Products', 'help' => 'Access to products page'],
                        'edit_products' => ['label' => 'Edit Products', 'help' => 'Modify product information'],
                    ] as $key => $config)
                    <label class="flex items-start hover:bg-gray-50 p-2 rounded">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-gray-900">{{ $config['label'] }}</span>
                            <p class="text-xs text-gray-500">{{ $config['help'] }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Attendance -->
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-indigo-200 pb-2">📅 Attendance</h3>
                    @foreach([
                        'view_attendance' => ['label' => 'View Attendance', 'help' => 'Access to attendance pages'],
                        'view_all_attendance' => ['label' => 'View All Attendance (vs own)', 'help' => 'See all attendance records. Uncheck = riders see only their own', 'highlight' => true],
                    ] as $key => $config)
                    <label class="flex items-start hover:bg-gray-50 p-2 rounded {{ ($config['highlight'] ?? false) ? 'bg-yellow-50' : '' }}">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-gray-900">{{ $config['label'] }}</span>
                            <p class="text-xs {{ ($config['highlight'] ?? false) ? 'text-yellow-700 font-medium' : 'text-gray-500' }}">{{ $config['help'] }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Requests & Approvals -->
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-orange-200 pb-2">📝 Requests & Approvals</h3>
                    @foreach([
                        'view_requests' => ['label' => 'View Requests', 'help' => 'Access to requests page'],
                        'view_all_requests' => ['label' => 'View All Requests (vs own)', 'help' => 'See all requests. Uncheck = riders see only their own', 'highlight' => true],
                        'create_requests' => ['label' => 'Create Requests', 'help' => 'Submit new requests (leave, etc.)'],
                        'approve_requests' => ['label' => 'Approve/Reject Requests', 'help' => 'Act on pending requests'],
                        'manage_request_settings' => ['label' => 'Manage Request Settings', 'help' => 'Configure approval workflows'],
                    ] as $key => $config)
                    <label class="flex items-start hover:bg-gray-50 p-2 rounded {{ ($config['highlight'] ?? false) ? 'bg-yellow-50' : '' }}">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-gray-900">{{ $config['label'] }}</span>
                            <p class="text-xs {{ ($config['highlight'] ?? false) ? 'text-yellow-700 font-medium' : 'text-gray-500' }}">{{ $config['help'] }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Administration -->
                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-red-200 pb-2">⚙️ Administration</h3>
                    @foreach([
                        'view_users' => ['label' => 'Manage Users', 'help' => 'Create and edit user accounts'],
                        'view_roles' => ['label' => 'Manage Roles', 'help' => 'Create and edit roles & permissions'],
                        'view_logs' => ['label' => 'View Error Logs', 'help' => 'Access system error logs'],
                        'view_operations' => ['label' => 'Access Operations', 'help' => 'Imports, bulk actions, advanced features'],
                    ] as $key => $config)
                    <label class="flex items-start hover:bg-gray-50 p-2 rounded">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-gray-900">{{ $config['label'] }}</span>
                            <p class="text-xs text-gray-500">{{ $config['help'] }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Business Unit Access -->
                <div class="space-y-4 bg-gradient-to-r from-purple-50 to-indigo-50 p-6 rounded-lg border border-purple-200">
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-purple-300 pb-2">🏢 Business Unit Access</h3>
                    <p class="text-sm text-purple-700 mb-4">
                        Control which business units users with this role can access for expenses, vendors, and company accounts.
                    </p>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-2">Access Type</label>
                            <select name="business_unit_access" id="business_unit_access" 
                                    onchange="toggleBusinessUnitOptions()"
                                    class="w-full px-3 py-2 border-2 border-purple-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 bg-white text-gray-900">
                                <option value="all" {{ ($role->business_unit_access ?? 'all') == 'all' ? 'selected' : '' }}>
                                    All Business Units (Full Access)
                                </option>
                                <option value="single" {{ ($role->business_unit_access ?? '') == 'single' ? 'selected' : '' }}>
                                    Single Business Unit Only
                                </option>
                                <option value="multiple" {{ ($role->business_unit_access ?? '') == 'multiple' ? 'selected' : '' }}>
                                    Multiple Business Units
                                </option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">
                                "All" = sees all company accounts | "Single/Multiple" = only sees accounts tagged to assigned units
                            </p>
                        </div>
                        
                        <div id="default_bu_section">
                            <label class="block text-sm font-semibold text-gray-800 mb-2">Default Business Unit</label>
                            <select name="default_business_unit_id" 
                                    class="w-full px-3 py-2 border-2 border-purple-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 bg-white text-gray-900">
                                @foreach($businessUnits ?? [] as $bu)
                                    <option value="{{ $bu->id }}" {{ ($role->default_business_unit_id ?? 1) == $bu->id ? 'selected' : '' }}
                                            style="color: {{ $bu->color_hex ?? '#374151' }}">
                                        {{ $bu->name }} {{ $bu->short_code ? '(' . $bu->short_code . ')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Pre-selected when creating expenses or vendors</p>
                        </div>
                        
                        <div id="multiple_bu_section" style="display: none;">
                            <label class="block text-sm font-semibold text-gray-800 mb-2">Assigned Business Units</label>
                            <div class="space-y-2 bg-white p-4 rounded-lg border border-purple-200">
                                @foreach($businessUnits ?? [] as $bu)
                                    <label class="flex items-center hover:bg-purple-50 p-2 rounded">
                                        <input type="checkbox" name="assigned_business_units[]" value="{{ $bu->id }}"
                                               {{ in_array($bu->id, $assignedBusinessUnits ?? []) ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-purple-600 shadow-sm focus:ring-purple-500">
                                        <span class="ml-3 text-sm font-medium" style="color: {{ $bu->color_hex ?? '#374151' }}">
                                            {{ $bu->name }} {{ $bu->short_code ? '(' . $bu->short_code . ')' : '' }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Select which business units this role can access</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <a href="{{ route('roles.index') }}" 
               class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" 
                    class="px-4 py-2 bg-blue-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                💾 Save Permissions
            </button>
        </div>
    </form>
</div>

<script>
function toggleBusinessUnitOptions() {
    const accessType = document.getElementById('business_unit_access').value;
    const multipleBuSection = document.getElementById('multiple_bu_section');
    const defaultBuSection = document.getElementById('default_bu_section');
    
    if (accessType === 'all') {
        multipleBuSection.style.display = 'none';
        defaultBuSection.style.display = 'block';
    } else if (accessType === 'single') {
        multipleBuSection.style.display = 'none';
        defaultBuSection.style.display = 'block';
    } else if (accessType === 'multiple') {
        multipleBuSection.style.display = 'block';
        defaultBuSection.style.display = 'block';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleBusinessUnitOptions();
});
</script>
@endsection

