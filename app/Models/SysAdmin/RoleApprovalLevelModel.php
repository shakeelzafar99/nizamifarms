<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\BaseModel;

class RoleApprovalLevelModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_sys_role_approval_level';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'role_id',
        'approval_level',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'approval_level' => 'integer',
        'is_active' => 'boolean'
    ];

    // Relationships
    public function role(): BelongsTo
    {
        return $this->belongsTo(RoleModel::class, 'role_id', 'id');
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

    public function scopeLevel($query, int $level)
    {
        return $query->where('approval_level', $level);
    }

    // Helper methods
    public static function getRolesByLevel(int $level)
    {
        return static::with('role')
            ->active()
            ->level($level)
            ->get()
            ->pluck('role');
    }

    public static function getUsersWithApprovalLevel(int $level)
    {
        $roleIds = static::active()
            ->level($level)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return collect();
        }

        return UserModel::whereHas('roles', function($query) use ($roleIds) {
            $query->whereIn('t_sys_role.id', $roleIds);
        })
        ->where('is_active', 1)
        ->get();
    }

    public static function userHasApprovalLevel(int $userId, int $level): bool
    {
        return UserModel::find($userId)
            ->roles()
            ->whereHas('approvalLevels', function($query) use ($level) {
                $query->where('approval_level', $level)
                    ->where('is_active', 1);
            })
            ->exists();
    }
}

