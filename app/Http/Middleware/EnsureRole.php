<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        // Fetch user roles from pivot table t_sys_user_role -> t_sys_role
        $userRoleNames = DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->pluck('r.urole_name')
            ->map(fn($n) => strtolower(trim($n)))
            ->toArray();

        $required = array_map(fn($n) => strtolower(trim($n)), $roles);
        if (!array_intersect($required, $userRoleNames)) {
            abort(403);
        }

        return $next($request);
    }
}


