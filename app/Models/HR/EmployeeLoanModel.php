<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use App\Models\FIN\LedgerModel;

class EmployeeLoanModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_hr_employee_loans';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'loan_date',
        'loan_number',
        'principal_amount',
        'monthly_installment',
        'outstanding_balance',
        'loan_status',
        'loan_type',
        'description',
        'terms',
        'notes',
        'ledger_transaction_id',
        'disbursement_account_id', // ⭐ Track source account (NULL = outside cash)
        'created_by',
        'updated_by',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason'
    ];

    protected $casts = [
        'loan_date' => 'date',
        'principal_amount' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime'
    ];

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Relationships

    /**
     * Employee who has this loan
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }

    /**
     * Ledger transaction if loan was disbursed via ledger
     */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_transaction_id', 'id');
    }

    /**
     * Payment history for this loan
     */
    public function payments(): HasMany
    {
        return $this->hasMany(LoanPaymentModel::class, 'loan_id', 'id')
            ->orderBy('payment_date', 'desc');
    }

    /**
     * User who created this loan
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    /**
     * User who cancelled this loan
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'cancelled_by', 'id');
    }

    // Scopes

    /**
     * Active loans only
     */
    public function scopeActive($query)
    {
        return $query->where('loan_status', self::STATUS_ACTIVE);
    }

    /**
     * Completed loans only
     */
    public function scopeCompleted($query)
    {
        return $query->where('loan_status', self::STATUS_COMPLETED);
    }

    /**
     * Cancelled loans only
     */
    public function scopeCancelled($query)
    {
        return $query->where('loan_status', self::STATUS_CANCELLED);
    }

    /**
     * Loans for a specific user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Loans with outstanding balance
     */
    public function scopeWithBalance($query)
    {
        return $query->where('outstanding_balance', '>', 0);
    }

    // Helper Methods

    /**
     * Check if loan is active
     */
    public function isActive(): bool
    {
        return $this->loan_status === self::STATUS_ACTIVE;
    }

    /**
     * Check if loan is completed
     */
    public function isCompleted(): bool
    {
        return $this->loan_status === self::STATUS_COMPLETED;
    }

    /**
     * Check if loan is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->loan_status === self::STATUS_CANCELLED;
    }

    /**
     * Get percentage paid
     */
    public function getPercentagePaid(): float
    {
        if ($this->principal_amount <= 0) {
            return 0;
        }

        $paid = $this->principal_amount - $this->outstanding_balance;
        return ($paid / $this->principal_amount) * 100;
    }

    /**
     * Get amount paid so far
     */
    public function getAmountPaid(): float
    {
        return $this->principal_amount - $this->outstanding_balance;
    }

    /**
     * Record a payment against this loan
     */
    public function recordPayment(
        float $amount, 
        ?int $salarySlipId = null,
        string $paymentType = 'salary_deduction',
        ?string $notes = null,
        ?int $createdBy = null
    ): ?LoanPaymentModel {
        
        if ($amount <= 0 || !$this->isActive()) {
            return null;
        }

        $balanceBefore = $this->outstanding_balance;
        $balanceAfter = max(0, $balanceBefore - $amount);

        // Create payment record
        $payment = LoanPaymentModel::create([
            'loan_id' => $this->id,
            'salary_slip_id' => $salarySlipId,
            'payment_date' => now(),
            'payment_amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'payment_type' => $paymentType,
            'payment_notes' => $notes,
            'created_by' => $createdBy
        ]);

        // Update loan balance
        $this->outstanding_balance = $balanceAfter;
        
        // If fully paid, mark as completed
        if ($this->outstanding_balance <= 0) {
            $this->loan_status = self::STATUS_COMPLETED;
            $this->completed_at = now();
        }
        
        $this->save();

        return $payment;
    }

    /**
     * Cancel this loan
     */
    public function cancel(string $reason, ?int $cancelledBy = null): bool
    {
        if ($this->isCancelled()) {
            return false;
        }

        $this->loan_status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();
        $this->cancelled_by = $cancelledBy;
        $this->cancellation_reason = $reason;

        return $this->save();
    }

    /**
     * Get estimated months remaining
     */
    public function getMonthsRemaining(): int
    {
        if ($this->monthly_installment <= 0 || $this->outstanding_balance <= 0) {
            return 0;
        }

        return (int) ceil($this->outstanding_balance / $this->monthly_installment);
    }

    /**
     * Get loan summary for display
     */
    public function getLoanSummary(): array
    {
        return [
            'id' => $this->id,
            'loan_number' => $this->loan_number,
            'employee_name' => $this->employee->fullname ?? 'Unknown',
            'loan_type' => $this->loan_type,
            'loan_date' => $this->loan_date->format('M d, Y'),
            'principal_amount' => $this->principal_amount,
            'outstanding_balance' => $this->outstanding_balance,
            'amount_paid' => $this->getAmountPaid(),
            'percentage_paid' => round($this->getPercentagePaid(), 2),
            'monthly_installment' => $this->monthly_installment,
            'months_remaining' => $this->getMonthsRemaining(),
            'loan_status' => $this->loan_status,
            'description' => $this->description,
            'is_active' => $this->isActive(),
            'payments_count' => $this->payments()->count()
        ];
    }

    /**
     * Generate unique loan number
     */
    public static function generateLoanNumber(): string
    {
        $year = date('Y');
        $lastLoan = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastLoan ? (intval(substr($lastLoan->loan_number, -4)) + 1) : 1;
        
        return 'LOAN-' . $year . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}

