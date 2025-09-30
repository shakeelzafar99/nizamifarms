<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;

class RolePermissionModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_sys_role_permissions';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'role_id',
        'permission_key',
        'permission_name',
        'is_allowed',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_allowed' => 'boolean'
    ];

    public function role()
    {
        return $this->belongsTo(RoleModel::class, 'role_id');
    }

    // Get permissions for a role
    public static function getPermissionsForRole($roleId)
    {
        return static::where('role_id', $roleId)->pluck('is_allowed', 'permission_key');
    }

    // Check if role has permission
    public static function hasPermission($roleId, $permissionKey)
    {
        return static::where('role_id', $roleId)
            ->where('permission_key', $permissionKey)
            ->where('is_allowed', true)
            ->exists();
    }
}
