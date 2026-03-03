<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\InvoiceSettlementModel;
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

        // Calculate pending approvals and effective balance for each account
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
            
            // Set effective balance (calculated for employee_cash, stored for others)
            $account->effective_balance = $account->getEffectiveBalance();
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
        
        // === KPI 1: INVOICES DELIVERED (Enhanced with detailed settlement tracking) ===
        // Main value: Total invoices delivered (by delivery date)
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
        
        // === CASH INVOICES: Detailed Breakdown ===
        // Use invoice ledger entries which have complete tracking
        $cashInvoiceLedgers = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
            ->whereHas('order', function($q) use ($deliveredOrderIds) {
                $q->whereIn('id', $deliveredOrderIds)
                  ->whereIn('payment_method', ['cash', 'cash_on_delivery', 'Cash', 'COD']);
            });
        
        if ($startDate && $endDate) {
            $cashInvoiceLedgers->whereBetween('transaction_date', [$startDate, $endDate]);
        }
        
        $cashInvoiceLedgers = $cashInvoiceLedgers->get();
        
        // Calculate totals from invoice ledger entries
        $invoicesCash = $cashInvoiceLedgers->sum('amount');
        
        // Use settled_amount field which is the source of truth
        $cashSettledAmount = $cashInvoiceLedgers->sum('settled_amount');
        $cashOpenAmount = $invoicesCash - $cashSettledAmount;
        
        // Get settlement breakdown using InvoiceSettlementModel so that we
        // only count the portion of each deposit that actually settles
        // invoices delivered in this period.
        $cashInvoiceIds = $cashInvoiceLedgers->pluck('id')->toArray();
        
        $cashDeposited = 0;
        $cashUsedInExpenses = 0;
        $totalFromSettlements = 0;
        
        if (!empty($cashInvoiceIds)) {
            // Fetch all settlement rows for these invoices with their deposit
            $invoiceSettlements = InvoiceSettlementModel::whereIn('invoice_ledger_id', $cashInvoiceIds)
                ->with('settlementDeposit')
                ->get();
            
            foreach ($invoiceSettlements as $settlement) {
                $deposit = $settlement->settlementDeposit;
                if (!$deposit) {
                    // No deposit record (legacy data) – treat entire amount as deposit
                    $cashDeposited += $settlement->settled_amount;
                    $totalFromSettlements += $settlement->settled_amount;
                    continue;
                }
                
                $metadata = $deposit->settlement_metadata;
                $settledAmount = $settlement->settled_amount ?? 0;
                $totalFromSettlements += $settledAmount;
                
                if ($metadata && isset($metadata['is_short_cash_settlement']) && $metadata['is_short_cash_settlement']) {
                    // Short cash settlement: split this invoice's settled amount
                    $depositAmount = $metadata['deposit_amount'] ?? 0;
                    $shortCashAmount = $metadata['short_cash_amount'] ?? 0;
                    $totalSettlementAmount = $depositAmount + $shortCashAmount;
                    
                    if ($totalSettlementAmount > 0) {
                        $ratioDeposit = $depositAmount / $totalSettlementAmount;
                        $ratioShort  = $shortCashAmount / $totalSettlementAmount;
                        
                        $cashDeposited      += $settledAmount * $ratioDeposit;
                        $cashUsedInExpenses += $settledAmount * $ratioShort;
                    } else {
                        // Fallback: treat as regular deposit
                        $cashDeposited += $settledAmount;
                    }
                } else {
                    // Regular deposit: entire settled amount counts as deposited
                    $cashDeposited += $settledAmount;
                }
            }
        }
        
        // If there are settled invoices that don't have settlement rows
        // (very old data), treat the difference as fully deposited so that
        // Settled ≈ Deposited + Short Cash.
        $unmappedSettled = $cashSettledAmount - $totalFromSettlements;
        if ($unmappedSettled > 0) {
            $cashDeposited += $unmappedSettled;
        }
        
        // Pending with rider = invoices not yet settled (open status)
        $cashPendingWithRider = $cashOpenAmount;
        
        // === ONLINE INVOICES: Detailed Breakdown ===
        // Get ALL online invoice ledgers
        // Check ONLINE account (to_account) instead of order payment method for accuracy
        $onlineAccount = AccountModel::where('account_code', 'ONLINE')->first();
        $onlineInvoiceLedgersQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED);
        
        if ($onlineAccount) {
            $onlineInvoiceLedgersQuery->where('to_account_id', $onlineAccount->id);
        }
        
        if ($startDate && $endDate) {
            $onlineInvoiceLedgersQuery->whereBetween('transaction_date', [$startDate, $endDate]);
        }
        
        $onlineInvoiceLedgers = $onlineInvoiceLedgersQuery->get();
        
        // Calculate totals from invoice ledger entries
        $invoicesOnline = $onlineInvoiceLedgers->sum('amount');
        $onlineApproved = $onlineInvoiceLedgers
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->sum('amount');

        // Split pending by approval level (L1 vs L2)
        // Legacy 'pending' status is treated as pending_l1
        $onlinePendingL1 = $onlineInvoiceLedgers
            ->filter(function ($invoice) {
                return in_array($invoice->approval_status, [
                    LedgerModel::STATUS_PENDING,      // Legacy: treat as L1
                    LedgerModel::STATUS_PENDING_L1,
                ], true);
            })
            ->sum('amount');
        
        $onlinePendingL2 = $onlineInvoiceLedgers
            ->where('approval_status', LedgerModel::STATUS_PENDING_L2)
            ->sum('amount');
        
        // Total pending (for backward compatibility)
        $onlinePending = $onlinePendingL1 + $onlinePendingL2;
        
        // === KPI 2: ALL EXPENSES (from ledger, excluding vendor payments, including salaries) ===
        // Main value: ALL expenses from any account (ledger-based)
        $expensesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED);
        
        if ($startDate && $endDate) {
            $expensesQuery->whereBetween('transaction_date', [$startDate, $endDate]);
        }
        
        $ledgerExpenses = (clone $expensesQuery)->sum('amount') ?? 0;
        
        // Add salary payments (these are also expenses but tracked separately)
        $salaryExpensesQuery = \App\Models\HR\SalarySlipModel::whereIn('slip_status', ['approved', 'paid'])
            ->whereNotNull('ledger_transaction_id');
        
        if ($startDate && $endDate) {
            $salaryExpensesQuery->whereBetween('created_at', [$startDate, $endDate]);
        }
        
        $salaryExpenses = $salaryExpensesQuery->sum('net_salary') ?? 0;
        
        // Total expenses = Ledger expenses + Salary expenses
        $totalExpenses = $ledgerExpenses + $salaryExpenses;
        
        // Sub-value 1: Regular Expenses (ledger expenses only, excluding salaries)
        $regularExpenses = $ledgerExpenses;
        
        // Sub-value 2: Salary Expenses (from salary slips)
        $salaryExpensesForDisplay = $salaryExpenses;
        
        // Sub-value 3: Expenses Needing Settlement (pending settlement)
        $expensesNeedingSettlement = \App\Models\Request\RequestModel::where('status', 'approved')
            ->where('settlement_status', 'pending')
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            });
        
        if ($startDate && $endDate) {
            // ⭐ Use expense_date for filtering (falls back to created_at for old records)
            $expensesNeedingSettlement->whereRaw('DATE(COALESCE(expense_date, created_at)) BETWEEN ? AND ?', [$startDate, $endDate]);
        }
        
        $expensesNeedingSettlement = $expensesNeedingSettlement->sum('amount') ?? 0;
        
        // === KPI 3: VENDOR PAYMENTS (purchases + payments) ===
        // Sub-value 1: Vendor Purchases
        $vendorPurchasesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED);
        
        if ($startDate && $endDate) {
            $vendorPurchasesQuery->whereBetween('transaction_date', [$startDate, $endDate]);
        }
        
        $vendorPurchases = $vendorPurchasesQuery->sum('amount') ?? 0;
        
        // Sub-value 2: Vendor Payments
        $vendorPaymentsQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->where('approval_status', LedgerModel::STATUS_APPROVED);
        
        if ($startDate && $endDate) {
            $vendorPaymentsQuery->whereBetween('transaction_date', [$startDate, $endDate]);
        }
        
        $vendorPayments = $vendorPaymentsQuery->sum('amount') ?? 0;
        
        // Main value: Vendor Balance (what we owe vendors)
        // Balance = Purchases - Payments
        $vendorBalance = $vendorPurchases - $vendorPayments;
        
        // === KPI 4: APPROVALS & RIDERS BALANCE ===
        // Main value: Riders Balance (Real-time, NO FILTER)
        // Uses CALCULATED balance (excludes salary/personal transactions)
        $ridersBalance = AccountModel::getTotalEmployeeCashCalculatedBalance();
        
        // Sub-value 1: Pending Deposits (Cash IN)
        $pendingDepositsQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
            ->where('approval_status', LedgerModel::STATUS_PENDING);
        
        if ($startDate && $endDate) {
            $pendingDepositsQuery->whereBetween('transaction_date', [$startDate, $endDate]);
        }
        
        $pendingDeposits = $pendingDepositsQuery->sum('amount') ?? 0;
        
        // Sub-value 2: Pending Expenses (Cash OUT)
        $pendingExpensesQuery = \App\Models\Request\RequestModel::where('status', 'pending')
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            });
        
        if ($startDate && $endDate) {
            $pendingExpensesQuery->whereBetween('created_at', [$startDate, $endDate]);
        }
        
        $pendingExpenses = $pendingExpensesQuery->sum('amount') ?? 0;
        
        // === KPI 5: NF BALANCE (PROFIT) ===
        // Profit = Invoices Delivered - Expenses - Vendor Purchases
        $profit = $totalInvoices - $totalExpenses - $vendorPurchases;
        
        $summaryKPIs = [
            // Card 1: Invoices (Enhanced with detailed breakdown)
            'total_invoices' => $totalInvoices,
            'invoices_cash' => $invoicesCash,
            'cash_settled' => $cashSettledAmount, // NEW: Total settled amount
            'cash_deposited' => $cashDeposited,
            'cash_used_in_expenses' => $cashUsedInExpenses,
            'cash_pending_with_rider' => $cashPendingWithRider,
            'invoices_online' => $invoicesOnline,
            'online_approved' => $onlineApproved,
            'online_pending' => $onlinePending,
            'online_pending_l1' => $onlinePendingL1, // NEW: Pending L1 split
            'online_pending_l2' => $onlinePendingL2, // NEW: Pending L2 split
            
            // Card 2: Expenses
            'total_expenses' => $totalExpenses,
            'regular_expenses' => $regularExpenses,
            'salary_expenses' => $salaryExpensesForDisplay,
            'expenses_needing_settlement' => $expensesNeedingSettlement,
            
            // Card 3: Vendor Balance
            'vendor_balance' => $vendorBalance,
            'vendor_purchases' => $vendorPurchases,
            'vendor_payments' => $vendorPayments,
            
            // Card 4: Approvals & Riders
            'riders_balance' => $ridersBalance,
            'pending_deposits' => $pendingDeposits,
            'pending_expenses' => $pendingExpenses,
            
            // Card 5: NF Balance (Profit)
            'profit' => $profit,
            'profit_invoices' => $totalInvoices,
            'profit_expenses' => $totalExpenses,
            'profit_vendor_purchases' => $vendorPurchases,
            
            // Filter info
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
            // For vendor payments, use posted_date if available; otherwise transaction_date.
            $ledgerQuery->where(function($q) use ($dateFrom, $dateTo) {
                $q->where(function($subQ) use ($dateFrom, $dateTo) {
                    $subQ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where(function($dateQ) use ($dateFrom, $dateTo) {
                             $dateQ->whereBetween('posted_date', [$dateFrom, $dateTo])
                                   ->orWhere(function($fallbackQ) use ($dateFrom, $dateTo) {
                                       $fallbackQ->whereNull('posted_date')
                                                 ->whereBetween('transaction_date', [$dateFrom, $dateTo]);
                                   });
                         });
                })->orWhere(function($subQ) use ($dateFrom, $dateTo) {
                    $subQ->where('transaction_type', '!=', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->whereBetween('transaction_date', [$dateFrom, $dateTo]);
                });
            });
        }

        // Get ledger transactions
        $ledger = $ledgerQuery
            ->with(['fromAccount', 'toAccount', 'order.customer'])
            // Order by effective date: posted_date (for vendor payments) or transaction_date
            ->orderByRaw("COALESCE(CASE WHEN transaction_type = ? THEN posted_date END, transaction_date) DESC", [LedgerModel::TYPE_VENDOR_PAYMENT])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Calculate running balance (from oldest to newest for calculation)
        // IMPORTANT: Get ALL transactions for display, but only update balance for APPROVED ones
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
            // Only update balance for APPROVED transactions
            // Pending and rejected transactions show the balance WITHOUT their effect
            if ($transaction->approval_status === LedgerModel::STATUS_APPROVED) {
                if ($transaction->to_account_id === $account->id) {
                    // Money coming in
                    $runningBalance += $transaction->amount;
                } else {
                    // Money going out
                    $runningBalance -= $transaction->amount;
                }
            }
            // Store the current balance for this transaction (whether approved or not)
            // Rejected/pending transactions will show the balance as if they never happened
            $balanceMap[$transaction->id] = $runningBalance;
        }

        // Attach running balances to paginated results (in reverse since we display newest first)
        $ledger->getCollection()->transform(function($transaction) use ($balanceMap, $account) {
            // Use the balance from the map, or fall back to current balance if not found
            $transaction->running_balance = $balanceMap[$transaction->id] ?? $account->current_balance;
            return $transaction;
        });

        // Summary with date filters
        // For EMPLOYEE accounts: Invoices come TO account, deposits go FROM account
        // For COMPANY accounts (NF Cash): Deposits come TO account, expenses go FROM account
        
        $invoicesQuery = LedgerModel::where('to_account_id', $account->id)
            ->where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED); // Exclude reversed transactions
        $expensesQuery = LedgerModel::where('from_account_id', $account->id)
            ->where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED); // Exclude reversed transactions
        
        // FIXED: Deposits direction depends on account type
        // IMPORTANT: Only count APPROVED deposits (exclude pending and rejected)
        if ($isEmployeeAccount) {
            // Employee depositing TO company: money going FROM employee account
            $depositsQuery = LedgerModel::where('from_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_APPROVED);
        } else {
            // Company receiving deposits: money coming TO company account
            $depositsQuery = LedgerModel::where('to_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_APPROVED);
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
            
            // Invoices IN (for Online Bank account specifically) - ONLY APPROVED (excludes reversed)
            $invoicesInQuery = LedgerModel::where('to_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', LedgerModel::STATUS_APPROVED);
            if ($dateFrom && $dateTo) {
                $invoicesInQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashInBreakdown['invoices'] = $invoicesInQuery->sum('amount') ?? 0;
            
            // Others IN (adjustments, reimbursements, receipts, etc.)
            $othersInQuery = LedgerModel::where('to_account_id', $account->id)
                ->whereIn('transaction_type', [
                    LedgerModel::TYPE_ADJUSTMENT,
                    LedgerModel::TYPE_REIMBURSEMENT_PAYMENT,
                    LedgerModel::TYPE_SALARY_ADVANCE,
                    'company_receipt'  // External receipts
                ]);
            if ($dateFrom && $dateTo) {
                $othersInQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashInBreakdown['others_in'] = $othersInQuery->sum('amount') ?? 0;
            
            $cashInBreakdown['total'] = $cashInBreakdown['deposits'] + 
                                        $cashInBreakdown['settlements'] + 
                                        $cashInBreakdown['transfers_in'] + 
                                        $cashInBreakdown['invoices'] + 
                                        $cashInBreakdown['others_in'];
            
            // === EXP_FUND SPECIFIC: Transfer Sources Breakdown ===
            if ($account->account_code === 'EXP_FUND') {
                $transfersInDetailed = LedgerModel::with('fromAccount')
                    ->where('to_account_id', $account->id)
                    ->where('transaction_type', LedgerModel::TYPE_TRANSFER);
                if ($dateFrom && $dateTo) {
                    $transfersInDetailed->whereBetween('transaction_date', [$dateFrom, $dateTo]);
                }
                $transfersInData = $transfersInDetailed->get();
                
                $transferSources = [
                    'from_online' => 0,
                    'from_nf_cash' => 0,
                    'from_personal' => 0,
                    'from_others' => 0
                ];
                
                foreach ($transfersInData as $transfer) {
                    $sourceCode = $transfer->fromAccount->account_code ?? 'unknown';
                    if ($sourceCode === 'ONLINE') {
                        $transferSources['from_online'] += $transfer->amount;
                    } elseif ($sourceCode === 'NF_CASH') {
                        $transferSources['from_nf_cash'] += $transfer->amount;
                    } elseif (str_contains($sourceCode, 'PERSONAL')) {
                        $transferSources['from_personal'] += $transfer->amount;
                    } else {
                        $transferSources['from_others'] += $transfer->amount;
                    }
                }
                
                $cashInBreakdown['transfer_sources'] = $transferSources;
            }
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
            
            // Others OUT (adjustments, payments, etc.)
            $othersOutQuery = LedgerModel::where('from_account_id', $account->id)
                ->whereIn('transaction_type', [
                    LedgerModel::TYPE_ADJUSTMENT,
                    LedgerModel::TYPE_VENDOR_PURCHASE,
                    'company_payment'  // External payments
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
            
            // === EXP_FUND SPECIFIC: Top 5 Expense Categories ===
            if ($account->account_code === 'EXP_FUND') {
                // Get ALL cash out transactions from ledger (expenses, salaries, salary advances, vendor payments, etc.)
                $allExpensesQuery = LedgerModel::where('from_account_id', $account->id)
                    ->whereIn('transaction_type', [
                        LedgerModel::TYPE_EXPENSE,
                        'salary_payment',
                        LedgerModel::TYPE_SALARY_ADVANCE,
                        LedgerModel::TYPE_VENDOR_PAYMENT,
                        LedgerModel::TYPE_VENDOR_PURCHASE,
                        LedgerModel::TYPE_SETTLEMENT
                    ]);
                
                if ($dateFrom && $dateTo) {
                    $allExpensesQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
                }
                
                $allExpenses = $allExpensesQuery->get();
                
                // Categorize expenses
                $expensesByCategory = [];
                
                foreach ($allExpenses as $expense) {
                    $category = 'Uncategorized';
                    
                    // Determine category based on transaction type and description
                    if ($expense->transaction_type === 'salary_payment') {
                        $category = 'Salary';
                    } elseif ($expense->transaction_type === LedgerModel::TYPE_SALARY_ADVANCE) {
                        $category = 'Salary Advance';
                    } elseif ($expense->transaction_type === LedgerModel::TYPE_VENDOR_PAYMENT) {
                        $category = 'Vendor Payments';
                    } elseif ($expense->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE) {
                        $category = 'Vendor Purchases';
                    } elseif ($expense->transaction_type === LedgerModel::TYPE_SETTLEMENT) {
                        // For settlements, try to get category from linked request
                        if ($expense->request_id) {
                            $request = \App\Models\Request\RequestModel::find($expense->request_id);
                            if ($request && $request->expense_category) {
                                $category = $request->expense_category;
                            } else {
                                $category = 'Settlements';
                            }
                        } else {
                            $category = 'Settlements';
                        }
                    } elseif ($expense->transaction_type === LedgerModel::TYPE_EXPENSE) {
                        // For regular expenses, try to get category from linked request
                        if ($expense->request_id) {
                            $request = \App\Models\Request\RequestModel::find($expense->request_id);
                            if ($request && $request->expense_category) {
                                $category = $request->expense_category;
                            }
                        } else {
                            // Try to extract category from description
                            $category = 'Other Expenses';
                        }
                    }
                    
                    if (!isset($expensesByCategory[$category])) {
                        $expensesByCategory[$category] = 0;
                    }
                    $expensesByCategory[$category] += $expense->amount;
                }
                
                // Sort by amount descending
                arsort($expensesByCategory);
                
                // Get top 5 and group rest as "Others"
                $topCategories = [];
                $othersTotal = 0;
                $count = 0;
                
                foreach ($expensesByCategory as $category => $amount) {
                    if ($count < 5) {
                        $topCategories[$category] = $amount;
                        $count++;
                    } else {
                        $othersTotal += $amount;
                    }
                }
                
                $cashOutBreakdown['expense_categories'] = [
                    'top_5' => $topCategories,
                    'others' => $othersTotal
                ];
                
                // Update the total to match the breakdown for EXP_FUND
                $cashOutBreakdown['total'] = array_sum($topCategories) + $othersTotal;
            }
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
            // Exclude reversed transactions (e.g., from payment method changes)
            $cashInvoicesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('mode', LedgerModel::MODE_CASH)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->whereHas('toAccount', function($q) {
                    $q->where('account_category', 'employee_cash');
                });
            
            if ($dateFrom && $dateTo) {
                $cashInvoicesQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            $cashInvoices = $cashInvoicesQuery->sum('amount') ?? 0;
            
            // Riders Balance: Total CALCULATED balance held by all riders
            // Uses calculated balance (excludes salary/personal transactions)
            $ridersBalance = AccountModel::getTotalEmployeeCashCalculatedBalance();
        }
        
        // Get pending approvals details for Online Bank account (NO DATE FILTER - show all pending)
        $pendingApprovals = [];
        $pendingApprovalsTotal = 0;
        if ($account->account_code === 'ONLINE') {
            $pendingApprovals = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy'])
                ->where('to_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', LedgerModel::STATUS_PENDING)
                ->orderBy('transaction_date', 'desc')
                ->get();
            
            // Calculate total pending amount for Online Bank
            $pendingApprovalsTotal = $pendingApprovals->sum('amount');
        }
        
        $summary = [
            'opening_balance' => $account->opening_balance,
            'current_balance' => $account->getEffectiveBalance(), // Uses calculated for employee_cash
            'total_invoices' => $invoicesQuery->sum('amount'),
            'total_expenses' => $expensesQuery->sum('amount'),
            'total_deposits' => $depositsQuery->sum('amount'),
            'total_withdrawals' => $withdrawalsQuery->sum('amount'),
            'total_pending' => $account->account_code === 'ONLINE' ? $pendingApprovalsTotal : ($pendingQuery ? $pendingQuery->sum('amount') : ($pendingAmount->sum('amount') ?? 0)),
            'cash_in' => $cashInBreakdown,
            'cash_out' => $cashOutBreakdown,
            'short_cash' => $shortCash,
            'cash_invoices' => $cashInvoices,
            'riders_balance' => $ridersBalance,
            'pending_approvals' => $pendingApprovals
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
        // ⭐ Use expense_date for filtering (falls back to created_at for old records)
        if ($dateFrom && $dateTo) {
            $expenseRequestsQuery->whereRaw('DATE(COALESCE(expense_date, created_at)) BETWEEN ? AND ?', [$dateFrom, $dateTo]);
        }

        $expenseRequests = $expenseRequestsQuery->orderBy(\DB::raw('COALESCE(expense_date, created_at)'), 'desc')->get();

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
            // Also calculate pending settlement amounts per invoice
            $pendingSettlementInvoiceIds = [];
            $pendingSettlementAmounts = []; // Track pending amounts per invoice
            
            $pendingDeposits = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('from_account_id', $employeeAccount->id)
                ->where('approval_status', LedgerModel::STATUS_PENDING)
                ->where(function($q) {
                    $q->where('description', 'LIKE', '%Settlement%')
                      ->orWhere('description', 'LIKE', '%Partial Payment%');
                })
                ->get();
            
            foreach ($pendingDeposits as $deposit) {
                // Try ledger metadata first (NEW), fallback to session (OLD)
                $settlementData = $deposit->settlement_metadata;
                if (!$settlementData) {
                    $sessionKey = "settlement_pending_{$deposit->id}";
                    $settlementData = \Session::get($sessionKey);
                }
                
                if ($settlementData && isset($settlementData['invoice_ids'])) {
                    $invoiceIds = $settlementData['invoice_ids'];
                    $depositAmount = $settlementData['deposit_amount'] ?? 0;
                    
                    // Check if this is a short cash or partial payment
                    $isShortCash = $settlementData['is_short_cash_settlement'] ?? false;
                    $isPartialPayment = $settlementData['is_partial_payment'] ?? false;
                    $shortCashAmount = $settlementData['short_cash_amount'] ?? 0;
                    
                    // Calculate total settlement amount
                    if ($isShortCash) {
                        $totalSettlementAmount = $depositAmount + $shortCashAmount;
                    } else {
                        $totalSettlementAmount = $depositAmount;
                    }
                    
                    // Distribute pending amount across invoices (same logic as processInvoiceSettlement)
                    $invoices = LedgerModel::whereIn('id', $invoiceIds)
                        ->whereIn('settlement_status', ['open', 'partial'])
                        ->orderBy('transaction_date', 'asc')
                        ->get();
                    
                    $remainingAmount = $totalSettlementAmount;
                    foreach ($invoices as $invoice) {
                        $outstandingForThisInvoice = $invoice->amount - ($invoice->settled_amount ?? 0);
                        $amountToSettle = min($remainingAmount, $outstandingForThisInvoice);
                        
                        if ($amountToSettle > 0) {
                            if (!isset($pendingSettlementAmounts[$invoice->id])) {
                                $pendingSettlementAmounts[$invoice->id] = 0;
                            }
                            $pendingSettlementAmounts[$invoice->id] += $amountToSettle;
                            $remainingAmount -= $amountToSettle;
                        }
                    }
                    
                    // Only exclude invoices that will be fully settled by pending deposits
                    foreach ($invoices as $invoice) {
                        $pendingForInvoice = $pendingSettlementAmounts[$invoice->id] ?? 0;
                        $outstandingAfterPending = ($invoice->amount - ($invoice->settled_amount ?? 0)) - $pendingForInvoice;
                        
                        if ($outstandingAfterPending <= 0) {
                            // Will be fully settled - exclude from list
                            $pendingSettlementInvoiceIds[] = $invoice->id;
                        }
                    }
                }
            }
            
            // Get all open and partial invoices for this rider
            // IMPORTANT: Exclude reversed transactions (e.g., from payment method changes)
            // Now we include invoices with pending settlements if they still have remaining balance
            $openInvoices = LedgerModel::where('to_account_id', $employeeAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->whereIn('settlement_status', ['open', 'partial'])
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->whereNotIn('id', $pendingSettlementInvoiceIds)
                ->orderBy('transaction_date', 'asc')
                ->get();
            
            // Calculate outstanding balance (amount - settled_amount - pending_amount)
            $invoices = $openInvoices->map(function($invoice) use ($pendingSettlementAmounts) {
                $pendingAmount = $pendingSettlementAmounts[$invoice->id] ?? 0;
                $outstandingAmount = $invoice->amount - ($invoice->settled_amount ?? 0) - $pendingAmount;
                
                // Get customer name from order (uses OrderModel's customer_name accessor)
                $customerName = $invoice->order ? $invoice->order->customer_name : null;
                
                return [
                    'id' => $invoice->id,
                    'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                    'customer_name' => $customerName, // ✅ Added customer name
                    'transaction_date' => $invoice->transaction_date->format('Y-m-d'),
                    'description' => $invoice->description,
                    'amount' => $invoice->amount,
                    'settled_amount' => $invoice->settled_amount ?? 0,
                    'pending_settlement_amount' => $pendingAmount,
                    'outstanding_amount' => $outstandingAmount
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

            // Verify selected invoices belong to this rider and are open or partial
            // Exclude reversed transactions (e.g., from payment method changes)
            $selectedInvoices = LedgerModel::whereIn('id', $request->invoice_ids)
                ->where('to_account_id', $employeeAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->whereIn('settlement_status', ['open', 'partial'])
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->orderBy('transaction_date', 'asc')
                ->get();

            if ($selectedInvoices->count() !== count($request->invoice_ids)) {
                throw new \Exception("Some selected invoices are invalid or already settled");
            }

            // Calculate expected amount (remaining balance for partial invoices)
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
     * Record short cash settlement (deposit + expense for shortage)
     */
    public function recordShortCashSettlement(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'destination_account_id' => 'nullable|exists:t_fin_accounts,id',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'exists:t_fin_ledger,id',
            'expense_category' => 'nullable|string|max:100' // Optional - only required if there's shortage
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

            // Verify selected invoices belong to this rider and are open or partial
            // Exclude reversed transactions (e.g., from payment method changes)
            $selectedInvoices = LedgerModel::whereIn('id', $request->invoice_ids)
                ->where('to_account_id', $employeeAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->whereIn('settlement_status', ['open', 'partial'])
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->orderBy('transaction_date', 'asc')
                ->get();

            if ($selectedInvoices->count() !== count($request->invoice_ids)) {
                throw new \Exception("Some selected invoices are invalid or already settled");
            }

            // Calculate expected amount and shortage (remaining balance for partial invoices)
            $totalOutstanding = $selectedInvoices->sum(function($invoice) {
                return $invoice->amount - ($invoice->settled_amount ?? 0);
            });

            $depositAmount = $request->amount;
            $shortCashAmount = $totalOutstanding - $depositAmount;

            // Use tolerance for floating-point comparison (0.01 Rs tolerance)
            // This handles edge cases from old transactions or rounding issues
            $TOLERANCE = 0.01;
            
            if ($shortCashAmount < -$TOLERANCE) {
                throw new \Exception("Deposit amount cannot exceed total outstanding");
            }
            
            // If shortage is within tolerance (e.g., 0.001 Rs), treat as exact match
            if (abs($shortCashAmount) < $TOLERANCE) {
                $shortCashAmount = 0;
            }

            // Handle both full payment (no shortage) and short cash (with shortage)
            $expenseRequest = null;
            $isPartialPayment = false;
            
            if ($shortCashAmount > 0) {
                // Validate expense category is provided when there's shortage
                if (!$request->expense_category) {
                    throw new \Exception("Category is required when there is a shortage.");
                }
                
                // Check if this is a PENDING (partial payment) or actual expense
                if (strtoupper($request->expense_category) === 'PENDING') {
                    // Partial payment - no expense request, just settle what was deposited
                    $isPartialPayment = true;
                    \Log::info("Partial payment settlement - keeping invoices open", [
                        'deposit_amount' => $depositAmount,
                        'remaining_amount' => $shortCashAmount,
                        'total_outstanding' => $totalOutstanding
                    ]);
                } else {
                    // Create expense request for actual shortage
                    $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'expense')->first();
                    
                    if (!$category) {
                        throw new \Exception("Expense category not found in system. Please contact administrator.");
                    }
                    
                    $expenseRequest = \App\Models\Request\RequestModel::create([
                        'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
                        'category_id' => $category->id,
                        'requester_user_id' => $employeeAccount->user_id,
                        'title' => "Short Cash - {$request->expense_category}",
                        'amount' => $shortCashAmount,
                        'expense_category' => $request->expense_category,
                        'description' => "Short cash from invoice settlement - " . $request->expense_category . ($request->description ? " - {$request->description}" : ""),
                        'payment_source_account_id' => $employeeAccount->id, // From rider's balance
                        'status' => \App\Models\Request\RequestModel::STATUS_PENDING,
                        'settlement_status' => 'pending', // Needs settlement - deduct from rider balance
                        'requires_level_1' => $category->requiresLevel1(),
                        'requires_level_2' => $category->requiresLevel2(),
                        'level_1_status' => $category->requiresLevel1() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
                        'level_2_status' => $category->requiresLevel2() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
                        'submitted_at' => now(),
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            // Build description with invoice numbers
            $invoiceNumbers = $selectedInvoices->map(function($invoice) {
                return $invoice->order ? $invoice->order->order_number : "Invoice #" . $invoice->id;
            })->take(3)->join(', ');
            
            if ($selectedInvoices->count() > 3) {
                $invoiceNumbers .= " + " . ($selectedInvoices->count() - 3) . " more";
            }

            // Build description based on whether there's shortage or not
            if ($shortCashAmount > 0) {
                if ($isPartialPayment) {
                    // Partial payment settlement
                    $description = "Partial Payment: {$invoiceNumbers} - Paid Rs. " . number_format($depositAmount, 2) . ", Remaining Rs. " . number_format($shortCashAmount, 2);
                    $comments = "Partial payment for {$selectedInvoices->count()} invoice(s). Total outstanding: Rs. " . number_format($totalOutstanding, 2) . ". Paid: Rs. " . number_format($depositAmount, 2) . ". Remaining: Rs. " . number_format($shortCashAmount, 2) . " (will remain open for future settlement)";
                } else {
                    // Short cash settlement with expense
                    $description = "Short Cash Settlement: {$invoiceNumbers} - Deposit Rs. " . number_format($depositAmount, 2) . ", Expense Rs. " . number_format($shortCashAmount, 2) . " ({$request->expense_category})";
                    $comments = "Short cash settlement for {$selectedInvoices->count()} invoice(s). Total outstanding: Rs. " . number_format($totalOutstanding, 2) . ". Deposit: Rs. " . number_format($depositAmount, 2) . ". Expense ({$request->expense_category}): Rs. " . number_format($shortCashAmount, 2);
                }
            } else {
                // Full payment settlement
                $description = "Settlement for invoices: {$invoiceNumbers}";
                $comments = "Full payment settlement for {$selectedInvoices->count()} invoice(s). Total: Rs. " . number_format($totalOutstanding, 2);
            }
            
            if ($request->description) {
                $description .= " - {$request->description}";
            }

            // Create DEPOSIT TRANSACTION (pending approval)
            // Store settlement metadata
            $settlementMetadata = [
                'invoice_ids' => $request->invoice_ids,
                'deposit_amount' => $depositAmount,
                'total_outstanding' => $totalOutstanding,
            ];
            
            // Add short cash details if applicable
            if ($shortCashAmount > 0) {
                $settlementMetadata['short_cash_amount'] = $shortCashAmount;
                $settlementMetadata['expense_category'] = $request->expense_category;
                
                if ($isPartialPayment) {
                    // Partial payment - no expense, just partial settlement
                    $settlementMetadata['is_partial_payment'] = true;
                    $settlementMetadata['is_short_cash_settlement'] = false;
                } else if ($expenseRequest) {
                    // Short cash with expense
                    $settlementMetadata['expense_request_id'] = $expenseRequest->id;
                    $settlementMetadata['is_short_cash_settlement'] = true;
                    $settlementMetadata['is_partial_payment'] = false;
                }
            }
            
            $depositLedger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
                'description' => $description,
                'from_account_id' => $employeeAccount->id,
                'to_account_id' => $destinationAccount->id,
                'amount' => $depositAmount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_PENDING,
                'approval_date' => null,
                'approved_by' => null,
                'created_by' => auth()->id(),
                'comments' => $comments,
                'settlement_metadata' => $settlementMetadata
            ]);

            DB::commit();

            // Build success message based on settlement type
            if ($shortCashAmount > 0) {
                if ($isPartialPayment) {
                    $message = "Partial payment recorded and pending approval! ";
                    $message .= "Paid: Rs. " . number_format($depositAmount, 2) . ", ";
                    $message .= "Remaining: Rs. " . number_format($shortCashAmount, 2) . ". ";
                    $message .= "Invoice(s) will remain open with remaining balance after approval.";
                } else {
                    $message = "Short cash settlement recorded and pending approval! ";
                    $message .= "Deposit: Rs. " . number_format($depositAmount, 2) . ", ";
                    $message .= "Expense ({$request->expense_category}): Rs. " . number_format($shortCashAmount, 2) . ". ";
                    $message .= "Both transactions will be processed together upon manager approval.";
                }
            } else {
                $message = "Settlement recorded and pending approval! ";
                $message .= "Amount: Rs. " . number_format($depositAmount, 2) . " for {$selectedInvoices->count()} invoice(s). ";
                $message .= "Balances will update after manager approval.";
            }

            return redirect()->route('fin.employee.show', $employeeAccount->id)
                           ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording short cash settlement: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error recording short cash settlement: ' . $e->getMessage());
        }
    }

    /**
     * Debug: Detailed settlement info for a specific invoice
     */
    public function debugInvoiceSettlement($invoiceId)
    {
        try {
            $invoice = LedgerModel::with(['order', 'toAccount.user'])->find($invoiceId);
            
            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }
            
            $debug = [
                'invoice' => [
                    'id' => $invoice->id,
                    'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                    'order_id' => $invoice->order_id,
                    'amount' => $invoice->amount,
                    'settled_amount' => $invoice->settled_amount,
                    'settlement_status' => $invoice->settlement_status,
                    'settled_via_ledger_id' => $invoice->settled_via_ledger_id,
                    'transaction_date' => $invoice->transaction_date->format('Y-m-d'),
                    'rider' => $invoice->toAccount && $invoice->toAccount->user ? $invoice->toAccount->user->fullname : 'N/A',
                ],
                'settlement_methods' => [],
            ];
            
            // Method 1: Check metadata (NEW method)
            $metadataDeposits = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->whereJsonContains('settlement_metadata->invoice_ids', (string)$invoiceId)
                ->get();
            
            if ($metadataDeposits->count() > 0) {
                $debug['settlement_methods']['metadata'] = $metadataDeposits->map(function($dep) {
                    return [
                        'deposit_id' => $dep->id,
                        'amount' => $dep->amount,
                        'date' => $dep->transaction_date->format('Y-m-d'),
                        'approval_status' => $dep->approval_status,
                        'metadata' => $dep->settlement_metadata,
                    ];
                });
            } else {
                $debug['settlement_methods']['metadata'] = 'Not found in any deposit metadata';
            }
            
            // Method 2: Check invoice_settlements table (LEGACY)
            $legacySettlements = \DB::table('t_fin_invoice_settlements')
                ->where('invoice_ledger_id', $invoiceId)
                ->get();
            
            if ($legacySettlements->count() > 0) {
                $debug['settlement_methods']['invoice_settlements_table'] = $legacySettlements->map(function($row) {
                    $ledger = LedgerModel::find($row->settlement_deposit_id);
                    return [
                        'settlement_deposit_id' => $row->settlement_deposit_id,
                        'settled_amount' => $row->settled_amount,
                        'created_at' => $row->created_at,
                        'ledger_exists' => $ledger ? 'Yes' : 'No',
                        'ledger_type' => $ledger ? $ledger->transaction_type : null,
                        'ledger_amount' => $ledger ? $ledger->amount : null,
                    ];
                });
            } else {
                $debug['settlement_methods']['invoice_settlements_table'] = 'Not found in invoice_settlements table';
            }
            
            // Method 3: Check settled_via_ledger_id (DIRECT REFERENCE)
            if ($invoice->settled_via_ledger_id) {
                $settledVia = LedgerModel::find($invoice->settled_via_ledger_id);
                $debug['settlement_methods']['settled_via_ledger_id'] = [
                    'ledger_id' => $invoice->settled_via_ledger_id,
                    'ledger_exists' => $settledVia ? 'Yes' : 'No',
                    'ledger_type' => $settledVia ? $settledVia->transaction_type : null,
                    'ledger_amount' => $settledVia ? $settledVia->amount : null,
                    'ledger_date' => $settledVia ? $settledVia->transaction_date->format('Y-m-d') : null,
                    'approval_status' => $settledVia ? $settledVia->approval_status : null,
                ];
            } else {
                $debug['settlement_methods']['settled_via_ledger_id'] = 'Not set';
            }
            
            // Check for any deposits from this rider around the settlement date
            if ($invoice->settled_at) {
                $nearbyDeposits = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                    ->where('from_account_id', $invoice->to_account_id)
                    ->whereBetween('transaction_date', [
                        \Carbon\Carbon::parse($invoice->settled_at)->subDays(3),
                        \Carbon\Carbon::parse($invoice->settled_at)->addDays(3)
                    ])
                    ->get();
                
                $debug['nearby_deposits'] = $nearbyDeposits->map(function($dep) {
                    return [
                        'id' => $dep->id,
                        'amount' => $dep->amount,
                        'date' => $dep->transaction_date->format('Y-m-d'),
                        'description' => $dep->description,
                        'approval_status' => $dep->approval_status,
                        'has_metadata' => !empty($dep->settlement_metadata),
                    ];
                });
            }
            
            return response()->json([
                'success' => true,
                'debug' => $debug,
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Error in debugInvoiceSettlement: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug: Find settled invoices without settlement metadata
     */
    public function debugMissingMetadata(Request $request)
    {
        try {
            $filterType = $request->input('filter_type', 'month');
            $filterMonth = $request->input('filter_month');
            
            $startDate = null;
            $endDate = null;
            
            if ($filterType === 'month' && $filterMonth) {
                $startDate = \Carbon\Carbon::parse($filterMonth . '-01')->startOfMonth();
                $endDate = \Carbon\Carbon::parse($filterMonth . '-01')->endOfMonth();
            }
            
            // Get delivered order IDs
            $invoicesQuery = \DB::table('t_crm_order_status_history')
                ->where('status_code', 'delivered')
                ->where('is_current', 1);
            
            if ($startDate && $endDate) {
                $invoicesQuery->whereBetween('changed_at', [$startDate, $endDate]);
            }
            
            $deliveredOrderIds = $invoicesQuery->pluck('order_id');
            
            // Get cash invoice ledgers that are settled
            $settledCashInvoices = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->whereHas('order', function($q) use ($deliveredOrderIds) {
                    $q->whereIn('id', $deliveredOrderIds)
                      ->whereIn('payment_method', ['cash', 'cash_on_delivery', 'Cash', 'COD']);
                })
                ->where(function($q) {
                    $q->where('settled_amount', '>', 0)
                      ->orWhere('settlement_status', 'settled');
                })
                ->with(['order'])
                ->get();
            
            $missingMetadata = [];
            $hasMetadata = [];
            
            foreach ($settledCashInvoices as $invoice) {
                // Try to find settlement deposit
                $settlementDeposit = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                    ->whereIn('approval_status', [
                        LedgerModel::STATUS_APPROVED,
                        LedgerModel::STATUS_PENDING,
                        LedgerModel::STATUS_PENDING_L1,
                        LedgerModel::STATUS_PENDING_L2,
                    ])
                    ->whereJsonContains('settlement_metadata->invoice_ids', (string)$invoice->id)
                    ->first();
                
                $info = [
                    'invoice_id' => $invoice->id,
                    'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                    'amount' => $invoice->amount,
                    'settled_amount' => $invoice->settled_amount,
                    'settlement_status' => $invoice->settlement_status,
                    'transaction_date' => $invoice->transaction_date->format('Y-m-d'),
                ];
                
                if (!$settlementDeposit || !$settlementDeposit->settlement_metadata) {
                    $missingMetadata[] = $info;
                } else {
                    $hasMetadata[] = array_merge($info, [
                        'deposit_id' => $settlementDeposit->id,
                        'is_short_cash' => $settlementDeposit->settlement_metadata['is_short_cash_settlement'] ?? false,
                        'deposit_amount' => $settlementDeposit->settlement_metadata['deposit_amount'] ?? $settlementDeposit->amount,
                        'expense_amount' => $settlementDeposit->settlement_metadata['short_cash_amount'] ?? 0,
                    ]);
                }
            }
            
            return response()->json([
                'success' => true,
                'summary' => [
                    'total_settled' => $settledCashInvoices->count(),
                    'with_metadata' => count($hasMetadata),
                    'missing_metadata' => count($missingMetadata),
                    'total_settled_amount' => $settledCashInvoices->sum('settled_amount'),
                    'metadata_deposit_total' => collect($hasMetadata)->sum('deposit_amount'),
                    'metadata_expense_total' => collect($hasMetadata)->sum('expense_amount'),
                ],
                'missing_metadata_invoices' => $missingMetadata,
                'has_metadata_invoices' => $hasMetadata,
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Error in debugMissingMetadata: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed invoice breakdown for the invoices card modal
     */
    public function getInvoiceBreakdown(Request $request)
    {
        try {
            // Get filter parameters
            $filterType = $request->input('filter_type', 'month'); // day, month, custom
            $filterDate = $request->input('filter_date');
            $filterMonth = $request->input('filter_month');
            $filterStartDate = $request->input('filter_start_date');
            $filterEndDate = $request->input('filter_end_date');
            
            // Modal-specific filters
            $paymentMethod = $request->input('payment_method', 'all'); // all, cash, online
            $settlementStatus = $request->input('settlement_status', 'all'); // all, settled, open
            
            // Determine date range
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
            
            // Get delivered order IDs for the period
            $invoicesQuery = \DB::table('t_crm_order_status_history')
                ->where('status_code', 'delivered')
                ->where('is_current', 1);
            
            if ($startDate && $endDate) {
                $invoicesQuery->whereBetween('changed_at', [$startDate, $endDate]);
            }
            
            $deliveredOrderIds = $invoicesQuery->pluck('order_id');
            
            // Get invoice ledger entries
            $query = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->whereHas('order', function($q) use ($deliveredOrderIds) {
                    $q->whereIn('id', $deliveredOrderIds);
                })
                ->with(['order.customer', 'toAccount.user']);
            
            if ($startDate && $endDate) {
                $query->whereBetween('transaction_date', [$startDate, $endDate]);
            }
            
            // Apply payment method filter
            if ($paymentMethod === 'cash') {
                $query->whereHas('order', function($q) {
                    $q->whereIn('payment_method', ['cash', 'cash_on_delivery', 'Cash', 'COD']);
                });
            } elseif ($paymentMethod === 'online') {
                $query->whereHas('order', function($q) {
                    $q->whereIn('payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment']);
                });
            }
            
            // Apply settlement status filter
            if ($settlementStatus === 'settled') {
                $query->where('settlement_status', 'settled');
            } elseif ($settlementStatus === 'open') {
                $query->whereIn('settlement_status', ['open', 'partial']);
            }
            
            $invoices = $query->orderBy('transaction_date', 'desc')->get();
            
            // Enhance each invoice with settlement details
            $invoices = $invoices->map(function($invoice) {
                $settlementDetails = null;
                
                // Strategy 1: Try to find deposit with this invoice in metadata (NEW method)
                // Note: Try both integer and string formats as metadata might store either
                $settlementDeposit = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                    ->whereIn('approval_status', [
                        LedgerModel::STATUS_APPROVED,
                        LedgerModel::STATUS_PENDING,
                        LedgerModel::STATUS_PENDING_L1,
                        LedgerModel::STATUS_PENDING_L2,
                    ])
                    ->where(function($q) use ($invoice) {
                        $q->whereJsonContains('settlement_metadata->invoice_ids', (int)$invoice->id)
                          ->orWhereJsonContains('settlement_metadata->invoice_ids', (string)$invoice->id);
                    })
                    ->first();
                
                if ($settlementDeposit && $settlementDeposit->settlement_metadata) {
                    $metadata = $settlementDeposit->settlement_metadata;
                    $settlementDetails = [
                        'deposit_id' => $settlementDeposit->id,
                        'deposit_date' => $settlementDeposit->transaction_date,
                        'approval_status' => $settlementDeposit->approval_status,
                        'is_short_cash' => $metadata['is_short_cash_settlement'] ?? false,
                        'deposit_amount' => $metadata['deposit_amount'] ?? $settlementDeposit->amount,
                        'expense_amount' => $metadata['short_cash_amount'] ?? 0,
                        'expense_category' => $metadata['expense_category'] ?? null,
                        'is_legacy' => false,
                        'account_id' => $settlementDeposit->from_account_id, // For building the link
                    ];
                } elseif ($invoice->settlement_status === 'settled' && $invoice->settled_amount > 0) {
                    // Strategy 2: Legacy settlement (OLD method) - check InvoiceSettlementModel
                    $legacySettlement = \DB::table('t_fin_invoice_settlements')
                        ->where('invoice_ledger_id', $invoice->id)
                        ->first();
                    
                    if ($legacySettlement) {
                        // Found in invoice_settlements table
                        $settlementLedger = LedgerModel::find($legacySettlement->settlement_deposit_id);
                        
                        $settlementDetails = [
                            'deposit_id' => $legacySettlement->settlement_deposit_id,
                            'deposit_date' => $settlementLedger ? $settlementLedger->transaction_date : null,
                            'approval_status' => $settlementLedger ? $settlementLedger->approval_status : 'unknown',
                            'is_short_cash' => false,
                            'deposit_amount' => $legacySettlement->settled_amount,
                            'expense_amount' => 0,
                            'expense_category' => null,
                            'is_legacy' => true,
                            'legacy_note' => 'Settled via legacy method (no metadata)',
                            'account_id' => $settlementLedger ? $settlementLedger->from_account_id : null,
                        ];
                    } else {
                        // Strategy 3: Check if settled_via_ledger_id is set (direct reference)
                        if ($invoice->settled_via_ledger_id) {
                            $settlementLedger = LedgerModel::find($invoice->settled_via_ledger_id);
                            
                            $settlementDetails = [
                                'deposit_id' => $invoice->settled_via_ledger_id,
                                'deposit_date' => $settlementLedger ? $settlementLedger->transaction_date : null,
                                'approval_status' => $settlementLedger ? $settlementLedger->approval_status : 'unknown',
                                'is_short_cash' => false,
                                'deposit_amount' => $invoice->settled_amount,
                                'expense_amount' => 0,
                                'expense_category' => null,
                                'is_legacy' => true,
                                'legacy_note' => 'Settled via direct ledger reference',
                                'account_id' => $settlementLedger ? $settlementLedger->from_account_id : null,
                            ];
                        } else {
                            // No settlement info found - mark as unknown
                            $settlementDetails = [
                                'is_legacy' => true,
                                'legacy_note' => 'Settlement method unknown - marked settled but no deposit found',
                                'deposit_amount' => $invoice->settled_amount,
                                'expense_amount' => 0,
                                'account_id' => null,
                            ];
                        }
                    }
                }
                
                return [
                    'id' => $invoice->id,
                    'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                    'customer_name' => $invoice->order ? $invoice->order->customer_name : 'N/A',
                    'rider_name' => $invoice->toAccount && $invoice->toAccount->user ? $invoice->toAccount->user->fullname : 'N/A',
                    'transaction_date' => $invoice->transaction_date->format('M j, Y'),
                    'payment_method' => $invoice->order ? $invoice->order->payment_method : 'N/A',
                    'amount' => $invoice->amount,
                    'settled_amount' => $invoice->settled_amount ?? 0,
                    'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0),
                    'settlement_status' => $invoice->settlement_status,
                    'approval_status' => $invoice->approval_status,
                    'settlement_details' => $settlementDetails,
                ];
            });
            
            // Calculate summary
            $summary = [
                'total_count' => $invoices->count(),
                'total_amount' => $invoices->sum('amount'),
                'settled_amount' => $invoices->sum('settled_amount'),
                'outstanding_amount' => $invoices->sum('outstanding_amount'),
                'cash_count' => $invoices->where('payment_method', 'cash')->count() + $invoices->where('payment_method', 'Cash')->count() + $invoices->where('payment_method', 'COD')->count(),
                'cash_amount' => $invoices->whereIn('payment_method', ['cash', 'Cash', 'COD', 'cash_on_delivery'])->sum('amount'),
                'online_count' => $invoices->whereIn('payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])->count(),
                'online_amount' => $invoices->whereIn('payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])->sum('amount'),
                'settled_count' => $invoices->where('settlement_status', 'settled')->count(),
                'open_count' => $invoices->whereIn('settlement_status', ['open', 'partial'])->count(),
            ];
            
            return response()->json([
                'success' => true,
                'invoices' => $invoices->values(),
                'summary' => $summary,
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Error getting invoice breakdown: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading invoice breakdown: ' . $e->getMessage()
            ], 500);
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
            // ⭐ Default: group by date (primary), include online by default
            $groupBy = $request->get('group_by', 'date'); // 'date' (default) or 'rider'
            $includeOnline = !$request->has('include_online') || $request->get('include_online') === '1'; // Default true
            
            // Base query for ALL invoices (not just open)
            // IMPORTANT: Exclude reversed transactions (e.g., from payment method changes)
            $invoicesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
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
                return $invoice->settlement_status === 'partial' || 
                       ($invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) > 0);
            });
            
            $settledInvoices = $allInvoices->filter(function($invoice) {
                return $invoice->settlement_status === 'settled';
            });
            
            // Get pending settlement deposits (settlements awaiting approval)
            // Include both "Settlement" and "Partial Payment" descriptions
            $pendingSettlements = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_PENDING)
                ->where(function($q) {
                    $q->where('description', 'LIKE', '%Settlement%')
                      ->orWhere('description', 'LIKE', '%Partial Payment%');
                })
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
                        
                        // Add settlement breakdown for each invoice
                        $invoicesWithBreakdown = $dayInvoices->map(function($invoice) {
                            // Get settlement breakdown (if settled via short cash)
                            $settlementBreakdown = null;
                            $settlementDeposit = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                                ->whereJsonContains('settlement_metadata->invoice_ids', (string)$invoice->id)
                                ->first();
                            
                            if ($settlementDeposit && isset($settlementDeposit->settlement_metadata['is_short_cash_settlement'])) {
                                $metadata = $settlementDeposit->settlement_metadata;
                                $settlementBreakdown = [
                                    'deposit_amount' => $metadata['deposit_amount'] ?? 0,
                                    'expense_amount' => $metadata['short_cash_amount'] ?? 0,
                                    'expense_category' => $metadata['expense_category'] ?? 'Unknown'
                                ];
                            }
                            
                            $invoice->settlement_breakdown = $settlementBreakdown;
                            return $invoice;
                        });
                        
                        return [
                            'invoices' => $invoicesWithBreakdown,
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
                        
                        // Get settlement breakdown (if settled via short cash)
                        $settlementBreakdown = null;
                        if ($invoice->settlement_status === 'settled' && $invoice->settled_at) {
                            // Find the deposit transaction that settled this invoice
                            $settlementDeposit = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                                ->whereJsonContains('settlement_metadata->invoice_ids', (string)$invoice->id)
                                ->first();
                            
                            if ($settlementDeposit && isset($settlementDeposit->settlement_metadata['is_short_cash_settlement'])) {
                                $metadata = $settlementDeposit->settlement_metadata;
                                $settlementBreakdown = [
                                    'deposit_amount' => $metadata['deposit_amount'] ?? 0,
                                    'expense_amount' => $metadata['short_cash_amount'] ?? 0,
                                    'expense_category' => $metadata['expense_category'] ?? 'Unknown'
                                ];
                            }
                        }
                        
                        return [
                            'id' => $invoice->id,
                            'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                            'customer_name' => $invoice->order ? $invoice->order->customer_name : null, // ✅ Added customer name
                            'transaction_date' => $invoice->transaction_date,
                            'is_pending_approval' => $isPendingApproval,
                            'pending_settlement_id' => $pendingSettlementId,
                            'description' => $invoice->description,
                            'amount' => $invoice->amount,
                            'settled_amount' => $invoice->settled_amount ?? 0,
                            'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0),
                            'settlement_status' => $invoice->settlement_status,
                            'settled_at' => $invoice->settled_at,
                            'settlement_breakdown' => $settlementBreakdown
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
            
            // ⭐ Fetch Online Orders (for include_online option)
            $onlineData = null;
            if ($includeOnline && $statusFilter === 'settled') {
                \Log::info('Daily Closing: Fetching online data', ['dateFrom' => $dateFrom, 'dateTo' => $dateTo]);
                $onlineData = $this->fetchOnlineOrdersForDailyClosing($dateFrom, $dateTo);
                \Log::info('Daily Closing: Online data result', [
                    'has_data' => $onlineData !== null,
                    'pending_count' => $onlineData['pending_approval']['count'] ?? 0,
                    'approved_count' => $onlineData['approved']['count'] ?? 0,
                ]);
            }
            
            // ⭐ Group by date (for settled view)
            $invoicesByDate = null;
            if ($groupBy === 'date' && $statusFilter === 'settled') {
                $invoicesByDate = $this->groupSettledInvoicesByDate($settledInvoices, $onlineData);
            }
            
            // ============================================================
            // ⭐ Online WhatsApp Message Tracking (separate from ledger/settlement)
            // Shows today's online delivered orders and whether rider sent payment reminder
            // This does NOT affect any settlement, ledger, or invoice logic
            // ============================================================
            $onlineMessageTracking = $this->fetchOnlineMessageTracking($riderFilter);
            
            return view('fin.employee.outstanding-invoices', [
                'invoicesByRider' => $invoicesByRider,
                'invoicesByDate' => $invoicesByDate, // ⭐ New: Date-level grouping
                'onlineData' => $onlineData, // ⭐ New: Online orders data
                'onlineMessageTracking' => $onlineMessageTracking, // ⭐ WhatsApp message tracking
                'stats' => $stats,
                'pendingSettlements' => $pendingSettlements,
                'allRiders' => $allRiders,
                'filters' => [
                    'status' => $statusFilter,
                    'rider' => $riderFilter,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'group_by' => $groupBy, // ⭐ New
                    'include_online' => $includeOnline // ⭐ New
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Error fetching all outstanding invoices: " . $e->getMessage());
            return back()->with('error', 'Error loading outstanding invoices: ' . $e->getMessage());
        }
    }

    /**
     * ⭐ Fetch Online WhatsApp Message Tracking for Today
     * Returns today's online delivered orders grouped by rider with message sent/pending status
     * This is purely informational - does NOT affect any ledger, settlement, or invoice logic
     */
    private function fetchOnlineMessageTracking($riderFilter = 'all')
    {
        try {
            $onlinePaymentMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment', 'direct_bank_transfer', 'bacs'];
            
            // Find orders delivered today with online payment methods
            $query = \App\Models\CRM\OrderModel::whereIn('order_status', ['delivered', 'completed'])
                ->whereIn('payment_method', $onlinePaymentMethods)
                ->whereExists(function($q) {
                    $q->select(\DB::raw(1))
                      ->from('t_crm_order_status_history as h')
                      ->whereColumn('h.order_id', 't_crm_prod_order.id')
                      ->where('h.status_code', 'delivered')
                      ->whereDate('h.changed_at', today());
                })
                ->with(['customer', 'assignedRider']);
            
            // Apply rider filter if specific rider selected
            if ($riderFilter !== 'all') {
                $riderAccount = AccountModel::find($riderFilter);
                if ($riderAccount && $riderAccount->user_id) {
                    $query->where('assigned_rider_user_id', $riderAccount->user_id);
                }
            }
            
            $orders = $query->orderBy('order_number', 'desc')->get();
            
            if ($orders->isEmpty()) {
                return null;
            }
            
            // Pre-fetch delivery timestamps from status history
            $orderIds = $orders->pluck('id')->all();
            $deliveryHistory = \DB::table('t_crm_order_status_history')
                ->whereIn('order_id', $orderIds)
                ->where('status_code', 'delivered')
                ->get()
                ->keyBy('order_id');
            
            $messageSent = $orders->whereNotNull('online_message_sent_at');
            $messagePending = $orders->whereNull('online_message_sent_at');
            
            // Group by rider
            $byRider = $orders->groupBy('assigned_rider_user_id')->map(function($riderOrders) use ($deliveryHistory) {
                $rider = $riderOrders->first()->assignedRider;
                $riderName = $rider ? $rider->fullname : 'Unknown Rider';
                
                return [
                    'rider_name' => $riderName,
                    'sent_count' => $riderOrders->whereNotNull('online_message_sent_at')->count(),
                    'pending_count' => $riderOrders->whereNull('online_message_sent_at')->count(),
                    'total_amount' => round($riderOrders->sum('total_price')),
                    'message_sent' => $riderOrders->whereNotNull('online_message_sent_at')->map(function($order) {
                        return [
                            'order_number' => $order->order_number,
                            'customer_name' => trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''))
                                ?: ($order->customer ? trim($order->customer->first_name . ' ' . $order->customer->last_name) : 'N/A'),
                            'amount' => round($order->total_price),
                            'sent_at' => $order->online_message_sent_at ? $order->online_message_sent_at->format('h:i A') : null,
                        ];
                    })->values(),
                    'message_pending' => $riderOrders->whereNull('online_message_sent_at')->map(function($order) use ($deliveryHistory) {
                        $customerName = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''))
                            ?: ($order->customer ? trim($order->customer->first_name . ' ' . $order->customer->last_name) : 'N/A');
                        $customerPhone = $order->address_phone ?: ($order->customer ? ($order->customer->phone_original ?? $order->customer->phone ?? '') : '');
                        $riderUser = $order->assignedRider;
                        $riderFullName = $riderUser ? $riderUser->fullname : 'your rider';
                        
                        $deliveryRecord = $deliveryHistory->get($order->id);
                        $deliveryDate = $deliveryRecord ? \Carbon\Carbon::parse($deliveryRecord->changed_at)->format('M d, Y') : ($order->delivery_date ?? now()->format('M d, Y'));
                        $deliveryTime = $deliveryRecord ? \Carbon\Carbon::parse($deliveryRecord->changed_at)->format('h:i A') : '';
                        
                        return [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                            'customer_name' => $customerName,
                            'customer_phone' => $customerPhone,
                            'rider_name' => $riderFullName,
                            'delivery_date' => $deliveryDate,
                            'delivery_time' => $deliveryTime,
                            'amount' => round($order->total_price),
                        ];
                    })->values(),
                ];
            })->values();
            
            return [
                'total_count' => $orders->count(),
                'sent_count' => $messageSent->count(),
                'pending_count' => $messagePending->count(),
                'sent_amount' => round($messageSent->sum('total_price')),
                'pending_amount' => round($messagePending->sum('total_price')),
                'by_rider' => $byRider,
            ];
            
        } catch (\Exception $e) {
            \Log::error('fetchOnlineMessageTracking error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark that the WhatsApp delivery confirmation message was sent for an online order
     * Called from the daily closing page by the manager
     */
    public function markOnlineMessageSentWeb(Request $request, $orderId)
    {
        try {
            $order = \App\Models\CRM\OrderModel::find($orderId);

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            if (!in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json(['success' => false, 'message' => 'Order must be delivered first'], 400);
            }

            $paymentMethod = strtolower($order->payment_method ?? 'cash');
            if (in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod'])) {
                return response()->json(['success' => false, 'message' => 'This is not an online payment order'], 400);
            }

            $order->online_message_sent_at = now();
            $order->online_message_sent_by = \Auth::id();
            $order->save();

            \Log::info('Online message marked as sent from daily closing', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'marked_by' => \Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Message status updated',
                'sent_at' => $order->online_message_sent_at->format('h:i A'),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to mark online message sent from web', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * ⭐ Fetch Online Orders for Daily Closing View
     * Returns online orders that were delivered, grouped by approval status
     * Uses the same logic as Employee Cash page for consistency
     */
    private function fetchOnlineOrdersForDailyClosing($dateFrom, $dateTo)
    {
        try {
            // Get ONLINE account for ledger entries (same as Employee Cash page)
            $onlineAccount = AccountModel::where('account_code', 'ONLINE')->first();
            if (!$onlineAccount) {
                \Log::warning('fetchOnlineOrdersForDailyClosing: ONLINE account not found');
                return null;
            }
            
            // Query online ledger transactions (invoices) - SAME LOGIC AS EMPLOYEE CASH PAGE
            $onlineQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('to_account_id', $onlineAccount->id)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->with(['order.customer']);
            
            // Apply date filters using transaction_date (same as Employee Cash)
            if ($dateFrom) {
                $onlineQuery->where('transaction_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $onlineQuery->where('transaction_date', '<=', $dateTo);
            }
            
            $onlineTransactions = $onlineQuery->orderBy('transaction_date', 'desc')->get();
            
            \Log::info('fetchOnlineOrdersForDailyClosing: Found ' . $onlineTransactions->count() . ' online transactions');
            
            // Separate by approval status
            $pendingApproval = $onlineTransactions->filter(function($txn) {
                return in_array($txn->approval_status, [
                    LedgerModel::STATUS_PENDING,
                    LedgerModel::STATUS_PENDING_L1,
                    LedgerModel::STATUS_PENDING_L2
                ]);
            });
            
            $approved = $onlineTransactions->filter(function($txn) {
                return $txn->approval_status === LedgerModel::STATUS_APPROVED;
            });
            
            // Helper function to format transaction
            $formatTxn = function($txn) {
                return [
                    'id' => $txn->id,
                    'order_number' => $txn->order ? $txn->order->order_number : 'N/A',
                    'customer_name' => $txn->order && $txn->order->customer ? 
                        ($txn->order->customer->full_name ?: $txn->order->customer->company ?: 'Unknown') : 'Unknown',
                    'transaction_date' => $txn->transaction_date,
                    'amount' => $txn->amount,
                    'description' => $txn->description,
                    'approval_status' => $txn->approval_status,
                    'approved_at' => $txn->approved_at
                ];
            };
            
            // Group ALL online transactions by date (both approved and pending)
            // This gives a complete picture of online orders for each day
            $allByDate = $onlineTransactions->groupBy(function($txn) {
                return $txn->transaction_date ? \Carbon\Carbon::parse($txn->transaction_date)->format('Y-m-d') : 'Unknown';
            })->map(function($dayTxns, $date) use ($formatTxn) {
                $approvedTxns = $dayTxns->filter(fn($t) => $t->approval_status === LedgerModel::STATUS_APPROVED);
                $pendingTxns = $dayTxns->filter(fn($t) => in_array($t->approval_status, [
                    LedgerModel::STATUS_PENDING,
                    LedgerModel::STATUS_PENDING_L1,
                    LedgerModel::STATUS_PENDING_L2
                ]));
                
                return [
                    'date' => $date,
                    'transactions' => $dayTxns->map($formatTxn),
                    'approved_transactions' => $approvedTxns->map($formatTxn),
                    'pending_transactions' => $pendingTxns->map($formatTxn),
                    'total_amount' => $dayTxns->sum('amount'),
                    'approved_amount' => $approvedTxns->sum('amount'),
                    'pending_amount' => $pendingTxns->sum('amount'),
                    'count' => $dayTxns->count(),
                    'approved_count' => $approvedTxns->count(),
                    'pending_count' => $pendingTxns->count()
                ];
            });
            
            // Also keep the approved-only grouping for backward compatibility
            $approvedByDate = $approved->groupBy(function($txn) {
                return $txn->transaction_date ? \Carbon\Carbon::parse($txn->transaction_date)->format('Y-m-d') : 'Unknown';
            })->map(function($dayTxns, $date) use ($formatTxn) {
                return [
                    'date' => $date,
                    'transactions' => $dayTxns->map($formatTxn),
                    'total_amount' => $dayTxns->sum('amount'),
                    'count' => $dayTxns->count()
                ];
            });
            
            return [
                'pending_approval' => [
                    'count' => $pendingApproval->count(),
                    'amount' => $pendingApproval->sum('amount'),
                    'transactions' => $pendingApproval->map($formatTxn)
                ],
                'approved' => [
                    'count' => $approved->count(),
                    'amount' => $approved->sum('amount'),
                    'by_date' => $approvedByDate
                ],
                'all_by_date' => $allByDate, // ⭐ All transactions grouped by date (approved + pending)
                'total' => [
                    'count' => $onlineTransactions->count(),
                    'amount' => $onlineTransactions->sum('amount')
                ]
            ];
        } catch (\Exception $e) {
            \Log::error("Error fetching online orders: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * ⭐ Group settled invoices by date (instead of by rider)
     */
    private function groupSettledInvoicesByDate($settledInvoices, $onlineData = null)
    {
        // Group by settlement date
        $byDate = $settledInvoices->groupBy(function($invoice) {
            return $invoice->settled_at ? $invoice->settled_at->format('Y-m-d') : 'Unknown';
        })->map(function($dayInvoices, $date) {
            // Group by rider within each date
            $byRider = $dayInvoices->groupBy('to_account_id')->map(function($riderInvoices) {
                $account = $riderInvoices->first()->toAccount;
                return [
                    'rider_id' => $account->id,
                    'rider_name' => $account->account_name,
                    'invoices' => $riderInvoices->map(function($invoice) {
                        return [
                            'id' => $invoice->id,
                            'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                            'customer_name' => $invoice->order ? $invoice->order->customer_name : null,
                            'transaction_date' => $invoice->transaction_date,
                            'amount' => $invoice->amount,
                            'settled_amount' => $invoice->settled_amount ?? 0,
                            'description' => $invoice->description,
                            'settled_at' => $invoice->settled_at
                        ];
                    }),
                    'total_amount' => $riderInvoices->sum('amount'),
                    'count' => $riderInvoices->count()
                ];
            });
            
            return [
                'date' => $date,
                'riders' => $byRider,
                'total_amount' => $dayInvoices->sum('amount'),
                'total_count' => $dayInvoices->count(),
                'rider_count' => $byRider->count()
            ];
        })->sortKeysDesc(); // Most recent first
        
        // Convert to array for modification (Collections don't allow direct element modification)
        // Also convert nested 'riders' collections to arrays
        $byDateArray = $byDate->map(function($dayData) {
            $dayArray = is_array($dayData) ? $dayData : $dayData;
            if (isset($dayArray['riders']) && $dayArray['riders'] instanceof \Illuminate\Support\Collection) {
                $dayArray['riders'] = $dayArray['riders']->toArray();
            }
            return $dayArray;
        })->toArray();
        
        // Merge with online data if available (use all_by_date for complete picture)
        if ($onlineData && isset($onlineData['all_by_date'])) {
            foreach ($onlineData['all_by_date'] as $date => $onlineDay) {
                // Convert Collection to array if needed
                $onlineDayArray = $onlineDay instanceof \Illuminate\Support\Collection ? $onlineDay->toArray() : $onlineDay;
                
                if (isset($byDateArray[$date])) {
                    $byDateArray[$date]['online'] = $onlineDayArray;
                    $byDateArray[$date]['total_amount'] += $onlineDayArray['total_amount'];
                    $byDateArray[$date]['total_count'] += $onlineDayArray['count'];
                } else {
                    $byDateArray[$date] = [
                        'date' => $date,
                        'riders' => collect(),
                        'online' => $onlineDayArray,
                        'total_amount' => $onlineDayArray['total_amount'],
                        'total_count' => $onlineDayArray['count'],
                        'rider_count' => 0
                    ];
                }
            }
        }
        
        // Sort by date descending and convert back to collection
        krsort($byDateArray);
        
        return collect($byDateArray);
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
            $fromAccountId = $request->from_account_id;
            $description = $request->description;
            
            // If no internal account specified, use Opening Equity for external receipts
            if (!$fromAccountId) {
                $openingEquityAccount = ConfigModel::getOpeningEquityAccount();
                if (!$openingEquityAccount) {
                    throw new \Exception("Opening Equity account not found. Please configure it in system settings.");
                }
                $fromAccountId = $openingEquityAccount->id;
                
                if ($request->from_external) {
                    $description = "Receipt from: {$request->from_external} - {$description}";
                }
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
            $toAccountId = $request->to_account_id;
            $description = $request->description;
            
            // If no internal account specified, use Opening Equity for external payments
            if (!$toAccountId) {
                $openingEquityAccount = ConfigModel::getOpeningEquityAccount();
                if (!$openingEquityAccount) {
                    throw new \Exception("Opening Equity account not found. Please configure it in system settings.");
                }
                $toAccountId = $openingEquityAccount->id;
                
                if ($request->to_external) {
                    $description = "Payment to: {$request->to_external} - {$description}";
                }
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

