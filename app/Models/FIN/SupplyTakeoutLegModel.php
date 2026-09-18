<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which batch each part of a take-out came from.
 *
 * Normally one leg. A POOLED take-out (weight or pieces) may cross a purchase boundary
 * (owner ruling: allowed, and silently) — 1.5 kg when the oldest bale has 0.27 kg left,
 * or 30 cups when the oldest batch has 20, produces two legs at two different rates,
 * still one request.
 *
 * ⭐ Round 3 made these legs the authority on whether a purchase has been drawn on. A
 * weighed purchase's packet rows stay `in_stock` for ever (they are intake audit), so
 * "has anything come out of this bale?" can only be answered here.
 */
class SupplyTakeoutLegModel extends Model
{
    protected $table = 't_fin_supply_takeout_leg';
    protected $primaryKey = 'id';

    /** created_at only — the DB default fills it; there is no updated_at by design. */
    public $timestamps = false;

    protected $fillable = [
        'takeout_id',
        'batch_id',
        'packet_id',
        'qty',
        'cost',
        'created_at',
    ];

    protected $casts = [
        'takeout_id' => 'integer',
        'batch_id' => 'integer',
        'packet_id' => 'integer',
        'qty' => 'decimal:3',
        'cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplyBatchModel::class, 'batch_id');
    }

    public function takeout(): BelongsTo
    {
        return $this->belongsTo(SupplyTakeoutModel::class, 'takeout_id');
    }

    public function packet(): BelongsTo
    {
        return $this->belongsTo(SupplyPacketModel::class, 'packet_id');
    }
}
