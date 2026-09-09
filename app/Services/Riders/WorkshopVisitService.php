<?php

namespace App\Services\Riders;

use App\Services\ShiftResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🔧 WORKSHOP VISITS — "Asim, take AY-4771 in on Friday at 11" (owner ask, Sep-2026).
 *
 * Plan: VEHICLE-TICKETS-AND-WORKSHOP-PLAN-SEP2026.md §3.
 *
 * ⭐⭐ NOT A DAY TAG. `t_ops_day_tag` means "not needed — paid, not counted absent" and
 *    changes pay and absence treatment. OWNER RULING (2-Sep): a workshop day is a NORMAL
 *    PAID WORKING DAY; the person planning the shift adjusts that day so no lateness
 *    lands. So this is its own row that every surface READS, and nothing here ever
 *    writes a day tag.
 *
 * ⭐⭐ THE VISIT NAMES ONE PERSON, and that is what makes acceptance meaningful. The
 *    banner, the push and the Accept button all resolve `user_id` and nobody else, so a
 *    rider can never see — let alone accept — someone else's instruction. A manager may
 *    accept ON HIS BEHALF when the app is not working, and that is stored as such and
 *    rendered as "accepted by X for Y", never as the rider's own confirmation.
 *
 * ⭐ MISSED IS DERIVED, NEVER STORED. A visit whose date has passed while still
 *   scheduled/accepted is "missed" — computed at read time, exactly like the service
 *   alerts. Prod has no cron, so anything that depended on a nightly job to set a flag
 *   would simply never run (see [[prod-has-no-scheduler-cron]]).
 *
 * ⚠ Schema-guarded throughout: the PHP may be uploaded before
 *   `database/migrations/workshop_visits_sep2026.sql` runs.
 */
class WorkshopVisitService
{
    public const T_VISIT = 't_ops_workshop_visit';

    /** Set / reschedule / cancel / complete a visit. */
    public const PERMISSION = 'schedule_workshop';
    /** Be told one was set — RULED to include Farooq (role 20), who plans the shifts. */
    public const ALERT_PERMISSION = 'receive_workshop_alerts';
    /**
     * ⭐⭐ WHO MAY APPROVE A PROPOSED WORKSHOP DAY (owner + team ruling, 6-Sep-2026).
     *    The SHIFT PLANNERS — Shabib, Farooq, Taimur — because what a workshop day
     *    actually changes is the rider's day, and that is their table. Deliberately NOT
     *    `schedule_workshop`: Qasim holds that, and the whole point of this round is that
     *    Qasim's booking is a REQUEST until a planner says yes.
     * ⚠ Existed as a MOBILE key only until `workshop_approval_sep2026.sql` seeded the web
     *   half — without that SQL `hasPermission()` is false for everyone on the desk.
     */
    public const APPROVE_PERMISSION = 'manage_shifts';

    public const PURPOSES = ['service', 'repair', 'inspection', 'other'];
    /**
     * Statuses that still expect something to happen.
     *
     * ⚠⚠ `proposed` IS DELIBERATELY NOT IN HERE, and that is the safety mechanism of the
     *    whole approval round. Every rider-facing reader is built on this list —
     *    `nextForUser`, `awaitingOutcomeFor`, `summaryFor`'s rider branch, `dueReminders`,
     *    `mapForRange`'s default — so a proposal is invisible to the rider BY CONSTRUCTION
     *    rather than by remembering to filter it in each place. It is also what makes OLD
     *    APKs safe: a phone that has never heard of `proposed` is never sent one as his.
     */
    public const LIVE_STATUSES = ['scheduled', 'accepted'];
    /**
     * Everything a NEW booking on the same machine must supersede, and everything the
     * booker may still cancel. A proposal occupies the bike's slot exactly as a booking
     * does — two open instructions for one bike is how a rider ends up at the workshop on
     * the wrong day, and "one of them was only a request" does not make that better.
     */
    public const OPEN_STATUSES = ['proposed', 'scheduled', 'accepted'];

    private ?bool $tableExists = null;
    private ?bool $approvalCols = null;
    /**
     * Why the workshop could NOT be pinned to the rider's day, when it could not.
     * ⭐ Read straight after `applyShiftLocation()` and appended to the message the human
     *   sees. Same shape as `VehicleTicketController::$lastVoiceError`, and for the same
     *   reason: a convenience that quietly does nothing is worse than one that fails loudly.
     */
    private ?string $lastPinNote = null;

