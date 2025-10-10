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

        return view('fin.vendor.index', compact('vendors'));
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
            'vendor_name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric|min:0'
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
    public function show($id)
    {
        $vendor = VendorModel::with('account')->findOrFail($id);
        
        // Get ledger transactions
        $ledger = $vendor->getLedger();
        
        // Calculate running balance
        $runningBalance = $vendor->account ? $vendor->account->opening_balance : 0;
        $vendorAccountId = $vendor->account ? $vendor->account->id : null;
        
        $ledgerWithBalance = $ledger->map(function($transaction) use (&$runningBalance, $vendorAccountId) {
            if ($transaction->to_account_id === $vendorAccountId) {
                // Purchase - increases liability
                $runningBalance += $transaction->amount;
            } else {
                // Payment - decreases liability
                $runningBalance -= $transaction->amount;
            }
            
            $transaction->running_balance = $runningBalance;
            return $transaction;
        });

        // Get summary
        $summary = [
            'opening_balance' => $vendor->account ? $vendor->account->opening_balance : 0,
            'total_purchases' => $vendor->getTotalPurchases(),
            'total_payments' => $vendor->getTotalPayments(),
            'current_balance' => $vendor->getBalance()
        ];

        return view('fin.vendor.show', compact('vendor', 'ledgerWithBalance', 'summary'));
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
            'vendor_name' => 'required|string|max:255',
            'vendor_contact' => 'nullable|string|max:255',
            'vendor_email' => 'nullable|email|max:255',
            'vendor_phone' => 'nullable|string|max:50'
        ]);

        try {
            $vendor = VendorModel::findOrFail($id);

            $vendor->update([
                'vendor_name' => $request->vendor_name,
                'vendor_contact' => $request->vendor_contact,
                'vendor_email' => $request->vendor_email,
                'vendor_phone' => $request->vendor_phone,
                'updated_by' => auth()->id()
            ]);

            return redirect()->route('fin.vendors.show', $vendor->id)
                           ->with('success', 'Vendor updated successfully!');

        } catch (\Exception $e) {
            Log::error("Error updating vendor: " . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error updating vendor: ' . $e->getMessage());
        }
    }

    /**
     * Toggle vendor active status
     */
    public function toggleStatus($id)
    {
        try {
            $vendor = VendorModel::findOrFail($id);
            
            $vendor->is_active = !$vendor->is_active;
            $vendor->updated_by = auth()->id();
            $vendor->save();

            $status = $vendor->is_active ? 'activated' : 'deactivated';

            return redirect()->route('fin.vendors.index')
                           ->with('success', "Vendor {$status} successfully!");

        } catch (\Exception $e) {
            Log::error("Error toggling vendor status: " . $e->getMessage());
            
            return back()->with('error', 'Error updating vendor status: ' . $e->getMessage());
        }
    }

    /**
     * Record purchase
     */
    public function recordPurchase(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:500',
            'transaction_date' => 'required|date'
        ]);

        try {
            DB::beginTransaction();

            $vendor = VendorModel::with('account')->findOrFail($id);
            $purchaseAccount = AccountModel::getByCode('EXP_PURCHASES');

            if (!$purchaseAccount) {
                throw new \Exception("Purchase expense account not found");
            }

            // Create ledger entry
            LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
                'description' => $request->description,
                'from_account_id' => $purchaseAccount->id,
                'to_account_id' => $vendor->account->id,
                'amount' => $request->amount,
                'mode' => LedgerModel::MODE_CASH,
                'approval_status' => LedgerModel::STATUS_APPROVED,
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

            // Determine approval status based on source account
            // Online accounts or manager cash accounts require approval
            $requiresApproval = in_array($paymentAccount->account_code, ['ONLINE']) || 
                               $paymentAccount->account_category === 'employee_cash';
            
            $approvalStatus = $requiresApproval ? LedgerModel::STATUS_PENDING : LedgerModel::STATUS_APPROVED;
            $mode = ($paymentAccount->account_code === 'ONLINE') ? LedgerModel::MODE_ONLINE : LedgerModel::MODE_CASH;

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
                'description' => $request->description ?? "Payment to {$vendor->vendor_name}",
                'from_account_id' => $vendor->account->id,
                'to_account_id' => $paymentAccount->id,
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
}

