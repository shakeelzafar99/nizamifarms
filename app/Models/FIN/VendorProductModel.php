<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

class VendorProductModel extends Model
{
    protected $table = 't_fin_vendor_products';

    protected $fillable = [
        'vendor_id',
        'product_name',
        'unit',
        'rate_per_unit',
        'is_active'
    ];

    protected $casts = [
        'rate_per_unit' => 'decimal:2',
        'is_active' => 'boolean',
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

