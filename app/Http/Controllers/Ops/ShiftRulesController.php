<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Ops\ShiftTemplateModel;
use App\Services\Ops\ShiftAuthorityService;
use App\Services\Ops\ShiftChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ⚙ SHIFT RULES — the one page where Taimur sets who may change whose shift, plus the
 * approve / decline endpoints the corner banner and the phone both call.
 *
 * ⚠⚠ TWO DIFFERENT GATES LIVE IN THIS FILE AND THEY ARE NOT THE SAME GATE:
 *
 *   • The SETTINGS half (`index`, `data`, `saveLadder`, `savePerson`, `removePerson`,
 *     `saveSwitch`) needs `manage_shift_rules` — seeded to role 14 (Taimur) ONLY, owner
 *     ruling 6-Sep. Shabib and Farooq cannot open this page. Giving it to Shabib later is
 *     one tick on Roles → Permissions.
 *
 *   • The ANSWERING half (`approvals`, `approve`, `decline`, `withdraw`) is gated by
 *     LADDER POSITION, not by that permission: you may answer a request only if you rank
 *     above BOTH the person it would change and the person who asked. That keeps Taimur
 *     from being the only possible answer to every question while still meaning that, in
 *     practice, a change Shabib makes to Farooq can only be answered by Taimur.
 *
 * Shift TYPE proposals are the exception inside the second half: they are answered by the
 * top of the ladder, because a shift type belongs to nobody in particular.
 *
 * Plan + rulings: SHIFT-AUTHORITY-PLAN-SEP2026.md. SQL: shift_authority_sep2026.sql.
 */
class ShiftRulesController extends Controller
{
    private ShiftAuthorityService $auth;
    private ShiftChangeRequestService $requests;

    public function __construct()
    {
        $this->auth = app(ShiftAuthorityService::class);
        $this->requests = app(ShiftChangeRequestService::class);
    }

    private function me(Request $request)
    {
        return $request->user() ?: auth()->user();
    }

    /** The settings gate. Everything that WRITES a rule goes through this. */
    private function guardRules(Request $request)
    {
        if (!$this->auth->canManageRules($this->me($request))) {
            return response()->json(['success' => false, 'message' => 'Only the shift-rules owner can change this.'], 403);
        }
        return null;
    }

    // ═════════════════════════════════════════════════════════════════════════════
    //  The page
    // ═════════════════════════════════════════════════════════════════════════════

    public function index(Request $request)
    {
        if (!$this->auth->canManageRules($this->me($request))) {
            abort(403, 'This page is not for you.');
        }
        return view('pages.shifts.rules');
    }

    /**
     * Everything the page draws, in one fetch: the ladder, the per-person rules, the shift
     * types to pick from, the two switches, the staff list for "add a person", and the
     * queue of things waiting.
     */
    public function data(Request $request)
    {
        if ($deny = $this->guardRules($request)) return $deny;

        $ladder = [];
        $people = [];
        if ($this->auth->available()) {
            $rows = DB::table(ShiftAuthorityService::T_AUTHORITY . ' as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'a.user_id')
                ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->groupBy('a.user_id', 'a.rank', 'a.needs_approval', 'a.allowed_template_ids', 'u.fullname')
                ->orderByDesc('a.rank')
                ->get([
                    'a.user_id', 'a.rank', 'a.needs_approval', 'a.allowed_template_ids',
                    'u.fullname', DB::raw('MAX(r.urole_name) as role_name'),
                ]);

            foreach ($rows as $row) {
                $rule = $this->auth->ruleFor((int) $row->user_id);
                $entry = [
                    'user_id' => (int) $row->user_id,
                    'name' => $row->fullname ?: ('#' . $row->user_id),
                    'role' => $row->role_name,
                    'rank' => (int) $row->rank,
                    'needs_approval' => $rule['needs_approval'],
                    'allowed' => $rule['allowed'],
                ];
                if ((int) $row->rank > 0) $ladder[] = $entry;
                // ⭐ Everyone with a row appears in the per-person table, ladder or not —
                //   that table is where an allowed-shift list is set, and those are mostly
                //   for people who are NOT on the ladder (Haider, Waseem).
                $people[] = $entry;
            }
        }

        $templates = ShiftTemplateModel::where('active', 1)
            ->when($this->auth->templateApprovalAvailable(), fn ($q) => $q->where('approval_status', 'approved'))
            ->orderBy('shift_name')
            ->get()
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => $t->shift_name,
                'time' => substr($t->shift_start, 0, 5) . ($t->shift_end ? '–' . substr($t->shift_end, 0, 5) : ''),
            ])->values();

