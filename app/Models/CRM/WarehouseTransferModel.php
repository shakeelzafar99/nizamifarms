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
        'counted_by',
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
     * Who physically COUNTED the stock — often not the person who approved.
     * Optional: NULL means "not recorded", which is what every transfer
     * predating Aug-2026 means. Never inferred from the approver.
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'counted_by');
    }

    /**
     * True once BATCH-16 has been run (t_crm_warehouse_transfer.counted_by).
     *
     * Deploy here is manual and web files can land before the SQL does, so
     * every read/write of counted_by is gated on this — with the column absent
     * the feature simply does nothing and approvals behave exactly as they did
     * before, instead of 500ing on an unknown column. Cached per request:
     * Schema::hasColumn hits information_schema, and the transfer list renders
     * this once per row.
     */
    public static function supportsCountedBy(): bool
    {
        static $supported = null;
        if ($supported === null) {
            try {
                $supported = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_warehouse_transfer', 'counted_by');
            } catch (\Throwable $e) {
                $supported = false;
            }
        }
        return $supported;
    }

    /**
     * Normalise a submitted "counted by" value into something storable.
     *
     * Returns:
     *   null  => nothing was recorded (field left empty) — always allowed,
     *            and what every pre-Aug-2026 transfer means
     *   int   => a real, ACTIVE user id
     *   false => a value WAS supplied but is not a valid active user
     *
     * The false case is kept distinct from null on purpose: silently dropping a
     * bad id would leave the audit trail reading "not recorded" while the person
     * approving believes they recorded someone. Callers surface it instead.
     *
     * "Active" is `is_active = 1`, matching RiderController::getActiveUsers —
     * the same list that populates the picker. (t_sys_user uses the 1/0
     * convention, not Y/N.)
     */
    public static function normaliseCountedBy($value)
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        if (!is_numeric($value)) {
            return false;
        }
        $id = (int) $value;
        if ($id <= 0) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table('t_sys_user')
            ->where('id', $id)
            ->where('is_active', 1)
            ->exists() ? $id : false;
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
