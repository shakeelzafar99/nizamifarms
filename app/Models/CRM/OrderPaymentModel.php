<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\FIN\LedgerModel;

class OrderPaymentModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_crm_order_payments';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'order_id',
        'amount',
        'payment_method',
        // Which of our banks received the money (only set for online payments).
        // FK to t_fin_online_receiving_accounts.id.
        'receiving_account_id',
        'payment_date',
        'reference',
        'notes',
        'ledger_transaction_id',
        'status',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_transaction_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SysAdmin\UserModel::class, 'created_by');
    }

    // Which of OUR receiving banks the money landed in. Nullable by design:
    // cash payments have no bank, and older rows from before Apr 2026 won't
    // have it populated.
    public function receivingAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FIN\OnlineReceivingAccountModel::class, 'receiving_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
