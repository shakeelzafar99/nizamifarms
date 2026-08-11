<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A REQUEST for stock to be moved warehouse → store.
 *
 * ⭐⭐ This is deliberately NOT a status on t_crm_warehouse_transfer. There,
 * status='pending' already means the stock has physically LEFT the warehouse
 * (initiateTransfer debits the warehouse immediately and writes a ledger row), and
 * the whole app depends on that reading — the "In transit" row, the Combined Total
 * exclusion, pendingToStoreQty(), the batched push, and all five approve surfaces.
 *
 * A request has moved NOTHING. Keeping it in its own table means a declined request
 * cannot touch stock by construction — whereas the transfer table's reject path
 * RETURNS stock to the warehouse, which for a request would create inventory out of
 * thin air.
 *
 * Lifecycle: pending → accepted (a real transfer is created, id kept in transfer_id)
 *                    → declined (reason, no stock touched)
 *                    → cancelled (by the requester, no stock touched)
 */
class WarehouseTransferRequestModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_crm_warehouse_transfer_request';
    protected $primaryKey = 'id';
    public $timestamps = true;

    const STATUS_PENDING   = 'pending';
    const STATUS_ACCEPTED  = 'accepted';
    const STATUS_DECLINED  = 'declined';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'business_unit_id',
        'quantity',
        'status',
        'notes',
        'requested_by',
        'accepted_by',
        'accepted_at',
        'accepted_quantity',
        'transfer_id',
        'declined_by',
        'declined_at',
        'decline_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * pending_key is a STORED GENERATED column (product_id while pending, else NULL)
     * backing the uq_wtr_one_pending unique index. It must never be written.
     */
    protected $guarded = ['pending_key'];

    // ─── Relationships ───────────────────────────────────────────────────────

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

    public function accepter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'accepted_by');
    }

    public function decliner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'declined_by');
    }

    /** The transfer that was created when this request was accepted (NULL until then). */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransferModel::class, 'transfer_id');
    }

    // ─── Availability gate ───────────────────────────────────────────────────

    /**
     * True once PENDING-TRANSFER-REQUESTS-AUG11-2026.sql has been run.
     *
     * Deploy here is manual and web files can land before the SQL does, so every
     * read/write goes through this — with the table absent the feature simply
     * hides itself instead of 500ing the whole products page. Same pattern as
     * WarehouseTransferModel::supportsCountedBy(). Cached per request because the
     * products page would otherwise hit information_schema once per product.
     */
    public static function supported(): bool
    {
        static $supported = null;
        if ($supported === null) {
            try {
                $supported = \Illuminate\Support\Facades\Schema::hasTable('t_crm_warehouse_transfer_request');
            } catch (\Throwable $e) {
                $supported = false;
            }
        }
        return $supported;
    }

    // ─── Queries ─────────────────────────────────────────────────────────────

    /**
     * The single open request for a product, or null. At most one can exist —
     * enforced by uq_wtr_one_pending, not just by convention.
     */
    public static function pendingFor(int $productId, int $businessUnitId): ?self
    {
        if (!self::supported()) {
            return null;
        }

        return self::where('product_id', $productId)
            ->where('business_unit_id', $businessUnitId)
            ->where('status', self::STATUS_PENDING)
            ->first();
    }

    /**
     * Open requests for a BU, keyed by product_id — for badges on the products grid.
     * Returns an empty collection when the table is absent.
     */
    public static function pendingByProduct(int $businessUnitId)
    {
        if (!self::supported()) {
            return collect();
        }

        return self::where('business_unit_id', $businessUnitId)
            ->where('status', self::STATUS_PENDING)
            ->get()
            ->keyBy('product_id');
    }
}
