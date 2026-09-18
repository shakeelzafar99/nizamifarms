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
        'packet_barcode',
        'packet_kg',
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
        'packet_kg' => 'decimal:3',
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

    /**
     * ⭐⭐ ROUND 3 — only a SCAN product holds discrete packet rows as stock.
     *
     * Weight used to be in here, and that was the Sep-17 problem. A bale is weighed once on
     * a tray and booked under ONE label, so "one scanned label = one indivisible packet"
     * turned a 26.97 kg bale into the smallest thing that could leave the shelf: scanning a
     * 1.5 kg inner packet could only take the whole bale. Weighed stock is now a POOL in kg
     * (see isPooled) and packet rows for it are intake audit only — what was weighed in,
     * never what can be taken out.
     */
    public function usesPackets(): bool
    {
        return $this->mode === self::MODE_SCAN;
    }

    /**
     * Is this product's stock a POOL — a running quantity drawn down FIFO across purchases,
     * rather than a set of individually identified packets?
     *
     * Weight (kg) and pieces (a count) both are. They share one engine: consumeFromPool().
     */
    public function isPooled(): bool
    {
        return $this->mode === self::MODE_WEIGHT || $this->mode === self::MODE_PIECES;
    }

    /**
     * Does BOOKING IN record individual packet rows? Weight and scan both do — the rows are
     * what was physically weighed or scanned onto the shelf.
     *
     * ⚠ NOT the same question as usesPackets(). For a weight product those rows are intake
     * AUDIT — they say what came in — while the stock that can leave is the pool. Splitting
     * the two questions is the whole of round 3: booking in is unchanged, taking out is not.
     */
    public function booksPackets(): bool
    {
        return $this->mode !== self::MODE_PIECES;
    }

    /** Does an inner packet carry a fixed vendor barcode with a known nominal weight? */
    public function hasNominalPacket(): bool
    {
        return $this->mode === self::MODE_WEIGHT
            && !empty($this->packet_barcode)
            && (float) $this->packet_kg > 0;
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
