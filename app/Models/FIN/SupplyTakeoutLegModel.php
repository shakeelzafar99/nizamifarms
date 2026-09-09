<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which batch each part of a take-out came from.
 *
 * Normally one leg. A PIECES take-out may cross a batch boundary (owner ruling:
 * allowed) — 30 cups when the oldest batch has 20 left produces two legs at two
 * different unit costs, still one request.
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

    public function packet(): BelongsTo
    {
        return $this->belongsTo(SupplyPacketModel::class, 'packet_id');
    }
}
