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

    /**
     * Default business unit ID (Nizami Farms)
     */
    const DEFAULT_BUSINESS_UNIT_ID = 1;

    /**
     * Boot method to auto-set business_unit_id if not provided
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // ⭐ CRITICAL: Default to Nizami Farms (1) if business_unit_id not set
            if (empty($model->business_unit_id)) {
                $model->business_unit_id = self::DEFAULT_BUSINESS_UNIT_ID;
            }
        });
    }

    protected $fillable = [
        'account_code',
        'account_name',
        'account_type',
        'account_category',
        'user_id',
        'opening_balance',
        'current_balance',
        'petty_cash',
        'is_active',
        'is_private',
        'business_unit_id', // ⭐ FK to t_fin_business_units
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'petty_cash' => 'decimal:2',
        'is_active' => 'boolean',
        'is_private' => 'boolean'
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
     * Business Unit relationship
     */
    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnitModel::class, 'business_unit_id', 'id');
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

    public function scopeVisibleTo($query, $user)
    {
        $isTaimur = $user && $user->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists();
        if (!$isTaimur) {
            $query->where('is_private', 0);
        }
        return $query;
    }

    /**
     * Scope: Filter by business unit
     */
    public function scopeForBusinessUnit($query, $businessUnitId)
    {
        if ($businessUnitId) {
            return $query->where('business_unit_id', $businessUnitId);
        }
        return $query;
    }

    // [Ledger L5 cleanup] Removed dead updateBalance() helper (0 callers). It used an income-as-
    // credit-normal convention that CONFLICTS with how revenue is actually stored here (negative),
    // so it was a latent landmine. All balance mutation now goes through BalancePostingService.

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
            'NF_CASH', 'ONLINE', 'REV_SALES', 'EXP_FUND', 'EQUITY_OPENING',
            'QURBANI_CASH', 'QURBANI_ONLINE',
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
     * @param string $vendorName
     * @param int $businessUnitId - Defaults to 1 (Nizami Farms)
     */
    public static function createVendorAccount($vendorName, $businessUnitId = 1)
    {
        $code = 'VEN_' . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $vendorName));
        $code = substr($code, 0, 50); // Limit length
        
        return static::firstOrCreate(
            ['account_code' => $code],
            [
                'account_name' => 'Vendor - ' . $vendorName,
                'account_type' => self::TYPE_LIABILITY,
                'account_category' => self::CATEGORY_VENDOR_PAYABLE,
                'business_unit_id' => $businessUnitId, // ⭐ Business unit
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

    /**
     * ⭐ Get company payment accounts accessible to the current user based on their role's BU access
     * 
     * Company accounts are those with account_category = 'cash' or 'bank'
     * (NOT employee_cash or vendor_payable)
     * and their business_unit_id determines which business unit they belong to.
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAccessibleCompanyAccounts()
    {
        $user = auth()->user();
        
        // Company accounts = cash + bank categories (excludes employee_cash, vendor_payable, expense, etc.)
        $companyCategories = [self::CATEGORY_CASH, self::CATEGORY_BANK];
        
        if (!$user) {
            // Fallback: return all active company accounts
            return static::where('is_active', 1)
                ->whereIn('account_category', $companyCategories)
                ->orderBy('account_name')
                ->get();
        }

        // Get user's primary role for BU access check
        // ⭐ Resilient: try with BU columns first, fall back if columns don't exist yet
        try {
            $userRole = \Illuminate\Support\Facades\DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $user->id)
                ->select('r.id', 'r.business_unit_access', 'r.default_business_unit_id')
                ->first();
        } catch (\Illuminate\Database\QueryException $e) {
            // Columns don't exist yet (migration not run) - return all company accounts
            \Log::warning('business_unit_access columns missing from t_sys_role - returning all company accounts. Run business_unit_role_access.sql migration.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return static::where('is_active', 1)
                ->whereIn('account_category', $companyCategories)
                ->orderBy('account_name')
                ->get();
        }

        if (!$userRole) {
            // No role assigned - return only default NF accounts
            return static::where('is_active', 1)
                ->whereIn('account_category', $companyCategories)
                ->where('business_unit_id', 1)
                ->orderBy('account_name')
                ->get();
        }

        $query = static::where('is_active', 1)
            ->whereIn('account_category', $companyCategories);

        // Filter by business unit access
        $buAccess = $userRole->business_unit_access ?? 'all'; // Default to 'all' if null
        if ($buAccess === 'all') {
            // Full access - return all company accounts
            // No additional filtering needed
        } elseif ($buAccess === 'single') {
            // Single BU access - only accounts for default BU
            $query->where('business_unit_id', $userRole->default_business_unit_id ?? 1);
        } elseif ($buAccess === 'multiple') {
            // Multiple BU access - get assigned BUs
            try {
                $assignedBuIds = \Illuminate\Support\Facades\DB::table('t_sys_role_business_unit')
                    ->where('role_id', $userRole->id)
                    ->pluck('business_unit_id')
                    ->toArray();
                
                if (!empty($assignedBuIds)) {
                    $query->whereIn('business_unit_id', $assignedBuIds);
                } else {
                    // Fallback to default BU if no assignments
                    $query->where('business_unit_id', $userRole->default_business_unit_id ?? 1);
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // t_sys_role_business_unit table doesn't exist yet - no filtering
                \Log::warning('t_sys_role_business_unit table missing - returning all company accounts.', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Default: only Nizami Farms (BU 1) accounts
            $query->where('business_unit_id', 1);
        }

        return $query->orderBy('account_name')->get();
    }

    /**
     * ⭐ Get user's default business unit ID based on their role
     * 
     * @return int Default business unit ID (defaults to 1 = Nizami Farms)
     */
    public static function getUserDefaultBusinessUnitId()
    {
        $user = auth()->user();
        if (!$user) {
            return 1; // Default to Nizami Farms
        }

        try {
            $userRole = \Illuminate\Support\Facades\DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $user->id)
                ->select('r.default_business_unit_id')
                ->first();

            return $userRole->default_business_unit_id ?? 1;
        } catch (\Illuminate\Database\QueryException $e) {
            // Column doesn't exist yet (migration not run) - default to Nizami Farms
            return 1;
        }
    }

    /**
     * ⭐ Get user's accessible business units based on their role
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getUserAccessibleBusinessUnits()
    {
        $user = auth()->user();
        if (!$user) {
            return BusinessUnitModel::where('is_active', 1)->ordered()->get();
        }

        try {
            $userRole = \Illuminate\Support\Facades\DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $user->id)
                ->select('r.id', 'r.business_unit_access', 'r.default_business_unit_id')
                ->first();
        } catch (\Illuminate\Database\QueryException $e) {
            // Columns don't exist yet (migration not run) - return all active BUs
            \Log::warning('business_unit_access columns missing from t_sys_role - returning all BUs. Run business_unit_role_access.sql migration.', [
                'user_id' => $user->id,
            ]);
            return BusinessUnitModel::where('is_active', 1)->ordered()->get();
        }

        if (!$userRole) {
            return BusinessUnitModel::where('id', 1)->get(); // Only default
        }

        $buAccess = $userRole->business_unit_access ?? 'all'; // Default to 'all' if null
        if ($buAccess === 'all') {
            return BusinessUnitModel::where('is_active', 1)->ordered()->get();
        } elseif ($buAccess === 'single') {
            return BusinessUnitModel::where('id', $userRole->default_business_unit_id ?? 1)->get();
        } elseif ($buAccess === 'multiple') {
            try {
                $assignedBuIds = \Illuminate\Support\Facades\DB::table('t_sys_role_business_unit')
                    ->where('role_id', $userRole->id)
                    ->pluck('business_unit_id')
                    ->toArray();
                
                if (!empty($assignedBuIds)) {
                    return BusinessUnitModel::whereIn('id', $assignedBuIds)
                        ->where('is_active', 1)
                        ->ordered()
                        ->get();
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // t_sys_role_business_unit table doesn't exist - return all
                return BusinessUnitModel::where('is_active', 1)->ordered()->get();
            }
        }
        
        // Fallback
        return BusinessUnitModel::where('id', 1)->get();
    }
}

