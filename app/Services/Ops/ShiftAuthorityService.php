<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ SHIFT AUTHORITY — the ONE place that answers "may this person change that person's shift?"
 *
 * Owner ask (Taimur via Shabib, 6-Sep-2026), rulings locked the same day. Plan +
 * decision record: `SHIFT-AUTHORITY-PLAN-SEP2026.md`. SQL: `shift_authority_sep2026.sql`.
 *
 * THE RULE, in one sentence:
 *
 *      You may change the shift of anyone ranked BELOW you on the ladder, and not your own.
 *
 * and the approval rule that hangs off the same number:
 *
 *      The approver of a change is anyone ranked above BOTH the person being changed
 *      and the person making the change.
 *
 * Ladder as seeded: Taimur 3 · Shabib 2 · Farooq 1 · everyone else 0.
 *
 * ⭐ WHY ONE SERVICE AND NOT A MIDDLEWARE. Every shift write in the whole system — web
 *   planner, the reusable change-shift popup on attendance, and every mobile screen —
 *   funnels through `Ops\ShiftController::assignShiftToUser / bulkAssignShift /
 *   cancelShiftChange / removeShiftAssignment` (the mobile API wrappers delegate to it).
 *   Gating there covers web and phone at once and cannot drift apart. A middleware on the
 *   routes would have to be repeated on the API side and would miss the delegating calls.
 *
 * ⚠⚠ THIS ALSO CLOSES A HOLE THAT EXISTS TODAY. Before this round the web endpoints
 *    (`/shifts/assign`, `/shifts/bulk-assign`, `/shifts`, `/shifts/{id}`) had NO permission
 *    check at all — they sat in the plain `auth` group, and the sidebar showed the Shift
 *    Planner to every non-rider. Only the mobile wrappers checked `manage_shifts`. Step 0 of
 *    `decide()` is that check, so from this round the desk and the phone agree.
 *
 * ⚠ SCHEMA-GUARDED. The PHP may be uploaded before `shift_authority_sep2026.sql` runs. With
 *   the tables missing this degrades to "you must hold `manage_shifts`" — today's intended
 *   behaviour, minus the open door. It never fails a write because a column is not there.
 */
class ShiftAuthorityService
{
    public const T_AUTHORITY = 't_ops_shift_authority';
    public const T_REQUEST   = 't_ops_shift_change_request';

    /** Holding this means "you plan shifts" — the pre-existing key, unchanged. */
    public const PERMISSION = 'manage_shifts';

    /**
     * ⚠⚠ Opens the Shift rules page. It does NOT gate approve/decline — a queued change is
     *    answered by LADDER POSITION (anyone ranked above both the target and the requester),
     *    so Taimur is never a bottleneck. In practice the two coincide: a change Shabib makes
     *    to Farooq can only be answered by Taimur.
     *    Seeded to role 14 (Taimur) ONLY — owner ruling 6-Sep. Deliberately not Management
     *    (Shabib) or supervisor 2 (Farooq). Shabib can be given it later by ticking one row
     *    on Roles → Permissions; no code change.
     */
    public const RULES_PERMISSION = 'manage_shift_rules';

    /** Verdicts from decide(). */
    public const ALLOW    = 'allow';
    public const APPROVAL = 'approval';
    public const DENY     = 'deny';

    private ?bool $hasAuthority = null;
    private ?bool $hasRequests = null;
    private ?bool $hasTemplateApproval = null;
    private array $ruleCache = [];
    private ?int $topRankCache = null;

    // ─────────────────────────────────────────────────────────────────────────────
    //  Schema guards
    // ─────────────────────────────────────────────────────────────────────────────

    /** Is the ladder table there? Without it there are no rules, only `manage_shifts`. */
    public function available(): bool
    {
        if ($this->hasAuthority === null) {
            try { $this->hasAuthority = Schema::hasTable(self::T_AUTHORITY); }
            catch (\Throwable $e) { $this->hasAuthority = false; }
        }
        return $this->hasAuthority;
    }

