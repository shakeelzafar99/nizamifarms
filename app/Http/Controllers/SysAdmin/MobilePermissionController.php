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

        // Role Access header extras (Phase 3c-v2) — same data the Web tab shows, so the
        // two tabs render one consistent header. Read-only; chips reuse the existing
        // Request-Settings endpoints exactly like the Web tab.
        $roleUserCount = DB::table('t_sys_user_role')->where('role_id', $roleId)->count();
        $approvalRows = \App\Models\SysAdmin\RoleApprovalLevelModel::where('role_id', $roleId)
            ->where('is_active', 1)->get()->keyBy('approval_level');
        $approvalL1Id = optional($approvalRows->get(1))->id;
        $approvalL2Id = optional($approvalRows->get(2))->id;
        $canManageApprovals = auth()->user()->hasPermission('manage_request_settings');

        return view('pages.roles.mobile-permissions', compact(
            'role', 'permissionsGrouped', 'currentPermissions',
            'roleUserCount', 'approvalL1Id', 'approvalL2Id', 'canManageApprovals'
        ));
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
            
            // Get the permission IDs for these codes. Restricted to ACTIVE catalog rows
            // so a crafted request can neither grant a deactivated permission nor hit
            // the unique(role_id, mobile_permission_id) constraint via a survivor row.
            $permissionIds = MobilePermissionModel::whereIn('permission_code', $permissionCodes)
                ->where('is_active', 1)
                ->pluck('id')
                ->toArray();

            // Delete only grants for permissions the form actually renders as EDITABLE
            // checkboxes, so a save can never silently revoke a grant the form couldn't
            // resubmit. Since Phase 3c the whole catalog (incl. the former
            // 'store_mode_future' placeholders) renders as enabled, muted checkboxes, so
            // the scope is simply every ACTIVE catalog row. Rows with is_active = 0 are
            // not rendered at all, so they stay excluded and their grants survive.
            $manageableIds = MobilePermissionModel::where('is_active', 1)
                ->pluck('id')
                ->toArray();
            RoleMobilePermissionModel::where('role_id', $roleId)
                ->whereIn('mobile_permission_id', $manageableIds)
                ->delete();

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

