<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;

class LoanPaymentModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_hr_loan_payments';
    protected $primaryKey = 'id';
    public $timestamps = false; // Only has created_at

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'loan_id',
        'salary_slip_id',
        'payment_date',
        'payment_amount',
        'balance_before',
        'balance_after',
        'payment_type',
        'payment_notes',
        'created_by'
    ];

    protected $casts = [
        'payment_date' => 'date',
        'payment_amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2'
    ];

    // Payment type constants
    const TYPE_SALARY_DEDUCTION = 'salary_deduction';
    const TYPE_DIRECT_PAYMENT = 'direct_payment';
    const TYPE_ADJUSTMENT = 'adjustment';

    // Relationships

    /**
     * Loan this payment belongs to
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoanModel::class, 'loan_id', 'id');
    }

    /**
     * Salary slip if payment was via salary deduction
     */
    public function salarySlip(): BelongsTo
    {
        return $this->belongsTo(SalarySlipModel::class, 'salary_slip_id', 'id');
    }

    /**
     * User who recorded this payment
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    // Scopes

    /**
     * Payments for a specific loan
     */
    public function scopeForLoan($query, int $loanId)
    {
        return $query->where('loan_id', $loanId);
    }

    /**
     * Salary deduction payments only
     */
    public function scopeSalaryDeductions($query)
    {
        return $query->where('payment_type', self::TYPE_SALARY_DEDUCTION);
    }

    /**
     * Direct payments only
     */
    public function scopeDirectPayments($query)
    {
        return $query->where('payment_type', self::TYPE_DIRECT_PAYMENT);
    }

    /**
     * Recent payments first
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('payment_date', 'desc');
    }

    // Helper Methods

    /**
     * Is this a salary deduction?
     */
    public function isSalaryDeduction(): bool
    {
        return $this->payment_type === self::TYPE_SALARY_DEDUCTION;
    }

    /**
     * Is this a direct payment?
     */
    public function isDirectPayment(): bool
    {
        return $this->payment_type === self::TYPE_DIRECT_PAYMENT;
    }

    /**
     * Get formatted payment info
     */
    public function getPaymentInfo(): array
    {
        return [
            'id' => $this->id,
            'payment_date' => $this->payment_date->format('M d, Y'),
            'payment_amount' => $this->payment_amount,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'reduction' => $this->balance_before - $this->balance_after,
            'payment_type' => $this->payment_type,
            'payment_notes' => $this->payment_notes,
            'via_salary_slip' => $this->salary_slip_id ? true : false,
            'slip_number' => $this->salarySlip->slip_number ?? null,
            'recorded_by' => $this->creator->fullname ?? 'System'
        ];
    }
}

