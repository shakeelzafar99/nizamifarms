<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\InvoiceSettlementModel;
use App\Models\Request\RequestCategoryModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;
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
        
        $query = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'order.customer']);

        // Exclude transactions involving private accounts for non-Taimur users
        $webUser = auth()->user();
        $isWebTaimur = $webUser && $webUser->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists();
        if (!$isWebTaimur) {
            $privateIds = AccountModel::where('is_private', 1)->pluck('id')->toArray();
            if (!empty($privateIds)) {
                $query->whereNotIn('from_account_id', $privateIds)
                      ->whereNotIn('to_account_id', $privateIds);
            }
        }

        // Filter by date range
        // For vendor payments, use posted_date; for others, use transaction_date
        if ($startDate) {
            $query->where(function($q) use ($startDate) {
                $q->where(function($subQ) use ($startDate) {
                    // Vendor payments use posted_date
                    $subQ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where(function($dateQ) use ($startDate) {
                             $dateQ->where('posted_date', '>=', $startDate)
                                   ->orWhere(function($fallbackQ) use ($startDate) {
                                       $fallbackQ->whereNull('posted_date')
                                                 ->where('transaction_date', '>=', $startDate);
                                   });
                         });
                })->orWhere(function($subQ) use ($startDate) {
                    // All other transactions use transaction_date
                    $subQ->where('transaction_type', '!=', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where('transaction_date', '>=', $startDate);
                });
            });
        }
        if ($endDate) {
            $query->where(function($q) use ($endDate) {
                $q->where(function($subQ) use ($endDate) {
                    // Vendor payments use posted_date
                    $subQ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where(function($dateQ) use ($endDate) {
                             $dateQ->where('posted_date', '<=', $endDate)
                                   ->orWhere(function($fallbackQ) use ($endDate) {
                                       $fallbackQ->whereNull('posted_date')
                                                 ->where('transaction_date', '<=', $endDate);
                                   });
                         });
                })->orWhere(function($subQ) use ($endDate) {
                    // All other transactions use transaction_date
                    $subQ->where('transaction_type', '!=', LedgerModel::TYPE_VENDOR_PAYMENT)
                         ->where('transaction_date', '<=', $endDate);
                });
            });
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

        // Get all accounts for filters — hide private accounts for non-Taimur
        $accounts = AccountModel::where('is_active', 1)
                                ->visibleTo(auth()->user())
                                ->orderBy('account_name', 'asc')
                                ->get();

        // Get transaction types (all types from LedgerModel)
        $transactionTypes = [
            LedgerModel::TYPE_INVOICE => 'Invoice',
            LedgerModel::TYPE_EMPLOYEE_DEPOSIT => 'Employee Deposit',
            LedgerModel::TYPE_EXPENSE => 'Expense',
            LedgerModel::TYPE_VENDOR_PURCHASE => 'Vendor Purchase',
            LedgerModel::TYPE_VENDOR_PAYMENT => 'Vendor Payment',
            LedgerModel::TYPE_SALARY_ADVANCE => 'Salary Advance',
            LedgerModel::TYPE_SALARY_PAYMENT => 'Salary Payment',
            LedgerModel::TYPE_REIMBURSEMENT_ACCRUAL => 'Reimbursement Accrual',
            LedgerModel::TYPE_REIMBURSEMENT_PAYMENT => 'Reimbursement Payment',
            LedgerModel::TYPE_SETTLEMENT => 'Expense Settlement',
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
        
        // === ONLINE INVOICES: Enhanced with L1/L2 split (matching EmployeeCashController) ===
        // Get ALL online invoice ledgers
        // Check ONLINE account (to_account) instead of order payment method for accuracy
        $onlineAccount = AccountModel::where('account_code', 'ONLINE')->first();
        $onlineInvoiceLedgersQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
            ->whereBetween('transaction_date', [$startDate, $endDate]);
        
        if ($onlineAccount) {
            $onlineInvoiceLedgersQuery->where('to_account_id', $onlineAccount->id);
        }
        
        $onlineInvoiceLedgers = $onlineInvoiceLedgersQuery->get();
        
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
            'online_pending_l1' => $onlinePendingL1, // NEW: Pending L1 split
            'online_pending_l2' => $onlinePendingL2, // NEW: Pending L2 split
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
        // Get all active accounts — hide private for non-Taimur
        $accounts = AccountModel::where('is_active', 1)
                                ->visibleTo(auth()->user())
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
        // Store the previous URL (with filters) in session for "Back to Approvals" button
        if (url()->previous() !== url()->current()) {
            $previousUrl = url()->previous();
            // Only store if it's from the approvals index page
            if (strpos($previousUrl, route('approvals.index')) !== false || strpos($previousUrl, '/approvals') !== false) {
                session(['approvals_return_url' => $previousUrl]);
            }
        }
        
        $transaction = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'approvedBy', 'order.customer', 'request'])
                                  ->findOrFail($id);

        // ⭐ Fetch related approvals for the same order (if this is an invoice transaction)
        $relatedApprovals = $this->getRelatedApprovalsForOrder($transaction);

        return view('fin.ledger.show', compact('transaction', 'relatedApprovals'));
    }
    
    /**
     * Get related pending/approved approvals for the same order
     * This helps approvers see all related items for the same order at a glance
     */
    private function getRelatedApprovalsForOrder(LedgerModel $transaction): array
    {
        $relatedApprovals = [];
        
        // Only applicable for invoice transactions with an order_id
        if (!$transaction->order_id || $transaction->transaction_type !== LedgerModel::TYPE_INVOICE) {
            return $relatedApprovals;
        }
        
        $orderId = $transaction->order_id;
        $currentLedgerId = $transaction->id;
        
        // 1. Find other ledger transactions for the same order (excluding current)
        $relatedLedgers = LedgerModel::where('order_id', $orderId)
            ->where('id', '!=', $currentLedgerId)
            ->whereIn('approval_status', [
                LedgerModel::STATUS_PENDING,
                LedgerModel::STATUS_PENDING_L1,
                LedgerModel::STATUS_PENDING_L2,
                LedgerModel::STATUS_APPROVED
            ])
            ->with(['fromAccount', 'toAccount'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        foreach ($relatedLedgers as $ledger) {
            $relatedApprovals[] = [
                'type' => 'ledger',
                'id' => $ledger->id,
                'title' => 'Invoice Transaction #' . $ledger->id,
                'description' => $ledger->description,
                'amount' => $ledger->amount,
                'status' => $ledger->approval_status,
                'status_label' => $this->formatApprovalStatus($ledger->approval_status),
                'date' => $ledger->created_at ? $ledger->created_at->format('M j, Y') : '-',
                'is_pending' => $ledger->isPending(),
                'view_url' => route('fin.ledger.show', ['id' => $ledger->id, 'origin' => 'approvals'])
            ];
        }
        
        // 2. Find ledger adjustments for the same order
        $relatedAdjustments = \App\Models\FIN\LedgerAdjustmentModel::where('order_id', $orderId)
            ->whereIn('adjustment_status', [
                \App\Models\FIN\LedgerAdjustmentModel::STATUS_PENDING,
                \App\Models\FIN\LedgerAdjustmentModel::STATUS_APPROVED
            ])
            ->with(['ledger', 'requestedBy'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        foreach ($relatedAdjustments as $adj) {
            $isPending = $adj->adjustment_status === \App\Models\FIN\LedgerAdjustmentModel::STATUS_PENDING;
            $statusLabel = $isPending ? 'Pending' : 'Approved';
            
            // Check approval levels for pending adjustments
            if ($isPending) {
                if ($adj->level_1_status === 'pending') {
                    $statusLabel = 'Pending L1';
                } elseif ($adj->level_1_status === 'approved' && $adj->level_2_status === 'pending') {
                    $statusLabel = 'Pending L2';
                }
            }
            
            $relatedApprovals[] = [
                'type' => 'adjustment',
                'id' => $adj->id,
                'title' => 'Ledger Adjustment #' . $adj->id,
                'description' => $adj->adjustment_reason ?? 'Amount adjustment: Rs. ' . number_format(abs($adj->adjustment_amount), 2),
                'amount' => abs($adj->adjustment_amount),
                'status' => $adj->adjustment_status,
                'status_label' => $statusLabel,
                'date' => $adj->requested_at ? $adj->requested_at->format('M j, Y') : '-',
                'is_pending' => $isPending,
                'view_url' => route('fin.ledger.adjustments.show', $adj->id)
            ];
        }
        
        return $relatedApprovals;
    }
    
    /**
     * Format approval status for display
     */
    private function formatApprovalStatus(string $status): string
    {
        return match($status) {
            LedgerModel::STATUS_PENDING, LedgerModel::STATUS_PENDING_L1 => 'Pending L1',
            LedgerModel::STATUS_PENDING_L2 => 'Pending L2',
            LedgerModel::STATUS_APPROVED => 'Approved',
            LedgerModel::STATUS_REJECTED => 'Rejected',
            LedgerModel::STATUS_REVERSED => 'Reversed',
            default => ucfirst($status)
        };
    }

    /**
     * Approve pending transaction
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'approval_notes' => 'nullable|string|max:500',
            'override_destination_account_id' => 'nullable|exists:t_fin_accounts,id',
            'override_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'force_full_approval' => 'nullable|boolean', // ⭐ Allow bypassing L2 if user has L2 rights
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // ⭐ Optional proof of payment image (max 5MB)
            'receiving_account_id' => 'nullable|exists:t_fin_online_receiving_accounts,id', // ⭐ Which bank received this payment
        ]);

        try {
            DB::beginTransaction();

            $ledger = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);

            // ⭐ Handle optional proof of payment image upload
            $proofImagePath = $this->handleApprovalImageUpload($request, $ledger);

            if (!$ledger->isPending()) {
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

            // ================== APPROVAL LEVEL LOGIC ==================
            // Determine current level from status
            $currentLevel = 1;
            if ($ledger->approval_status === LedgerModel::STATUS_PENDING_L2) {
                $currentLevel = 2;
            }

            // Determine required levels from Request Settings category config
            $categoryCode = null;
            switch ($ledger->transaction_type) {
                case LedgerModel::TYPE_INVOICE:
                    $categoryCode = 'invoice_approval';
                    break;
                case LedgerModel::TYPE_EMPLOYEE_DEPOSIT:
                    $categoryCode = 'employee_deposit';
                    break;
                case LedgerModel::TYPE_VENDOR_PAYMENT:
                    $categoryCode = 'vendor_payment';
                    break;
                case LedgerModel::TYPE_TRANSFER:
                    $categoryCode = 'account_transfer';
                    break;
            }

            $requiresL1 = true;
            $requiresL2 = false;

            if ($categoryCode) {
                $category = RequestCategoryModel::getByCode($categoryCode);
                if ($category) {
                    $requiresL1 = $category->requiresLevel1();
                    $requiresL2 = $category->requiresLevel2();
                }
            }

            // Decide new status
            $finalApproval = false;
            $shouldUpdateBalances = false;
            $forceFullApproval = $request->boolean('force_full_approval', false);
            
            // ⭐ If force_full_approval is requested, check if user has L2 rights
            $userHasL2Rights = RoleApprovalLevelModel::userHasApprovalLevel(auth()->id(), 2);
            
            if ($currentLevel === 1 && $requiresL2 && !($forceFullApproval && $userHasL2Rights)) {
                // L1 → L2 pending: balance is applied now, L2 is verification only
                $ledger->approval_status = LedgerModel::STATUS_PENDING_L2;
                $ledger->comments = ($ledger->comments ?? '') .
                    " | L1 approved by User ID " . auth()->id();
                $shouldUpdateBalances = true;
                $ledger->balance_updated = 1;
            } elseif ($currentLevel === 2) {
                // L2 approval: verification step — mark approved, skip balance if already applied at L1
                $ledger->approval_status = LedgerModel::STATUS_APPROVED;
                $ledger->approved_by = auth()->id();
                $ledger->approval_date = now();
                $finalApproval = true;
                $shouldUpdateBalances = !$ledger->balance_updated;
                if (!$ledger->balance_updated) {
                    $ledger->balance_updated = 1;
                }
            } else {
                // Final approval: single-level, or force_full with L2 rights
                $ledger->approval_status = LedgerModel::STATUS_APPROVED;
                $ledger->approved_by = auth()->id();
                $ledger->approval_date = now();
                $finalApproval = true;
                $shouldUpdateBalances = true;
                $ledger->balance_updated = 1;
                
                if ($forceFullApproval && $currentLevel === 1 && $requiresL2 && $userHasL2Rights) {
                    $ledger->comments = ($ledger->comments ?? '') .
                        " | Fully approved (L1+L2) by User ID " . auth()->id() . " with L2 rights";
                }
            }

            // Add approval notes to comments field
            if ($request->approval_notes) {
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                                   "Approval Notes: " . $request->approval_notes;
            }

            // ⭐ Save proof of payment image if uploaded
            if ($proofImagePath) {
                $ledger->bill_image = $proofImagePath;
            }

            // ⭐ Save receiving bank account if provided (which bank received this online payment)
            if ($request->receiving_account_id) {
                $ledger->receiving_account_id = $request->receiving_account_id;
            }
            
            $ledger->save();

            // Reload accounts (in case they were changed)
            $ledger->load(['fromAccount', 'toAccount']);
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;

            // Update balances: at L1 (early reflect), force-full, or L2 for historical records without balance_updated
            if ($shouldUpdateBalances) {
                if ($fromAccount->account_type === 'asset') {
                    $fromAccount->current_balance -= $ledger->amount;
                } else {
                    $fromAccount->current_balance += $ledger->amount;
                }
                $fromAccount->save();

                if ($toAccount->account_type === 'asset') {
                    $toAccount->current_balance += $ledger->amount;
                } else {
                    $toAccount->current_balance -= $ledger->amount;
                }
                $toAccount->save();
            }

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

            // Decide success message based on whether this was final approval or L1 only
            $successMessage = $finalApproval
                ? 'Transaction approved successfully!'
                : 'Transaction approved at Level 1 (reflected in balances). Awaiting Level 2 verification.';

            // If AJAX request (from modal), return JSON with updated balance
            if ($request->ajax() || $request->wantsJson()) {
                $updatedToAccount = $ledger->to_account_id ? AccountModel::find($ledger->to_account_id) : null;
                return response()->json([
                    'success' => true,
                    'message' => $successMessage,
                    'new_balance' => $updatedToAccount ? $updatedToAccount->current_balance : null,
                ]);
            }

            // Redirect back to where they came from (if from outstanding invoices page, stay there)
            if ($request->input('_origin') === 'outstanding-invoices' || str_contains(url()->previous(), 'outstanding-invoices')) {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('success', $successMessage);
            }

            if ($request->input('_origin') === 'approvals' || str_contains(url()->previous(), 'approvals')) {
                return redirect()->route('approvals.index')
                               ->with('success', $successMessage);
            }

            return redirect()->route('fin.ledger.index')
                           ->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error approving transaction: " . $e->getMessage());
            
            // If AJAX request, return JSON error
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error approving transaction: ' . $e->getMessage()
                ], 500);
            }
            
            if ($request->input('_origin') === 'outstanding-invoices') {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('error', 'Error approving transaction: ' . $e->getMessage());
            }
            return back()->with('error', 'Error approving transaction: ' . $e->getMessage());
        }
    }

    /**
     * Approve transaction at L1 only (for L2 users who want to use proper two-stage approval)
     * This forces the transaction to move to L2 pending, even if the user has L2 rights
     */
    public function approveAtL1Only(Request $request, $id)
    {
        $request->validate([
            'approval_notes' => 'nullable|string|max:500',
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // ⭐ Optional proof of payment image
            'receiving_account_id' => 'nullable|exists:t_fin_online_receiving_accounts,id', // ⭐ Which bank received this payment
        ]);

        try {
            DB::beginTransaction();

            $ledger = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);

            // ⭐ Handle optional proof of payment image upload
            $proofImagePath = $this->handleApprovalImageUpload($request, $ledger);

            // Validation: Must be at L1 pending stage
            if (!in_array($ledger->approval_status, [LedgerModel::STATUS_PENDING, LedgerModel::STATUS_PENDING_L1])) {
                throw new \Exception("Transaction is not at L1 pending stage");
            }

            // Determine required levels from Request Settings
            $categoryCode = null;
            switch ($ledger->transaction_type) {
                case LedgerModel::TYPE_INVOICE:
                    $categoryCode = 'invoice_approval';
                    break;
                case LedgerModel::TYPE_EMPLOYEE_DEPOSIT:
                    $categoryCode = 'employee_deposit';
                    break;
                case LedgerModel::TYPE_VENDOR_PAYMENT:
                    $categoryCode = 'vendor_payment';
                    break;
                case LedgerModel::TYPE_TRANSFER:
                    $categoryCode = 'account_transfer';
                    break;
            }

            $requiresL2 = false;
            if ($categoryCode) {
                $category = RequestCategoryModel::getByCode($categoryCode);
                if ($category) {
                    $requiresL2 = $category->requiresLevel2();
                }
            }

            // Validation: Must require L2 approval
            if (!$requiresL2) {
                throw new \Exception("This transaction does not require L2 approval");
            }

            // Move to L2 pending and apply balances (L2 is verification only)
            $ledger->approval_status = LedgerModel::STATUS_PENDING_L2;
            $ledger->balance_updated = 1;
            $ledger->comments = ($ledger->comments ?? '') .
                " | L1 approved by User ID " . auth()->id() . " (L1-only approval)";

            // Add approval notes if provided
            if ($request->approval_notes) {
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                                   "L1 Approval Notes: " . $request->approval_notes;
            }

            // ⭐ Save proof of payment image if uploaded
            if ($proofImagePath) {
                $ledger->bill_image = $proofImagePath;
            }

            // ⭐ Save receiving bank account if provided
            if ($request->receiving_account_id) {
                $ledger->receiving_account_id = $request->receiving_account_id;
            }

            $ledger->save();

            // Update account balances at L1 stage (reflects in ledger immediately)
            $ledger->load(['fromAccount', 'toAccount']);
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;

            if ($fromAccount && $toAccount) {
                if ($fromAccount->account_type === 'asset') {
                    $fromAccount->current_balance -= $ledger->amount;
                } else {
                    $fromAccount->current_balance += $ledger->amount;
                }
                $fromAccount->save();

                if ($toAccount->account_type === 'asset') {
                    $toAccount->current_balance += $ledger->amount;
                } else {
                    $toAccount->current_balance -= $ledger->amount;
                }
                $toAccount->save();
            }

            DB::commit();

            $successMessage = "Transaction approved at Level 1 and reflected in balances. Now pending Level 2 verification.";

            // If AJAX request (from modal), return JSON with updated balance
            if ($request->ajax() || $request->wantsJson()) {
                $updatedToAccount = $ledger->to_account_id ? AccountModel::find($ledger->to_account_id) : null;
                return response()->json([
                    'success' => true,
                    'message' => $successMessage,
                    'new_balance' => $updatedToAccount ? $updatedToAccount->current_balance : null,
                ]);
            }

            // Redirect based on origin
            if ($request->input('_origin') === 'approvals' || str_contains(url()->previous(), 'approvals')) {
                return redirect()->route('approvals.index')
                               ->with('success', $successMessage);
            }

            return redirect()->route('fin.ledger.index')
                           ->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error approving transaction at L1: " . $e->getMessage());
            
            // If AJAX request, return JSON error
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->with('error', 'Error: ' . $e->getMessage());
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
            DB::beginTransaction();

            $ledger = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);

            if (!$ledger->isPending()) {
                throw new \Exception("Transaction is not pending approval");
            }

            $wasAtL2 = $ledger->approval_status === LedgerModel::STATUS_PENDING_L2;

            $ledger->approval_status = LedgerModel::STATUS_REJECTED;
            $ledger->approved_by = auth()->id();
            $ledger->approval_date = now()->toDateString();
            
            // Add rejection reason to comments (if provided)
            if ($request->rejection_reason) {
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                                   "Rejection Reason: " . $request->rejection_reason;
            }

            // Reverse account balances if they were already applied at L1
            if ($ledger->balance_updated) {
                $fromAccount = $ledger->fromAccount;
                $toAccount = $ledger->toAccount;

                if ($fromAccount && $toAccount) {
                    if ($fromAccount->account_type === 'asset') {
                        $fromAccount->current_balance += $ledger->amount;
                    } else {
                        $fromAccount->current_balance -= $ledger->amount;
                    }
                    $fromAccount->save();

                    if ($toAccount->account_type === 'asset') {
                        $toAccount->current_balance -= $ledger->amount;
                    } else {
                        $toAccount->current_balance += $ledger->amount;
                    }
                    $toAccount->save();

                    $ledger->balance_updated = 0;
                    $ledger->comments = ($ledger->comments ? $ledger->comments . "\n" : '') .
                        "Balance reversed due to L2 rejection by User ID " . auth()->id();

                    Log::info("Reversed account balances on L2 rejection", [
                        'ledger_id' => $ledger->id,
                        'amount' => $ledger->amount,
                        'from_account' => $fromAccount->id,
                        'to_account' => $toAccount->id
                    ]);
                }
            }
            
            $ledger->save();

            DB::commit();
            
            // Clean up settlement data from session
            \Session::forget("settlement_pending_{$ledger->id}");

            $successMessage = 'Transaction rejected successfully!';

            // If AJAX request (from modal), return JSON with updated balance
            if ($request->ajax() || $request->wantsJson()) {
                $updatedToAccount = $ledger->to_account_id ? AccountModel::find($ledger->to_account_id) : null;
                return response()->json([
                    'success' => true,
                    'message' => $successMessage,
                    'new_balance' => $updatedToAccount ? $updatedToAccount->current_balance : null,
                ]);
            }

            // Redirect back to where they came from (if from outstanding invoices page, stay there)
            if ($request->input('_origin') === 'outstanding-invoices' || str_contains(url()->previous(), 'outstanding-invoices')) {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('success', 'Settlement deposit rejected successfully.');
            }

            if ($request->input('_origin') === 'approvals' || str_contains(url()->previous(), 'approvals')) {
                return redirect()->route('approvals.index')
                               ->with('success', $successMessage);
            }

            return redirect()->route('fin.ledger.index')
                           ->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error rejecting transaction: " . $e->getMessage());
            
            // If AJAX request, return JSON error
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error rejecting transaction: ' . $e->getMessage()
                ], 500);
            }
            
            if ($request->input('_origin') === 'outstanding-invoices') {
                return redirect()->route('fin.employee.all-outstanding-invoices')
                               ->with('error', 'Error rejecting transaction: ' . $e->getMessage());
            }
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
            
            // Check if this is a short cash settlement or partial payment
            $isShortCash = $settlementData['is_short_cash_settlement'] ?? false;
            $isPartialPayment = $settlementData['is_partial_payment'] ?? false;
            $shortCashAmount = $settlementData['short_cash_amount'] ?? 0;
            
            // For short cash, the total amount settling invoices = deposit + expense
            // For partial payment, only the deposit amount is used (remaining stays open)
            if ($isShortCash) {
                $totalSettlementAmount = $depositAmount + $shortCashAmount;
            } else {
                $totalSettlementAmount = $depositAmount;
            }
            
            \Log::info("Processing invoice settlement", [
                'deposit_id' => $depositLedger->id,
                'is_short_cash' => $isShortCash,
                'is_partial_payment' => $isPartialPayment,
                'deposit_amount' => $depositAmount,
                'short_cash_amount' => $shortCashAmount,
                'total_settlement_amount' => $totalSettlementAmount
            ]);
            
            // Get the invoices that need to be settled (in order) - include both open and partial
            $invoices = LedgerModel::whereIn('id', $invoiceIds)
                ->whereIn('settlement_status', ['open', 'partial'])
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
                } else {
                    // Partially settled: keep status 'open' (legacy behavior). We infer partial by settled_amount > 0
                    // Do not write a non-existent enum value like 'partial' to the database.
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
                'is_short_cash' => $isShortCash,
                'is_partial_payment' => $isPartialPayment
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
            $transaction = LedgerModel::with(['approvedBy', 'fromAccount', 'toAccount', 'order'])
                ->findOrFail($id);
            
            // Get invoice details if this is a settlement transaction
            $invoices = [];
            if ($transaction->settlement_metadata && isset($transaction->settlement_metadata['invoice_ids'])) {
                $invoiceIds = $transaction->settlement_metadata['invoice_ids'];
                $invoiceModels = LedgerModel::whereIn('id', $invoiceIds)
                    ->with('order')
                    ->orderBy('transaction_date', 'asc')
                    ->get();
                
                foreach ($invoiceModels as $invoice) {
                    $invoices[] = [
                        'id' => $invoice->id,
                        'order_number' => $invoice->order ? $invoice->order->order_number : 'Invoice #' . $invoice->id,
                        'order_id' => $invoice->order_id,
                        'date' => $invoice->transaction_date->format('M j, Y'),
                        'amount' => $invoice->amount,
                        'settlement_status' => $invoice->settlement_status,
                        'settled_amount' => $invoice->settled_amount ?? 0
                    ];
                }
            }
            
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
                    'invoices' => $invoices,
                    'total_outstanding' => $transaction->settlement_metadata['total_outstanding'] ?? null
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

    /**
     * Get transaction details for viewing
     */
    public function getTransactionDetails($id)
    {
        try {
            $transaction = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy'])
                ->findOrFail($id);

            // Fetch line items if this is a weighted purchase
            $lineItems = [];
            if ($transaction->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE) {
                $lineItems = \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $transaction->id)
                    ->get()
                    ->map(function($item) {
                        return [
                            'vendor_product_id' => $item->vendor_product_id,
                            'product_name' => $item->product_name,
                            'quantity' => $item->quantity,
                            'unit' => $item->unit,
                            'rate_per_unit' => $item->rate_per_unit,
                            'line_total' => $item->line_total
                        ];
                    })
                    ->toArray();
            }

            // ⭐ Generate full image URL if bill_image exists (using public-storage proxy like attendance)
            $billImageUrl = null;
            if ($transaction->bill_image) {
                // If already a full URL, use as-is
                if (str_starts_with($transaction->bill_image, 'http')) {
                    $billImageUrl = $transaction->bill_image;
                } else {
                    // Use public-storage proxy endpoint (works for both web and mobile)
                    $base = request()->getSchemeAndHttpHost();
                    $billImageUrl = rtrim($base, '/') . '/public-storage/' . ltrim($transaction->bill_image, '/');
                }
            }

            return response()->json([
                'success' => true,
                'transaction' => [
                    'id' => $transaction->id,
                    'transaction_date' => $transaction->transaction_date ? $transaction->transaction_date->format('Y-m-d') : '-',
                    'transaction_date_formatted' => $transaction->transaction_date ? $transaction->transaction_date->format('M j, Y') : '-',
                    'posted_date' => $transaction->posted_date ? $transaction->posted_date->format('Y-m-d') : ($transaction->transaction_date ? $transaction->transaction_date->format('Y-m-d') : '-'),
                    'transaction_type' => ucfirst(str_replace('_', ' ', $transaction->transaction_type)),
                    'description' => $transaction->description,
                    'amount' => (float) $transaction->amount, // Ensure numeric
                    'adjustment_amount' => (float) ($transaction->adjustment_amount ?? 0), // For weighted purchases
                    'bill_image' => $billImageUrl, // ⭐ Full URL using public-storage proxy
                    'line_items' => $lineItems,
                    'from_account' => $transaction->fromAccount ? $transaction->fromAccount->account_name : '-',
                    'from_account_id' => $transaction->from_account_id,
                    'to_account' => $transaction->toAccount ? $transaction->toAccount->account_name : '-',
                    'to_account_id' => $transaction->to_account_id,
                    'created_by' => $transaction->createdBy ? $transaction->createdBy->name : '-',
                    'created_at' => $transaction->created_at ? $transaction->created_at->format('M j, Y g:i A') : '-',
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting transaction details: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading transaction details'
            ], 500);
        }
    }

    /**
     * Update transaction details
     */
    public function updateTransaction(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'bill_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120' // Max 5MB
        ]);

        try {
            DB::beginTransaction();

            $transaction = LedgerModel::with(['fromAccount', 'toAccount'])->findOrFail($id);
            
            // Only allow editing of vendor purchases and payments
            if (!in_array($transaction->transaction_type, [
                LedgerModel::TYPE_VENDOR_PURCHASE,
                LedgerModel::TYPE_VENDOR_PAYMENT
            ])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only vendor transactions can be edited'
                ], 400);
            }

            $oldAmount = $transaction->amount;
            $newAmount = $request->amount;
            $amountDifference = $newAmount - $oldAmount;

            // Handle bill image upload
            if ($request->hasFile('bill_image')) {
                // Delete old image if exists
                if ($transaction->bill_image && \Storage::disk('public')->exists($transaction->bill_image)) {
                    \Storage::disk('public')->delete($transaction->bill_image);
                }
                
                $file = $request->file('bill_image');
                $filename = 'vendor_' . time() . '_edit.' . $file->getClientOriginalExtension();
                $billImagePath = $file->storeAs('vendor_bills', $filename, 'public');
                $transaction->bill_image = $billImagePath;
            }

            // Update transaction
            $transaction->amount = $newAmount;
            $transaction->description = $request->description ?? $transaction->description;
            $transaction->updated_by = auth()->id();
            $transaction->save();

            // Update account balances if amount changed
            if ($amountDifference != 0) {
                if ($transaction->fromAccount) {
                    $transaction->fromAccount->current_balance += $amountDifference;
                    $transaction->fromAccount->save();
                }
                
                if ($transaction->toAccount) {
                    $transaction->toAccount->current_balance += $amountDifference;
                    $transaction->toAccount->save();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating transaction: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⭐ Handle optional proof of payment image upload during approval
     * Supports both multipart file upload (web/mobile) and base64 (mobile fallback)
     * Follows same pattern as VendorController::handleImageUpload
     */
    private function handleApprovalImageUpload(Request $request, LedgerModel $ledger): ?string
    {
        // Check for multipart file upload (web & mobile FormData)
        if ($request->hasFile('proof_image')) {
            $file = $request->file('proof_image');
            $filename = 'approval_' . $ledger->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            return $file->storeAs('approval_proofs', $filename, 'public');
        }

        // Check for base64 upload (mobile fallback)
        if ($request->has('proof_image_base64') && $request->input('proof_image_base64')) {
            $base64Image = $request->input('proof_image_base64');
            $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
            $filename = 'approval_' . $ledger->id . '_' . time() . '.jpg';
            \Storage::disk('public')->put('approval_proofs/' . $filename, $image);
            return 'approval_proofs/' . $filename;
        }

        return null;
    }
}

