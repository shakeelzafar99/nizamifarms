<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

class VendorProductModel extends Model
{
    protected $table = 't_fin_vendor_products';

    protected $fillable = [
        'vendor_id',
        'product_name',
        // Level-1 category (Chicken / Mutton / Beef ...) used by the
        // Category Report to compare purchases against sales. Values come
        // from t_crm_prod_product.attribute_1 so both sides line up.
        'category_level_1',
        'unit',
        'rate_per_unit',
        'is_active',
        'is_default'
    ];

    protected $casts = [
        'rate_per_unit' => 'decimal:2',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relationship: Belongs to Vendor
     */
    public function vendor()
    {
        return $this->belongsTo(VendorModel::class, 'vendor_id');
    }

    /**
     * Relationship: Has many purchase items
     */
    public function purchaseItems()
    {
        return $this->hasMany(VendorPurchaseItemModel::class, 'vendor_product_id');
    }

    /**
     * Scope: Active products only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    /**
     * Scope: For a specific vendor
     */
    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
}

