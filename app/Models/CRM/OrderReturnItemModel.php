<?php

namespace App\Models\CRM;

use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE packet scanned back into stock on a returned order.
 *
 * Shaped deliberately like t_crm_overnight_item (plu / barcode / quantity / unit)
 * because a returned meat packet BECOMES an overnight packet — `overnight_item_id`
 * links the two, so the Overnight board's ageing and verification apply to
 * returned stock exactly as they do to anything else on the shelf.
 *
 * One scan = one row, always. Two identical packets are two rows (the same rule
 * Overnight uses); nothing here ever merges.
 */
class OrderReturnItemModel extends BaseModel
{
    protected $table = 't_crm_order_return_item';
    protected $primaryKey = 'id';
    public $timestamps = false; // created_at only, stamped by the DB default

    /** Frozen (BU 2) goods — these go back to the store variant quantity. */
    public const DEST_STORE_STOCK = 'store_stock';
    public const DEST_FREEZER     = 'freezer';
    public const DEST_CHILLER     = 'chiller';

    protected $fillable = [
        'return_id',
        'order_id',
        'line_item_id',
        'product_id',
        'business_unit_id',
        'plu',
        'barcode',
        'quantity',
        'unit',
        'destination',
        'overnight_item_id',
        'scanned_by',
        'scanned_at',
        'created_at',
    ];

    protected $casts = [
        'quantity'   => 'decimal:3',
        'scanned_at' => 'datetime',
    ];

    public function returnRecord(): BelongsTo
    {
        return $this->belongsTo(OrderReturnModel::class, 'return_id');
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(OrderLineItemModel::class, 'line_item_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'scanned_by');
    }
}
