<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class FirebaseService
{
    /**
     * Audience for service-due pushes. ⚠ Deliberately NOT
     * `receive_bike_meter_alerts` — a machine needing work and a rider forgetting
     * a reading are different duties with different people responsible.
     * Mirrors BikeServiceAlerts::PERMISSION.
     */
    public const SERVICE_ALERT_PERMISSION = 'receive_service_alerts';

    protected string $projectId;
    protected ?string $credentialsPath;

    public function __construct()
    {
        $this->projectId = config('whatsapp.firebase_project_id', '');
        $credFile = config('whatsapp.firebase_credentials_path', 'firebase-service-account.json');
        $this->credentialsPath = base_path($credFile);
    }

    /**
     * Send a push notification to all active devices of users who have the
     * WhatsApp view permission — either full (view_whatsapp_messages) or
     * limited (view_whatsapp_messages_limited). Limited users are included
     * because the push is always about a fresh (today) inbound message, which
     * falls inside their "today + yesterday" visibility window, so opening
     * the conversation will show the message fine.
     */
    public function notifyNewWhatsAppMessage(string $senderName, string $preview, ?int $conversationId = null): void
    {
        $this->sendToPermissionGroups(['view_whatsapp_messages', 'view_whatsapp_messages_limited'], [
            'title' => "New message from {$senderName}",
            'body' => mb_substr($preview, 0, 200),
        ], [
            'type' => 'whatsapp_message',
            'conversation_id' => (string)($conversationId ?? ''),
            'sender' => $senderName,
        ], 'whatsapp_messages', null,
            // Route to NF Messages for anyone who has it — otherwise a user with
            // both APKs installed gets two notifications for the same message.
            // Users without it keep getting pushed on the primary app.
            'messages');
    }

    /**
     * Send a push notification to a SINGLE user's active devices. Used for
     * targeted notifications like WhatsApp conversation @mentions where
     * we want only the mentioned staff member to be pinged — not the
     * whole permission group. Best-effort: silently no-ops if the user
     * has no active device tokens or Firebase isn't configured.
     */
    public function notifyUser(int $userId, array $notification, array $data = [], string $channelId = 'whatsapp_messages', ?string $preferFlavor = null): void
    {
        if (!$this->projectId || !file_exists($this->credentialsPath)) {
            Log::debug('Firebase: Skipping user push (not configured)', ['user_id' => $userId]);
            return;
        }

        try {
            $rows = DB::table('t_wa_device_tokens')
                ->where('user_id', $userId)
                ->where('is_active', 1)
                ->select('fcm_token', 'user_id', 'app_flavor')
                ->get()
                ->all();

            // Only WhatsApp-flavoured pushes prefer the messaging app. Callers
            // that pass no $preferFlavor (e.g. ShiftController's shift-assigned
            // pings) keep hitting EVERY device — routing those to NF Messages,
            // which has no shift screen at all, would simply lose them.
            if ($preferFlavor !== null) {
                $rows = $this->preferFlavorPerUser($rows, $preferFlavor);
            }

            $tokens = array_map(fn($r) => $r->fcm_token, $rows);

            if (empty($tokens)) return;

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                Log::error('Firebase: Failed to get access token for notifyUser', ['user_id' => $userId]);
                return;
            }

            foreach ($tokens as $fcmToken) {
                $this->sendToDevice($accessToken, $fcmToken, $notification, $data, $channelId);
            }
        } catch (\Exception $e) {
            Log::error('Firebase: notifyUser failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Convenience wrapper: tell a specific user they have been mentioned
     * on a WhatsApp conversation. Picks a consistent notification shape
     * so mobile can deep-link straight into the conversation and highlight
     * the mention on arrival.
     */
    public function notifyWhatsAppMention(
        int $mentionedUserId,
        string $mentionedByName,
        string $conversationDisplayName,
        int $conversationId
    ): void {
        $this->notifyUser($mentionedUserId, [
            'title' => "{$mentionedByName} tagged you",
            'body'  => "on the chat with {$conversationDisplayName}",
        ], [
            'type'            => 'whatsapp_mention',
            'conversation_id' => (string) $conversationId,
            'mentioned_by'    => $mentionedByName,
        ], 'whatsapp_messages',
            // Same duplicate-suppression as a normal message push: a user with
            // both APKs should be tagged once, in the messaging app.
            'messages');
    }

    /**
     * Send a push notification when a new production plan/demand is created
     */
    public function notifyNewPlan(string $createdByName, string $demandDate, int $demandId, float $totalKg): void
    {
        $dateFormatted = date('M d', strtotime($demandDate));

        $this->sendToPermissionGroup('access_khaas_mode', [
            'title' => "New Plan for {$dateFormatted}",
            'body' => "{$createdByName} submitted a production plan ({$totalKg} kg) for {$dateFormatted}",
        ], [
            'type' => 'khaas_plan',
            'demand_id' => (string) $demandId,
            'demand_date' => $demandDate,
        ], 'khaas_planning');
    }

    /**
     * A store/manager has ASKED the warehouse to send stock.
     *
     * Sent immediately (unlike the batched store-transfer alert further down): a
     * request is a person waiting on an answer, not a stock movement that has
     * already happened, so debouncing it would only add delay. Volume is low —
     * one per product per shelf gap, and edits deliberately do NOT re-push.
     *
     * ⚠ Reuses the EXISTING 'khaas_planning' Android channel on purpose. Android
     * channels are created at app install; a brand-new channel id would not exist
     * on any APK already out there and the notification would be dropped silently.
     * Targets access_khaas_mode — the warehouse side, i.e. whoever can fulfil it.
     */
    public function notifyTransferRequest($transferRequest): void
    {
        $productName = $transferRequest->product?->title
            ?? \App\Models\CRM\ProductModel::where('id', $transferRequest->product_id)->value('title')
            ?? 'an item';
        $requesterName = $transferRequest->requester?->fullname
            ?? \App\Models\User::where('id', $transferRequest->requested_by)->value('fullname')
            ?? 'The store';

        $this->sendToPermissionGroup('access_khaas_mode', [
            'title' => 'Store needs stock',
            'body' => "{$requesterName} requested {$transferRequest->quantity} × {$productName} — tap to send.",
        ], [
            'type' => 'khaas_transfer_request',
            'request_id' => (string) $transferRequest->id,
            'product_id' => (string) $transferRequest->product_id,
        ], 'khaas_planning',
            // The manager who asked often holds access_khaas_mode himself —
            // never buzz the actor about their own action.
            (int) $transferRequest->requested_by);
    }

    /**
     * Combined (debounced) store-transfer alerts. Instead of one push per moved
     * item, this batches all pending Warehouse->Store transfers per store into ONE
     * push once the batch has SETTLED (no new move for 5 min) or CAPPED (oldest is
     * 10 min old). Poll-driven — called from the store/khaas polling endpoints via
     * app()->terminating(), NOT a cron. A cache mutex keeps it to ~once per 25s no
     * matter how many pollers hit it. Each transfer is stamped alert_batched_at so
     * it's alerted at most once; already-accepted (status != pending) transfers are
     * never alerted. Targets the 'receive_store_transfer_alerts' permission group.
     */
    public function flushDueTransferAlerts(): void
    {
        // Run at most once per ~25s regardless of how many endpoints trigger us.
        if (!\Cache::add('store_transfer_alert_flush_lock', 1, 25)) {
            return;
        }

        try {
            $model = \App\Models\CRM\WarehouseTransferModel::class;
            $now = now();
            $settleBefore = $now->copy()->subMinutes(5);  // batch is "done" if newest move >= 5 min old
            $capBefore    = $now->copy()->subMinutes(10); // never wait longer than 10 min

            // Group pending, not-yet-alerted store transfers by business unit.
            $groups = $model::where('status', $model::STATUS_PENDING)
                ->where('to_location', 'store')
                ->whereNull('alert_batched_at')
                ->selectRaw('business_unit_id, COUNT(*) as cnt, MIN(created_at) as oldest, MAX(created_at) as newest')
                ->groupBy('business_unit_id')
                ->get();

            foreach ($groups as $g) {
                $newest = $g->newest ? \Carbon\Carbon::parse($g->newest) : null;
                $oldest = $g->oldest ? \Carbon\Carbon::parse($g->oldest) : null;
                $settled = $newest && $newest->lte($settleBefore);
                $capped  = $oldest && $oldest->lte($capBefore);
                if (!$settled && !$capped) {
                    continue; // batch still forming — wait for it to settle
                }

                $transfers = $model::where('status', $model::STATUS_PENDING)
                    ->where('to_location', 'store')
                    ->whereNull('alert_batched_at')
                    ->where('business_unit_id', $g->business_unit_id)
                    ->with('product:id,title')
                    ->orderBy('created_at')
                    ->get();
                if ($transfers->isEmpty()) {
                    continue;
                }

                $count = $transfers->count();
                if ($count === 1) {
                    $t0 = $transfers->first();
                    $qty = rtrim(rtrim(number_format((float) $t0->quantity, 2, '.', ''), '0'), '.');
                    $name = optional($t0->product)->title ?? 'Frozen product';
                    $body = "{$qty} × {$name} — tap to accept.";
                } else {
                    $body = "{$count} frozen items arrived at the store — tap to accept.";
                }

                $this->sendToPermissionGroup('receive_store_transfer_alerts', [
                    'title' => 'Frozen stock arrived',
                    'body'  => $body,
                ], [
                    'type'        => 'store_transfer_pending',
                    'transfer_id' => (string) $transfers->last()->id,
                    'count'       => (string) $count,
                ], 'store_transfers');

                // Stamp them so this batch never re-alerts.
                $model::whereIn('id', $transfers->pluck('id')->all())
                    ->update(['alert_batched_at' => $now]);
            }
        } catch (\Throwable $e) {
            Log::warning('flushDueTransferAlerts failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Notify management that a NEW leave request was added and is pending review.
     * Fired at leave-create time (pending leaves only). Targets the
     * 'receive_leave_alerts' mobile-permission group.
     */
    public function notifyLeaveAdded(int $requestId, string $applicantName, ?string $dateRange = null, ?int $excludeUserId = null): void
    {
        $body = $dateRange
            ? "{$applicantName} applied for leave ({$dateRange}) — tap to review."
            : "{$applicantName} applied for leave — tap to review.";

        $this->sendToPermissionGroup('receive_leave_alerts', [
            'title' => 'New leave request',
            'body'  => $body,
        ], [
            'type'       => 'leave_added',
            'request_id' => (string) $requestId,
        ], 'leave_updates', $excludeUserId);
    }

    /**
     * U4 — escalate to management when a company-bike rider came home late or forgot to record his
     * meter (single strong alert to the rider on arrival happens elsewhere; this is the 10-minute
     * escalation). Targets whoever holds 'receive_bike_meter_alerts' (owner assigns the roles).
     */
    public function notifyHomeMeterMissed(int $attendanceId, string $riderName, string $state, ?int $minutesLate = null): void
    {
        $late = $minutesLate ? " ({$minutesLate} min late)" : '';
        $body = $state === 'late_locked'
            ? "{$riderName} reached home late{$late} and hasn't recorded his bike meter — it's locked. Unlock or enter it."
            : "{$riderName} is home but hasn't recorded his bike meter{$late}. Please follow up.";

        $this->sendToPermissionGroup('receive_bike_meter_alerts', [
            'title' => '🏍 Bike meter not recorded',
            'body'  => $body,
        ], [
            'type'          => 'home_meter_missed',
            'attendance_id' => (string) $attendanceId,
        ], 'shift_notifications');
    }

    /**
     * 🗓 A month has ended with absences nobody has decided about (Sep-2026).
     *
     * Left alone, those days are simply deducted the moment someone presses Pay — so "nobody
     * looked at it" and "we decided to cut it" become the same thing after the fact. This is
     * the nudge to look before that happens.
     *
     * Audience is `manage_payroll`: the key that already gates the Payroll screen, so the
     * people told are exactly the people who can act, and nobody else learns what anyone earns.
     *
     * ⚠ There is no scheduler on prod (`schedule:run` has never run), so this cannot fire on a
     * timer. The Payroll screen loading triggers it, deduped per month in
     * `t_ops_service_alert_push` — the same mechanism the bike-service alerts use — so a month
     * is announced once rather than once per page view.
     */
    public function notifyAbsenceDecisionsDue(string $month, int $employees, float $days): void
    {
        $label = date('F Y', strtotime($month . '-01'));
        $who   = $employees === 1 ? '1 employee' : $employees . ' employees';
        $dayTxt = rtrim(rtrim(number_format($days, 1), '0'), '.');
        $this->sendToPermissionGroup('manage_payroll', [
            'title' => '🗓 Absences waiting on a decision',
            'body'  => $label . ' has ended and ' . $who . ' have absences nobody has decided about '
                . '(' . $dayTxt . ' days). Unless you park or excuse them, paying will deduct them.',
        ], [
            'type'  => 'absence_decisions_due',
            'month' => $month,
        ], 'shift_notifications');
    }

    /**
     * 🛢 A scheduled service is DUE on a machine (Aug-2026).
     *
     * ⚠⚠ NOT the same as notifyHomeMeterMissed — that is about a RIDER forgetting
     *    a reading; this is about a MACHINE needing work. Separate audience key
     *    (`receive_service_alerts`) on purpose, so silencing one never silences the
     *    other.
     *
     * TWO audiences, both told (owner ruling Aug-12):
     *   • the managers who hold the permission — every machine;
     *   • the rider actually holding THIS machine — because he is the one who has
     *     to take it in, and he qualifies by holding it, not by permission.
     *     `notifyUser` is safe if he holds no key at all.
     *
     * Called once per service cycle — BikeServiceAlerts owns that dedupe.
     */
    public function notifyServiceDue(array $alert): void
    {
        $title = ($alert['state'] ?? '') === 'overdue'
            ? '🛢 Service overdue'
            : '🛢 Service due soon';
        $body  = (string) ($alert['message'] ?? 'A bike is due for service.');

        $data = [
            'type'       => 'bike_service_due',
            'vehicle_id' => (string) ($alert['vehicle_id'] ?? ''),
            'type_id'    => (string) ($alert['type_id'] ?? ''),
            'alert_key'  => (string) ($alert['alert_key'] ?? ''),
        ];

        // Managers first — the whole fleet is their problem.
        $this->sendToPermissionGroup(self::SERVICE_ALERT_PERMISSION,
            ['title' => $title, 'body' => $body], $data, 'shift_notifications');

        // …then the man holding it. Worded for him: he does not need the plate he
        // is sitting on, he needs to know his own bike is due.
        $keeper = $alert['keeper_user_id'] ?? null;
        if ($keeper) {
            // 🗣 Roman Urdu — the keeper is a RIDER and he is the one who has to take the
            // bike in (owner ruling). The group title above stays English for the managers.
            $hisTitle = ($alert['state'] ?? '') === 'overdue'
                ? '🛢 Aap ki bike ki service late ho chuki hai'
                : '🛢 Aap ki bike ki service aane wali hai';
            $his = ($alert['state'] ?? '') === 'overdue'
                ? 'Aap ki bike ka ' . ($alert['type_name'] ?? 'service') . ' '
                    . number_format(abs((int) ($alert['due_in_km'] ?? 0))) . ' km late ho chuka hai.'
                : 'Aap ki bike ka ' . ($alert['type_name'] ?? 'service') . ' '
                    . number_format((int) ($alert['due_in_km'] ?? 0)) . ' km baad hai.';
            try {
                $this->notifyUser((int) $keeper, ['title' => $hisTitle, 'body' => $his],
                                  $data, 'shift_notifications');
            } catch (\Throwable $e) {
                Log::warning('service-due push to keeper failed', [
                    'user_id' => $keeper, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify store users that a rider re-timed his OWN route while already
     * part-way through his deliveries.
     *
     * Why this is worth a push: the delivery times a customer was given are a
     * commitment, and a rider who is running late can press Re-dispatch and be
     * handed fresh, later ones. The promise itself is protected in the reports
     * (EtaPromiseService), so this is not about the numbers — it is so the store
     * finds out WHILE it is happening, from the person's name, rather than in
     * tomorrow's report.
     *
     * Fires only when he pressed it himself AND had already delivered stops —
     * a re-time before leaving the office changes nobody's expectations.
     * Targets the same 'receive_dispatch_alerts' group the store banners use.
     */
    public function notifyMidRunRouteChange(
        int $riderId,
        string $riderName,
        int $deliveredBefore,
        int $remaining,
        ?int $avgShiftMinutes = null
    ): void {
        $shift = '';
        if ($avgShiftMinutes !== null && $avgShiftMinutes !== 0) {
            $dir = $avgShiftMinutes > 0 ? 'later' : 'earlier';
            $shift = ' Times moved ~' . abs($avgShiftMinutes) . " min {$dir}.";
        }
        $body = "{$riderName} re-timed his route mid-run ({$deliveredBefore} delivered, {$remaining} left).{$shift}";

        $this->sendToPermissionGroup('receive_dispatch_alerts', [
            'title' => '⚠️ Route re-timed mid-run',
            'body'  => $body,
        ], [
            'type'             => 'mid_run_route_change',
            'rider_id'         => (string) $riderId,
            'delivered_before' => (string) $deliveredBefore,
            'remaining'        => (string) $remaining,
        ], 'dispatch_alerts', $riderId); // never push it back to the rider himself
    }

    /**
     * ⚠️ A van driver drove away from a meet-up with somebody's boxes still
     * aboard (Aug-2026). Pressing "Done" is an ABANDON — the normal end of a
     * meet-up is the last rider scanning his last box, which closes it by
     * itself — so the store is told the moment it happens, the same way a meter
     * or verified-pin bypass is reported, rather than discovering it tomorrow
     * when the boxes show as never handed over.
     *
     * Same 'receive_dispatch_alerts' audience as the other van/dispatch alerts.
     */
    /**
     * 🆘 A rider at the van cannot scan a label (Sep-2026). The manager's no-scan
     * handover is the exit, and it lives on the store Van tab / web van card —
     * this is what makes the ask reach whoever holds those screens.
     * Same 'receive_dispatch_alerts' audience as the other van alerts.
     */
    public function notifyVanHandoverHelp(int $orderId, string $orderNumber, int $riderId, string $riderName, string $reason): void
    {
        $this->sendToPermissionGroup('receive_dispatch_alerts', [
            'title' => '🆘 Van handover — label scan nahi ho raha',
            'body'  => "{$riderName} · {$orderNumber}: {$reason}. Van tab se \"Bina scan handover\" record karein.",
        ], [
            'type'     => 'van_handover_help',
            'order_id' => (string) $orderId,
            'rider_id' => (string) $riderId,
        ], 'dispatch_alerts', $riderId); // never push it back to the rider himself
    }

    public function notifyVanStopForceClosed(int $driverId, string $driverName, string $awaitingSummary): void
    {
        $this->sendToPermissionGroup('receive_dispatch_alerts', [
            'title' => '⚠️ Van meet-up closed early',
            'body'  => "{$driverName} closed the meet-up while {$awaitingSummary} still had orders on the van.",
        ], [
            'type'        => 'van_stop_forced',
            'van_user_id' => (string) $driverId,
        ], 'dispatch_alerts', $driverId); // never push it back to the driver himself
    }

    /**
     * Generic: send notifications to all users with a given permission
     */
    /**
     * 🔁 A RIDER IS ASKING FOR A VEHICLE (Sep-2026).
     *
     * Rajab asks for the van at 8 a.m. and then waits: until someone approves, the
     * registry still says he is on his own bike, so his meters, his fuel rules and
     * the van's kilometres all follow the wrong machine. A banner only works if
     * somebody happens to be looking at the right screen — this is what makes the
     * ask reach Shabib and Taimur wherever they are.
     *
     * ⚠⚠ TARGETED BY THE **WEB** `assign_vehicles` PERMISSION, NOT A MOBILE ONE.
     *    `sendToPermissionGroup` joins the mobile permission tables, but the right
     *    to approve a handover is `hasPermission('assign_vehicles')` — the web key
     *    that `canApprove()` and `canManage()` actually ask. Pushing to a different
     *    audience than the one that can act is how people get buzzed about work
     *    they cannot do (and how the people who CAN act get missed). Resolving the
     *    real holders keeps the notification and the button in exact step.
     *
     * ⚠ Never buzzes the rider about his own request, and never the approver about
     *   a request he raised himself.
     */
    public function notifyVehicleHandoverRequest(int $requestId, string $riderName,
                                                 string $direction, string $vehicleName,
                                                 ?int $excludeUserId = null): void
    {
        try {
            $ids = [];
            foreach (\App\Models\User::where('is_active', '1')->get() as $u) {
                if ($excludeUserId !== null && (int) $u->id === $excludeUserId) continue;
                if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
                if ($u->hasPermission('assign_vehicles')) $ids[] = (int) $u->id;
            }
            if (empty($ids)) return;

            $body = $direction === 'return'
                ? "{$riderName} wants to hand back {$vehicleName} — tap to approve."
                : "{$riderName} is asking for {$vehicleName} — tap to approve.";

            foreach ($ids as $uid) {
                $this->notifyUser($uid, [
                    'title' => 'Vehicle change requested',
                    'body'  => $body,
                ], [
                    'type'       => 'vehicle_handover_request',
                    'request_id' => (string) $requestId,
                    'direction'  => $direction,
                ], 'shift_notifications');
            }
        } catch (\Throwable $e) {
            // A request that was recorded must never fail because a push did.
            Log::warning('Firebase: vehicle handover push failed', [
                'request_id' => $requestId, 'error' => $e->getMessage(),
            ]);
        }
    }
    /**
     * 🛠 BIKE TICKETS (Sep-2026) — a rider reported a fault, or a manager answered.
     *
     * ⭐ THE AUDIENCE FOLLOWS THE DIRECTION OF THE CONVERSATION, which is the whole
     *   point of a ticket rather than a broadcast:
     *     • opened   → the managers who hold `receive_vehicle_ticket_alerts`
     *                  (RULED 2-Sep: Qasim, Shabib, Taimur — NOT Farooq, who gets
     *                  workshop alerts only in Phase 2);
     *     • replied  → if a manager spoke, the RIDER hears it (no permission needed —
     *                  it is his bike); if the rider spoke, the manager who took the
     *                  ticket hears it, falling back to the group when nobody has;
     *     • closed   → the rider, so an answered complaint visibly ends.
     *
     * ⚠ The actor is always excluded: nobody should be buzzed by their own message.
     * ⚠ Reuses the EXISTING `shift_notifications` channel. An Android channel is created
     *   at install time, so a brand-new id would not exist on any APK already in the
     *   field and the notification would be dropped silently — the same trap the vehicle
     *   handover pushes documented.
     */
    public function notifyVehicleTicket(string $event, int $ticketId, int $actorId): void
    {
        try {
            $t = DB::table(\App\Services\Riders\VehicleTicketService::T_TICKET)
                ->where('id', $ticketId)->first();
            if (!$t) return;

            $bike = null;
            try {
                $bike = (new \App\Services\Riders\VehicleResolver())->labelFor((int) $t->vehicle_id);
            } catch (\Throwable $e) {
                $bike = null;
            }
            $bike  = $bike ?: 'bike';   // reads correctly in both the English and Roman Urdu lines
            $actor = DB::table('t_sys_user')->where('id', $actorId)->value('fullname') ?: 'Someone';

            // The rider side of the conversation: who it was raised for, else who raised it.
            /**
             * ⭐⭐ THE RIDER TO TELL IS WHOEVER HOLDS THE MACHINE NOW (owner ruling 5-Sep-2026).
             *
             *    This used to be `opened_for_user_id ?: opened_by` — the man who RAISED it. Since
             *    tickets follow the machine (`VehicleTicketService::visibilityScope`), the raiser
             *    may no longer be able to open the thread at all: Waseem reported the chain, the
             *    bike went to Rajab, Qasim replied — and the push would have gone to Waseem, who
             *    taps it and is told "You cannot see that ticket", while Rajab, who can, hears
             *    nothing. The registry already knows the keeper; ask it.
             *
             * ⚠ An UNASSIGNED machine has no keeper, so no rider push — managers only, exactly as
             *   the visibility rule says. Never falls back to the raiser: a push to a thread you
             *   cannot open is worse than silence.
             */
            $riderId = 0;
            try {
                $k = (new \App\Services\Riders\VehicleService())->keeperOf((int) $t->vehicle_id);
                $riderId = $k ? (int) $k->user_id : 0;
            } catch (\Throwable $e) {
                $riderId = 0;
            }
            $data = [
                'type'      => 'vehicle_ticket',
                'ticket_id' => (string) $ticketId,
                'vehicle_id'=> (string) $t->vehicle_id,
            ];

            if ($event === 'opened') {
                $this->sendToPermissionGroup(
                    \App\Services\Riders\VehicleTicketService::ALERT_PERMISSION,
                    [
                        'title' => ($t->urgent ? '🔴 Bike problem — not rideable' : '🛠 Bike problem reported'),
                        'body'  => "{$actor}: {$bike} — {$t->title}",
                    ],
                    $data, 'shift_notifications', $actorId
                );
                return;
            }

            /**
             * ⭐ RIDER-FACING COPY IS ROMAN URDU (owner ruling, 2-Sep). The people who act on
             *   these are riders, and a banner they cannot read is a banner that does not work.
             *   MANAGER-facing lines above/below stay English — that audience reads the ledger
             *   and the reports in English all day. See [[alerts-copy-roman-urdu]].
             */
            if ($event === 'closed') {
                if ($riderId && $riderId !== $actorId) {
                    $this->notifyUser($riderId, [
                        'title' => '✅ Bike ka masla hal ho gaya',
                        'body'  => "{$actor} ne band kiya: {$bike} — {$t->title}",
                    ], $data, 'shift_notifications');
                }
                return;
            }

            /**
             * ⭐⭐ A REPLY REACHES EVERYONE ALREADY IN THE CONVERSATION (owner, 5-Sep: "there was
             *    no alert for the subsequent messages").
             *
             *    It used to tell exactly ONE person and stop:
             *      • a manager replied → only the rider;
             *      • a rider replied   → only the ASSIGNED manager.
             *    So on a ticket Qasim and Shabib were both working, neither ever heard the other.
             *    Measured on the replica before this change, over a four-message thread: Qasim was
             *    told once, Shabib never — and with the bike unassigned, a manager's reply told
             *    NOBODY at all.
             *
             *    A ticket thread is a chat. So the audience is: the machine's CURRENT keeper, the
             *    manager it is assigned to, and every manager who has already spoken in it —
             *    minus whoever just spoke.
             *
             * ⚠ Only people who can still OPEN the thread. Since tickets follow the machine, a
             *   PREVIOUS keeper cannot; pushing him would be a notification to something he is
             *   refused. That is why posters are filtered through `canManage` rather than simply
             *   included.
             * ⚠ Deliberately NOT the whole alert group on every message — that is the "opened"
             *   audience. Being in the conversation is what earns the follow-ups. The group is
             *   only the fallback when the conversation has nobody else in it yet.
             */
            $assigned   = (int) ($t->assigned_to ?: 0);
            $recipients = [];
            if ($riderId)  $recipients[$riderId]  = 'rider';
            if ($assigned) $recipients[$assigned] = 'manager';
            try {
                $tickets = new \App\Services\Riders\VehicleTicketService();
                $posters = DB::table(\App\Services\Riders\VehicleTicketService::T_MESSAGE)
                    ->where('ticket_id', $ticketId)
                    ->whereNotNull('user_id')
                    ->distinct()->pluck('user_id');
                foreach ($posters as $p) {
                    $p = (int) $p;
                    if ($p <= 0 || isset($recipients[$p])) continue;
                    $u = \App\Models\User::find($p);
                    if ($u && $tickets->canManage($u, true)) $recipients[$p] = 'manager';
                }
            } catch (\Throwable $e) {
                // The keeper and the assignee are already in — a failed thread read must not
                // cost the notification altogether.
            }
            unset($recipients[$actorId]);

            if (!$recipients) {
                // Nobody is in the conversation yet (a rider answering an untouched ticket, or a
                // manager writing on a machine nobody holds): reach the managers' group instead
                // of silently telling no one.
                $this->sendToPermissionGroup(
                    \App\Services\Riders\VehicleTicketService::ALERT_PERMISSION,
                    [
                        'title' => '🛠 New reply on a bike ticket',
                        'body'  => "{$actor}: {$bike} — {$t->title}",
                    ],
                    $data, 'shift_notifications', $actorId
                );
                return;
            }
            foreach ($recipients as $uid => $role) {
                $this->notifyUser((int) $uid, $role === 'rider'
                    // 🗣 The rider reads Roman Urdu; managers read English (owner ruling, 2-Sep).
                    ? ['title' => '🛠 Aap ke bike ka jawab aaya',
                       'body'  => "{$actor}: {$bike} — {$t->title}"]
                    : ['title' => '🛠 New reply on a bike ticket',
                       'body'  => "{$actor}: {$bike} — {$t->title}"],
                    $data, 'shift_notifications');
            }
        } catch (\Throwable $e) {
            // A ticket that was recorded must never fail because a push did.
            Log::warning('Firebase: vehicle ticket push failed', [
                'ticket_id' => $ticketId, 'event' => $event, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 🔧 WORKSHOP VISITS (Sep-2026) — a bike has been booked in, or a rider confirmed.
     *
     * ⭐ TWO AUDIENCES, AND THEY ARE NOT THE SAME AS THE TICKET ONES:
     *     • scheduled → the NAMED RIDER (he has to accept it — no permission involved,
     *       it is an instruction addressed to him) AND the managers holding
     *       `receive_workshop_alerts`, which RULED to include Farooq (role 20) because
     *       he plans the shifts and must know a rider is out that day;
     *     • accepted  → the managers, so "awaiting Asim" visibly becomes "confirmed";
     *     • cancelled → the rider, so he does not turn up to a cancelled appointment;
     *     • reminder  → the rider the day before (fired from the banner poll, since
     *       prod has no cron).
     *
     * ⚠ Reuses the EXISTING `shift_notifications` channel — a channel id an installed
     *   APK never created is dropped silently.
     */
    public function notifyWorkshopVisit(string $event, int $visitId, int $actorId): void
    {
        try {
            $v = DB::table(\App\Services\Riders\WorkshopVisitService::T_VISIT)
                ->where('id', $visitId)->first();
            if (!$v) return;

            $bike = null;
            try {
                $bike = (new \App\Services\Riders\VehicleResolver())->labelFor((int) $v->vehicle_id);
            } catch (\Throwable $e) {
                $bike = null;
            }
            $bike  = $bike ?: 'bike';   // reads correctly in both the English and Roman Urdu lines
            $when  = \Carbon\Carbon::parse($v->visit_date)->format('D j M')
                   . ($v->visit_time ? ' at ' . substr((string) $v->visit_time, 0, 5) : '');
            $rider = DB::table('t_sys_user')->where('id', $v->user_id)->value('fullname') ?: 'The rider';
            $actor = DB::table('t_sys_user')->where('id', $actorId)->value('fullname') ?: 'Someone';

            $data = [
                'type'       => 'workshop_visit',
                'visit_id'   => (string) $visitId,
                'vehicle_id' => (string) $v->vehicle_id,
            ];
            $riderId  = (int) $v->user_id;
            $ALERT    = \App\Services\Riders\WorkshopVisitService::ALERT_PERMISSION;

            if ($event === 'scheduled') {
                if ($riderId !== $actorId) {
                    // ⭐ Roman Urdu — this one has a BUTTON he must press (owner ruling).
                    $this->notifyUser($riderId, [
                        'title' => '🔧 Workshop jana hai — confirm karein',
                        'body'  => "{$bike} · {$when}. Tap kar ke confirm karein.",
                    ], $data, 'shift_notifications');
                }
                $this->sendToPermissionGroup($ALERT, [
                    'title' => '🔧 Workshop visit set',
                    'body'  => "{$rider} → {$bike}, {$when} · set by {$actor}",
                ], $data, 'shift_notifications', $actorId);
                return;
            }

            if ($event === 'accepted') {
                $this->sendToPermissionGroup($ALERT, [
                    'title' => '✅ Workshop visit confirmed',
                    'body'  => "{$rider} confirmed {$bike} on {$when}",
                ], $data, 'shift_notifications', $actorId);
                return;
            }

            if ($event === 'cancelled') {
                if ($riderId !== $actorId) {
                    $this->notifyUser($riderId, [
                        'title' => '🔧 Workshop cancel ho gaya',
                        'body'  => "{$bike} · {$when} ab nahi jana — {$actor} ne cancel kiya.",
                    ], $data, 'shift_notifications');
                }
                return;
            }

            if ($event === 'reminder') {
                $this->notifyUser($riderId, [
                    'title' => '🔧 Kal workshop jana hai',
                    'body'  => "{$bike} · {$when}"
                        . ($v->status === 'scheduled' ? '. Aap ne abhi tak confirm nahi kiya.' : ''),
                ], $data, 'shift_notifications');
                $this->sendToPermissionGroup($ALERT, [
                    'title' => '🔧 Workshop tomorrow',
                    'body'  => "{$rider} → {$bike}, {$when}"
                        . ($v->status === 'scheduled' ? ' · not confirmed' : ''),
                ], $data, 'shift_notifications');
            }
        } catch (\Throwable $e) {
            // A visit that was recorded must never fail because a push did.
            Log::warning('Firebase: workshop visit push failed', [
                'visit_id' => $visitId, 'event' => $event, 'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendToPermissionGroup(string $permissionCode, array $notification, array $data, string $channelId = 'whatsapp_messages', ?int $excludeUserId = null): void
    {
        $this->sendToPermissionGroups([$permissionCode], $notification, $data, $channelId, $excludeUserId);
    }

    /**
     * Generic: send notifications to all users that hold ANY of the given
     * mobile permissions. Used e.g. for WhatsApp messages where either a
     * full or limited viewer should receive the push.
     */
    protected function sendToPermissionGroups(array $permissionCodes, array $notification, array $data, string $channelId = 'whatsapp_messages', ?int $excludeUserId = null, ?string $preferFlavor = null): void
    {
        if (!$this->projectId || !file_exists($this->credentialsPath)) {
            Log::debug('Firebase: Skipping push notification (not configured)');
            return;
        }

        try {
            $tokens = $this->getActiveTokensForPermissions($permissionCodes);

            // Jul-2026: when a dedicated app exists for this kind of push (NF
            // Messages), send only to it for users who have it — otherwise
            // someone with both APKs gets buzzed twice for one message.
            if ($preferFlavor !== null) {
                $tokens = $this->preferFlavorPerUser($tokens, $preferFlavor);
            }

            if (empty($tokens)) {
                return;
            }

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                Log::error('Firebase: Failed to get access token');
                return;
            }

            foreach ($tokens as $tokenRecord) {
                // Never notify the actor about their own action (e.g. the manager
                // who just created the leave, or the requester who initiated it).
                if ($excludeUserId !== null && (int) $tokenRecord->user_id === $excludeUserId) {
                    continue;
                }
                $this->sendToDevice($accessToken, $tokenRecord->fcm_token, $notification, $data, $channelId);
            }
        } catch (\Exception $e) {
            Log::error('Firebase: Push notification failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get all active FCM tokens for users who have a specific mobile permission
     */
    protected function getActiveTokensForPermission(string $permissionCode): array
    {
        return $this->getActiveTokensForPermissions([$permissionCode]);
    }

    /**
     * Get all active FCM tokens for users who hold ANY of the given
     * mobile permissions (distinct by device token).
     */
    protected function getActiveTokensForPermissions(array $permissionCodes): array
    {
        if (empty($permissionCodes)) return [];

        return DB::table('t_wa_device_tokens as dt')
            ->join('t_sys_user_role as ur', 'ur.user_id', '=', 'dt.user_id')
            ->join('t_sys_role_mobile_permission as rmp', 'rmp.role_id', '=', 'ur.role_id')
            ->join('t_sys_mobile_permission as mp', 'mp.id', '=', 'rmp.mobile_permission_id')
            ->whereIn('mp.permission_code', $permissionCodes)
            ->where('dt.is_active', 1)
            // app_flavor added Jul-2026 (see NF-MESSAGES-PHASE2-app-flavor-tokens.sql).
            // Selected so preferFlavorPerUser() can route WhatsApp pushes to the
            // dedicated messaging app when a user has it installed.
            ->select('dt.fcm_token', 'dt.user_id', 'dt.app_flavor')
            ->distinct()
            ->get()
            ->all();
    }

    /**
     * Per user: if they have at least one active token for $flavor, keep ONLY
     * those and drop their other-flavor tokens. Users without a $flavor token
     * are returned untouched.
     *
     * The case this exists for: a manager with BOTH the Rider app and NF
     * Messages installed. Both register a token; both hold
     * view_whatsapp_messages; so one inbound WhatsApp message pushed to both
     * and the phone buzzed twice. This makes the dedicated app win.
     *
     * Deliberately per-USER, not global — a colleague who only has the Rider
     * app must keep receiving pushes there. And deliberately only applied to
     * push types that HAVE a dedicated app (see notifyNewWhatsAppMessage);
     * khaas / app_update / location pings are untouched and still go
     * everywhere.
     *
     * Defensive: rows from before the column existed (or from older APKs that
     * don't send it) default to 'primary', so this is a no-op for them.
     */
    protected function preferFlavorPerUser(array $tokens, string $flavor): array
    {
        $usersWithFlavor = [];
        foreach ($tokens as $t) {
            if (($t->app_flavor ?? 'primary') === $flavor) {
                $usersWithFlavor[(int) $t->user_id] = true;
            }
        }

        if (empty($usersWithFlavor)) {
            return $tokens; // nobody has the dedicated app — nothing to prefer
        }

        return array_values(array_filter($tokens, function ($t) use ($usersWithFlavor, $flavor) {
            $userId = (int) $t->user_id;
            if (!isset($usersWithFlavor[$userId])) {
                return true; // this user doesn't have the dedicated app
            }
            return ($t->app_flavor ?? 'primary') === $flavor;
        }));
    }

    /**
     * ⭐ App-release broadcast: notify EVERY active device (all users, all
     * roles) that a new APK version is available. Pressed manually from the
     * Operations page AFTER the owner has uploaded the new APK + AppController
     * to production, so the download link always points at a live file.
     * Data payload mirrors /api/app/version so the mobile app can show its
     * standard update dialog on tap. Returns counts for the UI.
     */
    public function notifyAppUpdate(array $version): array
    {
        if (!$this->projectId || !file_exists($this->credentialsPath)) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'error' => 'Firebase is not configured on this server'];
        }

        try {
            $tokens = DB::table('t_wa_device_tokens')
                ->where('is_active', 1)
                ->distinct()
                ->pluck('fcm_token')
                ->all();

            if (empty($tokens)) {
                return ['total' => 0, 'sent' => 0, 'failed' => 0, 'error' => 'No active devices registered'];
            }

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                Log::error('Firebase: Failed to get access token for notifyAppUpdate');
                return ['total' => count($tokens), 'sent' => 0, 'failed' => count($tokens), 'error' => 'Could not authenticate with Firebase'];
            }

            $notification = [
                // 🗣 Roman Urdu: this reaches EVERY device, and the people who put off
                // updating are the riders. Managers read this fine too.
                'title' => '🚀 App ka naya version aa gaya',
                'body'  => "Nizami Farms v{$version['name']} tayyar hai. Update karne ke liye tap karein.",
            ];
            // FCM v1 requires every data value to be a string.
            $data = [
                'type'         => 'app_update',
                'version_name' => (string) $version['name'],
                'version_code' => (string) $version['code'],
                'download_url' => (string) $version['download_url'],
            ];

            $sent = 0;
            foreach ($tokens as $fcmToken) {
                if ($this->sendToDevice($accessToken, $fcmToken, $notification, $data, 'app_updates')) {
                    $sent++;
                }
            }

            Log::info('Firebase: App update broadcast finished', [
                'version' => $version['name'], 'total' => count($tokens), 'sent' => $sent,
            ]);

            return ['total' => count($tokens), 'sent' => $sent, 'failed' => count($tokens) - $sent, 'error' => null];
        } catch (\Exception $e) {
            Log::error('Firebase: notifyAppUpdate failed', ['error' => $e->getMessage()]);
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a notification to a single device via FCM v1 API.
     * Returns true when FCM accepted the message (used for broadcast counts;
     * older callers simply ignore the return value).
     */
    protected function sendToDevice(string $accessToken, string $fcmToken, array $notification, array $data = [], string $channelId = 'whatsapp_messages'): bool
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => $notification,
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => $channelId,
                        'sound' => 'default',
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if (!$response->successful()) {
                $error = $response->json('error.message', 'Unknown');
                $code = $response->json('error.details.0.errorCode', '');
                Log::warning("Firebase: FCM send failed [{$code}]: {$error}");

                // If token is invalid/expired, deactivate it
                if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                    DB::table('t_wa_device_tokens')
                        ->where('fcm_token', $fcmToken)
                        ->update(['is_active' => 0]);
                }
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::warning('Firebase: HTTP error sending to device', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * ⭐ Silent "wake up" data push to a rider's devices — the GPS defibrillator.
     * Data-only (NO notification block) + high priority so the mobile app's
     * background message handler runs (even when the app was swiped away / the
     * OS snoozed it) and restarts the location foreground service, WITHOUT showing
     * anything to the rider. Best-effort; returns how many devices accepted it.
     */
    public function pingRiderToWake(int $userId, array $data = []): int
    {
        if (!$this->projectId || !file_exists($this->credentialsPath)) {
            return 0;
        }

        try {
            $tokens = DB::table('t_wa_device_tokens')
                ->where('user_id', $userId)
                ->where('is_active', 1)
                ->pluck('fcm_token')
                ->all();

            if (empty($tokens)) return 0;

            $accessToken = $this->getAccessToken();
            if (!$accessToken) return 0;

            $payloadData = array_merge(['type' => 'location_ping'], $data);
            $sent = 0;
            foreach ($tokens as $fcmToken) {
                if ($this->sendDataOnly($accessToken, $fcmToken, $payloadData)) {
                    $sent++;
                }
            }
            return $sent;
        } catch (\Exception $e) {
            Log::warning('Firebase: pingRiderToWake failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Send a DATA-ONLY high-priority FCM message (no visible notification). FCM
     * requires every data value to be a string. Deactivates a dead token.
     */
    protected function sendDataOnly(string $accessToken, string $fcmToken, array $data): bool
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[$k] = is_string($v) ? $v : (string) $v;
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'data'  => $stringData,
                'android' => [
                    'priority' => 'high',
                    // No 'notification' block → silent; wakes the JS background handler.
                ],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)->timeout(10)->post($url, $payload);
            if (!$response->successful()) {
                $code = $response->json('error.details.0.errorCode', '');
                Log::warning('Firebase: silent data push failed', [
                    'code'  => $code,
                    'error' => $response->json('error.message', 'Unknown'),
                ]);
                if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                    DB::table('t_wa_device_tokens')->where('fcm_token', $fcmToken)->update(['is_active' => 0]);
                }
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::warning('Firebase: silent data push HTTP error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get an OAuth2 access token using the service account credentials.
     * Caches the token for 50 minutes (they expire in 60).
     */
    protected function getAccessToken(): ?string
    {
        return Cache::remember('firebase_access_token', 3000, function () {
            try {
                $credentials = json_decode(file_get_contents($this->credentialsPath), true);

                if (!$credentials || !isset($credentials['client_email'], $credentials['private_key'], $credentials['token_uri'])) {
                    Log::error('Firebase: Invalid service account credentials file');
                    return null;
                }

                $now = time();
                $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
                $claim = base64_encode(json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $credentials['token_uri'],
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]));

                $headerClaim = str_replace(['+', '/', '='], ['-', '_', ''], $header)
                    . '.' . str_replace(['+', '/', '='], ['-', '_', ''], $claim);

                $privateKey = openssl_pkey_get_private($credentials['private_key']);
                if (!$privateKey) {
                    Log::error('Firebase: Failed to load private key');
                    return null;
                }

                openssl_sign($headerClaim, $signature, $privateKey, OPENSSL_ALGO_SHA256);
                $jwt = $headerClaim . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

                $response = Http::asForm()->post($credentials['token_uri'], [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('Firebase: Token exchange failed', ['response' => $response->json()]);
                return null;

            } catch (\Exception $e) {
                Log::error('Firebase: Access token generation failed', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }
}
