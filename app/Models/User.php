<?php

namespace App\Models;
 
use Tymon\JWTAuth\Contracts\JWTSubject; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\SysAdmin\RoleModel;
use App\Models\SysAdmin\RolePermissionModel;

class User extends Authenticatable 
{
    use HasFactory, Notifiable, HasApiTokens;
    protected $table = 't_sys_user';
    protected $primaryKey = 'id';
     
    protected $fillable = [ 
        'id',
        'company_id',
        'branch_id',
        'fullname',
        'email',
        'password',
        'user_type',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * User roles relationship
     */
    public function roles()
    {
        return $this->belongsToMany(RoleModel::class, 't_sys_user_role', 'user_id', 'role_id');
    }

    /**
     * Check if user has a specific permission
     * Checks across all roles the user has
     */
    public function hasPermission(string $permissionKey): bool
    {
        foreach ($this->roles as $role) {
            if (RolePermissionModel::hasPermission($role->id, $permissionKey)) {
                return true;
            }
        }
        return false;
    }
}
