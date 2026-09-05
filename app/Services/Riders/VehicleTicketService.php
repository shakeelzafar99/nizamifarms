<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🛠 BIKE TICKETS — a rider reports a problem with the machine he is holding, and a
 *    manager answers and closes it (owner ask, Sep-2026).
 *
 * Plan: VEHICLE-TICKETS-AND-WORKSHOP-PLAN-SEP2026.md §2.
 *
 * ⭐⭐ A TICKET BELONGS TO THE MACHINE, NOT TO THE RIDER. That single decision is what
 *    makes the feature worth having: hand the bike over and the complaint history goes
 *    with it, so the next rider sees what was already reported and a manager looking at
 *    a bike sees everything it has ever suffered. The rider is recorded
 *    (`opened_for_user_id`) but he is not the key.
 *
 * ⭐ ONE PLACE FOR EVERY RULE. Who may open, who may read, who may reply, when a reply
 *   re-opens a closed ticket, who may close, and what a status change writes into the
 *   thread — all here. The web controller and the mobile controller are thin doors onto
 *   these methods, exactly as markServiced is shared, so the two surfaces cannot drift.
 *
 * ⭐ OWNERSHIP IS ANSWERED THE SAME WAY THE SERVICE ALERTS ANSWER IT —
 *   `VehicleResolver::currentVehicleFor` ∪ `RiderDayLegs::ownMachineIdsFor`. A rider who
 *   takes the company van for the day still owns his own bike (taking the van RELEASES
 *   his bike's open assignment — one open row per rider), and if these two features
 *   disagreed about that, a rider could be shown an alert for a bike he cannot raise a
 *   ticket about. See [[bike-service-alerts]].
 *
 * ⚠ Schema-guarded throughout: the PHP may be uploaded before
 *   `database/migrations/vehicle_tickets_sep2026.sql` runs, and that must degrade to
 *   "no tickets" rather than 500 every Bikes screen.
 *
 * ⚠ Every write stamps DATETIME via now() — never a TIMESTAMP column
 *   (see [[rider-gps-two-clock-offset]]).
 */
class VehicleTicketService
{
    public const T_TICKET  = 't_ops_vehicle_ticket';
    public const T_MESSAGE = 't_ops_vehicle_ticket_message';
    public const T_READ    = 't_ops_vehicle_ticket_read';

    /** The right to answer and close. Same code on web and mobile, different tables. */
    public const PERMISSION = 'manage_vehicle_tickets';
    /** The right to be TOLD when one is raised. Separate on purpose — being alerted and
     *  being able to act are different grants (the same split as service alerts). */
    public const ALERT_PERMISSION = 'receive_vehicle_ticket_alerts';

    /**
     * ⭐⭐ OWNER RULING (2-Sep): a rider replying on a CLOSED ticket re-opens it, but only
     *    within 7 days. After that the thread is read-only and the app offers a new
     *    ticket instead. Without a bound, a ticket closed in March could be resurrected
     *    by a stray "ok thanks" and land back on a manager's list months later.
     */
    public const REOPEN_WINDOW_DAYS = 7;

    public const CATEGORIES = ['problem', 'service', 'accident', 'other'];
    public const OPEN_STATUSES = ['open', 'acknowledged', 'scheduled'];

    private ?bool $tableExists = null;

    public function available(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = Schema::hasTable(self::T_TICKET)
                    && Schema::hasTable(self::T_MESSAGE);
            } catch (\Throwable $e) {
                $this->tableExists = false;
            }
        }
        return $this->tableExists;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  WHO MAY DO WHAT
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * May this user answer and close tickets?
     *
     * ⚠ `$mobile` picks WHICH permission table is consulted — the two are separate and
     *   a role can hold one without the other, exactly as canManageService works in
     *   FleetFuelController. A read-only account is refused outright.
     */
    public function canManage($user, bool $mobile = false): bool
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
     * The machines this user personally holds — the qualification that lets a rider
     * open and read a ticket with no permission at all.
     *
     * @return array<int, int> vehicle ids
     */
    public function ownMachineIds(int $userId): array
    {
        $ids = [];
        try {
            $held = (new VehicleResolver())->currentVehicleFor($userId);
            if ($held) $ids[(int) $held] = true;
            foreach ((new RiderDayLegs())->ownMachineIdsFor($userId) as $own) {
                $ids[(int) $own] = true;
            }
        } catch (\Throwable $e) {
            Log::warning('VehicleTicketService::ownMachineIds failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * The caller's own machines, LABELLED — so the app can offer a picker when he holds
     * more than one (a rider on the company van still owns his bike). Empty for a manager:
     * he chooses a rider, and the registry answers the machine.
     *
     * @return array<int, array{id: int, name: ?string}>
     */
    public function myMachines(int $userId): array
    {
        $res = new VehicleResolver();
        return array_map(
            fn ($id) => ['id' => (int) $id, 'name' => $res->labelFor((int) $id)],
            $this->ownMachineIds($userId)
        );
    }

    /**
     * May this user see this ticket?
     *
     * A manager sees everything. Otherwise: the person who opened it, the rider it was
     * opened for, or whoever holds the machine NOW — the last of those is what makes the
     * history travel with the bike across a handover.
     */
    public function mayRead($user, array $ticket, bool $mobile = false): bool
    {
        if (!$user) return false;
        $uid = (int) $user->id;
        if ($this->canManage($user, $mobile)) return true;
        if ((int) $ticket['opened_by'] === $uid) return true;
        if ((int) ($ticket['opened_for_user_id'] ?? 0) === $uid) return true;
        return in_array((int) $ticket['vehicle_id'], $this->ownMachineIds($uid), true);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  OPENING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Raise a ticket.
     *
     * @return array{ok: bool, message: string, ticket_id?: int}
     */
    public function open($user, array $in, bool $mobile = false): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'message' => 'Bike tickets are not set up yet.'];
        }
        if (!$user) return ['ok' => false, 'message' => 'Not signed in.'];
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) {
            return ['ok' => false, 'message' => 'This account is read-only.'];
        }

        $uid       = (int) $user->id;
        $isManager = $this->canManage($user, $mobile);
        $vehicleId = (int) ($in['vehicle_id'] ?? 0);
        $forUser   = (int) ($in['opened_for_user_id'] ?? 0) ?: null;

        // A rider need not say which bike — he has one. Resolving it here rather than
        // trusting the client also means he cannot open a ticket against someone else's.
        if (!$vehicleId && !$isManager) {
            $mine = $this->ownMachineIds($uid);
            if (count($mine) === 1) $vehicleId = $mine[0];
        }
        // ⭐ A MANAGER opening one FOR a rider need not know the vehicle id either — the
        //   Bikes drawer knows the man, not the machine. The registry answers it, the
        //   same way WorkshopVisitService::schedule() does. `vehicle_id` still wins.
        if (!$vehicleId && $isManager && $forUser) {
            try {
                $vehicleId = (int) ((new VehicleResolver())->currentVehicleFor($forUser) ?: 0);
            } catch (\Throwable $e) {
                $vehicleId = 0;
            }
        }
        if (!$vehicleId) {
            /**
             * ⚠⚠ TWO MACHINES IS THE COMMON CASE, NOT AN EDGE CASE (found on the device,
             *    3-Sep). A rider driving the company van still OWNS his own bike, so
             *    `ownMachineIds` returns two and the auto-resolution above declines to
             *    guess. The message then said "No bike is assigned to you right now" —
             *    flatly untrue, and a dead end: he cannot report a fault on either.
             *    Now it names them, and the app shows a picker (see `my_machines`).
             */
            $mine = $isManager ? [] : $this->ownMachineIds($uid);
            if (count($mine) > 1) {
                $labels = array_values(array_filter(array_map(
                    fn ($id) => (new VehicleResolver())->labelFor((int) $id), $mine)));
                return ['ok' => false, 'message' =>
                    'You have more than one machine (' . implode(', ', $labels)
                    . '). Choose which one this is about.'];
            }
            return ['ok' => false, 'message' => $isManager
                ? 'Choose which bike this is about.'
                : 'No bike is assigned to you right now, so there is nothing to report against. Ask a manager to raise it.'];
        }

        // ⚠ The gate is "do you hold this machine", NOT "are you a rider" — a manager
        //   raising one for someone else is the other allowed path.
        if (!$isManager && !in_array($vehicleId, $this->ownMachineIds($uid), true)) {
            return ['ok' => false, 'message' => 'That bike is not assigned to you.'];
        }

        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') return ['ok' => false, 'message' => 'Say in one line what is wrong.'];

        $category = in_array($in['category'] ?? '', self::CATEGORIES, true) ? $in['category'] : 'problem';

        // Opened FOR: a rider is always his own subject; a manager names the rider, and
        // if he names nobody the registry answers who is holding the bike today.
        if (!$isManager) {
            $forUser = $uid;
        } elseif (!$forUser) {
            try {
                $forUser = (new VehicleResolver())->riderForVehicleDay($vehicleId, date('Y-m-d'));
            } catch (\Throwable $e) {
                $forUser = null;
            }
        }

        try {
            $now = now();
            $ticketId = null;
            DB::transaction(function () use (&$ticketId, $vehicleId, $uid, $forUser, $category, $in, $title, $now) {
                $ticketId = (int) DB::table(self::T_TICKET)->insertGetId([
                    'vehicle_id'         => $vehicleId,
                    'opened_by'          => $uid,
                    'opened_for_user_id' => $forUser,
                    'category'           => $category,
                    'urgent'             => !empty($in['urgent']) ? 1 : 0,
                    'title'              => mb_substr($title, 0, 120),
                    'status'             => 'open',
                    'opened_at'          => $now,
                    'last_message_at'    => $now,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);

                // The opening description is the thread's first message, not a column on
                // the ticket — so it reads, quotes and scrolls like every later reply
                // instead of being a special case in every renderer.
                $body = trim((string) ($in['body'] ?? ''));
                if ($body !== '') {
                    DB::table(self::T_MESSAGE)->insert([
                        'ticket_id' => $ticketId, 'user_id' => $uid, 'kind' => 'text',
                        'body' => $body, 'created_at' => $now,
                    ]);
                }
            });

            // The opener has by definition read his own ticket.
            $this->markRead($uid, (int) $ticketId);

            return ['ok' => true, 'ticket_id' => (int) $ticketId, 'message' => 'Reported. A manager will look at it.'];
        } catch (\Throwable $e) {
            Log::error('VehicleTicketService::open failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not raise the ticket.'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  REPLYING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Add a message. Handles the two automatic status moves:
     *   • a manager's FIRST reply acknowledges the ticket (and stamps first_response_at,
     *     which is how long the rider waited);
     *   • a rider's reply on a ticket closed within the re-open window re-opens it.
     *
     * @param array $msg {kind, body, media_path, media_mime, duration_ms}
     * @return array{ok: bool, message: string, message_id?: int, reopened?: bool, status?: string}
     */
    public function reply($user, int $ticketId, array $msg, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Bike tickets are not set up yet.'];
        $ticket = $this->find($ticketId);
        if (!$ticket) return ['ok' => false, 'message' => 'That ticket no longer exists.'];
        if (!$this->mayRead($user, $ticket, $mobile)) {
            return ['ok' => false, 'message' => 'You cannot see that ticket.'];
        }

        $uid       = (int) $user->id;
        $isManager = $this->canManage($user, $mobile);
        $kind      = in_array($msg['kind'] ?? 'text', ['text', 'photo', 'voice'], true) ? $msg['kind'] : 'text';
        $body      = trim((string) ($msg['body'] ?? ''));

        if ($kind === 'text' && $body === '') {
            return ['ok' => false, 'message' => 'Nothing to send.'];
        }
        if ($kind !== 'text' && empty($msg['media_path'])) {
            return ['ok' => false, 'message' => 'The attachment did not upload.'];
        }

        // ⭐⭐ THE RE-OPEN RULE (owner ruling, 2-Sep).
        $reopened = false;
        if ($ticket['status'] === 'closed') {
            if ($isManager) {
                // A manager adding a note to a closed ticket is a correction to the
                // record, not a new problem — it must NOT silently reopen his own close.
                // (He reopens deliberately, via reopen().)
            } elseif (!$this->withinReopenWindow($ticket)) {
                return ['ok' => false, 'message' =>
                    'This was closed more than ' . self::REOPEN_WINDOW_DAYS . ' days ago. '
                    . 'Please raise a new ticket so it gets looked at.'];
            } else {
                $reopened = true;
            }
        }

        try {
            $now = now();
            $messageId = null;
            $newStatus = $ticket['status'];

            DB::transaction(function () use (&$messageId, &$newStatus, $ticket, $ticketId, $uid,
                                             $kind, $body, $msg, $now, $isManager, $reopened) {
                $messageId = (int) DB::table(self::T_MESSAGE)->insertGetId([
                    'ticket_id'   => $ticketId,
                    'user_id'     => $uid,
                    'kind'        => $kind,
                    'body'        => $body !== '' ? $body : null,
                    'media_path'  => $msg['media_path']  ?? null,
                    'media_mime'  => $msg['media_mime']  ?? null,
                    'duration_ms' => isset($msg['duration_ms']) ? (int) $msg['duration_ms'] : null,
                    'created_at'  => $now,
                ]);

                $update = ['last_message_at' => $now, 'updated_at' => $now];

                if ($reopened) {
                    $newStatus = 'open';
                    $update['status']     = 'open';
                    $update['closed_at']  = null;
                    $update['closed_by']  = null;
                    $update['close_note'] = null;
                }

                if ($isManager) {
                    // First manager reply = someone has picked it up. Also names him as
                    // the assignee, so a later rider reply pings HIM rather than the
                    // whole group.
                    if (empty($ticket['first_response_at'])) {
                        $update['first_response_at'] = $now;
                    }
                    if (empty($ticket['assigned_to'])) {
                        $update['assigned_to'] = $uid;
                    }
                    // ⚠ Only 'open' is promoted. 'scheduled' already outranks
                    //   'acknowledged' (Phase 2 sets it when a workshop visit is
                    //   attached), so a chatty manager must not knock it back down.
                    if (($update['status'] ?? $ticket['status']) === 'open') {
                        $newStatus = 'acknowledged';
                        $update['status'] = 'acknowledged';
                    }
                }

                DB::table(self::T_TICKET)->where('id', $ticketId)->update($update);
            });

            if ($reopened) {
                $this->system($ticketId, 'Re-opened by a reply from ' . $this->nameOf($uid) . '.');
            }
            $this->markRead($uid, $ticketId);

            return ['ok' => true, 'message_id' => (int) $messageId, 'status' => $newStatus,
                    'reopened' => $reopened, 'message' => $reopened ? 'Re-opened and sent.' : 'Sent.'];
        } catch (\Throwable $e) {
            Log::error('VehicleTicketService::reply failed', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not send that.'];
        }
    }

    /** Is a closed ticket still inside the window where a rider reply revives it? */
    public function withinReopenWindow(array $ticket): bool
    {
        if (($ticket['status'] ?? '') !== 'closed' || empty($ticket['closed_at'])) return false;
        try {
            return \Carbon\Carbon::parse($ticket['closed_at'])
                ->addDays(self::REOPEN_WINDOW_DAYS)->isFuture();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  CLOSING / RE-OPENING
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ⭐ OWNER RULING (2-Sep): ONLY a manager may close. A rider saying "it is fixed"
     *   is a message on the thread, not a state change — the person accountable for the
     *   machine decides when the matter is finished.
     */
    public function close($user, int $ticketId, ?string $note, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Bike tickets are not set up yet.'];
        if (!$this->canManage($user, $mobile)) {
            return ['ok' => false, 'message' => 'Only a manager can close a ticket.'];
        }
        $ticket = $this->find($ticketId);
        if (!$ticket) return ['ok' => false, 'message' => 'That ticket no longer exists.'];
        if ($ticket['status'] === 'closed') {
            return ['ok' => false, 'message' => 'That ticket is already closed.'];
        }

        try {
            $now  = now();
            $note = $note !== null ? mb_substr(trim($note), 0, 500) : null;
            DB::table(self::T_TICKET)->where('id', $ticketId)->update([
                'status'          => 'closed',
                'closed_at'       => $now,
                'closed_by'       => (int) $user->id,
                'close_note'      => $note ?: null,
                'last_message_at' => $now,
                'updated_at'      => $now,
            ]);
            $this->system($ticketId, 'Closed by ' . $this->nameOf((int) $user->id)
                . ($note ? ' — ' . $note : '') . '.');
            return ['ok' => true, 'message' => 'Closed.'];
        } catch (\Throwable $e) {
            Log::error('VehicleTicketService::close failed', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not close it.'];
        }
    }

    /** Deliberate re-open by a manager (distinct from a rider reply reviving one). */
    public function reopen($user, int $ticketId, bool $mobile = false): array
    {
        if (!$this->available()) return ['ok' => false, 'message' => 'Bike tickets are not set up yet.'];
        if (!$this->canManage($user, $mobile)) {
            return ['ok' => false, 'message' => 'Only a manager can re-open a ticket.'];
        }
        $ticket = $this->find($ticketId);
        if (!$ticket) return ['ok' => false, 'message' => 'That ticket no longer exists.'];
        if ($ticket['status'] !== 'closed') return ['ok' => false, 'message' => 'That ticket is not closed.'];

        try {
            DB::table(self::T_TICKET)->where('id', $ticketId)->update([
                'status' => 'open', 'closed_at' => null, 'closed_by' => null, 'close_note' => null,
                'last_message_at' => now(), 'updated_at' => now(),
            ]);
            $this->system($ticketId, 'Re-opened by ' . $this->nameOf((int) $user->id) . '.');
            return ['ok' => true, 'message' => 'Re-opened.'];
        } catch (\Throwable $e) {
            Log::error('VehicleTicketService::reopen failed', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not re-open it.'];
        }
    }

    /**
     * A line written by the app itself — a status change, or (Phase 2) a scheduled
     * workshop visit. ⭐ These live in the SAME thread as the conversation, which is
     * what makes "what happened to this complaint" answerable in one place.
     * Never throws: an audit line must not be able to fail the action it describes.
     */
    public function system(int $ticketId, string $line): void
    {
        try {
            DB::table(self::T_MESSAGE)->insert([
                'ticket_id' => $ticketId, 'user_id' => null, 'kind' => 'system',
                'body' => mb_substr($line, 0, 500), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Ticket system line not written', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  READING
    // ─────────────────────────────────────────────────────────────────────────────

    /** One ticket as a plain array, or null. */
    public function find(int $ticketId): ?array
    {
        if (!$this->available()) return null;
        try {
            $r = DB::table(self::T_TICKET)->where('id', $ticketId)->first();
            return $r ? (array) $r : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The tickets this user may see, newest activity first.
     *
     * @param array $opts {vehicle_id?: int, status?: 'open'|'closed'|'all', limit?: int}
     */
    public function listFor($user, array $opts = [], bool $mobile = false): array
    {
        if (!$this->available() || !$user) return [];
        $uid       = (int) $user->id;
        $isManager = $this->canManage($user, $mobile);
        $mine      = $isManager ? [] : $this->ownMachineIds($uid);

        // Someone who is neither a manager nor holding a machine may still have opened
        // one in the past (or had one opened for him), so we never early-return empty —
        // the WHERE below covers that case.
        try {
            $q = DB::table(self::T_TICKET . ' as t')
                ->leftJoin('t_sys_user as ob', 'ob.id', '=', 't.opened_by')
                ->leftJoin('t_sys_user as fo', 'fo.id', '=', 't.opened_for_user_id');

            if (!$isManager) {
                $q->where(function ($w) use ($uid, $mine) {
                    $w->where('t.opened_by', $uid)->orWhere('t.opened_for_user_id', $uid);
                    if ($mine) $w->orWhereIn('t.vehicle_id', $mine);
                });
            }
            if (!empty($opts['vehicle_id'])) $q->where('t.vehicle_id', (int) $opts['vehicle_id']);
            // ⭐ "This rider's tickets" — the Bikes drawer knows the man, not the machine.
            //   Filtered here rather than client-side, where a fleet-wide `limit` could
            //   silently drop his rows on a busy day.
            if (!empty($opts['user_id'])) {
                $ru = (int) $opts['user_id'];
                $q->where(fn ($w) => $w->where('t.opened_for_user_id', $ru)->orWhere('t.opened_by', $ru));
            }

            $status = $opts['status'] ?? 'all';
            if ($status === 'open')   $q->whereIn('t.status', self::OPEN_STATUSES);
            if ($status === 'closed') $q->where('t.status', 'closed');

            $rows = $q->orderByDesc('t.last_message_at')->orderByDesc('t.id')
                ->limit(min(200, max(1, (int) ($opts['limit'] ?? 60))))
                ->get(['t.*', 'ob.fullname as opened_by_name', 'fo.fullname as opened_for_name']);

            if ($rows->isEmpty()) return [];

            $ids     = $rows->pluck('id')->all();
            $unread  = $this->unreadCounts($uid, $ids);
            $labels  = $this->vehicleLabels($rows->pluck('vehicle_id')->unique()->all());

            return $rows->map(fn ($r) => $this->shape((array) $r, $labels, $unread))->values()->all();
        } catch (\Throwable $e) {
            Log::warning('VehicleTicketService::listFor failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** The full thread. Caller must have checked mayRead(). */
    public function thread(int $ticketId): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table(self::T_MESSAGE . ' as m')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'm.user_id')
                ->where('m.ticket_id', $ticketId)
                ->orderBy('m.id')
                ->get(['m.*', 'u.fullname as author_name'])
                ->map(fn ($m) => [
                    'id'          => (int) $m->id,
                    'user_id'     => $m->user_id ? (int) $m->user_id : null,
                    'author_name' => $m->kind === 'system' ? null : $m->author_name,
                    'kind'        => $m->kind,
                    'body'        => $m->body,
                    'media_url'   => $this->publicUrl($m->media_path),
                    'media_mime'  => $m->media_mime,
                    'duration_ms' => $m->duration_ms !== null ? (int) $m->duration_ms : null,
                    'created_at'  => (string) $m->created_at,
                ])->values()->all();
        } catch (\Throwable $e) {
            Log::warning('VehicleTicketService::thread failed', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** Remember how far this person has read, so the unread badge is per person. */
    public function markRead(int $userId, int $ticketId): void
    {
        if (!$this->available()) return;
        try {
            $last = (int) DB::table(self::T_MESSAGE)->where('ticket_id', $ticketId)->max('id');
            DB::table(self::T_READ)->updateOrInsert(
                ['ticket_id' => $ticketId, 'user_id' => $userId],
                ['last_read_message_id' => $last, 'updated_at' => now()]
            );
        } catch (\Throwable $e) {
            // A read marker is a convenience; never fail the read over it.
        }
    }

    /**
     * Unread message count per ticket for one person.
     * ⚠ Own messages are excluded — replying to yourself must not light up your own badge.
     *
     * @return array<int, int> ticket_id => unread
     */
    public function unreadCounts(int $userId, array $ticketIds): array
    {
        if (!$this->available() || !$ticketIds) return [];
        try {
            $marks = DB::table(self::T_READ)->where('user_id', $userId)
                ->whereIn('ticket_id', $ticketIds)->pluck('last_read_message_id', 'ticket_id');
            $rows = DB::table(self::T_MESSAGE)
                ->whereIn('ticket_id', $ticketIds)
                ->where(function ($w) use ($userId) {
                    $w->whereNull('user_id')->orWhere('user_id', '<>', $userId);
                })
                ->get(['ticket_id', 'id']);
            $out = [];
            foreach ($rows as $r) {
                $seen = (int) ($marks[$r->ticket_id] ?? 0);
                if ((int) $r->id > $seen) {
                    $out[(int) $r->ticket_id] = ($out[(int) $r->ticket_id] ?? 0) + 1;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * What the banners need: how many open tickets this user is concerned with, and the
     * newest one. Deliberately cheap — it is polled.
     *
     * `latest_id` drives the mobile NotificationBanner high-water mark, so it must be
     * the ticket id and must only move when something genuinely newer appears.
     */
    public function summaryFor($user, bool $mobile = false): array
    {
        $empty = ['count' => 0, 'unread' => 0, 'latest_id' => 0, 'latest' => null, 'can_manage' => false];
        if (!$this->available() || !$user) return $empty;

        $open = $this->listFor($user, ['status' => 'open', 'limit' => 50], $mobile);
        /**
         * ⚠⚠ array_merge, NOT the `+` union operator. `$empty` ALREADY carries
         *    'can_manage' => false, and PHP's `+` keeps the LEFT operand's key — so
         *    `$empty + ['can_manage' => true]` silently evaluates to FALSE, and a
         *    manager with no open tickets was reported as not a manager at all.
         *    Latent today (the banner does not render at count 0), but a trap for the
         *    next consumer that reads this payload to decide what to show. Found by
         *    probing Qasim's own screens, 3-Sep.
         */
        if (!$open) return array_merge($empty, ['can_manage' => $this->canManage($user, $mobile)]);

        /**
         * ⭐⭐ `latest_id` IS THE NEWEST MESSAGE ID, NOT THE NEWEST TICKET ID.
         *
         * The banner only re-fires when this number goes UP (a per-device high-water
         * mark — see components/NotificationBanner). Keyed to the ticket id it would
         * announce a ticket once and then stay silent forever, so the rider waiting
         * for an answer — the person the feature exists for — would never be told his
         * bike had been replied to. Keyed to the message id, every new reply on any
         * ticket he can see lights it up exactly once.
         *
         * ⚠⚠ Own messages are EXCLUDED here too (Sep-4 review). The earlier reasoning —
         *    "sending a message moves your own watermark forward" — was wrong in practice:
         *    the device's high-water mark only advances when the banner is PRESSED, so a
         *    rider's own reply came back on the next poll as a fresh alert about his own
         *    words. System lines (user_id NULL: closed, scheduled) still count — those are
         *    exactly what he should be told about.
         */
        $ids = array_column($open, 'id');
        $uid = (int) ($user->id ?? 0);
        $latestMessageId = 0;
        try {
            $latestMessageId = (int) DB::table(self::T_MESSAGE)->whereIn('ticket_id', $ids)
                ->where(function ($q) use ($uid) { $q->whereNull('user_id')->orWhere('user_id', '!=', $uid); })
                ->max('id');
        } catch (\Throwable $e) {
            $latestMessageId = 0;
        }

        return [
            'count'      => count($open),
            'unread'     => array_sum(array_column($open, 'unread')),
            'latest_id'  => $latestMessageId ?: (int) $open[0]['id'],
            'latest'     => $open[0],
            'can_manage' => $this->canManage($user, $mobile),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  helpers
    // ─────────────────────────────────────────────────────────────────────────────

    private function shape(array $r, array $labels, array $unread): array
    {
        return [
            'id'                 => (int) $r['id'],
            'vehicle_id'         => (int) $r['vehicle_id'],
            'vehicle_name'       => $labels[(int) $r['vehicle_id']] ?? null,
            'opened_by'          => (int) $r['opened_by'],
            'opened_by_name'     => $r['opened_by_name'] ?? null,
            'opened_for_user_id' => $r['opened_for_user_id'] ? (int) $r['opened_for_user_id'] : null,
            'opened_for_name'    => $r['opened_for_name'] ?? null,
            'category'           => (string) $r['category'],
            'urgent'             => (bool) $r['urgent'],
            'title'              => (string) $r['title'],
            'status'             => (string) $r['status'],
            'is_open'            => in_array((string) $r['status'], self::OPEN_STATUSES, true),
            'assigned_to'        => $r['assigned_to'] ? (int) $r['assigned_to'] : null,
            'workshop_visit_id'  => $r['workshop_visit_id'] ? (int) $r['workshop_visit_id'] : null,
            'request_id'         => $r['request_id'] ? (int) $r['request_id'] : null,
            'opened_at'          => (string) $r['opened_at'],
            'first_response_at'  => $r['first_response_at'] ? (string) $r['first_response_at'] : null,
            'closed_at'          => $r['closed_at'] ? (string) $r['closed_at'] : null,
            'close_note'         => $r['close_note'] ?? null,
            'last_message_at'    => $r['last_message_at'] ? (string) $r['last_message_at'] : null,
            'unread'             => (int) ($unread[(int) $r['id']] ?? 0),
            // Lets a client grey out the composer without duplicating the rule.
            'can_reply'          => (string) $r['status'] !== 'closed' || $this->withinReopenWindow($r),
        ];
    }

    /** Plate else nickname — the SAME rule every other fleet surface uses. */
    private function vehicleLabels(array $vehicleIds): array
    {
        if (!$vehicleIds) return [];
        try {
            $res = new VehicleResolver();
            $out = [];
            foreach ($vehicleIds as $vid) $out[(int) $vid] = $res->labelFor((int) $vid);
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function nameOf(?int $userId): string
    {
        if (!$userId) return 'the system';
        try {
            return (string) (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'someone');
        } catch (\Throwable $e) {
            return 'someone';
        }
    }

    /** Same door the condition photos use — works on shared hosting with no symlink. */
    private function publicUrl(?string $path): ?string
    {
        if (!$path) return null;
        $rel = '/public-storage/' . ltrim($path, '/');
        try {
            $base = request() ? request()->getSchemeAndHttpHost() : null;
        } catch (\Throwable $e) {
            $base = null;
        }
        return $base ? rtrim($base, '/') . $rel : $rel;
    }
}
