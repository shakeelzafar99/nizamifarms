<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Models\FIN\LedgerModel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderRiderController extends Controller
{
    public function assign(Request $request, int $orderId)
    {
        $data = $request->validate([
            'rider_user_id' => 'required|integer',
            'notes' => 'nullable|string',
            'assigned_at' => 'nullable|date',
            'confirmed' => 'nullable|boolean', // For ledger change confirmation
        ]);

        /** @var OrderModel|null $order */
        $order = OrderModel::find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        // ================================================================
        // LEDGER CHANGE DETECTION FOR RIDER ASSIGNMENT
        // ================================================================
        // Check if order has a ledger entry and if it's a cash order
        // Only cash orders are affected by rider changes (online orders don't care about rider)
        if ($order->ledger_transaction_id) {
            $ledger = LedgerModel::find($order->ledger_transaction_id);
            
            if ($ledger && $ledger->mode === LedgerModel::MODE_CASH) {
                // Check if rider is actually changing
                $oldRiderId = $order->assigned_rider_user_id;
                $newRiderId = (int)$data['rider_user_id'];
                
                if ($oldRiderId && $oldRiderId != $newRiderId) {
                    // Check if ledger is settled
                    if ($ledger->settlement_status === 'settled') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot change rider: Invoice has already been settled.',
                            'error_type' => 'already_settled'
                        ], 422);
                    }
                    
                    // Check for partial settlement
                    if ($ledger->settlement_status === 'partial' || ($ledger->settled_amount > 0)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot change rider: Invoice has partial settlement.',
                            'error_type' => 'partial_settlement'
                        ], 422);
                    }
                    
                    // Require confirmation if not already confirmed
                    if (!isset($data['confirmed']) || !$data['confirmed']) {
                        $oldRider = User::find($oldRiderId);
                        $newRider = User::find($newRiderId);
                        
                        return response()->json([
                            'success' => false,
                            'requires_confirmation' => true,
                            'confirmation_data' => [
                                'message' => 'Rider change will update ledger',
                                'old_rider_id' => $oldRiderId,
                                'old_rider_name' => $oldRider ? ($oldRider->fullname ?? $oldRider->name) : 'Unknown',
                                'new_rider_id' => $newRiderId,
                                'new_rider_name' => $newRider ? ($newRider->fullname ?? $newRider->name) : 'Unknown',
                                'amount' => $order->total_price,
                                'order_number' => $order->order_number
                            ]
                        ], 200);
                    }
                    
                    // Confirmed - proceed with ledger reversal and reposting
                    try {
                        $this->handleRiderChangeLedgerUpdate($order, $ledger, $newRiderId);
                        
                        Log::info("Rider change ledger update completed", [
                            'order_id' => $order->id,
                            'old_rider_id' => $oldRiderId,
                            'new_rider_id' => $newRiderId,
                            'ledger_id' => $ledger->id
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Failed to update ledger for rider change", [
                            'order_id' => $order->id,
                            'error' => $e->getMessage()
                        ]);
                        
                        return response()->json([
                            'success' => false,
                            'message' => 'Failed to update ledger: ' . $e->getMessage()
                        ], 500);
                    }
                }
            }
        }

        // Proceed with normal rider assignment
        $assignedAt = isset($data['assigned_at']) ? new \DateTime($data['assigned_at']) : null;
        $ok = $order->assignRider((int)$data['rider_user_id'], $data['notes'] ?? null, null, $assignedAt);
        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'Assignment failed'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rider assigned successfully',
            'ledger_updated' => isset($ledger) && $ledger->mode === LedgerModel::MODE_CASH
        ]);
    }

    public function timeline(int $orderId)
    {
        $rows = \DB::table('t_ops_order_rider_history as h')
            ->join('t_sys_user as u', 'u.id', '=', 'h.rider_user_id')
            ->leftJoin('t_sys_user as a', 'a.id', '=', 'h.assigned_by')
            ->where('h.order_id', $orderId)
            ->orderByDesc('h.assigned_at')
            ->limit(20)
            ->get([
                'h.rider_user_id',
                'u.fullname as rider_name',
                'h.assigned_at',
                'h.unassigned_at',
                'h.is_current',
                'h.source',
                'h.notes',
                'a.fullname as assigned_by_name',
            ]);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Handle ledger update when rider changes on a delivered order
     * Reverses old ledger entry and creates new one with new rider's account
     * 
     * @param OrderModel $order
     * @param LedgerModel $oldLedger
     * @param int $newRiderId
     * @return void
     * @throws \Exception
     */
    private function handleRiderChangeLedgerUpdate(OrderModel $order, LedgerModel $oldLedger, int $newRiderId)
    {
        DB::beginTransaction();
        
        try {
            $oldRider = User::find($order->assigned_rider_user_id);
            $newRider = User::find($newRiderId);
            
            $oldRiderName = $oldRider ? ($oldRider->fullname ?? $oldRider->name) : 'Unknown';
            $newRiderName = $newRider ? ($newRider->fullname ?? $newRider->name) : 'Unknown';
            
            // 1. Reverse the old ledger entry
            $this->reverseLedgerEntry($oldLedger, "Rider changed from '{$oldRiderName}' to '{$newRiderName}'");
            
            // 2. Clear the old ledger_transaction_id so new one can be created
            $order->ledger_transaction_id = null;
            $order->save();
            
            // 3. Temporarily update order rider for posting (will be updated again by assignRider)
            $order->assigned_rider_user_id = $newRiderId;
            
            // 4. Create new ledger entry with new rider's account
            $ledgerService = new \App\Services\FIN\LedgerPostingService();
            $result = $ledgerService->postInvoiceFromOrder($order);
            
            if (!$result['success']) {
                throw new \Exception("Failed to repost invoice: " . $result['message']);
            }
            
            // 5. Add note to new ledger entry
            $newLedger = LedgerModel::find($result['ledger_id']);
            if ($newLedger) {
                $newLedger->comments = "Rider changed from '{$oldRiderName}' to '{$newRiderName}'. Original ledger #{$oldLedger->id} reversed.";
                $newLedger->save();
            } else {
                throw new \Exception("New ledger entry was not created properly");
            }
            
            DB::commit();
            
            Log::info("Rider change ledger update successful", [
                'order_id' => $order->id,
                'old_ledger_id' => $oldLedger->id,
                'new_ledger_id' => $newLedger->id,
                'old_rider' => $oldRiderName,
                'new_rider' => $newRiderName
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to handle rider change ledger update", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Reverse a ledger entry and update balances
     * 
     * @param LedgerModel $ledger
     * @param string $reason
     * @return void
     */
    private function reverseLedgerEntry(LedgerModel $ledger, string $reason = 'Reversed')
    {
        $wasApproved = $ledger->approval_status === LedgerModel::STATUS_APPROVED;
        
        // Mark ledger as reversed
        $ledger->approval_status = LedgerModel::STATUS_REVERSED;
        $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . "REVERSED: {$reason}";
        $ledger->save();
        
        // Reverse balances ONLY if the transaction was approved
        if ($wasApproved) {
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;
            
            if ($fromAccount) {
                // Reverse the debit (add back)
                $fromAccount->current_balance += $ledger->amount;
                $fromAccount->save();
                
                Log::info("Reversed balance for from_account", [
                    'account_id' => $fromAccount->id,
                    'account_name' => $fromAccount->account_name,
                    'amount_added_back' => $ledger->amount,
                    'new_balance' => $fromAccount->current_balance
                ]);
            }
            
            if ($toAccount) {
                // Reverse the credit (subtract back)
                $toAccount->current_balance -= $ledger->amount;
                $toAccount->save();
                
                Log::info("Reversed balance for to_account", [
                    'account_id' => $toAccount->id,
                    'account_name' => $toAccount->account_name,
                    'amount_subtracted' => $ledger->amount,
                    'new_balance' => $toAccount->current_balance
                ]);
            }
        }
        
        Log::info("Ledger entry reversed", [
            'ledger_id' => $ledger->id,
            'was_approved' => $wasApproved,
            'balances_reversed' => $wasApproved,
            'reason' => $reason
        ]);
    }
}


