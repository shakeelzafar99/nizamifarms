<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;

class RiderController extends Controller
{
    // Return active riders (id + fullname)
    public function active()
    {
        $rows = \DB::table('t_sys_user as u')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where(function ($q) {
                $q->whereNull('p.user_id')->orWhere('p.active', 1);
            })
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


