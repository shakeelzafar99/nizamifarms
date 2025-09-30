@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Manage Permissions</h1>
            <p class="text-gray-600">Role: <strong>{{ $role->urole_name }}</strong> ({{ ucfirst($role->type) }})</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('roles.permissions.defaults', $role->id) }}" 
               class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Set Defaults for {{ ucfirst($role->type) }}
            </a>
            <a href="{{ route('roles.index') }}" 
               class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Back to Roles
            </a>
        </div>
    </div>

    <form action="{{ route('roles.permissions.update', $role->id) }}" method="POST">
        @csrf
        @method('PUT')
        
        <div class="bg-white border border-gray-200 rounded-lg p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Navigation & Core -->
                <div class="space-y-4">
                    <h3 class="text-lg font-medium text-gray-900 border-b pb-2">Navigation & Core</h3>
                    
                    @foreach(['view_dashboard' => 'View Dashboard', 'view_orders' => 'View Orders', 'view_all_orders' => 'View All Orders (vs own assigned)', 'edit_orders' => 'Edit Orders'] as $key => $name)
                    <label class="flex items-center">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <span class="ml-3 text-sm text-gray-700">{{ $name }}</span>
                    </label>
                    @endforeach
                </div>

                <!-- Customer & Product Management -->
                <div class="space-y-4">
                    <h3 class="text-lg font-medium text-gray-900 border-b pb-2">Customer & Product Management</h3>
                    
                    @foreach(['view_customers' => 'View Customers', 'edit_customers' => 'Edit Customers', 'view_products' => 'View Products', 'edit_products' => 'Edit Products'] as $key => $name)
                    <label class="flex items-center">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <span class="ml-3 text-sm text-gray-700">{{ $name }}</span>
                    </label>
                    @endforeach
                </div>

                <!-- Operations & Status -->
                <div class="space-y-4">
                    <h3 class="text-lg font-medium text-gray-900 border-b pb-2">Operations & Status</h3>
                    
                    @foreach(['view_order_status' => 'Manage Order Status', 'view_status_history' => 'View Status History', 'view_operations' => 'Access Operations (imports, bulk actions)', 'assign_riders' => 'Assign Riders to Orders', 'bulk_operations' => 'Bulk Operations (status, rider assign)'] as $key => $name)
                    <label class="flex items-center">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <span class="ml-3 text-sm text-gray-700">{{ $name }}</span>
                    </label>
                    @endforeach
                </div>

                <!-- Administration & Attendance -->
                <div class="space-y-4">
                    <h3 class="text-lg font-medium text-gray-900 border-b pb-2">Administration & Attendance</h3>
                    
                    @foreach(['view_users' => 'Manage Users', 'view_roles' => 'Manage Roles', 'view_logs' => 'View Error Logs', 'view_attendance' => 'View Attendance', 'view_all_attendance' => 'View All Attendance (vs own)'] as $key => $name)
                    <label class="flex items-center">
                        <input type="checkbox" name="permissions[{{ $key }}]" value="1" 
                               {{ ($currentPermissions[$key] ?? false) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        <span class="ml-3 text-sm text-gray-700">{{ $name }}</span>
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
                    class="px-4 py-2 bg-blue-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-blue-700">
                Save Permissions
            </button>
        </div>
    </form>
</div>
@endsection
