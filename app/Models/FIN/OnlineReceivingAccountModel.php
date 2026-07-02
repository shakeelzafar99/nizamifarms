<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

class OnlineReceivingAccountModel extends Model
{
    protected $table = 't_fin_online_receiving_accounts';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'name',
        'short_code',
        'account_last4',
        'bank_name',
        'color_hex',
        'opening_balance',
        'opening_balance_date',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
    ];

    /**
     * Scope: Active only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Ordered
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
