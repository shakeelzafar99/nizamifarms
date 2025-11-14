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
     * Check if user has permission to manage request settings
     */
    private function checkPermission()
    {
        $user = auth()->user();
        if (!$user->hasPermission('manage_request_settings')) {
            abort(403, 'You do not have permission to manage request settings.');
        }
    }
    
    /**
     * Display request settings page
     */
    public function index()
    {
        // Check permission
        $user = auth()->user();
        if (!$user->hasPermission('manage_request_settings')) {
            return redirect()->route('requests.index')
                ->with('error', 'You do not have permission to manage request settings.');
        }
        
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
        $this->checkPermission();
        
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
        $this->checkPermission();
        
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
        $this->checkPermission();
        
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
        $this->checkPermission();
        
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
        $this->checkPermission();
        
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
        $this->checkPermission();
        
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

    /**
     * Get all routing rules
     */
    public function getRoutingRules()
    {
        $this->checkPermission();
        
        try {
            $rules = DB::table('t_req_approval_rules as r')
                ->leftJoin('t_fin_accounts as a', 'r.payment_source_account_id', '=', 'a.id')
                ->where('r.is_active', 1)
                ->select(
                    'r.*',
                    'a.account_name as payment_source_account_name'
                )
                ->orderBy('r.priority')
                ->orderBy('r.created_at', 'desc')
                ->get();
            
            // Get assignees for each rule
            $rulesWithAssignees = $rules->map(function($rule) {
                $assignees = DB::table('t_req_approval_rule_assignees as ra')
                    ->join('t_sys_user as u', 'ra.user_id', '=', 'u.id')
                    ->where('ra.rule_id', $rule->id)
                    ->orderBy('ra.sequence_order')
                    ->select('ra.*', 'u.fullname as user_name', 'u.name as user_username')
                    ->get()
                    ->map(function($assignee) {
                        return [
                            'user_id' => $assignee->user_id,
                            'user_name' => $assignee->user_name ?? $assignee->user_username,
                            'is_primary' => $assignee->is_primary,
                            'sequence_order' => $assignee->sequence_order
                        ];
                    });
                
                // Format display names
                $areaTypeDisplay = match($rule->area_type) {
                    'request_category' => 'Request Category',
                    'ledger_transaction' => 'Ledger Transaction',
                    'ledger_adjustment' => 'Ledger Adjustment',
                    default => $rule->area_type
                };
                
                $areaIdentifierDisplay = $this->getAreaIdentifierDisplay($rule->area_type, $rule->area_identifier);
                
                return [
                    'id' => $rule->id,
                    'rule_name' => $rule->rule_name,
                    'area_type' => $rule->area_type,
                    'area_type_display' => $areaTypeDisplay,
                    'area_identifier' => $rule->area_identifier,
                    'area_identifier_display' => $areaIdentifierDisplay,
                    'approval_level' => $rule->approval_level,
                    'payment_source_account_id' => $rule->payment_source_account_id,
                    'payment_source_account_name' => $rule->payment_source_account_name,
                    'payment_mode' => $rule->payment_mode,
                    'min_amount' => $rule->min_amount,
                    'max_amount' => $rule->max_amount,
                    'priority' => $rule->priority,
                    'assignees' => $assignees
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $rulesWithAssignees
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get routing rules error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get routing rules: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get single routing rule
     */
    public function getRoutingRule($id)
    {
        $this->checkPermission();
        
        try {
            $rule = DB::table('t_req_approval_rules')->where('id', $id)->first();
            
            if (!$rule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rule not found'
                ], 404);
            }
            
            $assignees = DB::table('t_req_approval_rule_assignees as ra')
                ->join('t_sys_user as u', 'ra.user_id', '=', 'u.id')
                ->where('ra.rule_id', $id)
                ->orderBy('ra.sequence_order')
                ->select('ra.*', 'u.fullname as user_name', 'u.name as user_username')
                ->get()
                ->map(function($assignee) {
                    return [
                        'user_id' => $assignee->user_id,
                        'user_name' => $assignee->user_name ?? $assignee->user_username,
                        'is_primary' => $assignee->is_primary,
                        'sequence_order' => $assignee->sequence_order
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => array_merge((array)$rule, ['assignees' => $assignees])
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get routing rule error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get routing rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create routing rule
     */
    public function createRoutingRule(Request $request)
    {
        $this->checkPermission();
        
        $validated = $request->validate([
            'rule_name' => 'required|string|max:255',
            'area_type' => 'required|in:request_category,ledger_transaction,ledger_adjustment',
            'area_identifier' => 'required|string|max:100',
            'approval_level' => 'required|integer|in:1,2',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'payment_mode' => 'nullable|in:cash,online',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:1',
            'assignees' => 'required|array|min:1',
            'assignees.*.user_id' => 'required|exists:t_sys_user,id',
            'assignees.*.is_primary' => 'required|boolean',
            'assignees.*.sequence_order' => 'required|integer|min:0'
        ]);
        
        try {
            DB::beginTransaction();
            
            $user = auth()->user();
            
            // Create rule
            $ruleId = DB::table('t_req_approval_rules')->insertGetId([
                'rule_name' => $validated['rule_name'],
                'area_type' => $validated['area_type'],
                'area_identifier' => $validated['area_identifier'],
                'approval_level' => $validated['approval_level'],
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                'payment_mode' => $validated['payment_mode'] ?? null,
                'min_amount' => $validated['min_amount'] ?? null,
                'max_amount' => $validated['max_amount'] ?? null,
                'assignment_strategy' => 'single_primary',
                'priority' => $validated['priority'] ?? 100,
                'is_active' => 1,
                'created_at' => now(),
                'created_by' => $user->id
            ]);
            
            // Create assignees
            foreach ($validated['assignees'] as $assignee) {
                DB::table('t_req_approval_rule_assignees')->insert([
                    'rule_id' => $ruleId,
                    'user_id' => $assignee['user_id'],
                    'is_primary' => $assignee['is_primary'],
                    'sequence_order' => $assignee['sequence_order'],
                    'created_at' => now()
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Routing rule created successfully',
                'rule_id' => $ruleId
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Create routing rule error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create routing rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update routing rule
     */
    public function updateRoutingRule(Request $request, $id)
    {
        $this->checkPermission();
        
        $validated = $request->validate([
            'rule_name' => 'required|string|max:255',
            'area_type' => 'required|in:request_category,ledger_transaction,ledger_adjustment',
            'area_identifier' => 'required|string|max:100',
            'approval_level' => 'required|integer|in:1,2',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'payment_mode' => 'nullable|in:cash,online',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:1',
            'assignees' => 'required|array|min:1',
            'assignees.*.user_id' => 'required|exists:t_sys_user,id',
            'assignees.*.is_primary' => 'required|boolean',
            'assignees.*.sequence_order' => 'required|integer|min:0'
        ]);
        
        try {
            DB::beginTransaction();
            
            $user = auth()->user();
            
            // Update rule
            DB::table('t_req_approval_rules')
                ->where('id', $id)
                ->update([
                    'rule_name' => $validated['rule_name'],
                    'area_type' => $validated['area_type'],
                    'area_identifier' => $validated['area_identifier'],
                    'approval_level' => $validated['approval_level'],
                    'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                    'payment_mode' => $validated['payment_mode'] ?? null,
                    'min_amount' => $validated['min_amount'] ?? null,
                    'max_amount' => $validated['max_amount'] ?? null,
                    'priority' => $validated['priority'] ?? 100,
                    'updated_at' => now(),
                    'updated_by' => $user->id
                ]);
            
            // Delete existing assignees
            DB::table('t_req_approval_rule_assignees')->where('rule_id', $id)->delete();
            
            // Create new assignees
            foreach ($validated['assignees'] as $assignee) {
                DB::table('t_req_approval_rule_assignees')->insert([
                    'rule_id' => $id,
                    'user_id' => $assignee['user_id'],
                    'is_primary' => $assignee['is_primary'],
                    'sequence_order' => $assignee['sequence_order'],
                    'created_at' => now()
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Routing rule updated successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update routing rule error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update routing rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete routing rule
     */
    public function deleteRoutingRule($id)
    {
        $this->checkPermission();
        
        try {
            DB::beginTransaction();
            
            // Delete assignees first
            DB::table('t_req_approval_rule_assignees')->where('rule_id', $id)->delete();
            
            // Delete rule
            DB::table('t_req_approval_rules')->where('id', $id)->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Routing rule deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete routing rule error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete routing rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Helper to get display name for area identifier
     */
    private function getAreaIdentifierDisplay($areaType, $identifier)
    {
        if ($areaType === 'request_category') {
            $category = RequestCategoryModel::where('category_code', $identifier)->first();
            return $category ? $category->category_name : $identifier;
        } elseif ($areaType === 'ledger_transaction') {
            $types = [
                'invoice' => 'Invoice',
                'expense' => 'Expense',
                'vendor_payment' => 'Vendor Payment',
                'employee_deposit' => 'Employee Deposit',
                'salary_payment' => 'Salary Payment',
                'transfer' => 'Account Transfer'
            ];
            return $types[$identifier] ?? $identifier;
        } elseif ($areaType === 'ledger_adjustment') {
            return 'Ledger Adjustment';
        }
        
        return $identifier;
    }
}

