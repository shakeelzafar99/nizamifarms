<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;

class BusinessUnitModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_business_units';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'short_code',
        'description',
        'color_hex',
        'display_order',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'display_order' => 'integer'
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
     * Get all active business units for dropdown
     */
    public static function getForDropdown()
    {
        return static::active()
            ->ordered()
            ->get(['id', 'code', 'name']);
    }

    /**
     * Get by code
     */
    public static function getByCode(string $code)
    {
        return static::where('code', $code)->first();
    }

    /**
     * Get Nizami Farms business unit
     */
    public static function getNizamiFarms()
    {
        return static::getByCode('NF');
    }

    /**
     * Get NF Foods business unit
     */
    public static function getNFFoods()
    {
        return static::getByCode('NF_FOODS');
    }
}
