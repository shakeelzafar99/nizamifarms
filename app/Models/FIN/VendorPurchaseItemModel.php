<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

class VendorPurchaseItemModel extends Model
{
    protected $table = 't_fin_vendor_purchase_items';

    protected $fillable = [
        'ledger_id',
        'vendor_product_id',
        'product_name',
        'quantity',
        'unit',
        'rate_per_unit',
        'line_total',
        // ❄ Sep-2026: what this line was, in ingredient terms. STAMPED at save time
        // from the catalogue, never read back through the catalogue — so re-tagging a
        // product tomorrow cannot rewrite what a line meant on the day it was bought.
        // Both nullable: an untagged line is an ordinary line and always has been.
        'ingredient_id',
        'qty_base',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'rate_per_unit' => 'decimal:2',
        'line_total' => 'decimal:2',
        'ingredient_id' => 'integer',
        'qty_base' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Resolve the ingredient columns for one submitted line.
     *
     * The catalogue product carries "one unit of me is this many base units of that
     * ingredient" (a 1 L canola pack = 1000 ml). Multiply by the quantity bought and
     * the purchase becomes a quantity the recipe side can subtract from.
     *
     * A line the caller marks `not_ingredient` — a carrier bag, an FBR POS charge,
     * slaughtering fees — resolves to nulls on purpose: it stays in the money and out
     * of the ingredient stock and out of the rate.
     *
     * @return array{ingredient_id: int|null, qty_base: float|null}
     */
    public static function ingredientColumnsFor(?int $vendorProductId, float $quantity, array $overrides = []): array
    {
        $none = ['ingredient_id' => null, 'qty_base' => null];

        // ⚠⚠ THE ONLY THING THE CLIENT MAY SAY IS "NOT AN INGREDIENT".
        //    An earlier version also honoured a client-supplied ingredient_id and
        //    pack_qty_base. Nothing validated them, the column has no foreign key, and
        //    the purchase validator does not whitelist those keys — so anyone able to
        //    record a purchase could post pack_qty_base=999999 and collapse an
        //    ingredient's average rate, and with it the month's cost for every product
        //    using it. The ingredient now comes from the CATALOGUE, always. Tag the
        //    product if a line should count; that door checks the ingredient exists.
        if (!empty($overrides['not_ingredient'])) {
            return $none;
        }

        if (!$vendorProductId || !self::supportsIngredients()) {
            return $none;
        }

        try {
            $product = VendorProductModel::find($vendorProductId);
        } catch (\Throwable $e) {
            return $none;
        }

        if (!$product || !$product->ingredient_id) {
            return $none;
        }

        $packQty = (float) $product->pack_qty_base;
        if ($packQty <= 0) {
            return $none;
        }

        return [
            'ingredient_id' => (int) $product->ingredient_id,
            'qty_base'      => round($quantity * $packQty, 3),
        ];
    }

    /**
     * Has the Sep-2026 migration run yet?
     *
     * ⚠⚠ Deploy here is manual and the SQL is applied by hand, so the PHP CAN arrive
     *    first. Without this check the two new columns would be written unconditionally
     *    and EVERY weighted purchase — Frozen or not — would die on "Unknown column",
     *    taking purchase recording down app-wide until somebody ran the SQL. Costing is
     *    not worth that. Cached per request; the columns never appear mid-request.
     */
    public static function supportsIngredients(): bool
    {
        static $ok = null;

        if ($ok === null) {
            try {
                $ok = \Illuminate\Support\Facades\Schema::hasColumn('t_fin_vendor_purchase_items', 'ingredient_id');
            } catch (\Throwable $e) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Relationship: Belongs to Ledger
     */
    public function ledger()
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_id');
    }

    /**
     * Relationship: Belongs to Vendor Product (nullable)
     */
    public function vendorProduct()
    {
        return $this->belongsTo(VendorProductModel::class, 'vendor_product_id');
    }

    /**
     * Scope: For a specific ledger transaction
     */
    public function scopeForLedger($query, $ledgerId)
    {
        return $query->where('ledger_id', $ledgerId);
    }
}

