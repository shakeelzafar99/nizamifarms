<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSettlementModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_invoice_settlements';
    protected $primaryKey = 'id';
    public $timestamps = false; // Only created_at, no updated_at

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'settlement_deposit_id',
        'invoice_ledger_id',
        'settled_amount'
    ];

    protected $casts = [
        'settled_amount' => 'decimal:2'
    ];

    /**
     * Relationships
     */
    public function settlementDeposit(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'settlement_deposit_id', 'id');
    }

    public function invoiceLedger(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'invoice_ledger_id', 'id');
    }
}

