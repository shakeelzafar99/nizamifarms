<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ops\ShiftTemplateModel;
use App\Models\Ops\UserShiftAssignmentModel;
use App\Services\ShiftResolutionService;
use App\Services\Ops\ShiftAuthorityService;
use App\Services\Ops\ShiftChangeRequestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShiftController extends Controller
{
    protected $shiftService;

    /**
     * ⭐⭐ THE GATE (Sep-2026). Every shift write in the company arrives here — the web
     *    planner, the reusable change-shift popup on the attendance page, and every mobile
     *    screen (the API wrappers in RiderController delegate to these very methods). So
     *    "who may change whose shift" is answered ONCE, in ShiftAuthorityService, and the
     *    desk and the phone cannot drift apart.
     *
     * ⚠⚠ Before this round these endpoints had NO permission check at all on the web side.
     *    Step 0 of decide() is `manage_shifts`; that alone closes a door that was open to
     *    every logged-in non-rider.
     *
     * See SHIFT-AUTHORITY-PLAN-SEP2026.md; SQL shift_authority_sep2026.sql.
     */
    protected ShiftAuthorityService $authority;
    protected ShiftChangeRequestService $requests;

    public function __construct()
    {
        $this->shiftService = new ShiftResolutionService();
        $this->authority = app(ShiftAuthorityService::class);
        $this->requests = app(ShiftChangeRequestService::class);
    }

    /**
     * Who is asking, and is this the phone? Mobile calls arrive through the API wrappers,
     * which stamp `shift_log_source` — the same flag the audit log already uses, so the
     * permission half (web key vs mobile key) is read from the one place that knows.
     */
    private function actor(Request $request)
    {
        return $request->user() ?: auth()->user();
    }

    private function isMobile(Request $request): bool
    {
        return $request->attributes->get('shift_log_source', 'web') === 'mobile';
    }

    /**
     * A change this rider has ALREADY BEEN TOLD ABOUT that the new one would replace, or null.
     *
     * ⭐ Only two shapes count, because only these were announced as "something changes on
     *   this day": a TEMPORARY override, and an UPCOMING primary that has not started yet. A
     *   standing shift is not a promise about a particular day, so replacing it is ordinary
     *   work and must not nag.
     * ⚠ `notified_at` OR `acknowledged_at` — told, or told and confirmed. Both are promises;
     *   the second is just a stronger one, and the message says which.
     */
    private function announcedChangeClashing(int $userId, string $from, ?string $to): ?array
    {
        try {
            $today = now()->format('Y-m-d');
            $spanEnd = $to ?: '9999-12-31';   // an open-ended change reaches forward for ever
            $rows = UserShiftAssignmentModel::with('shiftTemplate')
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereNotNull('acknowledged_at')->orWhereNotNull('notified_at');
                })
                ->get();
            foreach ($rows as $r) {
                $f = $r->effective_from ? $r->effective_from->format('Y-m-d') : null;
                $t = $r->effective_to ? $r->effective_to->format('Y-m-d') : null;
                $isTemporary = $t !== null;
                $isUpcoming  = $t === null && $f !== null && $f > $today;
                if (!$isTemporary && !$isUpcoming) continue;      // a standing shift — ignore
                if ($t !== null && $t < $today) continue;         // already elapsed
                // Overlap between [f, t|∞] and the new [from, spanEnd].
                if (($t ?: '9999-12-31') < $from || ($f ?: '0000-01-01') > $spanEnd) continue;
                $name = optional($r->shiftTemplate)->shift_name ?: 'a shift';
                $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('j M') : '';
                return [
                    'assignment_id' => (int) $r->id,
                    'shift_name'    => $name,
                    'from'          => $f,
                    'to'            => $t,
                    'acknowledged'  => $r->acknowledged_at !== null,
                    'label'         => $name . ' · ' . ($isTemporary
                        ? ($fmt($f) . ($t && $t !== $f ? '–' . $fmt($t) : ''))
                        : ('from ' . $fmt($f))),
                ];
            }
        } catch (\Throwable $e) {
            // Advisory only — never let it block a legitimate change.
        }
        return null;
    }

    /**
     * The gate, in the shape a controller wants: returns NULL to carry on, or a ready
     * JSON refusal. `assign` may also come back as "queued for approval" — that is not a
     * refusal, so callers handle APPROVAL themselves before calling this.
     */
    private function refuse(Request $request, int $targetUserId, ?int $templateId, string $action)
    {
        if ($request->attributes->get('shift_authority_ok')) {
            return null; // an approved request replaying through the engine
        }
        $d = $this->authority->decide($this->actor($request), $targetUserId, $templateId, $action, $this->isMobile($request));
        if ($d['verdict'] === ShiftAuthorityService::DENY) {
            return response()->json(['success' => false, 'message' => $d['message']], 403);
        }
        return null;
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
        /**
         * ⭐ `for_user_id` — "which shifts may I put THIS person on?" A picker that asks gets
         *   back only what the gate would accept, so a manager never picks a shift and then
         *   reads a refusal. Asking without it (the Shift Types admin page) still lists
         *   everything, which is what that page is for.
         *
         * ⚠ A shift type still WAITING for approval is never assignable. It is returned
         *   anyway, flagged `pending`, so the person who proposed it sees "⏳ waiting" in his
         *   own picker instead of wondering where it went — the front ends disable it.
         */
        $actor = $this->actor($request);
        $forUserId = $request->filled('for_user_id') ? (int) $request->input('for_user_id') : null;
        $allowedIds = ($forUserId && $actor) ? $this->authority->allowedTemplateIdsFor($actor, $forUserId) : null;
        $hasApproval = $this->authority->templateApprovalAvailable();
        $meId = (int) (optional($actor)->id ?? 0);

        $shifts = ShiftTemplateModel::with(['userAssignments'])
            ->orderBy('is_default', 'desc')
            ->orderBy('shift_name')
            ->get()
            ->filter(function ($shift) use ($allowedIds, $hasApproval, $meId) {
                if ($hasApproval && ($shift->approval_status ?? 'approved') !== 'approved') {
                    // Declined types are gone for good; a proposal is shown only to the
                    // person who raised it and to whoever can approve it.
                    if (($shift->approval_status ?? '') !== 'proposed') return false;
                    // ⚠ `$meId > 0` matters: a proposal whose `proposed_by` is NULL would
                    //   otherwise match an unauthenticated caller (0 === 0) and leak.
                    if ($meId > 0 && (int) ($shift->proposed_by ?? 0) === $meId) return true;
                    return $meId > 0 && $this->authority->isTop($meId);
                }
                return $allowedIds === null || in_array((int) $shift->id, $allowedIds, true);
            })
            ->map(function($shift) use ($hasApproval) {
                $status = $hasApproval ? ($shift->approval_status ?? 'approved') : 'approved';
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
                    'approval_status' => $status,
                    'pending' => $status === 'proposed',
                    'assigned_users_count' => $shift->currentUserAssignments()->count()
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $shifts,
            // What the front ends need to word their own buttons — never to enforce
            // anything; every action is re-checked here.
            'can_create' => $this->authority->templateCreateState($actor, $this->isMobile($request)),
            'can_edit' => $this->authority->canEditTemplate($actor, null, $this->isMobile($request))['can'],
            'restricted' => $allowedIds !== null,
        ]);
    }

    /**
     * Store a new shift template
     */
    public function store(Request $request)
    {
        /**
         * 🆕 A NEW SHIFT TYPE IS NOT A SMALL THING — it is a new set of hours anyone can then
         * be put on. Owner ask 6-Sep: others should not mint them freely. Under the default
         * policy (`approval`) a planner may PROPOSE one; it lands `proposed`, appears in his
         * own picker as "⏳ waiting", cannot be assigned to anybody, and shows as a card for
         * the top of the ladder. Policy lives in t_fin_config so it changes without code.
         */
        $state = $this->authority->templateCreateState($this->actor($request), $this->isMobile($request));
        if (!$state['can']) {
            return response()->json(['success' => false, 'message' => $state['message']], 403);
        }

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
            $data = [
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
            ];
            // Schema-guarded: before shift_authority_sep2026.sql these columns do not exist
            // and a proposal is impossible, so the old "create it outright" behaviour stands.
            if ($this->authority->templateApprovalAvailable()) {
                $data['approval_status'] = $state['proposed'] ? 'proposed' : 'approved';
                $data['proposed_by'] = $state['proposed'] ? auth()->id() : null;
                if (!$state['proposed']) {
                    $data['approved_by'] = auth()->id();
                    $data['approved_at'] = now();
                }
            }
            $shift = ShiftTemplateModel::create($data);

            // Clear cache
            $this->shiftService->clearAllShiftCaches();

            if (!empty($state['proposed'])) {
                $this->notifyTemplateProposed((int) $shift->id, $request);
                return response()->json([
                    'success' => true,
                    'pending' => true,
                    'message' => 'Sent to ' . $this->authority->namesOf($this->authority->topUserIds())
                               . ' for approval. You can use it once it is approved.',
                    'data' => $shift
                ]);
            }

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

    /** Tell the top of the ladder that a shift type is waiting. Best-effort. */
    private function notifyTemplateProposed(int $templateId, Request $request): void
    {
        try {
            $t = ShiftTemplateModel::find($templateId);
            if (!$t) return;
            $who = optional($this->actor($request))->fullname ?: 'A manager';
            $body = $who . ' proposed "' . $t->shift_name . '" · '
                  . substr($t->shift_start, 0, 5) . ($t->shift_end ? '–' . substr($t->shift_end, 0, 5) : ' onwards')
                  . '. Nobody can be put on it until you approve.';
            foreach ($this->authority->topUserIds() as $uid) {
                app(\App\Services\FirebaseService::class)->notifyUser(
                    (int) $uid,
                    ['title' => '🆕 A new shift type needs your approval', 'body' => $body],
                    ['type' => 'shift_type_proposed', 'template_id' => (string) $templateId],
                    'shift_notifications'
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('Shift type proposal push failed (non-fatal)', ['error' => $e->getMessage()]);
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

        /**
         * ⚠⚠ THE BACK DOOR THIS CHECK EXISTS TO SHUT. Editing "Manager Shift" from 11:00 to
         *    14:00 changes the real working hours of everybody on it — Shabib included —
         *    without touching one assignment. Gating assignments and leaving template editing
         *    open would have made the whole feature bypassable in two clicks.
         */
        $edit = $this->authority->canEditTemplate($this->actor($request), (int) $id, $this->isMobile($request));
        if (!$edit['can']) {
            return response()->json(['success' => false, 'message' => $edit['message']], 403);
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
    public function destroy(Request $request, $id)
    {
        // Same back door as update(): deleting a type rewrites what everyone on it worked.
        $edit = $this->authority->canEditTemplate($this->actor($request), (int) $id, $this->isMobile($request));
        if (!$edit['can']) {
            return response()->json(['success' => false, 'message' => $edit['message']], 403);
        }

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
        $edit = $this->authority->canEditTemplate($this->actor($request), (int) $id, $this->isMobile($request));
        if (!$edit['can']) {
            return response()->json(['success' => false, 'message' => $edit['message']], 403);
        }

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
    public function setDefault(Request $request, $id)
    {
        // ⚠ The DEFAULT template is what every unassigned person resolves to — moving it
        //   changes real hours company-wide, so it is gated like an edit.
        $edit = $this->authority->canEditTemplate($this->actor($request), (int) $id, $this->isMobile($request));
        if (!$edit['can']) {
            return response()->json(['success' => false, 'message' => $edit['message']], 403);
        }

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
            /**
             * 👥 ONE COMMON LIST (owner ruling Sep-2026). This is the THIRD shift-assign
             * surface ("Select users and assign them to a shift template" on Shift Types)
             * and it used to list every active account — System Administrators included.
             * It now draws the same attendance roster as the web grid and the mobile list.
             */
            $roster = \App\Models\User::shiftPlannerRoster();

            /**
             * ⚠ ONE row per person. `effective_to IS NULL` alone is NOT unique: a
             * superseded open-ended primary is not always closed, so on this data
             * Kanan Anoos has THREE open rows and Arslan Aslam two. Joining on that
             * listed them 3× / 2× in the picker and showed whichever shift the join
             * happened to pick — sometimes the OLD one.
             *
             * So pick the newest open row per user, the same way ShiftResolutionService
             * resolves it (latest effective_from, id as the tie-break) — which is why
             * the resolved shift on every other screen was right all along.
             *
             * ⚠ This only fixes the DISPLAY. The stale rows are still in the table;
             *   cleaning them up is a separate data decision for the owner.
             */
            $latestOpen = DB::table('t_ops_user_shift_assignment')
                ->selectRaw('user_id, MAX(COALESCE(effective_from, "1970-01-01")) as max_from')
                ->whereNull('effective_to')
                ->groupBy('user_id');

            $users = DB::table('t_sys_user as u')
                ->where('u.is_active', 1)
                ->whereIn('u.id', $roster['ids'] ?: [0])
                ->leftJoinSub($latestOpen, 'lo', 'lo.user_id', '=', 'u.id')
                ->leftJoin('t_ops_user_shift_assignment as usa', function ($j) {
                    $j->on('usa.user_id', '=', 'u.id')
                      ->whereNull('usa.effective_to')
                      ->whereRaw('COALESCE(usa.effective_from, "1970-01-01") = lo.max_from')
                      // Two rows on the same date → the later id wins, as the resolver does.
                      ->whereRaw('usa.id = (SELECT MAX(x.id) FROM t_ops_user_shift_assignment x
                                            WHERE x.user_id = u.id AND x.effective_to IS NULL
                                              AND COALESCE(x.effective_from, "1970-01-01") = lo.max_from)');
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

        /**
         * ⏳ THE GATE. Three answers: go ahead, refuse, or park it for someone above.
         * ⚠ A replay from an approval carries `shift_authority_ok` and skips all of this —
         *   the question was already answered when the approver pressed the button.
         */
        if (!$request->attributes->get('shift_authority_ok')) {
            $d = $this->authority->decide($this->actor($request), $userId, $templateId, 'assign', $this->isMobile($request));
            if ($d['verdict'] === ShiftAuthorityService::DENY) {
                return response()->json(['success' => false, 'message' => $d['message']], 403);
            }
            if ($d['verdict'] === ShiftAuthorityService::APPROVAL) {
                /**
                 * ⚠⚠ NEVER QUEUE A PURELY-HISTORICAL CORRECTION — it would be a dead letter.
                 *    A bounded change that ENDS before today is a past-record correction. The
                 *    lapse rule (owner Q5) kills any request whose start day has passed, and
                 *    the sweep runs on the very next approvals poll — so a correction raised
                 *    today for last week would be accepted, parked, and silently killed
                 *    seconds later, leaving the requester with a "dropped" push and the
                 *    record still wrong. Refuse it NOW, naming who can make it, so he knows
                 *    at once instead of finding out from a notification.
                 */
                if ($to !== null && $to < now()->format('Y-m-d')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'That is a correction to a period that has already passed — '
                                   . $this->authority->namesOf($d['approvers']) . ' must make it. Ask them.',
                    ], 403);
                }
                $q = $this->requests->queue([
                    'user_id' => $userId,
                    'shift_template_id' => $templateId,
                    'mode' => $mode,
                    'effective_from' => $from,
                    'effective_to' => $to,
                    'location_id' => $request->input('location_id'),
                    'set_default_location' => $request->boolean('set_default_location'),
                ], $this->actor($request), $d['approvers'], $request->attributes->get('shift_log_source', 'web'));

                // ⚠⚠ `pending: true` — NOT an assignment. An old APK simply shows the message
                //    and refreshes; it can never read this as "done".
                return response()->json([
                    'success' => (bool) $q['ok'],
                    'pending' => (bool) $q['ok'],
                    'request_id' => $q['id'],
                    'message' => $q['message'],
                ], $q['ok'] ? 200 : 422);
            }
        }

        /**
         * ⚠⚠ IS HE ALREADY EXPECTING SOMETHING ELSE? (owner ruling 7-Sep.) A shift change the
         *    rider has already been TOLD about — or has CONFIRMED — is a promise. Overwriting
         *    it used to be silent for the manager: the row was simply replaced, and the only
         *    hint was a chip he may not have read. Now the first attempt is refused, says what
         *    he is about to undo, and only goes through when the screen sends `confirm_replace`.
         *
         * ⭐ Deliberately NARROW so this is not noise. It looks only at TEMPORARY overrides and
         *   at an UPCOMING primary — the things that were announced as a change. A rider's
         *   standing shift is not "a promise about a particular day", so ordinary day-to-day
         *   assigning is untouched.
         * ⚠ The rider is told about the NEW change by the engine as usual; this is about the
         *   manager knowing he is replacing something, and the approver seeing it too.
         */
        if (!$request->attributes->get('shift_authority_ok') && !$request->boolean('confirm_replace')) {
            $clash = $this->announcedChangeClashing($userId, $from, $isTemp ? $to : null);
            if ($clash) {
                return response()->json([
                    'success' => false,
                    'needs_confirmation' => true,
                    'replaces' => $clash,
                    'message' => 'He has already been told about a shift change: ' . $clash['label']
                        . ($clash['acknowledged'] ? ' — he has confirmed it' : ' — he has not confirmed it yet')
                        . '. Yeh us ko badal dega — usko dobara batana parega.',
                ], 409);
            }
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

        /**
         * ⏳ THE GATE, per person — owner ruling Q7 (6-Sep): a mixed selection is NOT refused
         * as a batch. Apply what may be applied, park what needs approval, skip what may
         * never be touched, and say all three in one sentence. Refusing the whole batch over
         * one locked row would make a planner un-tick people to find the culprit.
         */
        $queued = [];
        $skipped = [];
        if (!$request->attributes->get('shift_authority_ok')) {
            $actor = $this->actor($request);
            $mobile = $this->isMobile($request);
            $src = $request->attributes->get('shift_log_source', 'web');
            $go = [];
            foreach ($userIds as $uid) {
                $d = $this->authority->decide($actor, (int) $uid, $templateId, 'assign', $mobile);
                if ($d['verdict'] === ShiftAuthorityService::DENY) {
                    $skipped[] = $this->authority->nameOf((int) $uid);
                } elseif ($d['verdict'] === ShiftAuthorityService::APPROVAL) {
                    $q = $this->requests->queue([
                        'user_id' => (int) $uid,
                        'shift_template_id' => $templateId,
                        'mode' => $mode,
                        'effective_from' => $from,
                        'effective_to' => $to,
                        'location_id' => $locationId,
                        'set_default_location' => $setDefault,
                    ], $actor, $d['approvers'], $src);
                    if ($q['ok']) $queued[] = $this->authority->nameOf((int) $uid);
                    else $skipped[] = $this->authority->nameOf((int) $uid);
                } else {
                    $go[] = (int) $uid;
                }
            }
            $userIds = $go;

            // Nothing left to write, but something DID happen (or was refused) — report it
            // rather than running an empty transaction and claiming success for 0 people.
            if (!$userIds) {
                $parts = [];
                if ($queued)  $parts[] = count($queued) . ' sent for approval (' . implode(', ', $queued) . ')';
                if ($skipped) $parts[] = count($skipped) . ' skipped (' . implode(', ', $skipped) . ')';
                return response()->json([
                    'success' => (bool) $queued,
                    'pending' => (bool) $queued,
                    'message' => $parts ? ucfirst(implode(' · ', $parts)) . '.' : 'Nothing to change.',
                ], $queued ? 200 : 403);
            }
        }

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

            $msg = $isHistorical
                ? ('Past shift corrected for ' . count($userIds) . ' rider(s) — attendance recalculated. No notification sent.')
                : ('Shift ' . ($isTemp ? 'change' : 'assigned') . ' saved for ' . count($userIds) . ' user(s)');
            // Q7: the other two outcomes ride along in the SAME message, so the planner is
            // never left wondering what happened to the people he had also ticked.
            if ($queued)  $msg .= ' · ' . count($queued) . ' sent for approval (' . implode(', ', $queued) . ')';
            if ($skipped) $msg .= ' · ' . count($skipped) . ' skipped (' . implode(', ', $skipped) . ')';

            return response()->json([
                'success' => true,
                'pending' => (bool) $queued,
                'message' => $msg,
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

        /**
         * 🔒 SHIFT AUTHORITY — the reusable popup asks the same question the grid does, in
         * the same fetch it already makes. Without this the attendance page would offer a
         * Change button on someone the server will then refuse.
         * ⚠ Advisory only; every write re-asks.
         */
        $me = $this->actor($request);
        $rowState = $this->authority->rowStateFor($me, $userId, $this->isMobile($request));

        return response()->json([
            'success' => true,
            'primary' => ['shift_name' => $primary['shift_name'], 'start' => $primary['shift_start'], 'end' => $primary['shift_end']],
            'changes' => $changes,
            'locations' => $locations,
            'default_location_id' => $defaultLocationId ? (int) $defaultLocationId : null,
            'usual_location_id' => $primary['location_id'] ?? null,
            'can_change' => $rowState['can'],
            'lock_reason' => $rowState['reason'],
            'needs_approval' => $rowState['needs_approval'],
            'allowed_template_ids' => $this->authority->allowedTemplateIdsFor($me, $userId),
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

        /**
         * ⚠⚠ CANCELLING IS CHANGING. Reverting a temporary change puts the person back on a
         *    different shift, so it goes through the same gate. `decide('cancel')` never
         *    returns "queue it" — a revert has no payload to replay later — so a person whose
         *    row needs approval comes back as a refusal naming who to ask. Without this,
         *    Shabib could quietly undo a change Taimur had made to his own day.
         */
        if ($deny = $this->refuse($request, $userId, null, 'cancel')) return $deny;

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

        // Ending someone's assignment drops them to the default shift — a change like any
        // other, and gated like one.
        if ($deny = $this->refuse($request, (int) $request->user_id, null, 'end')) return $deny;

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

