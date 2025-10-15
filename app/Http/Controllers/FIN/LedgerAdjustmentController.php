<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\LedgerAdjustmentModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\Log;

class LedgerAdjustmentController extends Controller
{
    /**
     * Display a list of pending ledger adjustments
     */
    public function index()
    {
        $adjustments = LedgerAdjustmentModel::where('adjustment_status', LedgerAdjustmentModel::STATUS_PENDING)
            ->with(['ledger', 'order', 'requestedBy', 'level1Approver', 'level2Approver'])
            ->orderBy('requested_at', 'asc')
            ->get();
        
        // Check if user has approval rights
        $user = auth()->user();
        $hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
        $hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
        
        return view('fin.adjustments.index', compact('adjustments', 'hasLevel1Rights', 'hasLevel2Rights'));
    }
    
    /**
     * Approve a ledger adjustment at a specific level
     */
    public function approve(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'level' => 'required|integer|in:1,2',
                'comments' => 'nullable|string|max:1000'
            ]);
            
            $adjustment = LedgerAdjustmentModel::with(['ledger', 'order'])->findOrFail($id);
            $user = auth()->user();
            $level = $validated['level'];
            
            // Check if user has approval rights for this level
            if (!RoleApprovalLevelModel::userHasApprovalLevel($user->id, $level)) {
                return back()->with('error', "You don't have Level {$level} approval rights.");
            }
            
            // Check if adjustment can be approved at this level
            if (!$adjustment->canBeApprovedByLevel($level)) {
                return back()->with('error', "This adjustment cannot be approved at Level {$level} at this time.");
            }
            
            // Process the approval
            $success = $adjustment->processApproval(
                $level,
                $user->id,
                'approved',
                $validated['comments'] ?? null
            );
            
            if ($success) {
                $adjustment->refresh(); // Reload to get updated status
                
                $message = $adjustment->adjustment_status === LedgerAdjustmentModel::STATUS_APPROVED
                    ? "Ledger adjustment #{$adjustment->id} approved and applied successfully!" 
                    : "Ledger adjustment #{$adjustment->id} approved at Level {$level}. Awaiting Level " . ($level + 1) . " approval.";
                
                return back()->with('success', $message);
            }
            
            return back()->with('error', "Failed to approve ledger adjustment #{$adjustment->id} at Level {$level}.");
            
        } catch (\Exception $e) {
            Log::error("Error approving ledger adjustment #{$id}: " . $e->getMessage(), ['exception' => $e]);
            return back()->with('error', 'An unexpected error occurred during approval: ' . $e->getMessage());
        }
    }
    
    /**
     * Reject a ledger adjustment at a specific level
     */
    public function reject(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'level' => 'required|integer|in:1,2',
                'comments' => 'nullable|string|max:1000' // Optional reason for rejection
            ]);
            
            $adjustment = LedgerAdjustmentModel::with(['ledger', 'order'])->findOrFail($id);
            $user = auth()->user();
            $level = $validated['level'];
            
            // Check if user has approval rights for this level
            if (!RoleApprovalLevelModel::userHasApprovalLevel($user->id, $level)) {
                return back()->with('error', "You don't have Level {$level} approval rights.");
            }
            
            // Check if adjustment can be rejected at this level
            if (!$adjustment->canBeApprovedByLevel($level)) {
                return back()->with('error', "This adjustment cannot be rejected at Level {$level} at this time.");
            }
            
            // Process the rejection
            $success = $adjustment->processApproval(
                $level,
                $user->id,
                'rejected',
                $validated['comments']
            );
            
            if ($success) {
                return back()->with('success', "Ledger adjustment #{$adjustment->id} rejected at Level {$level}. The order remains unchanged.");
            }
            
            return back()->with('error', "Failed to reject ledger adjustment #{$adjustment->id} at Level {$level}.");
            
        } catch (\Exception $e) {
            Log::error("Error rejecting ledger adjustment #{$id}: " . $e->getMessage(), ['exception' => $e]);
            return back()->with('error', 'An unexpected error occurred during rejection: ' . $e->getMessage());
        }
    }
    
    /**
     * View details of a specific adjustment
     */
    public function show($id)
    {
        $adjustment = LedgerAdjustmentModel::with([
            'ledger.fromAccount',
            'ledger.toAccount',
            'order.customer',
            'order.lineItems',
            'requestedBy',
            'level1Approver',
            'level2Approver'
        ])->findOrFail($id);
        
        return view('fin.adjustments.show', compact('adjustment'));
    }
}

