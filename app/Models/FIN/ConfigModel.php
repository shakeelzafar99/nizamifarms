<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\Cache;

class ConfigModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_config';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'config_key',
        'config_value',
        'description',
        'business_unit_id',
    ];

    protected $casts = [
        'business_unit_id' => 'integer',
    ];

    // Config key constants
    const KEY_EXPENSE_FUNDING_ACCOUNT = 'expense_funding_account_id';
    const KEY_NF_CASH_ACCOUNT = 'nf_cash_account_id';
    const KEY_ONLINE_BANK_ACCOUNT = 'online_bank_account_id';
    const KEY_SALES_REVENUE_ACCOUNT = 'sales_revenue_account_id';
    const KEY_OPENING_EQUITY_ACCOUNT = 'opening_equity_account_id';
    const KEY_BU_DEFAULT_EXPENSE_ACCOUNT = 'BU_DEFAULT_EXPENSE_ACCOUNT';
    const KEY_QURBANI_CASH_ACCOUNT = 'qurbani_cash_account_id';
    const KEY_QURBANI_ONLINE_ACCOUNT = 'qurbani_online_account_id';

    /**
     * Get config value by key
     */
    public static function get($key, $default = null)
    {
        $cacheKey = "fin_config_{$key}";
        
        return Cache::remember($cacheKey, 3600, function() use ($key, $default) {
            $config = static::where('config_key', $key)->first();
            return $config ? $config->config_value : $default;
        });
    }

    /**
     * Set config value
     */
    public static function set($key, $value, $description = null)
    {
        $config = static::updateOrCreate(
            ['config_key' => $key],
            [
                'config_value' => $value,
                'description' => $description
            ]
        );

        // Clear cache
        Cache::forget("fin_config_{$key}");
        
        return $config;
    }

    /**
     * Get expense funding account
     */
    public static function getExpenseFundingAccount()
    {
        $accountId = static::get(self::KEY_EXPENSE_FUNDING_ACCOUNT);
        return $accountId ? AccountModel::find($accountId) : null;
    }

    /**
     * Get NF Cash account
     */
    public static function getNFCashAccount()
    {
        $accountId = static::get(self::KEY_NF_CASH_ACCOUNT);
        return $accountId ? AccountModel::find($accountId) : AccountModel::getByCode('NF_CASH');
    }

    /**
     * Get Online Bank account
     */
    public static function getOnlineBankAccount()
    {
        $accountId = static::get(self::KEY_ONLINE_BANK_ACCOUNT);
        return $accountId ? AccountModel::find($accountId) : AccountModel::getByCode('ONLINE');
    }

    /**
     * Get Sales Revenue account
     */
    public static function getSalesRevenueAccount()
    {
        $accountId = static::get(self::KEY_SALES_REVENUE_ACCOUNT);
        return $accountId ? AccountModel::find($accountId) : AccountModel::getByCode('REV_SALES');
    }

    /**
     * Get Opening Equity account
     */
    public static function getOpeningEquityAccount()
    {
        $accountId = static::get(self::KEY_OPENING_EQUITY_ACCOUNT);
        return $accountId ? AccountModel::find($accountId) : AccountModel::getByCode('EQUITY_OPENING');
    }

    /**
     * Get Qurbani Cash account (falls back to NF Cash if not configured)
     */
    public static function getQurbaniCashAccount()
    {
        $accountId = static::get(self::KEY_QURBANI_CASH_ACCOUNT);
        if ($accountId) {
            $account = AccountModel::find($accountId);
            if ($account) return $account;
        }
        return AccountModel::getByCode('QURBANI_CASH') ?? static::getNFCashAccount();
    }

    /**
     * Get Qurbani Online account (falls back to Online Bank if not configured)
     */
    public static function getQurbaniOnlineAccount()
    {
        $accountId = static::get(self::KEY_QURBANI_ONLINE_ACCOUNT);
        if ($accountId) {
            $account = AccountModel::find($accountId);
            if ($account) return $account;
        }
        return AccountModel::getByCode('QURBANI_ONLINE') ?? static::getOnlineBankAccount();
    }

    /**
     * Get the default expense account for a business unit.
     * Looks up BU_DEFAULT_EXPENSE_ACCOUNT in t_fin_config for the given BU.
     * Falls back to the first active non-employee/non-vendor account for that BU.
     * 
     * @param int $businessUnitId
     * @return \App\Models\FIN\AccountModel|null
     */
    public static function getBuDefaultExpenseAccount(int $businessUnitId): ?AccountModel
    {
        $cacheKey = "fin_config_bu_default_expense_{$businessUnitId}";
        
        $accountId = Cache::remember($cacheKey, 3600, function() use ($businessUnitId) {
            // Look for configured default
            $config = static::where('config_key', self::KEY_BU_DEFAULT_EXPENSE_ACCOUNT)
                ->where('business_unit_id', $businessUnitId)
                ->first();
            
            return $config ? $config->config_value : null;
        });
        
        if ($accountId) {
            $account = AccountModel::find($accountId);
            if ($account && $account->is_active) {
                return $account;
            }
        }
        
        // Fallback: first active non-employee/non-vendor account for this BU
        return AccountModel::where('is_active', 1)
            ->where('business_unit_id', $businessUnitId)
            ->whereNotIn('account_category', [
                AccountModel::CATEGORY_EMPLOYEE_CASH,
                AccountModel::CATEGORY_VENDOR_PAYABLE,
            ])
            ->orderBy('account_name')
            ->first();
    }

    /**
     * Set the default expense account for a business unit.
     * 
     * @param int $businessUnitId
     * @param int $accountId
     * @return void
     */
    public static function setBuDefaultExpenseAccount(int $businessUnitId, int $accountId): void
    {
        static::updateOrCreate(
            [
                'config_key' => self::KEY_BU_DEFAULT_EXPENSE_ACCOUNT,
                'business_unit_id' => $businessUnitId,
            ],
            [
                'config_value' => (string) $accountId,
                'description' => "Default expense/fund account for business unit #{$businessUnitId}",
            ]
        );
        
        // Clear cache
        Cache::forget("fin_config_bu_default_expense_{$businessUnitId}");
    }

    /**
     * Clear all config cache
     */
    public static function clearCache()
    {
        $keys = [
            self::KEY_EXPENSE_FUNDING_ACCOUNT,
            self::KEY_NF_CASH_ACCOUNT,
            self::KEY_ONLINE_BANK_ACCOUNT,
            self::KEY_SALES_REVENUE_ACCOUNT,
            self::KEY_OPENING_EQUITY_ACCOUNT,
            self::KEY_QURBANI_CASH_ACCOUNT,
            self::KEY_QURBANI_ONLINE_ACCOUNT,
        ];

        foreach ($keys as $key) {
            Cache::forget("fin_config_{$key}");
        }
    }
}

