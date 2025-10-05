<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ops\ShiftTemplateModel;
use App\Models\Ops\UserShiftAssignmentModel;
use App\Services\ShiftResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShiftController extends Controller
{
    protected $shiftService;

    public function __construct()
    {
        $this->shiftService = new ShiftResolutionService();
    }

    /**
     * Display shift templates management page
     */
    public function index()
    {
        return view('pages.shifts.index');
    }

    /**
     * Clear all shift caches (for debugging/testing)
     */
    public function clearCache()
    {
        $this->shiftService->clearAllShiftCaches();
        return response()->json(['success' => true, 'message' => 'All shift caches cleared']);
    }

    /**
     * Get all shift templates
     */
    public function list(Request $request)
    {
        $shifts = ShiftTemplateModel::with(['userAssignments'])
            ->orderBy('is_default', 'desc')
            ->orderBy('shift_name')
            ->get()
            ->map(function($shift) {
                return [
                    'id' => $shift->id,
                    'shift_name' => $shift->shift_name,
                    'shift_code' => $shift->shift_code,
                    'shift_start' => substr($shift->shift_start, 0, 5),
                    'shift_end' => substr($shift->shift_end, 0, 5),
                    'working_days' => $shift->working_days,
                    'working_days_count' => $shift->getWorkingDaysCount(),
                    'off_days' => $shift->getOffDaysString(),
                    'is_default' => $shift->is_default,
                    'active' => $shift->active,
                    'description' => $shift->description,
                    'assigned_users_count' => $shift->userAssignments()->count()
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $shifts
        ]);
    }

    /**
     * Store a new shift template
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shift_name' => 'required|string|max:100',
            'shift_code' => 'required|string|max:50|unique:t_ops_shift_template,shift_code',
            'shift_start' => 'required|date_format:H:i',
            'shift_end' => 'required|date_format:H:i',
            'working_days' => 'required|array|min:1',
            'working_days.*' => 'integer|between:1,7',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $shift = ShiftTemplateModel::create([
                'shift_name' => $request->shift_name,
                'shift_code' => $request->shift_code,
                'shift_start' => $request->shift_start . ':00',
                'shift_end' => $request->shift_end . ':00',
                'working_days' => $request->working_days,
                'is_default' => false, // New shifts are not default by default
                'description' => $request->description,
                'active' => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);

            // Clear cache
            $this->shiftService->clearAllShiftCaches();

            return response()->json([
                'success' => true,
                'message' => 'Shift template created successfully',
                'data' => $shift
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a shift template
     */
    public function update(Request $request, $id)
    {
        $shift = ShiftTemplateModel::find($id);
        
        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'Shift template not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'shift_name' => 'required|string|max:100',
            'shift_code' => 'required|string|max:50|unique:t_ops_shift_template,shift_code,' . $id,
            'shift_start' => 'required|date_format:H:i',
            'shift_end' => 'required|date_format:H:i',
            'working_days' => 'required|array|min:1',
            'working_days.*' => 'integer|between:1,7',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $shift->update([
                'shift_name' => $request->shift_name,
                'shift_code' => $request->shift_code,
                'shift_start' => $request->shift_start . ':00',
                'shift_end' => $request->shift_end . ':00',
                'working_days' => $request->working_days,
                'description' => $request->description,
                'updated_by' => auth()->id()
            ]);

            // Clear cache
            $this->shiftService->clearAllShiftCaches();

            return response()->json([
                'success' => true,
                'message' => 'Shift template updated successfully',
                'data' => $shift
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a shift template
     */
    public function destroy($id)
    {
        $shift = ShiftTemplateModel::find($id);
        
        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'Shift template not found'
            ], 404);
        }

        // Check if shift has users assigned
        $assignedCount = $shift->userAssignments()->count();
        if ($assignedCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete shift. It is assigned to {$assignedCount} user(s). Please reassign them first."
            ], 400);
        }

        // Don't allow deleting the default shift
        if ($shift->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the default shift. Set another shift as default first.'
            ], 400);
        }

        try {
            $shift->delete();

            // Clear cache
            $this->shiftService->clearAllShiftCaches();

            return response()->json([
                'success' => true,
                'message' => 'Shift template deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set a shift as default
     */
    public function setDefault($id)
    {
        $shift = ShiftTemplateModel::find($id);
        
        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'Shift template not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Unset all other defaults
            ShiftTemplateModel::where('is_default', 1)->update(['is_default' => 0]);

            // Set this shift as default
            $shift->update(['is_default' => 1]);

            DB::commit();

            // Clear cache
            $this->shiftService->clearAllShiftCaches();

            return response()->json([
                'success' => true,
                'message' => "'{$shift->shift_name}' is now the default shift"
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error setting default shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all users with their current shift assignments
     */
    public function getUsersWithShifts(Request $request)
    {
        try {
            // Get ALL users (no filtering - your table structure doesn't have status/deleted_at)
            $users = DB::table('t_sys_user as u')
                ->leftJoin('t_ops_user_shift_assignment as usa', 'usa.user_id', '=', 'u.id')
                ->leftJoin('t_ops_shift_template as st', 'st.id', '=', 'usa.shift_template_id')
                ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
                ->select(
                    'u.id as user_id',
                    'u.fullname',
                    'st.id as assigned_shift_id',
                    'st.shift_name as assigned_shift_name',
                    'st.shift_start as assigned_shift_start',
                    'st.shift_end as assigned_shift_end',
                    'rp.shift_start as legacy_shift_start',
                    'rp.shift_end as legacy_shift_end',
                    'rp.migrated_to_shift_system'
                )
                ->orderBy('u.fullname')
                ->get();

            // Get default shift for fallback
            $defaultShift = ShiftTemplateModel::where('is_default', 1)->first();
            
            $usersWithShifts = $users->map(function($user) use ($defaultShift) {
                // Determine current shift
                $currentShiftName = '';
                $currentShiftSource = '';
                $shiftStart = '09:00';
                $shiftEnd = '17:00';
                
                if ($user->assigned_shift_name) {
                    // Has explicit assignment
                    $currentShiftName = $user->assigned_shift_name;
                    $currentShiftSource = 'user_assignment';
                    $shiftStart = substr($user->assigned_shift_start ?? '09:00', 0, 5);
                    $shiftEnd = substr($user->assigned_shift_end ?? '17:00', 0, 5);
                } elseif ($user->legacy_shift_start && $user->legacy_shift_end) {
                    // Has legacy shift from rider profile
                    $currentShiftName = 'Legacy Shift';
                    $currentShiftSource = 'legacy_rider_profile';
                    $shiftStart = substr($user->legacy_shift_start, 0, 5);
                    $shiftEnd = substr($user->legacy_shift_end, 0, 5);
                } elseif ($defaultShift) {
                    // Use default shift
                    $currentShiftName = $defaultShift->shift_name;
                    $currentShiftSource = 'default_shift';
                    $shiftStart = substr($defaultShift->shift_start, 0, 5);
                    $shiftEnd = substr($defaultShift->shift_end, 0, 5);
                } else {
                    // Hardcoded fallback
                    $currentShiftName = 'System Default';
                    $currentShiftSource = 'hardcoded_fallback';
                }
                
                return [
                    'user_id' => (int)$user->user_id,
                    'fullname' => $user->fullname,
                    'assigned_shift_id' => $user->assigned_shift_id ? (int)$user->assigned_shift_id : null,
                    'assigned_shift_name' => $user->assigned_shift_name,
                    'current_shift_name' => $currentShiftName,
                    'current_shift_source' => $currentShiftSource,
                    'shift_start' => $shiftStart,
                    'shift_end' => $shiftEnd,
                    'has_legacy_shift' => $user->legacy_shift_start ? true : false,
                    'is_migrated' => $user->migrated_to_shift_system == 1
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $usersWithShifts->values()->toArray()
            ]);
        } catch (\Exception $e) {
            \Log::error('getUsersWithShifts error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign shift to single user
     */
    public function assignShiftToUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:t_sys_user,id',
            'shift_template_id' => 'required|exists:t_ops_shift_template,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            // Delete existing assignment
            UserShiftAssignmentModel::where('user_id', $request->user_id)->delete();

            // Create new assignment
            UserShiftAssignmentModel::create([
                'user_id' => $request->user_id,
                'shift_template_id' => $request->shift_template_id,
                'effective_from' => now()->format('Y-m-d'),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);

            // Clear cache for this user
            $this->shiftService->clearUserShiftCache($request->user_id);

            return response()->json([
                'success' => true,
                'message' => 'Shift assigned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error assigning shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk assign shift to multiple users
     */
    public function bulkAssignShift(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:t_sys_user,id',
            'shift_template_id' => 'required|exists:t_ops_shift_template,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $userIds = $request->user_ids;
            $shiftTemplateId = $request->shift_template_id;

            // Delete existing assignments for these users
            UserShiftAssignmentModel::whereIn('user_id', $userIds)->delete();

            // Create new assignments
            $assignments = [];
            foreach ($userIds as $userId) {
                $assignments[] = [
                    'user_id' => $userId,
                    'shift_template_id' => $shiftTemplateId,
                    'effective_from' => now()->format('Y-m-d'),
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            UserShiftAssignmentModel::insert($assignments);

            DB::commit();

            // Clear cache for all affected users
            foreach ($userIds as $userId) {
                $this->shiftService->clearUserShiftCache($userId);
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift assigned to ' . count($userIds) . ' user(s) successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error bulk assigning shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove shift assignment from user (will fall back to legacy or default)
     */
    public function removeShiftAssignment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:t_sys_user,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            UserShiftAssignmentModel::where('user_id', $request->user_id)->delete();

            // Clear cache
            $this->shiftService->clearUserShiftCache($request->user_id);

            return response()->json([
                'success' => true,
                'message' => 'Shift assignment removed. User will use legacy shift or default.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error removing shift assignment: ' . $e->getMessage()
            ], 500);
        }
    }
}

