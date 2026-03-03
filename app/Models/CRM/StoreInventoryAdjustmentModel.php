<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreInventoryAdjustmentModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_crm_store_inventory_adjustment';
    protected $primaryKey = 'id';
    public $timestamps = false; // Only has created_at

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'business_unit_id',
        'change_type',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'notes',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'quantity_before' => 'integer',
        'quantity_change' => 'integer',
        'quantity_after' => 'integer',
        'created_at' => 'datetime',
    ];

    // Relationships
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariantModel::class, 'product_variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
