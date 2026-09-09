<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only storage movement ledger. Never updated, never deleted.
 *
 *   in       stock booked in (one row per packet, or one per pieces batch)
 *   out      taken out for use
 *   undo     a take-out was rejected or cancelled and the stock went back
 *   void     a whole batch was reversed
 *   correct  a batch's price was corrected (qty/cost carry the NEW total)
 *   count    a physical shelf count (qty = what was counted; the note carries the gap)
 *
 * `product_name` is a snapshot so the history stays readable after a rename.
 * No updated_at by design.
 */
class SupplyLogModel extends Model
{
    protected $table = 't_fin_supply_log';
    protected $primaryKey = 'id';
    public $timestamps = false;

    const ACTION_IN      = 'in';
    const ACTION_OUT     = 'out';
    const ACTION_UNDO    = 'undo';
    const ACTION_VOID    = 'void';
    /** A batch's price was entered wrong and has been corrected. */
    const ACTION_CORRECT = 'correct';
    /** Someone physically counted the shelf against what the system believed. */
    const ACTION_COUNT   = 'count';

    protected $fillable = [
        'action',
        'product_id',
        'product_name',
        'batch_id',
        'packet_id',
        'takeout_id',
        'qty',
        'unit',
        'cost',
        'source',
        'note',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'batch_id' => 'integer',
        'packet_id' => 'integer',
        'takeout_id' => 'integer',
        'qty' => 'decimal:3',
        'cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];
}
