<?php

namespace App\Models\CRM;

use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvernightItemModel extends BaseModel
{
    protected $table = 't_crm_overnight_item';
    protected $primaryKey = 'id';

    protected $fillable = [
        'product_id',
        'product_name',
        'plu',
        'barcode',
        'quantity',
        'unit',
        'section',
        'status',
        'source',
        'verified_at',
        'verified_by',
        'entered_at',
        'entered_by',
        'taken_out_at',
        'taken_out_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'entered_at' => 'datetime',
        'taken_out_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'entered_by');
    }

    public function takenOutBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'taken_out_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }
}
