<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\VendorModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorController extends Controller
{
    /**
     * Display vendor list
     */
    public function index(Request $request)
    {
        $query = VendorModel::with('account');

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('vendor_name', 'LIKE', "%{$search}%")
                  ->orWhere('vendor_contact', 'LIKE', "%{$search}%")
                  ->orWhere('vendor_email', 'LIKE', "%{$search}%");
        }

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', 1);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', 0);
            }
        }

        $vendors = $query->orderBy('vendor_name', 'asc')->paginate(20);

        // Calculate total balance for all active vendors
        $totalBalance = VendorModel::with('account')
            ->where('is_active', 1)
            ->get()
            ->sum(function($vendor) {
                return $vendor->account ? $vendor->account->current_balance : 0;
            });

        // Get last payment info for each vendor
        foreach ($vendors as $vendor) {
            if ($vendor->account) {
                // Get the last payment transaction for this vendor
                $lastPayment = LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                    ->where(function($q) use ($vendor) {
                        $q->where('from_account_id', $vendor->account->id)
                          ->orWhere('to_account_id', $vendor->account->id);
                    })
                    ->orderBy('transaction_date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                $vendor->last_payment_date = $lastPayment ? $lastPayment->transaction_date : null;
                $vendor->last_payment_amount = $lastPayment ? $lastPayment->amount : null;
            } else {
                $vendor->last_payment_date = null;
                $vendor->last_payment_amount = null;
            }
        }

        return view('fin.vendor.index', compact('vendors', 'totalBalance'));
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
            'opening_balance' => 'nullable|numeric|min:0'
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
                'account_id' => $account->id,
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
        $vendor = VendorModel::with('account')->findOrFail($id);
        
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
        
        return view('fin.vendor.show', compact('vendor', 'ledgerWithBalance', 'groupedTransactions', 'dailySummaries', 'summary', 'expandAll'));
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
            'default_purchase_method' => 'required|in:by_weight,by_total'
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

            // Handle bill image upload
            $billImagePath = null;
            if ($request->hasFile('bill_image')) {
                $file = $request->file('bill_image');
                $filename = 'vendor_' . $vendor->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $billImagePath = $file->storeAs('vendor_bills', $filename, 'public');
            }

            // Create ledger entry
            LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
                'description' => $request->description ?: "Purchase from {$vendor->vendor_name}",
                'from_account_id' => $purchaseAccount->id,
                'to_account_id' => $vendor->account->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'bill_image' => $billImagePath,
                'created_by' => auth()->id()
            ]);

            // Update balances
            $purchaseAccount->current_balance += $request->amount;
            $purchaseAccount->save();
            
            $vendor->account->current_balance += $request->amount;
            $vendor->account->save();

            DB::commit();

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', 'Purchase recorded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording purchase: " . $e->getMessage());
            
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
            'description' => 'nullable|string|max:500',
            'transaction_date' => 'required|date'
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

            // Check if payment amount exceeds vendor balance
            if ($request->amount > $vendor->getBalance()) {
                throw new \Exception("Payment amount cannot exceed vendor balance");
            }

            // Check approval configuration for vendor payments
            $vendorPaymentCategory = \App\Models\Request\RequestCategoryModel::getByCode('vendor_payment');
            $requiresApproval = false;
            
            if ($vendorPaymentCategory && $vendorPaymentCategory->approvalConfig) {
                // Check if any approval level is required
                $requiresApproval = $vendorPaymentCategory->approvalConfig->requires_level_1 || 
                                   $vendorPaymentCategory->approvalConfig->requires_level_2;
            }
            
            $approvalStatus = $requiresApproval ? LedgerModel::STATUS_PENDING : LedgerModel::STATUS_APPROVED;
            $mode = ($paymentAccount->account_code === 'ONLINE') ? LedgerModel::MODE_ONLINE : LedgerModel::MODE_CASH;
            
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
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
                'description' => $request->description ?? "Payment to {$vendor->vendor_name}",
                'from_account_id' => $paymentAccount->id,  // Money leaving from payment source
                'to_account_id' => $vendor->account->id,   // Money going to settle vendor liability
                'amount' => $request->amount,
                'mode' => $mode,
                'approval_status' => $approvalStatus,
                'approval_date' => ($approvalStatus === LedgerModel::STATUS_APPROVED) ? now() : null,
                'approved_by' => ($approvalStatus === LedgerModel::STATUS_APPROVED) ? auth()->id() : null,
                'created_by' => auth()->id(),
                'comments' => "Paid from: {$paymentAccount->account_name}"
            ]);

            // Update balances (only if approved)
            if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
                $vendor->account->current_balance -= $request->amount;
                $vendor->account->save();
                
                $paymentAccount->current_balance -= $request->amount;
                $paymentAccount->save();
            }

            DB::commit();

            $message = ($approvalStatus === LedgerModel::STATUS_PENDING) 
                ? 'Payment recorded and pending approval!' 
                : 'Payment recorded successfully!';

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording payment: " . $e->getMessage());
            
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

            // Handle bill image upload
            $billImagePath = null;
            if ($request->hasFile('bill_image')) {
                Log::info('Bill image file detected in weighted purchase');
                $file = $request->file('bill_image');
                $filename = 'vendor_' . $vendor->id . '_weighted_' . time() . '.' . $file->getClientOriginalExtension();
                $billImagePath = $file->storeAs('vendor_bills', $filename, 'public');
                Log::info('Bill image saved to: ' . $billImagePath);
            } else {
                Log::info('No bill image file in weighted purchase request');
            }

            // Calculate grand total from line items
            $grandTotal = 0;
            $itemsSummary = [];
            
            foreach ($request->items as $item) {
                $lineTotal = $item['quantity'] * $item['rate'];
                $grandTotal += $lineTotal;
                
                $itemsSummary[] = "{$item['product_name']} ({$item['quantity']} {$item['unit']} @ Rs.{$item['rate']})";
            }

            // Create description
            $description = $request->description ?? "Weighted purchase with " . count($request->items) . " items";
            $comments = implode(', ', $itemsSummary);

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
                'description' => $description,
                'from_account_id' => $purchaseAccount->id,
                'to_account_id' => $vendor->account->id,
                'amount' => $grandTotal,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'bill_image' => $billImagePath,
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

            // Update balances
            $purchaseAccount->current_balance += $grandTotal;
            $purchaseAccount->save();
            
            $vendor->account->current_balance += $grandTotal;
            $vendor->account->save();

            DB::commit();

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', 'Weighted purchase recorded successfully! Total: Rs. ' . number_format($grandTotal, 2));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error recording weighted purchase: " . $e->getMessage());
            
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
                
                $transactions = LedgerModel::where(function($q) use ($vendor) {
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
                    $date = $txn->transaction_date->format('M j, Y');
                    
                    if (!isset($dailySummary[$date])) {
                        $dailySummary[$date] = [
                            'date' => $date,
                            'total_purchases' => 0,
                            'total_payments' => 0,
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

                    $dailySummary[$date]['transactions'][] = [
                        'transaction_id' => $txn->transaction_id,
                        'type' => $isPurchase ? 'purchase' : 'payment',
                        'amount' => $amount,
                        'description' => $txn->description,
                        'line_items' => $lineItems
                    ];

                    if ($isPurchase) {
                        $dailySummary[$date]['total_purchases'] += $amount;
                        $vendorTotalPurchases += $amount;
                    } else {
                        $dailySummary[$date]['total_payments'] += $amount;
                        $vendorTotalPayments += $amount;
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
            $amount = $transaction->amount;

            // Reverse account balances
            if ($isPurchase) {
                // Reverse purchase: decrease vendor balance and purchase account balance
                $vendorAccount->current_balance -= $amount;
                $vendorAccount->save();

                // Get and update purchase account
                $purchaseAccount = AccountModel::find($transaction->from_account_id);
                if ($purchaseAccount) {
                    $purchaseAccount->current_balance -= $amount;
                    $purchaseAccount->save();
                }
            } else {
                // Reverse payment: increase vendor balance and payment source balance
                // Only if the transaction was approved
                if ($transaction->approval_status === LedgerModel::STATUS_APPROVED) {
                    $vendorAccount->current_balance += $amount;
                    $vendorAccount->save();

                    // Get and update payment source account
                    $paymentAccount = AccountModel::find($transaction->from_account_id);
                    if ($paymentAccount) {
                        $paymentAccount->current_balance += $amount;
                        $paymentAccount->save();
                    }
                }
            }

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
            'amount' => 'nullable|numeric|min:0.01',
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
                $newAmount = 0;
                foreach ($request->items as $item) {
                    $lineTotal = $item['quantity'] * $item['rate'];
                    $newAmount += $lineTotal;
                    
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
                
                $transaction->amount = $newAmount;
            } elseif ($request->has('amount')) {
                // Simple transaction - use provided amount
                $newAmount = $request->amount;
                $transaction->amount = $newAmount;
            } else {
                $newAmount = $oldAmount;
            }

            // Update basic fields
            $transaction->transaction_date = $request->transaction_date;
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
}

