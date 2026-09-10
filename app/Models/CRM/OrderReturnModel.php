<?php

namespace App\Models\CRM;

use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One returned order — what the manager decided about the MONEY and the GOODS.
 *
 * ⭐⭐ Every report reads the outcome from THIS row, never from the order status.
 * The status only says "this order came back"; it cannot say whether the customer
 * was refunded, credited, or never paid in the first place, nor whether the meat
 * went into the chiller or the bin. Inferring any of that from the status would be
 * guessing, and the Returns section exists precisely to stop the guessing.
 *
 * ⚠ Do not write this table directly — OrderReturnService is the only writer. It
 * holds the money postings, the locking and the stock put-back rules.
 *
 * ⚠ There is deliberately NO `status` column: this class extends BaseModel, whose
 * protected string $status = "Success" shadows any such attribute inside the model
 * (see the BaseModel status-shadow trap). Workflow state is read from the
 * timestamps — decided_at, completed_at — which cannot be shadowed.
 */
class OrderReturnModel extends BaseModel
{
    protected $table = 't_crm_order_return';
    protected $primaryKey = 'id';
    public $timestamps = true;

    // ---- goods_action -------------------------------------------------
    /** The goods are good: they go back into stock once the store scans them. */
    public const GOODS_RESTOCK = 'restock';
    /** Damaged / spoiled / discarded. Nothing is scanned and NO stock is written up. */
    public const GOODS_WASTED  = 'wasted';

    // ---- meat_section (where returned FRESH meat goes) -----------------
    public const SECTION_FREEZER = 'freezer';
    public const SECTION_CHILLER = 'chiller';

    // ---- money_action -------------------------------------------------
    /** The order's invoice row was reversed — the sale is unwound entirely. */
    public const MONEY_REVERSED = 'reversed';
    /** Money handed back out of the account it arrived in (an order_refund ledger row). */
    public const MONEY_REFUND   = 'refund';
    /** Money kept, but owed to the customer as account balance (a credit grant). */
    public const MONEY_CREDIT   = 'credit';
    /** Nothing to do — the customer never paid anything. */
    public const MONEY_NONE     = 'none';

    // ---- money_state (what the ledger looked like when the return was taken) ----
    /** Cash, rider still holds it (invoice open, nothing settled). */
    public const STATE_CASH_UNSETTLED = 'S1';
    /** Cash, already handed over to the office. */
    public const STATE_CASH_SETTLED   = 'S2';
    /** Cash, part in the till and part still with the rider. */
    public const STATE_CASH_PARTIAL   = 'S3';
    /** Online and the invoice has reached the balances. */
    public const STATE_ONLINE_PAID    = 'S4';
    /** Online but still waiting at Level 1 — no money seen yet. */
    public const STATE_ONLINE_PENDING = 'S5';
    /** Shop / Qurbani — settled through their own payment flow. Refused for now. */
    public const STATE_INCREMENTAL    = 'S6';

    protected $fillable = [
        'order_id',
        'reason',
        'goods_action',
        'meat_section',
        'money_state',
        'money_action',
        'amount',
        'refund_ledger_id',
        'credit_grant_id',
        'regrant_id',
        'tip_returned',
        'tip_amount',
        'decided_by',
        'decided_at',
        'completed_at',
        'completed_by',
        'completed_short',
        'short_reason',
        'notes',
        'lines_snapshot',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'tip_amount'      => 'decimal:2',
        'tip_returned'    => 'boolean',
        'completed_short' => 'boolean',
        'decided_at'      => 'datetime',
        'completed_at'    => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItemModel::class, 'return_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'decided_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'completed_by');
    }

    /** Is the store still expected to scan anything back in? */
    public function awaitingPutBack(): bool
    {
        return $this->goods_action === self::GOODS_RESTOCK && $this->completed_at === null;
    }
}
