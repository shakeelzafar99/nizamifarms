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

            // ⭐⭐ WHOSE HAND POSTED THIS ROW (Sep-2026).
            //
            // `created_by` cannot answer that question: on an expense row
            // LedgerPostingService sets it to `$request->requester_user_id` — the person
            // the expense BELONGS to, not the person who pressed the button. On the
            // replica that is 628 rows naming the wrong person, e.g. Haider's petrol
            // posted by Shabib reads "Haider". `entered_by` is the logged-in actor.
            //
            // One engine: every ledger writer goes through Eloquent create()/save()
            // (the same fact LedgerAuditObserver relies on), so this hook is the only
            // place it needs to be set. The two raw insertGetId calls in
            // EmployeeLoanController bypass Eloquent and pass the column themselves.
            //
            // ⚠ Wrapped: this runs inside live money transactions. A console command or
            // a request with no session must never be able to fail a payment — a row
            // with no actor simply reads as "System".
            //
            // ⚠⚠ DEPLOY-ORDER GUARD. Production is uploaded by hand, and the one way this
            // column can break the ledger is the web files landing BEFORE the SQL: every
            // INSERT would then carry an unknown column and every delivery, expense and
            // settlement would fail. So the hook first asks whether the column exists —
            // once per process, memoised — and stays silent until it does.
            try {
                if ($model->entered_by === null && self::hasEnteredByColumn()) {
                    $model->entered_by = auth()->id();
                }
            } catch (\Throwable $e) {
                // no auth context (console/queue) — leave it null
            }
        });
    }

    /** Memoised once per process: does t_fin_ledger.entered_by exist yet? (see boot()) */
    private static ?bool $hasEnteredBy = null;

    /** Public so the few readers that name the column in raw SQL can degrade the same way. */
    public static function hasEnteredByColumn(): bool
    {
        if (self::$hasEnteredBy === null) {
            try {
                self::$hasEnteredBy = \Schema::hasColumn('t_fin_ledger', 'entered_by');
            } catch (\Throwable $e) {
                self::$hasEnteredBy = false;
            }
        }
        return self::$hasEnteredBy;
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
        'entered_by', // ⭐ the logged-in ACTOR (created_by is the requester on expense rows)
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
    /**
     * Storage (Supplies): buying packaging stock in bulk.
     * ⭐ Deliberately NOT 'expense'. The cash leaves now, but the COST is booked one
     * packet at a time as the stock is used, so this row must stay invisible to every
     * expense/P&L query (they all filter transaction_type = 'expense'). Reports and HQ
     * show it on their own "Supplies bought" line so the money-out is still visible.
     */
    const TYPE_SUPPLY_PURCHASE = 'supply_purchase';

    // Tips Fund (Sep-2026). A tip rides inside the invoice, so the invoice row
    // books it as revenue; TYPE_TIP_COLLECTED immediately moves it back out of
    // revenue and into the TIPS_FUND liability, and TYPE_TIP_PAYOUT hands it
    // over from a real cash/bank account. Neither is an expense — deliberately,
    // so tip money can never land in the P&L. See TipsFundService.
    const TYPE_TIP_COLLECTED = 'tip_collected';
    const TYPE_TIP_PAYOUT = 'tip_payout';

    /**
     * Money handed BACK to a customer whose delivered order was returned (Sep-2026).
     *
     * Shape: from = the account the money is leaving (the till it was settled into,
     * or the bank the online payment landed in) → to = Sales Revenue. Through the
     * balance engine that is "cash out, revenue un-recognised", i.e. the exact
     * counter-entry to the invoice — WITHOUT touching the invoice row, which has to
     * stay because the sale and the cash really did happen on their own dates.
     *
     * ⭐ Deliberately NOT 'expense': a refund is negative revenue, not a cost. Every
     * expense/P&L query filters transaction_type = 'expense', so booking it there
     * would put returns into the expense line and overstate both revenue and costs.
     * Ledger-side revenue readers subtract this type instead — see
     * LedgerKpiService and HQ ExecutiveClosingService.
     *
     * ⚠ Order-based revenue (ProfitRevenueSql and its callers) must NOT subtract it:
     * those already drop the order the moment its status leaves `delivered`.
     * Subtracting there as well would count the return twice. See OrderReturnService.
     */
    const TYPE_ORDER_REFUND = 'order_refund';

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
        self::TYPE_SUPPLY_PURCHASE  => 'Storage stock purchase',
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
        self::TYPE_ORDER_REFUND     => 'Order Refund',
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

    /** ⭐ The logged-in person who actually posted this row (null on pre-Sep-2026 / system rows). */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'entered_by', 'id');
    }

    /**
     * ⭐⭐ THE ONE "WHOSE HAND" RULE — every reader must come through here.
     *
     * The actor is `entered_by`, falling back to `created_by` for rows written before
     * Sep-2026. NULL means nobody was signed in (console, cron, webhook) — that reads
     * as "System" and counts as somebody else's hand, because it is certainly not the
     * viewer's own.
     */
    public function actorId(): ?int
    {
        $v = $this->entered_by ?? $this->created_by;
        return $v ? (int) $v : null;
    }

    /** The name to show for the hand that posted this row. */
    public function actorName(): string
    {
        $u = $this->entered_by ? $this->enteredBy : ($this->created_by ? $this->createdBy : null);
        return $u->fullname ?? 'System';
    }

    /**
     * Is this row somebody ELSE's doing, from $viewerId's point of view?
     *
     * ⭐ Owner's ruling (Sep-22-2026): "my hand" means I ENTERED it **or** I APPROVED it.
     * Without the approver half, every rider settlement Shabib waved through would come
     * back at him as somebody else's entry — which is exactly the noise that makes a
     * notification useless.
     */
    public function isSomeoneElsesHand(int $viewerId): bool
    {
        if ($viewerId <= 0) {
            return false;
        }
        if ($this->actorId() === $viewerId) {
            return false;
        }
        if ((int) ($this->approved_by ?? 0) === $viewerId) {
            return false;
        }
        return true;
    }

    /**
     * What this row did to $accountId's stored balance, signed.
     *
     * ⚠ Mirrors BalancePostingService::move() exactly — asset/expense/income accounts are
     * debit-arithmetic (TO +, FROM −) and `vendor_purchase` is stored with its sides
     * reversed. Every till-facing screen must agree with the engine that moved the money,
     * or the page contradicts the balance it is printed next to.
     */
    public function effectOnAccount(int $accountId): float
    {
        $reversed = $this->transaction_type === self::TYPE_VENDOR_PURCHASE;
        $creditsAccount = $reversed
            ? (int) $this->from_account_id === $accountId
            : (int) $this->to_account_id === $accountId;
        return $creditsAccount ? (float) $this->amount : -(float) $this->amount;
    }

    /** How many days earlier than the day it was typed does this row claim to be? */
    public function daysBackdated(): int
    {
        if (!$this->created_at || !$this->transaction_date) {
            return 0;
        }
        $typed = \Carbon\Carbon::parse($this->created_at)->startOfDay();
        $shows = \Carbon\Carbon::parse($this->transaction_date)->startOfDay();
        return max(0, $shows->diffInDays($typed, false));
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
     * ⭐ "Nothing to collect" — the ONE rule for Rs 0 cash invoices (Sep-2026).
     *
     * A free / replacement order (every line FREE, priced 0, or 100% discounted) is
     * still invoiced at delivery so the order keeps its ledger link and the audit's
     * "delivered without a ledger entry" list stays clean — but there is no cash for
     * the rider to hand over, so the row is settled the moment it is posted. Before
     * this rule such rows sat 'open' forever: the settle lists drop anything under
     * Rs 0.01 (the SH-21250 guard), so no surface could ever settle them.
     *
     * Only CASH invoices: an online Rs 0 invoice keeps its normal approval flow.
     *
     * Identity = "settled, but no cash was ever settled on it" (settled_amount 0).
     * Deliberately NOT keyed on the current amount: a re-price rewrites amount
     * first and then asks this to decide whether to reopen. Exact on the data —
     * every real settlement writes settled_amount ≥ amount > 0 (engine rule), and
     * the replica has 0 settled cash invoices with settled_amount 0 and amount > 0.
     */
    public function isSettledNothingToCollect(): bool
    {
        return $this->transaction_type === self::TYPE_INVOICE
            && $this->settlement_status === 'settled'
            && $this->mode === self::MODE_CASH
            && round((float) ($this->settled_amount ?? 0), 2) < 0.01;
    }

    /**
     * True only when the holder actually handed cash over. Every "already settled"
     * guard (cancel, rider change, payment-method change, post-settlement correction
     * absorb) must ask THIS, not settlement_status: a free order auto-settled at
     * posting has moved no money, so those actions stay allowed on it.
     */
    public function isSettledWithCash(): bool
    {
        return $this->settlement_status === 'settled' && !$this->isSettledNothingToCollect();
    }

    /**
     * Keep the nothing-to-collect flag in step with the amount. Call after the
     * amount is (re)written and BEFORE save(). Two directions, one place:
     *   amount → 0 on an unsettled cash invoice  ⇒ settle it (nothing to collect)
     *   amount → > 0 on a nothing-to-collect row ⇒ reopen it (the rider now holds cash)
     * A row with real settled cash is never touched here.
     */
    public function refreshNothingToCollectSettlement(string $context): void
    {
        if ($this->transaction_type !== self::TYPE_INVOICE || $this->mode !== self::MODE_CASH) {
            return;
        }
        if (round((float) ($this->settled_amount ?? 0), 2) >= 0.01) {
            return; // real cash was settled on this row — not ours to flip
        }
        $stamp = now()->format('Y-m-d H:i:s');
        $isZero = round((float) $this->amount, 2) < 0.01;

        if ($isZero && $this->settlement_status !== 'settled') {
            $this->settlement_status = 'settled';
            $this->settled_amount = 0.00;
            $this->settled_at = now();
            $this->settled_via_ledger_id = null;
            $this->comments = trim(($this->comments ?? '') .
                " | Rs 0 invoice — nothing to collect, auto-settled ({$context}, {$stamp})", ' |');
        } elseif (!$isZero && $this->isSettledNothingToCollect()) {
            $this->settlement_status = 'open';
            $this->settled_amount = 0.00;
            $this->settled_at = null;
            $this->settled_via_ledger_id = null;
            $this->comments = trim(($this->comments ?? '') .
                " | Re-priced to Rs " . number_format((float) $this->amount, 2) . " — reopened, rider now holds cash ({$context}, {$stamp})", ' |');
        }
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

