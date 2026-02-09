<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_vendors';
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
        'vendor_code',
        'vendor_name',
        'contact_person',
        'contact_phone',
        'contact_email',
        'address',
        'payment_terms',
        'account_id',
        'default_purchase_method',
        'default_payment_source_id',
        'is_active',
        'business_unit_id', // ⭐ FK to t_fin_business_units
        'notes',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];
    
    protected $appends = ['purchase_method'];

    /**
     * Relationships
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'account_id', 'id');
    }

    public function defaultPaymentSource(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'default_payment_source_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
    }

    /**
     * Business Unit relationship
     */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnitModel::class, 'business_unit_id', 'id');
    }

    public function purchases()
    {
        return $this->hasManyThrough(
            LedgerModel::class,
            AccountModel::class,
            'id',
            'to_account_id',
            'account_id',
            'id'
        )->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE);
    }

    public function payments()
    {
        return $this->hasManyThrough(
            LedgerModel::class,
            AccountModel::class,
            'id',
            'to_account_id',
            'account_id',
            'id'
        )->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT);
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
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

    /**
     * Helper Methods
     */
    public function getBalance()
    {
        return $this->account ? $this->account->current_balance : 0;
    }

    public function getFormattedBalanceAttribute()
    {
        return number_format($this->getBalance(), 2);
    }
    
    /**
     * Accessor for purchase_method (maps to default_purchase_method for API consistency)
     */
    public function getPurchaseMethodAttribute()
    {
        return $this->default_purchase_method;
    }

    public function getTotalPurchases()
    {
        if (!$this->account) {
            return 0;
        }

        return LedgerModel::where('to_account_id', $this->account->id)
            ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->sum('amount');
    }

    public function getTotalPayments()
    {
        if (!$this->account) {
            return 0;
        }

        // Vendor payments: Money flows FROM other accounts TO vendor account
        // So vendor account is the to_account_id (receiving the payment)
        return LedgerModel::where('to_account_id', $this->account->id)
            ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->sum('amount');
    }

    /**
     * Get or create vendor with account
     * @param string $vendorName
     * @param int|null $createdBy
     * @param int $businessUnitId - Defaults to 1 (Nizami Farms)
     */
    public static function getOrCreateVendor($vendorName, $createdBy = null, $businessUnitId = 1)
    {
        $vendor = static::where('vendor_name', $vendorName)->first();
        
        if (!$vendor) {
            // Create account first (also with business unit)
            $account = AccountModel::createVendorAccount($vendorName, $businessUnitId);
            
            // Create vendor
            $vendor = static::create([
                'vendor_name' => $vendorName,
                'account_id' => $account->id,
                'business_unit_id' => $businessUnitId, // ⭐ Default to Nizami Farms
                'is_active' => 1,
                'created_by' => $createdBy ?? auth()->id() ?? 1
            ]);
        }
        
        return $vendor;
    }

    /**
     * Get ledger for this vendor
     */
    public function getLedger($startDate = null, $endDate = null)
    {
        if (!$this->account) {
            return collect();
        }

        $query = LedgerModel::where(function($q) {
            $q->where('from_account_id', $this->account->id)
              ->orWhere('to_account_id', $this->account->id);
        })->orderBy('transaction_date', 'asc');

        if ($startDate) {
            $query->where('transaction_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('transaction_date', '<=', $endDate);
        }

        return $query->with(['fromAccount', 'toAccount'])->get();
    }
}

