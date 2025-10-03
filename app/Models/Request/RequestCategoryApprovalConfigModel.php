<?php

namespace App\Models\Request;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;

class RequestCategoryApprovalConfigModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_req_category_approval_config';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'category_id',
        'requires_level_1',
        'requires_level_2',
        'auto_approve_threshold',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'requires_level_1' => 'boolean',
        'requires_level_2' => 'boolean',
        'auto_approve_threshold' => 'decimal:2'
    ];

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(RequestCategoryModel::class, 'category_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
    }

    // Helper methods
    public function getRequiredLevels(): array
    {
        $levels = [];
        if ($this->requires_level_1) $levels[] = 1;
        if ($this->requires_level_2) $levels[] = 2;
        return $levels;
    }

    public function canAutoApprove(?float $amount): bool
    {
        if ($this->auto_approve_threshold === null || $amount === null) {
            return false;
        }
        return $amount <= $this->auto_approve_threshold;
    }
}

