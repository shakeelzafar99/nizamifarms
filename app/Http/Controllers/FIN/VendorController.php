<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\VendorModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\BusinessUnitModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VendorController extends Controller
{
    /**
     * Display vendor list
     */
    public function index(Request $request)
    {
        $query = VendorModel::with(['account', 'defaultPaymentSource', 'businessUnit']);

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('vendor_name', 'LIKE', "%{$search}%")
                  ->orWhere('vendor_contact', 'LIKE', "%{$search}%")
                  ->orWhere('vendor_email', 'LIKE', "%{$search}%");
        }

        // Default to active vendors only
        $query->where('is_active', 1);

        // ⭐ Filter by business unit — defaults to BU 1 (Nizami Farms) unless explicitly set
        // Use "all" to see all business units, or a specific BU ID
        $buFilter = $request->input('business_unit_id');
        if ($buFilter === null && !$request->has('business_unit_id')) {
            // No filter param at all → default to BU 1 (Nizami Farms) for web views
            // For API requests, respect the absence (mobile passes its own filter)
            if (!$request->expectsJson() && !$request->is('api/*')) {
                $buFilter = '1';
            }
        }
        if ($buFilter && $buFilter !== 'all') {
            $query->where('business_unit_id', $buFilter);
        }

        $vendors = $query->orderBy('vendor_name', 'asc')->paginate(20);

        // Calculate total balance for all active vendors (respect BU filter)
        $totalBalanceQuery = VendorModel::with('account')
            ->where('is_active', 1);
        if ($buFilter && $buFilter !== 'all') {
            $totalBalanceQuery->where('business_unit_id', $buFilter);
        }
        $totalBalance = $totalBalanceQuery->get()
            ->sum(function($vendor) {
                return $vendor->account ? $vendor->account->current_balance : 0;
            });

        // Return JSON for API requests
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'vendors' => $vendors->items(),
                'total_balance' => $totalBalance,
                'pagination' => [
                    'current_page' => $vendors->currentPage(),
                    'last_page' => $vendors->lastPage(),
                    'per_page' => $vendors->perPage(),
                    'total' => $vendors->total()
                ]
            ]);
        }

        // ⭐ Get business units for dropdown - filtered by user's access
        $businessUnits = AccountModel::getUserAccessibleBusinessUnits();
        
        // ⭐ Get accessible company accounts for payment source dropdown
        $accessibleCompanyAccounts = AccountModel::getAccessibleCompanyAccounts();
        
        // ⭐ Get user's default business unit ID
        $userDefaultBuId = AccountModel::getUserDefaultBusinessUnitId();
        
        return view('fin.vendor.index', compact('vendors', 'totalBalance', 'businessUnits', 'accessibleCompanyAccounts', 'userDefaultBuId'));
    }

    /**
     * Get monthly purchase/payment summary for vendors in a business unit.
     * Used by Khaas mode mobile screen to show total purchases & payments for a month.
     *
     * GET /vendors/monthly-summary?business_unit_id=X&month=YYYY-MM
     */
    public function monthlySummary(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            $month = $request->input('month', now()->format('Y-m')); // Default: current month

            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id is required'], 400);
            }

            // Parse month into date range
            $startDate = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // Get all active vendors for this BU
            $vendors = VendorModel::with('account')
                ->where('is_active', 1)
                ->where('business_unit_id', $businessUnitId)
                ->orderBy('vendor_name')
                ->get();

            $vendorPurchases = [];
            $vendorPayments = [];
            $totalPurchases = 0;
            $totalPayments = 0;

            foreach ($vendors as $vendor) {
                if (!$vendor->account) continue;

                $purchases = LedgerModel::where('to_account_id', $vendor->account->id)
                    ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                    ->whereBetween('transaction_date', [$startDate, $endDate])
                    ->sum('amount');

                $payments = LedgerModel::where('to_account_id', $vendor->account->id)
                    ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                    ->whereBetween('transaction_date', [$startDate, $endDate])
                    ->sum('amount');

                if ($purchases > 0) {
                    $vendorPurchases[] = [
                        'vendor_id' => $vendor->id,
                        'vendor_name' => $vendor->vendor_name,
                        'amount' => round($purchases, 2),
                    ];
                    $totalPurchases += $purchases;
                }

                if ($payments > 0) {
                    $vendorPayments[] = [
                        'vendor_id' => $vendor->id,
                        'vendor_name' => $vendor->vendor_name,
                        'amount' => round($payments, 2),
                    ];
                    $totalPayments += $payments;
                }
            }

            // Sort by amount descending
            usort($vendorPurchases, fn($a, $b) => $b['amount'] <=> $a['amount']);
            usort($vendorPayments, fn($a, $b) => $b['amount'] <=> $a['amount']);

            // ⭐ Get the BU's default expense/fund account balance
            // Same logic as expenses: for non-NF BUs use getBuDefaultExpenseAccount, for NF use EXP_FUND
            $fundBalance = null;
            $fundAccountName = null;
            if ((int) $businessUnitId !== 1) {
                $fundAccount = \App\Models\FIN\ConfigModel::getBuDefaultExpenseAccount((int) $businessUnitId);
            } else {
                $fundAccount = \App\Models\FIN\ConfigModel::getExpenseFundingAccount()
                    ?? AccountModel::where('account_code', 'EXP_FUND')->first();
            }
            if ($fundAccount) {
                $fundBalance = round((float) $fundAccount->current_balance, 2);
                $fundAccountName = $fundAccount->account_name;
            }

            return response()->json([
                'success' => true,
                'month' => $month,
                'total_purchases' => round($totalPurchases, 2),
                'total_payments' => round($totalPayments, 2),
                'vendor_purchases' => $vendorPurchases,
                'vendor_payments' => $vendorPayments,
                'fund_balance' => $fundBalance,
                'fund_account_name' => $fundAccountName,
            ]);

        } catch (\Exception $e) {
            Log::error("Error fetching vendor monthly summary: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching summary: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show create vendor form
     */
    public function create()
    {
        return view('fin.vendor.create');
    }

    /**
     * Store new vendor
     */
    public function store(Request $request)
    {
        $request->validate([
            'vendor_name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\s\-\_\.\(\)]+$/'],
            'contact_person' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'default_purchase_method' => 'required|in:by_weight,by_total',
            'default_payment_source_id' => 'nullable|exists:t_fin_accounts,id',
            'opening_balance' => 'nullable|numeric|min:0',
            'business_unit_id' => 'nullable|exists:t_fin_business_units,id'
        ], [
            'vendor_name.regex' => 'Vendor name can only contain letters, numbers, spaces, hyphens (-), underscores (_), dots (.), and parentheses.'
        ]);

        try {
            DB::beginTransaction();

            // Create vendor account
            $account = AccountModel::createVendorAccount($request->vendor_name);

            // Set opening balance if provided
            if ($request->opening_balance && $request->opening_balance > 0) {
                $account->opening_balance = $request->opening_balance;
                $account->current_balance = $request->opening_balance;
                $account->save();

                // Create opening balance ledger entry
                $openingEquity = AccountModel::getByCode('EQUITY_OPENING');
                if ($openingEquity) {
                    LedgerModel::create([
                        'transaction_date' => now(),
                        'transaction_type' => LedgerModel::TYPE_OPENING_BALANCE,
                        'description' => "Opening balance for vendor: {$request->vendor_name}",
                        'from_account_id' => $openingEquity->id,
                        'to_account_id' => $account->id,
                        'amount' => $request->opening_balance,
                        'mode' => null,
                        'approval_status' => LedgerModel::STATUS_APPROVED,
                        'business_unit_id' => $request->business_unit_id ?? 1, // ⭐ Use selected BU
                        'created_by' => auth()->id()
                    ]);
                }
            }

            // Generate vendor code
            $vendorCode = 'VEN_' . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $request->vendor_name));
            $vendorCode = substr($vendorCode, 0, 50);
            
            // Create vendor
            $vendor = VendorModel::create([
                'vendor_code' => $vendorCode,
                'vendor_name' => $request->vendor_name,
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'contact_email' => $request->contact_email,
                'default_purchase_method' => $request->default_purchase_method,
                'default_payment_source_id' => $request->default_payment_source_id,
                'account_id' => $account->id,
                'business_unit_id' => $request->business_unit_id ?? 1, // Default to Nizami Farms (id=1)
                'is_active' => 1,
                'created_by' => auth()->id()
            ]);

            DB::commit();

            return redirect()->route('fin.vendors.index')
                           ->with('success', 'Vendor created successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating vendor: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error creating vendor: ' . $e->getMessage());
        }
    }

    /**
     * Show vendor details
     */
    public function show(Request $request, $id)
    {
        $vendor = VendorModel::with(['account', 'defaultPaymentSource'])->findOrFail($id);
        
        // Get date range from request
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        
        // Get ledger transactions with optional date filter
        if ($dateFrom && $dateTo) {
            $ledger = $vendor->getLedger()
                ->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        } else {
            $ledger = $vendor->getLedger();
        }
        
        // Calculate running balance
        $runningBalance = $vendor->account ? $vendor->account->opening_balance : 0;
        $vendorAccountId = $vendor->account ? $vendor->account->id : null;
        
        $ledgerWithBalance = $ledger->map(function($transaction) use (&$runningBalance, $vendorAccountId) {
            // Check transaction type instead of account direction
            if ($transaction->transaction_type === 'vendor_purchase') {
                // Purchase - increases liability (vendor owes us or we owe vendor)
                $runningBalance += $transaction->amount;
            } elseif ($transaction->transaction_type === 'vendor_payment') {
                // Payment - decreases liability (we pay vendor)
                $runningBalance -= $transaction->amount;
            }
            
            $transaction->running_balance = $runningBalance;
            return $transaction;
        });

        // Get last payment info
        $lastPayment = null;
        if ($vendor->account) {
            $lastPayment = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                ->where('to_account_id', $vendor->account->id)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->first();
        }

        // Calculate week dates (Tuesday to Monday)
        // Week starts on Tuesday and ends on Monday
        // Tuesday purchases count towards the NEXT week (starting that Tuesday)
        $today = \Carbon\Carbon::now();
        $dayOfWeek = $today->dayOfWeek; // 0=Sunday, 1=Monday, 2=Tuesday, 3=Wednesday, etc.
        
        // Find the start of current week (last Tuesday)
        if ($dayOfWeek >= 2) { // Tuesday to Saturday
            $thisWeekStart = $today->copy()->subDays($dayOfWeek - 2)->startOfDay();
        } else { // Sunday or Monday
            $thisWeekStart = $today->copy()->subDays($dayOfWeek + 5)->startOfDay();
        }
        
        $thisWeekEnd = $thisWeekStart->copy()->addDays(6)->endOfDay(); // Next Monday 23:59:59
        $lastWeekStart = $thisWeekStart->copy()->subWeek()->startOfDay();
        $lastWeekEnd = $thisWeekStart->copy()->subDay()->endOfDay();

        // Get purchases for this week and last week
        $purchasesThisWeek = 0;
        $purchasesLastWeek = 0;
        
        if ($vendor->account) {
            $purchasesThisWeek = LedgerModel::where('to_account_id', $vendor->account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereBetween('transaction_date', [$thisWeekStart, $thisWeekEnd])
                ->sum('amount');
            
            $purchasesLastWeek = LedgerModel::where('to_account_id', $vendor->account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
                ->whereBetween('transaction_date', [$lastWeekStart, $lastWeekEnd])
                ->sum('amount');
        }

        // Get last 5 payments
        $lastFivePayments = [];
        if ($vendor->account) {
            $lastFivePayments = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                ->where('to_account_id', $vendor->account->id)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
        }

        // Get filtered purchases and payments based on date range
        $filteredPurchases = 0;
        $filteredPayments = 0;
        
        if ($vendor->account) {
            $purchaseQuery = LedgerModel::where('to_account_id', $vendor->account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE);
            
            $paymentQuery = LedgerModel::where('to_account_id', $vendor->account->id)
                ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT);
            
            if ($dateFrom && $dateTo) {
                $purchaseQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
                $paymentQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
            }
            
            $filteredPurchases = $purchaseQuery->sum('amount');
            $filteredPayments = $paymentQuery->sum('amount');
        }
        
        // If no filter applied, use total values
        if (!$dateFrom && !$dateTo) {
            $filteredPurchases = $vendor->getTotalPurchases();
            $filteredPayments = $vendor->getTotalPayments();
        }

        // Get summary
        $summary = [
            'current_balance' => $vendor->getBalance(),
            'last_payment_date' => $lastPayment ? $lastPayment->transaction_date : null,
            'last_payment_amount' => $lastPayment ? $lastPayment->amount : null,
            'purchases_this_week' => $purchasesThisWeek,
            'purchases_last_week' => $purchasesLastWeek,
            'filtered_purchases' => $filteredPurchases,
            'filtered_payments' => $filteredPayments,
            'total_payments' => $vendor->getTotalPayments(),
            'last_five_payments' => $lastFivePayments
        ];

        // Group transactions by date for better organization
        $groupedTransactions = $ledgerWithBalance->groupBy(function($transaction) {
            return $transaction->transaction_date ? $transaction->transaction_date->format('Y-m-d') : 'unknown';
        })->sortKeysDesc(); // Sort dates descending (latest first)
        
        // Calculate daily summaries
        $dailySummaries = [];
        foreach ($groupedTransactions as $date => $transactions) {
            $purchases = 0;
            $payments = 0;
            $endBalance = 0;
            
            foreach ($transactions as $txn) {
                if ($txn->transaction_type === 'vendor_purchase') {
                    $purchases += $txn->amount;
                }
                if ($txn->transaction_type === 'vendor_payment') {
                    $payments += $txn->amount;
                }
                $endBalance = $txn->running_balance; // Last transaction's balance
            }
            
            $dailySummaries[$date] = [
                'purchases' => $purchases,
                'payments' => $payments,
                'net' => $purchases - $payments,
                'end_balance' => $endBalance,
                'transaction_count' => $transactions->count()
            ];
        }
        
        // Get expand preference from session (default: collapsed)
        $expandAll = session('vendor_transactions_expand_all', false);
        
        // Return JSON for API requests
        if ($request->expectsJson() || $request->is('api/*')) {
            // Format grouped transactions for API
            $formattedTransactions = [];
            foreach ($groupedTransactions as $date => $transactions) {
                $formattedTransactions[$date] = [
                    'transactions' => $transactions->toArray(),
                    'summary' => $dailySummaries[$date] ?? []
                ];
            }
            
            return response()->json([
                'success' => true,
                'vendor' => $vendor,
                'grouped_transactions' => $formattedTransactions,
                'summary' => $summary
            ]);
        }
        
        // ⭐ Get accessible company accounts for payment source dropdown
        $accessibleCompanyAccounts = AccountModel::getAccessibleCompanyAccounts();

        // Receiving banks (with computed balances) for the mandatory bank picker
        // shown when an ONLINE payment source is chosen.
        $bankBalances = app(\App\Services\FIN\BankBalanceService::class)->balancesByBank();
        $receivingBanks = \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()
            ->get(['id', 'name', 'short_code', 'color_hex', 'opening_balance'])
            ->map(fn ($acc) => [
                'id' => $acc->id,
                'name' => $acc->name,
                'short_code' => $acc->short_code,
                'color_hex' => $acc->color_hex,
                'balance' => $bankBalances[(int) $acc->id]['balance'] ?? (float) $acc->opening_balance,
            ])->values();

        return view('fin.vendor.show', compact('vendor', 'ledgerWithBalance', 'groupedTransactions', 'dailySummaries', 'summary', 'expandAll', 'accessibleCompanyAccounts', 'receivingBanks'));
    }

    /**
     * Show edit form
     */
    public function edit($id)
    {
        $vendor = VendorModel::findOrFail($id);
        
        return view('fin.vendor.edit', compact('vendor'));
    }

    /**
     * Update vendor
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'vendor_name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\s\-\_\.\(\)]+$/'],
            'contact_person' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'default_purchase_method' => 'required|in:by_weight,by_total',
            'default_payment_source_id' => 'nullable|exists:t_fin_accounts,id',
            'business_unit_id' => 'nullable|exists:t_fin_business_units,id'
        ], [
            'vendor_name.regex' => 'Vendor name can only contain letters, numbers, spaces, hyphens (-), underscores (_), dots (.), and parentheses.'
        ]);

        try {
            $vendor = VendorModel::findOrFail($id);

            $vendor->update([
                'vendor_name' => $request->vendor_name,
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'contact_email' => $request->contact_email,
                'default_purchase_method' => $request->default_purchase_method,
                'default_payment_source_id' => $request->default_payment_source_id,
                'business_unit_id' => $request->business_unit_id ?? $vendor->business_unit_id ?? 1,
                'updated_by' => auth()->id()
            ]);

            return redirect()->route('fin.vendors.index')
                           ->with('success', 'Vendor updated successfully!');

        } catch (\Exception $e) {
            Log::error("Error updating vendor: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error updating vendor: ' . $e->getMessage());
        }
    }

    /**
     * Toggle vendor active/inactive status
     */
    public function toggleStatus($id)
    {
        try {
            $vendor = VendorModel::findOrFail($id);
            
            $vendor->is_active = !$vendor->is_active;
            $vendor->updated_by = auth()->id();
            $vendor->save();

            return response()->json([
                'success' => true,
                'message' => 'Vendor status updated successfully',
                'is_active' => $vendor->is_active
            ]);

        } catch (\Exception $e) {
            Log::error('Vendor toggle status error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vendor status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete vendor (only if balance is zero)
     */
    public function destroy($id)
    {
        try {
            $vendor = VendorModel::with('account')->findOrFail($id);

            // Safety check: Only allow deletion if balance is zero
            if ($vendor->account && $vendor->account->current_balance != 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete vendor with non-zero balance. Current balance: Rs. ' . number_format($vendor->account->current_balance, 2)
                ], 400);
            }

            // Check if there are any ledger transactions
            $hasTransactions = LedgerModel::where(function($q) use ($vendor) {
                $q->where('from_account_id', $vendor->account_id)
                  ->orWhere('to_account_id', $vendor->account_id);
            })->exists();

            if ($hasTransactions) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete vendor with transaction history. Consider marking as inactive instead.'
                ], 400);
            }

            DB::beginTransaction();

            // Store account ID before deleting vendor
            $accountId = $vendor->account_id;

            // Set account_id to null first to avoid foreign key constraint
            $vendor->account_id = null;
            $vendor->save();

            // Delete the vendor
            $vendor->delete();

            // Delete the vendor account
            if ($accountId) {
                $account = AccountModel::find($accountId);
                if ($account) {
                    $account->delete();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Vendor deletion error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete vendor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record purchase
     */
    public function recordPurchase(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
            'bill_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120' // Max 5MB
        ]);

        try {
            DB::beginTransaction();

            $vendor = VendorModel::with('account')->findOrFail($id);
            $purchaseAccount = AccountModel::getByCode('EXP_PURCHASES');

            if (!$purchaseAccount) {
                throw new \Exception("Purchase expense account not found");
            }

            // Handle bill image upload (web or mobile)
            $billImagePath = $this->handleImageUpload($request, 'bill_image', $vendor);

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
                'description' => $request->description ?: "Purchase from {$vendor->vendor_name}",
                'from_account_id' => $purchaseAccount->id,
                'to_account_id' => $vendor->account->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'bill_image' => $billImagePath,
                'business_unit_id' => $vendor->business_unit_id ?? 1, // ⭐ Use vendor's BU
                'created_by' => auth()->id()
            ]);

            // Apply via the canonical engine (vendor_purchase: purchases/expense +, vendor owed +),
            // row-locked; sets balance_updated. Same net move as the old inline code.
            (new \App\Services\FIN\BalancePostingService())->apply($ledger);

            DB::commit();

            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Purchase recorded successfully!'
                ]);
            }

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', 'Purchase recorded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording purchase: " . $e->getMessage());
            
            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error recording purchase: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->withInput()
                       ->with('error', 'Error recording purchase: ' . $e->getMessage());
        }
    }

    /**
     * Record payment
     */
    public function recordPayment(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            // Which of OUR banks an ONLINE vendor payment is made from —
            // required below when the paying account is a bank.
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
            'posted_date' => 'nullable|date', // Date when entry is posted to ledger
            'receipt_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120' // Receipt image
        ]);

        try {
            DB::beginTransaction();

            $vendor = VendorModel::with('account')->findOrFail($id);

            // Get payment source account (user selection or default to NF Cash)
            if ($request->payment_source_account_id) {
                $paymentAccount = AccountModel::findOrFail($request->payment_source_account_id);
            } else {
                $paymentAccount = AccountModel::getByCode('NF_CASH');
            }

            if (!$paymentAccount) {
                throw new \Exception("Payment source account not found");
            }

            // ONLINE vendor payment → the specific bank is mandatory so per-bank
            // balances reconcile with the ONLINE account.
            $isOnlinePayment = $paymentAccount->account_category === AccountModel::CATEGORY_BANK;
            $vendorBankId = $isOnlinePayment ? $request->receiving_account_id : null;
            if ($isOnlinePayment && !$vendorBankId) {
                DB::rollBack();
                $msg = 'Select which bank this online payment is made from.';
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->withInput()->with('error', $msg);
            }

            // Check if payment amount exceeds vendor balance
            if ($request->amount > $vendor->getBalance()) {
                throw new \Exception("Payment amount cannot exceed vendor balance");
            }

            // Handle receipt image upload (web or mobile)
            $receiptImagePath = $this->handleImageUpload($request, 'receipt_image', $vendor);

            // Check approval configuration for vendor payments
            $vendorPaymentCategory = \App\Models\Request\RequestCategoryModel::getByCode('vendor_payment');
            $requiresApproval = false;
            
            if ($vendorPaymentCategory && $vendorPaymentCategory->approvalConfig) {
                // Check if any approval level is required
                $requiresApproval = $vendorPaymentCategory->approvalConfig->requires_level_1 || 
                                   $vendorPaymentCategory->approvalConfig->requires_level_2;
            }
            
            $approvalStatus = $requiresApproval ? LedgerModel::STATUS_PENDING : LedgerModel::STATUS_APPROVED;
            // Any bank-category paying account is an online movement (matches the
            // server-wide rule used by expenses / Bank Balances), not just the
            // account literally coded 'ONLINE'.
            $mode = $isOnlinePayment ? LedgerModel::MODE_ONLINE : LedgerModel::MODE_CASH;
            
            \Log::info("Vendor payment approval check", [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->vendor_name,
                'amount' => $request->amount,
                'payment_source' => $paymentAccount->account_name,
                'payment_source_code' => $paymentAccount->account_code,
                'requires_approval' => $requiresApproval,
                'approval_status' => $approvalStatus,
                'category_found' => $vendorPaymentCategory ? true : false,
                'config_exists' => $vendorPaymentCategory && $vendorPaymentCategory->approvalConfig ? true : false
            ]);

            // Create ledger entry
            // Dr Vendor Account (liability decreases) → Cr Payment Account (cash/bank decreases)
            // posted_date: date when entry is posted to ledger (for balance calculations)
            // transaction_date: actual date of transaction (for vendor records)
            $postedDate = $request->posted_date ?? $request->transaction_date;
            
            // Always name the VENDOR in the description — a custom note is
            // appended, never substituted, so listings always show who was paid.
            $vendorPayDescription = "Payment to {$vendor->vendor_name}";
            if ($request->filled('description')) {
                $vendorPayDescription .= " — " . trim($request->description);
            }
            // Name the bank too, so the ledger listing shows at a glance which
            // bank the money left from.
            if ($vendorBankId) {
                $vendorBankShort = \App\Models\FIN\OnlineReceivingAccountModel::find($vendorBankId)?->short_code;
                if ($vendorBankShort) {
                    $vendorPayDescription .= " · via {$vendorBankShort}";
                }
            }

            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'posted_date' => $postedDate,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
                'description' => $vendorPayDescription,
                'from_account_id' => $paymentAccount->id,  // Money leaving from payment source
                'to_account_id' => $vendor->account->id,   // Money going to settle vendor liability
                'amount' => $request->amount,
                'mode' => $mode,
                // Which of OUR banks the online payment left from (null for cash)
                // — keeps per-bank balances reconciled.
                'receiving_account_id' => $vendorBankId,
                'approval_status' => $approvalStatus,
                'approval_date' => ($approvalStatus === LedgerModel::STATUS_APPROVED) ? now() : null,
                'business_unit_id' => $vendor->business_unit_id ?? 1, // ⭐ Use vendor's BU
                'approved_by' => ($approvalStatus === LedgerModel::STATUS_APPROVED) ? auth()->id() : null,
                'bill_image' => $receiptImagePath, // Store receipt image in bill_image field
                'created_by' => auth()->id(),
                'comments' => "Paid from: {$paymentAccount->account_name}"
            ]);

            // Apply via the canonical engine when approved (vendor_payment: vendor owed −, till/bank −),
            // row-locked; sets balance_updated. A pending payment stays flag=0 and is applied later by
            // the online approval (approve() → engine). Same net move as the old inline code.
            if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
                (new \App\Services\FIN\BalancePostingService())->apply($ledger);
            }

            DB::commit();

            $message = ($approvalStatus === LedgerModel::STATUS_PENDING) 
                ? 'Payment recorded and pending approval!' 
                : 'Payment recorded successfully!';

            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => $message
                ]);
            }

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording payment: " . $e->getMessage());
            
            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error recording payment: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->withInput()
                       ->with('error', 'Error recording payment: ' . $e->getMessage());
        }
    }

    /**
     * Record weighted purchase (with line items)
     */
    public function recordWeightedPurchase(Request $request, $id)
    {
        $request->validate([
            'transaction_date' => 'required|date',
            'description' => 'nullable|string|max:500',
            'bill_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // Max 5MB
            'adjustment_amount' => 'nullable|numeric', // Can be positive or negative
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:t_fin_vendor_products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.rate' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:50',
            'items.*.product_name' => 'required|string|max:255'
        ]);

        try {
            DB::beginTransaction();

            $vendor = VendorModel::with('account')->findOrFail($id);
            $purchaseAccount = AccountModel::getByCode('EXP_PURCHASES');

            if (!$purchaseAccount) {
                throw new \Exception("Purchase expense account not found");
            }

            // Handle bill image upload (web or mobile) - use helper method
            $billImagePath = $this->handleImageUpload($request, 'bill_image', $vendor);

            // Calculate grand total from line items
            $itemsTotal = 0;
            $itemsSummary = [];
            
            foreach ($request->items as $item) {
                $lineTotal = $item['quantity'] * $item['rate'];
                $itemsTotal += $lineTotal;
                
                $itemsSummary[] = "{$item['product_name']} ({$item['quantity']} {$item['unit']} @ Rs.{$item['rate']})";
            }

            // Apply adjustment amount (can be positive or negative)
            $adjustmentAmount = floatval($request->adjustment_amount ?? 0);
            $grandTotal = $itemsTotal + $adjustmentAmount;

            // Create description
            $description = $request->description ?? "Weighted purchase with " . count($request->items) . " items";
            if ($adjustmentAmount != 0) {
                $adjustmentLabel = $adjustmentAmount > 0 ? '+' : '';
                $description .= " (Adjustment: {$adjustmentLabel}" . number_format($adjustmentAmount, 2) . ")";
            }
            $comments = implode(', ', $itemsSummary);

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
                'description' => $description,
                'from_account_id' => $purchaseAccount->id,
                'to_account_id' => $vendor->account->id,
                'amount' => $grandTotal,
                'adjustment_amount' => $adjustmentAmount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'bill_image' => $billImagePath,
                'business_unit_id' => $vendor->business_unit_id ?? 1, // ⭐ Use vendor's BU
                'created_by' => auth()->id(),
                'comments' => $comments
            ]);

            // Create line items
            foreach ($request->items as $item) {
                \App\Models\FIN\VendorPurchaseItemModel::create([
                    'ledger_id' => $ledger->id,
                    'vendor_product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'rate_per_unit' => $item['rate'],
                    'line_total' => $item['quantity'] * $item['rate']
                ]);
            }

            // Apply via the canonical engine (vendor_purchase: purchases +, vendor owed +), row-locked;
            // sets balance_updated. Same net move as the old inline code.
            (new \App\Services\FIN\BalancePostingService())->apply($ledger);

            DB::commit();

            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Weighted purchase recorded successfully! Total: Rs. ' . number_format($grandTotal, 2)
                ]);
            }

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', 'Weighted purchase recorded successfully! Total: Rs. ' . number_format($grandTotal, 2));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording weighted purchase: " . $e->getMessage());
            
            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error recording weighted purchase: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->withInput()
                       ->with('error', 'Error recording weighted purchase: ' . $e->getMessage());
        }
    }

    /**
     * Generate vendor report with daily summary
     */
    public function getReport(Request $request)
    {
        try {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $vendorId = $request->input('vendor_id');
            $showPayments = $request->input('show_payments', '1') === '1'; // Default to showing payments

            if (!$dateFrom || !$dateTo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide both start and end dates'
                ], 400);
            }

            // Build vendor query
            $vendorQuery = VendorModel::with('account')->where('is_active', 1);
            
            if ($vendorId) {
                $vendorQuery->where('id', $vendorId);
            }
            
            $vendors = $vendorQuery->orderBy('vendor_name')->get();

            $reportData = [];
            $grandTotalPurchases = 0;
            $grandTotalPayments = 0;

            foreach ($vendors as $vendor) {
                if (!$vendor->account) {
                    continue;
                }

                // Get all transactions for this vendor in the date range
                $transactionTypes = [LedgerModel::TYPE_VENDOR_PURCHASE];
                if ($showPayments) {
                    $transactionTypes[] = LedgerModel::TYPE_VENDOR_PAYMENT;
                }
                
                $transactions = LedgerModel::with('fromAccount')
                    ->where(function($q) use ($vendor) {
                        $q->where('from_account_id', $vendor->account->id)
                          ->orWhere('to_account_id', $vendor->account->id);
                    })
                    ->whereBetween('transaction_date', [$dateFrom, $dateTo])
                    ->whereIn('transaction_type', $transactionTypes)
                    ->orderBy('transaction_date')
                    ->orderBy('created_at')
                    ->get();

                if ($transactions->isEmpty()) {
                    continue; // Skip vendors with no transactions in this period
                }

                // Group transactions by date
                $dailySummary = [];
                $vendorTotalPurchases = 0;
                $vendorTotalPayments = 0;

                foreach ($transactions as $txn) {
                    // Format date with day of week: "Nov 7, 2025 Friday"
                    $date = $txn->transaction_date->format('M j, Y l');
                    
                    if (!isset($dailySummary[$date])) {
                        $dailySummary[$date] = [
                            'date' => $date,
                            'total_purchases' => 0,
                            'total_payments' => 0,
                            'total_payments_online' => 0,
                            'total_payments_cash' => 0,
                            'transactions' => []
                        ];
                    }

                    $isPurchase = $txn->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE;
                    $amount = $txn->amount;

                    // Fetch line items for weighted purchases
                    $lineItems = [];
                    if ($isPurchase) {
                        $lineItems = \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $txn->id)
                            ->get()
                            ->map(function($item) {
                                return [
                                    'product_name' => $item->product_name,
                                    'quantity' => $item->quantity,
                                    'unit' => $item->unit,
                                    'rate_per_unit' => $item->rate_per_unit,
                                    'line_total' => $item->line_total
                                ];
                            })
                            ->toArray();
                    }

                    // Determine payment mode for payments (Online or Cash)
                    $paymentMode = null;
                    if (!$isPurchase) {
                        // Load the fromAccount to check account_code
                        $fromAccount = $txn->fromAccount;
                        if ($fromAccount) {
                            // If account_code is 'ONLINE', it's Online, otherwise it's Cash
                            $paymentMode = ($fromAccount->account_code === 'ONLINE') ? 'Online' : 'Cash';
                        }
                    }

                    $dailySummary[$date]['transactions'][] = [
                        'transaction_id' => $txn->transaction_id,
                        'type' => $isPurchase ? 'purchase' : 'payment',
                        'amount' => $amount,
                        'adjustment_amount' => $txn->adjustment_amount ?? 0,
                        'description' => $txn->description,
                        'line_items' => $lineItems,
                        'payment_mode' => $paymentMode
                    ];

                    if ($isPurchase) {
                        $dailySummary[$date]['total_purchases'] += $amount;
                        $vendorTotalPurchases += $amount;
                    } else {
                        $dailySummary[$date]['total_payments'] += $amount;
                        $vendorTotalPayments += $amount;
                        
                        // Track payment mode totals
                        if ($paymentMode === 'Online') {
                            $dailySummary[$date]['total_payments_online'] += $amount;
                        } else {
                            $dailySummary[$date]['total_payments_cash'] += $amount;
                        }
                    }
                }

                $grandTotalPurchases += $vendorTotalPurchases;
                $grandTotalPayments += $vendorTotalPayments;

                $reportData[] = [
                    'vendor_name' => $vendor->vendor_name,
                    'contact_person' => $vendor->contact_person,
                    'contact_phone' => $vendor->contact_phone,
                    'current_balance' => $vendor->account->current_balance,
                    'total_purchases' => $vendorTotalPurchases,
                    'total_payments' => $vendorTotalPayments,
                    'daily_summary' => array_values($dailySummary)
                ];
            }

            return response()->json([
                'success' => true,
                'report' => [
                    'date_from' => \Carbon\Carbon::parse($dateFrom)->format('M j, Y'),
                    'date_to' => \Carbon\Carbon::parse($dateTo)->format('M j, Y'),
                    'vendors' => $reportData,
                    'grand_total' => [
                        'total_purchases' => $grandTotalPurchases,
                        'total_payments' => $grandTotalPayments
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error generating vendor report: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error generating report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete vendor transaction (purchase or payment)
     * Reverses all ledger entries and balances
     */
    public function deleteTransaction($transactionId)
    {
        try {
            DB::beginTransaction();

            // Find the transaction
            $transaction = LedgerModel::findOrFail($transactionId);
            
            // Verify it's a vendor transaction
            if (!in_array($transaction->transaction_type, [
                LedgerModel::TYPE_VENDOR_PURCHASE,
                LedgerModel::TYPE_VENDOR_PAYMENT
            ])) {
                throw new \Exception("This is not a vendor transaction");
            }

            // Get the vendor account - for both purchases and payments, vendor is to_account_id
            $vendorAccount = AccountModel::find($transaction->to_account_id);
            if (!$vendorAccount) {
                Log::error("Vendor account not found. Transaction ID: {$transactionId}, to_account_id: {$transaction->to_account_id}");
                throw new \Exception("Vendor account not found (ID: {$transaction->to_account_id})");
            }
            
            // Verify it's actually a vendor account (allow both 'vendor' and 'vendor_payable')
            $validCategories = ['vendor', 'vendor_payable'];
            if ($vendorAccount->account_category && !in_array($vendorAccount->account_category, $validCategories)) {
                Log::error("Account is not a vendor account. Category: {$vendorAccount->account_category}");
                throw new \Exception("This is not a vendor account (Category: {$vendorAccount->account_category})");
            }

            $isPurchase = $transaction->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE;

            // Reverse via the canonical engine — exact inverse of the vendor posting (purchase → both
            // legs −, payment → both legs +). Self-guards on balance_updated and is idempotent.
            // REQUIRES the vendor flag backfill (LEDGER-L3-VENDOR-FLAG-BACKFILL.sql) to have run first:
            // legacy vendor rows were applied but carry balance_updated=0, so without the backfill the
            // engine would treat them as un-applied and SKIP the reversal. After the backfill every
            // applied vendor row is flag=1 and reverses correctly. The row is hard-deleted below.
            (new \App\Services\FIN\BalancePostingService())->reverse($transaction);

            // Delete associated line items if it's a weighted purchase
            if ($isPurchase) {
                \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $transaction->id)->delete();
            }

            // Delete the transaction
            $transaction->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error deleting vendor transaction: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update vendor transaction
     * Allows editing date, amount, description, and line items
     */
    public function updateTransaction(Request $request, $transactionId)
    {
        $request->validate([
            'transaction_date' => 'required|date',
            'posted_date' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0.01',
            'adjustment_amount' => 'nullable|numeric', // Can be positive or negative
            'description' => 'nullable|string|max:500',
            'bill_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:t_fin_vendor_products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.rate' => 'required_with:items|numeric|min:0.01',
            'items.*.unit' => 'required_with:items|string|max:50',
            'items.*.product_name' => 'required_with:items|string|max:255'
        ]);

        try {
            DB::beginTransaction();

            $transaction = LedgerModel::findOrFail($transactionId);
            
            // Verify it's a vendor transaction
            if (!in_array($transaction->transaction_type, [
                LedgerModel::TYPE_VENDOR_PURCHASE,
                LedgerModel::TYPE_VENDOR_PAYMENT
            ])) {
                throw new \Exception("This is not a vendor transaction");
            }

            $isPurchase = $transaction->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE;
            $oldAmount = $transaction->amount;
            
            // Check if this is a weighted purchase
            $hasLineItems = \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $transaction->id)->exists();

            // Handle weighted purchase updates
            if ($hasLineItems && $request->has('items')) {
                // Delete old line items
                \App\Models\FIN\VendorPurchaseItemModel::where('ledger_id', $transaction->id)->delete();
                
                // Calculate new total from items
                $itemsTotal = 0;
                foreach ($request->items as $item) {
                    $lineTotal = $item['quantity'] * $item['rate'];
                    $itemsTotal += $lineTotal;
                    
                    // Create new line item
                    \App\Models\FIN\VendorPurchaseItemModel::create([
                        'ledger_id' => $transaction->id,
                        'vendor_product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'rate_per_unit' => $item['rate'],
                        'line_total' => $lineTotal
                    ]);
                }
                
                // Apply adjustment amount (can be positive or negative)
                $adjustmentAmount = floatval($request->adjustment_amount ?? 0);
                $newAmount = $itemsTotal + $adjustmentAmount;
                
                $transaction->amount = $newAmount;
                $transaction->adjustment_amount = $adjustmentAmount;
            } elseif ($request->has('amount')) {
                // Simple transaction - use provided amount
                $newAmount = $request->amount;
                $transaction->amount = $newAmount;
            } else {
                $newAmount = $oldAmount;
            }

            // Update basic fields
            $transaction->transaction_date = $request->transaction_date;
            if ($request->has('posted_date')) {
                $transaction->posted_date = $request->posted_date;
            } elseif (!$transaction->posted_date) {
                // If no posted_date exists, default to transaction_date
                $transaction->posted_date = $request->transaction_date;
            }
            if ($request->has('description')) {
                $transaction->description = $request->description;
            }

            // Handle bill image update
            if ($request->hasFile('bill_image')) {
                // Delete old image if exists
                if ($transaction->bill_image && \Storage::disk('public')->exists($transaction->bill_image)) {
                    \Storage::disk('public')->delete($transaction->bill_image);
                }
                
                // Upload new image
                $file = $request->file('bill_image');
                $filename = 'vendor_bill_' . $transaction->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $billImagePath = $file->storeAs('vendor_bills', $filename, 'public');
                $transaction->bill_image = $billImagePath;
            }

            $transaction->save();

            // Update account balances if amount changed
            if ($oldAmount != $newAmount) {
                $amountDiff = $newAmount - $oldAmount;
                
                // Get accounts
                $vendorAccount = AccountModel::find($transaction->to_account_id);
                
                if ($isPurchase) {
                    // Update vendor balance
                    if ($vendorAccount) {
                        $vendorAccount->current_balance += $amountDiff;
                        $vendorAccount->save();
                    }
                    
                    // Update purchase account
                    $purchaseAccount = AccountModel::find($transaction->from_account_id);
                    if ($purchaseAccount) {
                        $purchaseAccount->current_balance += $amountDiff;
                        $purchaseAccount->save();
                    }
                } else {
                    // For payments, only update if approved
                    if ($transaction->approval_status === LedgerModel::STATUS_APPROVED) {
                        if ($vendorAccount) {
                            $vendorAccount->current_balance -= $amountDiff;
                            $vendorAccount->save();
                        }
                        
                        $paymentAccount = AccountModel::find($transaction->from_account_id);
                        if ($paymentAccount) {
                            $paymentAccount->current_balance -= $amountDiff;
                            $paymentAccount->save();
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating vendor transaction: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating transaction: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Toggle expand/collapse preference for vendor transactions
     */
    public function toggleExpandAll(Request $request)
    {
        $expandAll = $request->input('expand_all', false);
        session(['vendor_transactions_expand_all' => $expandAll]);
        
        return response()->json([
            'success' => true,
            'expand_all' => $expandAll
        ]);
    }

    /**
     * Helper method to handle image uploads from both web (multipart) and mobile (base64)
     */
    private function handleImageUpload(Request $request, $fieldName, $vendor)
    {
        // Check for traditional file upload (web)
        if ($request->hasFile($fieldName)) {
            $file = $request->file($fieldName);
            $filename = 'vendor_' . $vendor->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            return $file->storeAs('vendor_bills', $filename, 'public');
        }
        
        // Check for base64 upload (mobile)
        $base64Field = $fieldName . '_base64';
        if ($request->has($base64Field) && $request->input($base64Field)) {
            $base64Image = $request->input($base64Field);
            
            // Remove data:image prefix if present
            $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
            
            $filename = 'vendor_' . $vendor->id . '_' . time() . '.jpg';
            Storage::disk('public')->put('vendor_bills/' . $filename, $image);
            
            return 'vendor_bills/' . $filename;
        }
        
        return null;
    }
}

