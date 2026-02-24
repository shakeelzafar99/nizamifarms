<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseInventoryLogModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_crm_warehouse_inventory_log';
    protected $primaryKey = 'id';
    public $timestamps = false; // Only has created_at

    protected $fillable = [
        'warehouse_inventory_id',
        'product_id',
        'business_unit_id',
        'change_type',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'reference_type',
        'reference_id',
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
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(WarehouseInventoryModel::class, 'warehouse_inventory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
