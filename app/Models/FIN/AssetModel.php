<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AssetModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_assets';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'asset_code',
        'asset_name',
        'description',
        'business_unit_id',
        'category_id',
        'purchase_date',
        'purchase_amount',
        'vendor_id',
        'vendor_name_snapshot',
        'payment_account_id',
        'payment_mode',
        'serial_number',
        'model_number',
        'location',
        'condition',
        'useful_life_years',
        'salvage_value',
        'depreciation_method',
        'current_book_value',
        'purchased_by',
        'ledger_transaction_id',
        'status',
        'disposal_date',
        'disposal_amount',
        'disposal_notes',
        'bill_image',
        'notes',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'disposal_date' => 'date',
        'purchase_amount' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'current_book_value' => 'decimal:2',
        'disposal_amount' => 'decimal:2',
        'useful_life_years' => 'integer'
    ];

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_DISPOSED = 'disposed';
    const STATUS_SOLD = 'sold';
    const STATUS_WRITTEN_OFF = 'written_off';
    const STATUS_TRANSFERRED = 'transferred';

    // Condition constants
    const CONDITION_NEW = 'new';
    const CONDITION_GOOD = 'good';
    const CONDITION_FAIR = 'fair';
    const CONDITION_POOR = 'poor';
    const CONDITION_DISPOSED = 'disposed';

    /**
     * Relationships
     */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnitModel::class, 'business_unit_id', 'id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategoryModel::class, 'category_id', 'id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorModel::class, 'vendor_id', 'id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'payment_account_id', 'id');
    }

    public function purchasedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'purchased_by', 'id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_transaction_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeByBusinessUnit($query, $businessUnitId)
    {
        return $query->where('business_unit_id', $businessUnitId);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Generate unique asset code: AST-YYYY-XXXX
     */
    public static function generateAssetCode(): string
    {
        $year = date('Y');
        $prefix = "AST-{$year}-";
        
        // Get the latest asset code for this year
        $lastAsset = static::where('asset_code', 'LIKE', $prefix . '%')
            ->orderBy('asset_code', 'desc')
            ->first();
        
        if ($lastAsset) {
            $lastNumber = (int) substr($lastAsset->asset_code, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return sprintf('%s%04d', $prefix, $newNumber);
    }

    /**
     * Get formatted purchase amount
     */
    public function getFormattedAmountAttribute(): string
    {
        return 'Rs. ' . number_format($this->purchase_amount, 0);
    }

    /**
     * Get status badge class
     */
    public function getStatusBadgeAttribute(): array
    {
        $badges = [
            self::STATUS_ACTIVE => ['class' => 'bg-green-100 text-green-800', 'label' => 'Active'],
            self::STATUS_DISPOSED => ['class' => 'bg-red-100 text-red-800', 'label' => 'Disposed'],
            self::STATUS_SOLD => ['class' => 'bg-blue-100 text-blue-800', 'label' => 'Sold'],
            self::STATUS_WRITTEN_OFF => ['class' => 'bg-gray-100 text-gray-800', 'label' => 'Written Off'],
            self::STATUS_TRANSFERRED => ['class' => 'bg-yellow-100 text-yellow-800', 'label' => 'Transferred'],
        ];
        
        return $badges[$this->status] ?? ['class' => 'bg-gray-100 text-gray-800', 'label' => ucfirst($this->status)];
    }

    /**
     * Get condition badge class
     */
    public function getConditionBadgeAttribute(): array
    {
        $badges = [
            self::CONDITION_NEW => ['class' => 'bg-green-100 text-green-800', 'label' => 'New'],
            self::CONDITION_GOOD => ['class' => 'bg-blue-100 text-blue-800', 'label' => 'Good'],
            self::CONDITION_FAIR => ['class' => 'bg-yellow-100 text-yellow-800', 'label' => 'Fair'],
            self::CONDITION_POOR => ['class' => 'bg-red-100 text-red-800', 'label' => 'Poor'],
            self::CONDITION_DISPOSED => ['class' => 'bg-gray-100 text-gray-800', 'label' => 'Disposed'],
        ];
        
        return $badges[$this->condition] ?? ['class' => 'bg-gray-100 text-gray-800', 'label' => ucfirst($this->condition)];
    }

    /**
     * Calculate current book value (for straight line depreciation)
     */
    public function calculateCurrentBookValue(): float
    {
        if ($this->depreciation_method === 'none' || !$this->useful_life_years) {
            return $this->purchase_amount;
        }

        $purchaseDate = $this->purchase_date;
        $yearsOwned = now()->diffInYears($purchaseDate);
        
        if ($yearsOwned >= $this->useful_life_years) {
            return $this->salvage_value ?? 0;
        }

        $depreciableAmount = $this->purchase_amount - ($this->salvage_value ?? 0);
        $annualDepreciation = $depreciableAmount / $this->useful_life_years;
        $totalDepreciation = $annualDepreciation * $yearsOwned;
        
        return $this->purchase_amount - $totalDepreciation;
    }

    /**
     * Get summary statistics
     */
    public static function getSummary($businessUnitId = null)
    {
        $query = static::query();
        
        if ($businessUnitId) {
            $query->where('business_unit_id', $businessUnitId);
        }

        return [
            'total_assets' => $query->count(),
            'active_assets' => (clone $query)->active()->count(),
            'total_value' => (clone $query)->active()->sum('purchase_amount'),
            'by_category' => (clone $query)->active()
                ->select('category_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(purchase_amount) as total'))
                ->groupBy('category_id')
                ->with('category:id,name')
                ->get()
        ];
    }
}
