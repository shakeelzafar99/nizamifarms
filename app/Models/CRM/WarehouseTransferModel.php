<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTransferModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_crm_warehouse_transfer';
    protected $primaryKey = 'id';
    public $timestamps = true;

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'business_unit_id',
        'from_location',
        'to_location',
        'quantity',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'rejected_by');
    }

    /**
     * Scope: pending transfers for a product
     */
    public function scopePendingForProduct($query, int $productId, ?int $variantId = null)
    {
        $query->where('product_id', $productId)
              ->where('status', self::STATUS_PENDING);
        
        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        }
        
        return $query;
    }

    /**
     * Get total pending transfer qty TO store for a product
     */
    public static function pendingToStoreQty(int $productId, ?int $variantId = null): int
    {
        $query = self::where('product_id', $productId)
            ->where('status', self::STATUS_PENDING)
            ->where('to_location', 'store');
        
        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        }
        
        return $query->sum('quantity');
    }
}
