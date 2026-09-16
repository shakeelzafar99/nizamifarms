<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🛠 THE ISSUES BOARD — the fleet's open problems, grouped BY MACHINE.
 *
 * Plan: VEHICLE-ISSUES-BOARD-PLAN-SEP2026.md. Owner ask, 15-Sep-2026:
 *
 *   "Vehicles have individual chats. I want a third tab under Bikes that consolidates those
 *    chats by vehicle, tells me what is going on, whether issues are still open and whether a
 *    workshop day has been assigned — a summary view so I can see if there's any delay or if
 *    no one is closing these tickets."
 *
 * ⭐⭐ WHY BY MACHINE, AND NOT BY TICKET. On the replica, **not one** workshop visit carries a
 *    `ticket_id` and not one ticket carries a `workshop_visit_id`: managers book the MACHINE
 *    from the vehicle panel, never from a thread. So "is a workshop day assigned for this
 *    issue?" is only answerable per VEHICLE today. Grouping by machine is not a presentation
 *    choice — it is the only grouping the data can actually answer. (Part B of the plan closes
 *    that gap by linking tickets at booking time; when it lands, `waiting_on: workshop` becomes
 *    exact and this class needs no reshaping.)
 *
 * ⭐⭐ WHY A SERVER-SIDE BOARD AND NOT THREE CLIENT FETCHES. The alternative was for the Blade
 *    and the React Native screen each to fetch vehicles + tickets + visits, join them, classify
 *    "whose turn is it", and compose a sentence. That is two copies of the same judgement in two
 *    languages, and they drift — the exact failure `checkin_line` was created to stop (10-Sep:
 *    "composed HERE, not on the clients: two apps and a blade formatting the same two-branch
 *    sentence is three chances to drift"). One payload, one set of words, both surfaces.
 *
 * ⚠⚠ THIS CLASS DECIDES NOTHING AND WRITES NOTHING. It is a reader. Every rule it needs already
 *    exists and is asked for rather than re-implemented:
 *      • who may see what  → VehicleTicketService::visibilityScope()  (the ONE predicate)
 *      • who may act       → canManage / canSchedule / canApprove
 *      • what a visit is   → WorkshopVisitService::listVisits() + its derived is_missed flags
 *    Re-deriving any of those here is how the four-way-OR visibility bug happened in the first
 *    place.
 *
 * ⚠ Carbon everywhere, never CURDATE()/NOW(): the DB session on this host runs 2 hours behind
 *   PHP, so between 00:00 and 02:00 PKT MySQL still thinks it is yesterday.
 */
class VehicleIssueBoard
{
    /**
     * ⏱ THE THRESHOLDS (owner, 15-Sep: "as recommended").
     *
     * ⚠ Constants with an OPTIONAL config override, so shipping this needs no migration. A
     *   `t_fin_config` row only matters if someone later wants to tune it; absent, these stand.
     */
    public const UNANSWERED_HOURS = 24;   // raised, no manager reply yet
    public const WAITING_HOURS    = 24;   // the ball has been in our court this long
    public const STALE_DAYS       = 3;    // nobody has said anything, whoever's turn it is

    /** Attention levels, worst first. The card's stripe colour and the sort both read this. */
    public const LEVELS = ['red', 'amber', 'blue', 'green', 'grey'];

    public function __construct(
        private VehicleTicketService $tickets,
        private WorkshopVisitService $visits,
        private VehicleService $vehicles,
    ) {
    }

    public function available(): bool
    {
        return $this->tickets->available();
    }

    /**
     * The whole board for one person.
     *
     * @param array $opts {mode: 'live'|'history', vehicle_id?: int}
     */
    public function forUser($user, array $opts = [], bool $mobile = false): array
    {
        $empty = [
            'available' => $this->available(),
            'can_manage' => false, 'can_schedule' => false, 'can_approve' => false,
            'threads' => false, 'read_only' => true,
            'totals' => $this->emptyTotals(), 'vehicles' => [], 'quiet' => [], 'history' => [],
            'generated_at' => now()->toDateTimeString(),
        ];
        if (!$this->available() || !$user) return $empty;

        $canManage  = $this->tickets->canManage($user, $mobile);
        $canSchedule = $this->visits->canSchedule($user, $mobile);
        $canApprove  = $this->visits->canApprove($user, $mobile);

        /**
         * ⭐⭐ THE READ-ONLY DOOR (owner ruling, 15-Sep — Grade 1, no SQL).
         *
         *    A shift planner (Farooq) needs to see what is stuck so he can plan around it, but
         *    Phase 2 deliberately gave him `receive_workshop_alerts` and NOT
         *    `receive_vehicle_ticket_alerts` — his exclusion from riders' complaint threads is a
         *    RULING, not an oversight. So he sees every machine's card, its workshop line and
         *    its ticket titles, ages and whose turn it is — and cannot open a thread.
         *
         * ⚠ `visibilityScope()` alone would hand him only the machines he HOLDS (one bike), and
         *   a one-bike "fleet board" is a lie. The read grant is what widens it, and it widens
         *   ONLY this reader — nothing else in the ticket system consults it.
         */
        $readGrant = !$canManage && $this->hasReadGrant($user, $mobile);
        $seesFleet = $canManage || $canSchedule || $readGrant;

        /**
         * ⚠⚠ ONE RULE, NOT TWO: anyone shown the WHOLE fleet who cannot manage tickets reads
         *    through the summary door and cannot open a thread.
         *
         *    It used to key off `$readGrant` alone, which left a gap: someone who can schedule
         *    a workshop but holds neither the ticket key nor a read grant had `$seesFleet` true
         *    — so the board drew a card for every machine — while his TICKETS still came from
         *    `listFor()`, which correctly hands a non-manager only the machines he HOLDS. Every
         *    other machine would have rendered as "quiet": a board under-reporting exactly what
         *    it exists to show. Nobody holds that combination today (every role with
         *    `schedule_workshop` also has `manage_vehicle_tickets`), so this is a guard against
         *    a future permission edit, not a live fix.
         */
        $summaryReader = $seesFleet && !$canManage;

        $out = [
            'available'    => true,
            'can_manage'   => $canManage,
            'can_schedule' => $canSchedule,
            'can_approve'  => $canApprove,
            // Whether a client may offer a way INTO a thread. False for every summary reader.
            'threads'      => !$summaryReader,
            'read_only'    => $summaryReader,
            'generated_at' => now()->toDateTimeString(),
        ];

        try {
            $mode = ($opts['mode'] ?? 'live') === 'history' ? 'history' : 'live';

            // ── the machines this person may be shown ────────────────────────────────
            $scope   = $this->tickets->visibilityScope($user, $mobile);
            $spine   = $this->vehicleSpine();
            if (!$seesFleet) {
                $ids   = is_array($scope) ? $scope : [];
                $spine = array_values(array_filter($spine, fn ($v) => in_array($v['id'], $ids, true)));
            }

            // ── tickets ──────────────────────────────────────────────────────────────
            /**
             * ⚠ OPEN tickets are fetched as their own list rather than asking for everything and
             *   filtering here. `listFor` orders by last activity and caps at 200, so on a busy
             *   fleet a pile of recently-closed threads could push the open ones off the end —
             *   the board would then quietly under-report exactly what it exists to show.
             *   Closed rows are COUNTED with an aggregate instead, which is cheap and exact.
             */
            /**
             * ⚠⚠ THE READ-ONLY DOOR NEEDS A WIDER TICKET READ THAN `listFor` CAN GIVE IT.
             *    `visibilityScope()` correctly hands a non-manager only the machines he HOLDS,
             *    so a planner would get his own one bike and a board claiming to show the fleet.
             *    Widening `visibilityScope` itself was never an option — it is the ONE rule that
             *    decides who may open a conversation. So the summary reader is its own door,
             *    and it is the ONLY caller of it. It returns no unread counts and the payload
             *    says `threads: false`, so nothing it hands over can open a thread.
             */
            $open = $summaryReader
                ? $this->tickets->listFleetForReader(['status' => 'open', 'limit' => 200])
                : $this->tickets->listFor($user, ['status' => 'open', 'limit' => 200], $mobile);
            $byVehicle = [];
            foreach ($open as $t) $byVehicle[(int) $t['vehicle_id']][] = $t;

            $lastMsgs   = $this->lastMessages(array_column($open, 'id'));
            $managerIds = $this->managerUserIds();
            $closed     = $this->closedCounts($scope, $seesFleet);
            // ⚠ `shape()` carries `assigned_to` as an ID only. One query for the names beats a
            //   lookup per row, and a bare number on a card answers nobody's question.
            $assignees  = $this->userNames(array_filter(array_column($open, 'assigned_to')));

            // ── workshop visits, one fleet-wide read, bucketed ───────────────────────
            $visitOpts = ['include_done' => true, 'limit' => 500];
            // ⚠ Proposals are manager/planner-only — the same gate WorkshopVisitController::index
            //   applies. A rider must never receive a day he has not been told about.
            if ($canSchedule || $canApprove) $visitOpts['include_proposed'] = true;
            /**
             * ⚠ `listVisits` filters by ONE `vehicle_id`, not a list — so this read is fleet-wide
             *   even for a rider. That is safe, not sloppy: the bucket below is only ever READ
             *   through `$spine`, which has already been narrowed to the machines he may see, so
             *   nothing outside it can reach the payload. Passing a `vehicle_ids` option that the
             *   function does not implement would look like a filter while being ignored, which
             *   is worse than no filter at all.
             */
            $visitsByVehicle = [];
            foreach ($this->visits->listVisits($visitOpts) as $v) {
                $visitsByVehicle[(int) $v['vehicle_id']][] = $v;
            }

            // ── build a card per machine ─────────────────────────────────────────────
            $cards = []; $quiet = [];
            foreach ($spine as $veh) {
                $vid  = (int) $veh['id'];
                $rows = $byVehicle[$vid] ?? [];
                $vis  = $visitsByVehicle[$vid] ?? [];
                $live = $this->pickLiveVisit($vis);
                $done = $this->pickRecentDone($vis, $rows);

                /**
                 * ⚠ A RETIRED machine is dropped only when it has nothing open. A retired bike
                 *   with a live ticket is a STUCK TICKET, not a retired problem, and hiding it
                 *   is how it would never be closed.
                 */
                if (!$rows && empty($veh['is_active'])) continue;

                if (!$rows && !$live) {
                    $quiet[] = [
                        'id' => $vid, 'name' => $veh['name'], 'keeper_name' => $veh['keeper_name'],
                        'is_company' => $veh['is_company'], 'closed_count' => (int) ($closed[$vid]['n'] ?? 0),
                    ];
                    continue;
                }

                $shaped = array_map(
                    fn ($t) => $this->shapeTicket($t, $lastMsgs, $managerIds, $live, $assignees),
                    $rows
                );
                // Worst first inside the card, so the top row is the one to read.
                usort($shaped, fn ($a, $b) => [$this->ticketRank($a), -$a['age_hours']]
                                          <=> [$this->ticketRank($b), -$b['age_hours']]);

                $attention = $this->attentionFor($shaped, $live, $done, $veh);
                $cards[] = [
                    'id'              => $vid,
                    'name'            => $veh['name'],
                    'vtype'           => $veh['vtype'],
                    'is_company'      => $veh['is_company'],
                    'is_active'       => $veh['is_active'],
                    'keeper_user_id'  => $veh['keeper_user_id'],
                    'keeper_name'     => $veh['keeper_name'],
                    'attention'       => $attention,
                    'open_tickets'    => $shaped,
                    'workshop'        => $live,
                    'last_done_visit' => $done,
                    'closed_count'    => (int) ($closed[$vid]['n'] ?? 0),
                    'last_closed_at'  => $closed[$vid]['last'] ?? null,
                ];
            }

            usort($cards, fn ($a, $b) => [$a['attention']['rank'], -$a['attention']['age_hours']]
                                     <=> [$b['attention']['rank'], -$b['attention']['age_hours']]);

            $out['vehicles'] = $cards;
            $out['quiet']    = $quiet;
            $out['totals']   = $this->totals($cards, $quiet, $closed);
            $out['history']  = $mode === 'history'
                ? $this->history($user, $opts, $mobile, $summaryReader)
                : [];
            return $out;
        } catch (\Throwable $e) {
            Log::warning('VehicleIssueBoard::forUser failed', ['error' => $e->getMessage()]);
            return array_merge($empty, [
                'can_manage' => $canManage, 'can_schedule' => $canSchedule, 'can_approve' => $canApprove,
                'failed' => true,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  THE JUDGEMENTS — each written ONCE, here
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⭐⭐ WHOSE TURN IS IT? The one question the owner actually asked ("so I can see if there's
     *    any delay"), and the only thing on this board that is not already in a column.
     *
     *    Read from the newest HUMAN message: if a manager spoke last, we are waiting on the
     *    rider; otherwise the ball is with us. System lines are skipped for this — "Qasim
     *    scheduled a workshop" is not somebody answering.
     *
     * ⚠ A ticket with only its opening message counts as waiting on US. That is the case the
     *   board exists for: replica ticket #13 (third puncture in two days, a photo and no words)
     *   had sat unanswered for three days.
     */
    private function shapeTicket(array $t, array $lastMsgs, array $managerIds, ?array $liveVisit, array $assignees = []): array
    {
        $tid  = (int) $t['id'];
        $last = $lastMsgs[$tid] ?? null;
        $now  = \Carbon\Carbon::now();

        $lastHuman = $last['human'] ?? null;
        $lastAny   = $last['any'] ?? null;

        $waitingOn = 'us';
        if ($lastHuman && in_array((int) $lastHuman['user_id'], $managerIds, true)) {
            $waitingOn = 'rider';
        }
        // ⚠ A ticket whose answer is "it is booked in" is waiting on neither — saying "waiting
        //   on us" about a bike that goes to the workshop tomorrow is noise on a busy board.
        if ((string) $t['status'] === 'scheduled' && $liveVisit) $waitingOn = 'workshop';

        $since = $lastHuman['created_at'] ?? ($t['opened_at'] ?? null);
        $waitingHours = $since ? max(0, $now->diffInHours(\Carbon\Carbon::parse($since), false) * -1) : 0;
        $ageHours = !empty($t['opened_at'])
            ? max(0, $now->diffInHours(\Carbon\Carbon::parse($t['opened_at']), false) * -1) : 0;

        return $t + [
            'last_message'  => $lastAny ? [
                'id'          => (int) $lastAny['id'],
                'user_id'     => $lastAny['user_id'] !== null ? (int) $lastAny['user_id'] : null,
                'author_name' => $lastAny['author_name'],
                'kind'        => $lastAny['kind'],
                // ⚠ A photo- or voice-only message has no body. Say what it WAS rather than
                //   printing an empty line, which reads as a broken row.
                'snippet'     => $this->snippet($lastAny),
                'created_at'  => $lastAny['created_at'],
            ] : null,
            'waiting_on'    => $waitingOn,
            'waiting_hours' => (int) $waitingHours,
            // `first_response_at` already means exactly this — no new derivation.
            'unanswered'    => empty($t['first_response_at']),
            'age_hours'     => (int) $ageHours,
            'is_stale'      => $waitingHours >= self::STALE_DAYS * 24,
            'assigned_to_name' => !empty($t['assigned_to']) ? ($assignees[(int) $t['assigned_to']] ?? null) : null,
        ];
    }

    private function snippet(array $m): string
    {
        $body = trim((string) ($m['body'] ?? ''));
        if ($body !== '') return mb_substr($body, 0, 60) . (mb_strlen($body) > 60 ? '…' : '');
        return match ((string) $m['kind']) {
            'photo' => '📷 photo',
            'voice' => '🎤 voice note',
            default => '—',
        };
    }

    /** Worst ticket first inside a card. Mirrors the card order, one level down. */
    private function ticketRank(array $t): int
    {
        if (!empty($t['urgent']))                                              return 0;
        if ($t['unanswered'] && $t['age_hours'] >= self::UNANSWERED_HOURS)     return 1;
        if ($t['waiting_on'] === 'us' && $t['waiting_hours'] >= self::WAITING_HOURS) return 2;
        if ($t['waiting_on'] === 'us')                                         return 3;
        if ($t['waiting_on'] === 'workshop')                                   return 4;
        return 5;
    }

    /**
     * ⭐⭐ ONE SENTENCE PER MACHINE, composed HERE so the phone and the desk cannot say two
     *    different things about the same bike. The first rule that matches writes the sentence,
     *    the colour and the sort position — so what the manager reads, what he sees and where it
     *    sits in the list can never disagree.
     *
     * English: this is a manager surface ([[alerts-copy-roman-urdu]] — Roman Urdu is for
     * instructions to a rider, and there are none on this board).
     */
    private function attentionFor(array $tickets, ?array $live, ?array $done, array $veh): array
    {
        $mk = fn (int $rank, string $level, string $line, int $age = 0) =>
            ['rank' => $rank, 'level' => $level, 'line' => $line, 'age_hours' => $age];

        $urgent     = array_values(array_filter($tickets, fn ($t) => !empty($t['urgent'])));
        $unanswered = array_values(array_filter(
            $tickets, fn ($t) => $t['unanswered'] && $t['age_hours'] >= self::UNANSWERED_HOURS));
        $waitingUs  = array_values(array_filter(
            $tickets, fn ($t) => $t['waiting_on'] === 'us' && $t['waiting_hours'] >= self::WAITING_HOURS));
        $waitingHim = array_values(array_filter($tickets, fn ($t) => $t['waiting_on'] === 'rider'));

        // 1. the bike cannot be ridden — a rider stranded, not a queue item
        if ($urgent) {
            $t = $urgent[0];
            return $mk(0, 'red',
                '🔴 Not rideable — ' . $this->phraseDays($t['age_hours']) . ' since it was reported',
                $t['age_hours']);
        }
        // 2. he was told to take it in and did not
        if ($live && !empty($live['is_missed'])) {
            return $mk(1, 'red', '❗ Workshop day missed — ' . $this->dateWord($live['visit_date'])
                . ($live['workshop_label'] ? ' · ' . $live['workshop_label'] : ''), 0);
        }
        // 3. nobody has answered him at all
        if ($unanswered) {
            $t = $unanswered[0];
            $extra = count($waitingUs) ? ' · ' . count($waitingUs) . ' more waiting on us' : '';
            return $mk(2, 'red', '❓ No reply for ' . $this->phraseDays($t['age_hours']) . $extra,
                $t['age_hours']);
        }
        // 4. the ball has been in our court too long
        if ($waitingUs) {
            $worst = max(array_column($waitingUs, 'waiting_hours'));
            return $mk(3, 'amber', '⏱ ' . count($waitingUs) . ' waiting on us for '
                . $this->phraseDays($worst), $worst);
        }
        /**
         * 5. ⭐ THE "NOBODY IS CLOSING TICKETS" DETECTOR — the owner's words. The machine went
         *    in, the work was done, and the complaint is still open. On the replica this is
         *    AY-4771: ticket open since 6-Sep, visit marked done 11-Sep, nobody closed it.
         *
         * ⚠⚠ ONLY WHEN NOTHING IS BOOKED. Seen on the device: a machine with a completed visit
         *    AND a new day booked for tomorrow read "Workshop done 11 Sep, 1 issue still open" —
         *    true, but the least useful thing to say about a bike that is going back in
         *    tomorrow. The attention line carries the ONE most actionable fact, and a standing
         *    appointment outranks a stale one. The ticket rows below still show it is open.
         */
        if ($done && $tickets && !$live) {
            return $mk(4, 'amber', '🔧 Workshop done ' . $this->dateWord($done['visit_date'])
                . ', ' . count($tickets) . ' ' . (count($tickets) === 1 ? 'issue' : 'issues')
                . ' still open', 0);
        }
        // 6. a request nobody has answered — the rider has NOT been told
        if ($live && !empty($live['is_proposed'])) {
            return $mk(5, 'amber', '⏳ Workshop day awaiting a shift planner — rider NOT told', 0);
        }
        // 7. we answered; he has not
        if ($waitingHim) {
            $worst = max(array_column($waitingHim, 'waiting_hours'));
            $name  = $veh['keeper_name'] ?: 'the rider';
            return $mk(6, 'blue', '💬 Waiting on ' . $name . ' for ' . $this->phraseDays($worst), $worst);
        }
        // 8. booked, and everything has been answered
        if ($live) {
            $when = !empty($live['is_today']) ? 'today'
                  : (!empty($live['is_tomorrow']) ? 'tomorrow' : $this->dateWord($live['visit_date']));
            return $mk(7, 'green', '🔧 Workshop ' . $when
                . (!empty($live['accepted']) ? ' · accepted' : ' · not accepted yet'), 0);
        }
        return $mk(8, 'grey', $tickets
            ? count($tickets) . ' open, nothing waiting on anyone'
            : 'Nothing open', 0);
    }

    /** "3 days" / "5 hours" — a manager reads days, not 73h. */
    private function phraseDays(int $hours): string
    {
        if ($hours < 48) return max(1, $hours) . ($hours === 1 ? ' hour' : ' hours');
        $d = intdiv($hours, 24);
        return $d . ' days';
    }

    /** "11 Sep" — short, and never a bare ISO date on a manager's screen. */
    private function dateWord(?string $ymd): string
    {
        if (!$ymd) return '';
        try { return \Carbon\Carbon::parse($ymd)->format('j M'); }
        catch (\Throwable $e) { return (string) $ymd; }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  THE READS
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * The machines, lean. `VehicleService::all()` is the richer engine but it computes a meter,
     * a photo count, a thumbnail and a last-keeper per machine — none of which a board about
     * conversations needs, and this endpoint is polled.
     *
     * ⚠ The LABEL is still asked for, never re-derived: `VehicleResolver::labelFor` holds the
     *   one rule (company ⇒ plate, personal ⇒ nickname), and a screen inventing its own is how
     *   Rajab was once offered "APPLIED-FOR" as the name of a bike.
     */
    private function vehicleSpine(): array
    {
        $res = new VehicleResolver();
        $rows = DB::table('t_ops_vehicle as v')
            ->leftJoin('t_ops_vehicle_assignment as a', function ($j) {
                $j->on('a.vehicle_id', '=', 'v.id')->whereNull('a.released_on');
            })
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
            ->get(['v.id', 'v.vtype', 'v.is_company', 'v.is_active',
                   'a.user_id as keeper_user_id', 'u.fullname as keeper_name']);

        return $rows->map(fn ($r) => [
            'id'             => (int) $r->id,
            'name'           => $res->labelFor((int) $r->id),
            'vtype'          => $r->vtype,
            'is_company'     => (int) $r->is_company === 1,
            'is_active'      => (int) $r->is_active === 1,
            'keeper_user_id' => $r->keeper_user_id ? (int) $r->keeper_user_id : null,
            'keeper_name'    => $r->keeper_name,
        ])->values()->all();
    }

    /**
     * The newest message on each ticket — and separately the newest HUMAN one, because "whose
     * turn is it" must not be answered by a system line.
     *
     * ⚠ ONE query for the lot (the `(ticket_id, id)` index makes it cheap), not a MAX() per row.
     *   A per-ticket lookup is the N+1 shape that made the payroll page 14 seconds slow.
     */
    private function lastMessages(array $ticketIds): array
    {
        if (!$ticketIds) return [];
        try {
            $rows = DB::table('t_ops_vehicle_ticket_message as m')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'm.user_id')
                ->whereIn('m.ticket_id', $ticketIds)
                ->orderBy('m.ticket_id')->orderBy('m.id')
                ->get(['m.id', 'm.ticket_id', 'm.user_id', 'm.kind', 'm.body', 'm.created_at',
                       'u.fullname as author_name']);

            $out = [];
            foreach ($rows as $r) {
                $t = (int) $r->ticket_id;
                $m = [
                    'id' => (int) $r->id, 'user_id' => $r->user_id !== null ? (int) $r->user_id : null,
                    'author_name' => $r->author_name, 'kind' => (string) $r->kind,
                    'body' => $r->body, 'created_at' => (string) $r->created_at,
                ];
                // Rows arrive in id order, so the last one written wins for each bucket.
                $out[$t]['any'] = $m;
                if ($r->kind !== 'system') $out[$t]['human'] = $m;
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('VehicleIssueBoard::lastMessages failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Who counts as "us" when deciding whose turn it is.
     *
     * ⚠ BOTH permission tables. The web key lives in `t_sys_role_permissions` and the mobile one
     *   in `t_sys_mobile_permission` — the push group already resolves against the mobile side,
     *   and a manager who holds only one of the two would otherwise be read as a rider, flipping
     *   every ticket he answered to "waiting on us".
     */
    private function managerUserIds(): array
    {
        try {
            $roleIds = DB::table('t_sys_role_permissions')
                ->where('permission_key', VehicleTicketService::PERMISSION)
                ->where('is_allowed', 1)->pluck('role_id')->all();

            $mobileRoleIds = DB::table('t_sys_role_mobile_permission as rp')
                ->join('t_sys_mobile_permission as p', 'p.id', '=', 'rp.mobile_permission_id')
                ->where('p.permission_code', VehicleTicketService::PERMISSION)
                ->pluck('rp.role_id')->all();

            $all = array_unique(array_merge($roleIds, $mobileRoleIds));
            if (!$all) return [];
            return DB::table('t_sys_user_role')->whereIn('role_id', $all)
                ->pluck('user_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        } catch (\Throwable $e) {
            Log::warning('VehicleIssueBoard::managerUserIds failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** id => fullname, in one query. @return array<int, string> */
    private function userNames(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if (!$ids) return [];
        try {
            return DB::table('t_sys_user')->whereIn('id', $ids)
                ->pluck('fullname', 'id')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Closed tickets per machine — counted, not listed. Honours the same visibility. */
    private function closedCounts($scope, bool $seesFleet): array
    {
        try {
            $q = DB::table('t_ops_vehicle_ticket')
                ->where('status', 'closed')
                ->groupBy('vehicle_id')
                ->select('vehicle_id', DB::raw('COUNT(*) as n'), DB::raw('MAX(closed_at) as last'));
            if (!$seesFleet) {
                $ids = is_array($scope) ? $scope : [];
                if (!$ids) return [];
                $q->whereIn('vehicle_id', $ids);
            }
            $out = [];
            foreach ($q->get() as $r) {
                $out[(int) $r->vehicle_id] = ['n' => (int) $r->n, 'last' => $r->last];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The machine's live (or proposed) visit — the one thing a manager can still act on. */
    private function pickLiveVisit(array $visits): ?array
    {
        foreach ($visits as $v) {
            if (!empty($v['is_live']) || !empty($v['is_proposed'])) return $v;
        }
        return null;
    }

    /**
     * ⭐ The most recent DONE visit, attached ONLY when it post-dates an open ticket.
     *
     * ⚠ Otherwise it is noise: every machine has been serviced at some point, and a card that
     *   says "workshop done in July" next to a complaint raised yesterday tells a manager
     *   nothing. The pairing is the whole signal — work happened, and the conversation about it
     *   was never finished.
     */
    private function pickRecentDone(array $visits, array $openTickets): ?array
    {
        if (!$openTickets) return null;
        $oldestOpen = min(array_map(fn ($t) => (string) ($t['opened_at'] ?? '9999'), $openTickets));
        $best = null;
        foreach ($visits as $v) {
            if (($v['status'] ?? '') !== 'done' || empty($v['done_at'])) continue;
            if ((string) $v['done_at'] < $oldestOpen) continue;
            if (!$best || $v['done_at'] > $best['done_at']) $best = $v;
        }
        return $best;
    }

    /**
     * ⭐ THE READ GRANT for the planners' door (Grade 1 — no SQL, no new key).
     *
     * ⚠ Deliberately NOT `receive_vehicle_ticket_alerts`: Phase 2 split those two keys precisely
     *   so that a planner could be told about workshop days without being put inside riders'
     *   complaint threads. This grant opens the BOARD, and `threads` stays false for it.
     */
    private function hasReadGrant($user, bool $mobile): bool
    {
        foreach ([WorkshopVisitService::ALERT_PERMISSION, 'manage_shifts'] as $key) {
            if ($mobile && method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission($key)) return true;
            if (method_exists($user, 'hasPermission') && $user->hasPermission($key)) return true;
        }
        return false;
    }

    /** Closed tickets, newest first — the history mode behind the ✓ Closed chip. */
    private function history($user, array $opts, bool $mobile, bool $summaryReader = false): array
    {
        $o = ['status' => 'closed', 'limit' => (int) ($opts['limit'] ?? 60)];
        if (!empty($opts['vehicle_id'])) $o['vehicle_id'] = (int) $opts['vehicle_id'];
        // ⚠ Same widening as the live list, and for the same reason — see the note there.
        $rows = $summaryReader ? $this->tickets->listFleetForReader($o) : $this->tickets->listFor($user, $o, $mobile);
        $last = $this->lastMessages(array_column($rows, 'id'));
        return array_map(function ($t) use ($last) {
            $m = $last[(int) $t['id']]['any'] ?? null;
            return $t + ['last_message' => $m ? ['snippet' => $this->snippet($m),
                                                 'author_name' => $m['author_name'],
                                                 'created_at' => $m['created_at']] : null];
        }, $rows);
    }

    private function emptyTotals(): array
    {
        return ['machines' => 0, 'with_issues' => 0, 'quiet' => 0, 'open_tickets' => 0,
                'urgent' => 0, 'unanswered' => 0, 'waiting_on_us' => 0, 'waiting_on_rider' => 0,
                'stale' => 0, 'workshop_booked' => 0, 'workshop_missed' => 0,
                'workshop_done_open' => 0, 'proposed' => 0, 'closed' => 0];
    }

    /** The top strip: the whole answer for a manager who only glances at the tab. */
    private function totals(array $cards, array $quiet, array $closed): array
    {
        $t = $this->emptyTotals();
        $t['machines']    = count($cards) + count($quiet);
        $t['with_issues'] = count($cards);
        $t['quiet']       = count($quiet);
        $t['closed']      = array_sum(array_column($closed, 'n'));

        foreach ($cards as $c) {
            foreach ($c['open_tickets'] as $tk) {
                $t['open_tickets']++;
                if (!empty($tk['urgent'])) $t['urgent']++;
                if ($tk['unanswered'] && $tk['age_hours'] >= self::UNANSWERED_HOURS) $t['unanswered']++;
                if ($tk['waiting_on'] === 'us'    && $tk['waiting_hours'] >= self::WAITING_HOURS) $t['waiting_on_us']++;
                if ($tk['waiting_on'] === 'rider') $t['waiting_on_rider']++;
                if (!empty($tk['is_stale'])) $t['stale']++;
            }
            $w = $c['workshop'];
            if ($w) {
                if (!empty($w['is_missed']))        $t['workshop_missed']++;
                elseif (!empty($w['is_proposed']))  $t['proposed']++;
                else                                $t['workshop_booked']++;
            }
            // ⚠ Same guard as the attention rule, so the chip and the cards cannot disagree
            //   about which machines are "done but still open".
            if ($c['last_done_visit'] && $c['open_tickets'] && !$c['workshop']) $t['workshop_done_open']++;
        }
        return $t;
    }
}
