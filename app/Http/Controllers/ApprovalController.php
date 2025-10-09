<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\FIN\LedgerModel;
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
                ->with(['category', 'requester'])
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
        
        // ========== CALCULATE SUMMARIES ==========
        $requestSummary = [
            'count' => $pendingRequests->count(),
            'total_amount' => $pendingRequests->sum('amount')
        ];
        
        $ledgerSummary = [
            'count' => $pendingLedger->count(),
            'total_amount' => $pendingLedger->sum('amount'),
            'by_type' => $pendingLedger->groupBy('transaction_type')
                                       ->map(function($items) {
                                           return [
                                               'count' => $items->count(),
                                               'amount' => $items->sum('amount')
                                           ];
                                       })
        ];
        
        $grandTotal = [
            'count' => $requestSummary['count'] + $ledgerSummary['count'],
            'amount' => $requestSummary['total_amount'] + $ledgerSummary['total_amount']
        ];
        
        return view('approvals.index', compact(
            'pendingRequests',
            'pendingLedger',
            'requestSummary',
            'ledgerSummary',
            'grandTotal',
            'hasLevel1Rights',
            'hasLevel2Rights'
        ));
    }
}

