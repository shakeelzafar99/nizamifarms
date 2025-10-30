<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Model;

class RoleMobilePermissionModel extends Model
{
    protected $table = 't_sys_role_mobile_permission';
    
    protected $fillable = [
        'role_id',
        'mobile_permission_id'
    ];
    
    public $timestamps = false; // Only has created_at
    
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;
    
    /**
     * Get the role
     */
    public function role()
    {
        return $this->belongsTo(RoleModel::class, 'role_id');
    }
    
    /**
     * Get the mobile permission
     */
    public function mobilePermission()
    {
        return $this->belongsTo(MobilePermissionModel::class, 'mobile_permission_id');
    }
}

