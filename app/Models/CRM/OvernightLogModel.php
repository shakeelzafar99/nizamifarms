<?php

namespace App\Models\CRM;

use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvernightLogModel extends BaseModel
{
    protected $table = 't_crm_overnight_log';
    protected $primaryKey = 'id';
    public $timestamps = false; // Append-only ledger: only has created_at

    protected $fillable = [
        'item_id',
        'action',
        'from_section',
        'to_section',
        'section',
        'item_count',
        'product_id',
        'product_name',
        'quantity',
        'unit',
        'source',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(OvernightItemModel::class, 'item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
