<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\BusinessUnitModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountController extends Controller
{
    /**
     * Display list of accounts grouped by type
     */
    public function index()
    {
        // Get all accounts grouped by account_type — hide private for non-Taimur
        $accountQuery = AccountModel::visibleTo(auth()->user())
                                ->orderBy('account_type')
                                ->orderBy('account_name');
        $accounts = $accountQuery->get()->groupBy('account_type');

        // Calculate totals by type (respecting visibility)
        $visibleBase = AccountModel::visibleTo(auth()->user());
        $summary = [
            'total_accounts' => (clone $visibleBase)->count(),
            'active_accounts' => (clone $visibleBase)->where('is_active', 1)->count(),
            'total_assets' => (clone $visibleBase)->where('account_type', 'asset')->sum('current_balance'),
            'total_liabilities' => (clone $visibleBase)->where('account_type', 'liability')->sum('current_balance'),
            'total_expenses' => (clone $visibleBase)->where('account_type', 'expense')->sum('current_balance'),
        ];

        return view('fin.account.index', compact('accounts', 'summary'));
    }

    /**
     * Show create account form
     */
    public function create()
    {
        // Define account types with categories
        $accountTypes = [
            'asset' => [
                'label' => 'Asset',
                'categories' => ['cash', 'bank', 'employee_cash', 'receivables', 'prepayments', 'fixed_asset']
            ],
            'liability' => [
                'label' => 'Liability',
                'categories' => ['vendor_payable', 'employee_payable', 'accrued_expenses', 'loans']
            ],
            'revenue' => [
                'label' => 'Revenue',
                'categories' => ['sales', 'other_income', 'interest_income']
            ],
            'expense' => [
                'label' => 'Expense',
                'categories' => ['expense', 'cost_of_goods', 'overhead', 'depreciation']
            ],
            'equity' => [
                'label' => 'Equity',
                'categories' => ['capital', 'retained_earnings', 'drawings']
            ]
        ];

        // Get business units for dropdown
        $businessUnits = BusinessUnitModel::where('is_active', 1)->ordered()->get();

        return view('fin.account.create', compact('accountTypes', 'businessUnits'));
    }

    /**
     * Store new account
     */
    public function store(Request $request)
    {
        $request->validate([
            'account_name' => 'required|string|max:255',
            'account_code' => 'required|string|max:50|unique:t_fin_accounts,account_code',
            'account_type' => 'required|in:asset,liability,revenue,expense,equity',
            'account_category' => 'required|string|max:100',
            'opening_balance' => 'nullable|numeric',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'business_unit_id' => 'nullable|exists:t_fin_business_units,id'
        ]);

        try {
            $account = AccountModel::create([
                'account_name' => $request->account_name,
                'account_code' => strtoupper($request->account_code),
                'account_type' => $request->account_type,
                'account_category' => $request->account_category,
                'opening_balance' => $request->opening_balance ?? 0,
                'current_balance' => $request->opening_balance ?? 0,
                'description' => $request->description,
                'is_active' => $request->is_active ?? 1,
                'business_unit_id' => $request->business_unit_id ?? 1, // Default to Nizami Farms (id=1)
                'created_by' => auth()->id()
            ]);

            return redirect()->route('fin.accounts.index')
                           ->with('success', "Account '{$account->account_name}' created successfully!");

        } catch (\Exception $e) {
            Log::error('Error creating account: ' . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error creating account: ' . $e->getMessage());
        }
    }

    /**
     * Show account details and ledger
     */
    public function show($id)
    {
        $account = AccountModel::with(['user'])->findOrFail($id);

        // Get ledger transactions for this account
        $ledger = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'approvedBy'])
            ->where(function($query) use ($id) {
                $query->where('from_account_id', $id)
                      ->orWhere('to_account_id', $id);
            })
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50);

        // Calculate running balance
        $runningBalance = $account->opening_balance;
        $ledger->getCollection()->transform(function($transaction) use (&$runningBalance, $account) {
            // Determine if this account is debited or credited
            if ($transaction->from_account_id == $account->id) {
                // Money going out
                $transaction->debit = 0;
                $transaction->credit = $transaction->amount;
                
                if (in_array($account->account_type, ['asset', 'expense'])) {
                    $runningBalance -= $transaction->amount;
                } else {
                    $runningBalance += $transaction->amount;
                }
            } else {
                // Money coming in
                $transaction->debit = $transaction->amount;
                $transaction->credit = 0;
                
                if (in_array($account->account_type, ['asset', 'expense'])) {
                    $runningBalance += $transaction->amount;
                } else {
                    $runningBalance -= $transaction->amount;
                }
            }
            
            $transaction->running_balance = $runningBalance;
            return $transaction;
        });

        // Summary stats
        $summary = [
            'opening_balance' => $account->opening_balance,
            'current_balance' => $account->current_balance,
            'total_debits' => LedgerModel::where('to_account_id', $account->id)->sum('amount'),
            'total_credits' => LedgerModel::where('from_account_id', $account->id)->sum('amount'),
            'transaction_count' => LedgerModel::byAccount($account->id)->count()
        ];

        return view('fin.account.show', compact('account', 'ledger', 'summary'));
    }

    /**
     * Show edit form
     */
    public function edit($id)
    {
        $account = AccountModel::findOrFail($id);

        // Define account types with categories
        $accountTypes = [
            'asset' => [
                'label' => 'Asset',
                'categories' => ['cash', 'bank', 'employee_cash', 'receivables', 'prepayments', 'fixed_asset']
            ],
            'liability' => [
                'label' => 'Liability',
                'categories' => ['vendor_payable', 'employee_payable', 'accrued_expenses', 'loans']
            ],
            'revenue' => [
                'label' => 'Revenue',
                'categories' => ['sales', 'other_income', 'interest_income']
            ],
            'expense' => [
                'label' => 'Expense',
                'categories' => ['expense', 'cost_of_goods', 'overhead', 'depreciation']
            ],
            'equity' => [
                'label' => 'Equity',
                'categories' => ['capital', 'retained_earnings', 'drawings']
            ]
        ];

        // Get business units for dropdown
        $businessUnits = BusinessUnitModel::where('is_active', 1)->ordered()->get();

        // Check if current user is Taimur (for private account toggle)
        $isTaimurRole = false;
        if (auth()->check()) {
            $isTaimurRole = \DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', auth()->id())
                ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
                ->exists();
        }

        return view('fin.account.edit', compact('account', 'accountTypes', 'businessUnits', 'isTaimurRole'));
    }

    /**
     * Update account
     */
    public function update(Request $request, $id)
    {
        $account = AccountModel::findOrFail($id);

        $request->validate([
            'account_name' => 'required|string|max:255',
            'account_code' => 'required|string|max:50|unique:t_fin_accounts,account_code,' . $id,
            'account_category' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'is_private' => 'nullable|boolean',
            'business_unit_id' => 'nullable|exists:t_fin_business_units,id'
        ]);

        try {
            $updateData = [
                'account_name' => $request->account_name,
                'account_code' => strtoupper($request->account_code),
                'account_category' => $request->account_category,
                'description' => $request->description,
                'is_active' => $request->is_active ?? $account->is_active,
                'business_unit_id' => $request->business_unit_id ?? $account->business_unit_id ?? 1,
                'updated_by' => auth()->id()
            ];

            // Only Taimur can toggle is_private
            $isTaimurRole = \DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', auth()->id())
                ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
                ->exists();
            if ($isTaimurRole) {
                $updateData['is_private'] = $request->has('is_private') ? 1 : 0;
            }

            $account->update($updateData);

            return redirect()->route('fin.accounts.show', $account->id)
                           ->with('success', 'Account updated successfully!');

        } catch (\Exception $e) {
            Log::error('Error updating account: ' . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error updating account: ' . $e->getMessage());
        }
    }

    /**
     * Toggle account active status
     */
    public function toggleStatus($id)
    {
        try {
            $account = AccountModel::findOrFail($id);
            
            $account->is_active = !$account->is_active;
            $account->updated_by = auth()->id();
            $account->save();

            $status = $account->is_active ? 'activated' : 'deactivated';
            
            return redirect()->back()
                           ->with('success', "Account '{$account->account_name}' {$status} successfully!");

        } catch (\Exception $e) {
            Log::error('Error toggling account status: ' . $e->getMessage());
            
            return back()->with('error', 'Error updating account status: ' . $e->getMessage());
        }
    }

    /**
     * Adjust account balance (manual adjustment)
     */
    public function adjustBalance(Request $request, $id)
    {
        $request->validate([
            'adjustment_amount' => 'required|numeric|not_in:0',
            'adjustment_type' => 'required|in:increase,decrease',
            'reason' => 'required|string|max:500',
            'transaction_date' => 'required|date'
        ]);

        try {
            DB::beginTransaction();

            $account = AccountModel::findOrFail($id);
            $equityAccount = AccountModel::getByCode('EQUITY_OPENING');

            if (!$equityAccount) {
                throw new \Exception("Equity account not found for adjustment");
            }

            $amount = abs($request->adjustment_amount);

            // Determine from/to based on adjustment type
            if ($request->adjustment_type === 'increase') {
                $fromAccountId = $equityAccount->id;
                $toAccountId = $account->id;
                $description = "Balance increase adjustment: {$request->reason}";
            } else {
                $fromAccountId = $account->id;
                $toAccountId = $equityAccount->id;
                $description = "Balance decrease adjustment: {$request->reason}";
            }

            // Create ledger entry
            LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_ADJUSTMENT,
                'description' => $description,
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'amount' => $amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'created_by' => auth()->id(),
                'comments' => $request->reason
            ]);

            // Update balances
            if ($request->adjustment_type === 'increase') {
                $account->current_balance += $amount;
            } else {
                $account->current_balance -= $amount;
            }
            $account->save();

            // Update equity account (opposite direction)
            if ($request->adjustment_type === 'increase') {
                $equityAccount->current_balance -= $amount;
            } else {
                $equityAccount->current_balance += $amount;
            }
            $equityAccount->save();

            DB::commit();

            return redirect()->route('fin.accounts.show', $account->id)
                           ->with('success', 'Balance adjustment recorded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error adjusting balance: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error adjusting balance: ' . $e->getMessage());
        }
    }

    /**
     * Get payment source accounts for vendor payments (API endpoint for mobile)
     * Now uses Business Unit filtering based on user's role permissions
     */
    public function getPaymentSources()
    {
        try {
            // Use the new BU-filtered method to get company accounts (cash, bank)
            // This respects user's business unit access permissions
            $accounts = AccountModel::getAccessibleCompanyAccounts(['cash', 'bank']);
            
            // Transform to include only necessary fields
            $accounts = $accounts->map(function($account) {
                return [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'account_code' => $account->account_code,
                    'current_balance' => $account->current_balance,
                    'business_unit_id' => $account->business_unit_id
                ];
            });

            return response()->json([
                'success' => true,
                'accounts' => $accounts
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching payment sources: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching payment sources'
            ], 500);
        }
    }
}

