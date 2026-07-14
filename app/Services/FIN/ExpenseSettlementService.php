<?php

namespace App\Services\FIN;

use App\Models\Request\RequestModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpenseSettlementService
{
    /**
     * Settle an expense by creating bridge transaction
     * 
     * @param RequestModel $expenseRequest The expense request to settle
     * @param int|null $settlementSourceAccountId Source account (defaults to Expense Fund)
     * @param string|null $notes Settlement notes
     * @return array Result with success status and message
     */
    public function settleExpense(
        RequestModel $expenseRequest, 
        ?int $settlementSourceAccountId = null, 
        ?string $notes = null
    ): array {
        DB::beginTransaction();
        
        try {
            // Validation
            if ($expenseRequest->settlement_status === 'settled') {
                throw new \Exception("Expense #{$expenseRequest->request_number} is already settled");
            }
            
            if (!$expenseRequest->ledger_transaction_id) {
                throw new \Exception("Expense #{$expenseRequest->request_number} has not been posted to ledger");
            }
            
            if ($expenseRequest->status !== RequestModel::STATUS_APPROVED) {
                throw new \Exception("Only approved expenses can be settled");
            }
            
            // 1. Get settlement source (usually Expense Fund)
            $expenseFund = $settlementSourceAccountId 
                ? AccountModel::findOrFail($settlementSourceAccountId)
                : ConfigModel::getExpenseFundingAccount();
            
            if (!$expenseFund) {
                // Fallback to EXP_FUND if config not set
                $expenseFund = AccountModel::where('account_code', 'EXP_FUND')->first();
            }
            
            if (!$expenseFund) {
                throw new \Exception("Settlement source account not found. Please configure Expense Fund.");
            }
            
            // 2. Determine destination (where cash was supposed to go)
            $settlementDestination = $this->determineSettlementDestination($expenseRequest);
            
            if (!$settlementDestination) {
                throw new \Exception("Settlement destination not found");
            }
            
            // 3. Validate same account settlement
            if ($expenseFund->id === $settlementDestination->id) {
                throw new \Exception("Settlement source and destination cannot be the same account");
            }
            
            // 4. Check if expense fund has sufficient balance (warn but allow negative)
            $balanceWarning = null;
            if ($expenseFund->account_type === 'asset' && $expenseFund->current_balance < $expenseRequest->amount) {
                $balanceAfterSettlement = $expenseFund->current_balance - $expenseRequest->amount;
                $balanceWarning = "Warning: {$expenseFund->account_name} will have negative balance after settlement. Current: Rs. " . number_format($expenseFund->current_balance, 2) . ", After: Rs. " . number_format($balanceAfterSettlement, 2);
                
                \Log::warning("Settlement proceeding with negative balance", [
                    'account' => $expenseFund->account_name,
                    'current_balance' => $expenseFund->current_balance,
                    'settlement_amount' => $expenseRequest->amount,
                    'balance_after' => $balanceAfterSettlement,
                    'expense_request' => $expenseRequest->request_number
                ]);
            }
            
            // ⭐ Per-bank tag inheritance (Ledger L1, B3): if the settlement lands on the ONLINE
            // bank account, carry the physical-bank tag of the rider's most recent deposit to that
            // account, so the bank balance stays correct instead of an untagged row.
            $settlementBankId = null;
            if ($settlementDestination->account_category === AccountModel::CATEGORY_BANK) {
                $riderCashAccount = AccountModel::where('user_id', $expenseRequest->requester_user_id)
                    ->where('account_category', 'employee_cash')->first();
                $settlementBankId = LedgerModel::when($riderCashAccount, fn($q) => $q->where('from_account_id', $riderCashAccount->id))
                    ->where('to_account_id', $settlementDestination->id)
                    ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                    ->whereNotNull('receiving_account_id')
                    ->orderByDesc('transaction_date')->orderByDesc('id')
                    ->value('receiving_account_id');
            }

            // 5. Create SETTLEMENT ledger transaction
            // This is a NEW transaction that "bridges the gap"
            $settlementLedger = LedgerModel::create([
                'transaction_date' => now(),
                'transaction_type' => LedgerModel::TYPE_SETTLEMENT,
                'description' => "Settlement for Expense #{$expenseRequest->request_number}"
                                . ($expenseRequest->expense_category ? " - {$expenseRequest->expense_category}" : '')
                                . ($expenseRequest->requester ? " ({$expenseRequest->requester->fullname})" : ''),
                'from_account_id' => $expenseFund->id,
                'to_account_id' => $settlementDestination->id,
                'amount' => $expenseRequest->amount,
                'mode' => LedgerModel::MODE_CASH, // Internal transfer
                'receiving_account_id' => $settlementBankId,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approval_date' => now(),
                'approved_by' => auth()->id(),
                'reference_type' => 'expense_request',
                'reference_id' => $expenseRequest->id,
                'comments' => "Settlement: Replenishing {$settlementDestination->account_name} for expense originally paid from {$expenseRequest->paymentSourceAccount->account_name}. " 
                             . ($notes ?? ''),
                'created_by' => auth()->id()
            ]);
            
            // 6. Apply balances via the canonical engine (EXP_FUND −, till +; both asset, so every
            // convention agrees — zero net change vs the old asset-aware block). Row-locked; sets
            // balance_updated so the reversal path can undo it correctly.
            (new \App\Services\FIN\BalancePostingService())->apply($settlementLedger);
            
            // 7. Update expense request metadata
            $expenseRequest->settlement_status = 'settled';
            $expenseRequest->settled_at = now();
            $expenseRequest->settled_by = auth()->id();
            $expenseRequest->settlement_transaction_id = $settlementLedger->id;
            $expenseRequest->settlement_destination_account_id = $settlementDestination->id;
            $expenseRequest->settlement_notes = $notes;
            
            // IMPORTANT: DO NOT change payment_source_account_id!
            // Keep original for audit trail - settlement_status shows it's been reconciled
            
            $expenseRequest->save();
            
            DB::commit();
            
            Log::info("Expense settled successfully", [
                'request_id' => $expenseRequest->id,
                'request_number' => $expenseRequest->request_number,
                'settlement_ledger_id' => $settlementLedger->id,
                'amount' => $expenseRequest->amount,
                'destination' => $settlementDestination->account_name,
                'settled_by' => auth()->user()->name ?? auth()->id(),
                'balance_warning' => $balanceWarning
            ]);
            
            // Build success message with warning if applicable
            $message = "Settlement completed: Rs. " . number_format($expenseRequest->amount, 2) . " transferred from {$expenseFund->account_name} to {$settlementDestination->account_name}";
            if ($balanceWarning) {
                $message .= "\n\n⚠️ " . $balanceWarning;
            }
            
            return [
                'success' => true,
                'message' => $message,
                'settlement_ledger_id' => $settlementLedger->id,
                'destination_account' => $settlementDestination->account_name,
                'has_warning' => $balanceWarning !== null,
                'warning' => $balanceWarning
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Expense settlement failed", [
                'request_id' => $expenseRequest->id,
                'request_number' => $expenseRequest->request_number ?? 'N/A',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Settlement failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Settle multiple expenses in bulk
     * 
     * @param array $expenseRequestIds Array of request IDs to settle
     * @param int|null $settlementSourceAccountId Source account
     * @param string|null $notes Settlement notes
     * @return array Result with counts
     */
    public function bulkSettle(
        array $expenseRequestIds, 
        ?int $settlementSourceAccountId = null, 
        ?string $notes = null
    ): array {
        $successCount = 0;
        $failCount = 0;
        $errors = [];
        
        foreach ($expenseRequestIds as $requestId) {
            $request = RequestModel::find($requestId);
            
            if (!$request) {
                $failCount++;
                $errors[] = "Request ID {$requestId} not found";
                continue;
            }
            
            $result = $this->settleExpense($request, $settlementSourceAccountId, $notes);
            
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                $errors[] = "{$request->request_number}: {$result['message']}";
            }
        }
        
        return [
            'success' => $successCount > 0,
            'message' => "Settled {$successCount} expense(s). {$failCount} failed.",
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'errors' => $errors
        ];
    }
    
    /**
     * Determine where settlement money should go
     * Rule: Find most recent deposit destination for this employee
     * 
     * @param RequestModel $expenseRequest
     * @return AccountModel|null
     */
    private function determineSettlementDestination(RequestModel $expenseRequest): ?AccountModel
    {
        // Find rider's cash account
        $riderCashAccount = AccountModel::where('user_id', $expenseRequest->requester_user_id)
            ->where('account_category', 'employee_cash')
            ->first();
        
        if (!$riderCashAccount) {
            // Default to NF Cash if employee has no cash account
            return ConfigModel::getNFCashAccount();
        }
        
        // Find most recent deposit from this rider before or on the expense date
        $recentDeposit = LedgerModel::where('from_account_id', $riderCashAccount->id)
            ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
            ->where('transaction_date', '<=', $expenseRequest->created_at)
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        if ($recentDeposit) {
            // Settlement goes to the same place as their most recent deposit
            return AccountModel::find($recentDeposit->to_account_id);
        }
        
        // Default: NF Cash
        return ConfigModel::getNFCashAccount();
    }
    
    /**
     * Get list of expenses needing settlement
     * 
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int|null $employeeId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getExpensesNeedingSettlement($dateFrom = null, $dateTo = null, $employeeId = null)
    {
        $query = RequestModel::whereNotNull('ledger_transaction_id')
            ->where('settlement_status', 'pending')
            ->with(['requester', 'paymentSourceAccount', 'category']);
        
        if ($dateFrom && $dateTo) {
            $query->whereBetween('created_at', [$dateFrom, $dateTo]);
        }
        
        if ($employeeId) {
            $query->where('requester_user_id', $employeeId);
        }
        
        return $query->orderBy('created_at', 'asc')->get();
    }
    
    /**
     * Get settled expenses for reporting
     * 
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSettledExpenses($dateFrom = null, $dateTo = null)
    {
        $query = RequestModel::where('settlement_status', 'settled')
            ->with(['requester', 'paymentSourceAccount', 'settlementDestinationAccount', 'settledBy']);
        
        if ($dateFrom && $dateTo) {
            $query->whereBetween('settled_at', [$dateFrom, $dateTo]);
        }
        
        return $query->orderBy('settled_at', 'desc')->get();
    }
    
    /**
     * Mark expense as requiring settlement
     * Called when expense is approved and paid from non-Expense-Fund source
     * 
     * @param RequestModel $expenseRequest
     * @return void
     */
    public function markAsNeedingSettlement(RequestModel $expenseRequest): void
    {
        // Check if this expense needs settlement
        $expenseFund = ConfigModel::getExpenseFundingAccount();
        
        if (!$expenseFund) {
            $expenseFund = AccountModel::where('account_code', 'EXP_FUND')->first();
        }
        
        // If paid from Expense Fund, no settlement needed
        if ($expenseRequest->payment_source_account_id && 
            $expenseFund && 
            $expenseRequest->payment_source_account_id == $expenseFund->id) {
            $expenseRequest->settlement_status = 'not_required';
        } else {
            // Otherwise, mark as pending settlement
            $expenseRequest->settlement_status = 'pending';
        }
        
        $expenseRequest->save();
    }
}

