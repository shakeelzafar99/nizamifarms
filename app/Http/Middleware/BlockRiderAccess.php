<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Denies access to accounts that are riders ONLY (hold a 'rider' role and no staff
 * role of any other type). Mirrors the sidebar's `$userRole !== 'rider'` visibility
 * rule for the Operations / bulk-import area, but enforced server-side so the page
 * and its action endpoints cannot be reached by direct URL.
 *
 * Deliberately blocks only PURE riders: a multi-role account that also holds a
 * non-rider role is treated as staff and allowed through, so this can never revoke
 * access from anyone who has it today (it only closes the rider hole).
 */
class BlockRiderAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $roleTypes = DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $user->id)
                ->pluck('r.type')
                ->all();

            $hasRiderRole = in_array('rider', $roleTypes, true);
            $hasStaffRole = count(array_filter($roleTypes, function ($type) {
                return $type !== 'rider';
            })) > 0;

            if ($hasRiderRole && !$hasStaffRole) {
                abort(403, 'This area is not available for rider accounts.');
            }
        }

        return $next($request);
    }
}
