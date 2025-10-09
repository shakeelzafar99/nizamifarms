<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\AccountModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerController extends Controller
{
    /**
     * Display overall ledger (all transactions)
     */
    public function index(Request $request)
    {
        $query = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy']);

        // Filter by date range
        if ($request->has('start_date') && $request->start_date) {
            $query->where('transaction_date', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        // Filter by transaction type
        if ($request->has('type') && $request->type) {
            $query->where('transaction_type', $request->type);
        }

        // Filter by mode
        if ($request->has('mode') && $request->mode) {
            $query->where('mode', $request->mode);
        }

        // Filter by approval status
        if ($request->has('status') && $request->status) {
            $query->where('approval_status', $request->status);
        }

        // Filter by account (from or to)
        if ($request->has('account_id') && $request->account_id) {
            $accountId = $request->account_id;
            $query->where(function($q) use ($accountId) {
                $q->where('from_account_id', $accountId)
                  ->orWhere('to_account_id', $accountId);
            });
        }

        // Search by description
        if ($request->has('search') && $request->search) {
            $query->where('description', 'LIKE', "%{$request->search}%");
        }

        // Order by date (newest first)
        $ledger = $query->orderBy('transaction_date', 'desc')
                       ->orderBy('created_at', 'desc')
                       ->paginate(50);

        // Get all accounts for filters
        $accounts = AccountModel::where('is_active', 1)
                                ->orderBy('account_name', 'asc')
                                ->get();

        // Get transaction types
        $transactionTypes = [
            LedgerModel::TYPE_INVOICE => 'Invoice',
            LedgerModel::TYPE_EMPLOYEE_DEPOSIT => 'Employee Deposit',
            LedgerModel::TYPE_EXPENSE => 'Expense',
            LedgerModel::TYPE_VENDOR_PURCHASE => 'Vendor Purchase',
            LedgerModel::TYPE_VENDOR_PAYMENT => 'Vendor Payment',
            LedgerModel::TYPE_TRANSFER => 'Account Transfer',
            LedgerModel::TYPE_ADJUSTMENT => 'Adjustment',
            LedgerModel::TYPE_OPENING_BALANCE => 'Opening Balance',
        ];

        // Calculate pending summary (for ALL pending, not filtered)
        $pendingSummary = [
            'total_count' => LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)->count(),
            'total_amount' => LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)->sum('amount'),
            'by_type' => LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)
                                    ->select('transaction_type', 
                                             DB::raw('COUNT(*) as count'),
                                             DB::raw('SUM(amount) as amount'))
                                    ->groupBy('transaction_type')
                                    ->get()
                                    ->keyBy('transaction_type')
        ];

        return view('fin.ledger.index', compact('ledger', 'accounts', 'transactionTypes', 'pendingSummary'));
    }

    /**
     * Show transfer form
     */
    public function createTransfer()
    {
        // Get all active accounts
        $accounts = AccountModel::where('is_active', 1)
                                ->orderBy('account_name', 'asc')
                                ->get()
                                ->groupBy('account_type');

        return view('fin.ledger.transfer', compact('accounts'));
    }

    /**
     * Process account transfer
     */
    public function storeTransfer(Request $request)
    {
        $request->validate([
            'from_account_id' => 'required|exists:t_fin_accounts,id',
            'to_account_id' => 'required|exists:t_fin_accounts,id|different:from_account_id',
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:500',
            'mode' => 'required|in:cash,online'
        ]);

        try {
            DB::beginTransaction();

            $fromAccount = AccountModel::findOrFail($request->from_account_id);
            $toAccount = AccountModel::findOrFail($request->to_account_id);

            // Check if from account has sufficient balance for asset accounts
            if (in_array($fromAccount->account_type, ['ASSET', 'CASH_EMPLOYEE'])) {
                if ($fromAccount->current_balance < $request->amount) {
                    throw new \Exception("Insufficient balance in {$fromAccount->account_name}. Current balance: Rs. " . number_format($fromAccount->current_balance, 2));
                }
            }

            // Determine approval status
            // Online transfers require approval
            $approvalStatus = $request->mode === 'online' 
                ? LedgerModel::STATUS_PENDING 
                : LedgerModel::STATUS_APPROVED;

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_TRANSFER,
                'description' => $request->description,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $request->amount,
                'mode' => $request->mode,
                'approval_status' => $approvalStatus,
                'created_by' => auth()->id()
            ]);

            // Update balances (only if approved or cash)
            if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
                // From account: debit or credit based on account type
                if (in_array($fromAccount->account_type, ['ASSET', 'CASH_EMPLOYEE'])) {
                    $fromAccount->current_balance -= $request->amount;
                } else {
                    // Liability, income, expense accounts
                    $fromAccount->current_balance += $request->amount;
                }
                $fromAccount->save();

                // To account: opposite
                if (in_array($toAccount->account_type, ['ASSET', 'CASH_EMPLOYEE'])) {
                    $toAccount->current_balance += $request->amount;
                } else {
                    $toAccount->current_balance -= $request->amount;
                }
                $toAccount->save();
            }

            DB::commit();

            $message = $approvalStatus === LedgerModel::STATUS_PENDING
                ? 'Transfer created and pending approval!'
                : 'Transfer completed successfully!';

            return redirect()->route('fin.ledger.index')
                           ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing transfer: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error processing transfer: ' . $e->getMessage());
        }
    }

    /**
     * Show transaction details
     */
    public function show($id)
    {
        $transaction = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'order', 'request'])
                                  ->findOrFail($id);

        return view('fin.ledger.show', compact('transaction'));
    }

    /**
     * Approve pending transaction
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'approval_notes' => 'nullable|string|max:500',
            'override_destination_account_id' => 'nullable|exists:t_fin_accounts,id',
            'override_source_account_id' => 'nullable|exists:t_fin_accounts,id'
        ]);

        try {
            DB::beginTransaction();

            $ledger = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);

            if ($ledger->approval_status !== LedgerModel::STATUS_PENDING) {
                throw new \Exception("Transaction is not pending approval");
            }

            // Handle account overrides if provided
            $originalFrom = $ledger->from_account_id;
            $originalTo = $ledger->to_account_id;
            
            if ($request->override_source_account_id) {
                $ledger->from_account_id = $request->override_source_account_id;
                $ledger->comments = ($ledger->comments ?? '') . " | Source changed from Account ID {$originalFrom} to {$request->override_source_account_id}";
            }
            
            if ($request->override_destination_account_id) {
                $ledger->to_account_id = $request->override_destination_account_id;
                $ledger->comments = ($ledger->comments ?? '') . " | Destination changed from Account ID {$originalTo} to {$request->override_destination_account_id}";
            }

            // Update approval status
            $ledger->approval_status = LedgerModel::STATUS_APPROVED;
            $ledger->approved_by = auth()->id();
            $ledger->approved_at = now();
            $ledger->approval_notes = $request->approval_notes;
            $ledger->save();

            // Reload accounts (in case they were changed)
            $ledger->load(['fromAccount', 'toAccount']);
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;

            // From account adjustment
            if (in_array($fromAccount->account_type, ['ASSET', 'CASH_EMPLOYEE'])) {
                $fromAccount->current_balance -= $ledger->amount;
            } else {
                $fromAccount->current_balance += $ledger->amount;
            }
            $fromAccount->save();

            // To account adjustment
            if (in_array($toAccount->account_type, ['ASSET', 'CASH_EMPLOYEE'])) {
                $toAccount->current_balance += $ledger->amount;
            } else {
                $toAccount->current_balance -= $ledger->amount;
            }
            $toAccount->save();

            DB::commit();

            return redirect()->route('fin.ledger.index')
                           ->with('success', 'Transaction approved successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error approving transaction: " . $e->getMessage());
            
            return back()->with('error', 'Error approving transaction: ' . $e->getMessage());
        }
    }

    /**
     * Reject pending transaction
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500'
        ]);

        try {
            $ledger = LedgerModel::findOrFail($id);

            if ($ledger->approval_status !== LedgerModel::STATUS_PENDING) {
                throw new \Exception("Transaction is not pending approval");
            }

            $ledger->approval_status = LedgerModel::STATUS_REJECTED;
            $ledger->approved_by = auth()->id();
            $ledger->approved_at = now();
            $ledger->approval_notes = $request->rejection_reason;
            $ledger->save();

            return redirect()->route('fin.ledger.index')
                           ->with('success', 'Transaction rejected successfully!');

        } catch (\Exception $e) {
            Log::error("Error rejecting transaction: " . $e->getMessage());
            
            return back()->with('error', 'Error rejecting transaction: ' . $e->getMessage());
        }
    }
}

