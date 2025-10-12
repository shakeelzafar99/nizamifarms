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
            
            // 4. Check if expense fund has sufficient balance
            if ($expenseFund->account_type === 'asset' && $expenseFund->current_balance < $expenseRequest->amount) {
                throw new \Exception("Insufficient balance in {$expenseFund->account_name}. Current: Rs. " . number_format($expenseFund->current_balance, 2));
            }
            
            // 5. Create SETTLEMENT ledger transaction
            // This is a NEW transaction that "bridges the gap"
            $settlementLedger = LedgerModel::create([
                'transaction_date' => now(),
                'transaction_type' => LedgerModel::TYPE_SETTLEMENT,
                'description' => "Settlement for Expense #{$expenseRequest->request_number}" 
                                . ($expenseRequest->expense_category ? " ({$expenseRequest->expense_category})" : ''),
                'from_account_id' => $expenseFund->id,
                'to_account_id' => $settlementDestination->id,
                'amount' => $expenseRequest->amount,
                'mode' => LedgerModel::MODE_CASH, // Internal transfer
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approval_date' => now(),
                'approved_by' => auth()->id(),
                'reference_type' => 'expense_request',
                'reference_id' => $expenseRequest->id,
                'comments' => "Settlement: Replenishing {$settlementDestination->account_name} for expense originally paid from {$expenseRequest->paymentSourceAccount->account_name}. " 
                             . ($notes ?? ''),
                'created_by' => auth()->id()
            ]);
            
            // 6. Update balances based on account type
            // From account (Expense Fund)
            if ($expenseFund->account_type === 'asset') {
                $expenseFund->current_balance -= $expenseRequest->amount;
            } else {
                $expenseFund->current_balance += $expenseRequest->amount;
            }
            $expenseFund->save();
            
            // To account (Settlement Destination)
            if ($settlementDestination->account_type === 'asset') {
                $settlementDestination->current_balance += $expenseRequest->amount;
            } else {
                $settlementDestination->current_balance -= $expenseRequest->amount;
            }
            $settlementDestination->save();
            
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
                'settled_by' => auth()->user()->name ?? auth()->id()
            ]);
            
            return [
                'success' => true,
                'message' => "Settlement completed: Rs. " . number_format($expenseRequest->amount, 2) . " transferred from {$expenseFund->account_name} to {$settlementDestination->account_name}",
                'settlement_ledger_id' => $settlementLedger->id,
                'destination_account' => $settlementDestination->account_name
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
            // Default to NF Main Till if employee has no cash account
            return ConfigModel::getAccountByCode('CASH_NF_MAIN_TILL')
                ?? ConfigModel::getAccountByCode('NF_CASH');
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
        
        // Default: NF Main Till
        return ConfigModel::getAccountByCode('CASH_NF_MAIN_TILL')
            ?? ConfigModel::getAccountByCode('NF_CASH');
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

