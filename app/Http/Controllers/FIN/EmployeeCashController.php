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

        // Calculate totals
        $totalCash = AccountModel::employeeCash()->sum('current_balance');

        return view('fin.employee.index', compact('employees', 'totalCash'));
    }

    /**
     * Show employee cash details
     */
    public function show($id)
    {
        $account = AccountModel::with('user')->findOrFail($id);

        if ($account->account_category !== AccountModel::CATEGORY_EMPLOYEE_CASH) {
            abort(404, 'Not an employee cash account');
        }

        // Get ledger transactions
        $ledger = LedgerModel::where(function($q) use ($id) {
            $q->where('from_account_id', $id)
              ->orWhere('to_account_id', $id);
        })
        ->with(['fromAccount', 'toAccount'])
        ->orderBy('transaction_date', 'desc')
        ->orderBy('created_at', 'desc')
        ->paginate(50);

        // Calculate running balance (from oldest to newest for calculation)
        $allTransactions = LedgerModel::where(function($q) use ($id) {
            $q->where('from_account_id', $id)
              ->orWhere('to_account_id', $id);
        })
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

        // Summary
        $summary = [
            'opening_balance' => $account->opening_balance,
            'current_balance' => $account->current_balance,
            'total_invoices' => LedgerModel::where('to_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->sum('amount'),
            'total_expenses' => LedgerModel::where('from_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_EXPENSE)
                ->sum('amount'),
            'total_deposits' => LedgerModel::where('from_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->sum('amount')
        ];

        return view('fin.employee.show', compact('account', 'ledger', 'summary'));
    }

    /**
     * Record employee deposit
     */
    public function recordDeposit(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
            'short_over' => 'nullable|numeric'
        ]);

        try {
            DB::beginTransaction();

            $employeeAccount = AccountModel::findOrFail($id);
            $nfCash = ConfigModel::getNFCashAccount();

            if (!$nfCash) {
                throw new \Exception("NF Cash account not found");
            }

            // Check if amount exceeds employee balance
            if ($request->amount > $employeeAccount->current_balance) {
                throw new \Exception("Deposit amount cannot exceed employee cash balance");
            }

            // Main deposit transaction
            LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
                'description' => $request->description ?? "Deposit from {$employeeAccount->account_name}",
                'from_account_id' => $employeeAccount->id,
                'to_account_id' => $nfCash->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'created_by' => auth()->id()
            ]);

            // Update balances
            $employeeAccount->current_balance -= $request->amount;
            $employeeAccount->save();
            
            $nfCash->current_balance += $request->amount;
            $nfCash->save();

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

            return redirect()->route('fin.employee.show', $employeeAccount->id)
                           ->with('success', 'Deposit recorded successfully!');

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
}

