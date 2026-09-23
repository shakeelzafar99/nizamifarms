<?php

namespace App\Models\Khaas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient inside one recipe version.
 *
 * `qty_per_basis` is in the ingredient's BASE unit and covers the WHOLE reference
 * batch, not one packet. The per-packet figure is derived at read time; see
 * RecipeModel::perPacket().
 *
 * `is_optional` marks a line that is a pinch rather than a measure (a seasoning). Such
 * a line still consumes and still costs, but it is left out of the "we used more than
 * we bought" warning, because nobody counts grams of chaat masala.
 */
class RecipeLineModel extends Model
{
    protected $table = 't_crm_khaas_recipe_line';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'recipe_id',
        'ingredient_id',
        'qty_per_basis',
        'is_optional',
        'sort_order',
        'created_at',
    ];

    protected $casts = [
        'recipe_id'     => 'integer',
        'ingredient_id' => 'integer',
        'qty_per_basis' => 'float',
        'is_optional'   => 'boolean',
        'sort_order'    => 'integer',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(IngredientModel::class, 'ingredient_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(RecipeModel::class, 'recipe_id');
    }
}
