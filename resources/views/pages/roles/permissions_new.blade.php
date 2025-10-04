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
                    onclick="if(confirm('Reset to default permissions for {{ $role->type }} role?')) { window.location='{{ route('roles.permissions.setDefaults', $role->id) }}' }"
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
@endsection

