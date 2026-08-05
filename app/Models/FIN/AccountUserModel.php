<?php

namespace App\Models\FIN;

use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who may pay money OUT of which account — the account usage tag.
 *
 * One row = "this person may fund <purpose> from this account", optionally starred
 * as their pre-selected account. Introduced Aug-2026 (batch 10) to replace the
 * all-or-nothing `expense_all_payment_sources` mobile permission as the thing that
 * builds payment-source pickers: that permission could only say "Expense Fund only"
 * or "absolutely everything", so giving someone the fuel float also handed them the
 * Qurbani and Khaas accounts.
 *
 * Read exclusively through PaymentSourceService. Nothing else should query this
 * table to decide what a picker shows — that is how four disagreeing copies of the
 * rules appeared last time.
 */
class AccountUserModel extends BaseModel
{
    protected $table = 't_fin_account_users';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /** The three things money goes out for. Each is a separate grant. */
    public const PURPOSE_EXPENSE = 'expense';
    public const PURPOSE_VENDOR  = 'vendor';
    public const PURPOSE_ADVANCE = 'advance';

    public const PURPOSES = [
        self::PURPOSE_EXPENSE,
        self::PURPOSE_VENDOR,
        self::PURPOSE_ADVANCE,
    ];

    protected $fillable = [
        'account_id',
        'user_id',
        'is_default',
        'can_expense',
        'can_vendor',
        'can_advance',
        'preferred_bank_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_default'  => 'boolean',
        'can_expense' => 'boolean',
        'can_vendor'  => 'boolean',
        'can_advance' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'account_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }

    /** Column name for a purpose, or null if the purpose is not one we know. */
    public static function columnForPurpose(?string $purpose): ?string
    {
        return match ($purpose) {
            self::PURPOSE_EXPENSE => 'can_expense',
            self::PURPOSE_VENDOR  => 'can_vendor',
            self::PURPOSE_ADVANCE => 'can_advance',
            default               => null,
        };
    }

    /**
     * Star this (user, account) and clear the user's other star IN THE SAME
     * BUSINESS UNIT.
     *
     * The star is per user PER BUSINESS UNIT, not per user: one global star could
     * not express the owner's own case — Online Bank in Nizami Farms, his own Khaas
     * online account in Khaas. The business unit lives on t_fin_accounts, not on
     * this table, so a UNIQUE key cannot enforce it and this method must.
     */
    public static function setDefault(int $userId, int $accountId): void
    {
        $account = AccountModel::find($accountId);
        if (!$account) {
            return;
        }

        $siblingIds = AccountModel::where('business_unit_id', $account->business_unit_id)
            ->pluck('id');

        static::where('user_id', $userId)
            ->whereIn('account_id', $siblingIds)
            ->where('account_id', '!=', $accountId)
            ->update(['is_default' => 0]);

        static::where('user_id', $userId)
            ->where('account_id', $accountId)
            ->update(['is_default' => 1]);
    }
}
