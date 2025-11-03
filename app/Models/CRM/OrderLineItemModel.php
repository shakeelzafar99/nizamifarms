<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLineItemModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_prod_order_line_item';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'order_id',
        'external_line_item_id',
        'product_id',
        'variant_id',
        'sku',
        'name',
        'vendor',
        'quantity',
        'unit_price',
        'line_subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
        'preparation_status',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2'
    ];

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariantModel::class, 'variant_id');
    }

    public function getDisplaySkuAttribute(): string
    {
        $sku = trim((string)($this->sku ?? ''));
        if ($sku !== '') {
            return $sku;
        }

        if ($this->relationLoaded('variant') && $this->variant) {
            $variantSku = trim((string)($this->variant->sku ?? ''));
            if ($variantSku !== '') {
                return $variantSku;
            }
        }

        if ($this->variant_id) {
            $variantSku = trim((string)(ProductVariantModel::where('id', $this->variant_id)->value('sku') ?? ''));
            if ($variantSku !== '') {
                return $variantSku;
            }
        }

        return '';
    }

    // Helper methods
    public function getCalculatedTotalAttribute(): float
    {
        return ($this->line_subtotal ?? 0) - ($this->discount_amount ?? 0) + ($this->tax_amount ?? 0);
    }

    // Automatically calculate line_total if not provided
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($lineItem) {
            if (is_null($lineItem->line_total)) {
                $lineItem->line_total = $lineItem->getCalculatedTotalAttribute();
            }
        });

        static::updating(function ($lineItem) {
            if (is_null($lineItem->line_total)) {
                $lineItem->line_total = $lineItem->getCalculatedTotalAttribute();
            }
        });
    }
}
