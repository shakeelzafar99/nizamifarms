<?php

namespace App\Models\Request;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Support\Facades\DB;

class RequestModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_req_master';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'request_number',
        'category_id',
        'requester_user_id',
        'title',
        'description',
        'amount',
        'expense_category',
        'expense_date', // ⭐ Backdated expense date
        'meter_distance',
        'petrol_rate',
        'attendance_id',
        'payment_source_account_id',
        'receiving_account_id', // Which of OUR banks an ONLINE expense is paid from
        'business_unit_id', // ⭐ Business unit for this expense
        'leave_start_date',
        'leave_end_date',
        'leave_type',
        'leave_days',
        'status',
        'priority',
        'requires_level_1',
        'requires_level_2',
        'level_1_status',
        'level_2_status',
        'attachments',
        'remarks',
        'rejection_reason',
        'submitted_at',
        'completed_at',
        'ledger_transaction_id',
        'settlement_status',
        'settled_at',
        'settled_by',
        'settlement_transaction_id',
        'settlement_destination_account_id',
        'settlement_notes',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meter_distance' => 'decimal:2',
        'petrol_rate' => 'decimal:2',
        'attendance_id' => 'integer',
        'expense_date' => 'date', // ⭐ Expense date for backdated entries
        'leave_start_date' => 'date',
        'leave_end_date' => 'date',
        'leave_days' => 'integer',
        'requires_level_1' => 'boolean',
        'requires_level_2' => 'boolean',
        'attachments' => 'json',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'settled_at' => 'datetime'
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    const APPROVAL_STATUS_PENDING = 'pending';
    const APPROVAL_STATUS_APPROVED = 'approved';
    const APPROVAL_STATUS_REJECTED = 'rejected';

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(RequestCategoryModel::class, 'category_id', 'id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'requester_user_id', 'id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RequestApprovalModel::class, 'request_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
    }

    public function paymentSourceAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FIN\AccountModel::class, 'payment_source_account_id', 'id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'settled_by', 'id');
    }

    public function settlementTransaction(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FIN\LedgerModel::class, 'settlement_transaction_id', 'id');
    }

    public function settlementDestinationAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FIN\AccountModel::class, 'settlement_destination_account_id', 'id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeByRequester($query, int $userId)
    {
        return $query->where('requester_user_id', $userId);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeLeaveRequests($query)
    {
        return $query->whereHas('category', function($q) {
            $q->where('category_code', 'leave');
        });
    }

    // Helper methods
    public static function generateRequestNumber(): string
    {
        $prefix = 'REQ';
        $year = date('Y');
        $month = date('m');
        
        // Get last number for this month
        $lastRequest = static::where('request_number', 'LIKE', "{$prefix}-{$year}{$month}-%")
            ->orderBy('request_number', 'desc')
            ->first();
        
        if ($lastRequest) {
            $lastNumber = (int) substr($lastRequest->request_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return sprintf('%s-%s%s-%04d', $prefix, $year, $month, $newNumber);
    }

    public function isPending(): bool
    {
        // Use getAttribute to bypass the BaseModel property and get the DB column
        $status = $this->getAttribute('status') ?? $this->getAttributeValue('status') ?? '';
        return strtolower(trim($status)) === 'pending';
    }

    public function isApproved(): bool
    {
        $status = $this->getAttribute('status') ?? '';
        return strtolower(trim($status)) === 'approved';
    }

    public function isRejected(): bool
    {
        $status = $this->getAttribute('status') ?? '';
        return strtolower(trim($status)) === 'rejected';
    }

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

    public function processApproval(int $level, int $approverId, string $action, ?string $comments = null): bool
    {
        if (!in_array($action, ['approved', 'rejected'])) {
            return false;
        }

        if (!$this->canBeApprovedByLevel($level)) {
            return false;
        }

        DB::beginTransaction();
        try {
            // Create approval record
            RequestApprovalModel::create([
                'request_id' => $this->id,
                'approval_level' => $level,
                'approver_user_id' => $approverId,
                'status' => $action,
                'comments' => $comments,
                'action_date' => now(),
                'created_by' => $approverId
            ]);

            // Update request status
            if ($level === 1) {
                $this->level_1_status = $action;
            } elseif ($level === 2) {
                $this->level_2_status = $action;
            }

            // If rejected at any level, mark entire request as rejected
            if ($action === 'rejected') {
                $this->setAttribute('status', self::STATUS_REJECTED);
                $this->rejection_reason = $comments;
                $this->completed_at = now();
            }
            // Check if all required approvals are complete
            elseif ($this->areAllApprovalsComplete()) {
                $this->setAttribute('status', self::STATUS_APPROVED);
                $this->completed_at = now();
                
                // NEW: Handle invoice approval requests (online invoices)
                if ($this->category->category_code === 'invoice_approval' && $this->order_id) {
                    try {
                        $this->postInvoiceToLedgerAfterApproval();
                    } catch (\Exception $e) {
                        \Log::error("Exception posting invoice to ledger after approval", [
                            'request_id' => $this->id,
                            'order_id' => $this->order_id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't fail the approval if ledger posting fails
                    }
                }
                // If it's a leave request, create attendance records
                elseif ($this->category->category_code === 'leave') {
                    $this->createAttendanceRecordsForLeave();
                }
                // If it's an expense-type request, post to ledger
                elseif ($this->isExpenseTypeCategory() && $this->amount > 0) {
                    try {
                        $ledgerService = new \App\Services\FIN\LedgerPostingService();
                        $result = $ledgerService->postExpenseFromRequest($this);
                        
                        if ($result['success']) {
                            \Log::info("Expense posted to ledger", [
                                'request_id' => $this->id,
                                'ledger_id' => $result['ledger_id'] ?? null
                            ]);
                        } else {
                            \Log::warning("Failed to post expense to ledger", [
                                'request_id' => $this->id,
                                'message' => $result['message'] ?? 'Unknown error'
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error("Exception posting expense to ledger", [
                            'request_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't fail the approval if ledger posting fails
                    }
                }
                // If it's a salary advance request, post to ledger
                elseif ($this->category->category_code === 'salary_advance' && $this->amount > 0) {
                    try {
                        $this->postSalaryAdvanceToLedger();
                    } catch (\Exception $e) {
                        \Log::error("Exception posting salary advance to ledger", [
                            'request_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't fail the approval if ledger posting fails
                    }
                }
            }

            $this->updated_by = $approverId;
            $this->save();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Request approval error: ' . $e->getMessage());
            return false;
        }
    }

    public function isExpenseTypeCategory(): bool
    {
        if (!$this->category) return false;
        $code = $this->category->category_code;
        if (in_array($code, ['expense', 'khaas_expense'])) return true;
        
        // Check form_type from model attribute
        $formType = $this->category->form_type ?? null;
        if ($formType === 'expense') return true;
        
        // Fallback: query DB directly in case the model attribute is stale or column was added late
        if ($formType === null && !in_array($code, ['leave', 'salary_advance', 'invoice_approval'])) {
            try {
                $dbFormType = \DB::table('t_req_category')
                    ->where('category_code', $code)
                    ->value('form_type');
                \Log::info('isExpenseTypeCategory fallback check', [
                    'category_code' => $code,
                    'model_form_type' => $formType,
                    'db_form_type' => $dbFormType,
                    'show_in_expenses' => $this->category->show_in_expenses ?? null,
                ]);
                if ($dbFormType === 'expense') return true;
                
                // Final fallback: if show_in_expenses is enabled and it's not a known non-expense type
                $showInExpenses = $this->category->show_in_expenses ?? false;
                if ($showInExpenses) return true;
            } catch (\Exception $e) {
                \Log::warning('isExpenseTypeCategory fallback failed', ['error' => $e->getMessage()]);
            }
        }
        
        return false;
    }

    protected function areAllApprovalsComplete(): bool
    {
        if ($this->requires_level_1 && $this->level_1_status !== self::APPROVAL_STATUS_APPROVED) {
            return false;
        }

        if ($this->requires_level_2 && $this->level_2_status !== self::APPROVAL_STATUS_APPROVED) {
            return false;
        }

        return true;
    }

    protected function createAttendanceRecordsForLeave(): void
    {
        if (!$this->leave_start_date || !$this->leave_end_date) {
            return;
        }

        $startDate = $this->leave_start_date;
        $endDate = $this->leave_end_date;
        $userId = $this->requester_user_id;

        // Create attendance records for each day of the leave
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            // Check if attendance record already exists
            $exists = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', $currentDate->format('Y-m-d'))
                ->exists();

            if (!$exists) {
                DB::table('t_ops_attendance')->insert([
                    'user_id' => $userId,
                    'attendance_date' => $currentDate->format('Y-m-d'),
                    'login_time' => null,
                    'logout_time' => null,
                    'notes' => "Approved leave: {$this->leave_type}",
                    'leave_request_id' => $this->id,
                    'leave_type' => $this->leave_type,
                    'created_at' => now(),
                    'created_by' => $this->updated_by
                ]);
            }

            $currentDate->addDay();
        }
    }

    public function getApprovalStatusText(): string
    {
        $status = $this->getAttribute('status');
        
        if ($status === self::STATUS_APPROVED) {
            return 'Approved';
        }

        if ($status === self::STATUS_REJECTED) {
            return 'Rejected';
        }

        if ($status === self::STATUS_CANCELLED) {
            return 'Cancelled';
        }

        // Pending - show which level
        if ($this->requires_level_1 && $this->level_1_status === self::APPROVAL_STATUS_PENDING) {
            return 'Pending Level 1 Approval';
        }

        if ($this->requires_level_2 && $this->level_2_status === self::APPROVAL_STATUS_PENDING) {
            return 'Pending Level 2 Approval';
        }

        return 'Pending';
    }

    public function getLevel1Approver()
    {
        return $this->approvals()
            ->where('approval_level', 1)
            ->with('approver')
            ->first();
    }

    public function getLevel2Approver()
    {
        return $this->approvals()
            ->where('approval_level', 2)
            ->with('approver')
            ->first();
    }

    /**
     * Post salary advance to ledger when approved
     */
    protected function postSalaryAdvanceToLedger(): bool
    {
        try {
            // Use LedgerPostingService for consistent handling
            $ledgerService = new \App\Services\FIN\LedgerPostingService();
            $result = $ledgerService->postSalaryAdvanceFromRequest($this);
            
            return $result['success'] ?? false;

        } catch (\Exception $e) {
            \Log::error("Failed to post salary advance to ledger", [
                'request_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Post invoice to ledger after request approval (for online invoices)
     * This is called when an invoice_approval request is approved at L1
     */
    protected function postInvoiceToLedgerAfterApproval(): void
    {
        try {
            $order = $this->order;
            if (!$order) {
                throw new \Exception("Order not found for invoice request");
            }
            
            // Check if order payment method is still online
            $onlinePaymentMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];
            
            if (!in_array($order->payment_method, $onlinePaymentMethods)) {
                // Payment method changed to cash - post as approved directly
                \Log::info("Payment method changed to cash, posting invoice as approved", [
                    'request_id' => $this->id,
                    'request_number' => $this->request_number,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'payment_method' => $order->payment_method
                ]);
                
                // Use the standard posting service (will post as cash)
                $ledgerService = new \App\Services\FIN\LedgerPostingService();
                $result = $ledgerService->postInvoiceFromOrder($order);
                
                if ($result['success']) {
                    \Log::info("Invoice posted to ledger as cash (auto-approved)", [
                        'request_id' => $this->id,
                        'order_id' => $order->id,
                        'ledger_id' => $result['ledger_id'] ?? null
                    ]);
                } else {
                    \Log::warning("Failed to post cash invoice to ledger", [
                        'request_id' => $this->id,
                        'order_id' => $order->id,
                        'message' => $result['message'] ?? 'Unknown error'
                    ]);
                }
                
                return;
            }
            
            // Still online - post as PENDING for L2 approval
            \Log::info("Posting online invoice to ledger as pending (requires L2 approval)", [
                'request_id' => $this->id,
                'request_number' => $this->request_number,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'amount' => $order->total_price
            ]);
            
            DB::beginTransaction();
            
            $salesAccount = \App\Models\FIN\ConfigModel::getSalesRevenueAccount();
            $onlineAccount = \App\Models\FIN\ConfigModel::getOnlineBankAccount();
            
            if (!$salesAccount || !$onlineAccount) {
                throw new \Exception("Required accounts not found (Sales Revenue or Online Bank)");
            }
            
            // Build description with customer name
            $customerName = 'Unknown Customer';
            if (!empty($order->name)) {
                $customerName = trim($order->name);
            } elseif ($order->customer) {
                $customerName = $order->customer->full_name ?? $order->customer->name ?? 'Unknown';
            }
            
            $description = "Invoice #{$order->order_number} - Delivered ({$customerName})";
            
            // Create ledger entry as PENDING L2 (L2 is verification only, balance applied now)
            $ledger = \App\Models\FIN\LedgerModel::create([
                'transaction_date' => now(),
                'transaction_type' => \App\Models\FIN\LedgerModel::TYPE_INVOICE,
                'description' => $description,
                'from_account_id' => $salesAccount->id,
                'to_account_id' => $onlineAccount->id,
                'amount' => $order->total_price,
                'mode' => \App\Models\FIN\LedgerModel::MODE_ONLINE,
                'approval_status' => \App\Models\FIN\LedgerModel::STATUS_PENDING_L2,
                'balance_updated' => 1,
                'settlement_status' => 'open',
                'settled_amount' => 0.00,
                'order_id' => $order->id,
                'request_id' => $this->id,
                'created_by' => $this->updated_by ?? auth()->id() ?? 1,
                'comments' => "Approved via request #{$this->request_number} - Awaiting L2 verification"
            ]);
            
            // Apply balances immediately (L2 is verification only)
            $salesAccount->current_balance -= $order->total_price;
            $salesAccount->save();
            
            $onlineAccount->current_balance += $order->total_price;
            $onlineAccount->save();
            
            // Link ledger to order
            $order->ledger_transaction_id = $ledger->id;
            $order->save();
            
            // Link ledger to request
            $this->ledger_transaction_id = $ledger->id;
            $this->save();
            
            DB::commit();
            
            \Log::info("Online invoice posted to ledger as pending", [
                'request_id' => $this->id,
                'request_number' => $this->request_number,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'ledger_id' => $ledger->id,
                'amount' => $order->total_price,
                'status' => 'pending_l2_approval'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Failed to post invoice to ledger after approval", [
                'request_id' => $this->id,
                'request_number' => $this->request_number,
                'order_id' => $this->order_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Relationship: Get the order for invoice approval requests
     */
    public function order()
    {
        return $this->belongsTo(\App\Models\CRM\OrderModel::class, 'order_id', 'id');
    }
}

