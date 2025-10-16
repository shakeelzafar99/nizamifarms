<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

class VendorPurchaseItemModel extends Model
{
    protected $table = 't_fin_vendor_purchase_items';

    protected $fillable = [
        'ledger_id',
        'vendor_product_id',
        'product_name',
        'quantity',
        'unit',
        'rate_per_unit',
        'line_total'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'rate_per_unit' => 'decimal:2',
        'line_total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relationship: Belongs to Ledger
     */
    public function ledger()
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_id');
    }

    /**
     * Relationship: Belongs to Vendor Product (nullable)
     */
    public function vendorProduct()
    {
        return $this->belongsTo(VendorProductModel::class, 'vendor_product_id');
    }

    /**
     * Scope: For a specific ledger transaction
     */
    public function scopeForLedger($query, $ledgerId)
    {
        return $query->where('ledger_id', $ledgerId);
    }
}

