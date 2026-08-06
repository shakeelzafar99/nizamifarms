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

    /**
     * View-only account? (permission `account_read_only`).
     *
     * Memoized per instance — the authenticated user is a per-request singleton,
     * so this costs one query per request no matter how many blades ask. Used by
     * ReadOnlyGuard (the hard block) and by blades/layout to hide write controls
     * and show the view-only banner.
     */
    protected ?bool $readOnlyCache = null;

    public function isReadOnly(): bool
    {
        if ($this->readOnlyCache === null) {
            $this->readOnlyCache = $this->hasPermission('account_read_only');
        }
        return $this->readOnlyCache;
    }

    /**
     * Check if user has a specific mobile permission
     */
    public function hasMobilePermission(string $permissionCode): bool
    {
        $permissions = $this->getMobilePermissions();
        return in_array($permissionCode, $permissions);
    }

    /**
     * Get all mobile permissions for this user across all their roles
     */
    public function getMobilePermissions(): array
    {
        $permissions = [];
        
        foreach ($this->roles as $role) {
            // Get mobile permissions for this role
            $rolePermissions = $role->mobilePermissions()
                ->where('is_active', 1)
                ->pluck('permission_code')
                ->toArray();
            
            $permissions = array_merge($permissions, $rolePermissions);
        }
        
        // Return unique permissions
        return array_unique($permissions);
    }

    /**
     * Staff who may be picked as "counted by" on a Frozen warehouse transfer.
     *
     * Owner's rule (Aug-2026): use the SAME list the Attendance page shows —
     * i.e. the roster curated via "Customize user list", not every active
     * account. That's `t_ops_attendance_visibility`, where NO ROW MEANS VISIBLE
     * (only an explicit is_visible = 0 hides someone) — mirroring
     * AttendanceController's own join, which is the source of truth for it.
     *
     * ⚠ $alwaysIncludeId (the current user) is unioned in regardless of that
     * setting, because the picker DEFAULTS to whoever is approving and several
     * managers — Taimur included — are deliberately hidden from attendance since
     * they don't clock in. Without this they could not record themselves at all.
     *
     * Current user sorts first so the default sits at the top of the list.
     */
    public static function countedByCandidates(?int $alwaysIncludeId = null)
    {
        return \Illuminate\Support\Facades\DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->where(function ($q) use ($alwaysIncludeId) {
                $q->whereNull('av.is_visible')      // no record = visible
                  ->orWhere('av.is_visible', 1);    // explicitly visible
                if ($alwaysIncludeId) {
                    $q->orWhere('u.id', $alwaysIncludeId);
                }
            })
            ->orderByRaw('CASE WHEN u.id = ? THEN 0 ELSE 1 END', [$alwaysIncludeId ?? 0])
            ->orderBy('u.fullname')
            ->get(['u.id', 'u.fullname']);
    }
}
