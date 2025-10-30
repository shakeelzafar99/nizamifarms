<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Model;

class MobilePermissionModel extends Model
{
    protected $table = 't_sys_mobile_permission';
    
    protected $fillable = [
        'permission_code',
        'permission_name',
        'permission_group',
        'description',
        'display_order',
        'is_active'
    ];
    
    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer'
    ];
    
    /**
     * Get roles that have this permission
     */
    public function roles()
    {
        return $this->belongsToMany(
            RoleModel::class,
            't_sys_role_mobile_permission',
            'mobile_permission_id',
            'role_id'
        );
    }
    
    /**
     * Scope: Only active permissions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
    
    /**
     * Scope: By permission group
     */
    public function scopeByGroup($query, $group)
    {
        return $query->where('permission_group', $group);
    }
    
    /**
     * Get all permissions grouped by permission_group
     */
    public static function getAllGrouped()
    {
        return self::active()
            ->orderBy('permission_group')
            ->orderBy('display_order')
            ->orderBy('permission_name')
            ->get()
            ->groupBy('permission_group');
    }
}

