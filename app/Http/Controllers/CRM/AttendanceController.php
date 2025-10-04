<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    // Admin/Manager view
    public function index(Request $request)
    {
        return view('pages.attendance.index');
    }

    public function data(Request $request)
    {
        // Build subquery of leave requests (category=leave) with fields we need
        $leaveSub = DB::table('t_req_master as r')
            ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
            ->where('c.category_code', '=', 'leave')
            ->select(
                'r.id',
                'r.requester_user_id',
                'r.status',
                'r.leave_type',
                'r.leave_start_date',
                'r.leave_end_date'
            );

        // Start from users and LEFT JOIN everything else so users on leave without attendance still show
        $selectedDate = $request->input('date', now()->toDateString());
        
        $query = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance as a', function($join) use ($selectedDate) {
                $join->on('u.id', '=', 'a.user_id')
                     ->whereDate('a.attendance_date', '=', $selectedDate);
            })
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
            // Join leave subquery matching the selected date
            ->leftJoinSub($leaveSub, 'lr', function($join) use ($selectedDate) {
                $join->on('lr.requester_user_id', '=', 'u.id')
                    ->whereIn('lr.status', ['approved', 'pending'])
                    ->whereRaw('? BETWEEN lr.leave_start_date AND lr.leave_end_date', [$selectedDate]);
            })
            ->select(
                'u.id as user_id',
                'u.fullname',
                'a.id as attendance_id',
                'a.attendance_date',
                'a.login_time',
                'a.logout_time',
                'a.notes',
                DB::raw('COALESCE(rp.shift_start, "09:00") as shift_start'),
                DB::raw('COALESCE(rp.shift_end, "17:00") as shift_end'),
                // Leave fields
                'lr.id as leave_request_id',
                'lr.status as leave_status',
                'lr.leave_type as leave_type_from_req'
            )
            // Only show users who have: attendance record OR leave request for this date
            ->where(function($q) use ($selectedDate) {
                $q->whereNotNull('a.id')  // Has attendance
                  ->orWhereNotNull('lr.id'); // Has leave
            })
            ->orderBy('u.fullname');

        if ($request->filled('user_id')) {
            $query->where('u.id', (int)$request->input('user_id'));
        }
        if ($request->filled('user')) {
            $search = $request->input('user');
            $query->where(function($q) use ($search) {
                $q->where('u.fullname', 'like', '%' . $search . '%')
                  ->orWhere('u.id', '=', $search);
            });
        }

        $rows = $query->limit(500)->get();
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

    // Get employee details for last 30 days with order delivery stats
    public function employeeDetails(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            $fromDate = $request->input('from_date');
            
            if (!$userId || !$fromDate) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id and from_date are required'
                ], 400);
            }

            // Calculate date range (30 days before from_date)
            $endDate = $fromDate;
            $startDate = date('Y-m-d', strtotime($fromDate . ' -30 days'));

            // Get user info
            $user = DB::table('t_sys_user as u')
                ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
                ->select(
                    'u.id',
                    'u.fullname',
                    DB::raw('COALESCE(rp.shift_start, "09:00") as shift_start'),
                    DB::raw('COALESCE(rp.shift_end, "17:00") as shift_end')
                )
                ->where('u.id', $userId)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Build per-day delivered orders via subquery to avoid cross-day aggregation
            // Filter by the selected rider and the requested date range, and only current assignments
            $deliveredPerDay = DB::table('t_ops_order_rider_history as orh')
                ->join('t_crm_order_status_history as osh', function($join) {
                    $join->on('osh.order_id', '=', 'orh.order_id')
                         ->where('osh.status_code', 'delivered')
                         ->where('osh.is_current', 1);
                })
                ->select(
                    'orh.rider_user_id as rider_id',
                    DB::raw('DATE(osh.changed_at) as delivered_date'),
                    DB::raw('COUNT(DISTINCT osh.order_id) as orders_delivered'),
                    DB::raw('MIN(TIME(osh.changed_at)) as first_delivery_time'),
                    DB::raw('MAX(TIME(osh.changed_at)) as last_delivery_time')
                )
                ->where('orh.rider_user_id', '=', $userId)
                ->where('orh.is_current', '=', 1)
                ->whereBetween(DB::raw('DATE(osh.changed_at)'), [$startDate, $endDate])
                ->groupBy('orh.rider_user_id', DB::raw('DATE(osh.changed_at)'));

            // Leave requests subquery
            $leaveSub = DB::table('t_req_master')
                ->select(
                    'requester_user_id',
                    'id as leave_request_id',
                    'status as leave_status',
                    'leave_type',
                    'leave_start_date',
                    'leave_end_date'
                )
                ->where('category_id', function($q) {
                    $q->select('id')
                      ->from('t_req_category')
                      ->where('category_code', 'leave')
                      ->limit(1);
                });

            // Attendance rows joined with per-day delivered orders and leave requests for this user
            $query = DB::table('t_ops_attendance as a')
                ->leftJoinSub($deliveredPerDay, 'd', function($join) {
                    $join->on('d.rider_id', '=', 'a.user_id')
                         ->on('d.delivered_date', '=', 'a.attendance_date');
                })
                ->leftJoinSub($leaveSub, 'lr', function($join) {
                    $join->on('lr.requester_user_id', '=', 'a.user_id')
                         ->whereColumn('a.attendance_date', '>=', 'lr.leave_start_date')
                         ->whereColumn('a.attendance_date', '<=', 'lr.leave_end_date');
                })
                ->where('a.user_id', '=', $userId)
                ->whereBetween('a.attendance_date', [$startDate, $endDate])
                ->select(
                    'a.attendance_date',
                    'a.login_time',
                    'a.logout_time',
                    'lr.leave_request_id',
                    'lr.leave_status',
                    'lr.leave_type',
                    DB::raw('COALESCE(d.orders_delivered, 0) as total_orders_delivered'),
                    DB::raw('COALESCE(d.first_delivery_time, NULL) as first_delivery_time'),
                    DB::raw('COALESCE(d.last_delivery_time, NULL) as last_delivery_time')
                )
                ->orderByDesc('a.attendance_date');
            
            // Log the SQL query for debugging
            $sql = $query->toSql();
            $bindings = $query->getBindings();
            
            // Replace ? with actual values for easier debugging
            $fullSql = $sql;
            foreach ($bindings as $binding) {
                $value = is_numeric($binding) ? $binding : "'{$binding}'";
                $fullSql = preg_replace('/\?/', $value, $fullSql, 1);
            }
            
            Log::info('Employee details query', [
                'user_id' => $userId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'sql' => $sql,
                'bindings' => $bindings,
                'full_sql' => $fullSql
            ]);
            
            $records = $query->get();
            
            // Log results for debugging
            Log::info('Employee details results', [
                'user_id' => $userId,
                'record_count' => $records->count(),
                'first_3_records' => $records->take(3)->toArray(),
                'total_orders_sum' => $records->sum('total_orders_delivered')
            ]);

            // Calculate working days in date range (excluding off days)
            // For now, assume Tuesday is off (day 2). You can make this configurable later.
            $workingDays = 0;
            $currentDate = new \DateTime($startDate);
            $endDateObj = new \DateTime($endDate);
            
            while ($currentDate <= $endDateObj) {
                $dayOfWeek = (int)$currentDate->format('N'); // 1=Monday, 7=Sunday
                // Exclude Tuesday (2) - you can make this configurable
                if ($dayOfWeek != 2) {
                    $workingDays++;
                }
                $currentDate->modify('+1 day');
            }

            // Calculate statistics
            $totalDays = $records->count();
            $presentDays = $records->where('login_time', '!=', null)->count();
            
            // Count leave days
            $onLeaveDays = 0;
            foreach ($records as $record) {
                if ($record->leave_request_id && 
                    in_array(strtolower($record->leave_status), ['approved', 'pending'])) {
                    $onLeaveDays++;
                }
            }
            
            // Calculate absent days = working days - present - on leave
            $absentDays = $workingDays - $presentDays - $onLeaveDays;
            if ($absentDays < 0) $absentDays = 0; // Safety check
            
            $lateDays = 0;
            $overtimeDays = 0;
            $totalHours = 0;
            $totalOrdersDelivered = 0;

            foreach ($records as $record) {
                // Calculate hours worked
                if ($record->login_time && $record->logout_time) {
                    $login = strtotime($record->login_time);
                    $logout = strtotime($record->logout_time);
                    $hours = ($logout - $login) / 3600;
                    $totalHours += $hours;
                    $record->hours_worked = round($hours, 1);
                } else {
                    $record->hours_worked = 0;
                }

                // Check if late
                if ($record->login_time && $user->shift_start) {
                    $shiftStart = strtotime($record->attendance_date . ' ' . $user->shift_start);
                    $actualLogin = strtotime($record->attendance_date . ' ' . $record->login_time);
                    if ($actualLogin > $shiftStart) {
                        $lateDays++;
                        $record->late_minutes = round(($actualLogin - $shiftStart) / 60);
                    } else {
                        $record->late_minutes = 0;
                    }
                } else {
                    $record->late_minutes = 0;
                }

                // Check if overtime
                if ($record->logout_time && $user->shift_end) {
                    $shiftEnd = strtotime($record->attendance_date . ' ' . $user->shift_end);
                    $actualLogout = strtotime($record->attendance_date . ' ' . $record->logout_time);
                    if ($actualLogout > $shiftEnd) {
                        $overtimeDays++;
                        $record->overtime_minutes = round(($actualLogout - $shiftEnd) / 60);
                    } else {
                        $record->overtime_minutes = 0;
                    }
                } else {
                    $record->overtime_minutes = 0;
                }

                // Add order count to total
                $totalOrdersDelivered += $record->total_orders_delivered;

                // Format delivery times for display
                $record->first_delivery_time = $record->first_delivery_time ? date('H:i', strtotime($record->first_delivery_time)) : '-';
                $record->last_delivery_time = $record->last_delivery_time ? date('H:i', strtotime($record->last_delivery_time)) : '-';
            }

            return response()->json([
                'success' => true,
                'employee' => [
                    'user_id' => $user->id,
                    'fullname' => $user->fullname,
                    'shift_start' => $user->shift_start,
                    'shift_end' => $user->shift_end,
                    'working_days' => $workingDays,
                    'present_days' => $presentDays,
                    'on_leave_days' => $onLeaveDays,
                    'absent_days' => $absentDays,
                    'late_days' => $lateDays,
                    'overtime_days' => $overtimeDays,
                    'total_hours' => round($totalHours, 1),
                    'total_orders_delivered' => $totalOrdersDelivered,
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate
                    ]
                ],
                'daily_records' => $records
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in employeeDetails: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error loading employee details: ' . $e->getMessage()
            ], 500);
        }
    }
}