    public function available(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = Schema::hasTable(self::T_VISIT);
            } catch (\Throwable $e) {
                $this->tableExists = false;
            }
        }
        return $this->tableExists;
    }

    public function canSchedule($user, bool $mobile = false): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) return false;
        if ($mobile) {
            return method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission(self::PERMISSION);
        }
        return method_exists($user, 'hasPermission') && (bool) $user->hasPermission(self::PERMISSION);
    }

    /**
     * ⭐⭐ IS THE APPROVAL FLOW EVEN INSTALLED? Until `workshop_approval_sep2026.sql` has
     *    run there is nowhere to record who proposed or approved anything, so this whole
     *    round falls back to the OLD behaviour — a booking is assigned immediately and the
     *    rider is told, exactly as before. That is the correct degradation for a system
     *    deployed by hand: the PHP routinely lands before the SQL does, and a half-applied
     *    approval flow that silently swallowed bookings would be far worse than the old one.
     */
    public function approvalEnabled(): bool
    {
        if ($this->approvalCols === null) {
            try {
                $this->approvalCols = $this->available()
                    && Schema::hasColumn(self::T_VISIT, 'proposed_by');
            } catch (\Throwable $e) {
                $this->approvalCols = false;
            }
        }
        return $this->approvalCols;
    }

    /**
     * ⭐ ONE PREDICATE for "is this person a shift planner?", asked by the booking decision,
     *   the approve/decline doors, the banner and both UIs. Written once for the same
     *   reason `VehicleTicketService::visibilityScope` is: a rule spelled out in four
     *   places is a rule that will disagree with itself within a month.
     */
    public function canApprove($user, bool $mobile = false): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) return false;
        if ($mobile) {
            return method_exists($user, 'hasMobilePermission')
                && (bool) $user->hasMobilePermission(self::APPROVE_PERMISSION);
        }
        return method_exists($user, 'hasPermission') && (bool) $user->hasPermission(self::APPROVE_PERMISSION);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  SCHEDULING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Set a date. If the machine already has a live visit, this REPLACES it — the old
     * row becomes 'rescheduled' and points at the new one, and the rider must accept
     * again (a moved date he never saw is worse than no date at all).
     *
     * @return array{ok: bool, message: string, visit_id?: int, warnings?: array, rescheduled_from?: int}
     */
    public function schedule($user, array $in, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->canSchedule($user, $mobile)) {
            return ['ok' => false, 'message' => 'You cannot schedule workshop visits.'];
        }

        $vehicleId = (int) ($in['vehicle_id'] ?? 0);
        $date      = trim((string) ($in['visit_date'] ?? ''));

        /**
         * ⭐ THE BIKE CAN BE RESOLVED FROM THE RIDER. Callers that already know which man
         *   they are looking at (the Bikes drawer, a ticket) should not have to carry a
         *   vehicle id around — the registry answers it, and asking the registry once here
         *   is safer than four screens each finding their own way to the same number.
         *   `vehicle_id` still wins when given, for the vehicle-first surfaces.
         */
        if (!$vehicleId && !empty($in['user_id'])) {
            try {
                $vehicleId = (int) ((new VehicleResolver())->currentVehicleFor((int) $in['user_id']) ?: 0);
            } catch (\Throwable $e) {
                $vehicleId = 0;
            }
        }
        if (!$vehicleId) return ['ok' => false, 'message' => 'Choose which bike is going in — the registry has no machine for that rider.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'message' => 'Give the date as YYYY-MM-DD.'];
        }
        if ($date < \Carbon\Carbon::today()->format('Y-m-d')) {
            // A visit is an instruction for the future. Recording work already done is
            // "Record service", which is a different action with a different effect.
            return ['ok' => false, 'message' => 'That date has already passed. To record work already done, use Record service on the Bikes screen.'];
        }

        // WHO goes. Named explicitly, else whoever the registry says holds it that day.
        $riderId = (int) ($in['user_id'] ?? 0);
        if (!$riderId) {
            try {
                $riderId = (int) ((new VehicleResolver())->riderForVehicleDay($vehicleId, $date) ?: 0);
            } catch (\Throwable $e) {
                $riderId = 0;
            }
        }
        if (!$riderId) {
            return ['ok' => false, 'message' => 'Nobody holds that bike on the day — name the rider who should take it.'];
        }

        $purpose = in_array($in['purpose'] ?? '', self::PURPOSES, true) ? $in['purpose'] : 'service';

        /**
         * ⭐⭐ THE BOOKING DECISION (owner + team ruling, 6-Sep-2026), made SERVER-SIDE from
         *    the caller's own rights so no client can grant itself an assignment.
         *
         *      Qasim (schedule_workshop, not a planner)  → ALWAYS a proposal.
         *      Shabib / Taimur (planner as well)         → the form asks him, and he
         *                                                  answers with `send_for_approval`.
         *
         * ⚠⚠ THE PLANNER'S DEFAULT IS THE OLD BEHAVIOUR, ON PURPOSE. A planner on an old
         *    APK — or an old cached web page — sends neither flag, and must not have his
         *    booking quietly turned into a request he will never see the banner for and
         *    which the morning sweep then auto-declines. So the ASK is a UI affordance and
         *    the flag is opt-IN; only a client that knows about approval can send for it.
         *    Qasim's client being old changes nothing, because it is the PLANNERS' screens
         *    that show a proposal and those ship with this server.
         *
         * ⚠ A non-planner sending `assign_now` is simply not believed — the flag is never
         *   read from the request for him; his rights decide, not his payload.
         */
        $isPlanner = $this->canApprove($user, $mobile);
        $proposed  = $this->approvalEnabled()
            && (!$isPlanner || !empty($in['send_for_approval']));

        /**
         * ⏰ DEAD ON ARRIVAL (owner ruling 7-Sep). A proposal is auto-declined once the clock
         * passes its approval cut-off — min(shift start, appointment) minus the lead time.
         * Booking one AFTER that moment was accepted with a cheerful "Sent for approval", and
         * the very next alerts poll killed it. The booker walked away believing a planner
         * would look; nobody could have, because `approve()` refuses on the same clock.
         *
         * Refuse it at the door instead, and say what to do about it. A PLANNER assigning
         * directly is unaffected — he is not asking anyone, so there is no cut-off to miss.
         */
        if ($proposed) {
            $cutoff = $this->approvalCutoffFor([
                'user_id'    => $riderId,
                'visit_date' => $date,
                'visit_time' => $in['visit_time'] ?? null,
            ]);
            if (now()->greaterThanOrEqualTo($cutoff)) {
                return ['ok' => false, 'message' =>
                    'Too late to send this for approval — it had to be decided by '
                    . $cutoff->format('g:i A') . ' on ' . $cutoff->format('j M')
                    . '. Pick a later day, or ask a shift planner to book it directly.'];
            }
        }

        /**
         * ⚠⚠ TELL HIM BEFORE, NOT AFTER (owner ruling 7-Sep). Booking a bike that already has
         *    an APPROVED workshop day changes a plan the rider has already been told about —
         *    and possibly confirmed. The warnings used to arrive with the receipt, once the
         *    booking had happened. Now the server refuses the first attempt, says exactly what
         *    would change, and only accepts it when the client sends `confirm_replace`.
         *
         * ⭐ Server-side on purpose: the desk and the phone get the same guard from one place,
         *   and it cannot be skipped by a screen that forgot to ask.
         * ⚠ An OLD client cannot send the flag, so it simply cannot replace an approved day —
         *   it reads the refusal instead. That is the safe direction to fail in: the whole
         *   point is that this must never happen by accident.
         */
        $liveNow = $this->liveVisitFor($vehicleId);
        if ($liveNow && empty($in['confirm_replace'])) {
            return [
                'ok' => false,
                'needs_confirmation' => true,
                'replaces' => $liveNow,
                'message' => $this->replacementNoticeFor($liveNow)
                    . ($proposed
                        ? ' Approval ke baad hi woh badlega.'
                        : ' Woh abhi cancel ho jayega.'),
            ];
        }

        try {
            $now = now();
            $warnings = $this->warningsFor($vehicleId, $riderId, $date);

            $visitId = null;
            $replaced = null;
            DB::transaction(function () use (&$visitId, &$replaced, $vehicleId, $riderId, $date, $purpose, $in, $user, $now, $proposed) {
                /**
                 * ⚠ One open visit per machine — two open instructions for one bike is how a
                 *   rider ends up at the workshop on the wrong day.
                 *
                 * ⭐⭐ BUT A PROPOSAL MAY NOT KILL AN APPROVED DAY (owner ruling 7-Sep). Until
                 *    this round, booking a bike that already had an APPROVED visit marked that
                 *    visit `rescheduled` and deleted its shift pin the instant the new one was
                 *    raised — even when the new one was only a request nobody had answered. The
                 *    rider had been told to go, may have confirmed it, and then heard nothing:
                 *    his day quietly lost its pin and the replacement might never be approved.
                 *
                 *    So a PROPOSAL now supersedes only other PROPOSALS (re-asking is harmless).
                 *    The approved day stands, untouched, until somebody approves the
                 *    replacement — and `approve()` is what swaps them and tells the rider it
                 *    moved. A planner assigning DIRECTLY still supersedes at once, because he
                 *    is not asking anyone; the rider is told in the same breath.
                 *
                 * ⚠ The rider never sees two: a proposal is not a LIVE status, so every
                 *   rider-facing reader ignores it by construction.
                 */
                $supersedable = $proposed
                    ? ['proposed']
                    : ($this->approvalEnabled() ? self::OPEN_STATUSES : self::LIVE_STATUSES);
                $existing = DB::table(self::T_VISIT)
                    ->where('vehicle_id', $vehicleId)
                    ->whereIn('status', $supersedable)
                    ->lockForUpdate()
                    ->orderByDesc('id')->first();

                $visitId = (int) DB::table(self::T_VISIT)->insertGetId([
                    'vehicle_id'          => $vehicleId,
                    'user_id'             => $riderId,
                    'visit_date'          => $date,
                    'visit_time'          => $in['visit_time'] ?? null,
                    'workshop'            => isset($in['workshop']) ? mb_substr(trim((string) $in['workshop']), 0, 120) : null,
                    'location_id'         => !empty($in['location_id']) ? (int) $in['location_id'] : null,
                    'purpose'             => $purpose,
                    'maintenance_type_id' => $purpose === 'service' && !empty($in['maintenance_type_id'])
                                             ? (int) $in['maintenance_type_id'] : null,
                    'ticket_id'           => !empty($in['ticket_id']) ? (int) $in['ticket_id'] : null,
                    'note'                => isset($in['note']) ? mb_substr(trim((string) $in['note']), 0, 255) : null,
                    'status'              => $proposed ? 'proposed' : 'scheduled',
                    'created_by'          => (int) $user->id,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ] + ($proposed ? ['proposed_by' => (int) $user->id] : []));

                if ($existing) {
                    DB::table(self::T_VISIT)->where('id', $existing->id)->update([
                        'status'        => 'rescheduled',
                        'superseded_by' => $visitId,
                        'updated_at'    => $now,
                    ]);
                    $replaced = (int) $existing->id;
                }
            });

            /**
             * ⭐ The ticket thread is the audit trail — a visit scheduled off a complaint
             *   must be visible to the rider who complained, in the place he is watching.
             *
             * ⚠⚠ NOT WHILE IT IS ONLY A PROPOSAL. The rider reads that thread. Writing
             *    "Workshop visit set for Sat 7 Sep" into it would tell him the one thing the
             *    whole ruling exists to withhold — and would tell him a date that a planner
             *    may yet decline. The line is written on APPROVAL instead, which is the
             *    moment it becomes true.
             */
            if (!$proposed) {
                $this->linkTicket((int) $visitId, $in, $user, $date, $in['visit_time'] ?? null);
            }

            /**
             * 📍 PHASE 4. A visit booked at a REGISTERED workshop makes that workshop the
             * rider's shift location for that one day, so checking in there is on time by
             * itself. Opt-in: no `location_id`, no override, nothing changes.
             * ⚠ The row it replaces is cleared first — a MOVED visit must not leave the old
             *   day pointing at a workshop nobody is going to any more.
             * ⚠⚠ A PROPOSAL PINS NOTHING. Approval is precisely the gate on this write: until
             *    a planner says yes, the rider's day is untouched and he is measured against
             *    his normal place, exactly as if nobody had booked anything.
             */
            if ($replaced) $this->clearShiftLocation($replaced, $riderId);
            // ⚠ $user is passed so the SHIFT RULES apply to this door too — see applyShiftLocation.
            $pinned = !$proposed && $this->applyShiftLocation((int) $visitId, $riderId, $date,
                                                !empty($in['location_id']) ? (int) $in['location_id'] : null,
                                                null, $user);

            return [
                'ok'               => true,
                'visit_id'         => (int) $visitId,
                'rescheduled_from' => $replaced,
                'warnings'         => $warnings,
                // ⭐ The controller reads this to decide WHO gets pushed: the planners
                //   ("approve this"), or the rider ("this is your day").
                'proposed'         => $proposed,
                // Say it plainly: this is the difference between "he must not be marked late"
                // being handled by the system and being a job the planner still has to do.
                'shift_location_set' => $pinned,
                'message'          => $proposed
                    ? ('Sent for approval. ' . $this->nameOf($riderId) . ' has NOT been told yet — '
                        . 'a shift planner has to approve it first.'
                        . (!empty($in['location_id']) ? '' : ' ⚠ No workshop was chosen, so nothing '
                            . 'will be pinned to his day even after approval.'))
                    : (($replaced
                        ? 'Moved. ' . $this->nameOf($riderId) . ' has to accept the new date.'
                        : 'Set. ' . $this->nameOf($riderId) . ' will be asked to accept it.')
                    . ($pinned
                        ? ' That day he checks in at the workshop, so he will not be marked late or remote.'
                        // ⚠⚠ 6-Sep: this used to say NOTHING when nothing was pinned, which is
                        //    how Danish was booked into a workshop while his day still pointed
                        //    at LaCarne and nobody could tell from the confirmation.
                        : ' ⚠ ' . ($this->lastPinNote ?: 'His check-in place was not changed.'))),
            ];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::schedule failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not set that date.'];
        }
    }

    /**
     * ⭐ WARN, NEVER BLOCK (owner intent). A workshop appointment on a rider's off day is
     *   unusual but legitimate — the manager may have agreed it with him. What is NOT
     *   acceptable is setting it without noticing. So these are returned for the UI to
     *   show, and the visit is created either way.
     *
     * @return array<int, string>
     */
    /**
     * The bike's APPROVED (live) workshop day, if it has one — the thing a new booking would
     * replace. Returns null when there is none, and never counts the visit being worked on.
     *
     * ⭐ One source for all three places that must agree about "he already has a day":
     *   the booker's warning, the approver's card, and the swap inside `approve()`.
     */
    public function liveVisitFor(int $vehicleId, ?int $exceptVisitId = null): ?array
    {
        if (!$vehicleId || !$this->available()) return null;
        try {
            $q = DB::table(self::T_VISIT)
                ->where('vehicle_id', $vehicleId)
                ->whereIn('status', self::LIVE_STATUSES);
            if ($exceptVisitId) $q->where('id', '<>', $exceptVisitId);
            $r = $q->orderByDesc('id')->first(['id', 'user_id', 'visit_date', 'visit_time', 'workshop', 'status']);
            if (!$r) return null;
            return [
                'id'         => (int) $r->id,
                'user_id'    => (int) $r->user_id,
                'visit_date' => substr((string) $r->visit_date, 0, 10),
                'visit_time' => $r->visit_time ? substr((string) $r->visit_time, 0, 5) : null,
                'workshop'   => $r->workshop,
                'accepted'   => (string) $r->status === 'accepted',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * One human sentence for "he already has an approved day", used by the warning, the
     * approval card and the pushes so all three say the same thing.
     * ⚠ English lead, Roman Urdu for the DETAIL — owner ruling 7-Sep. The rider must be
     *   re-told, and that is the part a manager has to actually take in.
     */
    public function replacementNoticeFor(?array $live): ?string
    {
        if (!$live) return null;
        $when = \Carbon\Carbon::parse($live['visit_date'])->format('D j M')
              . ($live['visit_time'] ? ' ' . $live['visit_time'] : '');
        return 'He already has an approved workshop day: ' . $when
             . ($live['workshop'] ? ' · ' . $live['workshop'] : '')
             . ($live['accepted'] ? ' (he has confirmed it)' : '')
             . '. Yeh us din ko badal dega — usko dobara batana parega.';
    }

    public function warningsFor(int $vehicleId, int $riderId, string $date): array
    {
        $out = [];
        // ⚠⚠ FIRST, because it is the only warning about something that ALREADY EXISTS and
        //    that somebody has already been told. The others are about the day being awkward.
        $notice = $this->replacementNoticeFor($this->liveVisitFor($vehicleId));
        if ($notice) $out[] = $notice;
        try {
            $kind = (new ShiftResolutionService())->dayKind($riderId, $date);
            if ($kind === 'off')        $out[] = 'That is his weekly off day.';
            if ($kind === 'holiday')    $out[] = 'That is a public holiday.';
            if ($kind === 'not_joined') $out[] = 'He had not joined by that date.';
            if ($kind === 'not_needed') $out[] = 'He is marked "not needed" that day.';
        } catch (\Throwable $e) {
            // A warning is advisory; never let it fail the scheduling.
        }
        try {
            $keeper = (new VehicleResolver())->riderForVehicleDay($vehicleId, $date);
            if ($keeper && (int) $keeper !== $riderId) {
                $out[] = 'On that day the registry says ' . $this->nameOf((int) $keeper) . ' has this bike.';
            }
        } catch (\Throwable $e) {
        }
        try {
            $leave = DB::table('t_req_master')
                ->where('requester_user_id', $riderId)
                ->where('status', 'approved')
                ->whereNotNull('leave_start_date')
                ->whereDate('leave_start_date', '<=', $date)
                ->whereDate('leave_end_date', '>=', $date)
                ->exists();
            if ($leave) $out[] = 'He has approved leave on that date.';
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** Does this install have the Phase-4 workshop label yet? */
    public function locationsEnabled(): bool
    {
        try {
            return Schema::hasColumn('t_ops_company_locations', 'is_workshop');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The registered workshops, for the "which workshop?" picker. */
    public function workshopLocations(): array
    {
        if (!$this->locationsEnabled()) return [];
        try {
            return DB::table('t_ops_company_locations')
                ->where('is_active', 1)->where('is_workshop', 1)
                ->orderBy('location_name')
                ->get(['id', 'location_name'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->location_name])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 📍 PHASE 4 — make the workshop that day's SHIFT LOCATION.
     *
     * ⭐⭐ WHY THIS IS ENOUGH. Attendance already resolves the check-in base from the rider's
     *    shift for the day: check-in → `ShiftResolutionService::getUserShift()` →
     *    `resolveLocation()` → the assignment's location → `LocationService`. So a rider who
     *    checks in AT the workshop is measured against the WORKSHOP and picks up no remote /
     *    late flag — with no change to attendance code at all. Owner intent, 2-Sep: "later
     *    workshop will be added as the shift location".
     *
     * ⚠⚠ THE SHIFT TIMES ARE NOT TOUCHED. The override reuses the template he was already on
     *    that day and changes ONLY `location_id`. A workshop day is a normal paid working day
     *    (owner ruling) — this must never become a way to shorten or move his hours.
     *
     * ⚠ `notified_at` is left NULL ON PURPOSE, so this does NOT raise the "your shift is
     *   changing — confirm" banner. He already confirms the VISIT; asking twice for one fact
     *   is how people start ignoring both.
     *
     * ⚠ It NEVER overwrites an override the planner made himself. If a bounded row already
     *   covers that day, his decision stands and this returns false — a scheduling convenience
     *   must not silently undo a human's deliberate change.
     *
     * ⚠ Bounded to exactly one day (from = to = the visit date) and removed again by
     *   `clearShiftLocation()` on cancel / reschedule, so nothing outlives the visit.
     */
    /**
     * @param int|null $templateId ⭐ 6-Sep: the approving planner may ALSO move the rider's
     *        start time for that one day — the case Danish exposed, whose visit was booked
     *        for 09:00 while his shift still started 09:30 at LaCarne. Given, this template
     *        replaces the one he was on; omitted, the times are untouched exactly as before.
     *        ⚠ Only a planner ever reaches this argument (see `approve()`); booking cannot.
     */
    private function applyShiftLocation(int $visitId, int $userId, string $date, ?int $locationId, ?int $templateId = null, $actor = null): bool
    {
        $this->lastPinNote = null;

        /**
         * ⚠⚠ THE SHIFT RULES APPLY HERE TOO (Sep-2026). This method writes a real one-day row
         *    into `t_ops_user_shift_assignment` WITHOUT going through the gated
         *    `ShiftController` engine, so it is a second door onto somebody's working day —
         *    and the shift-authority round would have left it unwatched.
         *
         *    Three things it let through before this check, all found by
         *    `probe_workshop_shift_seam.php`:
         *      • FAROOQ HOLDS A BIKE, so a visit can be booked for him — and as a planner he
         *        could approve it himself, writing his own one-day shift row. The one rule the
         *        owner cared about most ("nobody sets his own shift") went out of this door.
         *      • a planner could pin somebody ABOVE him on the ladder;
         *      • the Adjust picker could move a rider onto a shift OUTSIDE the allowed list
         *        Taimur set for him — refused by the planner screens, accepted here.
         *
         * ⭐ Only a DENY blocks. An "approval needed" verdict is fine: this pin IS a planner
         *   approving, and demanding a second approval to move a check-in place would make the
         *   ordinary workshop day unusable. For a plain rider — the overwhelming case — the
         *   gate answers ALLOW and nothing about today's behaviour changes.
         */
        if ($actor) {
            try {
                $gate = app(\App\Services\Ops\ShiftAuthorityService::class)
                    ->decide($actor, $userId, $templateId, 'assign');
                if ($gate['verdict'] === \App\Services\Ops\ShiftAuthorityService::DENY) {
                    $this->lastPinNote = 'His check-in place was NOT moved — ' . lcfirst((string) $gate['message'])
                        . ' The visit is approved; ask someone who can change that day to pin it.';
                    return false;
                }
            } catch (\Throwable $e) {
                // The rules are an extra guard, never a reason the workshop flow breaks.
                Log::warning('Shift-authority check skipped on workshop pin', ['error' => $e->getMessage()]);
            }
        }

        if (!$locationId) {
            $this->lastPinNote = 'No registered workshop was chosen, so his check-in place is '
                . 'unchanged — he will be measured against his normal location that day.';
            return false;
        }
        try {
            if (!Schema::hasTable('t_ops_user_shift_assignment')) return false;

            // A row the planner created for that day wins — see the note above.
            $existing = DB::table('t_ops_user_shift_assignment')
                ->where('user_id', $userId)
                ->whereNotNull('effective_to')
                ->whereDate('effective_from', '<=', $date)
                ->whereDate('effective_to', '>=', $date)
                ->first(['id', 'workshop_visit_id']);
            if ($existing && empty($existing->workshop_visit_id)) {
                /**
                 * ⚠⚠ AND SAY SO OUT LOUD (6-Sep). A human's row still wins — that has not
                 *    changed — but returning a bare false made this the second silent way to
                 *    reach "the visit exists and nothing is pinned", which is the exact
                 *    failure the approval round was built to end. The approver is now told,
                 *    in the same breath as his approval, that he has to move that day
                 *    himself.
                 */
                $this->lastPinNote = 'A shift change already exists for that day, made by hand — '
                    . 'it was left alone, so his check-in place was NOT moved to the workshop. '
                    . 'Change that day on the shift planner if he should check in there.';
                return false;
            }

            // The template he is ALREADY on that day — so only the place changes — unless a
            // planner deliberately chose a different one while approving.
            if (!$templateId) {
                $shift = (new ShiftResolutionService())->getUserShift($userId, $date);
                $templateId = $shift['shift_id'] ?? null;
            }
            if (!$templateId) {
                // Same principle as above: a silent "nothing happened" is what we are ending.
                $this->lastPinNote = 'He has no shift on that day, so there was nothing to pin the '
                    . 'workshop to — give him a shift for that day if he must check in there.';
                return false;   // cannot pin a location with no shift to pin it to
            }

            $row = [
                'user_id'           => $userId,
                'shift_template_id' => (int) $templateId,
                'location_id'       => $locationId,
                'effective_from'    => $date,
                'effective_to'      => $date,
                'workshop_visit_id' => $visitId,
                'notified_at'       => null,      // ⚠ no second confirm — see the note above
                'acknowledged_at'   => null,
                'updated_at'        => now(),
            ];
            if ($existing) {
                DB::table('t_ops_user_shift_assignment')->where('id', $existing->id)->update($row);
            } else {
                DB::table('t_ops_user_shift_assignment')->insert($row + ['created_at' => now()]);
            }
            (new ShiftResolutionService())->clearUserShiftCache($userId);
            return true;
        } catch (\Throwable $e) {
            // A convenience must never fail the scheduling it decorates.
            Log::warning('Workshop shift-location override not written',
                ['visit' => $visitId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Remove the one-day override a visit created. Only ever deletes OUR OWN row. */
    private function clearShiftLocation(int $visitId, int $userId): void
    {
        try {
            if (!Schema::hasTable('t_ops_user_shift_assignment')) return;
            if (!Schema::hasColumn('t_ops_user_shift_assignment', 'workshop_visit_id')) return;
            $n = DB::table('t_ops_user_shift_assignment')
                ->where('workshop_visit_id', $visitId)->delete();
            if ($n) (new ShiftResolutionService())->clearUserShiftCache($userId);
        } catch (\Throwable $e) {
            Log::warning('Workshop shift-location override not cleared',
                ['visit' => $visitId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Write the visit into the ticket's thread, when it came from one.
     *
     * @param string $verb ⭐ 6-Sep: "set for" when a planner assigned it directly, "approved
     *        for" when it arrived through the approval flow. The rider reads this thread, so
     *        it should say what actually happened — and it is only ever written at the moment
     *        the date becomes real (never for a proposal, see `schedule()`).
     */
    private function linkTicket(int $visitId, array $in, $user, string $date, ?string $time, string $verb = 'set for'): void
    {
        $ticketId = (int) ($in['ticket_id'] ?? 0);
        if (!$ticketId) return;
        try {
            $tickets = app(VehicleTicketService::class);
            if (!$tickets->available()) return;
            DB::table(VehicleTicketService::T_TICKET)->where('id', $ticketId)->update([
                'workshop_visit_id' => $visitId,
                // ⚠ 'scheduled' outranks 'acknowledged'; VehicleTicketService::reply
                //   deliberately never knocks it back down when a manager chats.
                'status'            => 'scheduled',
                'last_message_at'   => now(),
                'updated_at'        => now(),
            ]);
            $tickets->system($ticketId, 'Workshop visit ' . $verb . ' '
                . \Carbon\Carbon::parse($date)->format('D j M')
                . ($time ? ' at ' . substr((string) $time, 0, 5) : '')
                . ' by ' . $this->nameOf((int) $user->id) . '.');
        } catch (\Throwable $e) {
            Log::warning('Ticket not linked to workshop visit', ['visit' => $visitId, 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  ACCEPTING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⭐⭐ OWNER RULING (2-Sep): the RIDER accepts. A manager may stand in only while the
     *    visit is still awaiting acceptance, and it is recorded as `accepted_via=manager`
     *    so no screen can present it as the rider's own confirmation.
     */
    public function accept($user, int $visitId, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];

        $uid       = (int) $user->id;
        $isRider   = (int) $v['user_id'] === $uid;
        $isManager = $this->canSchedule($user, $mobile);

        if (!$isRider && !$isManager) {
            return ['ok' => false, 'message' => 'That visit is not yours.'];
        }
        if ($v['status'] === 'accepted') return ['ok' => false, 'message' => 'Already accepted.'];
        if (!in_array($v['status'], self::LIVE_STATUSES, true)) {
            return ['ok' => false, 'message' => 'That visit is no longer active.'];
        }

        try {
            DB::table(self::T_VISIT)->where('id', $visitId)->update([
                'status'       => 'accepted',
                'accepted_at'  => now(),
                'accepted_via' => $isRider ? 'app' : 'manager',
                'accepted_by'  => $uid,
                'updated_at'   => now(),
            ]);
            return ['ok' => true, 'message' => $isRider
                ? 'Confirmed. You are expected at the workshop that day.'
                : 'Marked as accepted for ' . $this->nameOf((int) $v['user_id']) . '.'];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::accept failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not confirm it.'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  APPROVAL — the 6-Sep ruling. A booked workshop day is a REQUEST until a shift
    //  planner says yes; only then is anything pinned and only then is the rider told.
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⭐⭐ APPROVE, optionally ADJUSTING it on the way through.
     *
     * A planner may change three things while approving, because he is the person who
     * knows the day:
     *   • which workshop  — `location_id` (must be a REGISTERED one, or nothing can pin)
     *   • the appointment — `visit_time`
     *   • the rider's start time that day — `shift_template_id`. ⭐ This is the case
     *     Danish exposed on 6-Sep: his visit was booked for 09:00 while his shift still
     *     began 09:30, so even a correct pin would have measured him against the wrong
     *     hour. Nothing else in the flow could fix that, and the planner is exactly who
     *     should.
     *
     * ⚠ Approving is the ONLY thing that writes the pin, tells the rider, and touches the
     *   ticket thread. Everything the rider can see hangs off this one call.
     */
    public function approve($user, int $visitId, array $in = [], bool $mobile = false): array
    {
        if (!$this->available())        return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->approvalEnabled())  return ['ok' => false, 'message' => 'The approval step is not installed yet (workshop_approval_sep2026.sql has not been run).'];
        if (!$this->canApprove($user, $mobile)) {
            return ['ok' => false, 'message' => 'Only a shift planner can approve a workshop day.'];
        }

        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That request no longer exists.'];
        if ((string) $v['status'] !== 'proposed') {
            // ⚠ Three planners see the same banner; two of them will press it at once.
            //   Say which of the two things happened rather than a flat refusal.
            return ['ok' => false, 'message' => (string) $v['status'] === 'scheduled'
                ? 'Someone has already approved that one.'
                : 'That request is no longer waiting — it is ' . $v['status'] . '.'];
        }

        $date   = substr((string) $v['visit_date'], 0, 10);
        $today  = \Carbon\Carbon::today()->format('Y-m-d');
        if ($date < $today) {
            // ⚠ Approving a dead date would tell a rider to go somewhere yesterday.
            return ['ok' => false, 'message' => 'That day has already passed — decline it and book a new date.'];
        }
        /**
         * ⚠⚠ AND THE SAME-DAY CUT-OFF (6-Sep review). Without this a planner could approve at
         *    11:00 for a 09:30 shift the rider has already checked in for at LaCarne — the pin
         *    would move under him and he would read as remote where he actually is. Same clock
         *    as the sweep, so "too late" means one thing.
         */
        $cutoff = $this->approvalCutoffFor($v);
        if (\Carbon\Carbon::now()->gte($cutoff)) {
            return ['ok' => false, 'message' => 'Too late for ' . \Carbon\Carbon::parse($date)->format('D j M')
                . ' — the cut-off was ' . $cutoff->format('H:i') . ', so he could not have been told in time. '
                . 'Decline it and book a new date.'];
        }

        // ── the adjustments ──────────────────────────────────────────────────────
        $locationId = array_key_exists('location_id', $in) && $in['location_id'] !== null && $in['location_id'] !== ''
            ? (int) $in['location_id']
            : (!empty($v['location_id']) ? (int) $v['location_id'] : null);
        if ($locationId && !$this->isWorkshopLocation($locationId)) {
            return ['ok' => false, 'message' => 'That place is not ticked as a workshop, so his day could not be pinned to it.'];
        }

        $time = $v['visit_time'];
        if (array_key_exists('visit_time', $in)) {
            $t = trim((string) $in['visit_time']);
            if ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t)) {
                return ['ok' => false, 'message' => 'Give the time as HH:MM.'];
            }
            $time = $t !== '' ? $t : null;
        }

        $templateId = !empty($in['shift_template_id']) ? (int) $in['shift_template_id'] : null;
        if ($templateId && !$this->shiftTemplateExists($templateId)) {
            return ['ok' => false, 'message' => 'That shift does not exist any more.'];
        }

        try {
            $riderId = (int) $v['user_id'];
            $claimed = DB::table(self::T_VISIT)->where('id', $visitId)->where('status', 'proposed')->update([
                'status'      => 'scheduled',
                'location_id' => $locationId,
                'visit_time'  => $time,
                'approved_by' => (int) $user->id,
                'approved_at' => now(),
                /**
                 * ⚠⚠ CLEAR THE NUDGE FLAG. `reminded_at` is shared with the day-before rider
                 *    reminder (one column, two sweeps — see `escalateProposals`). A proposal
                 *    that was nudged at 17:00 yesterday would otherwise have this set, and
                 *    `dueReminders()` would skip the rider's "kal workshop jana hai" for the
                 *    very visit that most needs it.
                 */
                'reminded_at' => null,
                'updated_at'  => now(),
            ]);

            /**
             * ⚠⚠ THE UPDATE IS THE CLAIM — read whether it actually won. `where(status =
             *    proposed)` was already the right filter, but the affected-row count was
             *    thrown away, so TWO planners pressing Approve within the same moment both
             *    fell through to the pin, the ticket line and the pushes: the rider got told
             *    twice, the booker got told twice, and the thread grew two "approved for"
             *    lines for one decision. Losing the race is not an error — it just means
             *    somebody else already said yes.
             */
            if (!$claimed) {
                return ['ok' => false, 'message' => 'Someone else has just answered this one.'];
            }

            /**
             * ⭐⭐ THE SWAP HAPPENS HERE, NOT AT BOOKING (owner ruling 7-Sep). If this bike
             *    still has an APPROVED day standing, approving the replacement is the moment
             *    it is retired: its pin is cleared, it is marked `rescheduled`, and the rider
             *    is told his day MOVED rather than being left believing the old one.
             * ⚠ Captured BEFORE the pin is written, or the two would fight over the same day.
             */
            $movedFrom = $this->liveVisitFor((int) ($v['vehicle_id'] ?? 0), $visitId);
            if ($movedFrom) {
                DB::table(self::T_VISIT)->where('id', $movedFrom['id'])->update([
                    'status'        => 'rescheduled',
                    'superseded_by' => $visitId,
                    'updated_at'    => now(),
                ]);
                $this->clearShiftLocation((int) $movedFrom['id'], $riderId);
            }

            // NOW the two things a proposal deliberately did not do.
            $pinned  = $this->applyShiftLocation($visitId, $riderId, $date, $locationId, $templateId, $user);
            $pinNote = $this->lastPinNote;
            $this->linkTicket($visitId, ['ticket_id' => $v['ticket_id'] ?? null], $user, $date, $time, 'approved for');

            return [
                'ok'         => true,
                'visit_id'   => $visitId,
                'rider_id'   => $riderId,
                'pinned'     => $pinned,
                'booked_by'  => (int) ($v['proposed_by'] ?? $v['created_by'] ?? 0),
                'message'    => 'Approved. ' . $this->nameOf($riderId) . ' has been told'
                    . ($pinned
                        ? ' — that day he checks in at the workshop, so he will not be marked late or remote.'
                        : '. ⚠ ' . ($pinNote ?: 'His check-in place was not changed.')),
            ];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::approve failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not approve it.'];
        }
    }

    /**
     * The planner says no. The reason travels back to whoever booked it, so he can
     * re-propose a day that works instead of guessing.
     *
     * ⚠ Nothing was pinned and the rider was never told, so declining has NOTHING to undo
     *   — which is the whole reason the proposal state is worth having.
     */
    public function decline($user, int $visitId, ?string $reason, bool $mobile = false): array
    {
        if (!$this->available())       return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->approvalEnabled()) return ['ok' => false, 'message' => 'The approval step is not installed yet.'];
        if (!$this->canApprove($user, $mobile)) {
            return ['ok' => false, 'message' => 'Only a shift planner can decline a workshop day.'];
        }
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That request no longer exists.'];
        if ((string) $v['status'] !== 'proposed') {
            return ['ok' => false, 'message' => 'That request is no longer waiting — it is ' . $v['status'] . '.'];
        }
        if (!$this->declineRow($visitId, (int) $user->id, $reason ?: 'No reason given.')) {
            return ['ok' => false, 'message' => 'Could not decline it.'];
        }
        return [
            'ok'        => true,
            'visit_id'  => $visitId,
            'booked_by' => (int) ($v['proposed_by'] ?? $v['created_by'] ?? 0),
            'message'   => 'Declined. ' . $this->nameOf((int) ($v['proposed_by'] ?? $v['created_by'] ?? 0))
                           . ' has been told, and ' . $this->nameOf((int) $v['user_id']) . ' was never told anything.',
        ];
    }

    /**
     * The one writer for "this proposal is dead". Used by the planner's Decline button and
     * by the morning sweep, so the two can never drift apart.
     * `$byUserId` is NULL when it was time, not a person, that killed it.
     */
    private function declineRow(int $visitId, ?int $byUserId, string $reason): bool
    {
        try {
            $n = DB::table(self::T_VISIT)->where('id', $visitId)->where('status', 'proposed')->update([
                'status'         => 'declined',
                'declined_by'    => $byUserId,
                'declined_at'    => now(),
                'decline_reason' => mb_substr(trim($reason), 0, 255),
                'updated_at'     => now(),
            ]);
            return $n > 0;
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::declineRow failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Is this location one a workshop day can actually be pinned to? */
    public function isWorkshopLocation(int $locationId): bool
    {
        if (!$this->locationsEnabled()) return false;
        try {
            return DB::table('t_ops_company_locations')
                ->where('id', $locationId)->where('is_active', 1)->where('is_workshop', 1)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function shiftTemplateExists(int $templateId): bool
    {
        try {
            return DB::table('t_ops_shift_template')->where('id', $templateId)->where('active', 1)
                ->when($this->shiftTypesCarryApproval(), fn ($q) => $q->where('approval_status', 'approved'))
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The shifts a planner may put the rider on for that one day (the Adjust picker).
     *
     * ⚠⚠ A shift TYPE still WAITING for approval must never appear here. Since the
     *    shift-authority round (Sep-2026) a type can be `proposed`, and every other picker in
     *    the app filters those out — this one did not, so a planner could pin a rider onto a
     *    set of hours nobody had approved, through a door the shift rules do not watch.
     *    Schema-guarded: before `shift_authority_sep2026.sql` the column does not exist and
     *    every type is assignable, exactly as before.
     */
    public function shiftTemplates(): array
    {
        try {
            return DB::table('t_ops_shift_template')->where('active', 1)
                ->when($this->shiftTypesCarryApproval(), fn ($q) => $q->where('approval_status', 'approved'))
                ->orderBy('shift_start')
                ->get(['id', 'shift_name', 'shift_start'])
                ->map(fn ($t) => [
                    'id'    => (int) $t->id,
                    'name'  => (string) $t->shift_name,
                    'start' => $t->shift_start ? substr((string) $t->shift_start, 0, 5) : null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Does ANYONE hold the approve permission? Checks both halves — a planner reaching the
     * desk needs the web row, one reaching it from the phone needs the mobile row, and either
     * is enough to mean "somebody can answer a proposal".
     * ⚠ Memoized per request; this is asked from a poll, not from a loop.
     */
    private ?bool $anyApprover = null;
    private function anyoneCanApprove(): bool
    {
        if ($this->anyApprover !== null) return $this->anyApprover;
        $found = false;
        try {
            $found = DB::table('t_sys_role_permissions')
                ->where('permission_key', self::APPROVE_PERMISSION)->where('is_allowed', 1)->exists();
            if (!$found) {
                $found = DB::table('t_sys_role_mobile_permission as rmp')
                    ->join('t_sys_mobile_permission as mp', 'mp.id', '=', 'rmp.mobile_permission_id')
                    ->where('mp.permission_code', self::APPROVE_PERMISSION)->exists();
            }
        } catch (\Throwable $e) {
            // If the question cannot be answered, assume somebody can — the old behaviour.
            $found = true;
        }
        return $this->anyApprover = $found;
    }

    /** Memoized: do shift types carry an approval state on this server yet? */
    private ?bool $shiftApprovalCol = null;
    private function shiftTypesCarryApproval(): bool
    {
        if ($this->shiftApprovalCol === null) {
            try { $this->shiftApprovalCol = Schema::hasColumn('t_ops_shift_template', 'approval_status'); }
            catch (\Throwable $e) { $this->shiftApprovalCol = false; }
        }
        return $this->shiftApprovalCol;
    }

    /**
     * What the planners' banner shows: every workshop day still waiting on one of them,
     * soonest first, each carrying the same warnings the booker was shown — the planner is
     * the person who should see "that is his day off" before he says yes.
     *
     * ⚠ Returns [] for anyone who is not a planner. This is a list of other people's days.
     */
    public function pendingApprovals($user, bool $mobile = false, int $limit = 20): array
    {
        if (!$this->approvalEnabled() || !$this->canApprove($user, $mobile)) return [];
        $rows = $this->listVisits(['statuses' => ['proposed'], 'limit' => $limit]);
        foreach ($rows as &$r) {
            $r['warnings'] = $this->warningsFor((int) $r['vehicle_id'], (int) $r['user_id'], $r['visit_date']);
            // ⏰ So the card can say "decide by 08:30" instead of leaving the planner to guess.
            $r['approve_by'] = $this->approvalCutoffFor($r)->format('Y-m-d H:i');
            /**
             * ⭐ WHAT APPROVING WOULD REPLACE. The approver must know he is not just saying
             *   yes to a new day — he is retiring one the rider has already been told about,
             *   and that rider has to be told again. Without this the card looks identical
             *   whether it is a first booking or a move.
             */
            $live = $this->liveVisitFor((int) $r['vehicle_id'], (int) $r['id']);
            $r['replaces'] = $live ? [
                'visit_date' => $live['visit_date'],
                'visit_time' => $live['visit_time'],
                'workshop'   => $live['workshop'],
                'accepted'   => $live['accepted'],
                'label'      => \Carbon\Carbon::parse($live['visit_date'])->format('D j M')
                                . ($live['visit_time'] ? ' ' . $live['visit_time'] : '')
                                . ($live['workshop'] ? ' · ' . $live['workshop'] : ''),
            ] : null;
        }
        unset($r);
        return $rows;
    }

    /**
     * ⭐⭐ SILENCE MUST NEVER SEND A RIDER ANYWHERE — and it must never quietly cancel a
     *    plan either. Prod has no cron (see the class note), so this rides the alerts poll
     *    exactly as `dueReminders()` does:
     *
     *      • a proposal for TOMORROW, still waiting at or after 17:00 → nudge the planners
     *        ONCE (flagged with `reminded_at`, which a proposal does not otherwise use;
     *        `approve()` clears it again so the rider's own day-before reminder still fires);
     *      • a proposal whose day has ARRIVED unapproved → auto-DECLINE, and tell the booker.
     *
     * ⚠⚠ AUTO-DECLINE, NEVER AUTO-APPROVE. An unapproved proposal is a question nobody
     *    answered; turning that into "go to the workshop tomorrow" would send a rider to a
     *    place no planner ever agreed to, on the strength of nobody having looked.
     * ⚠ A proposal made TODAY for TODAY is left alone — it has not had its day yet. Only one
     *   that has been waiting since before today is killed by the morning.
     *
     * @return array{nudge: array<int,int>, declined: array<int,int>}
     */
    public function escalateProposals(): array
    {
        $out = ['nudge' => [], 'declined' => []];
        if (!$this->approvalEnabled()) return $out;

        /**
         * ⚠⚠ A DEPLOY LANDMINE, GUARDED HERE. `approvalEnabled()` only asks whether PART 1 of
         *    `workshop_approval_sep2026.sql` (the columns) has run. PART 2 seeds the WEB half
         *    of `manage_shifts`, and the file's own note says a re-run dies mid-way on
         *    `1060 Duplicate column name` — so "columns applied, permission not" is a live
         *    possibility. In that state `canApprove()` is false for everyone on the desk:
         *    every booking becomes a proposal, nobody can answer one, and this sweep would
         *    quietly auto-decline the lot, an hour before each rider's shift.
         *
         *    So: if NOBODY can approve, the round is misconfigured, not overdue. Leave the
         *    proposals standing — they pile up visibly, which is how the misconfiguration
         *    gets noticed — and never turn silence into a decision.
         */
        if (!$this->anyoneCanApprove()) {
            Log::warning('Workshop proposals not escalated: nobody holds the approve permission '
                . '(is PART 2 of workshop_approval_sep2026.sql applied?)');
            return $out;
        }
        try {
            $now      = \Carbon\Carbon::now();
            $today    = $now->copy()->startOfDay();
            $tomorrow = $today->copy()->addDay()->format('Y-m-d');

            if ((int) $now->format('H') >= 17) {
                $ids = DB::table(self::T_VISIT)
                    ->where('status', 'proposed')->whereNull('reminded_at')
                    ->whereDate('visit_date', $tomorrow)
                    ->pluck('id')->map('intval')->all();
                if ($ids) {
                    DB::table(self::T_VISIT)->whereIn('id', $ids)->update(['reminded_at' => now()]);
                    $out['nudge'] = $ids;
                }
            }

            /**
             * ⭐ OWNER RULING (6-Sep, on review): give the planners until just before the
             *   rider's shift, not just until midnight. Candidates are every proposal dated
             *   today-or-earlier; each is then measured against ITS OWN cut-off (below),
             *   because "before his shift" is a different clock for every rider.
             */
            $candidates = DB::table(self::T_VISIT)
                ->where('status', 'proposed')
                ->whereDate('visit_date', '<=', $today->format('Y-m-d'))
                ->get(['id', 'user_id', 'visit_date', 'visit_time']);
            foreach ($candidates as $c) {
                if ($now->lt($this->approvalCutoffFor((array) $c))) continue;
                if ($this->declineRow((int) $c->id, null,
                        'Not approved in time — nobody approved it before his shift.')) {
                    $out['declined'][] = (int) $c->id;
                }
            }
            if ($out['nudge'] || $out['declined']) {
                Log::info('Workshop proposals escalated', $out);
            }
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::escalateProposals failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * ⭐⭐ THE LAST MOMENT A PROPOSAL CAN STILL BE APPROVED (owner ruling, 6-Sep review):
     *    "right before the assigned shift time — give time till then".
     *
     * Not the shift start itself, but a LEAD before it, because the point of telling the
     * rider is that he rides to the workshop instead of his usual place — so he has to hear
     * before he leaves home. An approval that lands after he has already checked in at
     * LaCarne cannot move that check-in, and would pin his day to a workshop he is not at.
     *
     *   cut-off = min(shift start that day, the appointment time) − WORKSHOP_APPROVAL_LEAD_MIN
     *
     * `WORKSHOP_APPROVAL_LEAD_MIN` lives in `t_fin_config` (default 60) so the owner can change
     * it without a code change, like the other operational knobs. If he has no shift that day
     * (off day, holiday) and no appointment time, 08:00 stands in — nobody should be sent
     * anywhere on an off day by silence either.
     *
     * ⚠ ONE clock for both the morning sweep (`escalateProposals`) and `approve()` — a planner
     *   pressing Approve after the cut-off is refused for the same reason the sweep declines.
     *   Two clocks here would let the answer depend on which one ran first.
     */
    public function approvalCutoffFor(array $v): \Carbon\Carbon
    {
        $date  = substr((string) $v['visit_date'], 0, 10);
        $start = null;
        try {
            $s = (new ShiftResolutionService())->getUserShift((int) $v['user_id'], $date);
            $start = !empty($s['shift_start']) ? substr((string) $s['shift_start'], 0, 5) : null;
        } catch (\Throwable $e) {
        }
        $appt = !empty($v['visit_time']) ? substr((string) $v['visit_time'], 0, 5) : null;
        $times = array_values(array_filter([$start, $appt]));
        $at = $times ? min($times) : '08:00';
        $lead = (int) $this->cfg('WORKSHOP_APPROVAL_LEAD_MIN', 60);
        if ($lead < 0 || $lead > 720) $lead = 60;
        return \Carbon\Carbon::parse($date . ' ' . $at . ':00')->subMinutes($lead);
    }

    private function cfg(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────

    public function cancel($user, int $visitId, ?string $reason, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->canSchedule($user, $mobile)) {
            return ['ok' => false, 'message' => 'You cannot change workshop visits.'];
        }
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];
        // ⚠ A PROPOSAL is cancellable too — the person who booked it may realise the bike
        //   is not going after all, and it should not sit in three planners' banners
        //   waiting to be declined for a reason nobody has any more.
        if (!in_array($v['status'], self::OPEN_STATUSES, true)) {
            return ['ok' => false, 'message' => 'That visit is no longer active.'];
        }
        try {
            DB::table(self::T_VISIT)->where('id', $visitId)->update([
                'status'       => 'cancelled',
                'outcome_note' => $reason ? mb_substr(trim($reason), 0, 500) : null,
                'updated_at'   => now(),
            ]);
            // 📍 A cancelled visit must not leave his shift pointing at a workshop he is no
            //   longer going to — that would measure his check-in against the wrong place.
            $this->clearShiftLocation($visitId, (int) $v['user_id']);
            /**
             * ⚠⚠ NOT FOR A PROPOSAL. The rider reads this thread, and a proposal never wrote
             *    anything into it (see `schedule()`), so "Workshop visit cancelled" would be
             *    the FIRST he ever heard of a visit — announcing a plan by cancelling it.
             */
            if (!empty($v['ticket_id']) && (string) $v['status'] !== 'proposed') {
                app(VehicleTicketService::class)->system((int) $v['ticket_id'],
                    'Workshop visit cancelled by ' . $this->nameOf((int) $user->id)
                    . ($reason ? ' — ' . $reason : '') . '.');
            }
            return ['ok' => true, 'was_proposal' => (string) $v['status'] === 'proposed',
                    'message' => (string) $v['status'] === 'proposed'
                        ? 'Request withdrawn. Nobody had been told.'
                        : 'Cancelled.'];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::cancel failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not cancel it.'];
        }
    }

    /**
     * The work happened.
     *
     * ⭐ This is where a workshop visit becomes a TYPED service record — the loop the
     *   whole round started from. The caller records the service through the normal
     *   `markServiced` door (so every rule about which clock moves stays in one place)
     *   and hands the resulting log id back here to be stored as the visit's outcome.
     */
    /**
     * ⭐⭐ THE ONE GATE for answering a visit ("ho gaya" AND "nahi hua"), returned as an error
     *    message or null. Public because the controller must ask it BEFORE it records the
     *    service and files the bill — the Sep-4 review found done() writing the service log
     *    (and money) first and only then discovering the caller was not allowed, the day had
     *    not come, or the visit was already done (a re-post inserted a fresh log each time).
     */
    public function completionGate($user, array $v, bool $mobile = false): ?string
    {
        $uid       = (int) ($user->id ?? 0);
        $isRider   = (int) $v['user_id'] === $uid;
        $isManager = $this->canSchedule($user, $mobile);
        if (!$isManager && !$isRider) {
            return 'You cannot complete workshop visits.';
        }
        if (!$isManager && substr((string) $v['visit_date'], 0, 10) > \Carbon\Carbon::today()->format('Y-m-d')) {
            return 'That day has not come round yet.';
        }
        if ($v['status'] === 'done') return 'That visit is already marked done.';
        if (!in_array($v['status'], self::LIVE_STATUSES, true)) {
            return 'That visit is no longer active.';
        }
        return null;
    }

    public function markDone($user, int $visitId, array $in, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];

        /**
         * ⭐⭐ WHO MAY CLOSE THE LOOP (owner ruling, 2-Sep): a manager on any visit, AND THE
         *    RIDER on his OWN — "when either Qasim enters the values or the riders enter it
         *    after the service". He holds no fleet key, so the gate is ownership of this
         *    visit, exactly as accept() works.
         *
         * ⚠ He may only answer for a visit that has actually come round — today or a past
         *   one he still has to account for. Letting him close a visit three days early
         *   would turn "did it get done?" into a way to make the instruction disappear.
         */
        if ($err = $this->completionGate($user, $v, $mobile)) {
            return ['ok' => false, 'message' => $err];
        }

        try {
            DB::table(self::T_VISIT)->where('id', $visitId)->update([
                'status'         => 'done',
                'done_at'        => now(),
                'done_by'        => (int) $user->id,
                'outcome_note'   => isset($in['outcome_note']) ? mb_substr(trim((string) $in['outcome_note']), 0, 500) : null,
                'service_log_id' => !empty($in['service_log_id']) ? (int) $in['service_log_id'] : null,
                'request_id'     => !empty($in['request_id']) ? (int) $in['request_id'] : null,
                'updated_at'     => now(),
            ]);

            if (!empty($v['ticket_id'])) {
                $tickets = app(VehicleTicketService::class);
                $tickets->system((int) $v['ticket_id'], 'Workshop visit completed by '
                    . $this->nameOf((int) $user->id)
                    . (!empty($in['outcome_note']) ? ' — ' . $in['outcome_note'] : '') . '.');
                // ⚠ Completing the VISIT does not close the TICKET. Only a manager closes
                //   a ticket (owner ruling), and he may want to hear from the rider that
                //   the fault is actually gone before he does. Put it back where a reply
                //   is expected instead of silently finishing the conversation.
                DB::table(VehicleTicketService::T_TICKET)
                    ->where('id', $v['ticket_id'])->where('status', 'scheduled')
                    ->update(['status' => 'acknowledged', 'updated_at' => now()]);
            }

            return ['ok' => true, 'message' => 'Marked done.'];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::markDone failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not mark it done.'];
        }
    }

    /**
     * "Nahi hua" — the rider (or a manager) reports that the trip did NOT happen.
     *
     * ⚠⚠ Found in the Sep-4 review: the mobile "Nahi hua / sirf dekha gaya" button posted to
     *    /done with only a note, and markDone() unconditionally wrote status = done — so
     *    "he went" and "he never went" became the same row, the exact thing this feature
     *    must never do. This is the distinct path.
     *
     * ⭐ NOTHING IS CLOSED. The instruction still stands: the visit stays LIVE (scheduled /
     *   accepted), so it keeps appearing for the manager as due or missed, and the outcome
     *   prompt returns to the rider until it is either done, cancelled or moved. The answer
     *   is written into outcome_note (dated) and, when the visit came from a ticket, as a
     *   system line on that thread, so the manager can see WHY it did not happen.
     *
     * Same gate as markDone: a manager on any visit, the rider only on his OWN, and only
     * once the day has come round.
     */
    public function reportNotDone($user, int $visitId, ?string $note, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];

        if ($err = $this->completionGate($user, $v, $mobile)) {
            return ['ok' => false, 'message' => $err];
        }
        $uid     = (int) ($user->id ?? 0);
        $isRider = (int) $v['user_id'] === $uid;

        $who  = $isRider ? 'Rider' : $this->nameOf($uid);
        $line = 'Nahi hua (' . $who . ', ' . now()->format('d M H:i') . ')'
            . ($note !== null && trim($note) !== '' ? ': ' . trim($note) : '');
        $prev = trim((string) ($v['outcome_note'] ?? ''));
        $new  = mb_substr(trim($prev !== '' ? $prev . "\n" . $line : $line), -500);

        try {
            DB::table(self::T_VISIT)->where('id', $visitId)->update([
                'outcome_note' => $new,
                'updated_at'   => now(),
            ]);
            if (!empty($v['ticket_id'])) {
                app(VehicleTicketService::class)->system((int) $v['ticket_id'],
                    'Workshop visit of ' . substr((string) $v['visit_date'], 0, 10) . ' did NOT happen — reported by '
                    . $this->nameOf($uid) . ($note !== null && trim($note) !== '' ? ' — ' . trim($note) : '') . '.');
            }
            return [
                'ok'         => true,
                'kept_open'  => true,
                // Rider-facing (Roman Urdu — it tells him what happens next).
                'message'    => 'Theek hai — manager ko bata diya gaya hai. Visit abhi bhi due hai; jab ho jaye tab yahin batayein.',
            ];
        } catch (\Throwable $e) {
            Log::error('WorkshopVisitService::reportNotDone failed', ['visit' => $visitId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not record that.'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  READING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⭐⭐ THE BIKE CHANGED HANDS — its booked workshop day goes with it (owner, 5-Sep-2026:
     *    "the subsequent actions… follow the correct rider so if a new person is assigned to
     *     the bike it follows it").
     *
     * A visit is "take THIS machine in on Friday". It is stored against the rider who was
     * going to take it, but the errand belongs to the machine: when the bike moves to Rajab,
     * Rajab is the one riding it to the workshop on Friday. So every LIVE visit on the machine
     * that is today-or-later is re-pointed to the new holder, and his acceptance is asked for
     * afresh — the old rider's "accepted" was a promise about a bike he no longer has.
     *
     * ⚠ Only today-or-later. A visit dated in the past is history and stays with the man who
     *   (did or did not) do it.
     * ⚠ Taken back to NOBODY: the visit is left as it is — there is nobody to give it to —
     *   and the next assign() will move it, because that fires this hook too.
     * ⚠ Never throws; a note or push must not undo a committed handover.
     *
     * @return int how many visits moved
     */
    public function onHandover(int $vehicleId, ?int $fromUserId, ?int $toUserId, ?int $actorId = null): int
    {
        if (!$this->available() || !$toUserId) return 0;
        try {
            $rows = DB::table(self::T_VISIT)
                ->where('vehicle_id', $vehicleId)
                // ⭐ 6-Sep: a PROPOSAL follows the bike too. The request is "take THIS machine
                //   in on Friday", so the man who will be riding it on Friday is the man the
                //   planner is being asked about — otherwise an approval a day later would
                //   pin the wrong rider's day.
                ->whereIn('status', $this->approvalEnabled() ? self::OPEN_STATUSES : self::LIVE_STATUSES)
                ->whereDate('visit_date', '>=', now()->format('Y-m-d'))
                ->where('user_id', '!=', $toUserId)
                ->get(['id', 'user_id', 'visit_date', 'note', 'location_id', 'status']);
            if ($rows->isEmpty()) return 0;

            $name = function (?int $id): ?string {
                return $id ? DB::table('t_sys_user')->where('id', $id)->value('fullname') : null;
            };
            $from = $name($fromUserId) ?: 'the previous rider';
            $to   = $name($toUserId)   ?: 'the new rider';

            foreach ($rows as $v) {
                $wasProposal = (string) $v->status === 'proposed';
                $line = 'Bike moved from ' . $from . ' to ' . $to . ' on ' . now()->format('d M')
                    . ' — this ' . ($wasProposal ? 'request' : 'visit') . ' is now his.';
                DB::table(self::T_VISIT)->where('id', (int) $v->id)->update([
                    'user_id'      => $toUserId,
                    // ⚠⚠ A PROPOSAL STAYS A PROPOSAL. Promoting it to `scheduled` here would
                    //    hand a rider a workshop day no planner ever approved — a handover
                    //    would have become a back door around the whole ruling.
                    'status'       => $wasProposal ? 'proposed' : 'scheduled',  // else he accepts it himself
                    'accepted_at'  => null,
                    'accepted_by'  => null,
                    'accepted_via' => null,
                    'note'         => trim((string) $v->note . "\n" . $line),
                    'updated_at'   => now(),
                ]);
                /**
                 * ⚠⚠ THE PHASE-4 PIN IS KEYED BY THE RIDER, NOT THE VISIT. It is a one-day row in
                 *    `t_ops_user_shift_assignment` for the OLD rider, pointing his shift at the
                 *    workshop. Re-pointing the visit alone would leave Waseem checking in at a
                 *    workshop he is not going to, and Rajab — who IS going — marked late or
                 *    remote there. Move the pin with the visit: clear his, pin the new holder.
                 */
                $this->clearShiftLocation((int) $v->id, (int) $v->user_id);
                // ⚠ A proposal has no pin to move — approval is what writes one.
                // ⚠⚠ NO actor here, deliberately: this is not a person choosing to change
                //    somebody's day, it is the system keeping an ALREADY-APPROVED pin correct
                //    after a handover. Gating it could leave the new holder measured against a
                //    workshop he is not going to — the exact bug the pin-move exists to prevent.
                if (!empty($v->location_id) && !$wasProposal) {
                    $this->applyShiftLocation((int) $v->id, $toUserId, substr((string) $v->visit_date, 0, 10), (int) $v->location_id);
                }
                try {
                    // Same push it would have got had it been booked for him in the first
                    // place — which for a proposal means the PLANNERS, not the rider.
                    app(\App\Services\FirebaseService::class)->notifyWorkshopVisit(
                        $wasProposal ? 'proposed' : 'scheduled', (int) $v->id, (int) ($actorId ?: 0));
                } catch (\Throwable $e) {
                    Log::warning('Handover workshop push failed', ['visit' => $v->id, 'error' => $e->getMessage()]);
                }
            }
            Log::info('Workshop visits travelled with the machine', [
                'vehicle_id' => $vehicleId, 'from_user' => $fromUserId, 'to_user' => $toUserId, 'visits' => $rows->count(),
            ]);
            return $rows->count();
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::onHandover failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    public function find(int $visitId): ?array
    {
        if (!$this->available()) return null;
        try {
            $r = DB::table(self::T_VISIT)->where('id', $visitId)->first();
            return $r ? (array) $r : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Every live visit, newest date first, shaped for a screen.
     *
     * @param array $opts {user_id?, vehicle_id?, from?, to?, include_done?,
     *                     include_proposed?, statuses?}
     *
     * ⚠⚠ THE DEFAULT IS LIVE-ONLY, AND MUST STAY THAT WAY. Every rider-facing reader goes
     *    through here with no status option, so a proposal is invisible to him unless a
     *    caller deliberately asks for it — `include_proposed` (manager surfaces: the vehicle
     *    panel, the planner cell) or `statuses` (the approval banner). Adding `proposed` to
     *    the default would leak it to the phone of the man it is being decided about.
     */
    public function listVisits(array $opts = []): array
    {
        if (!$this->available()) return [];
        try {
            $q = DB::table(self::T_VISIT . ' as v')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'v.user_id')
                ->leftJoin('t_sys_user as c', 'c.id', '=', 'v.created_by')
                ->leftJoin('t_sys_user as ab', 'ab.id', '=', 'v.accepted_by');

            if (!empty($opts['user_id']))    $q->where('v.user_id', (int) $opts['user_id']);
            if (!empty($opts['vehicle_id'])) $q->where('v.vehicle_id', (int) $opts['vehicle_id']);
            if (!empty($opts['from']))       $q->whereDate('v.visit_date', '>=', $opts['from']);
            if (!empty($opts['to']))         $q->whereDate('v.visit_date', '<=', $opts['to']);

            if (!empty($opts['statuses']) && is_array($opts['statuses'])) {
                $statuses = array_values(array_map('strval', $opts['statuses']));
            } else {
                $statuses = empty($opts['include_done'])
                    ? self::LIVE_STATUSES
                    : ['scheduled', 'accepted', 'done', 'cancelled', 'rescheduled'];
                // ⚠ `declined` is NOT in the history default either. A rider may legitimately
                //   ask this endpoint for his own history, and a request that was proposed for
                //   him and turned down is a conversation between managers that he was
                //   deliberately never part of. Manager surfaces ask for it by `statuses`.
                if (!empty($opts['include_proposed'])) $statuses = array_merge($statuses, ['proposed', 'declined']);
            }
            // ⚠ Never ask for a status the table cannot hold yet — before the approval SQL
            //   a `proposed` filter simply matches nothing, which is the right degradation.
            $q->whereIn('v.status', $statuses);

            $rows = $q->orderBy('v.visit_date')->orderBy('v.id')
                ->limit(min(500, max(1, (int) ($opts['limit'] ?? 200))))
                ->get(['v.*', 'u.fullname as rider_name', 'c.fullname as created_by_name',
                       'ab.fullname as accepted_by_name']);
            if ($rows->isEmpty()) return [];

            $labels = [];
            try {
                $res = new VehicleResolver();
                foreach ($rows->pluck('vehicle_id')->unique() as $vid) {
                    $labels[(int) $vid] = $res->labelFor((int) $vid);
                }
            } catch (\Throwable $e) {
            }

            /**
             * ⚠⚠ ONE QUERY FOR EVERY NAME, not one per row per column. The six approval columns
             *    carry three more user ids each (proposed / approved / declined by), and
             *    resolving them inside `shape()` would have been up to 60 extra queries on
             *    every banner poll once a few weeks of visits carry `approved_by` — the same
             *    N+1 shape the payroll page was 14 s slow for.
             */
            $names = [];
            $ids = [];
            foreach ($rows as $r) {
                foreach (['proposed_by', 'approved_by', 'declined_by'] as $k) {
                    if (!empty($r->$k)) $ids[(int) $r->$k] = true;
                }
            }
            if ($ids) {
                $names = DB::table('t_sys_user')->whereIn('id', array_keys($ids))
                    ->pluck('fullname', 'id')->all();
            }

            return $rows->map(fn ($r) => $this->shape((array) $r, $labels, $names))->values()->all();
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::listVisits failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐ "Did it get done?" — a visit whose day has ARRIVED (today, or past and unanswered)
     *   and which nobody has closed. Drives the rider's completion prompt, and the reason
     *   an end-of-day sweep is NOT needed: the question is derived, so it simply appears
     *   the moment the day turns and stays until answered.
     *
     * ⚠ Deliberately NOT auto-completed at midnight. Auto-marking a visit done would make
     *   "he went" and "he never went" indistinguishable — the one thing this must never do.
     *   Midnight only makes it MISSED, which is a question, not an answer.
     */
    public function awaitingOutcomeFor(int $userId): ?array
    {
        $today = \Carbon\Carbon::today()->format('Y-m-d');
        foreach ($this->listVisits(['user_id' => $userId, 'limit' => 20]) as $v) {
            if ($v['visit_date'] > $today) continue;

            /**
             * ⚠⚠ NOT WHILE HE STILL HAS TO ACCEPT IT (found on the device, 3-Sep).
             *
             *    A visit dated TODAY put BOTH cards on his screen at once: "take the bike to
             *    the workshop — confirm karein" and "workshop aaj — ho gaya?". Asking whether
             *    he has done a thing he has not yet agreed to do is nonsense, and two cards
             *    disagreeing about where he is in the flow is how a rider stops trusting them.
             *
             * ⭐ So: ask for the outcome once he has ACCEPTED — or once the day has PASSED,
             *   because a MISSED visit still needs an answer whether he confirmed it or not.
             *   That keeps exactly one card on screen at every point in the flow.
             */
            if ($v['visit_date'] === $today && !$v['accepted']) continue;

            return $v;
        }
        return null;
    }

    /** The rider's own next live visit — his banner, his vehicle card, his attendance line. */
    public function nextForUser(int $userId): ?array
    {
        // ⚠ `from` = today, so a MISSED visit still shows as "next" until it is dealt with —
        //   a rider who did not go must keep seeing it, not have it silently vanish.
        $rows = $this->listVisits(['user_id' => $userId,
                                   'from' => \Carbon\Carbon::today()->subDays(14)->format('Y-m-d'),
                                   'limit' => 1]);
        return $rows[0] ?? null;
    }

    /**
     * Visits keyed by "userId|Y-m-d" for a date range — the shift planner and the
     * attendance grid each paint a cell from this in ONE query, rather than asking per row.
     *
     * @return array<string, array>
     */
    /**
     * @param bool $includeProposed ⭐ 6-Sep: the SHIFT PLANNER's grid asks for true, because a
     *        proposal is precisely a question addressed to him and his cell is where he is
     *        looking. Everything else — the attendance grid included — leaves it false: a
     *        proposal is not yet a fact about that day and must not be drawn as one.
     */
    public function mapForRange(array $userIds, string $from, string $to, bool $includeProposed = false): array
    {
        if (!$this->available() || !$userIds) return [];
        try {
            $rows = DB::table(self::T_VISIT)
                ->whereIn('user_id', array_map('intval', $userIds))
                ->whereIn('status', $includeProposed && $this->approvalEnabled()
                    ? array_merge(self::LIVE_STATUSES, ['proposed'])
                    : self::LIVE_STATUSES)
                ->whereDate('visit_date', '>=', $from)
                ->whereDate('visit_date', '<=', $to)
                ->get(['id', 'user_id', 'vehicle_id', 'visit_date', 'visit_time', 'status',
                       'purpose', 'workshop', 'accepted_via', 'accepted_by']);
            $labels = [];
            try {
                $res = new VehicleResolver();
                foreach ($rows->pluck('vehicle_id')->unique() as $vid) {
                    $labels[(int) $vid] = $res->labelFor((int) $vid);
                }
            } catch (\Throwable $e) {
            }
            $out = [];
            foreach ($rows as $r) {
                $key = (int) $r->user_id . '|' . substr((string) $r->visit_date, 0, 10);
                $out[$key] = [
                    'id'           => (int) $r->id,
                    'vehicle_id'   => (int) $r->vehicle_id,
                    'vehicle_name' => $labels[(int) $r->vehicle_id] ?? null,
                    'time'         => $r->visit_time ? substr((string) $r->visit_time, 0, 5) : null,
                    'status'       => (string) $r->status,
                    'accepted'     => (string) $r->status === 'accepted',
                    'accepted_via' => $r->accepted_via,
                    'purpose'      => (string) $r->purpose,
                    'workshop'     => $r->workshop,
                    // ⏳ The planner's cell draws this differently: a request, not a plan.
                    'proposed'     => (string) $r->status === 'proposed',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::mapForRange failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * What the management banner shows: upcoming and missed visits.
     * A rider gets only his own — the same endpoint serves both, so there is one
     * audience rule rather than one per surface.
     */
    public function summaryFor($user, bool $mobile = false): array
    {
        $empty = ['count' => 0, 'missed' => 0, 'latest_id' => 0, 'latest' => null,
                  'can_schedule' => false, 'visits' => []];
        if (!$this->available() || !$user) return $empty;

        $isManager = $this->canSchedule($user, $mobile)
            || (method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission(self::ALERT_PERMISSION))
            || (method_exists($user, 'hasPermission') && $user->hasPermission(self::ALERT_PERMISSION));

        $visits = $isManager
            ? $this->listVisits(['limit' => 60])
            : $this->listVisits(['user_id' => (int) $user->id, 'limit' => 20]);

        // ⚠⚠ array_merge, not `+` — see the note in VehicleTicketService::summaryFor.
        if (!$visits) return array_merge($empty, ['can_schedule' => $this->canSchedule($user, $mobile)]);

        /**
         * ⭐⭐ THE BANNER WATERMARK IS AN EVENT INSTANT, NOT A ROW ID (Sep-2 review).
         *
         * Both banners re-fire only when `latest_id` goes UP. Keyed to the visit id it
         * fired once when a visit was created and never again — so "Asim accepted",
         * "Asim's workshop is TOMORROW" and "Asim MISSED it" would all have stayed silent,
         * and the owner asked for the tomorrow notice by name.
         *
         * Each visit therefore contributes the unix time of its LATEST event, and the
         * watermark is the max across the audience. Every event is a fixed instant, so it
         * fires exactly once and never again:
         *   created_at     — set                    accepted_at — the rider confirmed
         *   reminded_at    — became "tomorrow"      done_at     — completed
         *   missed_at      — midnight after the date, DERIVED (no cron; the instant is
         *                    fixed, so once it has passed it simply becomes the max)
         * `latest` is the visit whose event is newest — i.e. what just happened.
         * ⚠ Unix seconds fit the banners' integer compare and AsyncStorage/localStorage.
         */
        $eventTs = function (array $v): int {
            $ts = [];
            foreach (['created_at', 'accepted_at', 'reminded_at', 'done_at'] as $k) {
                if (!empty($v[$k])) {
                    try { $ts[] = \Carbon\Carbon::parse($v[$k])->getTimestamp(); } catch (\Throwable $e) {}
                }
            }
            if ($v['is_missed']) {
                try { $ts[] = \Carbon\Carbon::parse($v['visit_date'])->addDay()->startOfDay()->getTimestamp(); } catch (\Throwable $e) {}
            }
            return $ts ? max($ts) : 0;
        };
        $stamped = array_map(fn ($v) => $v + ['event_ts' => $eventTs($v)], $visits);

        /**
         * ⭐ `latest` is chosen by PRIORITY, not by recency. Two events can land in the
         *   same poll — B becomes missed at 00:00, A's "tomorrow" reminder fires on the
         *   first poll after 00:00 — and a manager must see the missed one first. The
         *   watermark (max event) decides WHETHER the banner fires; priority decides WHAT
         *   it says. Ties inside a priority go to the newest event.
         */
        $rank = fn (array $v): int => $v['is_missed'] ? 0
            : ($v['is_today'] ? 1 : ($v['is_tomorrow'] ? 2 : (!$v['accepted'] ? 3 : 4)));
        usort($stamped, fn ($a, $b) => [$rank($a), $b['event_ts']] <=> [$rank($b), $a['event_ts']]);

        $missed   = array_values(array_filter($stamped, fn ($v) => $v['is_missed']));
        $tomorrow = array_values(array_filter($stamped, fn ($v) => $v['is_tomorrow']));
        return [
            'count'        => count($stamped),
            'missed'       => count($missed),
            'tomorrow'     => count($tomorrow),
            'latest_id'    => (int) max(array_column($stamped, 'event_ts') ?: [0]),
            'latest'       => $stamped[0],
            'can_schedule' => $this->canSchedule($user, $mobile),
            'visits'       => array_slice($stamped, 0, 10),
        ];
    }

    /**
     * Day-before reminders. ⚠ There is NO CRON on prod, so this piggybacks a banner
     * request (`app()->terminating()`), exactly like the service-due push sweep.
     * `reminded_at` makes it fire once per visit no matter how often it is called.
     *
     * @return array<int, array> the visits that were reminded, for the caller to push
     */
    public function dueReminders(): array
    {
        if (!$this->available()) return [];
        try {
            $tomorrow = \Carbon\Carbon::today()->addDay()->format('Y-m-d');
            $rows = DB::table(self::T_VISIT)
                ->whereIn('status', self::LIVE_STATUSES)
                ->whereNull('reminded_at')
                ->whereDate('visit_date', $tomorrow)
                ->get(['id']);
            if ($rows->isEmpty()) return [];
            $ids = $rows->pluck('id')->map('intval')->all();
            DB::table(self::T_VISIT)->whereIn('id', $ids)->update(['reminded_at' => now()]);

            /**
             * ⚠⚠ RETURN ONLY THE ONES JUST FLAGGED. This used to claim `reminded_at` for the
             *    unreminded rows and then return EVERY live visit for tomorrow — so one new
             *    visit dragged everybody else's reminder along with it, and a rider who had
             *    already had "kal workshop jana hai" got it again each time somebody booked.
             *    `approve()` clears `reminded_at`, which creates a fresh row to remind and so
             *    made this fire more often, not less. Owner ruling 7-Sep: already reminded,
             *    do not push again.
             */
            $mine = array_flip($ids);
            return array_values(array_filter(
                $this->listVisits(['from' => $tomorrow, 'to' => $tomorrow, 'limit' => 100]),
                fn ($v) => isset($mine[(int) $v['id']])
            ));
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::dueReminders failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** @param array<int,string> $names user id → full name, resolved once by the caller. */
    private function shape(array $r, array $labels, array $names = []): array
    {
        $date   = substr((string) $r['visit_date'], 0, 10);
        $live   = in_array((string) $r['status'], self::LIVE_STATUSES, true);
        /**
         * ⭐ Derived, never stored — no cron could have set a flag (see the class note).
         * ⚠⚠ `date()` / Carbon here are PHP-side and PKT (config app.timezone). NEVER compare
         *    against MySQL CURDATE()/NOW(): the DB session runs 2 hours behind PHP on this
         *    host, so between 00:00 and 02:00 PKT the database still thinks it is yesterday
         *    and a visit "today" would read as tomorrow, a missed one as due.
         */
        // ⚠ Carbon, not native date(): both are PKT here, but only Carbon honours
        //   setTestNow(), which is how the midnight boundary is PROVEN in the test suite.
        $today     = \Carbon\Carbon::today()->format('Y-m-d');
        $tomorrowD = \Carbon\Carbon::today()->addDay()->format('Y-m-d');
        $missed    = $live && $date < $today;

        return [
            'id'                  => (int) $r['id'],
            'vehicle_id'          => (int) $r['vehicle_id'],
            'vehicle_name'        => $labels[(int) $r['vehicle_id']] ?? null,
            'user_id'             => (int) $r['user_id'],
            'rider_name'          => $r['rider_name'] ?? null,
            'visit_date'          => $date,
            'visit_time'          => $r['visit_time'] ? substr((string) $r['visit_time'], 0, 5) : null,
            'workshop'            => $r['workshop'],
            'location_id'         => $r['location_id'] ? (int) $r['location_id'] : null,
            'purpose'             => (string) $r['purpose'],
            'maintenance_type_id' => $r['maintenance_type_id'] ? (int) $r['maintenance_type_id'] : null,
            'ticket_id'           => $r['ticket_id'] ? (int) $r['ticket_id'] : null,
            'note'                => $r['note'],
            'status'              => (string) $r['status'],
            'is_live'             => $live,
            /**
             * ⏳ THE APPROVAL STATE, for every screen that draws a visit.
             * ⚠ `is_live` stays FALSE for a proposal — it is what the rider-facing surfaces
             *   key off, and a proposal is not something he has been told about.
             * ⚠ `?? null` throughout: the six approval columns may not exist yet (the PHP
             *   routinely lands before the SQL on a hand-deployed system).
             */
            'is_proposed'         => (string) $r['status'] === 'proposed',
            'is_declined'         => (string) $r['status'] === 'declined',
            'proposed_by'         => !empty($r['proposed_by']) ? (int) $r['proposed_by'] : null,
            'proposed_by_name'    => !empty($r['proposed_by']) ? ($names[(int) $r['proposed_by']] ?? null) : null,
            'approved_by'         => !empty($r['approved_by']) ? (int) $r['approved_by'] : null,
            'approved_by_name'    => !empty($r['approved_by']) ? ($names[(int) $r['approved_by']] ?? null) : null,
            'approved_at'         => !empty($r['approved_at']) ? (string) $r['approved_at'] : null,
            // ⚠ NULL when it was TIME that declined it, not a person (the morning sweep).
            'declined_by_name'    => !empty($r['declined_by']) ? ($names[(int) $r['declined_by']] ?? null) : null,
            'declined_at'         => !empty($r['declined_at']) ? (string) $r['declined_at'] : null,
            'decline_reason'      => $r['decline_reason'] ?? null,
            'is_missed'           => $missed,
            'is_today'            => $live && $date === $today,
            'is_tomorrow'         => $live && $date === $tomorrowD,
            'reminded_at'         => $r['reminded_at'] ? (string) $r['reminded_at'] : null,
            'accepted_at'         => $r['accepted_at'] ? (string) $r['accepted_at'] : null,
            'created_at'          => $r['created_at'] ? (string) $r['created_at'] : null,
            'accepted'            => (string) $r['status'] === 'accepted',
            'accepted_via'        => $r['accepted_via'],
            'accepted_by_name'    => $r['accepted_by_name'] ?? null,
            // ⚠ Rendered as "accepted by X for Y" — a stand-in confirmation must never
            //   be presented as the rider's own.
            'accepted_on_behalf'  => (string) ($r['accepted_via'] ?? '') === 'manager',
            'created_by'          => (int) $r['created_by'],
            'created_by_name'     => $r['created_by_name'] ?? null,
            'superseded_by'       => $r['superseded_by'] ? (int) $r['superseded_by'] : null,
            'done_at'             => $r['done_at'] ? (string) $r['done_at'] : null,
            'outcome_note'        => $r['outcome_note'] ?? null,
            'service_log_id'      => $r['service_log_id'] ? (int) $r['service_log_id'] : null,
        ];
    }

    private function nameOf(?int $userId): string
    {
        if (!$userId) return 'someone';
        try {
            return (string) (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'someone');
        } catch (\Throwable $e) {
            return 'someone';
        }
    }
}
