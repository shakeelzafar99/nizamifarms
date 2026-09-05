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
                    'shift_end' => $shift->shift_end ? substr($shift->shift_end, 0, 5) : null,
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
            'shift_end' => 'nullable|date_format:H:i', // start-only shifts leave this empty
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
                'shift_end' => $request->filled('shift_end') ? $request->shift_end . ':00' : null,
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
            'shift_end' => 'nullable|date_format:H:i', // start-only shifts leave this empty
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
                'shift_end' => $request->filled('shift_end') ? $request->shift_end . ':00' : null,
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

        // A bounded change that ends BEFORE today is a pure historical CORRECTION:
        // re-stamp the past so reports/lateness fix, but send NO notification and
        // require NO confirmation (there's nothing for the rider to act on).
        $isHistorical = ($to !== null && $to < now()->format('Y-m-d'));

        // Location for this assignment (chosen on the assign screen; defaults to the
        // rider's own default). Optionally make it the rider's new default going forward.
        $locationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $setDefault = $request->boolean('set_default_location');

        // Widen the re-stamp span to cover any existing override this one replaces
        // (captured before the transaction deletes those rows).
        [$rsFrom, $rsTo] = $isTemp ? $this->restampSpanForTemp($userId, $from, $to) : [$from, null];

        try {
            DB::transaction(function () use ($userId, $templateId, $from, $to, $isTemp, $isHistorical, $locationId) {
                if ($isTemp) {
                    $this->applyTemporaryAssignment($userId, $templateId, $from, $to, !$isHistorical, $locationId);
                } else {
                    $this->applyAssignment($userId, $templateId, $from, $locationId);
                }
            });

            if ($setDefault && $locationId) {
                $this->setUserDefaultLocation($userId, $locationId);
            }

            // Invalidate caches + re-stamp snapshots for any back-dated portion (ALWAYS —
            // this is the correction). Notify only for changes that touch today/future.
            $this->invalidateAndRestamp($userId, $rsFrom, $rsTo);
            if (!$isHistorical) {
                $this->pushShiftChange($userId);
                $this->dispatchShiftWhatsApp($userId);
            }
            $this->logAssignment($userId, 'assign', $mode, $templateId, $from, $isTemp ? $to : null, $request);

            return response()->json([
                'success' => true,
                'message' => $isHistorical
                    ? 'Past shift corrected — attendance recalculated. No notification sent.'
                    : ($isTemp ? 'Temporary shift change saved' : 'Shift assigned successfully')
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

        // Pure historical correction (bounded, ends before today) → re-stamp only, no notify.
        $isHistorical = ($to !== null && $to < now()->format('Y-m-d'));

        // Location (one for all selected riders); optionally set as each rider's default.
        $locationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $setDefault = $request->boolean('set_default_location');

        // Per-user widened re-stamp span (existing overrides this replaces), before delete.
        $spans = [];
        if ($isTemp) {
            foreach ($userIds as $uid) {
                $spans[(int) $uid] = $this->restampSpanForTemp((int) $uid, $from, $to);
            }
        }

        try {
            DB::transaction(function () use ($userIds, $templateId, $from, $to, $isTemp, $isHistorical, $locationId) {
                foreach ($userIds as $userId) {
                    if ($isTemp) {
                        $this->applyTemporaryAssignment((int) $userId, $templateId, $from, $to, !$isHistorical, $locationId);
                    } else {
                        $this->applyAssignment((int) $userId, $templateId, $from, $locationId);
                    }
                }
            });

            foreach ($userIds as $userId) {
                if ($setDefault && $locationId) {
                    $this->setUserDefaultLocation((int) $userId, $locationId);
                }
                [$rsFrom, $rsTo] = $isTemp ? $spans[(int) $userId] : [$from, null];
                $this->invalidateAndRestamp((int) $userId, $rsFrom, $rsTo);
                if (!$isHistorical) {
                    $this->pushShiftChange((int) $userId);
                    $this->dispatchShiftWhatsApp((int) $userId);
                }
                $this->logAssignment((int) $userId, 'assign', $mode, $templateId, $from, $isTemp ? $to : null, $request);
            }

            return response()->json([
                'success' => true,
                'message' => $isHistorical
                    ? ('Past shift corrected for ' . count($userIds) . ' rider(s) — attendance recalculated. No notification sent.')
                    : ('Shift ' . ($isTemp ? 'change' : 'assigned') . ' saved for ' . count($userIds) . ' user(s)')
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
    /**
     * Append-only audit log of an assignment action (assign / cancel / end). Records WHO
     * (actor + name snapshot), from WHERE (web/mobile), what shift, dates and mode — so the
     * team can see who did what even after a later in-place edit overwrites created/updated_by.
     * Non-fatal + no-ops if the log table hasn't been created yet.
     * $source is read from a request attribute the mobile delegators set (default 'web').
     */
    private function logAssignment(int $userId, string $action, ?string $mode, ?int $templateId, ?string $from, ?string $to, Request $request, ?string $note = null): void
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_shift_assignment_log')) {
                return;
            }
            $tplName = $templateId ? DB::table('t_ops_shift_template')->where('id', $templateId)->value('shift_name') : null;
            $actorId = auth()->id();
            $actorName = $actorId ? DB::table('t_sys_user')->where('id', $actorId)->value('fullname') : null;
            DB::table('t_ops_shift_assignment_log')->insert([
                'user_id' => $userId,
                'action' => $action,
                'mode' => $mode,
                'shift_template_id' => $templateId,
                'shift_name' => $tplName,
                'effective_from' => $from,
                'effective_to' => $to,
                'actor_user_id' => $actorId,
                'actor_name' => $actorName,
                'source' => $request->attributes->get('shift_log_source', 'web'),
                'note' => $note,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Shift assignment log failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Assignment history for a rider (append-only log), newest first. Powers the "History"
     * views on the mobile Month screen and the web Shift Planner. Each row carries a
     * pre-built human label + when/actor/via so the frontends just render it.
     */
    public function assignmentHistory(Request $request)
    {
        $userId = (int) $request->input('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_shift_assignment_log')) {
            return response()->json(['success' => true, 'history' => []]);
        }
        $rows = DB::table('t_ops_shift_assignment_log')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        $fmt = fn($d) => $d ? \Carbon\Carbon::parse($d)->format('j M') : null;
        $history = $rows->map(function ($r) use ($fmt) {
            $shift = $r->shift_name ?: 'shift';
            if ($r->action === 'cancel') {
                $label = 'Cancelled ' . $shift . ($r->effective_from ? ' · ' . $fmt($r->effective_from) . ($r->effective_to && $r->effective_to !== $r->effective_from ? '–' . $fmt($r->effective_to) : '') : '');
            } elseif ($r->action === 'end') {
                $label = 'Ended shift' . ($r->effective_from ? ' · from ' . $fmt($r->effective_from) : '');
            } elseif ($r->mode === 'until_changed') {
                $label = $shift . ' · from ' . $fmt($r->effective_from) . ' (regular)';
            } elseif ($r->mode === 'one_day') {
                $label = $shift . ' · ' . $fmt($r->effective_from) . ' only';
            } elseif ($r->mode === 'date_range') {
                $label = $shift . ' · ' . $fmt($r->effective_from) . '–' . $fmt($r->effective_to);
            } else {
                $label = $shift;
            }
            return [
                'id' => $r->id,
                'action' => $r->action,
                'label' => $label,
                'actor' => $r->actor_name ?: 'someone',
                'via' => $r->source ?: 'web',
                'when' => \Carbon\Carbon::parse($r->created_at)->format('j M, g:i A'),
                'note' => $r->note,
            ];
        });

        return response()->json(['success' => true, 'history' => $history]);
    }

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

        // Active office locations + this rider's default/usual, so the reusable modal can render the
        // location picker in ONE fetch (parity with the planner's assign sheet).
        $locations = [];
        $defaultLocationId = null;
        try {
            // ⚠ Van meet-up points share this table but are NOT offices — never
            //   offer one as a work location (see LocationService::nearestOfficeWithin).
            $locations = DB::table('t_ops_company_locations')->where('is_active', 1)
                ->when(\App\Services\LocationService::hasHandoverPointColumn(), fn ($q) => $q->where(function ($w) {
                    $w->where('is_handover_point', 0)->orWhereNull('is_handover_point');
                }))
                ->orderByDesc('is_primary')->orderBy('location_name')
                ->get(['id', 'location_name as name', 'is_primary'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'is_primary' => (int) $l->is_primary === 1])
                ->all();
            $defaultLocationId = DB::table('t_ops_user_location_assignment')
                ->where('user_id', $userId)->where('is_active', 1)->value('location_id');
        } catch (\Throwable $e) { /* locations optional */ }

        return response()->json([
            'success' => true,
            'primary' => ['shift_name' => $primary['shift_name'], 'start' => $primary['shift_start'], 'end' => $primary['shift_end']],
            'changes' => $changes,
            'locations' => $locations,
            'default_location_id' => $defaultLocationId ? (int) $defaultLocationId : null,
            'usual_location_id' => $primary['location_id'] ?? null,
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

            // Tell the rider — otherwise a change they may have already CONFIRMED
            // silently vanishes and they show up on the cancelled shift. (FCM only;
            // a WhatsApp cancel message would need its own approved template.)
            try {
                $t = $row->shiftTemplate;
                $what = $t
                    ? ($t->shift_name . ' (' . substr($t->shift_start, 0, 5) . ($t->shift_end ? '–' . substr($t->shift_end, 0, 5) : ' onwards') . ')' . $this->whenSuffix($row))
                    : 'Aap ki temporary shift change';
                // 🗣 Roman Urdu — the rider acts on this (he must turn up on the right shift).
                app(\App\Services\FirebaseService::class)->notifyUser(
                    $userId,
                    [
                        'title' => '🗓 Shift change cancel ho gaya',
                        'body'  => $what . ' cancel ho gaya — aap ki normal shift lagegi.',
                    ],
                    ['type' => 'shift_assigned'], // same type → mobile banner/screens refresh
                    'shift_notifications'
                );
            } catch (\Throwable $e) {
                \Log::warning('Shift-cancel push failed (non-fatal)', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }

            $this->logAssignment(
                $userId, 'cancel', null,
                $row->shift_template_id,
                $row->effective_from ? $row->effective_from->format('Y-m-d') : null,
                $row->effective_to ? $row->effective_to->format('Y-m-d') : null,
                $request, 'Cancelled temporary change'
            );

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

            $this->logAssignment((int) $request->user_id, 'end', null, null, $asOf, null, $request, 'Ended assignment (fall back to default)');

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
    /**
     * The full date span that a temporary override touches = the new [$from,$to]
     * UNIONED with any EXISTING bounded overrides it overlaps (which
     * applyTemporaryAssignment will delete). Days trimmed off a replaced wider
     * override revert to the primary, so they must be re-stamped too — otherwise
     * their snapshots keep the old override's lateness. Call BEFORE the transaction
     * (the overlapping rows still exist).
     */
    private function restampSpanForTemp(int $userId, string $from, string $to): array
    {
        $minFrom = $from;
        $maxTo = $to;
        $overlap = UserShiftAssignmentModel::where('user_id', $userId)
            ->whereNotNull('effective_to')->whereNotNull('effective_from')
            ->whereDate('effective_from', '<=', $to)
            ->whereDate('effective_to', '>=', $from)
            ->get(['effective_from', 'effective_to']);
        foreach ($overlap as $r) {
            $f = $r->effective_from->format('Y-m-d');
            $t = $r->effective_to->format('Y-m-d');
            if ($f < $minFrom) $minFrom = $f;
            if ($t > $maxTo) $maxTo = $t;
        }
        return [$minFrom, $maxTo];
    }

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
            // Self-guard (mirrors dispatchShiftWhatsApp): only announce a pending
            // current/future change. A purely-historical correction stamps
            // notified_at=NULL, and an already-acked change is gone too, so
            // latestPendingAssignment returns null → we push NOTHING. This keeps the
            // FCM and WhatsApp channels aligned even if a future caller forgets to
            // gate on !$isHistorical (past dates must never notify).
            $pending = $this->latestPendingAssignment($userId);
            if (!$pending || !$pending->shiftTemplate) {
                return;
            }
            $t = $pending->shiftTemplate;
            $time = substr($t->shift_start, 0, 5) . ($t->shift_end ? '–' . substr($t->shift_end, 0, 5) : ' onwards');
            $date = $pending->effective_from ? $pending->effective_from->format('Y-m-d') : now()->format('Y-m-d');
            $locName = $this->shiftService->getUserShift($userId, $date)['location_name'] ?? null;
            $body = 'Aap ki shift: ' . $t->shift_name . ' (' . $time . ')'
                . ($locName ? ' — ' . $locName : '')
                . $this->whenSuffix($pending) . '. Confirm karne ke liye app kholein.';
            app(\App\Services\FirebaseService::class)->notifyUser(
                $userId,
                ['title' => '🗓 Shift update ho gayi', 'body' => $body],
                ['type' => 'shift_assigned'],
                'shift_notifications'
            );
        } catch (\Throwable $e) {
            \Log::warning('Shift-change push failed (non-fatal)', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /** The rider's MOST-RECENTLY-NOTIFIED unacknowledged assignment (not fully past),
     *  or null. Ordered by notified_at (not id): a same-day primary correction updates
     *  an OLDER row in place, so id-ordering would announce a stale change instead. */
    private function latestPendingAssignment(int $userId)
    {
        $today = now()->format('Y-m-d');
        return UserShiftAssignmentModel::with('shiftTemplate')
            ->where('user_id', $userId)
            ->whereNotNull('notified_at')->whereNull('acknowledged_at')
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->orderByDesc('notified_at')->orderByDesc('id')->first();
    }

    /** " from Mon 14 Jul" / " on Tue 8 Jul" / " for Wed 9 – Sun 13 Jul" suffix for a change. */
    private function whenSuffix($row): string
    {
        $from = $row->effective_from ? $row->effective_from->format('D j M') : null;
        $to = $row->effective_to ? $row->effective_to->format('D j M') : null;
        // 🗣 Both callers are rider pushes written in Roman Urdu, so the date tail matches.
        if ($row->effective_to) {
            return $from === $to ? ($to ? ' — ' . $to . ' ko' : '') : ' — ' . $from . ' se ' . $to . ' tak';
        }
        return $from ? ' — ' . $from . ' se' : '';
    }

    /**
     * Fire the `shift.assigned` WhatsApp automation for a rider, out-of-band
     * (after the response) so a slow/failed WhatsApp send never blocks or breaks
     * the assignment. Self-guards: the automation service no-ops unless the master
     * switch + the shift_assigned rule are both ON. Only dispatches when there's a
     * fresh pending change (so a no-op re-assign or an already-acked change sends
     * nothing).
     *
     * PUBLIC on purpose: the update-phone endpoints call it after saving a number,
     * so a rider whose send was skipped "no phone" gets the WhatsApp as soon as the
     * manager adds their number (skips never count for dedup, so it goes out).
     */
    public function dispatchShiftWhatsApp(int $userId): void
    {
        try {
            $pending = $this->latestPendingAssignment($userId);
            if (!$pending) {
                return;
            }
            $context = [
                'user_id' => $userId,
                'assignment_id' => (int) $pending->id,
                'order_id' => (int) $pending->id, // best-effort entity ref for the activity log
            ];
            app()->terminating(function () use ($context) {
                try {
                    app(\App\Services\WhatsApp\Automation\WhatsAppAutomationService::class)
                        ->dispatch('shift.assigned', $context);
                } catch (\Throwable $e) {
                    \Log::warning('Shift WA dispatch failed (non-fatal)', ['error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
            \Log::warning('Shift WA seam failed (non-fatal)', ['user_id' => $userId, 'error' => $e->getMessage()]);
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
    /** Does the assignment table have the (owner-added) location_id column yet? Memoized. */
    private function locationColumnExists(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = \Illuminate\Support\Facades\Schema::hasColumn('t_ops_user_shift_assignment', 'location_id');
        }
        return $has;
    }

    /** Apply the location_id to a write array only when the column exists (deploy-order safe). */
    private function withLocation(array $data, ?int $locationId): array
    {
        if ($this->locationColumnExists()) {
            // ⚠ Never let a van meet-up point become a shift's work location —
            //   the check-in rules honour it. Dropped to null (the rider falls
            //   back to his default/primary office) rather than refused, so a
            //   stale form still saves the SHIFT it was really about.
            if ($locationId && !\App\Services\LocationService::isAssignableOffice($locationId)) {
                \Log::warning('Shift assign ignored a van meet-up point as a work location', [
                    'location_id' => $locationId, 'by' => auth()->id(),
                ]);
                $locationId = null;
            }
            $data['location_id'] = $locationId;
        }
        return $data;
    }

    /**
     * Set a rider's DEFAULT office location (t_ops_user_location_assignment) — used by
     * the "set as their default" toggle on the assign screens. One active row per user.
     * Busts that user's shift-resolution cache (location feeds getUserShift). Non-fatal.
     */
    private function setUserDefaultLocation(int $userId, int $locationId): void
    {
        try {
            // ⚠ A meet-up point is not an office — never make one somebody's default.
            if (!\App\Services\LocationService::isAssignableOffice($locationId)) {
                \Log::warning('Refused to set a van meet-up point as a default office', [
                    'user_id' => $userId, 'location_id' => $locationId, 'by' => auth()->id(),
                ]);
                return;
            }
            DB::table('t_ops_user_location_assignment')->where('user_id', $userId)
                ->update(['is_active' => 0, 'updated_at' => now()]);
            $existing = DB::table('t_ops_user_location_assignment')
                ->where('user_id', $userId)->where('location_id', $locationId)->first();
            if ($existing) {
                DB::table('t_ops_user_location_assignment')->where('id', $existing->id)
                    ->update(['is_active' => 1, 'assigned_by' => auth()->id(), 'updated_at' => now()]);
            } else {
                DB::table('t_ops_user_location_assignment')->insert([
                    'user_id' => $userId, 'location_id' => $locationId, 'is_active' => 1,
                    'assigned_at' => now(), 'assigned_by' => auth()->id(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->shiftService->clearUserShiftCache($userId);
        } catch (\Throwable $e) {
            \Log::warning('Set user default location failed (non-fatal)', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function applyAssignment(int $userId, int $shiftTemplateId, string $effectiveFrom, ?int $locationId = null): void
    {
        $prevDay = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));

        // No-op guard: user already on this template AND SAME location, open-ended,
        // covering the date → don't create a redundant history row or re-prompt. A
        // location-only change (same template, different branch) is NOT a no-op.
        $alreadyOnIt = UserShiftAssignmentModel::where('user_id', $userId)
            ->where('shift_template_id', $shiftTemplateId)
            ->when($this->locationColumnExists(), function ($q) use ($locationId) {
                return is_null($locationId) ? $q->whereNull('location_id') : $q->where('location_id', $locationId);
            })
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
            $sameDay->update($this->withLocation([
                'shift_template_id' => $shiftTemplateId,
                'effective_to'      => null,
                'notified_at'       => now(),
                'acknowledged_at'   => null,
                'updated_by'        => auth()->id(),
            ], $locationId));
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
        UserShiftAssignmentModel::create($this->withLocation([
            'user_id'           => $userId,
            'shift_template_id' => $shiftTemplateId,
            'effective_from'    => $effectiveFrom,
            'effective_to'      => null,
            'notified_at'       => now(),
            'acknowledged_at'   => null,
            'created_by'        => auth()->id(),
            'updated_by'        => auth()->id(),
        ], $locationId));
    }

    /**
     * Insert a TEMPORARY override that LAYERS on top of the primary for [$from,$to].
     * Does NOT touch the open primary — resolution picks the override for its dates and
     * the primary resumes automatically after $to. Removes any existing OVERLAPPING
     * bounded overrides (the "replace existing temporary change" behaviour — the UI
     * confirms first). Must run inside a DB transaction supplied by the caller.
     *
     * $notify=false for a pure HISTORICAL correction (range entirely before today):
     * the row is stamped with notified_at=NULL so it never appears as a pending /
     * awaiting-confirmation change anywhere — it's a silent data fix, not a request.
     */
    private function applyTemporaryAssignment(int $userId, int $shiftTemplateId, string $from, string $to, bool $notify = true, ?int $locationId = null): void
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

        UserShiftAssignmentModel::create($this->withLocation([
            'user_id'           => $userId,
            'shift_template_id' => $shiftTemplateId,
            'effective_from'    => $from,
            'effective_to'      => $to,
            'notified_at'       => $notify ? now() : null,
            'acknowledged_at'   => null,
            'created_by'        => auth()->id(),
            'updated_by'        => auth()->id(),
        ], $locationId));
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

