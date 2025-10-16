<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeCashController extends Controller
{
    /**
     * Display employee cash list (now includes company accounts)
     */
    public function index(Request $request)
    {
        // Determine account type filter
        $accountTypeFilter = $request->input('account_type', 'all');
        
        // Build base query
        $query = AccountModel::where('is_active', 1);
        
        // Apply account type filter
        if ($accountTypeFilter === 'employees') {
            $query->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH);
        } elseif ($accountTypeFilter === 'company') {
            $query->whereIn('account_category', [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK]);
        } elseif (in_array($accountTypeFilter, ['NF_CASH', 'ONLINE', 'EXP_FUND'])) {
            // Specific account filter
            $query->where('account_code', $accountTypeFilter);
        } else {
            // 'all' - show both employees and company accounts
            $query->where(function($q) {
                $q->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
                  ->orWhereIn('account_category', [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK]);
            });
        }
        
        $query->with('user');

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('account_name', 'LIKE', "%{$search}%");
        }

        // Filter by balance
        if ($request->has('balance_filter')) {
            if ($request->balance_filter === 'positive') {
                $query->where('current_balance', '>', 0);
            } elseif ($request->balance_filter === 'zero') {
                $query->where('current_balance', '=', 0);
            } elseif ($request->balance_filter === 'negative') {
                $query->where('current_balance', '<', 0);
            }
        }

        // Order: Company accounts first, then employee accounts (both alphabetically)
        $accounts = $query
            ->orderByRaw("CASE 
                WHEN account_category IN ('cash', 'bank') THEN 1 
                WHEN account_category = 'employee_cash' THEN 2 
                ELSE 3 
            END")
            ->orderBy('account_name', 'asc')
            ->paginate(20);

        // Calculate pending approvals for each account (ONLY unapproved requests)
        foreach ($accounts as $account) {
            $pendingApprovals = 0;
            
            // For employee accounts: sum ONLY pending expense requests (not yet approved)
            if ($account->user_id) {
                $pendingApprovals = \App\Models\Request\RequestModel::where('requester_user_id', $account->user_id)
                    ->where('status', 'pending')
                    ->whereHas('category', function($q) {
                        $q->where('category_code', 'expense');
                    })
                    ->sum('amount');
            } 
            // For company accounts: sum all pending requests that will be paid from this account
            else {
                // Sum all pending expense requests where payment source is THIS company account
                // OR where payment source is NULL and this is the Expense Fund (default)
                $query = \App\Models\Request\RequestModel::where('status', 'pending')
                    ->whereHas('category', function($q) {
                        $q->where('category_code', 'expense');
                    });
                
                // If this is the Expense Fund, include requests with NULL payment source (default to Expense Fund)
                if ($account->account_code === 'EXP_FUND') {
                    $query->where(function($q) use ($account) {
                        $q->where('payment_source_account_id', $account->id)
                          ->orWhereNull('payment_source_account_id');
                    });
                } else {
                    $query->where('payment_source_account_id', $account->id);
                }
                
                $pendingApprovals = $query->sum('amount');
            }
            
            $account->pending_approvals = $pendingApprovals ?? 0;
        }

        // Get filter parameters
        $filterType = $request->get('filter_type', 'month'); // day, month, custom
        $filterDate = $request->get('filter_date', now()->toDateString());
        $filterMonth = $request->get('filter_month', now()->format('Y-m'));
        $filterStartDate = $request->get('filter_start_date');
        $filterEndDate = $request->get('filter_end_date');
        
        // Determine date range for filtering
        $startDate = null;
        $endDate = null;
        
        if ($filterType === 'day') {
            $startDate = \Carbon\Carbon::parse($filterDate)->startOfDay();
            $endDate = \Carbon\Carbon::parse($filterDate)->endOfDay();
        } elseif ($filterType === 'month') {
            $startDate = \Carbon\Carbon::parse($filterMonth . '-01')->startOfMonth();
            $endDate = \Carbon\Carbon::parse($filterMonth . '-01')->endOfMonth();
        } elseif ($filterType === 'custom' && $filterStartDate && $filterEndDate) {
            $startDate = \Carbon\Carbon::parse($filterStartDate)->startOfDay();
            $endDate = \Carbon\Carbon::parse($filterEndDate)->endOfDay();
        }
        
        // === KPI 1: TOTAL INVOICES DELIVERED (with online/cash split FROM ORDERS) ===
        // This shows actual invoices created (for reconciliation against ledger)
        $invoicesQuery = \DB::table('t_crm_order_status_history')
            ->where('status_code', 'delivered')
            ->where('is_current', 1);
        
        if ($startDate && $endDate) {
            $invoicesQuery->whereBetween('changed_at', [$startDate, $endDate]);
        }
        
        $deliveredOrderIds = $invoicesQuery->pluck('order_id');
        
        $totalInvoices = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->sum('total_price') ?? 0;
        
        // Split by payment method in orders (for reconciliation)
        $invoicesCash = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['cash', 'cash_on_delivery', 'Cash', 'COD'])
            ->sum('total_price') ?? 0;
        
        $invoicesOnline = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
            ->sum('total_price') ?? 0;
        
        // === KPI 2: DEPOSITS TO NF CASH ===
        $nfCashAccount = AccountModel::where('account_code', 'NF_CASH')->first();
        
        if ($nfCashAccount) {
            $depositsQuery = LedgerModel::where('to_account_id', $nfCashAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_APPROVED); // Only count approved deposits
            
            if ($startDate && $endDate) {
                $depositsQuery->whereBetween('transaction_date', [$startDate, $endDate]);
            }
            
            $totalDeposits = $depositsQuery->sum('amount') ?? 0;
        } else {
            $totalDeposits = 0;
        }
        
        // === KPI 3: ALL APPROVED EXPENSES (with settlement status split) ===
        // Total: All approved expense requests from the request table
        $allExpensesRequestQuery = \App\Models\Request\RequestModel::where('status', 'approved')
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            });
        
        if ($startDate && $endDate) {
            $allExpensesRequestQuery->whereBetween('created_at', [$startDate, $endDate]);
        }
        
        $totalApprovedExpenses = (clone $allExpensesRequestQuery)->sum('amount') ?? 0;
        
        // Sub-value 1: Expenses waiting to be settled (settlement_status = 'pending')
        $expensesWaitingSettlement = (clone $allExpensesRequestQuery)
            ->where('settlement_status', 'pending')
            ->sum('amount') ?? 0;
        
        // Sub-value 2: Expenses already settled or not requiring settlement
        // (settlement_status = 'settled' OR 'not_required')
        $expensesInFund = (clone $allExpensesRequestQuery)
            ->whereIn('settlement_status', ['settled', 'not_required'])
            ->sum('amount') ?? 0;
        
        // === KPI 4: ONLINE PAYMENTS (with approved/pending split) ===
        $onlineAccount = AccountModel::where('account_code', 'ONLINE')->first();
        
        if ($onlineAccount) {
            $onlineQuery = LedgerModel::where('to_account_id', $onlineAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE);
            
            if ($startDate && $endDate) {
                $onlineQuery->whereBetween('transaction_date', [$startDate, $endDate]);
            }
            
            $totalOnlineApproved = (clone $onlineQuery)->where('approval_status', LedgerModel::STATUS_APPROVED)->sum('amount') ?? 0;
            $totalOnlinePending = (clone $onlineQuery)->where('approval_status', LedgerModel::STATUS_PENDING)->sum('amount') ?? 0;
            $totalOnline = $totalOnlineApproved + $totalOnlinePending;
        } else {
            $totalOnlineApproved = 0;
            $totalOnlinePending = 0;
            $totalOnline = 0;
        }
        
        // === KPI 5: RIDERS BALANCE (Real-time, no filtering) ===
        $ridersBalance = AccountModel::employeeCash()->sum('current_balance');
        
        // === KPI 6: OPEN INVOICES (Real-time, no filtering) ===
        $openInvoicesCount = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('settlement_status', 'open')
            ->whereHas('toAccount', function($q) {
                $q->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH);
            })
            ->count();
        
        $openInvoicesTotal = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('settlement_status', 'open')
            ->whereHas('toAccount', function($q) {
                $q->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH);
            })
            ->sum(\DB::raw('amount - COALESCE(settled_amount, 0)'));
        
        $summaryKPIs = [
            'total_invoices' => $totalInvoices,
            'invoices_cash' => $invoicesCash,
            'invoices_online' => $invoicesOnline,
            'total_deposits' => $totalDeposits,
            'total_approved_expenses' => $totalApprovedExpenses,
            'expenses_waiting_settlement' => $expensesWaitingSettlement,
            'expenses_in_fund' => $expensesInFund,
            'total_online' => $totalOnline,
            'online_approved' => $totalOnlineApproved,
            'online_pending' => $totalOnlinePending,
            'riders_balance' => $ridersBalance,
            'open_invoices_count' => $openInvoicesCount,
            'open_invoices_total' => $openInvoicesTotal,
            'filter_type' => $filterType,
            'filter_date' => $filterDate,
            'filter_month' => $filterMonth,
            'filter_start_date' => $filterStartDate,
            'filter_end_date' => $filterEndDate
        ];

        return view('fin.employee.index', compact('accounts', 'accountTypeFilter', 'summaryKPIs'));
    }

    /**
     * Show account details (employee or company account)
     */
    public function show(Request $request, $id)
    {
        $account = AccountModel::with('user')->findOrFail($id);

        // Allow employee cash, cash, and bank accounts
        $allowedCategories = [
            AccountModel::CATEGORY_EMPLOYEE_CASH,
            AccountModel::CATEGORY_CASH,
            AccountModel::CATEGORY_BANK
        ];
        
        if (!in_array($account->account_category, $allowedCategories)) {
            abort(404, 'Invalid account type');
        }
        
        // Determine if this is an employee account
        $isEmployeeAccount = $account->account_category === AccountModel::CATEGORY_EMPLOYEE_CASH;

        // Get date filter parameters
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // Build ledger query with date filters
        $ledgerQuery = LedgerModel::where(function($q) use ($id) {
            $q->where('from_account_id', $id)
              ->orWhere('to_account_id', $id);
        });

        // Apply date filters if provided
        if ($dateFrom && $dateTo) {
            $ledgerQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        }

        // Get ledger transactions
        $ledger = $ledgerQuery
            ->with(['fromAccount', 'toAccount'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Calculate running balance (from oldest to newest for calculation)
        $allTransactionsQuery = LedgerModel::where(function($q) use ($id) {
            $q->where('from_account_id', $id)
              ->orWhere('to_account_id', $id);
        });

        // Apply same date filters to running balance calculation
        if ($dateFrom && $dateTo) {
            $allTransactionsQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        }

        $allTransactions = $allTransactionsQuery
            ->orderBy('transaction_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $runningBalance = $account->opening_balance;
        $balanceMap = [];
        
        foreach ($allTransactions as $transaction) {
            if ($transaction->to_account_id === $account->id) {
                // Money coming in
                $runningBalance += $transaction->amount;
            } else {
                // Money going out
                $runningBalance -= $transaction->amount;
            }
            $balanceMap[$transaction->id] = $runningBalance;
        }

        // Attach running balances to paginated results (in reverse since we display newest first)
        $ledger->getCollection()->transform(function($transaction) use ($balanceMap) {
            $transaction->running_balance = $balanceMap[$transaction->id] ?? 0;
            return $transaction;
        });

        // Summary with date filters
        // For EMPLOYEE accounts: Invoices come TO account, deposits go FROM account
        // For COMPANY accounts (NF Cash): Deposits come TO account, expenses go FROM account
        
        $invoicesQuery = LedgerModel::where('to_account_id', $account->id)
            ->where('transaction_type', LedgerModel::TYPE_INVOICE);
        $expensesQuery = LedgerModel::where('from_account_id', $account->id)
            ->where('transaction_type', LedgerModel::TYPE_EXPENSE);
        
        // FIXED: Deposits direction depends on account type
        if ($isEmployeeAccount) {
            // Employee depositing TO company: money going FROM employee account
            $depositsQuery = LedgerModel::where('from_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT);
        } else {
            // Company receiving deposits: money coming TO company account
            $depositsQuery = LedgerModel::where('to_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT);
        }

        // Apply date filters to summary calculations
        if ($dateFrom && $dateTo) {
            $invoicesQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            $expensesQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            $depositsQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        }

        // === CASH IN BREAKDOWN (for company accounts) ===
        $cashInBreakdown = [];
        if (!$isEmployeeAccount) {
            // Deposits (already calculated above)
            $cashInBreakdown['deposits'] = $depositsQuery->sum('amount') ?? 0;
            
            // Settlements (expense settlements coming back TO this account)
            $settlementsQuery = LedgerModel::where('to_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_SETTLEMENT);
            if ($dateFrom && $dateTo) {
                $settlementsQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashInBreakdown['settlements'] = $settlementsQuery->sum('amount') ?? 0;
            
            // Transfers IN (money transferred TO this account)
            $transfersInQuery = LedgerModel::where('to_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_TRANSFER);
            if ($dateFrom && $dateTo) {
                $transfersInQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashInBreakdown['transfers_in'] = $transfersInQuery->sum('amount') ?? 0;
            
            // Others IN (adjustments, reimbursements, etc.)
            $othersInQuery = LedgerModel::where('to_account_id', $account->id)
                ->whereIn('transaction_type', [
                    LedgerModel::TYPE_ADJUSTMENT,
                    LedgerModel::TYPE_REIMBURSEMENT_PAYMENT,
                    LedgerModel::TYPE_SALARY_ADVANCE
                ]);
            if ($dateFrom && $dateTo) {
                $othersInQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashInBreakdown['others_in'] = $othersInQuery->sum('amount') ?? 0;
            
            $cashInBreakdown['total'] = $cashInBreakdown['deposits'] + 
                                        $cashInBreakdown['settlements'] + 
                                        $cashInBreakdown['transfers_in'] + 
                                        $cashInBreakdown['others_in'];
        }

        // === CASH OUT BREAKDOWN (for company accounts) ===
        $cashOutBreakdown = [];
        if (!$isEmployeeAccount) {
            // Unsettled Expenses (approved but not settled - from request table)
            $unsettledExpensesQuery = \App\Models\Request\RequestModel::where('status', 'approved')
                ->where('settlement_status', 'pending')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                });
            
            // Only count expenses paid FROM this account (NF Cash)
            if (in_array($account->account_code, ['NF_CASH', 'EXP_FUND'])) {
                // For default payment accounts, include both explicit and NULL payment sources
                $unsettledExpensesQuery->where(function($q) use ($account) {
                    $q->where('payment_source_account_id', $account->id)
                      ->orWhereNull('payment_source_account_id');
                });
            } else {
                // For other accounts, only include explicit assignments
                $unsettledExpensesQuery->where('payment_source_account_id', $account->id);
            }
            
            if ($dateFrom && $dateTo) {
                $unsettledExpensesQuery->whereBetween('created_at', [$dateFrom, $dateTo]);
            }
            $cashOutBreakdown['unsettled_expenses'] = $unsettledExpensesQuery->sum('amount') ?? 0;
            
            // Vendor Payments (money paid OUT to vendors)
            $vendorPaymentsQuery = LedgerModel::where('from_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT);
            if ($dateFrom && $dateTo) {
                $vendorPaymentsQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashOutBreakdown['vendor_payments'] = $vendorPaymentsQuery->sum('amount') ?? 0;
            
            // Transfers OUT (money transferred FROM this account)
            $transfersOutQuery = LedgerModel::where('from_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_TRANSFER);
            if ($dateFrom && $dateTo) {
                $transfersOutQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashOutBreakdown['transfers_out'] = $transfersOutQuery->sum('amount') ?? 0;
            
            // Expenses (all expenses FROM ledger - includes both settled and unsettled that hit the ledger)
            $expensesOutQuery = LedgerModel::where('from_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_EXPENSE);
            if ($dateFrom && $dateTo) {
                $expensesOutQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashOutBreakdown['expenses_ledger'] = $expensesOutQuery->sum('amount') ?? 0;
            
            // Others OUT (adjustments, etc.)
            $othersOutQuery = LedgerModel::where('from_account_id', $account->id)
                ->whereIn('transaction_type', [
                    LedgerModel::TYPE_ADJUSTMENT,
                    LedgerModel::TYPE_VENDOR_PURCHASE
                ]);
            if ($dateFrom && $dateTo) {
                $othersOutQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashOutBreakdown['others_out'] = $othersOutQuery->sum('amount') ?? 0;
            
            $cashOutBreakdown['total'] = $cashOutBreakdown['unsettled_expenses'] + 
                                         $cashOutBreakdown['vendor_payments'] + 
                                         $cashOutBreakdown['transfers_out'] + 
                                         $cashOutBreakdown['expenses_ledger'] + 
                                         $cashOutBreakdown['others_out'];
        }

        // Calculate withdrawals (money leaving this account)
        $withdrawalsQuery = LedgerModel::where('from_account_id', $account->id)
            ->whereNotIn('transaction_type', [LedgerModel::TYPE_EMPLOYEE_DEPOSIT]);
        
        // Calculate pending approvals for this account
        // FIXED: For company accounts, only show pending where payment source is THIS account
        if ($isEmployeeAccount) {
            // Employee account: all pending transactions involving this account
            $pendingQuery = LedgerModel::where(function($q) use ($account) {
                $q->where('from_account_id', $account->id)
                  ->orWhere('to_account_id', $account->id);
            })
            ->where('approval_status', 'pending');
        } else {
            // Company account: pending expense requests where payment source is THIS account OR rider accounts
            $pendingAmount = \App\Models\Request\RequestModel::where('status', 'pending')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                });
            
            // FIXED: Include both direct assignments and rider cash payments
            if ($account->account_code === 'EXP_FUND') {
                // Expense Fund: explicit assignments OR NULL (default)
                $pendingAmount->where(function($q) use ($account) {
                    $q->where('payment_source_account_id', $account->id)
                      ->orWhereNull('payment_source_account_id');
                });
            } elseif ($account->account_code === 'NF_CASH') {
                // NF Cash: explicit NF Cash assignments OR paid from any rider balance (will affect cash)
                $pendingAmount->where(function($q) use ($account) {
                    $q->where('payment_source_account_id', $account->id)
                      ->orWhereHas('paymentSourceAccount', function($subQ) {
                          $subQ->where('account_category', 'employee_cash');
                      });
                });
            } else {
                // Other accounts: only explicit assignments
                $pendingAmount->where('payment_source_account_id', $account->id);
            }
            
            if ($dateFrom && $dateTo) {
                $pendingAmount->whereBetween('created_at', [$dateFrom, $dateTo]);
            }
            
            $pendingQuery = null; // Set to null to indicate we calculated differently
        }
        
        // Apply date filters
        if ($dateFrom && $dateTo) {
            $withdrawalsQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            if ($pendingQuery) {
                $pendingQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
        }
        
        // === ADDITIONAL CARDS FOR COMPANY ACCOUNTS ===
        $shortCash = 0;
        $cashInvoices = 0;
        $ridersBalance = 0;
        
        if (!$isEmployeeAccount) {
            // Short Cash: Expenses paid from rider balance but not yet settled
            $shortCashQuery = \App\Models\Request\RequestModel::where('status', 'approved')
                ->where('settlement_status', 'pending')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                })
                ->whereHas('paymentSourceAccount', function($q) {
                    $q->where('account_category', 'employee_cash');
                });
            
            if ($dateFrom && $dateTo) {
                $shortCashQuery->whereBetween('created_at', [$dateFrom, $dateTo]);
            }
            $shortCash = $shortCashQuery->sum('amount') ?? 0;
            
            // Cash Invoices: ALL cash/COD invoices delivered (from employee accounts)
            // These go to rider accounts, not NF Cash directly
            $cashInvoicesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('mode', LedgerModel::MODE_CASH)
                ->whereHas('toAccount', function($q) {
                    $q->where('account_category', 'employee_cash');
                });
            
            if ($dateFrom && $dateTo) {
                $cashInvoicesQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashInvoices = $cashInvoicesQuery->sum('amount') ?? 0;
            
            // Riders Balance: Total current balance held by all riders
            $ridersBalance = AccountModel::where('account_category', 'employee_cash')
                ->where('is_active', 1)
                ->sum('current_balance') ?? 0;
        }
        
        $summary = [
            'opening_balance' => $account->opening_balance,
            'current_balance' => $account->current_balance,
            'total_invoices' => $invoicesQuery->sum('amount'),
            'total_expenses' => $expensesQuery->sum('amount'),
            'total_deposits' => $depositsQuery->sum('amount'),
            'total_withdrawals' => $withdrawalsQuery->sum('amount'),
            'total_pending' => $pendingQuery ? $pendingQuery->sum('amount') : ($pendingAmount->sum('amount') ?? 0),
            'cash_in' => $cashInBreakdown,
            'cash_out' => $cashOutBreakdown,
            'short_cash' => $shortCash,
            'cash_invoices' => $cashInvoices,
            'riders_balance' => $ridersBalance
        ];

        // Check user role
        $userRole = null;
        if (auth()->check()) {
            $userRole = \DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', auth()->id())
                ->value('r.type');
        }

        // Fetch expense requests for this employee with date filters
        $expenseRequestsQuery = \App\Models\Request\RequestModel::with(['category', 'createdBy', 'paymentSourceAccount'])
            ->where('requester_user_id', $account->user_id)
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            });

        // Apply date filters to expense requests
        if ($dateFrom && $dateTo) {
            $expenseRequestsQuery->whereBetween('created_at', [$dateFrom, $dateTo]);
        }

        $expenseRequests = $expenseRequestsQuery->orderBy('created_at', 'desc')->get();

        // Calculate expense request summary with payment source split
        // Key distinction: Expenses FROM rider's balance vs expenses paid by other sources
        // IMPORTANT: Respect settlement status - settled expenses move from rider to company
        
        // Expenses paid FROM THIS rider's own balance (affects his balance)
        // EXCLUDE settled expenses (they've been reconciled)
        $paidFromRiderBalance = $expenseRequests
            ->filter(function($req) use ($account) {
                // Must have ledger transaction
                if (!$req->ledger_transaction_id) {
                    return false;
                }
                
                // Exclude settled expenses (they've been reconciled)
                if ($req->settlement_status === 'settled') {
                    return false;
                }
                
                // Check if paid from THIS employee's cash account
                if ($req->payment_source_account_id) {
                    $paymentAccount = \App\Models\FIN\AccountModel::find($req->payment_source_account_id);
                    return $paymentAccount && $paymentAccount->id === $account->id;
                }
                
                return false;
            })->sum('amount');

        // All other approved expenses (does NOT affect rider's balance)
        // INCLUDES settled expenses (now company-funded after settlement)
        $paidFromOtherSources = $expenseRequests
            ->filter(function($req) use ($account) {
                // Must have ledger transaction
                if (!$req->ledger_transaction_id) {
                    return false;
                }
                
                // If settled, it's now company-funded regardless of original source
                if ($req->settlement_status === 'settled') {
                    return true;
                }
                
                // If no payment source set, assume it's from company (NOT rider)
                if (!$req->payment_source_account_id) {
                    return true;
                }
                
                // Otherwise, exclude only THIS rider's balance (if not settled)
                $paymentAccount = \App\Models\FIN\AccountModel::find($req->payment_source_account_id);
                return $paymentAccount && $paymentAccount->id !== $account->id;
            })->sum('amount');

        $expenseSummary = [
            'pending' => $expenseRequests->where('status', 'pending')->sum('amount'),
            'expense_from_rider_balance' => $paidFromRiderBalance, // Paid from rider's own cash
            'expense_amount' => $paidFromOtherSources, // Paid from other sources (NF Cash, Expense Fund, etc.)
            // Keep these for filter counts
            'paid_from_company' => $expenseRequests->whereNotNull('ledger_transaction_id')
                ->filter(function($req) {
                    if ($req->payment_source_account_id) {
                        $account = \App\Models\FIN\AccountModel::find($req->payment_source_account_id);
                        return $account && in_array($account->account_code, ['EXP_FUND', 'NF_CASH', 'ONLINE', 'CASH_NF_MAIN_TILL']);
                    }
                    return false;
                })->sum('amount'),
            'paid_from_employee' => $expenseRequests->whereNotNull('ledger_transaction_id')
                ->filter(function($req) {
                    if ($req->payment_source_account_id) {
                        $account = \App\Models\FIN\AccountModel::find($req->payment_source_account_id);
                        return $account && $account->account_category === 'employee_cash';
                    }
                    return false;
                })->sum('amount')
        ];

        // Get expense categories for the dropdown
        $expenseCategories = \App\Models\FIN\ConfigModel::where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
            ->pluck('config_value')
            ->toArray();

        return view('fin.employee.show', compact('account', 'ledger', 'summary', 'userRole', 'expenseRequests', 'expenseSummary', 'expenseCategories', 'dateFrom', 'dateTo', 'isEmployeeAccount'));
    }

    /**
     * Record employee deposit
     */
    public function recordDeposit(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'destination_account_id' => 'nullable|exists:t_fin_accounts,id',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
            'short_over' => 'nullable|numeric'
        ]);

        try {
            DB::beginTransaction();

            $employeeAccount = AccountModel::findOrFail($id);
            
            // Get destination account (user selection or default to NF Cash)
            if ($request->destination_account_id) {
                $destinationAccount = AccountModel::findOrFail($request->destination_account_id);
            } else {
                $destinationAccount = ConfigModel::getNFCashAccount();
            }

            if (!$destinationAccount) {
                throw new \Exception("Destination account not found");
            }

            // Check if amount exceeds employee balance
            if ($request->amount > $employeeAccount->current_balance) {
                throw new \Exception("Deposit amount cannot exceed employee cash balance");
            }

            // ALL DEPOSITS NOW REQUIRE APPROVAL
            $approvalStatus = LedgerModel::STATUS_PENDING;

            // Main deposit transaction
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
                'description' => $request->description ?? "Deposit from {$employeeAccount->account_name} to {$destinationAccount->account_name}",
                'from_account_id' => $employeeAccount->id,
                'to_account_id' => $destinationAccount->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => $approvalStatus,
                'approval_date' => null,
                'approved_by' => null,
                'created_by' => auth()->id(),
                'comments' => "Awaiting approval for deposit to: {$destinationAccount->account_name}"
            ]);

            // DO NOT update balances - wait for approval

            // Handle short/over if provided
            if ($request->short_over && $request->short_over != 0) {
                $shortOverAccount = null;
                
                if ($request->short_over < 0) {
                    // Cash short - expense
                    $shortOverAccount = AccountModel::getByCode('EXP_CASH_SHORT');
                } else {
                    // Cash over - income
                    $shortOverAccount = AccountModel::getByCode('REV_CASH_OVER');
                }

                if ($shortOverAccount) {
                    $absAmount = abs($request->short_over);
                    
                    LedgerModel::create([
                        'transaction_date' => $request->transaction_date,
                        'transaction_type' => LedgerModel::TYPE_EXPENSE,
                        'description' => "Cash " . ($request->short_over < 0 ? 'short' : 'over') . " adjustment",
                        'from_account_id' => $request->short_over < 0 ? $shortOverAccount->id : $nfCash->id,
                        'to_account_id' => $request->short_over < 0 ? $nfCash->id : $shortOverAccount->id,
                        'amount' => $absAmount,
                        'mode' => LedgerModel::MODE_CASH,
                        'approval_status' => LedgerModel::STATUS_APPROVED,
                        'created_by' => auth()->id()
                    ]);

                    // Update balances
                    if ($request->short_over < 0) {
                        // Short: Expense increases, Cash increases
                        $shortOverAccount->current_balance += $absAmount;
                        $nfCash->current_balance += $absAmount;
                    } else {
                        // Over: Cash decreases, Income increases
                        $nfCash->current_balance -= $absAmount;
                        $shortOverAccount->current_balance -= $absAmount;
                    }
                    
                    $shortOverAccount->save();
                    $nfCash->save();
                }
            }

            DB::commit();

            $message = 'Deposit recorded and pending approval! Balances will update after approval.';

            return redirect()->route('fin.employee.show', $employeeAccount->id)
                           ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording deposit: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error recording deposit: ' . $e->getMessage());
        }
    }

    /**
     * Get outstanding invoices for settlement
     */
    public function getOutstandingInvoices($id)
    {
        try {
            $employeeAccount = AccountModel::findOrFail($id);
            
            // Get IDs of invoices that already have pending settlement deposits
            $pendingSettlementInvoiceIds = [];
            $pendingDeposits = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('from_account_id', $employeeAccount->id)
                ->where('approval_status', LedgerModel::STATUS_PENDING)
                ->where('description', 'LIKE', '%Settlement%')
                ->get();
            
            foreach ($pendingDeposits as $deposit) {
                // Try ledger metadata first (NEW), fallback to session (OLD)
                $settlementData = $deposit->settlement_metadata;
                if (!$settlementData) {
                    $sessionKey = "settlement_pending_{$deposit->id}";
                    $settlementData = \Session::get($sessionKey);
                }
                
                if ($settlementData && isset($settlementData['invoice_ids'])) {
                    $pendingSettlementInvoiceIds = array_merge($pendingSettlementInvoiceIds, $settlementData['invoice_ids']);
                }
            }
            
            // Get all open invoices for this rider (exclude those with pending settlements)
            $openInvoices = LedgerModel::where('to_account_id', $employeeAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('settlement_status', 'open')
                ->whereNotIn('id', $pendingSettlementInvoiceIds)
                ->orderBy('transaction_date', 'asc')
                ->get();
            
            // Calculate outstanding balance (amount - settled_amount for partial settlements)
            $invoices = $openInvoices->map(function($invoice) {
                return [
                    'id' => $invoice->id,
                    'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                    'transaction_date' => $invoice->transaction_date->format('Y-m-d'),
                    'description' => $invoice->description,
                    'amount' => $invoice->amount,
                    'settled_amount' => $invoice->settled_amount ?? 0,
                    'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0)
                ];
            });
            
            return response()->json([
                'success' => true,
                'invoices' => $invoices,
                'total_outstanding' => $invoices->sum('outstanding_amount')
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record settlement deposit (with invoice selection)
     */
    public function recordSettlementDeposit(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'destination_account_id' => 'nullable|exists:t_fin_accounts,id',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'exists:t_fin_ledger,id'
        ]);

        try {
            DB::beginTransaction();

            $employeeAccount = AccountModel::findOrFail($id);
            
            // Get destination account
            if ($request->destination_account_id) {
                $destinationAccount = AccountModel::findOrFail($request->destination_account_id);
            } else {
                $destinationAccount = ConfigModel::getNFCashAccount();
            }

            if (!$destinationAccount) {
                throw new \Exception("Destination account not found");
            }

            // Verify selected invoices belong to this rider and are open
            $selectedInvoices = LedgerModel::whereIn('id', $request->invoice_ids)
                ->where('to_account_id', $employeeAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('settlement_status', 'open')
                ->orderBy('transaction_date', 'asc')
                ->get();

            if ($selectedInvoices->count() !== count($request->invoice_ids)) {
                throw new \Exception("Some selected invoices are invalid or already settled");
            }

            // Calculate expected amount
            $totalOutstanding = $selectedInvoices->sum(function($invoice) {
                return $invoice->amount - ($invoice->settled_amount ?? 0);
            });

            // Build description with invoice numbers
            $invoiceNumbers = $selectedInvoices->map(function($invoice) {
                return $invoice->order ? $invoice->order->order_number : "Invoice #" . $invoice->id;
            })->take(3)->join(', ');
            
            if ($selectedInvoices->count() > 3) {
                $invoiceNumbers .= " + " . ($selectedInvoices->count() - 3) . " more";
            }

            $description = $request->description 
                ? "Settlement: {$invoiceNumbers} - {$request->description}"
                : "Settlement for invoices: {$invoiceNumbers}";

            // Create deposit transaction (pending approval)
            // Store settlement metadata in the ledger itself (not session) for cross-user access
            $depositLedger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
                'description' => $description,
                'from_account_id' => $employeeAccount->id,
                'to_account_id' => $destinationAccount->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_PENDING,
                'approval_date' => null,
                'approved_by' => null,
                'created_by' => auth()->id(),
                'comments' => "Settlement deposit for {$selectedInvoices->count()} invoice(s). Total outstanding: Rs. " . number_format($totalOutstanding, 2),
                'settlement_metadata' => [
                    'invoice_ids' => $request->invoice_ids,
                    'deposit_amount' => $request->amount,
                    'total_outstanding' => $totalOutstanding
                ]
            ]);

            DB::commit();

            $message = 'Settlement deposit recorded and pending approval! ';
            if ($request->amount < $totalOutstanding) {
                $shortfall = $totalOutstanding - $request->amount;
                $message .= "Note: Short by Rs. " . number_format($shortfall, 2) . " - remaining balance will stay on last invoice.";
            }

            return redirect()->route('fin.employee.show', $employeeAccount->id)
                           ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error recording settlement deposit: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error recording settlement: ' . $e->getMessage());
        }
    }

    /**
     * Manager view: All outstanding invoices across all riders
     */
    public function allOutstandingInvoices(Request $request)
    {
        try {
            // Get filter parameters
            $statusFilter = $request->get('status', 'all'); // all, open, partial, pending_settlement, settled
            $riderFilter = $request->get('rider', 'all');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');
            
            // Base query for ALL invoices (not just open)
            $invoicesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->with(['toAccount', 'order'])
                ->whereHas('toAccount', function($q) {
                    $q->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH);
                });
            
            // Apply date filters
            if ($dateFrom) {
                $invoicesQuery->where('transaction_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $invoicesQuery->where('transaction_date', '<=', $dateTo);
            }
            
            // Apply rider filter
            if ($riderFilter !== 'all') {
                $invoicesQuery->where('to_account_id', $riderFilter);
            }
            
            // Get all invoices
            $allInvoices = $invoicesQuery->orderBy('transaction_date', 'desc')->get();
            
            // Separate into categories
            $openInvoices = $allInvoices->filter(function($invoice) {
                return $invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) == 0;
            });
            
            $partialInvoices = $allInvoices->filter(function($invoice) {
                return $invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) > 0;
            });
            
            $settledInvoices = $allInvoices->filter(function($invoice) {
                return $invoice->settlement_status === 'settled';
            });
            
            // Get pending settlement deposits (settlements awaiting approval)
            $pendingSettlements = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_PENDING)
                ->where('description', 'LIKE', '%Settlement%')
                ->with(['fromAccount'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            // Enhance pending settlements with invoice details from metadata or session
            $pendingSettlements = $pendingSettlements->map(function($settlement) {
                // Try ledger metadata first (NEW), fallback to session (OLD)
                $settlementData = $settlement->settlement_metadata;
                if (!$settlementData) {
                    $sessionKey = "settlement_pending_{$settlement->id}";
                    $settlementData = \Session::get($sessionKey);
                }
                
                if ($settlementData && isset($settlementData['invoice_ids'])) {
                    $settlement->invoice_ids = $settlementData['invoice_ids'];
                    $settlement->total_outstanding = $settlementData['total_outstanding'];
                    
                    // Get the actual invoice records for display
                    $settlement->invoices = LedgerModel::whereIn('id', $settlementData['invoice_ids'])
                        ->with('order')
                        ->orderBy('transaction_date', 'asc')
                        ->get()
                        ->map(function($invoice) {
                            return [
                                'id' => $invoice->id,
                                'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                                'transaction_date' => $invoice->transaction_date,
                                'description' => $invoice->description,
                                'amount' => $invoice->amount,
                                'settled_amount' => $invoice->settled_amount ?? 0,
                                'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0)
                            ];
                        });
                } else {
                    $settlement->invoice_ids = [];
                    $settlement->invoices = collect();
                    $settlement->total_outstanding = 0;
                }
                
                return $settlement;
            });
            
            // Apply status filter for display
            $displayInvoices = collect();
            switch ($statusFilter) {
                case 'open':
                    $displayInvoices = $openInvoices;
                    break;
                case 'partial':
                    $displayInvoices = $partialInvoices;
                    break;
                case 'settled':
                    $displayInvoices = $settledInvoices;
                    break;
                default:
                    $displayInvoices = $openInvoices->concat($partialInvoices);
            }
            
            // Create a map of invoice IDs to their pending settlement IDs
            $invoiceToPendingSettlement = [];
            foreach ($pendingSettlements as $settlement) {
                foreach ($settlement->invoice_ids as $invoiceId) {
                    $invoiceToPendingSettlement[$invoiceId] = $settlement->id;
                }
            }
            
            // Group pending settlements by rider (from_account_id)
            $settlementsByRider = $pendingSettlements->groupBy('from_account_id');
            
            // Group by rider for display
            $invoicesByRider = $displayInvoices->groupBy('to_account_id')->map(function($riderInvoices) use ($invoiceToPendingSettlement, $settlementsByRider, $statusFilter) {
                $account = $riderInvoices->first()->toAccount;
                $totalOutstanding = $riderInvoices->sum(function($invoice) {
                    return $invoice->amount - ($invoice->settled_amount ?? 0);
                });
                
                // Get pending settlements for this rider
                $riderSettlements = $settlementsByRider->get($account->id, collect());
                
                // Group settled invoices by settlement date
                $invoicesByDate = null;
                if ($statusFilter === 'settled') {
                    $invoicesByDate = $riderInvoices->groupBy(function($invoice) {
                        return $invoice->settled_at ? $invoice->settled_at->format('Y-m-d') : 'Unknown';
                    })->map(function($dayInvoices) {
                        $dayTotal = $dayInvoices->sum('settled_amount');
                        return [
                            'invoices' => $dayInvoices,
                            'day_total' => $dayTotal,
                            'count' => $dayInvoices->count()
                        ];
                    });
                }
                
                return [
                    'account' => $account,
                    'pending_settlements' => $riderSettlements,
                    'invoices_by_date' => $invoicesByDate, // NEW: Grouped by day for settled invoices
                    'invoices' => $riderInvoices->map(function($invoice) use ($invoiceToPendingSettlement) {
                        $isPendingApproval = isset($invoiceToPendingSettlement[$invoice->id]);
                        $pendingSettlementId = $isPendingApproval ? $invoiceToPendingSettlement[$invoice->id] : null;
                        
                        return [
                            'id' => $invoice->id,
                            'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                            'transaction_date' => $invoice->transaction_date,
                            'is_pending_approval' => $isPendingApproval,
                            'pending_settlement_id' => $pendingSettlementId,
                            'description' => $invoice->description,
                            'amount' => $invoice->amount,
                            'settled_amount' => $invoice->settled_amount ?? 0,
                            'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0),
                            'settlement_status' => $invoice->settlement_status,
                            'settled_at' => $invoice->settled_at
                        ];
                    }),
                    'total_outstanding' => $totalOutstanding,
                    'invoice_count' => $riderInvoices->count()
                ];
            });
            
            // === NEW: Calculate Pending Approvals for Expense Requests ===
            // Use same logic as NF Cash: pending expenses that will affect NF Cash
            // (either paid from NF Cash OR paid from rider balance needing settlement)
            $nfCashAccount = AccountModel::where('account_code', 'NF_CASH')->first();
            
            $pendingApprovalsQuery = \App\Models\Request\RequestModel::where('status', 'pending')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                });
            
            // NF Cash logic: explicit NF Cash assignments OR paid from any rider balance
            if ($nfCashAccount) {
                $pendingApprovalsQuery->where(function($q) use ($nfCashAccount) {
                    $q->where('payment_source_account_id', $nfCashAccount->id)
                      ->orWhereHas('paymentSourceAccount', function($subQ) {
                          $subQ->where('account_category', 'employee_cash');
                      });
                });
            }
            
            // Apply date filters if provided
            if ($dateFrom) {
                $pendingApprovalsQuery->where('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $pendingApprovalsQuery->where('created_at', '<=', $dateTo);
            }
            
            $pendingApprovalsAmount = $pendingApprovalsQuery->sum('amount') ?? 0;
            $pendingApprovalsCount = $pendingApprovalsQuery->count();
            
            // === NEW: Calculate Short Cash ===
            // Approved expenses paid from rider balance but not yet settled
            $shortCashQuery = \App\Models\Request\RequestModel::where('status', 'approved')
                ->where('settlement_status', 'pending')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                })
                ->whereHas('paymentSourceAccount', function($q) {
                    $q->where('account_category', 'employee_cash');
                });
            
            // Apply date filters if provided
            if ($dateFrom) {
                $shortCashQuery->where('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $shortCashQuery->where('created_at', '<=', $dateTo);
            }
            
            $shortCashAmount = $shortCashQuery->sum('amount') ?? 0;
            $shortCashCount = $shortCashQuery->count();
            
            // Calculate summary stats
            $stats = [
                'open_count' => $openInvoices->count(),
                'open_total' => $openInvoices->sum(function($inv) {
                    return $inv->amount - ($inv->settled_amount ?? 0);
                }),
                'partial_count' => $partialInvoices->count(),
                'partial_total' => $partialInvoices->sum(function($inv) {
                    return $inv->amount - ($inv->settled_amount ?? 0);
                }),
                'pending_settlement_count' => $pendingSettlements->count(),
                'pending_settlement_total' => $pendingSettlements->sum('amount'),
                'settled_count' => $settledInvoices->count(),
                'settled_total' => $settledInvoices->sum('amount'),
                'total_outstanding' => $openInvoices->sum(function($inv) {
                    return $inv->amount - ($inv->settled_amount ?? 0);
                }) + $partialInvoices->sum(function($inv) {
                    return $inv->amount - ($inv->settled_amount ?? 0);
                }),
                // NEW: Add pending approvals and short cash
                'pending_approvals_count' => $pendingApprovalsCount,
                'pending_approvals_amount' => $pendingApprovalsAmount,
                'short_cash_count' => $shortCashCount,
                'short_cash_amount' => $shortCashAmount
            ];
            
            // Get all riders for filter dropdown
            $allRiders = AccountModel::where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
                ->where('is_active', 1)
                ->orderBy('account_name')
                ->get();
            
            return view('fin.employee.outstanding-invoices', [
                'invoicesByRider' => $invoicesByRider,
                'stats' => $stats,
                'pendingSettlements' => $pendingSettlements,
                'allRiders' => $allRiders,
                'filters' => [
                    'status' => $statusFilter,
                    'rider' => $riderFilter,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Error fetching all outstanding invoices: " . $e->getMessage());
            return back()->with('error', 'Error loading outstanding invoices: ' . $e->getMessage());
        }
    }

    /**
     * Record manual adjustment
     */
    public function recordAdjustment(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|not_in:0',
            'type' => 'required|in:increase,decrease',
            'reason' => 'required|string|max:500',
            'transaction_date' => 'required|date'
        ]);

        try {
            DB::beginTransaction();

            $employeeAccount = AccountModel::findOrFail($id);
            $equityAccount = AccountModel::getByCode('EQUITY_OPENING');

            if (!$equityAccount) {
                throw new \Exception("Equity account not found");
            }

            $amount = abs($request->amount);

            // Determine from/to based on type
            if ($request->type === 'increase') {
                $fromAccountId = $equityAccount->id;
                $toAccountId = $employeeAccount->id;
            } else {
                $fromAccountId = $employeeAccount->id;
                $toAccountId = $equityAccount->id;
            }

            // Create ledger entry
            LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => 'adjustment',
                'description' => "Manual adjustment: " . $request->reason,
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'amount' => $amount,
                'mode' => null,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'comments' => $request->reason,
                'created_by' => auth()->id()
            ]);

            // Update balances
            if ($request->type === 'increase') {
                $employeeAccount->current_balance += $amount;
                $equityAccount->current_balance -= $amount;
            } else {
                $employeeAccount->current_balance -= $amount;
                $equityAccount->current_balance += $amount;
            }

            $employeeAccount->save();
            $equityAccount->save();

            DB::commit();

            return redirect()->route('fin.employee.show', $employeeAccount->id)
                           ->with('success', 'Adjustment recorded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording adjustment: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error recording adjustment: ' . $e->getMessage());
        }
    }

    /**
     * Employee cash summary dashboard
     */
    public function dashboard()
    {
        // Total employee cash
        $totalCash = AccountModel::employeeCash()->sum('current_balance');
        
        // Employee count
        $employeeCount = AccountModel::employeeCash()->count();
        
        // Employees with positive balances
        $positiveBalances = AccountModel::employeeCash()
            ->where('current_balance', '>', 0)
            ->count();
        
        // Top 5 employees by balance
        $topEmployees = AccountModel::employeeCash()
            ->orderBy('current_balance', 'desc')
            ->limit(5)
            ->get();
        
        // Recent transactions
        $recentTransactions = LedgerModel::whereIn('transaction_type', [
            LedgerModel::TYPE_INVOICE,
            LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
            LedgerModel::TYPE_EXPENSE
        ])
        ->whereIn('from_account_id', AccountModel::employeeCash()->pluck('id'))
        ->orWhereIn('to_account_id', AccountModel::employeeCash()->pluck('id'))
        ->with(['fromAccount', 'toAccount'])
        ->orderBy('transaction_date', 'desc')
        ->limit(10)
        ->get();

        return view('fin.employee.dashboard', compact(
            'totalCash',
            'employeeCount',
            'positiveBalances',
            'topEmployees',
            'recentTransactions'
        ));
    }

    /**
     * Create expense request for employee
     */
    public function createExpenseRequest(Request $request, $id)
    {
        $validated = $request->validate([
            'expense_category' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:1000',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id'
        ]);

        try {
            DB::beginTransaction();

            $employeeAccount = AccountModel::with('user')->findOrFail($id);
            
            if (!$employeeAccount->user) {
                throw new \Exception("Employee user not found");
            }

            // Get expense category
            $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'expense')->first();
            
            if (!$category) {
                throw new \Exception("Expense category not found");
            }

            $loggedInUser = auth()->user();
            $createdByNote = "\n\n[Created by {$loggedInUser->fullname} on behalf of employee]";

            // Create request
            $requestModel = \App\Models\Request\RequestModel::create([
                'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
                'category_id' => $category->id,
                'requester_user_id' => $employeeAccount->user->id, // The employee
                'title' => $validated['expense_category'],
                'description' => ($validated['description'] ?? '') . $createdByNote,
                'amount' => $validated['amount'],
                'expense_category' => $validated['expense_category'],
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                'status' => \App\Models\Request\RequestModel::STATUS_PENDING,
                'priority' => 'normal',
                'requires_level_1' => $category->requiresLevel1(),
                'requires_level_2' => $category->requiresLevel2(),
                'level_1_status' => $category->requiresLevel1() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
                'level_2_status' => $category->requiresLevel2() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
                'submitted_at' => now(),
                'created_by' => $loggedInUser->id
            ]);

            DB::commit();

            $message = "Expense request created successfully for " . ($employeeAccount->user->fullname ?? $employeeAccount->account_name);

            return redirect()->route('fin.employee.show', $employeeAccount->id)
                           ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating expense request: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error creating expense request: ' . $e->getMessage());
        }
    }

    /**
     * Record money received into a company account
     */
    public function recordCompanyReceipt(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'from_account_id' => 'nullable|exists:t_fin_accounts,id',
            'from_external' => 'nullable|string|max:255',
            'description' => 'required|string|max:500',
            'transaction_date' => 'required|date',
            'requires_approval' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            $companyAccount = AccountModel::findOrFail($id);
            
            // Determine approval status
            $approvalStatus = $request->requires_approval ? LedgerModel::STATUS_PENDING : LedgerModel::STATUS_APPROVED;
            
            // Determine source
            $fromAccountId = $request->from_account_id ?? null;
            $description = $request->description;
            
            if (!$fromAccountId && $request->from_external) {
                $description = "Receipt from: {$request->from_external} - {$description}";
            }

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => 'company_receipt',
                'description' => $description,
                'from_account_id' => $fromAccountId,
                'to_account_id' => $companyAccount->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => $approvalStatus,
                'approval_date' => $approvalStatus === LedgerModel::STATUS_APPROVED ? now()->toDateString() : null,
                'approved_by' => $approvalStatus === LedgerModel::STATUS_APPROVED ? auth()->id() : null,
                'created_by' => auth()->id(),
                'comments' => $approvalStatus === LedgerModel::STATUS_PENDING ? "Awaiting approval" : "Auto-approved"
            ]);

            // If approved, update balances immediately
            if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
                if ($fromAccountId) {
                    $fromAccount = AccountModel::find($fromAccountId);
                    if ($fromAccount) {
                        $fromAccount->decrement('current_balance', $request->amount);
                    }
                }
                $companyAccount->increment('current_balance', $request->amount);
            }

            DB::commit();

            return redirect()->back()->with('success', $approvalStatus === LedgerModel::STATUS_PENDING 
                ? 'Receipt recorded and pending approval.' 
                : 'Receipt recorded successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error recording company receipt: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to record receipt: ' . $e->getMessage());
        }
    }

    /**
     * Record money paid out from a company account
     */
    public function recordCompanyPayment(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'to_account_id' => 'nullable|exists:t_fin_accounts,id',
            'to_external' => 'nullable|string|max:255',
            'expense_category' => 'nullable|string|max:100',
            'description' => 'required|string|max:500',
            'transaction_date' => 'required|date',
            'requires_approval' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            $companyAccount = AccountModel::findOrFail($id);
            
            // Check sufficient balance if auto-approved
            if (!$request->requires_approval && $request->amount > $companyAccount->current_balance) {
                throw new \Exception("Insufficient balance in {$companyAccount->account_name}");
            }
            
            // Determine approval status
            $approvalStatus = $request->requires_approval ? LedgerModel::STATUS_PENDING : LedgerModel::STATUS_APPROVED;
            
            // Determine destination
            $toAccountId = $request->to_account_id ?? null;
            $description = $request->description;
            
            if (!$toAccountId && $request->to_external) {
                $description = "Payment to: {$request->to_external} - {$description}";
            }
            
            if ($request->expense_category) {
                $description = "[{$request->expense_category}] {$description}";
            }

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => 'company_payment',
                'description' => $description,
                'from_account_id' => $companyAccount->id,
                'to_account_id' => $toAccountId,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => $approvalStatus,
                'approval_date' => $approvalStatus === LedgerModel::STATUS_APPROVED ? now()->toDateString() : null,
                'approved_by' => $approvalStatus === LedgerModel::STATUS_APPROVED ? auth()->id() : null,
                'created_by' => auth()->id(),
                'comments' => $approvalStatus === LedgerModel::STATUS_PENDING ? "Awaiting approval" : "Auto-approved"
            ]);

            // If approved, update balances immediately
            if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
                $companyAccount->decrement('current_balance', $request->amount);
                
                if ($toAccountId) {
                    $toAccount = AccountModel::find($toAccountId);
                    if ($toAccount) {
                        $toAccount->increment('current_balance', $request->amount);
                    }
                }
            }

            DB::commit();

            return redirect()->back()->with('success', $approvalStatus === LedgerModel::STATUS_PENDING 
                ? 'Payment recorded and pending approval.' 
                : 'Payment recorded successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error recording company payment: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to record payment: ' . $e->getMessage());
        }
    }

    /**
     * Record transfer between company accounts
     */
    public function recordCompanyTransfer(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'to_account_id' => 'required|exists:t_fin_accounts,id',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date'
        ]);

        try {
            DB::beginTransaction();

            $fromAccount = AccountModel::findOrFail($id);
            $toAccount = AccountModel::findOrFail($request->to_account_id);
            
            // Validate it's a company-to-company transfer
            $allowedCategories = [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK];
            if (!in_array($fromAccount->account_category, $allowedCategories) || 
                !in_array($toAccount->account_category, $allowedCategories)) {
                throw new \Exception("Transfers are only allowed between company accounts");
            }
            
            // Check sufficient balance
            if ($request->amount > $fromAccount->current_balance) {
                throw new \Exception("Insufficient balance in {$fromAccount->account_name}");
            }

            // Internal transfers don't require approval
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => 'company_transfer',
                'description' => $request->description ?? "Transfer from {$fromAccount->account_name} to {$toAccount->account_name}",
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approval_date' => now()->toDateString(),
                'approved_by' => auth()->id(),
                'created_by' => auth()->id(),
                'comments' => "Internal transfer - auto-approved"
            ]);

            // Update balances immediately
            $fromAccount->decrement('current_balance', $request->amount);
            $toAccount->increment('current_balance', $request->amount);

            DB::commit();

            return redirect()->back()->with('success', 'Transfer completed successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error recording company transfer: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to record transfer: ' . $e->getMessage());
        }
    }
}