        $staff = DB::table('t_sys_user as u')
            ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('u.is_active', 1)
            ->groupBy('u.id', 'u.fullname')
            ->orderBy('u.fullname')
            ->get(['u.id', 'u.fullname', DB::raw('MAX(r.urole_name) as role_name')])
            ->map(fn ($u) => ['user_id' => (int) $u->id, 'name' => $u->fullname, 'role' => $u->role_name])
            ->values();

        return response()->json([
            'success' => true,
            'ready' => $this->auth->available(),
            'ladder' => $ladder,
            'people' => $people,
            'templates' => $templates,
            'staff' => $staff,
            'self_assign' => $this->auth->selfAssignEnabled(),
            'template_policy' => $this->auth->templatePolicy(),
            'pending_changes' => $this->requests->pendingFor($this->me($request)),
            'pending_templates' => $this->pendingTemplates($request),
            'me' => (int) (optional($this->me($request))->id ?? 0),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    //  Writing the rules
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * Save the ladder from an ordered list, top first. Ranks are re-numbered from the
     * order, so the page never has to think in numbers — it drags a row and posts the list.
     *
     * ⚠ Anyone dropped off the ladder keeps their other rules (an allowed-shift list is
     *   not a ladder position) and simply goes to rank 0 = changes nobody.
     */
    public function saveLadder(Request $request)
    {
        if ($deny = $this->guardRules($request)) return $deny;
        if (!$this->auth->available()) {
            return response()->json(['success' => false, 'message' => 'Shift rules are not switched on yet.'], 422);
        }
        $ids = array_values(array_unique(array_map('intval', (array) $request->input('user_ids', []))));
        $n = count($ids);
        try {
            DB::transaction(function () use ($ids, $n, $request) {
                DB::table(ShiftAuthorityService::T_AUTHORITY)->whereNotIn('user_id', $ids ?: [0])
                    ->update(['rank' => 0, 'updated_by' => auth()->id(), 'updated_at' => now()]);
                foreach ($ids as $i => $uid) {
                    $this->upsert($uid, ['rank' => $n - $i]);
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not save the ladder.'], 500);
        }
        $this->auth->flush();
        return response()->json(['success' => true, 'message' => 'Ladder saved.']);
    }

    /** One person's rules: needs-approval and the allowed-shift list. */
    public function savePerson(Request $request)
    {
        if ($deny = $this->guardRules($request)) return $deny;
        $data = $request->validate([
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'needs_approval' => 'nullable|boolean',
            'allowed' => 'nullable|array',
            'allowed.*' => 'integer|exists:t_ops_shift_template,id',
        ]);
        if (!$this->auth->available()) {
            return response()->json(['success' => false, 'message' => 'Shift rules are not switched on yet.'], 422);
        }
        $allowed = $data['allowed'] ?? null;
        // ⚠ An empty selection means "all shifts", never "no shifts" — a person who may be
        //   put on nothing could not be scheduled at all, and one stray save would do it.
        $json = ($allowed && count($allowed)) ? json_encode(array_values(array_unique(array_map('intval', $allowed)))) : null;

        try {
            $this->upsert((int) $data['user_id'], [
                'needs_approval' => $request->boolean('needs_approval') ? 1 : 0,
                'allowed_template_ids' => $json,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not save.'], 500);
        }
        $this->auth->flush();
        return response()->json(['success' => true, 'message' => 'Saved.']);
    }

    /** Drop a person's rule row entirely — back to the wide-open default. */
    public function removePerson(Request $request)
    {
        if ($deny = $this->guardRules($request)) return $deny;
        $uid = (int) $request->input('user_id');
        if (!$uid) return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        try {
            DB::table(ShiftAuthorityService::T_AUTHORITY)->where('user_id', $uid)->delete();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not remove.'], 500);
        }
        $this->auth->flush();
        return response()->json(['success' => true, 'message' => 'Rule removed — back to the default (any shift, no approval).']);
    }

    /** The two switches, stored in t_fin_config like every other operational knob. */
    public function saveSwitch(Request $request)
    {
        if ($deny = $this->guardRules($request)) return $deny;
        $key = (string) $request->input('key');
        $val = (string) $request->input('value');
        $allowed = [
            'SHIFT_SELF_ASSIGN' => ['Y', 'N'],
            'SHIFT_TYPE_CREATE_POLICY' => ['top_only', 'approval', 'anyone'],
        ];
        if (!isset($allowed[$key]) || !in_array($val, $allowed[$key], true)) {
            return response()->json(['success' => false, 'message' => 'Unknown setting.'], 422);
        }
        try {
            $exists = DB::table('t_fin_config')->where('config_key', $key)->exists();
            if ($exists) {
                DB::table('t_fin_config')->where('config_key', $key)->update(['config_value' => $val, 'updated_at' => now()]);
            } else {
                DB::table('t_fin_config')->insert(['config_key' => $key, 'config_value' => $val, 'created_at' => now()]);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not save the setting.'], 500);
        }
        return response()->json(['success' => true, 'message' => 'Saved.']);
    }

    private function upsert(int $userId, array $fields): void
    {
        $fields['updated_by'] = auth()->id();
        $fields['updated_at'] = now();
        $exists = DB::table(ShiftAuthorityService::T_AUTHORITY)->where('user_id', $userId)->exists();
        if ($exists) {
            DB::table(ShiftAuthorityService::T_AUTHORITY)->where('user_id', $userId)->update($fields);
        } else {
            DB::table(ShiftAuthorityService::T_AUTHORITY)->insert($fields + ['user_id' => $userId]);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════
    //  Answering what is waiting  (gated by the LADDER, not by manage_shift_rules)
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * The corner banner and the phone both poll this. Safe to call from anywhere: it
     * returns an empty queue for anyone with nothing to answer, so the banner simply
     * renders nothing for them.
     */
    public function approvals(Request $request)
    {
        $me = $this->me($request);
        $mobile = (bool) $request->attributes->get('shift_mobile', false);

        /**
         * 🕛 The lapse sweep rides this poll — there is no scheduler on production
         * ([[prod-has-no-scheduler-cron]]). `terminating` keeps it off the response path,
         * so a slow sweep never delays a banner.
         */
        app()->terminating(function () {
            try { app(ShiftChangeRequestService::class)->lapseDue(); } catch (\Throwable $e) { /* non-fatal */ }
        });

        return response()->json([
            'success' => true,
            'pending' => $this->requests->pendingFor($me, $mobile),
            'pending_templates' => $this->pendingTemplates($request),
            'mine' => $this->requests->myOpenRequests($me),
        ]);
    }

    public function approve(Request $request, $id)
    {
        $src = $request->attributes->get('shift_mobile') ? 'mobile' : 'web';
        $res = $this->requests->approve($this->me($request), (int) $id, $src);
        return response()->json(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
    }

    public function decline(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);
        $res = $this->requests->decline($this->me($request), (int) $id, $request->input('reason'));
        return response()->json(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
    }

    public function withdraw(Request $request, $id)
    {
        $res = $this->requests->withdraw($this->me($request), (int) $id);
        return response()->json(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    //  Shift TYPE proposals  (answered by the top of the ladder)
    // ═════════════════════════════════════════════════════════════════════════════

    private function pendingTemplates(Request $request): array
    {
        if (!$this->auth->templateApprovalAvailable()) return [];
        $meId = (int) (optional($this->me($request))->id ?? 0);
        if (!$this->auth->isTop($meId)) return [];
        try {
            return DB::table('t_ops_shift_template as t')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 't.proposed_by')
                ->where('t.approval_status', 'proposed')
                ->orderBy('t.id')
                ->limit(10)
                ->get(['t.id', 't.shift_name', 't.shift_start', 't.shift_end', 't.working_days',
                       't.created_at', 'u.fullname as proposed_by_name'])
                ->map(function ($t) {
                    $days = json_decode($t->working_days ?: '[]', true);
                    $names = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    $on = is_array($days) ? implode(', ', array_map(fn ($d) => $names[(int) $d] ?? '', $days)) : '';
                    return [
                        'id' => (int) $t->id,
                        'name' => $t->shift_name,
                        'time' => substr($t->shift_start, 0, 5) . ($t->shift_end ? '–' . substr($t->shift_end, 0, 5) : ' onwards'),
                        'days' => $on,
                        'proposed_by' => $t->proposed_by_name ?: 'someone',
                    ];
                })->all();
        } catch (\Throwable $e) { return []; }
    }

    public function approveTemplate(Request $request, $id)
    {
        $meId = (int) (optional($this->me($request))->id ?? 0);
        if (!$this->auth->isTop($meId)) {
            return response()->json(['success' => false, 'message' => 'This one is not yours to approve.'], 403);
        }
        try {
            $t = ShiftTemplateModel::find((int) $id);
            if (!$t || ($t->approval_status ?? '') !== 'proposed') {
                return response()->json(['success' => false, 'message' => 'That shift type is no longer waiting.'], 422);
            }
            $t->update(['approval_status' => 'approved', 'approved_by' => $meId, 'approved_at' => now()]);
            app(\App\Services\ShiftResolutionService::class)->clearAllShiftCaches();
            $this->tellProposer($t, true, null, $request);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not approve it.'], 500);
        }
        return response()->json(['success' => true, 'message' => 'Approved — it is now in every picker.']);
    }

    public function declineTemplate(Request $request, $id)
    {
        $meId = (int) (optional($this->me($request))->id ?? 0);
        if (!$this->auth->isTop($meId)) {
            return response()->json(['success' => false, 'message' => 'This one is not yours to answer.'], 403);
        }
        $request->validate(['reason' => 'nullable|string|max:255']);
        try {
            $t = ShiftTemplateModel::find((int) $id);
            if (!$t || ($t->approval_status ?? '') !== 'proposed') {
                return response()->json(['success' => false, 'message' => 'That shift type is no longer waiting.'], 422);
            }
            // ⚠ Declined, never deleted. A row that once existed may already be referenced
            //   from a log line, and the proposer should be able to see WHY it was refused.
            $t->update([
                'approval_status' => 'declined', 'active' => 0,
                'declined_by' => $meId, 'declined_at' => now(),
                'decline_reason' => $request->input('reason'),
            ]);
            $this->tellProposer($t, false, $request->input('reason'), $request);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not decline it.'], 500);
        }
        return response()->json(['success' => true, 'message' => 'Declined — the person who proposed it has been told.']);
    }

    private function tellProposer($t, bool $approved, ?string $reason, Request $request): void
    {
        $to = (int) ($t->proposed_by ?? 0);
        if (!$to) return;
        $by = optional($this->me($request))->fullname ?: 'the shift-rules owner';
        try {
            app(\App\Services\FirebaseService::class)->notifyUser(
                $to,
                $approved
                    ? ['title' => '✓ Shift type approved', 'body' => '"' . $t->shift_name . '" was approved by ' . $by . '. You can use it now.']
                    : ['title' => '✖ Shift type declined', 'body' => '"' . $t->shift_name . '" was declined by ' . $by . ($reason ? ' — ' . $reason : '') . '.'],
                ['type' => 'shift_type_proposed'],
                'shift_notifications'
            );
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}
