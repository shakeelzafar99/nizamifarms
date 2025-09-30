<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    // Admin/Manager view
    public function index(Request $request)
    {
        return view('pages.attendance.index');
    }

    public function data(Request $request)
    {
        $query = DB::table('t_ops_attendance as a')
            ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
            ->select(
                'a.*', 
                'u.fullname',
                DB::raw('COALESCE(rp.shift_start, "09:00") as shift_start'),
                DB::raw('COALESCE(rp.shift_end, "17:00") as shift_end')
            );

        if ($request->filled('user_id')) {
            $query->where('a.user_id', (int)$request->input('user_id'));
        }
        if ($request->filled('user')) {
            $search = $request->input('user');
            $query->where(function($q) use ($search) {
                $q->where('u.fullname', 'like', '%' . $search . '%')
                  ->orWhere('u.id', '=', $search);
            });
        }
        if ($request->filled('date')) {
            $query->whereDate('a.attendance_date', $request->input('date'));
        }

        $rows = $query->orderByDesc('a.attendance_date')->limit(500)->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    // Store new attendance record
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'attendance_date' => 'required|date',
            'login_time' => 'nullable|date_format:H:i',
            'logout_time' => 'nullable|date_format:H:i'
        ]);

        // Check if attendance already exists
        $existing = DB::table('t_ops_attendance')
            ->where('user_id', $validated['user_id'])
            ->where('attendance_date', $validated['attendance_date'])
            ->first();

        if ($existing) {
            // Update existing
            DB::table('t_ops_attendance')
                ->where('id', $existing->id)
                ->update([
                    'login_time' => $validated['login_time'],
                    'logout_time' => $validated['logout_time']
                ]);
        } else {
            // Insert new
            DB::table('t_ops_attendance')->insert([
                'user_id' => $validated['user_id'],
                'attendance_date' => $validated['attendance_date'],
                'login_time' => $validated['login_time'],
                'logout_time' => $validated['logout_time'],
                'created_at' => now()
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Attendance recorded']);
    }

    // Get summary/reports
    public function summary(Request $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');

        if (!$start || !$end) {
            return response()->json(['success' => false, 'message' => 'Start and end dates required'], 400);
        }

        $records = DB::table('t_ops_attendance as a')
            ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
            ->whereBetween('a.attendance_date', [$start, $end])
            ->select(
                'a.*',
                'u.fullname',
                DB::raw('COALESCE(rp.shift_start, "09:00") as shift_start')
            )
            ->get();

        $onTime = 0;
        $late = 0;
        $absent = 0;
        $byUser = [];

        foreach ($records as $r) {
            if (!$r->login_time) {
                $absent++;
            } elseif ($r->login_time > $r->shift_start) {
                $late++;
            } else {
                $onTime++;
            }

            // Per user stats
            if (!isset($byUser[$r->user_id])) {
                $byUser[$r->user_id] = [
                    'name' => $r->fullname,
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                    'total_hours' => 0,
                    'days' => 0
                ];
            }

            if (!$r->login_time) {
                $byUser[$r->user_id]['absent']++;
            } else {
                $byUser[$r->user_id]['present']++;
                if ($r->login_time > $r->shift_start) {
                    $byUser[$r->user_id]['late']++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'on_time' => $onTime,
                'late' => $late,
                'absent' => $absent,
                'by_user' => array_values($byUser)
            ]
        ]);
    }

    // Rider self view
    public function mine(Request $request)
    {
        return view('pages.attendance.mine');
    }

    public function mineData(Request $request)
    {
        $userId = $request->user()->id;
        $rows = DB::table('t_ops_attendance')->where('user_id', $userId)
            ->orderByDesc('attendance_date')->limit(500)->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }
}


