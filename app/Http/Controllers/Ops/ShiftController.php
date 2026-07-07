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
                    'assigned_users_count' => $shift->currentUserAssignments()->count()
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

        // Block deletion when the template has ANY assignment history (open OR closed).
        // The FK is ON DELETE CASCADE, so deleting the template would silently erase the
        // kept history rows and change past attendance/salary reports. Deactivate instead.
        $historyCount = $shift->userAssignments()->count();
        if ($historyCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "This shift has assignment history ({$historyCount} record(s)) and cannot be deleted — past reports would change. Deactivate it instead."
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
     * Activate / deactivate a shift template — the safe alternative to deleting a
     * template that has history. Resolution still honors an inactive template for its
     * existing assignments (history stays intact); `active` only controls whether the
     * shift is offered for NEW assignments in the pickers.
     */
    public function setActive(Request $request, $id)
    {
        $shift = ShiftTemplateModel::find($id);
        if (!$shift) {
            return response()->json(['success' => false, 'message' => 'Shift template not found'], 404);
        }

        $active = filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN);

        if (!$active && $shift->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate the default shift. Set another shift as default first.'
            ], 400);
        }

        $shift->update(['active' => $active, 'updated_by' => auth()->id()]);
        $this->shiftService->clearAllShiftCaches();

        return response()->json([
            'success' => true,
            'message' => $active ? 'Shift activated.' : 'Shift deactivated (history kept).'
        ]);
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
                // Only the CURRENT (open) assignment. Without this, a user with shift
                // history would appear once per historical row now that we keep history.
                ->leftJoin('t_ops_user_shift_assignment as usa', function ($j) {
                    $j->on('usa.user_id', '=', 'u.id')
                      ->whereNull('usa.effective_to');
                })
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
     * Resolve an assignment mode into [effectiveTo, isTemporary].
     *  - until_changed → sets/replaces the PRIMARY (open-ended).
     *  - one_day       → temporary override for a single day.
     *  - date_range    → temporary override for [from, effective_to].
     */
    private function resolveMode(string $mode, string $from, ?string $toInput): array
    {
        switch ($mode) {
            case 'one_day':    return [$from, true];
            case 'date_range': return [$toInput, true];
            default:           return [null, false]; // until_changed (primary)
        }
    }

    /**
     * Assign shift to single user. Modes: until_changed (primary) | one_day | date_range.
     */
    public function assignShiftToUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:t_sys_user,id',
            'shift_template_id' => 'required|exists:t_ops_shift_template,id',
            'mode' => 'nullable|in:until_changed,one_day,date_range',
            'effective_from' => 'nullable|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $mode = $request->input('mode', 'until_changed');
        $from = $request->input('effective_from') ?: now()->format('Y-m-d');
        $userId = (int) $request->user_id;
        $templateId = (int) $request->shift_template_id;
        [$to, $isTemp] = $this->resolveMode($mode, $from, $request->input('effective_to'));

        if ($isTemp && !$to) {
            return response()->json(['success' => false, 'message' => 'An end date is required for a date-range change.'], 422);
        }

        try {
            DB::transaction(function () use ($userId, $templateId, $from, $to, $isTemp) {
                if ($isTemp) {
                    $this->applyTemporaryAssignment($userId, $templateId, $from, $to);
                } else {
                    $this->applyAssignment($userId, $templateId, $from);
                }
            });

            // Invalidate caches + re-stamp snapshots for any back-dated portion.
            $this->invalidateAndRestamp($userId, $from, $isTemp ? $to : null);
            $this->pushShiftChange($userId);

            return response()->json([
                'success' => true,
                'message' => $isTemp ? 'Temporary shift change saved' : 'Shift assigned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error assigning shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk assign shift to multiple users (same mode for all).
     */
    public function bulkAssignShift(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:t_sys_user,id',
            'shift_template_id' => 'required|exists:t_ops_shift_template,id',
            'mode' => 'nullable|in:until_changed,one_day,date_range',
            'effective_from' => 'nullable|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $userIds = $request->user_ids;
        $templateId = (int) $request->shift_template_id;
        $mode = $request->input('mode', 'until_changed');
        $from = $request->input('effective_from') ?: now()->format('Y-m-d');
        [$to, $isTemp] = $this->resolveMode($mode, $from, $request->input('effective_to'));

        if ($isTemp && !$to) {
            return response()->json(['success' => false, 'message' => 'An end date is required for a date-range change.'], 422);
        }

        try {
            DB::transaction(function () use ($userIds, $templateId, $from, $to, $isTemp) {
                foreach ($userIds as $userId) {
                    if ($isTemp) {
                        $this->applyTemporaryAssignment((int) $userId, $templateId, $from, $to);
                    } else {
                        $this->applyAssignment((int) $userId, $templateId, $from);
                    }
                }
            });

            foreach ($userIds as $userId) {
                $this->invalidateAndRestamp((int) $userId, $from, $isTemp ? $to : null);
                $this->pushShiftChange((int) $userId);
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift ' . ($isTemp ? 'change' : 'assigned') . ' saved for ' . count($userIds) . ' user(s)'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error bulk assigning shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * A single rider's shift summary for the change popup: their CURRENT shift plus
     * active/upcoming changes (so the manager sees what's set before setting more).
     */
    public function userShiftSummary(Request $request)
    {
        $userId = (int) $request->input('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        }

        $today = now()->format('Y-m-d');
        $primary = $this->shiftService->getUserShift($userId, $today);

        $rows = UserShiftAssignmentModel::with('shiftTemplate')->where('user_id', $userId)->get();
        $changes = [];
        foreach ($rows as $row) {
            $f = $row->effective_from ? $row->effective_from->format('Y-m-d') : null;
            $t = $row->effective_to ? $row->effective_to->format('Y-m-d') : null;
            if ($t !== null && $t >= $today) {
                $changes[] = ['assignment_id' => $row->id, 'kind' => 'temporary',
                    'shift_name' => optional($row->shiftTemplate)->shift_name, 'from' => $f, 'to' => $t,
                    'started' => ($f === null || $f <= $today),
                    'acknowledged' => $row->acknowledged_at !== null];
            } elseif ($t === null && $f !== null && $f > $today) {
                $changes[] = ['assignment_id' => $row->id, 'kind' => 'upcoming_primary',
                    'shift_name' => optional($row->shiftTemplate)->shift_name, 'from' => $f, 'to' => null,
                    'acknowledged' => $row->acknowledged_at !== null];
            }
        }

        return response()->json([
            'success' => true,
            'primary' => ['shift_name' => $primary['shift_name'], 'start' => $primary['shift_start'], 'end' => $primary['shift_end']],
            'changes' => $changes,
        ]);
    }

    /**
     * Cancel a TEMPORARY shift change (a bounded override row). If it hasn't started
     * yet, remove it entirely; if it's in progress, end it yesterday so the primary
     * resumes today. Already-elapsed days stay as they happened. Never touches a
     * primary (open-ended) row.
     */
    public function cancelShiftChange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'assignment_id' => 'required|integer|exists:t_ops_user_shift_assignment,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $row = UserShiftAssignmentModel::find((int) $request->assignment_id);
        if (!$row || is_null($row->effective_to)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a temporary change can be cancelled. Change a primary shift with "Until I change it".'
            ], 400);
        }

        $userId = (int) $row->user_id;
        $today = now()->format('Y-m-d');
        $from = $row->effective_from ? $row->effective_from->format('Y-m-d') : $today;

        try {
            DB::transaction(function () use ($row, $today, $from) {
                if ($from > $today) {
                    $row->delete(); // hasn't started yet → remove entirely
                } else {
                    $row->update([  // in progress → end yesterday, primary resumes today
                        'effective_to' => date('Y-m-d', strtotime($today . ' -1 day')),
                        'updated_by' => auth()->id(),
                    ]);
                }
            });

            // Only today's resolution changes (elapsed override days stand); re-stamp today.
            $this->invalidateAndRestamp($userId, $today, $today);

            return response()->json(['success' => true, 'message' => 'Temporary change cancelled.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error cancelling change: ' . $e->getMessage()], 500);
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

        $asOf = now()->format('Y-m-d');

        try {
            DB::transaction(function () use ($request, $asOf) {
                $this->endAssignment((int) $request->user_id, $asOf);
            });

            // Clear cache
            $this->shiftService->clearUserShiftCache((int) $request->user_id);

            return response()->json([
                'success' => true,
                'message' => 'Shift assignment ended. From today the user falls back to the default shift; history is kept.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error removing shift assignment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * After an assignment change: invalidate caches, and for a BACK-DATED change,
     * re-stamp the affected attendance snapshots so late/overtime reflect the newly
     * assigned shift (working days already recompute via per-date resolution). This is
     * what makes "assign the correct old shift → the reports fix themselves" actually
     * true for lateness, matching the owner's expectation.
     */
    private function invalidateAndRestamp(int $userId, string $effectiveFrom, ?string $effectiveTo = null): void
    {
        $today = now()->format('Y-m-d');
        // Re-stamp window ends at the change's end (for a bounded override) or today,
        // whichever is earlier — never re-stamp future days (nothing stamped yet).
        $restampEnd = $effectiveTo ? min($effectiveTo, $today) : $today;

        // A far-back change can touch cached dates outside the per-user ±30d window,
        // so bump the global cache version instead (cheap now).
        if ($effectiveFrom < now()->subDays(30)->format('Y-m-d')) {
            $this->shiftService->clearAllShiftCaches();
        } else {
            $this->shiftService->clearUserShiftCache($userId);
        }

        if ($effectiveFrom <= $restampEnd) {
            $this->shiftService->restampRange($userId, $effectiveFrom, $restampEnd);
        }
    }

    /**
     * Notify a rider (FCM push) that their shift changed. Best-effort / non-fatal —
     * the in-app banner (pending_shift_ack) is the reliable channel; this is the nudge.
     * Fires for BOTH web and mobile assigns.
     */
    private function pushShiftChange(int $userId): void
    {
        try {
            $shift = $this->shiftService->getUserShift($userId, now()->format('Y-m-d'));
            app(\App\Services\FirebaseService::class)->notifyUser(
                $userId,
                [
                    'title' => 'Shift updated',
                    'body'  => 'Your shift is now ' . $shift['shift_name'] . ' (' . $shift['shift_start'] . '–' . $shift['shift_end'] . '). Open the app to confirm.',
                ],
                ['type' => 'shift_assigned'],
                'shift_notifications'
            );
        } catch (\Throwable $e) {
            \Log::warning('Shift-change push failed (non-fatal)', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Assign a shift to a user effective from a date, KEEPING history.
     * - If the user is already on this exact shift (open, covering the date): no-op.
     * - Any assignment starting exactly on $effectiveFrom is updated in place.
     * - Rows starting AFTER $effectiveFrom are removed (this change supersedes them);
     *   the removal is logged for audit since it can drop real history on a back-date.
     * - The currently-open covering row is CLOSED at ($effectiveFrom - 1 day).
     * - A new open row (effective_to = NULL) is inserted from $effectiveFrom.
     * Must run inside a DB transaction supplied by the caller.
     */
    private function applyAssignment(int $userId, int $shiftTemplateId, string $effectiveFrom): void
    {
        $prevDay = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));

        // No-op guard: user already on this template, open-ended, covering the date →
        // don't create a redundant history row or re-prompt the rider to acknowledge.
        $alreadyOnIt = UserShiftAssignmentModel::where('user_id', $userId)
            ->where('shift_template_id', $shiftTemplateId)
            ->whereNull('effective_to')
            ->where(function ($q) use ($effectiveFrom) {
                $q->whereNull('effective_from')
                  ->orWhereDate('effective_from', '<=', $effectiveFrom);
            })
            ->exists();
        if ($alreadyOnIt) {
            return;
        }

        // An assignment already starts exactly on this date -> update it in place
        // (avoids creating a zero-length historical row).
        $sameDay = UserShiftAssignmentModel::where('user_id', $userId)
            ->whereDate('effective_from', $effectiveFrom)
            ->first();

        // Remove rows that start AFTER the new date (this change supersedes them).
        // Log first — on a back-dated change these can be real, acknowledged history.
        $superseded = UserShiftAssignmentModel::where('user_id', $userId)
            ->whereNotNull('effective_from')
            ->whereDate('effective_from', '>', $effectiveFrom)
            ->get(['id', 'shift_template_id', 'effective_from', 'effective_to']);
        if ($superseded->isNotEmpty()) {
            \Log::info('Shift assignment: superseding future rows', [
                'user_id' => $userId, 'new_effective_from' => $effectiveFrom,
                'removed' => $superseded->toArray(),
            ]);
            UserShiftAssignmentModel::whereIn('id', $superseded->pluck('id'))->delete();
        }

        if ($sameDay) {
            $sameDay->update([
                'shift_template_id' => $shiftTemplateId,
                'effective_to'      => null,
                'notified_at'       => now(),
                'acknowledged_at'   => null,
                'updated_by'        => auth()->id(),
            ]);
            return;
        }

        // Close the currently-open covering row at the day before the new one starts.
        UserShiftAssignmentModel::where('user_id', $userId)
            ->whereNull('effective_to')
            ->where(function ($q) use ($effectiveFrom) {
                $q->whereNull('effective_from')
                  ->orWhereDate('effective_from', '<', $effectiveFrom);
            })
            ->update([
                'effective_to' => $prevDay,
                'updated_by'   => auth()->id(),
            ]);

        // Insert the new open assignment.
        UserShiftAssignmentModel::create([
            'user_id'           => $userId,
            'shift_template_id' => $shiftTemplateId,
            'effective_from'    => $effectiveFrom,
            'effective_to'      => null,
            'notified_at'       => now(),
            'acknowledged_at'   => null,
            'created_by'        => auth()->id(),
            'updated_by'        => auth()->id(),
        ]);
    }

    /**
     * Insert a TEMPORARY override that LAYERS on top of the primary for [$from,$to].
     * Does NOT touch the open primary — resolution picks the override for its dates and
     * the primary resumes automatically after $to. Removes any existing OVERLAPPING
     * bounded overrides (the "replace existing temporary change" behaviour — the UI
     * confirms first). Must run inside a DB transaction supplied by the caller.
     */
    private function applyTemporaryAssignment(int $userId, int $shiftTemplateId, string $from, string $to): void
    {
        // Remove overlapping temporary overrides (BOUNDED rows only — never the primary).
        $overlapping = UserShiftAssignmentModel::where('user_id', $userId)
            ->whereNotNull('effective_to')
            ->whereNotNull('effective_from')
            ->whereDate('effective_from', '<=', $to)
            ->whereDate('effective_to', '>=', $from)
            ->get(['id', 'shift_template_id', 'effective_from', 'effective_to']);
        if ($overlapping->isNotEmpty()) {
            \Log::info('Temporary shift override: replacing overlapping override(s)', [
                'user_id' => $userId, 'new_from' => $from, 'new_to' => $to,
                'removed' => $overlapping->toArray(),
            ]);
            UserShiftAssignmentModel::whereIn('id', $overlapping->pluck('id'))->delete();
        }

        UserShiftAssignmentModel::create([
            'user_id'           => $userId,
            'shift_template_id' => $shiftTemplateId,
            'effective_from'    => $from,
            'effective_to'      => $to,
            'notified_at'       => now(),
            'acknowledged_at'   => null,
            'created_by'        => auth()->id(),
            'updated_by'        => auth()->id(),
        ]);
    }

    /**
     * End a user's shift assignment as of a date, KEEPING history.
     * Closes the open covering row at ($asOf - 1 day) and drops any row starting
     * on/after $asOf; from $asOf the user resolves to the default shift.
     * Must run inside a DB transaction supplied by the caller.
     */
    private function endAssignment(int $userId, string $asOf): void
    {
        $prevDay = date('Y-m-d', strtotime($asOf . ' -1 day'));

        UserShiftAssignmentModel::where('user_id', $userId)
            ->whereNotNull('effective_from')
            ->whereDate('effective_from', '>=', $asOf)
            ->delete();

        UserShiftAssignmentModel::where('user_id', $userId)
            ->whereNull('effective_to')
            ->where(function ($q) use ($asOf) {
                $q->whereNull('effective_from')
                  ->orWhereDate('effective_from', '<', $asOf);
            })
            ->update([
                'effective_to' => $prevDay,
                'updated_by'   => auth()->id(),
            ]);
    }
}

