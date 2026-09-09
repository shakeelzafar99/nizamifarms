<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A storage supply (packaging etc) — deliberately NOT a sellable product.
 *
 * It lives in its own table rather than `t_crm_prod_product` because it must never
 * appear in the invoice product search, has no variants/SKUs, and carries something
 * a sellable product does not: the expense category its consumption is charged to.
 *
 * `mode` decides how one packet is identified:
 *   weight — Czerlop scale label; one scan = one packet, kg comes from the label
 *   scan   — a fixed product barcode; one scan = one packet, qty 1
 *   pieces — nothing to scan; the count is typed in and out
 */
class SupplyProductModel extends Model
{
    protected $table = 't_fin_supply_product';
    protected $primaryKey = 'id';
    public $timestamps = true;

    const MODE_WEIGHT = 'weight';
    const MODE_SCAN   = 'scan';
    const MODE_PIECES = 'pieces';

    const MODES = [self::MODE_WEIGHT, self::MODE_SCAN, self::MODE_PIECES];

    protected $fillable = [
        'name',
        'mode',
        'plu',
        'barcode',
        'pieces_per_packet',
        'expense_config_id',
        'expense_category_name',
        'business_unit_id',
        'low_stock_qty',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'plu' => 'integer',
        'pieces_per_packet' => 'integer',
        'expense_config_id' => 'integer',
        'business_unit_id' => 'integer',
        'low_stock_qty' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function batches(): HasMany
    {
        return $this->hasMany(SupplyBatchModel::class, 'product_id');
    }

    public function packets(): HasMany
    {
        return $this->hasMany(SupplyPacketModel::class, 'product_id');
    }

    public function expenseConfig(): BelongsTo
    {
        return $this->belongsTo(ConfigModel::class, 'expense_config_id');
    }

    /** Weight and scan products hold discrete packet rows; pieces products do not. */
    public function usesPackets(): bool
    {
        return $this->mode !== self::MODE_PIECES;
    }

    /** The unit label a take-out of this product is measured in. */
    public function takeoutUnit(): string
    {
        return match ($this->mode) {
            self::MODE_WEIGHT => 'kg',
            self::MODE_SCAN   => 'packet',
            default           => 'pcs',
        };
    }
}
