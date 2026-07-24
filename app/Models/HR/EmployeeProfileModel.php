<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;

class EmployeeProfileModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_hr_employee_profile';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'base_salary',
        'overtime_rate',
        'late_deduction_rate',
        'salary_currency',
        'salary_effective_date',
        'previous_salary',
        'last_salary_change_date',
        'pay_schedule',
        'rate_type',
        'business_unit_id',
        'designation',
        'department',
        'employee_code',
        'bank_name',
        'bank_account_number',
        'bank_account_title',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'overtime_rate' => 'decimal:2',
        'late_deduction_rate' => 'decimal:2',
        'previous_salary' => 'decimal:2',
        'salary_effective_date' => 'date',
        'last_salary_change_date' => 'date',
        'is_active' => 'boolean'
    ];

    // Relationships
    
    /**
     * Employee user record
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }

    /**
     * Salary slips for this employee
     */
    public function salarySlips(): HasMany
    {
        return $this->hasMany(SalarySlipModel::class, 'user_id', 'user_id')
            ->orderBy('salary_month', 'desc');
    }

    /**
     * Loans for this employee
     */
    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoanModel::class, 'user_id', 'user_id')
            ->orderBy('loan_date', 'desc');
    }

    /**
     * Active loans only
     */
    public function activeLoans(): HasMany
    {
        return $this->loans()->where('loan_status', 'active');
    }

    /**
     * Creator of this profile
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    /**
     * Last updater of this profile
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
    }

    // Scopes

    /**
     * Active profiles only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Profiles with salary configured
     */
    public function scopeWithSalary($query)
    {
        return $query->where('base_salary', '>', 0);
    }

    /**
     * Search by employee name or code
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('employee_code', 'like', "%{$search}%")
              ->orWhereHas('user', function($userQuery) use ($search) {
                  $userQuery->where('fullname', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
              });
        });
    }

    // Helper Methods

    /**
     * Get formatted salary
     */
    public function getFormattedSalaryAttribute(): string
    {
        return number_format($this->base_salary, 2) . ' ' . $this->salary_currency;
    }

    /**
     * Check if salary is configured
     */
    public function hasSalaryConfigured(): bool
    {
        return $this->base_salary > 0;
    }

    /**
     * Get total active loan balance
     */
    public function getTotalActiveLoanBalance(): float
    {
        return $this->activeLoans()->sum('outstanding_balance');
    }

    /**
     * Get total monthly loan installments
     */
    public function getTotalMonthlyLoanInstallment(): float
    {
        return $this->activeLoans()->sum('monthly_installment');
    }

    /**
     * Update salary (with history tracking)
     */
    public function updateSalary(float $newSalary, ?int $updatedBy = null): bool
    {
        $this->previous_salary = $this->base_salary;
        $this->last_salary_change_date = now();
        $this->base_salary = $newSalary;
        
        if ($updatedBy) {
            $this->updated_by = $updatedBy;
        }
        
        return $this->save();
    }

    /**
     * Check if profile belongs to an admin
     * Admins shouldn't have salary profiles
     */
    public function isAdminUser(): bool
    {
        if (!$this->user) {
            return false;
        }
        
        // Check if user has admin role type
        return $this->user->roles()
            ->where('type', 'admin')
            ->exists();
    }

    /**
     * Get employee full info for display
     */
    public function getEmployeeInfo(): array
    {
        return [
            'user_id' => $this->user_id,
            'fullname' => $this->user->fullname ?? 'Unknown',
            'email' => $this->user->email ?? '',
            'employee_code' => $this->employee_code,
            'designation' => $this->designation,
            'department' => $this->department,
            'base_salary' => $this->base_salary,
            'formatted_salary' => $this->formatted_salary,
            'overtime_rate' => $this->overtime_rate,
            'late_deduction_rate' => $this->late_deduction_rate,
            'salary_effective_date' => $this->salary_effective_date,
            'active_loans_count' => $this->activeLoans()->count(),
            'total_loan_balance' => $this->getTotalActiveLoanBalance(),
            'monthly_loan_installment' => $this->getTotalMonthlyLoanInstallment(),
            'is_active' => $this->is_active
        ];
    }

    /**
     * Create or get employee profile for a user
     */
    public static function getOrCreateForUser(int $userId, ?int $createdBy = null): ?self
    {
        // Check if user is admin - don't create profile for admins
        $user = UserModel::find($userId);
        if (!$user) {
            return null;
        }

        // Check if admin
        $isAdmin = $user->roles()->where('type', 'admin')->exists();
        if ($isAdmin) {
            return null; // Don't create salary profile for admins
        }

        // Get or create
        $profile = self::where('user_id', $userId)->first();
        
        if (!$profile) {
            $profile = self::create([
                'user_id' => $userId,
                'base_salary' => 0,
                'overtime_rate' => 0,
                'late_deduction_rate' => 0,
                'salary_currency' => 'PKR',
                'is_active' => true,
                'created_by' => $createdBy
            ]);
        }
        
        return $profile;
    }
}

