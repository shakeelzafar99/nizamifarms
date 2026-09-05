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

    public const PURPOSES = ['service', 'repair', 'inspection', 'other'];
    /** Statuses that still expect something to happen. */
    public const LIVE_STATUSES = ['scheduled', 'accepted'];

    private ?bool $tableExists = null;

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

        try {
            $now = now();
            $warnings = $this->warningsFor($vehicleId, $riderId, $date);

            $visitId = null;
            $replaced = null;
            DB::transaction(function () use (&$visitId, &$replaced, $vehicleId, $riderId, $date, $purpose, $in, $user, $now) {
                // ⚠ One live visit per machine. Two open instructions for one bike is how
                //   a rider ends up at the workshop on the wrong day.
                $existing = DB::table(self::T_VISIT)
                    ->where('vehicle_id', $vehicleId)
                    ->whereIn('status', self::LIVE_STATUSES)
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
                    'status'              => 'scheduled',
                    'created_by'          => (int) $user->id,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);

                if ($existing) {
                    DB::table(self::T_VISIT)->where('id', $existing->id)->update([
                        'status'        => 'rescheduled',
                        'superseded_by' => $visitId,
                        'updated_at'    => $now,
                    ]);
                    $replaced = (int) $existing->id;
                }
            });

            // ⭐ The ticket thread is the audit trail — a visit scheduled off a complaint
            //   must be visible to the rider who complained, in the place he is watching.
            $this->linkTicket((int) $visitId, $in, $user, $date, $in['visit_time'] ?? null);

            /**
             * 📍 PHASE 4. A visit booked at a REGISTERED workshop makes that workshop the
             * rider's shift location for that one day, so checking in there is on time by
             * itself. Opt-in: no `location_id`, no override, nothing changes.
             * ⚠ The row it replaces is cleared first — a MOVED visit must not leave the old
             *   day pointing at a workshop nobody is going to any more.
             */
            if ($replaced) $this->clearShiftLocation($replaced, $riderId);
            $pinned = $this->applyShiftLocation((int) $visitId, $riderId, $date,
                                                !empty($in['location_id']) ? (int) $in['location_id'] : null);

            return [
                'ok'               => true,
                'visit_id'         => (int) $visitId,
                'rescheduled_from' => $replaced,
                'warnings'         => $warnings,
                // Say it plainly: this is the difference between "he must not be marked late"
                // being handled by the system and being a job the planner still has to do.
                'shift_location_set' => $pinned,
                'message'          => ($replaced
                        ? 'Moved. ' . $this->nameOf($riderId) . ' has to accept the new date.'
                        : 'Set. ' . $this->nameOf($riderId) . ' will be asked to accept it.')
                    . ($pinned
                        ? ' That day he checks in at the workshop, so he will not be marked late or remote.'
                        : ''),
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
    public function warningsFor(int $vehicleId, int $riderId, string $date): array
    {
        $out = [];
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
    private function applyShiftLocation(int $visitId, int $userId, string $date, ?int $locationId): bool
    {
        if (!$locationId) return false;
        try {
            if (!Schema::hasTable('t_ops_user_shift_assignment')) return false;

            // A row the planner created for that day wins — see the note above.
            $existing = DB::table('t_ops_user_shift_assignment')
                ->where('user_id', $userId)
                ->whereNotNull('effective_to')
                ->whereDate('effective_from', '<=', $date)
                ->whereDate('effective_to', '>=', $date)
                ->first(['id', 'workshop_visit_id']);
            if ($existing && empty($existing->workshop_visit_id)) return false;

            // The template he is ALREADY on that day — so only the place changes.
            $shift = (new ShiftResolutionService())->getUserShift($userId, $date);
            $templateId = $shift['shift_id'] ?? null;
            if (!$templateId) return false;   // cannot pin a location with no shift to pin it to

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

    /** Write the visit into the ticket's thread, when it came from one. */
    private function linkTicket(int $visitId, array $in, $user, string $date, ?string $time): void
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
            $tickets->system($ticketId, 'Workshop visit set for '
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

    public function cancel($user, int $visitId, ?string $reason, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Workshop visits are not set up yet.'];
        if (!$this->canSchedule($user, $mobile)) {
            return ['ok' => false, 'message' => 'You cannot change workshop visits.'];
        }
        $v = $this->find($visitId);
        if (!$v) return ['ok' => false, 'message' => 'That visit no longer exists.'];
        if (!in_array($v['status'], self::LIVE_STATUSES, true)) {
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
            if (!empty($v['ticket_id'])) {
                app(VehicleTicketService::class)->system((int) $v['ticket_id'],
                    'Workshop visit cancelled by ' . $this->nameOf((int) $user->id)
                    . ($reason ? ' — ' . $reason : '') . '.');
            }
            return ['ok' => true, 'message' => 'Cancelled.'];
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
     * @param array $opts {user_id?, vehicle_id?, from?, to?, include_done?}
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
            $q->whereIn('v.status', empty($opts['include_done'])
                ? self::LIVE_STATUSES
                : ['scheduled', 'accepted', 'done', 'cancelled', 'rescheduled']);

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

            return $rows->map(fn ($r) => $this->shape((array) $r, $labels))->values()->all();
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
    public function mapForRange(array $userIds, string $from, string $to): array
    {
        if (!$this->available() || !$userIds) return [];
        try {
            $rows = DB::table(self::T_VISIT)
                ->whereIn('user_id', array_map('intval', $userIds))
                ->whereIn('status', self::LIVE_STATUSES)
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
            return $this->listVisits(['from' => $tomorrow, 'to' => $tomorrow, 'limit' => 100]);
        } catch (\Throwable $e) {
            Log::warning('WorkshopVisitService::dueReminders failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────

    private function shape(array $r, array $labels): array
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
