<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical packet — the unit of consumption for weight and scan products.
 *
 * `cost` is this packet's share of its batch price. Packets 1..N-1 are rounded to
 * the paisa and the LAST packet takes the remainder, so SUM(cost) = total_cost
 * exactly. Never n x rounded.
 *
 * A barcode is NOT a packet id: two bags weighing the same print the SAME label.
 * Take-out therefore matches on barcode and picks the oldest matching packet, which
 * is both FIFO and the correct cost.
 */
class SupplyPacketModel extends Model
{
    protected $table = 't_fin_supply_packet';
    protected $primaryKey = 'id';
    public $timestamps = true;

    const STATUS_IN_STOCK = 'in_stock';
    const STATUS_CONSUMED = 'consumed';
    const STATUS_VOIDED   = 'voided';

    protected $fillable = [
        'batch_id',
        'product_id',
        'seq',
        'barcode',
        'plu',
        'qty',
        'cost',
        'status',
        'consumed_at',
        'consumed_by',
        'takeout_id',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'product_id' => 'integer',
        'seq' => 'integer',
        'plu' => 'integer',
        'qty' => 'decimal:3',
        'cost' => 'decimal:2',
        'consumed_at' => 'datetime',
        'takeout_id' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplyBatchModel::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplyProductModel::class, 'product_id');
    }
}
