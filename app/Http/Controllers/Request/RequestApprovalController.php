<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\Log;

class RequestApprovalController extends Controller
{
    /**
     * Approve request at a given level
     */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'level' => 'required|integer|in:1,2',
            'comments' => 'nullable|string'
        ]);

        $requestModel = RequestModel::findOrFail($id);
        $user = auth()->user();
        $level = $validated['level'];

        // Check if user has approval rights for this level
        if (!RoleApprovalLevelModel::userHasApprovalLevel($user->id, $level)) {
            return response()->json([
                'success' => false,
                'message' => "You don't have Level {$level} approval rights"
            ], 403);
        }

        // Check if request can be approved at this level
        if (!$requestModel->canBeApprovedByLevel($level)) {
            return response()->json([
                'success' => false,
                'message' => "This request cannot be approved at Level {$level} at this time"
            ], 400);
        }

        try {
            $success = $requestModel->processApproval(
                $level,
                $user->id,
                'approved',
                $validated['comments'] ?? null
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => "Request approved at Level {$level}",
                    'request_status' => $requestModel->fresh()->status
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process approval'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Request approval error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject request at a given level
     */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'level' => 'required|integer|in:1,2',
            'comments' => 'required|string'
        ]);

        $requestModel = RequestModel::findOrFail($id);
        $user = auth()->user();
        $level = $validated['level'];

        // Check if user has approval rights for this level
        if (!RoleApprovalLevelModel::userHasApprovalLevel($user->id, $level)) {
            return response()->json([
                'success' => false,
                'message' => "You don't have Level {$level} approval rights"
            ], 403);
        }

        // Check if request can be approved/rejected at this level
        if (!$requestModel->canBeApprovedByLevel($level)) {
            return response()->json([
                'success' => false,
                'message' => "This request cannot be rejected at Level {$level} at this time"
            ], 400);
        }

        try {
            $success = $requestModel->processApproval(
                $level,
                $user->id,
                'rejected',
                $validated['comments']
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => "Request rejected at Level {$level}",
                    'request_status' => $requestModel->fresh()->status
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process rejection'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Request rejection error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get approval statistics (for dashboard/reporting)
     */
    public function statistics()
    {
        $user = auth()->user();
        
        $canApproveLevel1 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
        $canApproveLevel2 = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);

        $stats = [];

        if ($canApproveLevel1) {
            $stats['pending_level_1'] = RequestModel::where('status', RequestModel::STATUS_PENDING)
                ->where('requires_level_1', 1)
                ->where('level_1_status', RequestModel::APPROVAL_STATUS_PENDING)
                ->count();
        }

        if ($canApproveLevel2) {
            $stats['pending_level_2'] = RequestModel::where('status', RequestModel::STATUS_PENDING)
                ->where('requires_level_2', 1)
                ->where('level_2_status', RequestModel::APPROVAL_STATUS_PENDING)
                ->where(function($q) {
                    $q->where('requires_level_1', 0)
                      ->orWhere('level_1_status', RequestModel::APPROVAL_STATUS_APPROVED);
                })
                ->count();
        }

        $stats['my_pending'] = RequestModel::where('requester_user_id', $user->id)
            ->where('status', RequestModel::STATUS_PENDING)
            ->count();

        $stats['my_approved'] = RequestModel::where('requester_user_id', $user->id)
            ->where('status', RequestModel::STATUS_APPROVED)
            ->count();

        $stats['my_rejected'] = RequestModel::where('requester_user_id', $user->id)
            ->where('status', RequestModel::STATUS_REJECTED)
            ->count();

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}

