<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyOrderLineItemModel extends BaseModel
{
    use HasFactory, Notifiable;

    protected $table = 't_crm_shopify_order_line_item';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'sku',
        'name',
        'quantity',
        'unit_price',
        'line_subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
        'preparation_status',
        'created_by',
        'updated_by',
    ];

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
}


