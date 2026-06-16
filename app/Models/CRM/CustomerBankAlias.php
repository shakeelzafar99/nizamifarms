<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Model;

/**
 * A learned mapping of a customer's BANK-ACCOUNT NAME (as it appears on a
 * transfer) to their NF customer_id. Populated only when a human approves a
 * payment, so future bank emails can identify the customer even when no
 * WhatsApp screenshot was sent.
 *
 * @see database/migrations/create_payment_signal_and_alias_jun2026.sql
 */
class CustomerBankAlias extends Model
{
    protected $table = 't_crm_customer_bank_alias';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public const VIA_WHATSAPP = 'whatsapp_match';
    public const VIA_APPROVER = 'approver';
    public const VIA_MANUAL   = 'manual';

    protected $fillable = [
        'customer_id',
        'bank_account_name',
        'bank_account_masked',
        'sender_bank',
        'confirmed_via',
        'confirmed_by',
        'confirmed_at',
        'use_count',
        'last_used_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'last_used_at' => 'datetime',
        'use_count'    => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(CustomerModel::class, 'customer_id');
    }

    /**
     * Normalise a bank-account name for case/space-insensitive comparison.
     * "  HAFIZ  WASI ul HAQ " -> "hafiz wasi ul haq"
     */
    public static function normaliseName(?string $name): string
    {
        $name = (string) $name;
        $name = preg_replace('/\s+/', ' ', trim($name));
        return mb_strtolower($name);
    }
}
