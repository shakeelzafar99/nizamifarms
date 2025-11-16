<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ApprovalController;
use Illuminate\Http\Request;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\Log;

class ApprovalsAPIController extends Controller
{
    protected $approvalController;

    public function __construct()
    {
        $this->approvalController = new ApprovalController();
    }

    /**
     * Get approvals data for mobile app
     * Reuses the exact same logic as web ApprovalController
     * 
     * GET /api/mobile/approvals
     * 
     * Query params:
     * - level: 'l1', 'l2' (optional)
     * - area: 'exp_fund', 'nf_cash', 'online', 'others' (optional)
     * - assignee_id: user id (optional)
     * - last_synced: ISO timestamp for incremental sync (optional)
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            
            // Check if user has L1 or L2 approval rights
            $hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            if (!$hasLevel1Rights && !$hasLevel2Rights) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have approval rights'
                ], 403);
            }

            // Call the web controller's index method with AJAX flag
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            $request->headers->set('Accept', 'application/json');
            
            // Get the response from web controller
            $response = $this->approvalController->index($request);
            $data = $response->getData(true);
            
            if (!$data['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error loading approvals'
                ], 500);
            }

            // Get approver users list for dropdown
            $level1Users = RoleApprovalLevelModel::getUsersWithApprovalLevel(1);
            $level2Users = RoleApprovalLevelModel::getUsersWithApprovalLevel(2);
            $approverUsers = $level1Users
                ->merge($level2Users)
                ->unique('id')
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->fullname ?? $user->name ?? $user->email
                    ];
                })
                ->values()
                ->sortBy('name')
                ->values();

            // Format response for mobile
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $data['items'] ?? [],
                    'count' => $data['count'] ?? 0,
                    'total_amount' => $data['total_amount'] ?? 0,
                    'summaries' => $data['summaries'], // Will be null if no assignee filter
                    'users' => $approverUsers,
                    'current_user_id' => $user->id, // Current logged-in user ID
                    'current_user_name' => $user->fullname ?? $user->name ?? $user->email,
                    'has_level_1_rights' => $hasLevel1Rights,
                    'has_level_2_rights' => $hasLevel2Rights,
                    'last_synced' => now()->toIso8601String()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Mobile approvals error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while loading approvals'
            ], 500);
        }
    }

    /**
     * Get summary statistics only (lightweight endpoint for sync)
     * 
     * GET /api/mobile/approvals/summaries
     */
    public function summaries(Request $request)
    {
        try {
            $user = auth()->user();
            
            $hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            if (!$hasLevel1Rights && !$hasLevel2Rights) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have approval rights'
                ], 403);
            }

            // Get summaries from web controller
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            $request->headers->set('Accept', 'application/json');
            
            $response = $this->approvalController->index($request);
            $data = $response->getData(true);

            return response()->json([
                'success' => true,
                'data' => [
                    'summaries' => $data['summaries'],
                    'last_synced' => now()->toIso8601String()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Mobile approvals summaries error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred'
            ], 500);
        }
    }
}

