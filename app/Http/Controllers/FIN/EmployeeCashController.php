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
     * Display employee cash list
     */
    public function index(Request $request)
    {
        $query = AccountModel::employeeCash()->with('user');

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

        $employees = $query->orderBy('account_name', 'asc')->paginate(20);

        // Calculate pending expenses for each employee
        foreach ($employees as $employee) {
            if ($employee->user_id) {
                $pendingExpenses = \App\Models\Request\RequestModel::where('requester_user_id', $employee->user_id)
                    ->where('status', 'pending')
                    ->whereHas('category', function($q) {
                        $q->where('category_code', 'expense');
                    })
                    ->sum('amount');
                $employee->pending_expenses = $pendingExpenses ?? 0;
            } else {
                $employee->pending_expenses = 0;
            }
        }

        // Calculate totals
        $totalCash = AccountModel::employeeCash()->sum('current_balance');

        return view('fin.employee.index', compact('employees', 'totalCash'));
    }

    /**
     * Show employee cash details
     */
    public function show(Request $request, $id)
    {
        $account = AccountModel::with('user')->findOrFail($id);

        if ($account->account_category !== AccountModel::CATEGORY_EMPLOYEE_CASH) {
            abort(404, 'Not an employee cash account');
        }

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
        $invoicesQuery = LedgerModel::where('to_account_id', $account->id)
            ->where('transaction_type', LedgerModel::TYPE_INVOICE);
        $expensesQuery = LedgerModel::where('from_account_id', $account->id)
            ->where('transaction_type', LedgerModel::TYPE_EXPENSE);
        $depositsQuery = LedgerModel::where('from_account_id', $account->id)
            ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT);

        // Apply date filters to summary calculations
        if ($dateFrom && $dateTo) {
            $invoicesQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            $expensesQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            $depositsQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        }

        $summary = [
            'opening_balance' => $account->opening_balance,
            'current_balance' => $account->current_balance,
            'total_invoices' => $invoicesQuery->sum('amount'),
            'total_expenses' => $expensesQuery->sum('amount'),
            'total_deposits' => $depositsQuery->sum('amount')
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

        return view('fin.employee.show', compact('account', 'ledger', 'summary', 'userRole', 'expenseRequests', 'expenseSummary', 'expenseCategories', 'dateFrom', 'dateTo'));
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
            'description' => 'nullable|string|max:1000'
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
}

