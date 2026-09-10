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

    /**
     * 👥 THE ONE ROSTER the shift planners draw from — web grid AND mobile list.
     *
     * Owner ruling (Sep-2026): *"we should have one common list which is the
     * attendance"*. Before this, the two planners disagreed — the web grid could show
     * every active account ("All" chip) while mobile showed ONLY ticked delivery
     * riders, so a Management person with real shift assignments (Shabib, 12 rows)
     * was unschedulable from a phone. The Delivery Rider tick keeps its own meaning
     * (order-assign lists, Bikes roster, /riders page) and no longer decides who can
     * be given a shift.
     *
     * The rule, in one place so the two surfaces cannot drift again:
     *   1. Active accounts VISIBLE IN ATTENDANCE — the roster curated by
     *      "Customize user list". `t_ops_attendance_visibility`, where NO ROW MEANS
     *      VISIBLE (only an explicit is_visible = 0 hides someone). Same join as
     *      AttendanceController, which is the source of truth for it, and as
     *      countedByCandidates() above (Aug-2026, same owner rule).
     *      ⇒ Taimur is hidden from attendance and so is off the planner: he does not
     *        work shifts. Nothing to configure — the exemption he already has carries.
     *      ⇒ A new hire needs NO setup: no visibility row = visible = schedulable.
     *
     *   2. ⚠ SAFETY NET — someone hidden from attendance is still kept on the roster
     *      while they have shift state a planner must be able to REACH, and is tagged
     *      `off_roster` so the UI can say why. Without this, hiding someone would strand
     *      a live change that no screen could open to cancel. They drop off by
     *      themselves once the row ends. (This is the ONLY reason a hidden person may
     *      appear — being an active account is not enough.)
     *
     *      ⚠⚠ "Reachable" is deliberately NARROW, and matches exactly the rows the grid
     *      draws a Cancel button on (`temporary` + `upcoming_primary` in both planners):
     *        · a temporary assignment still covering today or later (`effective_to >= today`)
     *        · a future-dated primary change (`effective_from > today`)
     *        · an unanswered shift-change request
     *      It must NOT be "has an open assignment row". An open-ended primary row with no
     *      future date is just somebody's standing shift, and on this data EVERY person
     *      ever scheduled has one — including three riders last seen in 2025 whose rows
     *      were never closed. Treating those as live would make anyone who ever held a
     *      shift immune to being hidden, which is the whole ruling undone.
     *
     * ⚠ Deploy-order safe: the request table is only consulted when it exists, so the
     *   web files can go up before `shift_authority_sep2026.sql` without a 500.
     *
     * Returns the roster user ids. Callers add their own joins and `whereIn('u.id', …)`
     * so each surface keeps building the payload it needs.
     *
     * @return array{ids: int[], off_roster: int[]}
     */
    public static function shiftPlannerRoster(): array
    {
        // 1. The attendance roster.
        $visible = \Illuminate\Support\Facades\DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->where(function ($q) {
                $q->whereNull('av.is_visible')   // no record = visible
                  ->orWhere('av.is_visible', 1); // explicitly visible
            })
            ->pluck('u.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // 2. The safety net: live shift state on someone the roster no longer covers.
        //    Restricted to ACTIVE accounts — a disabled login is never schedulable.
        $today = now()->format('Y-m-d');
        $stranded = [];

        try {
            $stranded = \Illuminate\Support\Facades\DB::table('t_ops_user_shift_assignment as a')
                ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->where('u.is_active', 1)
                ->whereNotIn('a.user_id', $visible ?: [0])
                ->where(function ($q) use ($today) {
                    // A temporary cover still running…
                    $q->where(function ($w) use ($today) {
                        $w->whereNotNull('a.effective_to')
                          ->whereDate('a.effective_to', '>=', $today);
                    })
                    // …or a primary change that has not started yet.
                    ->orWhere(function ($w) use ($today) {
                        $w->whereNotNull('a.effective_from')
                          ->whereDate('a.effective_from', '>', $today);
                    });
                })
                ->distinct()
                ->pluck('a.user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            $stranded = [];
        }

        // Unanswered shift-change requests, when that table is deployed.
        try {
            if (app(\App\Services\Ops\ShiftAuthorityService::class)->requestsAvailable()) {
                $open = \Illuminate\Support\Facades\DB::table(\App\Services\Ops\ShiftChangeRequestService::T . ' as r')
                    ->join('t_sys_user as u', 'u.id', '=', 'r.user_id')
                    ->where('u.is_active', 1)
                    ->where('r.status', \App\Services\Ops\ShiftChangeRequestService::STATUS_OPEN)
                    ->whereNotIn('r.user_id', $visible ?: [0])
                    ->distinct()
                    ->pluck('r.user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $stranded = array_values(array_unique(array_merge($stranded, $open)));
            }
        } catch (\Throwable $e) {
            // Table not deployed yet → no extra names. Never fatal.
        }

        return [
            'ids' => array_values(array_unique(array_merge($visible, $stranded))),
            'off_roster' => array_values(array_unique($stranded)),
        ];
    }
}
