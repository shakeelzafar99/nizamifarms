<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDiscountModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_order_discounts';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'order_id',
        'discount_title',
        'discount_amount',
        'discount_type',
        'discount_percentage',
        'coupon_code',
        'display_order',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'display_order' => 'integer'
    ];

    /**
     * Get the order that owns this discount
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id');
    }

    /**
     * Get formatted discount amount with currency
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->discount_amount, 2);
    }

    /**
     * Scope to get discounts in display order
     */
    public function scopeInDisplayOrder($query)
    {
        return $query->orderBy('display_order')->orderBy('id');
    }
}

