<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ShiftResolutionService;

class AttendanceController extends Controller
{
    // Admin/Manager view
    public function index(Request $request)
    {
        return view('pages.attendance.index');
    }

    // Get all users with their attendance visibility status
    public function getUsersVisibility(Request $request)
    {
        $users = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->select(
                'u.id',
                'u.fullname',
                'r.urole_name as role_name',
                DB::raw('COALESCE(av.is_visible, 1) as is_visible'),
                'av.notes as hide_reason'
            )
            ->orderBy('u.fullname')
            ->get();

        return response()->json(['success' => true, 'data' => $users]);
    }

    // Update user visibility in attendance
    public function updateUserVisibility(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'is_visible' => 'required|boolean',
            'notes' => 'nullable|string|max:500'
        ]);

        $userId = $validated['user_id'];
        $isVisible = $validated['is_visible'];
        $notes = $validated['notes'] ?? null;

        // Check if record exists
        $existing = DB::table('t_ops_attendance_visibility')->where('user_id', $userId)->first();

        if ($existing) {
            // Update existing record
            DB::table('t_ops_attendance_visibility')
                ->where('user_id', $userId)
                ->update([
                    'is_visible' => $isVisible,
                    'notes' => $notes,
                    'hidden_by' => $isVisible ? null : auth()->id(),
                    'hidden_at' => $isVisible ? null : now(),
                    'updated_at' => now()
                ]);
        } else {
            // Insert new record
            DB::table('t_ops_attendance_visibility')->insert([
                'user_id' => $userId,
                'is_visible' => $isVisible,
                'notes' => $notes,
                'hidden_by' => $isVisible ? null : auth()->id(),
                'hidden_at' => $isVisible ? null : now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Visibility updated successfully']);
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
            // Join role information for filtering
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            // Join attendance visibility (default to visible if no record exists)
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            // Join leave subquery matching the selected date
            ->leftJoinSub($leaveSub, 'lr', function($join) use ($selectedDate) {
                $join->on('lr.requester_user_id', '=', 'u.id')
                    ->whereIn('lr.status', ['approved', 'pending'])
                    ->whereRaw('? BETWEEN lr.leave_start_date AND lr.leave_end_date', [$selectedDate]);
            })
            ->select(
                'u.id as user_id',
                'u.fullname',
                'u.is_active',
                'r.urole_name as role_name',
                'a.id as attendance_id',
                'a.attendance_date',
                'a.login_time',
                'a.logout_time',
                'a.notes',
                // Keep legacy shifts for fallback only (will be replaced by ShiftResolutionService)
                DB::raw('COALESCE(rp.shift_start, "09:00") as legacy_shift_start'),
                DB::raw('COALESCE(rp.shift_end, "17:00") as legacy_shift_end'),
                // Leave fields
                'lr.id as leave_request_id',
                'lr.status as leave_status',
                'lr.leave_type as leave_type_from_req',
                // Visibility (default to 1 if no record)
                DB::raw('COALESCE(av.is_visible, 1) as is_attendance_visible')
            )
            // Only show users that are visible in attendance (default to visible if no record)
            ->where(function($q) {
                $q->whereNull('av.is_visible')  // No visibility record = visible
                  ->orWhere('av.is_visible', 1); // Explicitly visible
            })
            ->orderBy('u.fullname');

        // Filter by active/all users (default to active only)
        $activeFilter = $request->input('active_filter', 'active');
        if ($activeFilter === 'active') {
            $query->where('u.is_active', 1);
        }

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
        
        // Resolve actual shifts using ShiftResolutionService
        $shiftService = new ShiftResolutionService();
        foreach ($rows as $row) {
            $shiftData = $shiftService->getUserShift($row->user_id);
            $row->shift_start = $shiftData['shift_start'];
            $row->shift_end = $shiftData['shift_end'];
            $row->shift_name = $shiftData['shift_name'];
            $row->shift_source = $shiftData['source'];
        }
        
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
        try {
        $month = $request->input('month', date('Y-m'));
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        // For current month, only count working days up to today (not the full month)
        $today = date('Y-m-d');
        $isCurrentMonth = (date('Y-m', strtotime($today)) === $month);
        $effectiveEndDate = $isCurrentMonth ? min($today, $endDate) : $endDate;
        
            Log::info('Monthly report requested', [
                'month' => $month,
                'startDate' => $startDate,
                'endDate' => $endDate
            ]);
            
            // Get all users with their attendance and leave data
            $data = DB::table('t_sys_user as u')
                ->leftJoin('t_ops_attendance as a', function($join) use ($startDate, $endDate) {
                    $join->on('u.id', '=', 'a.user_id')
                         ->whereBetween('a.attendance_date', [$startDate, $endDate]);
                })
            ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
                ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
                ->leftJoin('t_req_master as lr', function($join) use ($startDate, $endDate) {
                    $join->on('lr.requester_user_id', '=', 'u.id')
                         ->whereIn('lr.status', ['approved', 'pending'])
                         ->where(function($q) use ($startDate, $endDate) {
                             $q->whereBetween('lr.leave_start_date', [$startDate, $endDate])
                               ->orWhereBetween('lr.leave_end_date', [$startDate, $endDate])
                               ->orWhere(function($q2) use ($startDate, $endDate) {
                                   $q2->where('lr.leave_start_date', '<=', $startDate)
                                      ->where('lr.leave_end_date', '>=', $endDate);
                               });
                         });
                })
                ->leftJoin('t_req_category as lc', 'lc.id', '=', 'lr.category_id')
                ->where(function($query) {
                    $query->where('lc.category_code', '=', 'leave')
                          ->orWhereNull('lc.category_code');
                })
                ->where(function($query) {
                    $query->where('av.is_visible', '=', 1)
                          ->orWhereNull('av.is_visible');
                })
            ->select(
                    'u.id as user_id',
                'u.fullname',
                    'a.id as attendance_id',
                    'a.attendance_date',
                    'a.login_time',
                    'a.logout_time',
                    DB::raw('COALESCE(rp.shift_start, "09:00") as legacy_shift_start'),
                    DB::raw('COALESCE(rp.shift_end, "17:00") as legacy_shift_end'),
                    'lr.id as leave_request_id',
                    'lr.status as leave_status',
                    'lr.leave_start_date',
                    'lr.leave_end_date'
            )
            ->orderBy('u.fullname')
            ->orderBy('a.attendance_date')
            ->get();
            
            Log::info('Monthly report data fetched', ['record_count' => $data->count()]);
            
            // Initialize ShiftResolutionService
            $shiftService = new ShiftResolutionService();
            
        // Group by user and resolve shifts
        $byUser = [];
        foreach ($data as $record) {
            if (!isset($byUser[$record->user_id])) {
                try {
                    // Get user's shift info using today's date (current shift assignment)
                    // This ensures shifts apply retroactively to all past dates
                    $lookupDate = date('Y-m-d');
                    $shiftData = $shiftService->getUserShift($record->user_id, $lookupDate);
                    
                    Log::info('User shift resolved', [
                        'user_id' => $record->user_id,
                        'fullname' => $record->fullname,
                        'shift_data' => $shiftData
                    ]);
                    
                    // Calculate working days for this user in this month
                    // For current month, only count up to today
                    $workingDays = $shiftService->calculateWorkingDays($record->user_id, $startDate, $effectiveEndDate);
                    
                    Log::info('Working days calculated', [
                        'user_id' => $record->user_id,
                        'working_days' => $workingDays
                    ]);
                } catch (\Exception $e) {
                    Log::error('Error resolving shift for user', [
                        'user_id' => $record->user_id,
                        'error' => $e->getMessage()
                    ]);
                    // Fall back to legacy/default values
                    $shiftData = [
                        'shift_start' => $record->legacy_shift_start ?? '09:00',
                        'shift_end' => $record->legacy_shift_end ?? '17:00',
                        'shift_name' => 'Legacy Shift'
                    ];
                    $workingDays = 27;
                }
                
                $byUser[$record->user_id] = [
                    'user_id' => $record->user_id,
                    'fullname' => $record->fullname,
                    'working_days' => $workingDays,
                    'shift_name' => $shiftData['shift_name'],
                    'shift_start' => $shiftData['shift_start'],
                    'shift_end' => $shiftData['shift_end'],
                    'total_days' => 0,
                    'present_days' => 0,
                    'leave_days' => 0,
                    'absent_days' => 0,
                    'late_days' => 0,
                    'overtime_days' => 0,
                    'total_hours' => 0,
                    'total_late_minutes' => 0,
                    'total_overtime_minutes' => 0,
                    'leave_dates' => [],
                    'daily' => [],
                    'processed_attendance_ids' => [] // Track processed attendance records to prevent duplicates
                ];
            }
            
            $userShiftStart = $byUser[$record->user_id]['shift_start'];
            $userShiftEnd = $byUser[$record->user_id]['shift_end'];
            
            // Track leave dates for this user
            if ($record->leave_request_id && $record->leave_start_date && $record->leave_end_date) {
                $leaveStart = new \DateTime($record->leave_start_date);
                $leaveEnd = new \DateTime($record->leave_end_date);
                $current = clone $leaveStart;
                
                while ($current <= $leaveEnd) {
                    $dateStr = $current->format('Y-m-d');
                    // Only count if within the month range
                    if ($dateStr >= $startDate && $dateStr <= $endDate) {
                        $byUser[$record->user_id]['leave_dates'][$dateStr] = true;
                    }
                    $current->modify('+1 day');
                }
            }
            
            // IMPORTANT: Only process each attendance record once to prevent duplicates from JOINs
            // The LEFT JOIN with t_req_master can create duplicate rows for the same attendance record
            $isNewAttendanceRecord = false;
            if ($record->attendance_id && !in_array($record->attendance_id, $byUser[$record->user_id]['processed_attendance_ids'])) {
                $byUser[$record->user_id]['processed_attendance_ids'][] = $record->attendance_id;
                $isNewAttendanceRecord = true;
            }
            
            if ($isNewAttendanceRecord) {
                $byUser[$record->user_id]['total_days']++;
                if ($record->login_time) {
                    $byUser[$record->user_id]['present_days']++;
                    
                    // Calculate late using user's actual shift
                    if ($record->login_time > $userShiftStart) {
                        $byUser[$record->user_id]['late_days']++;
                        $late_mins = (strtotime($record->login_time) - strtotime($userShiftStart)) / 60;
                        $byUser[$record->user_id]['total_late_minutes'] += $late_mins;
                    }
                    
                    // Calculate overtime using user's actual shift
                    if ($record->logout_time && $record->logout_time > $userShiftEnd) {
                        $byUser[$record->user_id]['overtime_days']++;
                        $ot_mins = (strtotime($record->logout_time) - strtotime($userShiftEnd)) / 60;
                        $byUser[$record->user_id]['total_overtime_minutes'] += $ot_mins;
                    }
                    
                    // Calculate hours worked
                    if ($record->logout_time) {
                        $hours = (strtotime($record->logout_time) - strtotime($record->login_time)) / 3600;
                        $byUser[$record->user_id]['total_hours'] += $hours;
                    }
                }
            }
            
            // Add as array for JSON serialization
            // IMPORTANT: Only add records that have actual attendance data
            // Skip NULL attendance_date records (these come from leave request JOINs)
            if ($record->attendance_date !== null) {
                // Determine status for all records
                $status = null; // Default: let frontend determine from login/logout times
                
                // Check if this date is on leave
                if (isset($byUser[$record->user_id]['leave_dates'][$record->attendance_date])) {
                    $status = 'on_leave';
                } elseif (!$record->login_time && !$record->logout_time) {
                    // No attendance and no leave = absent
                    $status = 'absent';
                }
                
                $byUser[$record->user_id]['daily'][] = [
                    'attendance_date' => $record->attendance_date,
                    'login_time' => $record->login_time,
                    'logout_time' => $record->logout_time,
                    'shift_start' => $userShiftStart,
                    'shift_end' => $userShiftEnd,
                    'status' => $status // ✅ Always add status field
                ];
            }
        }
        
        // Calculate leave_days and absent_days for each user
        // Also add absent day records to the daily array for easier tracking
        foreach ($byUser as $userId => &$userData) {
            $userData['leave_days'] = count($userData['leave_dates']);
            $userData['absent_days'] = max(0, $userData['working_days'] - $userData['present_days'] - $userData['leave_days']);
            
            // Create a set of dates that have attendance records
            $attendanceDates = [];
            foreach ($userData['daily'] as $day) {
                $attendanceDates[$day['attendance_date']] = true;
            }
            
            // Add absent/leave day records for dates within the reporting period that have no attendance
            // IMPORTANT: Only add for WORKING DAYS (respects shift off days and public holidays)
            $currentDate = new \DateTime($startDate);
            $endDateObj = new \DateTime($effectiveEndDate);
            
            while ($currentDate <= $endDateObj) {
                $dateStr = $currentDate->format('Y-m-d');
                
                // Skip if attendance record exists
                if (!isset($attendanceDates[$dateStr])) {
                    // CRITICAL: Only add if this is a WORKING DAY for this user
                    // This respects shift schedule (e.g., Tuesday off) AND public holidays
                    if ($shiftService->isWorkingDay($userId, $dateStr)) {
                        // Check if on leave
                        if (isset($userData['leave_dates'][$dateStr])) {
                            // This is a working day on leave = ON LEAVE
                            $userData['daily'][] = [
                                'attendance_date' => $dateStr,
                                'login_time' => null,
                                'logout_time' => null,
                                'shift_start' => $userData['shift_start'],
                                'shift_end' => $userData['shift_end'],
                                'status' => 'on_leave' // ✅ Mark as on leave
                            ];
                        } else {
                            // This is a working day with no attendance = ABSENT
                            $userData['daily'][] = [
                                'attendance_date' => $dateStr,
                                'login_time' => null,
                                'logout_time' => null,
                                'shift_start' => $userData['shift_start'],
                                'shift_end' => $userData['shift_end'],
                                'status' => 'absent' // Mark as absent for frontend rendering
                            ];
                        }
                    }
                    // else: it's a day off or holiday, don't show in the report
                }
                
                $currentDate->modify('+1 day');
            }
            
            // Sort daily records by date for proper chronological display
            usort($userData['daily'], function($a, $b) {
                return strcmp($a['attendance_date'], $b['attendance_date']);
            });
            
            unset($userData['leave_dates']); // Remove temporary data
            unset($userData['processed_attendance_ids']); // Remove temporary tracking array
        }
        
        Log::info('Monthly report processed', [
            'user_count' => count($byUser),
            'sample_user' => array_values($byUser)[0] ?? null
        ]);
        
        return response()->json(['success' => true, 'data' => array_values($byUser), 'month' => $month]);
        
        } catch (\Exception $e) {
            Log::error('Monthly report error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
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
                ->select('u.id', 'u.fullname')
                ->where('u.id', $userId)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get shift info using ShiftResolutionService
            $shiftService = new ShiftResolutionService();
            $shiftInfo = $shiftService->getUserShift($userId, $fromDate);
            
            // Add shift times to user object for backward compatibility
            $user->shift_start = $shiftInfo['shift_start'];
            $user->shift_end = $shiftInfo['shift_end'];

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
                    DB::raw("COALESCE(d.first_delivery_time, '-') as first_delivery_time"),
                    DB::raw("COALESCE(d.last_delivery_time, '-') as last_delivery_time")
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
            
            // Clean up any null values in the records immediately after fetch
            foreach ($records as $record) {
                if ($record->first_delivery_time === null) {
                    $record->first_delivery_time = '-';
                }
                if ($record->last_delivery_time === null) {
                    $record->last_delivery_time = '-';
                }
            }
            
            // Log results for debugging
            Log::info('Employee details results', [
                'user_id' => $userId,
                'record_count' => $records->count(),
                'first_3_records' => $records->take(3)->toArray(),
                'total_orders_sum' => $records->sum('total_orders_delivered')
            ]);

            // Calculate working days using ShiftResolutionService
            // This considers user's shift schedule AND public holidays
            $shiftService = new ShiftResolutionService();
            $workingDays = $shiftService->calculateWorkingDays($userId, $startDate, $endDate);

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

                // Format delivery times for display (handle null values)
                if ($record->first_delivery_time && $record->first_delivery_time !== '-') {
                    try {
                        $record->first_delivery_time = date('H:i', strtotime($record->first_delivery_time));
                    } catch (\Exception $e) {
                        $record->first_delivery_time = '-';
                    }
                } else {
                    $record->first_delivery_time = '-';
                }
                
                if ($record->last_delivery_time && $record->last_delivery_time !== '-') {
                    try {
                        $record->last_delivery_time = date('H:i', strtotime($record->last_delivery_time));
                    } catch (\Exception $e) {
                        $record->last_delivery_time = '-';
                    }
                } else {
                    $record->last_delivery_time = '-';
                }
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
                    'shift_info' => [
                        'shift_name' => $shiftInfo['shift_name'],
                        'shift_source' => $shiftInfo['source'],
                        'working_days_per_week' => count($shiftInfo['working_days'])
                    ],
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


