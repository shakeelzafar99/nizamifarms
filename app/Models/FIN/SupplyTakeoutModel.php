<?php

namespace App\Models\FIN;

use App\Models\Request\RequestModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One take-out = one packet (or typed quantity) leaving storage for use, and one
 * expense request raised for it.
 *
 * status:
 *   pending   — the request is queued for approval
 *   approved  — approved, the expense is posted
 *   rejected  — the approver said no, and the stock went back on the shelf
 *   undone    — the scanner cancelled, and the stock went back on the shelf
 *   no_charge — a stock_only batch, or a cost that rounds to zero: nothing billed
 *
 * ⚠⚠ EXTENDS Model, NOT BaseModel — and every model in this feature must keep doing so.
 * BaseModel declares a REAL php property `protected string $status = "Success"`. From
 * outside a class that is invisible (protected, so __get falls through to the Eloquent
 * attribute), but INSIDE the class `$this->status` resolves to the php property and
 * returns "Success" — never the row's status. So `isPending()` below silently returned
 * false for every take-out, and nothing was ever settled. That is also why
 * RequestModel::processApproval writes `setAttribute('status', ...)` instead of
 * `$this->status =`. Any table with a `status` column is exposed to this.
 */
class SupplyTakeoutModel extends Model
{
    protected $table = 't_fin_supply_takeout';
    protected $primaryKey = 'id';
    public $timestamps = true;

    const STATUS_PENDING   = 'pending';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_UNDONE    = 'undone';
    const STATUS_NO_CHARGE = 'no_charge';

    /** The statuses that mean the stock has gone back onto the shelf. */
    const RESTORED_STATUSES = [self::STATUS_REJECTED, self::STATUS_UNDONE];

    protected $fillable = [
        'product_id',
        'qty',
        'unit',
        'cost',
        'source',
        'status',
        'request_id',
        'note',
        'taken_by',
        'taken_at',
        'settled_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'qty' => 'decimal:3',
        'cost' => 'decimal:2',
        'request_id' => 'integer',
        'taken_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplyProductModel::class, 'product_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(SupplyTakeoutLegModel::class, 'takeout_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RequestModel::class, 'request_id');
    }

    /** Still open — the scanner may cancel it, an approver may decide it. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
