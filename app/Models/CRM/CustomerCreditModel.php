<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event in a customer's credit ("bucket") history.
 *
 * ⭐ The balance is ALWAYS SUM(amount) over the counting statuses — never a
 * stored column anywhere. `amount` is SIGNED (grants positive, consumes and
 * adjustments negative) so a balance read is a plain SUM with no second
 * direction column that could disagree with it.
 *
 * ⚠ Do not write this table directly. CustomerCreditService is the only
 * writer; it holds the locking, the ledger posting and the non-negative rule.
 */
class CustomerCreditModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_crm_customer_credit';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /** Money added to the bucket. amount > 0. */
    public const TYPE_GRANT = 'grant';
    /** Money spent on an order. amount < 0. */
    public const TYPE_CONSUME = 'consume';
    /** Manual correction (today: the zero-out). amount < 0. */
    public const TYPE_ADJUST = 'adjust';

    /** Grant awaiting approval — does NOT count toward any balance. */
    public const STATUS_PENDING = 'pending';
    /** Consume applied to an order that has not been delivered yet. */
    public const STATUS_RESERVED = 'reserved';
    /** Grant approved, or consume finalised on delivery. */
    public const STATUS_ACTIVE = 'active';
    /** Rejected, released or cancelled — does NOT count. */
    public const STATUS_VOIDED = 'voided';

    /**
     * Statuses that count toward the balance a manager may SPEND.
     * Reserved money is already spoken for by an undelivered order, so it must
     * count here or the same rupee could be applied to two orders.
     */
    public const SPENDABLE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_RESERVED];

    public const SOURCE_OVERPAYMENT  = 'overpayment';
    public const SOURCE_MANUAL       = 'manual';
    public const SOURCE_CANCELLATION = 'cancellation';
    public const SOURCE_ZERO_OUT     = 'zero_out';

    /** The sentinel discount code that marks a consume on an order. */
    public const DISCOUNT_CODE = 'ACCOUNT_BALANCE';

    protected $fillable = [
        'customer_id',
        'entry_type',
        'amount',
        'status',
        'order_id',
        'source',
        'source_payment_id',
        'signal_id',
        'receiving_account_id',
        'ledger_transaction_id',
        'reason',
        'created_by',
        'approved_by',
        'approved_at',
        'voided_by',
        'voided_at',
        'voided_reason',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'customer_id' => 'integer',
        'order_id'    => 'integer',
        'approved_at' => 'datetime',
        'voided_at'   => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerModel::class, 'customer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id');
    }

    public function scopeSpendable($query)
    {
        return $query->whereIn('status', self::SPENDABLE_STATUSES);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /** Human label for the history list. */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->entry_type) {
            self::TYPE_GRANT   => 'Added',
            self::TYPE_CONSUME => 'Used',
            self::TYPE_ADJUST  => 'Adjusted',
            default            => ucfirst((string) $this->entry_type),
        };
    }
}
