<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiderProfileController extends Controller
{
    public function index()
    {
        $riders = DB::table('t_sys_user as u')
            ->join('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where('r.type', 'rider')
            ->where('u.is_active', 1)
            ->select([
                'u.id', 'u.fullname', 'u.email',
                'p.phone', 'p.vehicle_type', 'p.vehicle_plate', 
                'p.hire_date', 'p.active as profile_active'
            ])
            ->get();

        return view('pages.riders.index', compact('riders'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:t_sys_user,id',
            'phone' => 'nullable|string|max:50',
            'emergency_contact' => 'nullable|string|max:100',
            'vehicle_type' => 'nullable|string|max:50',
            'vehicle_plate' => 'nullable|string|max:50',
            'hire_date' => 'nullable|date',
            'active' => 'boolean'
        ]);

        try {
            DB::table('t_ops_rider_profile')->updateOrInsert(
                ['user_id' => $request->user_id],
                [
                    'phone' => $request->phone,
                    'emergency_contact' => $request->emergency_contact,
                    'vehicle_type' => $request->vehicle_type,
                    'vehicle_plate' => $request->vehicle_plate,
                    'hire_date' => $request->hire_date,
                    'active' => $request->active ?? 1,
                    'notes' => $request->notes,
                    'updated_at' => now()
                ]
            );

            return redirect()->route('riders.index')->with('success', 'Rider profile updated successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error updating rider profile: ' . $e->getMessage());
        }
    }

    public function show($userId)
    {
        $profile = DB::table('t_ops_rider_profile')->where('user_id', $userId)->first();
        return response()->json(['success' => true, 'profile' => $profile]);
    }
}
