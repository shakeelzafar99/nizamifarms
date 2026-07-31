<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

/**
 * A manual per-bank balance adjustment (Bank Balances page, Taimur-only).
 *
 * Attribution-layer only: moves a bank CARD's computed balance up or down and
 * shows in that bank's statement — it is NOT a ledger transaction and never
 * touches t_fin_ledger, account balances, or KPIs. Main use: the one-time
 * true-up of the half-tagged pre-go-live history; later e.g. bank fees.
 *
 * @see database/migrations/bank_balance_adjustments_jul2026.sql
 */
class BankBalanceAdjustmentModel extends Model
{
    protected $table = 't_fin_bank_balance_adjustment';
    protected $primaryKey = 'id';
    public $timestamps = false; // only created_at, DB-defaulted

    protected $fillable = [
        'receiving_account_id',
        'amount',
        'adjustment_date',
        'note',
        'transfer_group',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'adjustment_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function bank()
    {
        return $this->belongsTo(OnlineReceivingAccountModel::class, 'receiving_account_id', 'id');
    }
}
