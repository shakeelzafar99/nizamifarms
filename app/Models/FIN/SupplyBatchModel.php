<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bulk purchase. The FIFO source every take-out draws from.
 *
 * `qty_remaining` / `cost_remaining` are maintained inside the same transaction as
 * the packets. For weight/scan products they must always equal the in-stock packets'
 * totals (the SQL file ships the invariant query); for pieces products there are no
 * packet rows and these two columns ARE the stock.
 *
 * status:
 *   confirmed  — normal; the money leg is posted and `ledger_id` is set
 *   stock_only — opening stock already expensed in an earlier month: total_cost 0,
 *                no ledger row, and its take-outs charge nothing
 *   voided     — booked in error and reversed (only possible while nothing is consumed)
 */
class SupplyBatchModel extends Model
{
    protected $table = 't_fin_supply_batch';
    protected $primaryKey = 'id';
    public $timestamps = true;

    const STATUS_CONFIRMED  = 'confirmed';
    const STATUS_STOCK_ONLY = 'stock_only';
    const STATUS_VOIDED     = 'voided';

    protected $fillable = [
        'product_id',
        'mode',
        'packet_count',
        'qty_total',
        'qty_remaining',
        'total_cost',
        'cost_remaining',
        'unit_cost',
        'purchase_date',
        'payment_source_account_id',
        'receiving_account_id',
        'ledger_id',
        'status',
        'note',
        'bill_image',
        'booked_by',
        'voided_by',
        'voided_at',
        'client_uuid',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'packet_count' => 'integer',
        'qty_total' => 'decimal:3',
        'qty_remaining' => 'decimal:3',
        'total_cost' => 'decimal:2',
        'cost_remaining' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'purchase_date' => 'date',
        'payment_source_account_id' => 'integer',
        'receiving_account_id' => 'integer',
        'ledger_id' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplyProductModel::class, 'product_id');
    }

    public function packets(): HasMany
    {
        return $this->hasMany(SupplyPacketModel::class, 'batch_id');
    }

    public function paymentSourceAccount(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'payment_source_account_id');
    }

    public function receivingAccount(): BelongsTo
    {
        return $this->belongsTo(OnlineReceivingAccountModel::class, 'receiving_account_id');
    }

    /** A stock_only batch tracks quantity but never bills anything. */
    public function chargesMoney(): bool
    {
        return $this->status === self::STATUS_CONFIRMED && (float) $this->total_cost > 0;
    }

    /**
     * "stock paid from Online Bank · HBL" — the phrase appended to every take-out's
     * description so the ORIGINAL paying account is readable on screens that only
     * ever print a description (Expenses, Ledger, Reports drill, HQ, approval card).
     * The take-out's own payment source stays SUPPLIES_STOCK, because that is the
     * account this leg actually moves.
     */
    public function parentAccountPhrase(): string
    {
        if ($this->status === self::STATUS_STOCK_ONLY) {
            return 'opening stock (already expensed)';
        }

        $account = $this->relationLoaded('paymentSourceAccount')
            ? $this->paymentSourceAccount
            : $this->paymentSourceAccount()->first();

        if (!$account) {
            return 'stock purchase';
        }

        $phrase = 'stock paid from ' . $account->account_name;

        if ($this->receiving_account_id) {
            $bank = OnlineReceivingAccountModel::find($this->receiving_account_id);
            if ($bank && $bank->short_code) {
                $phrase .= ' · ' . $bank->short_code;
            }
        }

        return $phrase;
    }
}
