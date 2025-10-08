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
        'description'
    ];

    // Config key constants
    const KEY_EXPENSE_FUNDING_ACCOUNT = 'expense_funding_account_id';
    const KEY_NF_CASH_ACCOUNT = 'nf_cash_account_id';
    const KEY_ONLINE_BANK_ACCOUNT = 'online_bank_account_id';
    const KEY_SALES_REVENUE_ACCOUNT = 'sales_revenue_account_id';
    const KEY_OPENING_EQUITY_ACCOUNT = 'opening_equity_account_id';

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
     * Clear all config cache
     */
    public static function clearCache()
    {
        $keys = [
            self::KEY_EXPENSE_FUNDING_ACCOUNT,
            self::KEY_NF_CASH_ACCOUNT,
            self::KEY_ONLINE_BANK_ACCOUNT,
            self::KEY_SALES_REVENUE_ACCOUNT,
            self::KEY_OPENING_EQUITY_ACCOUNT
        ];

        foreach ($keys as $key) {
            Cache::forget("fin_config_{$key}");
        }
    }
}

