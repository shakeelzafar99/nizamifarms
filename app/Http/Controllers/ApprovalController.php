<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\LedgerAdjustmentModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    /**
     * Unified Approvals Dashboard
     * Shows both Request Approvals (L1/L2) and Financial Approvals
     */
    public function index()
    {
        $user = auth()->user();
        
        // Check if user has L1 or L2 approval rights
        $hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
        $hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
        
        // ========== EXPENSE REQUESTS (L1/L2 Workflow) ==========
        // Get all pending requests and filter by user's approval rights
        $pendingRequests = collect();
        
        if ($hasLevel1Rights || $hasLevel2Rights) {
            $allPendingRequests = RequestModel::where('status', 'pending')
                ->with(['category', 'requester', 'paymentSourceAccount'])
                ->orderBy('submitted_at', 'asc')
                ->get();
            
            // Filter requests that this user can approve
            $pendingRequests = $allPendingRequests->filter(function($request) use ($user, $hasLevel1Rights, $hasLevel2Rights) {
                // Check if user can approve at Level 1
                if ($hasLevel1Rights && 
                    $request->requires_level_1 && 
                    $request->level_1_status === 'pending') {
                    return true;
                }
                
                // Check if user can approve at Level 2
                if ($hasLevel2Rights && 
                    $request->requires_level_2 && 
                    $request->level_1_status === 'approved' && 
                    $request->level_2_status === 'pending') {
                    return true;
                }
                
                return false;
            });
        }
        
        // ========== FINANCIAL TRANSACTIONS (Ledger Approvals) ==========
        $pendingLedger = LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)
            ->with(['fromAccount', 'toAccount', 'createdBy', 'request', 'order'])
            ->orderBy('transaction_date', 'asc')
            ->get();
        
        // Split financial transactions by cash vs online/bank
        $cashLedger = $pendingLedger->filter(function($ledger) {
            return ($ledger->fromAccount && $ledger->fromAccount->account_category === \App\Models\FIN\AccountModel::CATEGORY_CASH) ||
                   ($ledger->toAccount && $ledger->toAccount->account_category === \App\Models\FIN\AccountModel::CATEGORY_CASH);
        });
        
        $onlineLedger = $pendingLedger->filter(function($ledger) {
            return ($ledger->fromAccount && $ledger->fromAccount->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK) ||
                   ($ledger->toAccount && $ledger->toAccount->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK);
        });
        
        // ========== LEAVE/ATTENDANCE REQUESTS ==========
        // Filter leave requests from pending requests
        $leaveRequests = collect();
        $expenseRequests = collect();
        
        if ($hasLevel1Rights || $hasLevel2Rights) {
            $leaveRequests = $pendingRequests->filter(function($request) {
                return $request->category && $request->category->category_code === 'leave';
            });
            
            $expenseRequests = $pendingRequests->filter(function($request) {
                return $request->category && $request->category->category_code === 'expense';
            });
        }
        
        // ========== CALCULATE SUMMARIES ==========
        $expenseSummary = [
            'count' => $expenseRequests->count(),
            'total_amount' => $expenseRequests->sum('amount')
        ];
        
        $leaveSummary = [
            'count' => $leaveRequests->count(),
            'total_days' => $leaveRequests->sum(function($request) {
                if ($request->leave_start_date && $request->leave_end_date) {
                    return $request->leave_start_date->diffInDays($request->leave_end_date) + 1;
                }
                return 0;
            })
        ];
        
        $cashSummary = [
            'count' => $cashLedger->count(),
            'total_amount' => $cashLedger->sum('amount')
        ];
        
        $onlineSummary = [
            'count' => $onlineLedger->count(),
            'total_amount' => $onlineLedger->sum('amount')
        ];
        
        // ========== LEDGER ADJUSTMENTS (L1/L2 Workflow) ==========
        $pendingAdjustments = collect();
        
        if ($hasLevel1Rights || $hasLevel2Rights) {
            $allPendingAdjustments = LedgerAdjustmentModel::where('adjustment_status', LedgerAdjustmentModel::STATUS_PENDING)
                ->with(['ledger', 'order', 'requestedBy'])
                ->orderBy('requested_at', 'asc')
                ->get();
            
            // Filter adjustments that this user can approve
            $pendingAdjustments = $allPendingAdjustments->filter(function($adj) use ($user, $hasLevel1Rights, $hasLevel2Rights) {
                // Check if user can approve at Level 1
                if ($hasLevel1Rights && 
                    $adj->requires_level_1 && 
                    $adj->level_1_status === LedgerAdjustmentModel::APPROVAL_STATUS_PENDING) {
                    return true;
                }
                
                // Check if user can approve at Level 2
                if ($hasLevel2Rights && 
                    $adj->requires_level_2 && 
                    $adj->level_1_status === LedgerAdjustmentModel::APPROVAL_STATUS_APPROVED && 
                    $adj->level_2_status === LedgerAdjustmentModel::APPROVAL_STATUS_PENDING) {
                    return true;
                }
                
                return false;
            });
        }
        
        $adjustmentSummary = [
            'count' => $pendingAdjustments->count(),
            'total_increase' => $pendingAdjustments->filter(fn($adj) => $adj->adjustment_amount > 0)->sum('adjustment_amount'),
            'total_decrease' => abs($pendingAdjustments->filter(fn($adj) => $adj->adjustment_amount < 0)->sum('adjustment_amount'))
        ];
        
        return view('approvals.index', compact(
            'pendingRequests',
            'pendingLedger',
            'cashLedger',
            'onlineLedger',
            'leaveRequests',
            'expenseRequests',
            'pendingAdjustments',
            'expenseSummary',
            'leaveSummary',
            'cashSummary',
            'onlineSummary',
            'adjustmentSummary',
            'hasLevel1Rights',
            'hasLevel2Rights'
        ));
    }
}

