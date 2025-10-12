<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use App\Models\CRM\OrderModel;
use App\Models\Request\RequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerPostingService
{
    /**
     * Post invoice to ledger when order is delivered
     */
    public function postInvoiceFromOrder(OrderModel $order)
    {
        try {
            // Check if automatic posting is enabled
            $autoPostEnabled = ConfigModel::where('config_key', 'LEDGER_AUTO_POST_ENABLED')
                ->value('config_value');
            
            if ($autoPostEnabled !== '1') {
                Log::info("Automatic ledger posting is disabled", ['order_id' => $order->id]);
                return ['success' => false, 'message' => 'Automatic posting disabled'];
            }

            // Check if already posted
            if ($order->ledger_transaction_id) {
                Log::info("Order already has ledger entry", ['order_id' => $order->id]);
                return ['success' => true, 'message' => 'Already posted'];
            }

            // Check if order is actually delivered
            if ($order->order_status !== 'delivered') {
                return ['success' => false, 'message' => 'Order must be delivered to post to ledger'];
            }

            DB::beginTransaction();

            $salesAccount = ConfigModel::getSalesRevenueAccount();
            if (!$salesAccount) {
                throw new \Exception("Sales revenue account not found");
            }

            // Determine destination account based on payment method
            // Online payments: online, bank_transfer, card (require approval)
            $onlinePaymentMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];
            
            if (in_array($order->payment_method, $onlinePaymentMethods)) {
                // Online payment
                $toAccount = ConfigModel::getOnlineBankAccount();
                $mode = LedgerModel::MODE_ONLINE;
                $approvalStatus = LedgerModel::STATUS_PENDING; // Online requires approval
            } else {
                // Cash payment - find rider's cash account
                if ($order->assigned_rider_user_id) {
                    $rider = \App\Models\SysAdmin\UserModel::find($order->assigned_rider_user_id);
                    if ($rider) {
                        $toAccount = AccountModel::createEmployeeCashAccount($rider->id, $rider->fullname ?? $rider->name);
                    } else {
                        // Create action item for missing rider user
                        \App\Models\FIN\ActionItemModel::create([
                            'item_type' => 'missing_rider',
                            'severity' => 'high',
                            'title' => "Order #{$order->order_number} - Rider user not found",
                            'description' => "Rider with ID {$order->assigned_rider_user_id} not found in system",
                            'related_entity_type' => 'order',
                            'order_id' => $order->id,
                            'suggested_action' => 'Verify rider user exists or reassign order to valid rider',
                            'created_by' => auth()->id() ?? 1
                        ]);
                        throw new \Exception("Rider not found");
                    }
                } else {
                    // Create action item for missing rider assignment
                    \App\Models\FIN\ActionItemModel::createMissingRiderItem($order);
                    throw new \Exception("No rider assigned to order");
                }
                $mode = LedgerModel::MODE_CASH;
                $approvalStatus = LedgerModel::STATUS_APPROVED; // Cash is auto-approved
            }

            if (!$toAccount) {
                throw new \Exception("Destination account not found");
            }

            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => now(),
                'transaction_type' => LedgerModel::TYPE_INVOICE,
                'description' => "Invoice #{$order->order_number} - Delivered",
                'from_account_id' => $salesAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $order->total_price,
                'mode' => $mode,
                'approval_status' => $approvalStatus,
                'approval_date' => $approvalStatus === LedgerModel::STATUS_APPROVED ? now() : null,
                'approved_by' => $approvalStatus === LedgerModel::STATUS_APPROVED ? auth()->id() : null,
                'order_id' => $order->id,
                'created_by' => auth()->id() ?? 1
            ]);

            // Update account balances if approved
            if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
                $salesAccount->current_balance -= $order->total_price; // Revenue (credit side)
                $salesAccount->save();
                
                $toAccount->current_balance += $order->total_price; // Asset (debit side)
                $toAccount->save();
            }

            // Link ledger to order
            $order->ledger_transaction_id = $ledger->id;
            $order->save();

            DB::commit();

            Log::info("Invoice posted to ledger", [
                'order_id' => $order->id,
                'ledger_id' => $ledger->id,
                'amount' => $order->total_price
            ]);

            return [
                'success' => true,
                'message' => 'Invoice posted to ledger successfully',
                'ledger_id' => $ledger->id
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to post invoice to ledger", [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to post to ledger: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Post expense from approved request
     */
    public function postExpenseFromRequest(RequestModel $request)
    {
        try {
            // Check if already posted
            if ($request->ledger_transaction_id) {
                Log::info("Request already has ledger entry", ['request_id' => $request->id]);
                return ['success' => true, 'message' => 'Already posted'];
            }

            // Check if request is actually approved
            if ($request->status !== 'approved') {
                return ['success' => false, 'message' => 'Request must be approved to post to ledger'];
            }

            DB::beginTransaction();

            // Get funding account (payment source)
            // Priority: 1) payment_source_account_id, 2) Config default, 3) EXP_FUND
            $fundingAccount = null;
            
            if ($request->payment_source_account_id) {
                $fundingAccount = AccountModel::find($request->payment_source_account_id);
            }
            
            if (!$fundingAccount) {
                $fundingAccount = ConfigModel::getExpenseFundingAccount();
            }
            
            if (!$fundingAccount) {
                $fundingAccount = AccountModel::getByCode('EXP_FUND');
            }

            if (!$fundingAccount) {
                throw new \Exception("Payment source account not found");
            }
            
            // IMPORTANT: Save the funding account back to request if it was defaulted
            if (!$request->payment_source_account_id) {
                $request->payment_source_account_id = $fundingAccount->id;
                // Don't save yet - will save with ledger_transaction_id below
            }

            // Get or create expense account
            // Priority: 1) expense_category (specific), 2) category name (general)
            $expenseAccountName = $request->expense_category ?? $request->category->category_name;
            $expenseAccount = $this->getOrCreateExpenseAccount($expenseAccountName);

            if (!$expenseAccount) {
                throw new \Exception("Expense account not found");
            }

            // Build description
            $description = "Expense Request #{$request->request_number}";
            if ($request->expense_category) {
                $description .= " - {$request->expense_category}";
            } else {
                $description .= " - {$request->category->category_name}";
            }
            
            // Create ledger entry
            $ledger = LedgerModel::create([
                'transaction_date' => $request->approved_at ?? now(),
                'transaction_type' => LedgerModel::TYPE_EXPENSE,
                'description' => $description,
                'from_account_id' => $fundingAccount->id,
                'to_account_id' => $expenseAccount->id,
                'amount' => $request->amount,
                'mode' => null,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approval_date' => $request->approved_at,
                'request_id' => $request->id,
                'created_by' => $request->requester_user_id,
                'comments' => "Paid from: {$fundingAccount->account_name}"
            ]);

            // Update account balances
            $fundingAccount->current_balance -= $request->amount; // Funding decreases
            $fundingAccount->save();
            
            $expenseAccount->current_balance += $request->amount; // Expense increases
            $expenseAccount->save();

            // Link ledger to request
            $request->ledger_transaction_id = $ledger->id;
            
            // Mark settlement status
            $this->markSettlementStatus($request, $fundingAccount);
            
            $request->save();

            DB::commit();

            Log::info("Expense posted to ledger", [
                'request_id' => $request->id,
                'ledger_id' => $ledger->id,
                'amount' => $request->amount
            ]);

            return [
                'success' => true,
                'message' => 'Expense posted to ledger successfully',
                'ledger_id' => $ledger->id
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to post expense to ledger", [
                'request_id' => $request->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to post to ledger: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get or create expense account for category
     */
    private function getOrCreateExpenseAccount($categoryName)
    {
        $code = 'EXP_' . strtoupper(str_replace([' ', '-', '.', '/', '(', ')'], '_', $categoryName));
        $code = substr($code, 0, 50);

        return AccountModel::firstOrCreate(
            ['account_code' => $code],
            [
                'account_name' => 'Expense - ' . $categoryName,
                'account_type' => AccountModel::TYPE_EXPENSE,
                'account_category' => AccountModel::CATEGORY_EXPENSE,
                'is_active' => 1,
                'created_by' => auth()->id() ?? 1
            ]
        );
    }

    /**
     * Approve online transaction and update balances
     */
    public function approveOnlineTransaction($ledgerId, $userId = null)
    {
        try {
            DB::beginTransaction();

            $ledger = LedgerModel::findOrFail($ledgerId);

            // Check if already approved
            if ($ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                return ['success' => false, 'message' => 'Transaction already approved'];
            }

            // Check if it's an online transaction
            if ($ledger->mode !== LedgerModel::MODE_ONLINE) {
                return ['success' => false, 'message' => 'Not an online transaction'];
            }

            // Update ledger
            $ledger->approval_status = LedgerModel::STATUS_APPROVED;
            $ledger->approval_date = now();
            $ledger->approved_by = $userId ?? auth()->id();
            $ledger->save();

            // Update account balances based on transaction type
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;

            if ($fromAccount && $toAccount) {
                // Debit from account (decrease)
                $fromAccount->current_balance -= $ledger->amount;
                $fromAccount->save();

                // Credit to account (increase)
                $toAccount->current_balance += $ledger->amount;
                $toAccount->save();
            }

            DB::commit();

            Log::info("Online transaction approved", [
                'ledger_id' => $ledgerId,
                'amount' => $ledger->amount
            ]);

            return [
                'success' => true,
                'message' => 'Transaction approved successfully'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to approve online transaction", [
                'ledger_id' => $ledgerId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to approve transaction: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Mark expense settlement status based on payment source
     * 
     * @param RequestModel $request
     * @param AccountModel $fundingAccount
     * @return void
     */
    private function markSettlementStatus($request, $fundingAccount): void
    {
        // Get Expense Fund account
        $expenseFund = ConfigModel::getExpenseFundingAccount();
        
        if (!$expenseFund) {
            $expenseFund = AccountModel::where('account_code', 'EXP_FUND')->first();
        }
        
        // If paid from Expense Fund, no settlement needed
        if ($expenseFund && $fundingAccount->id == $expenseFund->id) {
            $request->settlement_status = 'not_required';
        } else {
            // Otherwise, mark as pending settlement
            $request->settlement_status = 'pending';
        }
    }
}

