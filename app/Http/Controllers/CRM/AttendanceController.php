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
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:t_sys_user,id',
                'attendance_date' => 'required|date',
                'login_time' => 'nullable|date_format:H:i',
                'logout_time' => 'nullable|date_format:H:i'
            ]);

            $loggedInUserId = auth()->id() ?? 1; // Track who made the change

            // Check if attendance already exists
            $existing = DB::table('t_ops_attendance')
                ->where('user_id', $validated['user_id'])
                ->where('attendance_date', $validated['attendance_date'])
                ->first();

            if ($existing) {
                // Update existing - only update fields that were provided
                $updateData = [];
                if ($request->filled('login_time')) {
                    $updateData['login_time'] = $validated['login_time'];
                }
                if ($request->filled('logout_time')) {
                    $updateData['logout_time'] = $validated['logout_time'];
                }
                
                // Add audit fields
                $updateData['updated_by'] = $loggedInUserId;
                $updateData['updated_at'] = now();
                
                DB::table('t_ops_attendance')
                    ->where('id', $existing->id)
                    ->update($updateData);
            } else {
                // Insert new
                DB::table('t_ops_attendance')->insert([
                    'user_id' => $validated['user_id'],
                    'attendance_date' => $validated['attendance_date'],
                    'login_time' => $validated['login_time'] ?? null,
                    'logout_time' => $validated['logout_time'] ?? null,
                    'created_at' => now(),
                    'created_by' => $loggedInUserId
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Attendance recorded']);
        } catch (\Exception $e) {
            \Log::error('Attendance store error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    // Monthly report for all employees
    public function monthlyReport(Request $request)
    {
        $month = $request->input('month', date('Y-m'));
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $data = DB::table('t_ops_attendance as a')
            ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
            ->whereBetween('a.attendance_date', [$startDate, $endDate])
            ->select(
                'a.*',
                'u.fullname',
                DB::raw('COALESCE(rp.shift_start, "09:00") as shift_start'),
                DB::raw('COALESCE(rp.shift_end, "17:00") as shift_end')
            )
            ->orderBy('u.fullname')
            ->orderBy('a.attendance_date')
            ->get();
            
        // Group by user
        $byUser = [];
        foreach ($data as $record) {
            if (!isset($byUser[$record->user_id])) {
                $byUser[$record->user_id] = [
                    'user_id' => $record->user_id,
                    'fullname' => $record->fullname,
                    'total_days' => 0,
                    'present_days' => 0,
                    'late_days' => 0,
                    'overtime_days' => 0,
                    'total_hours' => 0,
                    'total_late_minutes' => 0,
                    'total_overtime_minutes' => 0,
                    'daily' => []
                ];
            }
            
            $byUser[$record->user_id]['total_days']++;
            if ($record->login_time) {
                $byUser[$record->user_id]['present_days']++;
                
                // Calculate late
                if ($record->login_time > $record->shift_start) {
                    $byUser[$record->user_id]['late_days']++;
                    $late_mins = (strtotime($record->login_time) - strtotime($record->shift_start)) / 60;
                    $byUser[$record->user_id]['total_late_minutes'] += $late_mins;
                }
                
                // Calculate overtime
                if ($record->logout_time && $record->logout_time > $record->shift_end) {
                    $byUser[$record->user_id]['overtime_days']++;
                    $ot_mins = (strtotime($record->logout_time) - strtotime($record->shift_end)) / 60;
                    $byUser[$record->user_id]['total_overtime_minutes'] += $ot_mins;
                }
                
                // Calculate hours worked
                if ($record->logout_time) {
                    $hours = (strtotime($record->logout_time) - strtotime($record->login_time)) / 3600;
                    $byUser[$record->user_id]['total_hours'] += $hours;
                }
            }
            
            // Add as array for JSON serialization
            $byUser[$record->user_id]['daily'][] = [
                'attendance_date' => $record->attendance_date,
                'login_time' => $record->login_time,
                'logout_time' => $record->logout_time,
                'shift_start' => $record->shift_start,
                'shift_end' => $record->shift_end
            ];
        }
        
        return response()->json(['success' => true, 'data' => array_values($byUser), 'month' => $month]);
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


