<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBatchModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_crm_product_batch';
    protected $primaryKey = 'id';
    public $timestamps = true;

    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'business_unit_id',
        'batch_number',
        'status',
        'started_at',
        'ended_at',
        'quantity_produced',
        'notes_start',
        'notes_end',
        'started_by',
        'ended_by',
        'warehouse_stock_log_id',
    ];

    protected $casts = [
        'quantity_produced' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
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

    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'started_by');
    }

    public function endedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'ended_by');
    }

    /**
     * Generate a batch number: BU{buId}-P{productId}-{date}-{seq}
     */
    public static function generateBatchNumber(int $productId, int $businessUnitId): string
    {
        $dateStr = now()->format('Ymd');
        $prefix = "B{$businessUnitId}-P{$productId}-{$dateStr}";
        
        // Count existing batches for this product today
        $todayCount = self::where('product_id', $productId)
            ->where('business_unit_id', $businessUnitId)
            ->whereDate('started_at', now()->toDateString())
            ->count();
        
        $seq = $todayCount + 1;
        return "{$prefix}-{$seq}";
    }

    /**
     * Check if there's an active (in_progress) batch for a product in a BU
     */
    public static function getActiveBatch(int $productId, int $businessUnitId): ?self
    {
        return self::where('product_id', $productId)
            ->where('business_unit_id', $businessUnitId)
            ->where('status', self::STATUS_IN_PROGRESS)
            ->orderBy('started_at', 'desc')
            ->first();
    }

    /**
     * Get elapsed time as human-readable string
     */
    public function getElapsedTimeAttribute(): string
    {
        $start = $this->started_at;
        $end = $this->ended_at ?? now();
        $diff = $start->diff($end);
        
        if ($diff->h > 0) {
            return $diff->h . 'h ' . $diff->i . 'm';
        }
        return $diff->i . 'm';
    }

    /**
     * Get the last completed batch for a product
     */
    public static function getLastCompleted(int $productId, int $businessUnitId): ?self
    {
        return self::where('product_id', $productId)
            ->where('business_unit_id', $businessUnitId)
            ->where('status', self::STATUS_COMPLETED)
            ->orderBy('ended_at', 'desc')
            ->first();
    }
}
