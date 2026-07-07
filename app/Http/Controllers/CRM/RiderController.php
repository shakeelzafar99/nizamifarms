<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;

class RiderController extends Controller
{
    // Return active delivery riders (id + fullname). A delivery rider = a user with an
    // ACTIVE rider profile (rider_profile.active=1) — the single curated assign list,
    // managed in the People list. Managers/office (no profile) are excluded.
    public function active()
    {
        $rows = \DB::table('t_sys_user as u')
            ->join('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where('p.active', 1)
            ->where('u.is_active', 1)
            ->orderBy('u.fullname')
            ->get([
                'u.id',
                'u.fullname',
            ]);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }
}


