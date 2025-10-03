<?php

namespace App\Models\Request;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;

class RequestCategoryModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_req_category';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'category_code',
        'category_name',
        'description',
        'icon',
        'color_class',
        'is_active',
        'sequence_order',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sequence_order' => 'integer'
    ];

    // Relationships
    public function requests(): HasMany
    {
        return $this->hasMany(RequestModel::class, 'category_id');
    }

    public function approvalConfig(): HasOne
    {
        return $this->hasOne(RequestCategoryApprovalConfigModel::class, 'category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence_order');
    }

    // Helper methods
    public static function getByCode(string $code): ?self
    {
        return static::where('category_code', $code)->active()->first();
    }

    public static function getActiveCategories()
    {
        return static::with('approvalConfig')->active()->ordered()->get();
    }

    public function requiresLevel1(): bool
    {
        return $this->approvalConfig ? $this->approvalConfig->requires_level_1 : true;
    }

    public function requiresLevel2(): bool
    {
        return $this->approvalConfig ? $this->approvalConfig->requires_level_2 : false;
    }

    public function getRequiredApprovalLevels(): array
    {
        $levels = [];
        if ($this->requiresLevel1()) $levels[] = 1;
        if ($this->requiresLevel2()) $levels[] = 2;
        return $levels;
    }
}