    /** Is the approval-request table there? Without it a change either happens or is refused. */
    public function requestsAvailable(): bool
    {
        if ($this->hasRequests === null) {
            try { $this->hasRequests = Schema::hasTable(self::T_REQUEST); }
            catch (\Throwable $e) { $this->hasRequests = false; }
        }
        return $this->hasRequests;
    }

    /** Do shift TYPES carry an approval state yet? */
    public function templateApprovalAvailable(): bool
    {
        if ($this->hasTemplateApproval === null) {
            try { $this->hasTemplateApproval = Schema::hasColumn('t_ops_shift_template', 'approval_status'); }
            catch (\Throwable $e) { $this->hasTemplateApproval = false; }
        }
        return $this->hasTemplateApproval;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Who is who
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Does this person plan shifts at all? Web checks the web permission half, the phone
     * checks the mobile half — the SAME key, seeded on both sides by the workshop round.
     */
    public function isPlanner($user, bool $mobile = false): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) return false;
        if ($mobile) {
            return method_exists($user, 'hasMobilePermission')
                && (bool) $user->hasMobilePermission(self::PERMISSION);
        }
        return method_exists($user, 'hasPermission') && (bool) $user->hasPermission(self::PERMISSION);
    }

    /** May this person open the Shift rules page / approve a queued change? */
    public function canManageRules($user, bool $mobile = false): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) return false;
        if ($mobile) {
            return method_exists($user, 'hasMobilePermission')
                && (bool) $user->hasMobilePermission(self::RULES_PERMISSION);
        }
        return method_exists($user, 'hasPermission') && (bool) $user->hasPermission(self::RULES_PERMISSION);
    }

    /** The whole rule row for one person, with the wide-open defaults for anyone unlisted. */
    public function ruleFor(int $userId): array
    {
        if (isset($this->ruleCache[$userId])) return $this->ruleCache[$userId];

        $rule = ['rank' => 0, 'needs_approval' => false, 'allowed' => null];
        if ($this->available()) {
            try {
                $row = DB::table(self::T_AUTHORITY)->where('user_id', $userId)->first();
                if ($row) {
                    $ids = null;
                    if (!empty($row->allowed_template_ids)) {
                        $decoded = json_decode($row->allowed_template_ids, true);
                        // An EMPTY array would mean "no shift at all" — never a rule anyone
                        // wants, and one bad save would lock a person out of every picker.
                        // Treat it as "all shifts", same as NULL.
                        if (is_array($decoded) && count($decoded)) {
                            $ids = array_values(array_unique(array_map('intval', $decoded)));
                        }
                    }
                    $rule = [
                        'rank' => (int) $row->rank,
                        'needs_approval' => (int) $row->needs_approval === 1,
                        'allowed' => $ids,
                    ];
                }
            } catch (\Throwable $e) { /* wide-open defaults stand */ }
        }
        return $this->ruleCache[$userId] = $rule;
    }

    public function rankOf(int $userId): int
    {
        return (int) $this->ruleFor($userId)['rank'];
    }

    /** The highest rank on the ladder (0 when nobody is on it). */
    public function topRank(): int
    {
        if ($this->topRankCache !== null) return $this->topRankCache;
        $top = 0;
        if ($this->available()) {
            try { $top = (int) DB::table(self::T_AUTHORITY)->max('rank'); }
            catch (\Throwable $e) { $top = 0; }
        }
        return $this->topRankCache = $top;
    }

    /**
     * Is this person the top of the ladder? Three things hang off it, all owner rulings:
     *  • he may set his OWN shift whatever the switch says (nobody above him to ask — Q3);
     *  • he is NOT bound by anyone's allowed-shift list (he is the one who wrote it — Q2);
     *  • he is the one who edits shift TYPES under the default policy.
     * ⚠ Rank 0 is never "the top", even when the ladder is empty.
     */
    public function isTop(int $userId): bool
    {
        $r = $this->rankOf($userId);
        return $r > 0 && $r >= $this->topRank();
    }

    /** Everyone ranked above BOTH of these two — the people who may approve. */
    public function approversFor(int $targetUserId, int $requesterId): array
    {
        if (!$this->available()) return [];
        $floor = max($this->rankOf($targetUserId), $this->rankOf($requesterId));
        try {
            return DB::table(self::T_AUTHORITY . ' as a')
                ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->where('a.rank', '>', $floor)
                ->where('u.is_active', 1)
                ->orderByDesc('a.rank')
                ->pluck('a.user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) { return []; }
    }

    public function nameOf(int $userId): string
    {
        try { return (string) (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'someone'); }
        catch (\Throwable $e) { return 'someone'; }
    }

    /** "Taimur", or "Shabib or Taimur" — for messages a manager actually reads. */
    public function namesOf(array $userIds): string
    {
        $names = array_values(array_filter(array_map(fn ($id) => $this->nameOf((int) $id), $userIds)));
        if (!$names) return 'someone above you';
        if (count($names) === 1) return $names[0];
        $last = array_pop($names);
        return implode(', ', $names) . ' or ' . $last;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  The switches
    // ─────────────────────────────────────────────────────────────────────────────

    /** May people on the ladder set their OWN shift? Default N (owner ask). */
    public function selfAssignEnabled(): bool
    {
        return strtoupper((string) $this->cfg('SHIFT_SELF_ASSIGN', 'N')) === 'Y';
    }

    /** top_only | approval (default) | anyone — who may mint a shift TYPE. */
    public function templatePolicy(): string
    {
        $p = strtolower(trim((string) $this->cfg('SHIFT_TYPE_CREATE_POLICY', 'approval')));
        return in_array($p, ['top_only', 'approval', 'anyone'], true) ? $p : 'approval';
    }

    private function cfg(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) { return $default; }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  THE GATE
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * May $actor do $action to $targetUserId's shift?
     *
     * @param  string $action  assign | cancel | end
     * @return array{verdict:string, message:?string, approvers:int[]}
     *
     * ⚠⚠ `cancel` and `end` NEVER return APPROVAL — they return DENY instead, naming who to
     *    ask. A revert has no request payload to queue (there is nothing to replay later),
     *    and letting Shabib silently cancel a temporary change Taimur made to his own shift
     *    is exactly the hole this round exists to close. It is rare by construction: only a
     *    person whose row says `needs_approval` can produce it.
     */
    public function decide($actor, int $targetUserId, ?int $templateId = null, string $action = 'assign', bool $mobile = false): array
    {
        // ── Step 0. Do you plan shifts at all? (the check the web side never had) ──
        if (!$this->isPlanner($actor, $mobile)) {
            return $this->deny('You do not have permission to change shifts.');
        }

        $actorId = (int) ($actor->id ?? 0);
        if (!$actorId) return $this->deny('You do not have permission to change shifts.');

        // Without the ladder table there are no rules — hold at "you are a planner".
        if (!$this->available()) return $this->allow();

        $actorRank  = $this->rankOf($actorId);
        $targetRank = $this->rankOf($targetUserId);
        $isSelf     = ($actorId === $targetUserId);

        // ── Step 1. Your own shift ──
        if ($isSelf) {
            // The top of the ladder always may: there is nobody above him to ask (Q3 ruling).
            if (!$this->isTop($actorId) && !$this->selfAssignEnabled()) {
                $above = $this->approversFor($targetUserId, $actorId);
                $who = $above ? $this->namesOf($above) : 'whoever is above you';
                return $this->deny("You can't set your own shift. Ask {$who}.");
            }
        } else {
            // ── Step 2. Reaching UP or ACROSS the ladder ──
            // ⭐ The test is deliberately `targetRank > 0 && targetRank >= actorRank`, NOT
            //   `actorRank > targetRank`. A planner who is not on the ladder at all (rank 0)
            //   keeps working exactly as today for riders and other rank-0 staff, and is
            //   still refused the management shifts. Protecting what the owner asked to
            //   protect without silently freezing anyone who is later given `manage_shifts`.
            if ($targetRank > 0 && $targetRank >= $actorRank) {
                $whoCan = $this->approversFor($targetUserId, $targetUserId);
                $name = $this->nameOf($targetUserId);
                $msg = $whoCan
                    ? "Only " . $this->namesOf($whoCan) . " can change {$name}'s shift."
                    : "You can't change {$name}'s shift.";
                return $this->deny($msg);
            }
        }

        // ── Step 3. Is this shift type even offered for this person? ──
        if ($templateId) {
            $tplCheck = $this->checkTemplate($actorId, $targetUserId, $templateId);
            if ($tplCheck) return $tplCheck;
        }

        // ── Step 4. Does someone above have to say yes? ──
        if ($this->ruleFor($targetUserId)['needs_approval']) {
            $approvers = $this->approversFor($targetUserId, $actorId);
            if ($approvers) {
                if ($action !== 'assign') {
                    $name = $isSelf ? 'your' : ($this->nameOf($targetUserId) . "'s");
                    return $this->deny(
                        "Changes to {$name} shift need approval — ask " . $this->namesOf($approvers) . " to do this."
                    );
                }
                if (!$this->requestsAvailable()) {
                    // Schema not there yet: refuse rather than apply something that was
                    // meant to wait. Never silently downgrade an approval to an assignment.
                    return $this->deny('Shift approvals are not switched on yet. Ask ' . $this->namesOf($approvers) . '.');
                }
                return [
                    'verdict' => self::APPROVAL,
                    'message' => 'Sent to ' . $this->namesOf($approvers) . ' for approval.',
                    'approvers' => $approvers,
                ];
            }
            // Nobody above both of you → you ARE the approver. Applies immediately.
        }

        return $this->allow();
    }

    /**
     * The allowed-shift list and the "is this type approved" check, shared by decide() and
     * the pickers so the server can never offer something it would then refuse.
     */
    private function checkTemplate(int $actorId, int $targetUserId, int $templateId): ?array
    {
        // A shift type still waiting for approval may not be assigned to anyone, by anyone.
        if ($this->templateApprovalAvailable()) {
            try {
                $status = DB::table('t_ops_shift_template')->where('id', $templateId)->value('approval_status');
                if ($status !== null && $status !== 'approved') {
                    return $this->deny('That shift type is still waiting for approval — it can\'t be assigned yet.');
                }
            } catch (\Throwable $e) { /* fall through */ }
        }

        // The top of the ladder is not bound by a list he wrote himself (Q2 ruling).
        if ($this->isTop($actorId)) return null;

        $allowed = $this->ruleFor($targetUserId)['allowed'];
        if ($allowed !== null && !in_array((int) $templateId, $allowed, true)) {
            $names = $this->templateNames($allowed);
            $who = $this->nameOf($targetUserId);
            return $this->deny(
                "{$who} can only be put on: " . ($names ?: 'the shifts Taimur allowed') . '.'
            );
        }
        return null;
    }

    private function templateNames(array $ids): string
    {
        try {
            return DB::table('t_ops_shift_template')->whereIn('id', $ids)
                ->orderBy('shift_name')->pluck('shift_name')->implode(', ');
        } catch (\Throwable $e) { return ''; }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  What the SCREENS ask (so a picker never offers what the gate would refuse)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Template ids this actor may put this person on — NULL means "all of them".
     * ⚠ Does not include the approved/proposed filter; `ShiftController::list()` applies
     *   that separately so it can still show a proposer his own "⏳ waiting" row.
     */
    public function allowedTemplateIdsFor($actor, int $targetUserId): ?array
    {
        $actorId = (int) ($actor->id ?? 0);
        if ($actorId && $this->isTop($actorId)) return null;
        return $this->ruleFor($targetUserId)['allowed'];
    }

    /**
     * Row-level answer for the planner grid and the mobile list: may I touch this person,
     * and if not, what do I tell the person looking at the screen?
     *
     * ⭐ Owner ruling 6-Sep: a locked row is SHOWN WITH A LOCK, never hidden — hiding
     *   people makes a planner think the grid is broken.
     *
     * @return array{can:bool, reason:?string, needs_approval:bool}
     */
    public function rowStateFor($actor, int $targetUserId, bool $mobile = false): array
    {
        $d = $this->decide($actor, $targetUserId, null, 'assign', $mobile);
        return [
            'can' => $d['verdict'] !== self::DENY,
            'reason' => $d['verdict'] === self::DENY ? $d['message'] : null,
            'needs_approval' => $d['verdict'] === self::APPROVAL,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Shift TYPES (the templates)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * May this person create a shift type at all, and does it land approved or proposed?
     * @return array{can:bool, proposed:bool, message:?string}
     */
    public function templateCreateState($actor, bool $mobile = false): array
    {
        if (!$this->isPlanner($actor, $mobile)) {
            return ['can' => false, 'proposed' => false, 'message' => 'You do not have permission to manage shifts.'];
        }
        $actorId = (int) ($actor->id ?? 0);
        if (!$this->available() || !$this->templateApprovalAvailable()) {
            return ['can' => true, 'proposed' => false, 'message' => null]; // pre-SQL behaviour
        }
        $policy = $this->templatePolicy();
        $isTop = $this->isTop($actorId);
        if ($isTop || $policy === 'anyone') {
            return ['can' => true, 'proposed' => false, 'message' => null];
        }
        if ($policy === 'top_only') {
            $tops = $this->topUserIds();
            return ['can' => false, 'proposed' => false,
                    'message' => 'Only ' . $this->namesOf($tops) . ' can create a shift type.'];
        }
        return ['can' => true, 'proposed' => true, 'message' => null]; // policy = approval
    }

    /**
     * May this person EDIT / deactivate / delete / set-default an EXISTING shift type?
     *
     * ⚠⚠ THIS IS A BACK DOOR AND IT IS WHY THE CHECK EXISTS. Editing "Manager Shift" from
     *    11:00 to 14:00 changes the actual working hours of everyone on it — including
     *    Shabib — without touching a single assignment. Gating assignments while leaving
     *    template editing open would have left the whole feature bypassable in two clicks.
     *    Same for `setDefault`: the default template is what everyone with no assignment
     *    resolves to.
     *
     * @return array{can:bool, message:?string}
     */
    public function canEditTemplate($actor, ?int $templateId = null, bool $mobile = false): array
    {
        if (!$this->isPlanner($actor, $mobile)) {
            return ['can' => false, 'message' => 'You do not have permission to manage shifts.'];
        }
        $actorId = (int) ($actor->id ?? 0);
        if (!$this->available() || !$this->templateApprovalAvailable()) {
            return ['can' => true, 'message' => null]; // pre-SQL behaviour
        }
        if ($this->isTop($actorId) || $this->templatePolicy() === 'anyone') {
            return ['can' => true, 'message' => null];
        }
        // You may still tidy up a type YOU proposed while it is still waiting.
        if ($templateId) {
            try {
                $row = DB::table('t_ops_shift_template')->where('id', $templateId)
                    ->first(['approval_status', 'proposed_by']);
                if ($row && $row->approval_status === 'proposed' && (int) $row->proposed_by === $actorId) {
                    return ['can' => true, 'message' => null];
                }
            } catch (\Throwable $e) { /* fall through to the refusal */ }
        }
        return ['can' => false,
                'message' => 'Only ' . $this->namesOf($this->topUserIds()) . ' can change a shift type.'];
    }

    /** Everyone sitting on the top rung (normally one person). */
    public function topUserIds(): array
    {
        if (!$this->available()) return [];
        $top = $this->topRank();
        if ($top <= 0) return [];
        try {
            return DB::table(self::T_AUTHORITY . ' as a')
                ->join('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->where('a.rank', $top)->where('u.is_active', 1)
                ->pluck('a.user_id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $e) { return []; }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  helpers
    // ─────────────────────────────────────────────────────────────────────────────

    private function allow(): array
    {
        return ['verdict' => self::ALLOW, 'message' => null, 'approvers' => []];
    }

    private function deny(string $msg): array
    {
        return ['verdict' => self::DENY, 'message' => $msg, 'approvers' => []];
    }

    /** Drop memoized rules — call after the rules page saves. */
    public function flush(): void
    {
        $this->ruleCache = [];
        $this->topRankCache = null;
    }
}
