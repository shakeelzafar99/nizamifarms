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
                // Meter readings
                'a.meter_start',
                'a.meter_end',
                // Location tracking fields
                'a.checkin_latitude',
                'a.checkin_longitude',
                'a.checkin_distance_from_base',
                'a.is_remote_checkin',
                'a.checkout_latitude',
                'a.checkout_longitude',
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
        $locationService = new \App\Services\LocationService();
        
        // Collect user IDs for batch GPS query
        $userIdsWithAttendance = [];
        
        foreach ($rows as $row) {
            $shiftData = $shiftService->getUserShift($row->user_id);
            $row->shift_start = $shiftData['shift_start'];
            $row->shift_end = $shiftData['shift_end'];
            $row->shift_name = $shiftData['shift_name'];
            $row->shift_source = $shiftData['source'];
            
            // ⭐ Calculate meter distance
            $row->meter_distance = null;
            if ($row->meter_start && $row->meter_end) {
                $row->meter_distance = abs((int) $row->meter_end - (int) $row->meter_start);
            }
            
            // ⭐ Initialize GPS distance fields
            $row->gps_distance = null;
            $row->gps_readings_count = 0;
            $row->gps_coverage_percent = null;
            
            // Track users with attendance for GPS batch query
            if ($row->login_time) {
                $userIdsWithAttendance[] = $row->user_id;
            }
            
            // Recalculate distance based on user's current assigned location
            if ($row->checkin_latitude && $row->checkin_longitude) {
                $distanceResult = $locationService->calculateDistanceFromBase(
                    $row->checkin_latitude,
                    $row->checkin_longitude,
                    $row->user_id
                );
                
                // Update with recalculated values
                $row->checkin_distance_from_base = $distanceResult['distance_meters'];
                $row->is_remote_checkin = $distanceResult['is_remote'] ? 1 : 0;
                $row->assigned_office_location = $distanceResult['base_location']->location_name ?? null;
            }
        }
        
        // ⭐ Batch calculate GPS distance for users with attendance
        if (!empty($userIdsWithAttendance)) {
            $allGpsReadings = DB::table('t_ops_rider_location')
                ->whereIn('user_id', $userIdsWithAttendance)
                ->whereDate('captured_at', $selectedDate)
                ->where('accuracy', '<=', 100) // Skip inaccurate readings
                ->orderBy('user_id')
                ->orderBy('captured_at')
                ->select('user_id', 'latitude', 'longitude', 'accuracy', 'captured_at')
                ->get()
                ->groupBy('user_id');
            
            foreach ($rows as $row) {
                if (isset($allGpsReadings[$row->user_id])) {
                    $readings = $allGpsReadings[$row->user_id]->values()->all();
                    $gpsResult = $this->calculateGpsDistanceFromReadings($readings);
                    $row->gps_distance = $gpsResult['distance'];
                    $row->gps_readings_count = $gpsResult['readings_count'];
                    
                    // ⭐ Calculate GPS coverage percentage
                    if ($row->login_time && $row->logout_time) {
                        $loginTime = strtotime($selectedDate . ' ' . $row->login_time);
                        $logoutTime = strtotime($selectedDate . ' ' . $row->logout_time);
                        $workingMinutes = ($logoutTime - $loginTime) / 60;
                        $expectedReadings = max(1, floor($workingMinutes / 5)); // One reading every 5 min
                        $row->gps_coverage_percent = min(100, round(($gpsResult['readings_count'] / $expectedReadings) * 100));
                    }
                }
            }
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
                    'a.picture_start',
                    'a.picture_end',
                    'a.meter_start',
                    'a.meter_end',
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
                    'picture_start' => $record->picture_start,
                    'picture_end' => $record->picture_end,
                    'meter_start' => $record->meter_start,
                    'meter_end' => $record->meter_end,
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
                    'a.picture_start',
                    'a.picture_end',
                    'a.meter_start',
                    'a.meter_end',
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
                
                // Calculate meter distance
                if ($record->meter_start && $record->meter_end) {
                    $record->meter_distance = abs(intval($record->meter_end) - intval($record->meter_start));
                } else {
                    $record->meter_distance = null;
                }

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
    
    /**
     * ⭐ GPS Readings Audit - Identify gaps in GPS tracking
     * Helps audit riders who may have gaps in their tracking
     */
    public function gpsReadingsAudit(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            $date = $request->input('date', now()->toDateString());
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id is required'
                ], 400);
            }
            
            // Get user info
            $user = DB::table('t_sys_user')
                ->select('id', 'fullname')
                ->where('id', $userId)
                ->first();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            // Get attendance for the date
            $attendance = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', $date)
                ->first();
            
            if (!$attendance || !$attendance->login_time) {
                return response()->json([
                    'success' => true,
                    'user' => $user,
                    'date' => $date,
                    'has_attendance' => false,
                    'message' => 'No attendance record for this date'
                ]);
            }
            
            // Get all GPS readings for the date (ordered by time)
            $readings = DB::table('t_ops_rider_location')
                ->where('user_id', $userId)
                ->whereDate('captured_at', $date)
                ->orderBy('captured_at')
                ->select('id', 'latitude', 'longitude', 'accuracy', 'captured_at')
                ->get();
            
            // Calculate expected readings based on 5-min intervals
            $loginTime = strtotime($date . ' ' . $attendance->login_time);
            $logoutTime = $attendance->logout_time ? strtotime($date . ' ' . $attendance->logout_time) : time();
            
            // If logout is not yet, cap at current time
            if ($logoutTime > time()) {
                $logoutTime = time();
            }
            
            $workingMinutes = ($logoutTime - $loginTime) / 60;
            $expectedReadings = max(1, floor($workingMinutes / 5)); // One reading every 5 min
            $actualReadings = $readings->count();
            
            // Analyze gaps - find periods > 10 minutes between readings
            $gaps = [];
            $totalGapMinutes = 0;
            $minGapThreshold = 10; // Minutes - gaps larger than this are flagged
            
            // Helper function to check if two points are at same location (within 50m)
            $isSameLocation = function($lat1, $lng1, $lat2, $lng2) {
                $earthRadius = 6371000;
                $lat1Rad = deg2rad($lat1);
                $lat2Rad = deg2rad($lat2);
                $deltaLat = deg2rad($lat2 - $lat1);
                $deltaLng = deg2rad($lng2 - $lng1);
                $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
                     cos($lat1Rad) * cos($lat2Rad) *
                     sin($deltaLng / 2) * sin($deltaLng / 2);
                $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                return ($earthRadius * $c) < 50; // Within 50 meters = same location
            };
            
            $stationaryGapMinutes = 0; // Gaps where rider didn't move (harmless)
            
            if ($actualReadings > 0) {
                // Check gap from login to first reading
                $firstReading = $readings->first();
                $firstReadingTime = strtotime($firstReading->captured_at);
                $gapFromLogin = ($firstReadingTime - $loginTime) / 60;
                
                if ($gapFromLogin > $minGapThreshold) {
                    $gaps[] = [
                        'from' => date('H:i:s', $loginTime),
                        'to' => date('H:i:s', $firstReadingTime),
                        'duration_minutes' => round($gapFromLogin),
                        'type' => 'start_delay',
                        'description' => 'Gap between login and first GPS reading',
                        'is_stationary' => false // Unknown - no prior reading to compare
                    ];
                    $totalGapMinutes += $gapFromLogin;
                }
                
                // Check gaps between consecutive readings
                $readingsArray = $readings->values()->all();
                for ($i = 1; $i < count($readingsArray); $i++) {
                    $prev = $readingsArray[$i - 1];
                    $curr = $readingsArray[$i];
                    $prevTime = strtotime($prev->captured_at);
                    $currTime = strtotime($curr->captured_at);
                    $gapMinutes = ($currTime - $prevTime) / 60;
                    
                    if ($gapMinutes > $minGapThreshold) {
                        // ⭐ Smart check: if rider didn't move during gap, it's harmless
                        $isStationary = $isSameLocation(
                            (float) $prev->latitude, (float) $prev->longitude,
                            (float) $curr->latitude, (float) $curr->longitude
                        );
                        
                        $gaps[] = [
                            'from' => date('H:i:s', $prevTime),
                            'to' => date('H:i:s', $currTime),
                            'duration_minutes' => round($gapMinutes),
                            'type' => $isStationary ? 'stationary_gap' : 'tracking_gap',
                            'description' => $isStationary 
                                ? 'GPS gap but rider stayed in same location (no loss)' 
                                : 'GPS tracking gap',
                            'is_stationary' => $isStationary
                        ];
                        
                        $totalGapMinutes += $gapMinutes;
                        if ($isStationary) {
                            $stationaryGapMinutes += $gapMinutes;
                        }
                    }
                }
                
                // Check gap from last reading to logout
                if ($attendance->logout_time) {
                    $lastReading = $readings->last();
                    $lastReadingTime = strtotime($lastReading->captured_at);
                    $gapToLogout = ($logoutTime - $lastReadingTime) / 60;
                    
                    if ($gapToLogout > $minGapThreshold) {
                        $gaps[] = [
                            'from' => date('H:i:s', $lastReadingTime),
                            'to' => date('H:i:s', $logoutTime),
                            'duration_minutes' => round($gapToLogout),
                            'type' => 'end_gap',
                            'description' => 'Gap between last GPS reading and logout',
                            'is_stationary' => false // Unknown - no next reading to compare
                        ];
                        $totalGapMinutes += $gapToLogout;
                    }
                }
            } else {
                // No readings at all
                $gaps[] = [
                    'from' => $attendance->login_time,
                    'to' => $attendance->logout_time ?? 'ongoing',
                    'duration_minutes' => round($workingMinutes),
                    'type' => 'no_tracking',
                    'description' => 'No GPS readings captured',
                    'is_stationary' => false
                ];
                $totalGapMinutes = $workingMinutes;
            }
            
            // ⭐ Effective gap = only gaps where we might have lost distance data
            $effectiveGapMinutes = $totalGapMinutes - $stationaryGapMinutes;
            
            // Calculate coverage percentage (based on readings, not gaps)
            $coveragePercent = $workingMinutes > 0 
                ? round((($workingMinutes - $totalGapMinutes) / $workingMinutes) * 100) 
                : 0;
            
            // ⭐ Effective coverage = coverage when excluding harmless stationary gaps
            $effectiveCoveragePercent = $workingMinutes > 0 
                ? round((($workingMinutes - $effectiveGapMinutes) / $workingMinutes) * 100) 
                : 0;
            
            // Calculate GPS straight-line distance
            $gpsDistance = null;
            if ($actualReadings >= 2) {
                $gpsResult = $this->calculateGpsDistanceFromReadings($readings->toArray());
                $gpsDistance = $gpsResult['distance'];
            }
            
            // ⭐ Calculate ROAD distance using OpenRouteService API (same as mobile)
            // BUT only if straight-line distance is meaningful (> 0.5 km)
            // This prevents API calls for stationary GPS that just has drift
            $roadDistance = null;
            $roadDistanceSource = null;
            if ($actualReadings >= 2 && $gpsDistance !== null && $gpsDistance >= 0.5) {
                // Sample readings to max 25 points with GPS drift filtering
                $sampledReadings = $this->sampleGpsReadings($readings->toArray(), 25);
                if (count($sampledReadings) >= 2) {
                    $roadDistance = $this->callOpenRouteService($sampledReadings);
                    $roadDistanceSource = $roadDistance !== null ? 'openrouteservice' : 'unavailable';
                }
            } elseif ($gpsDistance !== null && $gpsDistance < 0.5) {
                $roadDistanceSource = 'skipped_stationary';
            }
            
            // Meter distance from attendance (with raw values for transparency)
            $meterDistance = null;
            $meterStart = $attendance->meter_start;
            $meterEnd = $attendance->meter_end;
            if ($meterStart && $meterEnd) {
                $meterDistance = abs((int) $meterEnd - (int) $meterStart);
            }
            
            // ⭐ Get previous day's meter end for gap detection
            $prevMeter = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', '<', $date)
                ->whereNotNull('meter_end')
                ->orderBy('attendance_date', 'desc')
                ->select('meter_end', 'attendance_date')
                ->first();
            
            $prevMeterEnd = $prevMeter->meter_end ?? null;
            $prevMeterDate = $prevMeter->attendance_date ?? null;
            $meterGap = null;
            if ($prevMeterEnd && $meterStart) {
                $meterGap = (int)$meterStart - (int)$prevMeterEnd;
            }
            
            // Audit status
            $auditStatus = 'good';
            $auditNotes = [];
            
            if ($coveragePercent < 50) {
                $auditStatus = 'critical';
                $auditNotes[] = 'Less than 50% GPS coverage';
            } elseif ($coveragePercent < 80) {
                $auditStatus = 'warning';
                $auditNotes[] = 'GPS coverage below 80%';
            }
            
            if (count($gaps) > 3) {
                if ($auditStatus === 'good') $auditStatus = 'warning';
                $auditNotes[] = 'Multiple tracking gaps detected';
            }
            
            // Compare meter vs road/GPS distance if available
            $comparisonDistance = $roadDistance ?? $gpsDistance;
            if ($meterDistance && $comparisonDistance && $comparisonDistance > 0) {
                $discrepancyPercent = abs($meterDistance - $comparisonDistance) / $comparisonDistance * 100;
                if ($discrepancyPercent > 50) {
                    if ($auditStatus === 'good') $auditStatus = 'warning';
                    $auditNotes[] = 'Significant difference between meter and GPS distance';
                }
            }
            
            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'fullname' => $user->fullname
                ],
                'date' => $date,
                'has_attendance' => true,
                'attendance' => [
                    'login_time' => $attendance->login_time,
                    'logout_time' => $attendance->logout_time,
                    'working_minutes' => round($workingMinutes)
                ],
                'gps_analysis' => [
                    'expected_readings' => $expectedReadings,
                    'actual_readings' => $actualReadings,
                    'coverage_percent' => $coveragePercent,
                    'effective_coverage_percent' => $effectiveCoveragePercent,
                    'total_gap_minutes' => round($totalGapMinutes),
                    'stationary_gap_minutes' => round($stationaryGapMinutes),
                    'effective_gap_minutes' => round($effectiveGapMinutes),
                    'gaps_count' => count($gaps),
                    'stationary_gaps_count' => count(array_filter($gaps, fn($g) => $g['is_stationary'] ?? false)),
                    'gaps' => $gaps
                ],
                'distance' => [
                    'meter_km' => $meterDistance,
                    'meter_start' => $meterStart,
                    'meter_end' => $meterEnd,
                    'prev_meter_end' => $prevMeterEnd,
                    'prev_meter_date' => $prevMeterDate,
                    'meter_gap' => $meterGap,
                    'gps_straight_km' => $gpsDistance,
                    'gps_road_km' => $roadDistance !== null ? round($roadDistance, 1) : null,
                    'road_source' => $roadDistanceSource
                ],
                'audit' => [
                    'status' => $auditStatus,
                    'notes' => $auditNotes
                ],
                // Include readings for timeline visualization (limit to avoid huge response)
                'readings_preview' => $readings->take(100)->map(function($r) {
                    return [
                        'time' => date('H:i', strtotime($r->captured_at)),
                        'accuracy' => $r->accuracy
                    ];
                })
            ]);
            
        } catch (\Exception $e) {
            Log::error('GPS Audit Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error performing GPS audit: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Calculate GPS distance from array of readings using Haversine
     * Filters out GPS drift (< 20m movements)
     */
    private function calculateGpsDistanceFromReadings(array $readings): array
    {
        $totalDistance = 0;
        $validReadingsCount = count($readings);
        
        if ($validReadingsCount < 2) {
            return ['distance' => null, 'readings_count' => $validReadingsCount];
        }
        
        $minMovementMeters = 20; // Filter out GPS drift
        
        for ($i = 1; $i < $validReadingsCount; $i++) {
            $prev = is_array($readings[$i - 1]) ? (object) $readings[$i - 1] : $readings[$i - 1];
            $curr = is_array($readings[$i]) ? (object) $readings[$i] : $readings[$i];
            
            $distanceMeters = $this->haversineDistance(
                (float) $prev->latitude,
                (float) $prev->longitude,
                (float) $curr->latitude,
                (float) $curr->longitude
            );
            
            if ($distanceMeters >= $minMovementMeters) {
                $totalDistance += $distanceMeters;
            }
        }
        
        $distanceKm = round($totalDistance / 1000, 1);
        
        return [
            'distance' => $distanceKm > 0 ? $distanceKm : null,
            'readings_count' => $validReadingsCount,
        ];
    }
    
    /**
     * ⭐ Haversine formula to calculate distance between two lat/lng points
     * Returns distance in meters
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000;
        
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);
        
        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLng / 2) * sin($deltaLng / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadiusMeters * $c;
    }
    
    /**
     * ⭐ Sample GPS readings for road distance API call
     * 
     * Strategy:
     * 1. Filter out GPS DRIFT - consecutive readings within ~30m are noise
     * 2. Then sample evenly from filtered points to get max 50 waypoints
     * 
     * This removes fake distance from GPS drift while preserving actual route
     */
    private function sampleGpsReadings(array $readings, int $maxPoints = 25): array
    {
        $count = count($readings);
        
        if ($count <= 2) {
            return $readings;
        }
        
        // ⭐ Step 1: Filter GPS drift - consecutive points within ~30m
        $filtered = [];
        $prevLat = null;
        $prevLng = null;
        $driftThreshold = 0.0003; // ~30m
        
        foreach ($readings as $reading) {
            $lat = (float)(is_object($reading) ? $reading->latitude : ($reading['latitude'] ?? 0));
            $lng = (float)(is_object($reading) ? $reading->longitude : ($reading['longitude'] ?? 0));
            
            if ($prevLat === null || 
                abs($lat - $prevLat) > $driftThreshold || 
                abs($lng - $prevLng) > $driftThreshold) {
                $filtered[] = $reading;
                $prevLat = $lat;
                $prevLng = $lng;
            }
        }
        
        $filteredCount = count($filtered);
        
        if ($filteredCount < 2) {
            $filtered = $readings;
            $filteredCount = $count;
        }
        
        if ($filteredCount <= $maxPoints) {
            return $filtered;
        }
        
        // ⭐ Step 2: Sample evenly
        $sampled = [];
        $step = ($filteredCount - 1) / ($maxPoints - 1);
        
        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int) round($i * $step);
            if ($index < $filteredCount) {
                $sampled[] = $filtered[$index];
            }
        }
        
        // Ensure first/last included
        $first = $filtered[0];
        $last = $filtered[$filteredCount - 1];
        
        $firstLat = (float)(is_object($first) ? $first->latitude : ($first['latitude'] ?? 0));
        $firstLng = (float)(is_object($first) ? $first->longitude : ($first['longitude'] ?? 0));
        $sFirst = $sampled[0];
        $sFirstLat = (float)(is_object($sFirst) ? $sFirst->latitude : ($sFirst['latitude'] ?? 0));
        $sFirstLng = (float)(is_object($sFirst) ? $sFirst->longitude : ($sFirst['longitude'] ?? 0));
        
        if (abs($firstLat - $sFirstLat) > 0.00001 || abs($firstLng - $sFirstLng) > 0.00001) {
            array_unshift($sampled, $first);
        }
        
        $lastLat = (float)(is_object($last) ? $last->latitude : ($last['latitude'] ?? 0));
        $lastLng = (float)(is_object($last) ? $last->longitude : ($last['longitude'] ?? 0));
        $sLast = end($sampled);
        $sLastLat = (float)(is_object($sLast) ? $sLast->latitude : ($sLast['latitude'] ?? 0));
        $sLastLng = (float)(is_object($sLast) ? $sLast->longitude : ($sLast['longitude'] ?? 0));
        
        if (abs($lastLat - $sLastLat) > 0.00001 || abs($lastLng - $sLastLng) > 0.00001) {
            $sampled[] = $last;
        }
        
        return $sampled;
    }
    
    /**
     * ⭐ Call OpenRouteService Directions API for road distance
     * Same API used in mobile app - Free tier: 2,000 requests/day
     */
    private function callOpenRouteService(array $readings): ?float
    {
        // OpenRouteService API key
        $apiKey = env('OPENROUTESERVICE_API_KEY', '5b3ce3597851110001cf62487c37b3c0b8d74b9fb9f7d9f3c3d7f8e9');
        
        // Build coordinates array [lng, lat] format (GeoJSON standard)
        $coordinates = array_map(function($reading) {
            return [
                (float) (is_object($reading) ? $reading->longitude : ($reading['longitude'] ?? 0)),
                (float) (is_object($reading) ? $reading->latitude : ($reading['latitude'] ?? 0))
            ];
        }, $readings);
        
        // ⭐ Debug logging
        Log::info('OpenRouteService: Attempting API call', [
            'coordinates_count' => count($coordinates),
            'first_coord' => $coordinates[0] ?? null,
            'last_coord' => $coordinates[count($coordinates) - 1] ?? null,
        ]);
        
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);
            
            $response = $client->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'coordinates' => $coordinates,
                    'instructions' => false,
                    'geometry' => false,
                    'units' => 'km',
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (isset($data['routes'][0]['summary']['distance'])) {
                $distance = $data['routes'][0]['summary']['distance'];
                Log::info('OpenRouteService: Success', ['distance_km' => $distance]);
                return $distance; // Already in km
            }
            
            Log::warning('OpenRouteService response missing distance', ['data' => $data]);
            return null;
            
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response';
            Log::warning('OpenRouteService API client error (4xx)', [
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'body' => $responseBody,
                'coordinates_count' => count($coordinates)
            ]);
            return null;
            
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response';
            Log::warning('OpenRouteService API server error (5xx)', [
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'body' => $responseBody,
                'coordinates_count' => count($coordinates)
            ]);
            return null;
            
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::warning('OpenRouteService API connection error', [
                'error' => $e->getMessage(),
                'coordinates_count' => count($coordinates)
            ]);
            return null;
            
        } catch (\Exception $e) {
            Log::warning('OpenRouteService API error', [
                'error' => $e->getMessage(),
                'coordinates_count' => count($coordinates)
            ]);
            return null;
        }
    }
}
