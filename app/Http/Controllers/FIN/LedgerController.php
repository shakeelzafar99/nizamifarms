<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\InvoiceSettlementModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerController extends Controller
{
    /**
     * Display overall ledger (all transactions)
     */
    public function index(Request $request)
    {
        // Get date range for filters (default to current month)
        $startDate = $request->start_date ?? now()->startOfMonth()->format('Y-m-d');
        $endDate = $request->end_date ?? now()->endOfMonth()->format('Y-m-d');
        
        $query = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy']);

        // Filter by date range
        if ($startDate) {
            $query->where('transaction_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('transaction_date', '<=', $endDate);
        }

        // Filter by transaction type
        if ($request->has('type') && $request->type) {
            $query->where('transaction_type', $request->type);
        }
        
        // Special filter for vendor transactions (purchases + payments)
        if ($request->has('vendor_filter') && $request->vendor_filter) {
            $query->whereIn('transaction_type', [
                LedgerModel::TYPE_VENDOR_PURCHASE,
                LedgerModel::TYPE_VENDOR_PAYMENT
            ]);
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

        // Calculate KPI summary (respecting date filters)
        $summaryKPIs = $this->calculateKPIs($startDate, $endDate);

        return view('fin.ledger.index', compact('ledger', 'accounts', 'transactionTypes', 'pendingSummary', 'summaryKPIs', 'startDate', 'endDate'));
    }
    
    /**
     * Calculate KPI summary data (reuses logic from EmployeeCashController for consistency)
     */
    private function calculateKPIs($startDate, $endDate)
    {
        // Get delivered order IDs for the period (using status history table)
        $invoicesQuery = \DB::table('t_crm_order_status_history')
            ->where('status_code', 'delivered')
            ->where('is_current', 1);
        
        if ($startDate && $endDate) {
            $invoicesQuery->whereBetween('changed_at', [$startDate, $endDate]);
        }
        
        $deliveredOrderIds = $invoicesQuery->pluck('order_id');
        
        // === KPI 1: INVOICES ===
        $totalInvoices = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->sum('total_price') ?? 0;
        
        $invoicesCash = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['cash', 'cash_on_delivery', 'Cash', 'COD'])
            ->sum('total_price') ?? 0;
        
        $nfCashAccount = AccountModel::where('account_code', 'NF_CASH')->first();
        $cashDeposits = 0;
        $shortCashTotal = 0;
        
        if ($nfCashAccount) {
            $cashDeposits = LedgerModel::where('to_account_id', $nfCashAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount') ?? 0;
            
            $shortCashTotal = \App\Models\Request\RequestModel::where('status', 'approved')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                })
                ->whereHas('paymentSourceAccount', function($q) {
                    $q->where('account_category', 'employee_cash');
                })
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('amount') ?? 0;
        }
        
        $invoicesOnline = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
            ->sum('total_price') ?? 0;
        
        $onlineAccount = AccountModel::where('account_code', 'ONLINE')->first();
        $onlineApproved = 0;
        $onlinePending = 0;
        
        if ($onlineAccount) {
            $onlineApproved = LedgerModel::where('to_account_id', $onlineAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount') ?? 0;
            
            $onlinePending = LedgerModel::where('to_account_id', $onlineAccount->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', LedgerModel::STATUS_PENDING)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount') ?? 0;
        }
        
        // === KPI 2: EXPENSES ===
        $ledgerExpenses = LedgerModel::where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        $salaryExpenses = \App\Models\HR\SalarySlipModel::whereIn('slip_status', ['approved', 'paid'])
            ->whereNotNull('ledger_transaction_id')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('net_salary') ?? 0;
        
        $totalExpenses = $ledgerExpenses + $salaryExpenses;
        $regularExpenses = $ledgerExpenses;
        $salaryExpensesForDisplay = $salaryExpenses;
        
        $expensesNeedingSettlement = \App\Models\Request\RequestModel::where('status', 'approved')
            ->where('settlement_status', 'pending')
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        // === KPI 3: VENDOR BALANCE ===
        $vendorPurchases = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        $vendorPayments = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        $vendorBalance = $vendorPurchases - $vendorPayments;
        
        // === KPI 4: RIDERS BALANCE ===
        $ridersBalance = AccountModel::where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
            ->where('is_active', 1)
            ->sum('current_balance') ?? 0;
        
        $pendingDeposits = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
            ->where('approval_status', LedgerModel::STATUS_PENDING)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        $pendingExpenses = \App\Models\Request\RequestModel::where('status', 'pending')
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        // === KPI 5: NF BALANCE (PROFIT) ===
        $profit = $totalInvoices - $totalExpenses - $vendorPurchases;
        
        return [
            'total_invoices' => $totalInvoices,
            'invoices_cash' => $invoicesCash,
            'cash_deposits' => $cashDeposits,
            'short_cash_total' => $shortCashTotal,
            'invoices_online' => $invoicesOnline,
            'online_approved' => $onlineApproved,
            'online_pending' => $onlinePending,
            'total_expenses' => $totalExpenses,
            'regular_expenses' => $regularExpenses,
            'salary_expenses' => $salaryExpensesForDisplay,
            'expenses_needing_settlement' => $expensesNeedingSettlement,
            'vendor_balance' => $vendorBalance,
            'vendor_purchases' => $vendorPurchases,
            'vendor_payments' => $vendorPayments,
            'riders_balance' => $ridersBalance,
            'pending_deposits' => $pendingDeposits,
            'pending_expenses' => $pendingExpenses,
            'profit' => $profit,
            'profit_invoices' => $totalInvoices,
            'profit_expenses' => $totalExpenses,
            'profit_vendor_purchases' => $vendorPurchases,
        ];
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
            if ($fromAccount->account_type === 'asset') {
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
                if ($fromAccount->account_type === 'asset') {
                    // Money going OUT from asset = Decrease
                    $fromAccount->current_balance -= $request->amount;
                } else {
                    // Money going OUT from liability/income/equity = Increase
                    $fromAccount->current_balance += $request->amount;
                }
                $fromAccount->save();

                // To account: opposite
                if ($toAccount->account_type === 'asset') {
                    // Money coming IN to asset = Increase
                    $toAccount->current_balance += $request->amount;
                } else {
                    // Money coming IN to liability/income/equity = Decrease
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
        $transaction = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'approvedBy', 'order', 'request'])
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
            $ledger->approval_date = now()->toDateString();
            
            // Add approval notes to comments field
            if ($request->approval_notes) {
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                                   "Approval Notes: " . $request->approval_notes;
            }
            
            $ledger->save();

            // Reload accounts (in case they were changed)
            $ledger->load(['fromAccount', 'toAccount']);
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;

            // From account adjustment
            // For Asset accounts (Cash, Bank, Employee Cash): Debit increases, Credit decreases
            // For Liability/Income/Equity: Credit increases, Debit decreases
            if ($fromAccount->account_type === 'asset') {
                // Money going OUT from asset account = Decrease
                $fromAccount->current_balance -= $ledger->amount;
            } else {
                // Money going OUT from liability/income/equity = Increase (reducing the liability/increasing expense)
                $fromAccount->current_balance += $ledger->amount;
            }
            $fromAccount->save();

            // To account adjustment
            if ($toAccount->account_type === 'asset') {
                // Money coming IN to asset account = Increase
                $toAccount->current_balance += $ledger->amount;
            } else {
                // Money coming IN to liability/income/equity = Decrease (increasing the liability/reducing expense)
                $toAccount->current_balance -= $ledger->amount;
            }
            $toAccount->save();

            // ========== SETTLEMENT PROCESSING ==========
            // If this is an employee deposit with settlement intent, process it
            if ($ledger->transaction_type === LedgerModel::TYPE_EMPLOYEE_DEPOSIT) {
                // Try to get settlement data from ledger metadata (NEW) or fallback to session (OLD)
                $settlementData = $ledger->settlement_metadata;
                
                // Fallback to session for old deposits
                if (!$settlementData) {
                    $sessionKey = "settlement_pending_{$ledger->id}";
                    $settlementData = \Session::get($sessionKey);
                    \Log::info("Using session fallback for settlement data", [
                        'deposit_id' => $ledger->id
                    ]);
                }
                
                \Log::info("Checking for settlement data", [
                    'deposit_id' => $ledger->id,
                    'has_metadata' => $ledger->settlement_metadata ? 'yes' : 'no',
                    'has_data' => $settlementData ? 'yes' : 'no',
                    'data' => $settlementData
                ]);
                
                if ($settlementData && isset($settlementData['invoice_ids'])) {
                    \Log::info("Processing invoice settlement", [
                        'deposit_id' => $ledger->id,
                        'invoice_count' => count($settlementData['invoice_ids']),
                        'invoice_ids' => $settlementData['invoice_ids']
                    ]);
                    
                    $this->processInvoiceSettlement($ledger, $settlementData);
                    
                    // Check if this is a short cash settlement with linked expense request
                    if (isset($settlementData['is_short_cash_settlement']) && 
                        $settlementData['is_short_cash_settlement'] && 
                        isset($settlementData['expense_request_id'])) {
                        
                        $expenseRequestId = $settlementData['expense_request_id'];
                        
                        \Log::info("Auto-approving linked short cash expense", [
                            'deposit_id' => $ledger->id,
                            'expense_request_id' => $expenseRequestId
                        ]);
                        
                        // Auto-approve the linked expense request
                        $expenseRequest = \App\Models\Request\RequestModel::find($expenseRequestId);
                        
                        if ($expenseRequest && $expenseRequest->status === 'pending') {
                            // Process the approval (level, approverId, action, comments)
                            $expenseRequest->processApproval(1, auth()->id(), 'approved', 'Auto-approved with deposit settlement');
                            
                            \Log::info("Short cash expense auto-approved", [
                                'expense_request_id' => $expenseRequestId,
                                'amount' => $expenseRequest->amount
                            ]);
                        }
                    }
                    
                    // Clean up session if it was used
                    \Session::forget("settlement_pending_{$ledger->id}");
                } else {
                    \Log::warning("No settlement data found for deposit - invoices will not be auto-settled", [
                        'deposit_id' => $ledger->id,
                        'description' => $ledger->description
                    ]);
                }
            }

            DB::commit();

            // Redirect back to where they came from (if from outstanding invoices page, stay there)
            if (str_contains(url()->previous(), 'outstanding-invoices')) {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('success', 'Settlement deposit approved successfully! Invoices have been settled.');
            }

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
        // Make rejection_reason optional for quick rejects
        $request->validate([
            'rejection_reason' => 'nullable|string|max:500'
        ]);

        try {
            $ledger = LedgerModel::findOrFail($id);

            if ($ledger->approval_status !== LedgerModel::STATUS_PENDING) {
                throw new \Exception("Transaction is not pending approval");
            }

            $ledger->approval_status = LedgerModel::STATUS_REJECTED;
            $ledger->approved_by = auth()->id();
            $ledger->approval_date = now()->toDateString();
            
            // Add rejection reason to comments (if provided)
            if ($request->rejection_reason) {
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                                   "Rejection Reason: " . $request->rejection_reason;
            }
            
            $ledger->save();
            
            // Clean up settlement data from session
            \Session::forget("settlement_pending_{$ledger->id}");

            // Redirect back to where they came from (if from outstanding invoices page, stay there)
            if (str_contains(url()->previous(), 'outstanding-invoices')) {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('success', 'Settlement deposit rejected successfully.');
            }

            return redirect()->route('fin.ledger.index')
                           ->with('success', 'Transaction rejected successfully!');

        } catch (\Exception $e) {
            Log::error("Error rejecting transaction: " . $e->getMessage());
            
            return back()->with('error', 'Error rejecting transaction: ' . $e->getMessage());
        }
    }

    /**
     * Process invoice settlement when deposit is approved
     * 
     * @param LedgerModel $depositLedger The approved deposit transaction
     * @param array $settlementData Contains invoice_ids, deposit_amount, total_outstanding
     */
    private function processInvoiceSettlement(LedgerModel $depositLedger, array $settlementData)
    {
        try {
            $invoiceIds = $settlementData['invoice_ids'];
            $depositAmount = $settlementData['deposit_amount'];
            $totalOutstanding = $settlementData['total_outstanding'];
            
            // Check if this is a short cash settlement
            $isShortCash = $settlementData['is_short_cash_settlement'] ?? false;
            $shortCashAmount = $settlementData['short_cash_amount'] ?? 0;
            
            // For short cash, the total amount settling invoices = deposit + expense
            $totalSettlementAmount = $isShortCash ? ($depositAmount + $shortCashAmount) : $depositAmount;
            
            \Log::info("Processing invoice settlement", [
                'deposit_id' => $depositLedger->id,
                'is_short_cash' => $isShortCash,
                'deposit_amount' => $depositAmount,
                'short_cash_amount' => $shortCashAmount,
                'total_settlement_amount' => $totalSettlementAmount
            ]);
            
            // Get the invoices that need to be settled (in order)
            $invoices = LedgerModel::whereIn('id', $invoiceIds)
                ->where('settlement_status', 'open')
                ->orderBy('transaction_date', 'asc')
                ->get();
            
            $remainingAmount = $totalSettlementAmount;
            
            foreach ($invoices as $invoice) {
                $outstandingForThisInvoice = $invoice->amount - ($invoice->settled_amount ?? 0);
                
                if ($remainingAmount <= 0) {
                    break; // No more money to allocate
                }
                
                // Calculate how much to settle on this invoice
                $amountToSettle = min($remainingAmount, $outstandingForThisInvoice);
                
                // Update invoice
                $invoice->settled_amount = ($invoice->settled_amount ?? 0) + $amountToSettle;
                
                if ($invoice->settled_amount >= $invoice->amount) {
                    // Fully settled
                    $invoice->settlement_status = 'settled';
                    $invoice->settled_at = now();
                    $invoice->settled_via_ledger_id = $depositLedger->id;
                }
                $invoice->save();
                
                // Create audit record
                \App\Models\FIN\InvoiceSettlementModel::create([
                    'settlement_deposit_id' => $depositLedger->id,
                    'invoice_ledger_id' => $invoice->id,
                    'settled_amount' => $amountToSettle
                ]);
                
                $remainingAmount -= $amountToSettle;
            }
            
            \Log::info("Invoice settlement completed", [
                'deposit_id' => $depositLedger->id,
                'invoices_count' => $invoices->count(),
                'total_settlement_amount' => $totalSettlementAmount,
                'amount_allocated' => $totalSettlementAmount - $remainingAmount,
                'amount_remaining' => $remainingAmount,
                'is_short_cash' => $isShortCash
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Error processing invoice settlement: " . $e->getMessage(), [
                'deposit_id' => $depositLedger->id,
                'settlement_data' => $settlementData
            ]);
            throw $e;
        }
    }

    /**
     * Get approval details for a transaction (for audit trail modal)
     */
    public function getApprovalDetails($id)
    {
        try {
            $transaction = LedgerModel::with(['approvedBy', 'fromAccount', 'toAccount'])
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'transaction' => [
                    'id' => $transaction->id,
                    'transaction_type' => ucfirst(str_replace('_', ' ', $transaction->transaction_type)),
                    'description' => $transaction->description,
                    'amount' => $transaction->amount,
                    'approval_status' => $transaction->approval_status,
                    'approval_date' => $transaction->approval_date,
                    'approver_name' => $transaction->approvedBy ? $transaction->approvedBy->fullname : 'System',
                    'from_account' => $transaction->fromAccount ? $transaction->fromAccount->account_name : null,
                    'to_account' => $transaction->toAccount ? $transaction->toAccount->account_name : null,
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error getting approval details: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading approval details'
            ], 500);
        }
    }
}

