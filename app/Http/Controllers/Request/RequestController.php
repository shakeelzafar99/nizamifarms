<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\Request\RequestCategoryModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestController extends Controller
{
    /**
     * Display requests index page
     */
    public function index(Request $request)
    {
        $categories = RequestCategoryModel::getActiveCategories();
        $user = auth()->user();
        
        // Check if user has approval rights
        $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
        $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
        
        return view('pages.requests.index', compact('categories', 'canApproveLevel1', 'canApproveLevel2'));
    }

    /**
     * Get requests data (AJAX)
     */
    public function data(Request $request)
    {
        $user = auth()->user();
        $view = $request->input('view', 'my'); // my, pending_approval, all
        
        $query = RequestModel::with(['category', 'requester', 'approvals.approver', 'createdBy'])
            ->orderByDesc('created_at');

        // Check if user has permission to view all requests
        $canViewAllRequests = $user->hasPermission('view_all_requests');
        
        // Filter based on view
        if ($view === 'my') {
            $query->where('requester_user_id', $user->id);
        } elseif ($view === 'pending_approval') {
            // Get requests where user can approve
            $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            $query->where('status', RequestModel::STATUS_PENDING);
            
            $query->where(function($q) use ($canApproveLevel1, $canApproveLevel2) {
                if ($canApproveLevel1) {
                    $q->orWhere(function($subQ) {
                        $subQ->where('requires_level_1', 1)
                             ->where('level_1_status', RequestModel::APPROVAL_STATUS_PENDING);
                    });
                }
                if ($canApproveLevel2) {
                    $q->orWhere(function($subQ) {
                        $subQ->where('requires_level_2', 1)
                             ->where('level_2_status', RequestModel::APPROVAL_STATUS_PENDING)
                             ->where(function($approvedL1) {
                                 $approvedL1->where('requires_level_1', 0)
                                            ->orWhere('level_1_status', RequestModel::APPROVAL_STATUS_APPROVED);
                             });
                    });
                }
            });
        } elseif ($view === 'all') {
            // Only users with 'view_all_requests' permission can view all
            if (!$canViewAllRequests) {
                // Fall back to 'my requests' if they don't have permission
                $query->where('requester_user_id', $user->id);
            }
            // Otherwise, show all (no filter applied)
        }

        // Additional filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $requests = $query->limit(500)->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Show create request form
     */
    public function create()
    {
        $categories = RequestCategoryModel::getActiveCategories();
        return view('pages.requests.create', compact('categories'));
    }

    /**
     * Store new request
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:t_req_category,id',
            'requester_user_id' => 'nullable|exists:t_sys_user,id', // For admin/manager creating for others
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'expense_category' => 'nullable|string|max:255', // For expense requests
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id', // Payment source selection
            'leave_start_date' => 'nullable|date',
            'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
            'leave_type' => 'nullable|string',
            'priority' => 'nullable|in:low,normal,high,urgent'
        ]);

        try {
            DB::beginTransaction();

            $category = RequestCategoryModel::with('approvalConfig')->findOrFail($validated['category_id']);
            $loggedInUser = auth()->user();
            
            // Determine who the request is for
            $requesterId = $loggedInUser->id; // Default to logged-in user
            $createdByNote = '';
            
            // If requester_user_id is provided, verify permission
            if ($request->filled('requester_user_id') && $validated['requester_user_id']) {
                // Check if logged-in user has permission to create for others
                // Get ALL user's roles (not just the first one!)
                $userRoles = DB::table('t_sys_user_role as ur')
                    ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                    ->where('ur.user_id', $loggedInUser->id)
                    ->select('r.type', 'r.urole_name')
                    ->get();
                
                $hasPermission = false;
                
                // Check by role type OR role name
                $allowedTypes = ['admin', 'manager', 'supervisor'];
                $allowedNamePatterns = ['admin', 'manager', 'supervisor', 'super'];
                
                // Check each role
                foreach ($userRoles as $roleInfo) {
                    // Check type field
                    $typeMatch = in_array(strtolower($roleInfo->type ?? ''), $allowedTypes);
                    
                    // Check name field (case-insensitive, partial match)
                    $nameMatch = false;
                    $roleName = strtolower($roleInfo->urole_name ?? '');
                    foreach ($allowedNamePatterns as $pattern) {
                        if (strpos($roleName, $pattern) !== false) {
                            $nameMatch = true;
                            break;
                        }
                    }
                    
                    // If ANY role matches, user has permission
                    if ($typeMatch || $nameMatch) {
                        $hasPermission = true;
                        break;
                    }
                }
                
                if (!$hasPermission) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to create requests for other users'
                    ], 403);
                }
                
                // Valid - create for the specified user
                $requesterId = $validated['requester_user_id'];
                $requesterUser = \App\Models\SysAdmin\UserModel::find($requesterId);
                $createdByNote = "\n\n[Created by {$loggedInUser->fullname} on behalf of employee]";
            }

            // Calculate leave days if it's a leave request
            $leaveDays = null;
            if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
                $start = \Carbon\Carbon::parse($validated['leave_start_date']);
                $end = \Carbon\Carbon::parse($validated['leave_end_date']);
                $leaveDays = $end->diffInDays($start) + 1;
            }

            // Check if the logged-in user has L1 or L2 approval rights for auto-approval
            $userHasL1 = RoleApprovalLevelModel::userHasApprovalLevel($loggedInUser->id, 1);
            $userHasL2 = RoleApprovalLevelModel::userHasApprovalLevel($loggedInUser->id, 2);
            
            // Determine initial approval statuses based on user's approval rights
            $requiresL1 = $category->requiresLevel1();
            $requiresL2 = $category->requiresLevel2();
            
            // Auto-approve L1 if user has L1 rights
            $level1Status = null;
            $level1ApprovedBy = null;
            $level1ApprovedAt = null;
            if ($requiresL1) {
                if ($userHasL1) {
                    $level1Status = RequestModel::APPROVAL_STATUS_APPROVED;
                    $level1ApprovedBy = $loggedInUser->id;
                    $level1ApprovedAt = now();
                } else {
                    $level1Status = RequestModel::APPROVAL_STATUS_PENDING;
                }
            }
            
            // Auto-approve L2 if user has L2 rights (and L1 is approved or not required)
            $level2Status = null;
            $level2ApprovedBy = null;
            $level2ApprovedAt = null;
            if ($requiresL2) {
                if ($userHasL2 && (!$requiresL1 || $level1Status === RequestModel::APPROVAL_STATUS_APPROVED)) {
                    $level2Status = RequestModel::APPROVAL_STATUS_APPROVED;
                    $level2ApprovedBy = $loggedInUser->id;
                    $level2ApprovedAt = now();
                } else {
                    $level2Status = RequestModel::APPROVAL_STATUS_PENDING;
                }
            }
            
            // Determine overall status
            $overallStatus = RequestModel::STATUS_PENDING;
            if ((!$requiresL1 || $level1Status === RequestModel::APPROVAL_STATUS_APPROVED) &&
                (!$requiresL2 || $level2Status === RequestModel::APPROVAL_STATUS_APPROVED)) {
                $overallStatus = RequestModel::STATUS_APPROVED;
            }
            
            // Create request
            $requestModel = RequestModel::create([
                'request_number' => RequestModel::generateRequestNumber(),
                'category_id' => $validated['category_id'],
                'requester_user_id' => $requesterId, // The employee the request is for
                'title' => $validated['title'],
                'description' => ($validated['description'] ?? '') . $createdByNote,
                'amount' => $validated['amount'] ?? null,
                'expense_category' => $validated['expense_category'] ?? null,
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                'leave_type' => $validated['leave_type'] ?? null,
                'leave_days' => $leaveDays,
                'status' => $overallStatus,
                'priority' => $validated['priority'] ?? 'normal',
                'requires_level_1' => $requiresL1,
                'requires_level_2' => $requiresL2,
                'level_1_status' => $level1Status,
                'level_1_approved_by' => $level1ApprovedBy,
                'level_1_approved_at' => $level1ApprovedAt,
                'level_2_status' => $level2Status,
                'level_2_approved_by' => $level2ApprovedBy,
                'level_2_approved_at' => $level2ApprovedAt,
                'submitted_at' => now(),
                'created_by' => $loggedInUser->id // Track who actually created it
            ]);

            // If auto-approved and it's an expense/salary advance, post to ledger
            if ($overallStatus === RequestModel::STATUS_APPROVED) {
                $categoryCode = $category->category_code;
                if (in_array($categoryCode, ['expense', 'salary_advance'])) {
                    try {
                        $ledgerService = app(\App\Services\FIN\LedgerPostingService::class);
                        $ledgerService->postExpenseFromRequest($requestModel);
                        Log::info("Auto-approved expense posted to ledger", [
                            'request_id' => $requestModel->id,
                            'request_number' => $requestModel->request_number
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Failed to post auto-approved expense to ledger: " . $e->getMessage(), [
                            'request_id' => $requestModel->id
                        ]);
                        // Don't fail the request creation, just log the error
                    }
                }
            }

            DB::commit();

            $autoApprovedNote = ($userHasL1 || $userHasL2) && $overallStatus === RequestModel::STATUS_APPROVED 
                ? ' (Auto-approved)' 
                : '';
            
            $message = $requesterId !== $loggedInUser->id 
                ? 'Request created successfully for ' . ($requesterUser->fullname ?? 'employee') . $autoApprovedNote
                : 'Request submitted successfully' . $autoApprovedNote;

            return response()->json([
                'success' => true,
                'message' => $message,
                'request_id' => $requestModel->id,
                'request_number' => $requestModel->request_number,
                'auto_approved' => $overallStatus === RequestModel::STATUS_APPROVED
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Request creation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show request details
     */
    public function show($id)
    {
        $request = RequestModel::with([
            'category',
            'requester',
            'approvals.approver',
            'createdBy',
            'updatedBy',
            'paymentSourceAccount'
        ])->findOrFail($id);

        $user = auth()->user();
        
        // Check if user can approve this request
        $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1) 
            && $request->canBeApprovedByLevel(1);
        $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2) 
            && $request->canBeApprovedByLevel(2);

        return view('pages.requests.show', compact('request', 'canApproveLevel1', 'canApproveLevel2'));
    }

    /**
     * Update request (only for requester, and only if pending)
     */
    public function update(Request $request, $id)
    {
        $requestModel = RequestModel::findOrFail($id);
        $user = auth()->user();

        // Only requester can update, and only if pending
        if ($requestModel->requester_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only update your own requests'
            ], 403);
        }

        if (!$requestModel->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update request that is not pending'
            ], 400);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'leave_start_date' => 'nullable|date',
            'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
            'leave_type' => 'nullable|string',
            'priority' => 'nullable|in:low,normal,high,urgent'
        ]);

        try {
            // Calculate leave days if it's a leave request
            $leaveDays = null;
            if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
                $start = \Carbon\Carbon::parse($validated['leave_start_date']);
                $end = \Carbon\Carbon::parse($validated['leave_end_date']);
                $leaveDays = $end->diffInDays($start) + 1;
            }

            $requestModel->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                'leave_type' => $validated['leave_type'] ?? null,
                'leave_days' => $leaveDays,
                'priority' => $validated['priority'] ?? $requestModel->priority,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Request update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel request (only for requester, and only if pending)
     */
    public function cancel($id)
    {
        $request = RequestModel::findOrFail($id);
        $user = auth()->user();

        if ($request->requester_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only cancel your own requests'
            ], 403);
        }

        if (!$request->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel request that is not pending'
            ], 400);
        }

        try {
            $request->update([
                'status' => RequestModel::STATUS_CANCELLED,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request cancelled successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Request cancellation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Find request by request number (for clickable links in ledger)
     */
    public function findByNumber($requestNumber)
    {
        try {
            $request = RequestModel::where('request_number', $requestNumber)->first();
            
            if (!$request) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'request_id' => $request->id,
                'request_number' => $request->request_number
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error finding request by number: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error finding request'
            ], 500);
        }
    }
}

