<?php

namespace App\Http\Controllers\SysAdmin;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\RoleModel;
use App\Models\SysAdmin\UserRoleModel;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        // Get roles with user count
        $roles = RoleModel::withCount('userRoles')->paginate(10);
        
        return view('pages.roles.index', compact('roles'));
    }

    public function show($id)
    {
        try {
            $role = RoleModel::with(['userRoles.user'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'role' => $role
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'urole_name' => 'required|string|max:255|unique:t_sys_role,urole_name',
            'type' => 'required|string',
            'description' => 'nullable|string|max:500',
            'expense_backdate_days' => 'nullable|integer|min:0|max:30'
        ]);

        try {
            RoleModel::create([
                'urole_name' => $request->urole_name,
                'type' => $request->type,
                'description' => $request->description,
                'is_active' => $request->is_active ?? 1,
                'expense_backdate_days' => $request->expense_backdate_days ?? 0,
                'is_default' => 0,
                'company_id' => 1, // Default company for now
                'created_by' => auth()->id()
            ]);

            return redirect()->route('roles.index')->with('success', 'Role created successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error creating role: ' . $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'urole_name' => 'required|string|max:255|unique:t_sys_role,urole_name,' . $id,
            'type' => 'required|string',
            'description' => 'nullable|string|max:500',
            'expense_backdate_days' => 'nullable|integer|min:0|max:30'
        ]);

        try {
            $role = RoleModel::findOrFail($id);
            
            $role->update([
                'urole_name' => $request->urole_name,
                'type' => $request->type,
                'description' => $request->description,
                'is_active' => $request->is_active ?? 1,
                'expense_backdate_days' => $request->expense_backdate_days ?? 0,
                'updated_by' => auth()->id()
            ]);

            return redirect()->route('roles.index')->with('success', 'Role updated successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error updating role: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            $role = RoleModel::findOrFail($id);
            
            // Check if role has users assigned
            $userCount = UserRoleModel::where('role_id', $id)->count();
            if ($userCount > 0) {
                return redirect()->back()->with('error', 'Cannot delete role. It has ' . $userCount . ' user(s) assigned to it.');
            }

            $role->delete();

            return redirect()->route('roles.index')->with('success', 'Role deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error deleting role: ' . $e->getMessage());
        }
    }
}