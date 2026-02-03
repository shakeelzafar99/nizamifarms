<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;

class AssetCategoryModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_asset_categories';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'useful_life_years',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'useful_life_years' => 'integer',
        'sort_order' => 'integer'
    ];

    /**
     * Scope: Active only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Ordered by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get all active categories for dropdown
     */
    public static function getForDropdown()
    {
        return static::active()
            ->ordered()
            ->get(['id', 'code', 'name', 'useful_life_years']);
    }
}
