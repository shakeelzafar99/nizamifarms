<?php

namespace App\Models\Khaas;

use App\Models\CRM\ProductModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one warehouse stock_in SHOULD have used, per the recipe that governed its date.
 *
 * ⭐⭐ WRITTEN FOR EVERY stock_in, NOT JUST FOR BATCHES. The packs that enter the
 * warehouse are the truth about what was made — the standing ruling behind
 * FrozenMonthService's "Made = stock_in". Three batches were closed at 0 in Aug-2026
 * and 111 packs were typed in by hand; a costing engine that only listened to batches
 * would have missed a quarter of the month. `source_kind` remembers which door each
 * lot came through so Month Review can say "449 made: 338 through a plan, 111 entered
 * directly" instead of pretending they are the same thing.
 *
 * ⚠ A later count or adjustment does NOT delete these rows. Warehouse counts have
 *   never carried a link to what they correct — a correction and a photoshoot take-out
 *   look identical — so inventing a reversal here would be guessing. Month Review
 *   already shows counts on their own line, and restating a month is the explicit
 *   "Re-apply recipe" action.
 */
class BatchConsumptionModel extends Model
{
    protected $table = 't_crm_khaas_batch_consumption';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public const SOURCE_PLAN   = 'plan';
    public const SOURCE_DIRECT = 'direct';

    protected $fillable = [
        'warehouse_log_id',
        'business_unit_id',
        'product_id',
        'batch_id',
        'source_kind',
        'recipe_id',
        'ingredient_id',
        'packets',
        'qty_base',
        'made_on',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'warehouse_log_id' => 'integer',
        'business_unit_id' => 'integer',
        'product_id'       => 'integer',
        'batch_id'         => 'integer',
        'recipe_id'        => 'integer',
        'ingredient_id'    => 'integer',
        'packets'          => 'integer',
        'qty_base'         => 'float',
        'made_on'          => 'date',
        'created_by'       => 'integer',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(IngredientModel::class, 'ingredient_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    public function isFromPlan(): bool
    {
        return $this->source_kind === self::SOURCE_PLAN;
    }
}
