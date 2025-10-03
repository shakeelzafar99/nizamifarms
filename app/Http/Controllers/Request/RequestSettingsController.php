<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestCategoryModel;
use App\Models\Request\RequestCategoryApprovalConfigModel;
use App\Models\SysAdmin\RoleModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestSettingsController extends Controller
{
    /**
     * Display request settings page
     */
    public function index()
    {
        $categories = RequestCategoryModel::with('approvalConfig')->active()->ordered()->get();
        $roles = RoleModel::where('is_active', 1)->get();
        
        // Get current approval level assignments
        $level1Roles = RoleApprovalLevelModel::with('role')->active()->level(1)->get();
        $level2Roles = RoleApprovalLevelModel::with('role')->active()->level(2)->get();
        
        return view('pages.requests.settings', compact('categories', 'roles', 'level1Roles', 'level2Roles'));
    }

    /**
     * Update category approval configuration
     */
    public function updateCategoryConfig(Request $request, $categoryId)
    {
        $validated = $request->validate([
            'requires_level_1' => 'required|boolean',
            'requires_level_2' => 'required|boolean',
            'auto_approve_threshold' => 'nullable|numeric|min:0'
        ]);

        try {
            $category = RequestCategoryModel::findOrFail($categoryId);
            $user = auth()->user();

            $config = RequestCategoryApprovalConfigModel::updateOrCreate(
                ['category_id' => $categoryId],
                [
                    'requires_level_1' => $validated['requires_level_1'],
                    'requires_level_2' => $validated['requires_level_2'],
                    'auto_approve_threshold' => $validated['auto_approve_threshold'] ?? null,
                    'updated_by' => $user->id
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Approval configuration updated successfully',
                'data' => $config
            ]);

        } catch (\Exception $e) {
            Log::error('Category config update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign role to approval level
     */
    public function assignRoleToLevel(Request $request)
    {
        $validated = $request->validate([
            'role_id' => 'required|exists:t_sys_role,id',
            'approval_level' => 'required|integer|in:1,2'
        ]);

        try {
            $user = auth()->user();

            // Check if this role already has this level
            $existing = RoleApprovalLevelModel::where('role_id', $validated['role_id'])
                ->where('approval_level', $validated['approval_level'])
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'This role already has this approval level'
                ], 400);
            }

            $roleLevel = RoleApprovalLevelModel::create([
                'role_id' => $validated['role_id'],
                'approval_level' => $validated['approval_level'],
                'is_active' => 1,
                'created_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role assigned to approval level successfully',
                'data' => $roleLevel->load('role')
            ]);

        } catch (\Exception $e) {
            Log::error('Role level assignment error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign role: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove role from approval level
     */
    public function removeRoleFromLevel($id)
    {
        try {
            $roleLevel = RoleApprovalLevelModel::findOrFail($id);
            $roleLevel->delete();

            return response()->json([
                'success' => true,
                'message' => 'Role removed from approval level successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Role level removal error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove role: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get users with approval level (for debugging/info)
     */
    public function getUsersWithLevel($level)
    {
        try {
            $users = RoleApprovalLevelModel::getUsersWithApprovalLevel($level);

            return response()->json([
                'success' => true,
                'data' => $users
            ]);

        } catch (\Exception $e) {
            Log::error('Get users with level error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update category details (name, description, etc.)
     */
    public function updateCategory(Request $request, $id)
    {
        $validated = $request->validate([
            'category_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color_class' => 'nullable|string|max:50',
            'is_active' => 'required|boolean',
            'sequence_order' => 'required|integer|min:0'
        ]);

        try {
            $category = RequestCategoryModel::findOrFail($id);
            $user = auth()->user();

            $category->update([
                'category_name' => $validated['category_name'],
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? null,
                'color_class' => $validated['color_class'] ?? null,
                'is_active' => $validated['is_active'],
                'sequence_order' => $validated['sequence_order'],
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $category
            ]);

        } catch (\Exception $e) {
            Log::error('Category update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update category: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new category
     */
    public function createCategory(Request $request)
    {
        $validated = $request->validate([
            'category_code' => 'required|string|max:50|unique:t_req_category,category_code',
            'category_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color_class' => 'nullable|string|max:50'
        ]);

        try {
            DB::beginTransaction();
            
            $user = auth()->user();

            // Get next sequence order
            $maxSequence = RequestCategoryModel::max('sequence_order') ?? 0;

            $category = RequestCategoryModel::create([
                'category_code' => $validated['category_code'],
                'category_name' => $validated['category_name'],
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? 'file-text',
                'color_class' => $validated['color_class'] ?? 'gray',
                'is_active' => 1,
                'sequence_order' => $maxSequence + 1,
                'created_by' => $user->id
            ]);

            // Create default approval config
            RequestCategoryApprovalConfigModel::create([
                'category_id' => $category->id,
                'requires_level_1' => 1,
                'requires_level_2' => 0,
                'created_by' => $user->id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $category->load('approvalConfig')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category creation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category: ' . $e->getMessage()
            ], 500);
        }
    }
}

