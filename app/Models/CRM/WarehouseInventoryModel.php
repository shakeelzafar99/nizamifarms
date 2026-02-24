<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseInventoryModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_crm_warehouse_inventory';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'business_unit_id',
        'quantity',
        'unit',
        'min_stock_level',
        'max_stock_level',
        'warehouse_location',
        'last_counted_at',
        'last_counted_by',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'min_stock_level' => 'integer',
        'max_stock_level' => 'integer',
        'is_active' => 'boolean',
        'last_counted_at' => 'datetime',
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

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FIN\BusinessUnitModel::class, 'business_unit_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WarehouseInventoryLogModel::class, 'warehouse_inventory_id');
    }

    /**
     * Get or create warehouse inventory record for a product/variant/BU
     */
    public static function getOrCreate(int $productId, ?int $variantId, int $businessUnitId): self
    {
        return self::firstOrCreate([
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'business_unit_id' => $businessUnitId,
        ], [
            'quantity' => 0,
            'unit' => 'pcs',
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Adjust quantity and log the change
     */
    public function adjustQuantity(int $change, string $changeType, ?string $notes = null, ?string $referenceType = null, ?int $referenceId = null): void
    {
        $before = $this->quantity;
        $this->quantity += $change;
        $this->updated_by = auth()->id();
        $this->save();

        // Create log entry
        WarehouseInventoryLogModel::create([
            'warehouse_inventory_id' => $this->id,
            'product_id' => $this->product_id,
            'business_unit_id' => $this->business_unit_id,
            'change_type' => $changeType,
            'quantity_before' => $before,
            'quantity_change' => $change,
            'quantity_after' => $this->quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
