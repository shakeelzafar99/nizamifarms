<?php

namespace App\Http\Controllers\SysAdmin;

use App\Http\Controllers\Controller;
use App\Models\SysAdmin\RoleModel;
use App\Models\SysAdmin\MobilePermissionModel;
use App\Models\SysAdmin\RoleMobilePermissionModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobilePermissionController extends Controller
{
    /**
     * Display mobile permissions management page for a role
     */
    public function index($roleId)
    {
        $role = RoleModel::findOrFail($roleId);
        
        // Get all mobile permissions grouped by permission_group
        $permissionsGrouped = MobilePermissionModel::getAllGrouped();
        
        // Get current role's mobile permissions
        $currentPermissions = $role->mobilePermissions()->pluck('permission_code')->toArray();
        
        return view('pages.roles.mobile-permissions', compact('role', 'permissionsGrouped', 'currentPermissions'));
    }
    
    /**
     * Update mobile permissions for a role
     */
    public function update(Request $request, $roleId)
    {
        $role = RoleModel::findOrFail($roleId);
        
        try {
            DB::beginTransaction();
            
            // Get all permission codes from the request
            $permissionCodes = array_keys($request->input('permissions', []));
            
            // Get the permission IDs for these codes
            $permissionIds = MobilePermissionModel::whereIn('permission_code', $permissionCodes)
                ->pluck('id')
                ->toArray();
            
            // Delete existing permissions for this role
            RoleMobilePermissionModel::where('role_id', $roleId)->delete();
            
            // Insert new permissions
            if (!empty($permissionIds)) {
                $insertData = [];
                foreach ($permissionIds as $permissionId) {
                    $insertData[] = [
                        'role_id' => $roleId,
                        'mobile_permission_id' => $permissionId,
                        'created_at' => now()
                    ];
                }
                RoleMobilePermissionModel::insert($insertData);
            }
            
            DB::commit();
            
            return redirect()
                ->route('roles.mobile-permissions', $roleId)
                ->with('success', 'Mobile permissions updated successfully for role: ' . $role->urole_name);
                
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update mobile permissions', [
                'role_id' => $roleId,
                'error' => $e->getMessage()
            ]);
            
            return redirect()
                ->back()
                ->with('error', 'Failed to update mobile permissions: ' . $e->getMessage());
        }
    }
}

