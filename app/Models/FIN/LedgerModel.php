<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use App\Models\Request\RequestModel;
use App\Models\CRM\OrderModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_ledger';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /**
     * Default business unit ID (Nizami Farms)
     */
    const DEFAULT_BUSINESS_UNIT_ID = 1;

    /**
     * Boot method to auto-set business_unit_id if not provided
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // ⭐ CRITICAL: Default to Nizami Farms (1) if business_unit_id not set
            // This prevents any ledger creation from failing due to missing BU
            if (empty($model->business_unit_id)) {
                $model->business_unit_id = self::DEFAULT_BUSINESS_UNIT_ID;
            }
        });
    }

    protected $fillable = [
        'transaction_date',
        'transaction_type',
        'business_unit_id', // ⭐ FK to t_fin_business_units - defaults to 1 (Nizami Farms)
        'description',
        'from_account_id',
        'to_account_id',
        'amount',
        'adjustment_amount',
        'mode',
        'approval_status',
        'settlement_status',
        'settled_amount',
        'settled_at',
        'settled_via_ledger_id',
        'approval_date',
        'approved_by',
        'external_source',
        'external_txn_id',
        'external_ref_id',
        'content_hash',
        'request_id',
        'order_id',
        'device',
        'comments',
        'settlement_metadata',
        'bill_image',
        'receiving_account_id', // ⭐ FK to t_fin_online_receiving_accounts - which bank received online payment
        // Customer-side transaction reference captured at approval time
        // (e.g. the transaction ID from the customer's banking app).
        'transaction_reference',
        'posted_date',
        'balance_updated', // Tracks whether account balances were applied (for L1-early-balance flow)
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'posted_date' => 'date',
        'approval_date' => 'date',
        'settled_at' => 'datetime',
        'amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'settled_amount' => 'decimal:2',
        'settlement_metadata' => 'array'
    ];

    // Transaction type constants
    const TYPE_INVOICE = 'invoice';
    const TYPE_EXPENSE = 'expense';
    const TYPE_VENDOR_PURCHASE = 'vendor_purchase';
    const TYPE_VENDOR_PAYMENT = 'vendor_payment';
    const TYPE_EMPLOYEE_DEPOSIT = 'employee_deposit';
    const TYPE_REIMBURSEMENT_ACCRUAL = 'reimbursement_accrual';
    const TYPE_REIMBURSEMENT_PAYMENT = 'reimbursement_payment';
    const TYPE_SALARY_ADVANCE = 'salary_advance';
    const TYPE_SALARY_PAYMENT = 'salary_payment';
    const TYPE_TRANSFER = 'transfer';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_OPENING_BALANCE = 'opening_balance';
    const TYPE_SETTLEMENT = 'expense_settlement';
    const TYPE_ORDER_PAYMENT = 'order_payment';

    // Tips Fund (Sep-2026). A tip rides inside the invoice, so the invoice row
    // books it as revenue; TYPE_TIP_COLLECTED immediately moves it back out of
    // revenue and into the TIPS_FUND liability, and TYPE_TIP_PAYOUT hands it
    // over from a real cash/bank account. Neither is an expense — deliberately,
    // so tip money can never land in the P&L. See TipsFundService.
    const TYPE_TIP_COLLECTED = 'tip_collected';
    const TYPE_TIP_PAYOUT = 'tip_payout';

    // Mode constants
    const MODE_CASH = 'cash';
    const MODE_ONLINE = 'online';

    // Approval status constants
    const STATUS_PENDING      = 'pending';      // Legacy / generic pending (treated as L1)
    const STATUS_PENDING_L1   = 'pending_l1';   // Explicit Level 1 pending
    const STATUS_PENDING_L2   = 'pending_l2';   // Explicit Level 2 pending
    const STATUS_APPROVED     = 'approved';
    const STATUS_REJECTED     = 'rejected';
    const STATUS_REVERSED     = 'reversed';     // For payment method changes after delivery

    /**
     * Human labels for transaction_type — the wording the Ledger Hub account page shows.
     *
     * Added Aug-2026 so the MOBILE ledger stops printing a generic "Transaction" for every row
     * (its own local map knew only 8 types and had no `vendor_payment`, which is most of what a
     * Frozen account actually contains).
     *
     * ⚠ This is NOT yet the only copy. The same wording is duplicated in
     * `fin/hub/account-detail.blade.php` ($typeLabels), `AccountActivityService::TYPE_LABELS`
     * (which says "Fund Transfer" for transfer) and `PendingLedgerActionsService::TYPE_LABELS`.
     * Those are live and working, so they were left alone rather than refactored underneath a
     * bug fix — but this constant is where they should converge. Match THIS map when changing
     * wording, and prefer it for anything new.
     */
    const TYPE_LABELS = [
        self::TYPE_INVOICE          => 'Invoice',
        self::TYPE_ORDER_PAYMENT    => 'Order Payment',
        self::TYPE_EMPLOYEE_DEPOSIT => 'Deposit',
        self::TYPE_EXPENSE          => 'Expense',
        self::TYPE_VENDOR_PURCHASE  => 'Vendor Purchase',
        self::TYPE_VENDOR_PAYMENT   => 'Vendor Payment',
        self::TYPE_SETTLEMENT       => 'Settlement',
        self::TYPE_TRANSFER         => 'Transfer',
        self::TYPE_ADJUSTMENT       => 'Adjustment',
        self::TYPE_SALARY_ADVANCE   => 'Salary Advance',
        self::TYPE_SALARY_PAYMENT   => 'Salary',
        self::TYPE_OPENING_BALANCE  => 'Opening Balance',
        self::TYPE_REIMBURSEMENT_ACCRUAL => 'Reimbursement',
        self::TYPE_REIMBURSEMENT_PAYMENT => 'Reimbursement Paid',
        self::TYPE_TIP_COLLECTED    => 'Tip Collected',
        self::TYPE_TIP_PAYOUT       => 'Tip Paid Out',
    ];

    /** Label for a transaction_type, falling back to a readable form of the raw key. */
    public static function typeLabel(?string $type): string
    {
        return self::TYPE_LABELS[$type] ?? ucwords(str_replace('_', ' ', (string) $type));
    }

    /**
     * Relationships
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'from_account_id', 'id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'to_account_id', 'id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'approved_by', 'id');
    }

    public function receivingAccount(): BelongsTo
    {
        return $this->belongsTo(OnlineReceivingAccountModel::class, 'receiving_account_id', 'id');
    }

    /**
     * All attached bill/receipt images (t_fin_ledger_images). `bill_image` on this
     * row stays a MIRROR of the first — see LedgerImageModel for the contract.
     * Guard queries with LedgerImageModel::ready() until the table's SQL has run.
     */
    public function images()
    {
        return $this->hasMany(LedgerImageModel::class, 'ledger_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RequestModel::class, 'request_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id', 'id');
    }

    /**
     * Business Unit relationship
     */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnitModel::class, 'business_unit_id', 'id');
    }

    public function settledViaDeposit(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'settled_via_ledger_id', 'id');
    }

    public function settlements()
    {
        return $this->hasMany(InvoiceSettlementModel::class, 'settlement_deposit_id', 'id');
    }

    public function invoiceSettlements()
    {
        return $this->hasMany(InvoiceSettlementModel::class, 'invoice_ledger_id', 'id');
    }

    /**
     * Scopes
     */
    public function scopeApproved($query)
    {
        return $query->where('approval_status', self::STATUS_APPROVED);
    }

    public function scopePending($query)
    {
        // Treat all pending stages as "pending" for query helpers
        return $query->whereIn('approval_status', [
            self::STATUS_PENDING,
            self::STATUS_PENDING_L1,
            self::STATUS_PENDING_L2,
        ]);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('transaction_type', $type);
    }

    public function scopeByAccount($query, $accountId)
    {
        return $query->where(function($q) use ($accountId) {
            $q->where('from_account_id', $accountId)
              ->orWhere('to_account_id', $accountId);
        });
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    public function scopeByMode($query, $mode)
    {
        return $query->where('mode', $mode);
    }

    /**
     * Filter by business unit
     */
    public function scopeForBusinessUnit($query, $businessUnitId)
    {
        if ($businessUnitId) {
            return $query->where('business_unit_id', $businessUnitId);
        }
        return $query;
    }

    public function scopeOpenInvoices($query)
    {
        return $query->where('transaction_type', self::TYPE_INVOICE)
                     ->where('settlement_status', 'open');
    }

    public function scopeSettledInvoices($query)
    {
        return $query->where('transaction_type', self::TYPE_INVOICE)
                     ->where('settlement_status', 'settled');
    }

    /**
     * Helper Methods
     */
    public function isPending(): bool
    {
        return in_array($this->approval_status, [
            self::STATUS_PENDING,
            self::STATUS_PENDING_L1,
            self::STATUS_PENDING_L2,
        ], true);
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::STATUS_APPROVED;
    }

    public function requiresApproval(): bool
    {
        return $this->mode === self::MODE_ONLINE;
    }

    /**
     * Generate content hash for deduplication
     */
    public static function generateContentHash($data): string
    {
        return md5(json_encode($data));
    }

    /**
     * Check if transaction already exists
     */
    public static function transactionExists($source, $txnId): bool
    {
        return static::where('external_source', $source)
                     ->where('external_txn_id', $txnId)
                     ->exists();
    }

    /**
     * Format amount for display
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2);
    }

    /**
     * Get transaction description with from/to accounts
     */
    public function getFullDescriptionAttribute(): string
    {
        $from = $this->fromAccount ? $this->fromAccount->account_name : 'Unknown';
        $to = $this->toAccount ? $this->toAccount->account_name : 'Unknown';
        
        return "{$from} → {$to}: {$this->formatted_amount}";
    }
}

