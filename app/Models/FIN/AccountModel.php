<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_accounts';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'account_code',
        'account_name',
        'account_type',
        'account_category',
        'user_id',
        'opening_balance',
        'current_balance',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    // Account type constants
    const TYPE_ASSET = 'asset';
    const TYPE_LIABILITY = 'liability';
    const TYPE_INCOME = 'income';
    const TYPE_EXPENSE = 'expense';
    const TYPE_EQUITY = 'equity';

    // Account category constants
    const CATEGORY_CASH = 'cash';
    const CATEGORY_BANK = 'bank';
    const CATEGORY_EMPLOYEE_CASH = 'employee_cash';
    const CATEGORY_VENDOR_PAYABLE = 'vendor_payable';
    const CATEGORY_EXPENSE = 'expense';
    const CATEGORY_REVENUE = 'revenue';

    /**
     * Relationships
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
    }

    public function ledgerEntriesFrom(): HasMany
    {
        return $this->hasMany(LedgerModel::class, 'from_account_id', 'id');
    }

    public function ledgerEntriesTo(): HasMany
    {
        return $this->hasMany(LedgerModel::class, 'to_account_id', 'id');
    }

    public function vendor()
    {
        return $this->hasOne(VendorModel::class, 'account_id', 'id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('account_type', $type);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('account_category', $category);
    }

    public function scopeEmployeeCash($query)
    {
        return $query->where('account_category', self::CATEGORY_EMPLOYEE_CASH)
                     ->where('is_active', 1);
    }

    public function scopeVendorAccounts($query)
    {
        return $query->where('account_category', self::CATEGORY_VENDOR_PAYABLE)
                     ->where('is_active', 1);
    }

    /**
     * Helper Methods
     */
    public function updateBalance($amount, $isCredit = true)
    {
        if ($isCredit) {
            // Credit increases: Liabilities, Income, Equity
            // Credit decreases: Assets, Expenses
            if (in_array($this->account_type, [self::TYPE_LIABILITY, self::TYPE_INCOME, self::TYPE_EQUITY])) {
                $this->current_balance += $amount;
            } else {
                $this->current_balance -= $amount;
            }
        } else {
            // Debit increases: Assets, Expenses
            // Debit decreases: Liabilities, Income, Equity
            if (in_array($this->account_type, [self::TYPE_ASSET, self::TYPE_EXPENSE])) {
                $this->current_balance += $amount;
            } else {
                $this->current_balance -= $amount;
            }
        }
        
        $this->save();
    }

    public function getBalanceAttribute()
    {
        return $this->current_balance;
    }

    public function isEmployeeCashAccount(): bool
    {
        return $this->account_category === self::CATEGORY_EMPLOYEE_CASH;
    }

    public function isVendorAccount(): bool
    {
        return $this->account_category === self::CATEGORY_VENDOR_PAYABLE;
    }

    public function isSystemAccount(): bool
    {
        return in_array($this->account_code, [
            'NF_CASH', 'ONLINE', 'REV_SALES', 'EXP_FUND', 'EQUITY_OPENING'
        ]);
    }

    /**
     * Get or create account by code
     */
    public static function getByCode($code)
    {
        return static::where('account_code', $code)->first();
    }

    /**
     * Create employee cash account
     */
    public static function createEmployeeCashAccount($userId, $userName)
    {
        $code = 'CASH_EMP_' . strtoupper(str_replace([' ', '-', '.'], '_', $userName));
        
        return static::firstOrCreate(
            ['account_code' => $code],
            [
                'account_name' => 'Cash - ' . $userName,
                'account_type' => self::TYPE_ASSET,
                'account_category' => self::CATEGORY_EMPLOYEE_CASH,
                'user_id' => $userId,
                'is_active' => 1,
                'created_by' => auth()->id() ?? 1
            ]
        );
    }

    /**
     * Create vendor payable account
     */
    public static function createVendorAccount($vendorName)
    {
        $code = 'VEN_' . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $vendorName));
        $code = substr($code, 0, 50); // Limit length
        
        return static::firstOrCreate(
            ['account_code' => $code],
            [
                'account_name' => 'Vendor - ' . $vendorName,
                'account_type' => self::TYPE_LIABILITY,
                'account_category' => self::CATEGORY_VENDOR_PAYABLE,
                'is_active' => 1,
                'created_by' => auth()->id() ?? 1
            ]
        );
    }

    /**
     * Create expense account
     */
    public static function createExpenseAccount($expenseCategory)
    {
        $code = 'EXP_' . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $expenseCategory));
        $code = substr($code, 0, 50); // Limit length
        
        return static::firstOrCreate(
            ['account_code' => $code],
            [
                'account_name' => 'Expense - ' . $expenseCategory,
                'account_type' => self::TYPE_EXPENSE,
                'account_category' => self::CATEGORY_EXPENSE,
                'is_active' => 1,
                'created_by' => auth()->id() ?? 1
            ]
        );
    }

    /**
     * Get account balance with proper sign
     */
    public function getSignedBalance()
    {
        // Assets and Expenses are positive when debit
        // Liabilities, Income, Equity are positive when credit
        return $this->current_balance;
    }

    /**
     * Format balance for display
     */
    public function getFormattedBalanceAttribute()
    {
        return number_format($this->current_balance, 2);
    }

    /**
     * Transaction types to EXCLUDE from employee cash balance calculation.
     * These are personal/HR payments, not company cash held by employee.
     */
    const EXCLUDED_EMPLOYEE_CASH_TYPES = [
        'salary_payment',
        'salary_advance',
        'reimbursement_payment',
        'reimbursement_accrual',
    ];

    /**
     * Get calculated balance for employee_cash accounts.
     * For employee_cash: Excludes salary/personal transactions - only tracks company cash.
     * For other accounts: Returns current_balance (includes all transactions).
     * 
     * @return float The calculated balance
     */
    public function getCalculatedBalance(): float
    {
        // For non-employee accounts, use stored balance (they track everything)
        if ($this->account_category !== self::CATEGORY_EMPLOYEE_CASH) {
            return (float) $this->current_balance;
        }

        // For employee_cash accounts: Calculate from approved transactions
        // excluding salary/personal types
        $moneyIn = LedgerModel::where('to_account_id', $this->id)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereNotIn('transaction_type', self::EXCLUDED_EMPLOYEE_CASH_TYPES)
            ->sum('amount');

        $moneyOut = LedgerModel::where('from_account_id', $this->id)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->whereNotIn('transaction_type', self::EXCLUDED_EMPLOYEE_CASH_TYPES)
            ->sum('amount');

        return (float) $this->opening_balance + (float) $moneyIn - (float) $moneyOut;
    }

    /**
     * Get ledger transactions that affect employee cash balance.
     * Excludes salary/personal transactions for employee_cash accounts.
     * 
     * @param int $days Number of days to look back (default 30)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getBalanceAffectingTransactions(int $days = 30)
    {
        $query = LedgerModel::where(function($q) {
            $q->where('from_account_id', $this->id)
              ->orWhere('to_account_id', $this->id);
        })
        ->where('transaction_date', '>=', now()->subDays($days))
        ->where('approval_status', LedgerModel::STATUS_APPROVED);

        // For employee_cash accounts, exclude personal/salary transactions
        if ($this->account_category === self::CATEGORY_EMPLOYEE_CASH) {
            $query->whereNotIn('transaction_type', self::EXCLUDED_EMPLOYEE_CASH_TYPES);
        }

        return $query->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get the effective balance for display.
     * Uses calculated balance for employee_cash, current_balance for others.
     * 
     * @return float
     */
    public function getEffectiveBalance(): float
    {
        if ($this->account_category === self::CATEGORY_EMPLOYEE_CASH) {
            return $this->getCalculatedBalance();
        }
        return (float) $this->current_balance;
    }

    /**
     * Static method to get sum of calculated balances for all employee cash accounts.
     * Used for KPIs/dashboards.
     * 
     * @return float
     */
    public static function getTotalEmployeeCashCalculatedBalance(): float
    {
        $accounts = static::where('account_category', self::CATEGORY_EMPLOYEE_CASH)
            ->where('is_active', 1)
            ->get();

        return $accounts->sum(function($account) {
            return $account->getCalculatedBalance();
        });
    }
}

