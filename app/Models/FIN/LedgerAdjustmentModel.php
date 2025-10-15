<?php

namespace App\Models\FIN;

use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerAdjustmentModel extends BaseModel
{
    protected $table = 't_fin_ledger_adjustments';
    protected $primaryKey = 'id';
    public $timestamps = true;
    
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    
    const APPROVAL_STATUS_PENDING = 'pending';
    const APPROVAL_STATUS_APPROVED = 'approved';
    const APPROVAL_STATUS_REJECTED = 'rejected';
    
    protected $fillable = [
        'ledger_id',
        'order_id',
        'old_amount',
        'new_amount',
        'adjustment_amount',
        'reason',
        'adjustment_status',
        'requires_level_1',
        'level_1_status',
        'level_1_approved_by',
        'level_1_approved_at',
        'level_1_comments',
        'requires_level_2',
        'level_2_status',
        'level_2_approved_by',
        'level_2_approved_at',
        'level_2_comments',
        'requested_by',
        'requested_at',
        'finalized_at'
    ];
    
    protected $casts = [
        'old_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'requires_level_1' => 'boolean',
        'requires_level_2' => 'boolean',
        'level_1_approved_at' => 'datetime',
        'level_2_approved_at' => 'datetime',
        'requested_at' => 'datetime',
        'finalized_at' => 'datetime'
    ];
    
    // ================================================================
    // RELATIONSHIPS
    // ================================================================
    
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_id');
    }
    
    public function order(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CRM\OrderModel::class, 'order_id');
    }
    
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SysAdmin\UserModel::class, 'requested_by');
    }
    
    public function level1Approver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SysAdmin\UserModel::class, 'level_1_approved_by');
    }
    
    public function level2Approver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SysAdmin\UserModel::class, 'level_2_approved_by');
    }
    
    // ================================================================
    // STATUS CHECKS
    // ================================================================
    
    public function isPending(): bool
    {
        return $this->adjustment_status === self::STATUS_PENDING;
    }
    
    public function isApproved(): bool
    {
        return $this->adjustment_status === self::STATUS_APPROVED;
    }
    
    public function isRejected(): bool
    {
        return $this->adjustment_status === self::STATUS_REJECTED;
    }
    
    // ================================================================
    // APPROVAL LOGIC (Similar to RequestModel)
    // ================================================================
    
    /**
     * Check if this adjustment can be approved by a specific level
     */
    public function canBeApprovedByLevel(int $level): bool
    {
        if (!$this->isPending()) {
            return false;
        }
        
        if ($level === 1) {
            return $this->requires_level_1 && $this->level_1_status === self::APPROVAL_STATUS_PENDING;
        }
        
        if ($level === 2) {
            return $this->requires_level_2 && 
                   $this->level_2_status === self::APPROVAL_STATUS_PENDING &&
                   (!$this->requires_level_1 || $this->level_1_status === self::APPROVAL_STATUS_APPROVED);
        }
        
        return false;
    }
    
    /**
     * Process approval or rejection at a specific level
     * 
     * @param int $level 1 or 2
     * @param int $approverId User ID of approver
     * @param string $action 'approved' or 'rejected'
     * @param string|null $comments Optional comments
     * @return bool Success status
     */
    public function processApproval(int $level, int $approverId, string $action, ?string $comments = null): bool
    {
        if (!in_array($action, ['approved', 'rejected'])) {
            Log::error("Invalid approval action", [
                'adjustment_id' => $this->id,
                'action' => $action
            ]);
            return false;
        }
        
        if (!$this->canBeApprovedByLevel($level)) {
            Log::warning("Adjustment cannot be approved at this level", [
                'adjustment_id' => $this->id,
                'level' => $level,
                'current_status' => $this->adjustment_status
            ]);
            return false;
        }
        
        DB::beginTransaction();
        try {
            // Update approval fields for the specified level
            if ($level === 1) {
                $this->level_1_status = $action;
                $this->level_1_approved_by = $approverId;
                $this->level_1_approved_at = now();
                $this->level_1_comments = $comments;
            } elseif ($level === 2) {
                $this->level_2_status = $action;
                $this->level_2_approved_by = $approverId;
                $this->level_2_approved_at = now();
                $this->level_2_comments = $comments;
            }
            
            // Check if fully approved or rejected
            if ($action === 'rejected') {
                // Any rejection finalizes the adjustment as rejected
                $this->adjustment_status = self::STATUS_REJECTED;
                $this->finalized_at = now();
                
                Log::info("Ledger adjustment rejected", [
                    'adjustment_id' => $this->id,
                    'level' => $level,
                    'approver_id' => $approverId
                ]);
                
            } elseif ($this->isFullyApproved()) {
                // All required levels approved - apply the adjustment
                $this->adjustment_status = self::STATUS_APPROVED;
                $this->finalized_at = now();
                
                // Apply the ledger adjustment
                $this->applyAdjustment();
                
                Log::info("Ledger adjustment fully approved and applied", [
                    'adjustment_id' => $this->id,
                    'level' => $level,
                    'approver_id' => $approverId
                ]);
            } else {
                Log::info("Ledger adjustment approved at level {$level}, awaiting next level", [
                    'adjustment_id' => $this->id,
                    'level' => $level,
                    'approver_id' => $approverId
                ]);
            }
            
            $this->save();
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to process ledger adjustment approval", [
                'adjustment_id' => $this->id,
                'level' => $level,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Check if all required approval levels are approved
     */
    private function isFullyApproved(): bool
    {
        $l1Approved = !$this->requires_level_1 || $this->level_1_status === self::APPROVAL_STATUS_APPROVED;
        $l2Approved = !$this->requires_level_2 || $this->level_2_status === self::APPROVAL_STATUS_APPROVED;
        
        return $l1Approved && $l2Approved;
    }
    
    /**
     * Apply the adjustment to the ledger entry and update account balances
     * This is called automatically when adjustment is fully approved
     */
    private function applyAdjustment(): void
    {
        // Get the ledger entry
        $ledger = $this->ledger;
        if (!$ledger) {
            throw new \Exception("Ledger entry not found for adjustment {$this->id}");
        }
        
        $oldAmount = $ledger->amount;
        $newAmount = $this->new_amount;
        $difference = $newAmount - $oldAmount;
        
        Log::info("Applying ledger adjustment", [
            'adjustment_id' => $this->id,
            'ledger_id' => $ledger->id,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'difference' => $difference
        ]);
        
        // Update the ledger entry amount
        $ledger->amount = $newAmount;
        $ledger->updated_by = auth()->id() ?? 1;
        
        // ================================================================
        // Handle settlement status
        // ================================================================
        // If the invoice was settled and the amount increased, it should become open again
        if ($ledger->transaction_type === LedgerModel::TYPE_INVOICE && $ledger->settlement_status === 'settled') {
            if ($difference > 0) {
                // Amount increased - invoice should be reopened
                $previousSettledAmount = $ledger->settled_amount ?? 0;
                
                if ($newAmount > $previousSettledAmount) {
                    // The new amount exceeds what was settled, so mark as open with remaining balance
                    $ledger->settlement_status = 'open';
                    $ledger->settled_amount = $previousSettledAmount; // Keep what was settled
                    
                    Log::info("Invoice reopened due to amount increase", [
                        'ledger_id' => $ledger->id,
                        'order_id' => $this->order_id,
                        'previous_settled_amount' => $previousSettledAmount,
                        'new_total_amount' => $newAmount,
                        'remaining_balance' => $newAmount - $previousSettledAmount
                    ]);
                }
            } elseif ($difference < 0) {
                // Amount decreased - adjust settled_amount if needed
                if ($newAmount < $ledger->settled_amount) {
                    $ledger->settled_amount = $newAmount;
                    
                    Log::info("Settled amount adjusted down", [
                        'ledger_id' => $ledger->id,
                        'order_id' => $this->order_id,
                        'new_amount' => $newAmount,
                        'new_settled_amount' => $newAmount
                    ]);
                }
            }
        }
        
        $ledger->save();
        
        // Update account balances by the difference
        $fromAccount = $ledger->fromAccount;
        $toAccount = $ledger->toAccount;
        
        if ($fromAccount) {
            // For revenue accounts (from_account in invoices), decrease by difference
            // If difference is positive (price increased), balance decreases more (more revenue)
            // If difference is negative (price decreased), balance increases (less revenue)
            $fromAccount->current_balance -= $difference;
            $fromAccount->save();
            
            Log::info("Updated from_account balance", [
                'account_id' => $fromAccount->id,
                'account_code' => $fromAccount->account_code,
                'old_balance' => $fromAccount->current_balance + $difference,
                'new_balance' => $fromAccount->current_balance,
                'change' => -$difference
            ]);
        }
        
        if ($toAccount) {
            // For asset accounts (to_account in invoices), increase by difference
            // If difference is positive (price increased), balance increases more (more receivable)
            // If difference is negative (price decreased), balance decreases (less receivable)
            $toAccount->current_balance += $difference;
            $toAccount->save();
            
            Log::info("Updated to_account balance", [
                'account_id' => $toAccount->id,
                'account_code' => $toAccount->account_code,
                'old_balance' => $toAccount->current_balance - $difference,
                'new_balance' => $toAccount->current_balance,
                'change' => $difference
            ]);
        }
        
        Log::info("Ledger adjustment applied successfully", [
            'adjustment_id' => $this->id,
            'ledger_id' => $ledger->id,
            'order_id' => $this->order_id,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'difference' => $difference
        ]);
    }
    
    // ================================================================
    // HELPER METHODS
    // ================================================================
    
    /**
     * Get a human-readable status label
     */
    public function getStatusLabel(): string
    {
        if ($this->isRejected()) {
            return 'Rejected';
        }
        
        if ($this->isApproved()) {
            return 'Approved';
        }
        
        // Pending - show which level
        if ($this->level_1_status === self::APPROVAL_STATUS_PENDING) {
            return 'Pending L1 Approval';
        }
        
        if ($this->level_2_status === self::APPROVAL_STATUS_PENDING) {
            return 'Pending L2 Approval';
        }
        
        return 'Pending';
    }
    
    /**
     * Get the next approval level needed
     */
    public function getNextApprovalLevel(): ?int
    {
        if (!$this->isPending()) {
            return null;
        }
        
        if ($this->requires_level_1 && $this->level_1_status === self::APPROVAL_STATUS_PENDING) {
            return 1;
        }
        
        if ($this->requires_level_2 && $this->level_2_status === self::APPROVAL_STATUS_PENDING) {
            return 2;
        }
        
        return null;
    }
}

