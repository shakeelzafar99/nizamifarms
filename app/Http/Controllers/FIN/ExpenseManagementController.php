<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Request\RequestModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use App\Services\FIN\ExpenseSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpenseManagementController extends Controller
{
    protected $settlementService;
    
    public function __construct(ExpenseSettlementService $settlementService)
    {
        $this->settlementService = $settlementService;
    }
    
    /**
     * Display expense management dashboard
     */
    public function index(Request $request)
    {
        // Get filter parameters
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $category = $request->input('category');
        $paymentSource = $request->input('payment_source');
        $settlementStatus = $request->input('settlement_status');
        
        // Build base query for all expenses AND salary advances (both have settlement tracking)
        $expensesQuery = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'salary_advance']);
            })
            ->whereNotNull('ledger_transaction_id')
            ->with(['requester', 'paymentSourceAccount', 'category', 'settledBy', 'settlementDestinationAccount']);
        
        // Apply filters
        if ($dateFrom && $dateTo) {
            $expensesQuery->whereBetween('created_at', [$dateFrom, $dateTo]);
        }
        
        if ($category) {
            $expensesQuery->where('expense_category', $category);
        }
        
        if ($paymentSource) {
            $expensesQuery->where('payment_source_account_id', $paymentSource);
        }
        
        if ($settlementStatus) {
            $expensesQuery->where('settlement_status', $settlementStatus);
        }
        
        $allExpenses = $expensesQuery->orderBy('created_at', 'desc')->get();
        
        // Calculate KPIs
        $totalExpenses = $allExpenses->sum('amount');
        
        $fromExpenseFund = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'not_required';
        })->sum('amount');
        
        $needsSettlement = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'pending';
        })->sum('amount');
        
        $settled = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'settled';
        })->sum('amount');
        
        // Get expenses needing settlement (for priority view)
        $pendingSettlement = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'pending';
        })->sortBy('created_at');
        
        // Get settlement history (most recent)
        $settlementHistory = $allExpenses->filter(function($exp) {
            return $exp->settlement_status === 'settled';
        })->sortByDesc('settled_at')->take(20);
        
        // Get expense categories for filter
        $categories = ConfigModel::where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
            ->pluck('config_value')
            ->unique()
            ->sort();
        
        // Get payment sources for filter
        $paymentSources = AccountModel::whereIn('account_type', ['asset'])
            ->where('is_active', 1)
            ->orderBy('account_name')
            ->get();
        
        // Get settlement sources (for settlement modal)
        $settlementSources = AccountModel::whereIn('account_code', ['EXP_FUND', 'NF_CASH', 'ONLINE'])
            ->where('is_active', 1)
            ->get();
        
        // Get pending approvals (real-time, not filtered by date)
        // Include both expenses and salary advances
        $pendingApprovals = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'salary_advance']);
            })
            ->where('status', RequestModel::STATUS_PENDING)
            ->with(['requester', 'paymentSourceAccount', 'category'])
            ->orderBy('created_at', 'asc')
            ->get();
        
        $kpis = [
            'total_expenses' => $totalExpenses,
            'from_expense_fund' => $fromExpenseFund,
            'needs_settlement' => $needsSettlement,
            'settled' => $settled,
            'pending_count' => $pendingSettlement->count(),
            'settled_count' => $settlementHistory->count(),
            'pending_approvals' => $pendingApprovals->sum('amount'),
            'pending_approvals_count' => $pendingApprovals->count()
        ];
        
        return view('fin.expense.index', compact(
            'allExpenses',
            'pendingSettlement',
            'settlementHistory',
            'kpis',
            'categories',
            'paymentSources',
            'settlementSources',
            'pendingApprovals',
            'dateFrom',
            'dateTo',
            'category',
            'paymentSource',
            'settlementStatus'
        ));
    }
    
    /**
     * Settle a single expense
     */
    public function settle(Request $request, $id)
    {
        try {
            \Log::info("Settlement request received", [
                'expense_id' => $id,
                'request_data' => $request->all()
            ]);
            
            $validated = $request->validate([
                'settlement_source_account_id' => 'nullable|exists:t_fin_accounts,id',
                'notes' => 'nullable|string|max:1000'
            ]);
            
            \Log::info("Validation passed", ['validated' => $validated]);
            
            $expenseRequest = RequestModel::findOrFail($id);
            
            \Log::info("Expense request found", [
                'request_number' => $expenseRequest->request_number,
                'settlement_status' => $expenseRequest->settlement_status
            ]);
            
            $result = $this->settlementService->settleExpense(
                $expenseRequest,
                $validated['settlement_source_account_id'] ?? null,
                $validated['notes'] ?? null
            );
            
            \Log::info("Settlement service completed", ['result' => $result]);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }
        } catch (\Exception $e) {
            \Log::error("Settlement failed with exception", [
                'expense_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Settlement failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Bulk settle multiple expenses
     */
    public function bulkSettle(Request $request)
    {
        $validated = $request->validate([
            'expense_ids' => 'required|array',
            'expense_ids.*' => 'exists:t_req_master,id',
            'settlement_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'notes' => 'nullable|string|max:1000'
        ]);
        
        $result = $this->settlementService->bulkSettle(
            $validated['expense_ids'],
            $validated['settlement_source_account_id'] ?? null,
            $validated['notes'] ?? null
        );
        
        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'details' => [
                    'success_count' => $result['success_count'],
                    'fail_count' => $result['fail_count'],
                    'errors' => $result['errors']
                ]
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['errors']
            ], 400);
        }
    }
    
    /**
     * Get settlement details for modal
     */
    public function getSettlementDetails($id)
    {
        $expenseRequest = RequestModel::with([
            'requester', 
            'paymentSourceAccount', 
            'category',
            'settlementDestinationAccount'
        ])->findOrFail($id);
        
        // Determine destination account
        $destinationAccount = null;
        if ($expenseRequest->requester_user_id) {
            // Find rider's cash account
            $riderCashAccount = AccountModel::where('user_id', $expenseRequest->requester_user_id)
                ->where('account_category', 'employee_cash')
                ->first();
            
            if ($riderCashAccount) {
                $recentDeposit = LedgerModel::where('from_account_id', $riderCashAccount->id)
                    ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                    ->where('transaction_date', '<=', $expenseRequest->created_at)
                    ->orderBy('transaction_date', 'desc')
                    ->first();
                
                if ($recentDeposit) {
                    $destinationAccount = AccountModel::find($recentDeposit->to_account_id);
                }
            }
        }
        
        if (!$destinationAccount) {
            $destinationAccount = ConfigModel::getNFCashAccount();
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'request_number' => $expenseRequest->request_number,
                'employee' => $expenseRequest->requester ? ($expenseRequest->requester->fullname ?? $expenseRequest->requester->name) : 'Unknown',
                'category' => $expenseRequest->expense_category ?? ($expenseRequest->category ? $expenseRequest->category->category_name : 'Unknown'),
                'amount' => $expenseRequest->amount,
                'paid_from' => $expenseRequest->paymentSourceAccount ? $expenseRequest->paymentSourceAccount->account_name : 'Unknown',
                'destination' => $destinationAccount ? $destinationAccount->account_name : 'NF Cash',
                'date' => $expenseRequest->created_at->format('Y-m-d')
            ]
        ]);
    }
}

