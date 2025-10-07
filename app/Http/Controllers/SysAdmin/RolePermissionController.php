<?php

namespace App\Http\Controllers\SysAdmin;

use App\Http\Controllers\Controller;
use App\Models\SysAdmin\RoleModel;
use App\Models\SysAdmin\RolePermissionModel;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    // Available permissions in the system
    private function getAvailablePermissions()
    {
        return [
            // Core Navigation
            'view_dashboard' => 'View Dashboard',
            
            // Orders & Deliveries
            'view_orders' => 'View Orders',
            'view_all_orders' => 'View All Orders (vs own assigned)',
            'create_orders' => 'Create New Orders',
            'edit_orders' => 'Edit Orders',
            'view_shopify_orders' => 'View Shopify Orders',
            'view_order_status' => 'Manage Order Status',
            'view_status_history' => 'View Status History',
            'assign_riders' => 'Assign Riders to Orders',
            'bulk_operations' => 'Bulk Operations (status, rider assign)',
            
            // Invoices & Quantities
            'view_invoices' => 'View Invoices',
            'view_all_invoices' => 'View All Invoices (vs own orders)',
            'view_open_quantities' => 'View Open Order Quantities',
            
            // Riders
            'view_riders' => 'View Riders List',
            'view_all_riders' => 'View All Riders (vs only self)',
            'edit_riders' => 'Edit Rider Profiles',
            
            // Customers & Products
            'view_customers' => 'View Customers',
            'edit_customers' => 'Edit Customers',
            'view_products' => 'View Products',
            'edit_products' => 'Edit Products',
            
            // Attendance
            'view_attendance' => 'View Attendance',
            'view_all_attendance' => 'View All Attendance (vs own)',
            
            // Requests & Approvals
            'view_requests' => 'View Requests',
            'view_all_requests' => 'View All Requests (vs own)',
            'create_requests' => 'Create Requests',
            'approve_requests' => 'Approve/Reject Requests',
            'manage_request_settings' => 'Manage Request Settings',
            
            // Administration
            'view_users' => 'Manage Users',
            'view_roles' => 'Manage Roles',
            'view_logs' => 'View Error Logs',
            'view_operations' => 'Access Operations (imports, bulk actions)',
        ];
    }

    public function manage($roleId)
    {
        $role = RoleModel::findOrFail($roleId);
        $availablePermissions = $this->getAvailablePermissions();
        
        // Get current permissions for this role
        $currentPermissions = RolePermissionModel::where('role_id', $roleId)
            ->pluck('is_allowed', 'permission_key')
            ->toArray();

        return view('pages.roles.permissions', compact('role', 'availablePermissions', 'currentPermissions'));
    }

    public function update(Request $request, $roleId)
    {
        $role = RoleModel::findOrFail($roleId);
        $availablePermissions = array_keys($this->getAvailablePermissions());
        
        try {
            // Delete existing permissions for this role
            RolePermissionModel::where('role_id', $roleId)->delete();
            
            // Insert new permissions
            foreach ($availablePermissions as $permissionKey) {
                $isAllowed = $request->has("permissions.{$permissionKey}");
                
                RolePermissionModel::create([
                    'role_id' => $roleId,
                    'permission_key' => $permissionKey,
                    'permission_name' => $this->getAvailablePermissions()[$permissionKey],
                    'is_allowed' => $isAllowed,
                    'created_by' => auth()->id()
                ]);
            }

            return redirect()->route('roles.index')
                ->with('success', "Permissions updated for role: {$role->urole_name}");
                
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error updating permissions: ' . $e->getMessage());
        }
    }

    // Set default permissions for a role type
    public function setDefaults($roleId)
    {
        $role = RoleModel::findOrFail($roleId);
        $availablePermissions = $this->getAvailablePermissions();
        
        // Default permissions by role type
        $defaults = [
            'rider' => [
                'view_dashboard',
                'view_orders', // Only assigned orders (no view_all_orders)
                'view_invoices', // Only own orders (no view_all_invoices)
                'view_riders', // Can see riders list but only self (no view_all_riders)
                'view_attendance', // Only own (no view_all_attendance)
                'view_requests', // Only own (no view_all_requests)
                'create_requests',
                // NOT: create_orders, edit_orders, view_shopify_orders, view_open_quantities, view_all_*
            ],
            'manager' => [
                'view_dashboard',
                'view_orders', 'view_all_orders', 'create_orders', 'edit_orders', 'view_shopify_orders',
                'view_order_status', 'view_status_history', 'assign_riders', 'bulk_operations',
                'view_invoices', 'view_all_invoices', 'view_open_quantities',
                'view_riders', 'view_all_riders', 'edit_riders',
                'view_customers', 'edit_customers',
                'view_products', 'edit_products',
                'view_attendance', 'view_all_attendance',
                'view_requests', 'view_all_requests', 'create_requests', 'approve_requests',
            ],
            'admin' => array_keys($availablePermissions), // All permissions
            'super_admin' => array_keys($availablePermissions) // All permissions
        ];

        $rolePermissions = $defaults[$role->type] ?? [];
        
        // Delete existing and set defaults
        RolePermissionModel::where('role_id', $roleId)->delete();
        
        foreach ($availablePermissions as $key => $name) {
            RolePermissionModel::create([
                'role_id' => $roleId,
                'permission_key' => $key,
                'permission_name' => $name,
                'is_allowed' => in_array($key, $rolePermissions),
                'created_by' => auth()->id()
            ]);
        }

        return redirect()->route('roles.permissions.manage', $roleId)
            ->with('success', 'Default permissions set for ' . $role->type);
    }
}
