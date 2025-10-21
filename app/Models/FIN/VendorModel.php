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
        'is_active',
        'notes',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    /**
     * Relationships
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'account_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
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

        return LedgerModel::where('from_account_id', $this->account->id)
            ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->sum('amount');
    }

    /**
     * Get or create vendor with account
     */
    public static function getOrCreateVendor($vendorName, $createdBy = null)
    {
        $vendor = static::where('vendor_name', $vendorName)->first();
        
        if (!$vendor) {
            // Create account first
            $account = AccountModel::createVendorAccount($vendorName);
            
            // Create vendor
            $vendor = static::create([
                'vendor_name' => $vendorName,
                'account_id' => $account->id,
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

