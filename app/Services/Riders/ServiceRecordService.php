<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🛢 RECORDING THAT A SERVICE HAPPENED — the ONE place that rule lives (Sep-2026).
 *
 * ⭐⭐ WHY THIS EXISTS. Three different people can now tell the system a service was done:
 *      • a manager, from the Bikes screen (`FleetFuelController::markServiced`);
 *      • a manager, by completing a workshop visit;
 *      • THE RIDER, by answering "did it get done?" on his own visit (owner ruling,
 *        2-Sep) — and he holds no `manage_bike_service` key at all.
 *    Their PERMISSION gates differ, so they cannot share a controller method. Their
 *    RULE must not differ, or this round's whole point is lost: which countdown resets,
 *    whether the bike's overall clock moves, and that a type is mandatory. So the gate
 *    stays in each caller and the rule lives here, called by all three.
 *
 * ⚠⚠ THE TYPE IS REQUIRED whenever a meter is given and scheduled types exist. The old
 *    "guess the shortest clock-resetting type" fallback silently misfiled a real service
 *    (t_fleet_service_log #8) and is deliberately gone. See
 *    [[record-service-untyped-fallback-trap]].
 *
 * ⚠ This writes the SERVICE RECORD only. It never touches `service_interval_km` — the
 *   schedule is a separate decision with a separate button, and conflating them is what
 *   made "Record service" and "This bike" behave identically once before.
 */
class ServiceRecordService
{
    /** Active maintenance types that actually have a countdown to reset. */
    public function scheduledTypes(): array
    {
        try {
            return array_values(array_filter(
                app(MaintenanceTypeService::class)->options(),
                fn ($t) => (int) ($t['interval_km'] ?? 0) > 0
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐⭐ A BILL IS TIED TO A SERVICE BY BEING **CHOSEN**, NEVER BY BEING GUESSED
     *    (owner ruling, 3-Sep). An earlier draft of this matched a bill to a reading on
     *    "meter within 100 km and date within 7 days". The owner rejected that, and he was
     *    right: guessing is what misfiled service log #8, and a tolerance is not auditable —
     *    nobody can later say *why* two rows were treated as one job. So the person filing
     *    the bill picks the service from a list of his own un-billed readings, or says it is
     *    a new one. Nothing is ever inferred.
     *
     * ⚠⚠ A LINK IS ONLY LIVE WHILE THE CLAIM IS. Found in review: the de-duplication hid a
     *    linked claim whatever its status, and nothing on the reject path touched the log —
     *    so a REJECTED bill left the service reading as paid for ever, and a re-filed bill
     *    had nothing to attach to. A link therefore counts only while its claim is pending
     *    or approved; rejected and cancelled release the service to be billed again.
     */
    public const LIVE_BILL_STATUSES = ['pending', 'approved'];

    /**
     * Request ids whose link to a service log is LIVE, as [request_id => log_id].
     * The one reader of "is this claim already spoken for?" — used by both de-duplication
     * sites in VehicleService so the history and the evidence engine cannot disagree.
     */
    public static function liveBillLinks(): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')
                || !Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                return [];
            }
            return DB::table('t_fleet_service_log as l')
                ->join('t_req_master as r', 'r.id', '=', 'l.request_id')
                ->whereNotNull('l.request_id')
                ->whereIn('r.status', self::LIVE_BILL_STATUSES)
                ->pluck('l.id', 'l.request_id')
                ->map(fn ($v) => (int) $v)
                ->all();
        } catch (\Throwable $e) {
            // A lookup failure must never hide real rows — fall back to "nothing is linked",
            // which shows both halves rather than silently dropping one.
            return [];
        }
    }

    /**
     * 🧾 THE SERVICES A BILL CAN BE ATTACHED TO — this rider's own readings that no live bill
     *    speaks for yet, newest first. This is the list the picker shows on every bill form.
     *
     * ⚠ Scoped to ONE rider because a claim belongs to a requester: attaching a bill to
     *   another man's service would move his countdown and his money together.
     * ⚠ `$vehicleId` narrows it on the vehicle page, where the machine is already the subject.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unbilledServicesFor(int $riderId, ?int $vehicleId = null, int $days = 60): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')) return [];
            $hasLink = Schema::hasColumn('t_fleet_service_log', 'request_id');
            $live    = $hasLink ? array_flip(self::liveBillLinks()) : [];   // [log_id => request_id]

            $rows = DB::table('t_fleet_service_log as l')
                ->leftJoin('t_fleet_maintenance_types as t', 't.id', '=', 'l.maintenance_type_id')
                ->where('l.user_id', $riderId)
                ->whereNotNull('l.meter')
                ->whereDate('l.service_date', '>=', \Carbon\Carbon::today()->subDays($days)->format('Y-m-d'))
                ->orderByDesc('l.service_date')->orderByDesc('l.id')
                ->limit(40)
                ->get(['l.id', 'l.meter', 'l.service_date', 'l.maintenance_type_id',
                       'l.request_id', 't.type_name', 't.bucket']);

            $out = [];
            foreach ($rows as $r) {
                if (isset($live[(int) $r->id])) continue;   // a live bill already speaks for it
                // ⚠ The machine is resolved the SAME way the countdowns resolve it, so the
                //   vehicle page never offers a service that belongs to a different bike.
                $vid = null;
                try { $vid = (new VehicleResolver())->vehicleForDay($riderId, substr((string) $r->service_date, 0, 10)); }
                catch (\Throwable $e) { $vid = null; }
                if ($vehicleId && (int) $vid !== (int) $vehicleId) continue;

                $out[] = [
                    'log_id'              => (int) $r->id,
                    'meter'               => (int) $r->meter,
                    'date'                => substr((string) $r->service_date, 0, 10),
                    'maintenance_type_id' => $r->maintenance_type_id ? (int) $r->maintenance_type_id : null,
                    'type_name'           => $r->type_name ?: 'Service',
                    'bucket'              => $r->bucket,
                    'vehicle_id'          => $vid ? (int) $vid : null,
                    // What the picker shows: "30 Aug · Oil + Tuning · 27,906 km"
                    'label'               => \Carbon\Carbon::parse($r->service_date)->format('j M')
                                             . ' · ' . ($r->type_name ?: 'Service')
                                             . ' · ' . number_format((int) $r->meter) . ' km',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('unbilledServicesFor failed', ['rider' => $riderId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ⭐⭐ MAY THIS BILL BE ATTACHED TO THIS SERVICE? — the one gate every bill door calls.
     *
     * Returns the reading to INHERIT so the filer never retypes a meter he has already
     * entered (the owner's whole reason for asking for this): the claim takes the log's
     * odometer, its job and its date.
     *
     * ⚠⚠ THE DOUBLE-MONEY GUARD LIVES HERE. If the chosen service already has a live bill,
     *    this refuses — naming the bill, its amount and who filed it. That is the case where
     *    a manager records the service with the receipt and the rider then files the same
     *    receipt from his phone: without this, the money goes out twice.
     *
     * @return array{ok:bool, message:string, inherit?:array{meter:int,maintenance_type_id:?int,date:string}}
     */
    public function validateBillTarget($logId, int $requesterId): array
    {
        if (empty($logId)) return ['ok' => true, 'message' => ''];
        try {
            if (!Schema::hasTable('t_fleet_service_log')) {
                return ['ok' => false, 'message' => 'Service records are not set up yet.'];
            }
            $log = DB::table('t_fleet_service_log')->where('id', (int) $logId)->first();
            if (!$log) {
                return ['ok' => false, 'message' => 'That service record no longer exists. Refresh and choose again.'];
            }
            // ⚠ A claim belongs to its requester — attaching it to someone else's service
            //   would move another man's countdown and his money in one step.
            if ((int) $log->user_id !== $requesterId) {
                return ['ok' => false, 'message' => 'That service was recorded for a different rider.'];
            }
            if ($log->meter === null) {
                return ['ok' => false, 'message' => 'That service record has no odometer reading to bill against.'];
            }
            if (Schema::hasColumn('t_fleet_service_log', 'request_id') && !empty($log->request_id)) {
                $live = DB::table('t_req_master')->where('id', $log->request_id)
                    ->whereIn('status', self::LIVE_BILL_STATUSES)->first(['id', 'amount', 'created_by', 'status']);
                if ($live) {
                    /**
                     * ⚠⚠ A REFUSAL MUST NAME THE WAY OUT, and it must name the RIGHT one.
                     *
                     *    ONE VISIT COMMONLY MEANS SEVERAL JOBS — checked against the data, not
                     *    guessed: every same-odometer pair on this system is two DIFFERENT jobs
                     *    done in one visit (Waseem, 27,906 km: Oil + Tuning Rs 3,500 AND Brake
                     *    Shoe Rs 650), never the same job billed twice. Each job is its own
                     *    service record with its own bill, which this model already supports.
                     *
                     * ⚠ So the way out is NOT "file it without choosing" — a maintenance claim
                     *   needs a meter anyway, and an unlinked twin is the duplicate row this
                     *   whole design removes. It is: record the OTHER job as its own service
                     *   and bill that one.
                     */
                    return ['ok' => false, 'message' =>
                        'That service already has a bill — Rs ' . number_format((float) $live->amount)
                        . ' filed by ' . $this->nameOf($live->created_by ? (int) $live->created_by : null)
                        . ($live->status === 'pending' ? ' (waiting for approval)' : '')
                        . '. If that bill is wrong, reverse it first. If this bill is for a DIFFERENT '
                        . 'job done in the same visit, record that job as its own service (same '
                        . 'odometer) and attach the bill to it.'];
                }
                // A dead link (rejected / cancelled) is simply overwritten below.
            }
            return ['ok' => true, 'message' => '', 'inherit' => [
                'meter'               => (int) $log->meter,
                'maintenance_type_id' => $log->maintenance_type_id ? (int) $log->maintenance_type_id : null,
                'date'                => substr((string) $log->service_date, 0, 10),
            ]];
        } catch (\Throwable $e) {
            Log::error('validateBillTarget failed', ['log' => $logId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not check that service record.'];
        }
    }

    /** Tie a freshly created bill to the service it was filed for. */
    public function attachBillToService(int $logId, int $requestId): void
    {
        try {
            if (!Schema::hasColumn('t_fleet_service_log', 'request_id')) return;
            $row = DB::table('t_fleet_service_log')->where('id', $logId)->first(['id', 'user_id', 'note']);
            if (!$row) return;
            DB::table('t_fleet_service_log')->where('id', $logId)->update([
                'request_id' => $requestId,
                'note'       => mb_substr(trim(($row->note ? $row->note . ' · ' : '') . 'bill attached'), 0, 250),
            ]);
            $this->bustCaches((int) $row->user_id);
        } catch (\Throwable $e) {
            Log::error('attachBillToService failed', ['log' => $logId, 'request' => $requestId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * ⭐⭐ THE SAME RULE FOR A MAINTENANCE **CLAIM** (owner, 3-Sep: "same engine rule").
     *
     * ⚠⚠ WHAT WAS HAPPENING. A manager recording a service is REFUSED an untyped odometer
     *    (resolveType above — owner ruling, no guessing). But a rider's own request, and a
     *    manager's claim for him, went through `MaintenanceTypeService::resolve()`, which
     *    turned a legacy "Regular service" into `[oil_change, null]` and FILED IT. The
     *    evidence engine skips every claim with no `maintenance_type_id`, so those claims
     *    never reset a per-type countdown — 116 of 140 maintenance claims are untyped. Two
     *    rules for one fact, and the one riders hit was the silent one.
     *
     * ⚠ Narrower than resolveType on purpose. resolveType also refuses "as conditions" work
     *   (General Repair, Chain Set) because there is no countdown to RECORD against — but a
     *   General Repair BILL is a perfectly good claim. So here: a Maintenance claim that
     *   carries an ODOMETER must name a type when the list exists; any active type will do.
     *   No odometer → nothing can feed a countdown → untyped is harmless and still accepted,
     *   which is what keeps an older APK able to file a repair bill.
     *
     * @return array{ok: bool, message: string}
     */
    public function requireTypeForClaim($typeId, bool $hasMeter): array
    {
        if (!$hasMeter || !empty($typeId)) return ['ok' => true, 'message' => ''];
        try {
            $svc = app(MaintenanceTypeService::class);
            if (!$svc->available() || empty($svc->options())) {
                // No list to choose from (pre-batch-12): behave as before types existed.
                return ['ok' => true, 'message' => ''];
            }
        } catch (\Throwable $e) {
            return ['ok' => true, 'message' => ''];   // fail OPEN — a lookup error must never block a bill
        }
        return ['ok' => false, 'message' =>
            'Choose which service was done — the odometer alone does not say which countdown '
            . 'to reset. If the app only offers "Regular service / Repair", pull down to refresh '
            . 'the form or update the app.'];
    }

    /**
     * Turn a submitted type id into the row to record against, applying the two rules
     * that decide whether it may be recorded at all.
     *
     * @return array{ok: bool, type: ?object, message: string}
     */
    public function resolveType($typeId): array
    {
        $scheduled = $this->scheduledTypes();

        if (empty($typeId)) {
            if ($scheduled) {
                // ⭐ REFUSED, never guessed (owner ruling 2-Sep).
                return ['ok' => false, 'type' => null, 'message' =>
                    'Choose which service was done — the odometer alone does not say which '
                    . 'countdown to reset. Please update the app if it does not ask you.'];
            }
            // No type list at all (pre-batch-12): nothing to choose, behave as before
            // types existed rather than blocking the action outright.
            return ['ok' => true, 'type' => null, 'message' => ''];
        }

        $type = app(MaintenanceTypeService::class)->find($typeId);
        if (!$type) {
            return ['ok' => false, 'type' => null, 'message' => 'That maintenance type no longer exists.'];
        }
        if ((int) $type->interval_km <= 0) {
            // "As conditions" work (Chain Set, Misc) has no countdown, so there is
            // nothing here to record against.
            return ['ok' => false, 'type' => null, 'message' =>
                '"' . $type->type_name . '" is done as conditions require, so it has no due date '
                . 'to reset. File it as a maintenance request instead — that keeps the bill and '
                . 'the photo with it.'];
        }
        return ['ok' => true, 'type' => $type, 'message' => ''];
    }

    /**
     * Write the service record.
     *
     * @param array $in {rider_id, meter, date, type (?object from resolveType), actor_id, note}
     * @return array{ok: bool, service_log_id: ?int, moved_clock: bool, message: string}
     */
    public function record(array $in): array
    {
        $riderId = (int) ($in['rider_id'] ?? 0);
        $meter   = (int) ($in['meter'] ?? 0);
        $date    = $in['date'] ?: \Carbon\Carbon::today()->format('Y-m-d');
        $type    = $in['type'] ?? null;
        $actorId = (int) ($in['actor_id'] ?? 0);

        if (!$riderId || $meter <= 0) {
            return ['ok' => false, 'service_log_id' => null, 'moved_clock' => false,
                    'message' => 'A rider and an odometer reading are both needed.'];
        }

        try {
            $logId = null;

            /**
             * Every scheduled type gets a log row, so the per-type countdown on the Bikes
             * drawer resets. Deliberately NOT a zero-amount expense request: a service
             * record is not a money movement, and faking one would push Rs 0 rows into the
             * expense reports and the ledger.
             *
             * ⚠ `$type` is null here ONLY when the type table does not exist yet — a meter
             *   with no type is refused by resolveType() — and then there is nothing to log
             *   against, exactly as before types were introduced. The profile stamp below
             *   still happens in that case, so nothing regresses.
             */
            if ($type && Schema::hasTable('t_fleet_service_log')) {
                $logId = (int) DB::table('t_fleet_service_log')->insertGetId([
                    'user_id'             => $riderId,
                    'maintenance_type_id' => (int) $type->id,
                    'meter'               => $meter,
                    'service_date'        => $date,
                    'note'                => $in['note'] ?? 'Recorded on the Bikes screen (no bill filed)',
                    'created_by'          => $actorId ?: null,
                    'created_at'          => now(),
                ]);
            }

            /**
             * ⭐ ONLY a clock-resetting type moves the bike's overall service-due clock. A
             * brake-shoe job is real work on its own 10,000 km cycle, but it must never make
             * an overdue oil change look done — the same rule the approval path enforces via
             * BikeServiceClock.
             *
             * ⚠⚠ NO INTERVAL IS WRITTEN HERE. The old "schedule follows the work done" write
             *    stamped the recorded type's interval as the bike's own override, which after
             *    Aug-27 silently rewrote a DIFFERENT job's schedule (Oil Change 1,200 → 2,500
             *    from one click) and opted the bike out of the company default forever. An
             *    override is written only when a manager explicitly asks for one.
             */
            $movedClock = !$type || $type->resets_service_clock;
            if ($movedClock) {
                DB::table('t_ops_rider_profile')->where('user_id', $riderId)->update([
                    'last_service_meter' => $meter,
                    'last_service_at'    => $date,
                    'updated_at'         => now(),
                ]);
            }

            $this->bustCaches($riderId);

            return ['ok' => true, 'service_log_id' => $logId, 'moved_clock' => $movedClock,
                    'message' => $this->receipt($type, $meter, $date, $movedClock)];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::record failed', ['rider' => $riderId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'service_log_id' => null, 'moved_clock' => false,
                    'message' => 'Could not save the service record.'];
        }
    }

    /**
     * ✏️ CORRECT A SERVICE RECORD (owner ask, 3-Sep): "make sure Qasim or Shabib or Taimur can
     *    modify these service dates later on as well if needed."
     *
     * ⭐⭐ WHY THIS MATTERS MORE THAN IT LOOKS. Until now these rows were INSERT-ONLY. A record
     *    filed against the wrong job, the wrong day or the wrong odometer could be fixed only
     *    by hand-written SQL — which is exactly the situation log row #8 left us in, and the
     *    reason that repair is still sitting in a file waiting for someone to run it. A manager
     *    who can make the record must be able to correct it.
     *
     * ⚠ The countdown is DERIVED from these rows, so an edit self-corrects every surface the
     *   moment the caches are busted — there is nothing else to update, and no frozen figure
     *   to chase (unlike an approved claim, which carries money and is deliberately NOT
     *   editable here).
     *
     * ⚠ The profile stamp is REBUILT from the evidence rather than patched: a correction can
     *   move a record backwards, change its type so it no longer resets the clock, or delete
     *   it entirely, and only a rebuild is right in all three cases.
     *
     * @return array{ok: bool, message: string}
     */
    public function amend(int $logId, array $in, int $actorId): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')) {
                return ['ok' => false, 'message' => 'Service records are not set up yet.'];
            }
            $row = DB::table('t_fleet_service_log')->where('id', $logId)->first();
            if (!$row) return ['ok' => false, 'message' => 'That service record no longer exists.'];

            $update = ['note' => $row->note];

            if (array_key_exists('maintenance_type_id', $in) && $in['maintenance_type_id']) {
                $t = $this->resolveType($in['maintenance_type_id']);
                if (!$t['ok']) return ['ok' => false, 'message' => $t['message']];
                $update['maintenance_type_id'] = (int) $t['type']->id;
            }
            if (!empty($in['meter'])) {
                if ((int) $in['meter'] <= 0) return ['ok' => false, 'message' => 'Give the odometer in kilometres.'];
                $update['meter'] = (int) $in['meter'];
            }
            if (!empty($in['date'])) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date'])) {
                    return ['ok' => false, 'message' => 'Give the date as YYYY-MM-DD.'];
                }
                // ⚠ Same rule as recording one: work cannot have happened in the future.
                if ($in['date'] > \Carbon\Carbon::today()->format('Y-m-d')) {
                    return ['ok' => false, 'message' => 'That date is in the future.'];
                }
                $update['service_date'] = $in['date'];
            }
            if (count($update) === 1) return ['ok' => false, 'message' => 'Nothing to change.'];

            // ⭐ The correction is part of the record. Without this an audit cannot tell a
            //   figure someone chose from one someone later fixed.
            $update['note'] = trim(($row->note ? $row->note . ' · ' : '')
                . 'corrected ' . \Carbon\Carbon::today()->format('j M Y') . ' by ' . $this->nameOf($actorId));
            $update['note'] = mb_substr($update['note'], 0, 250);

            DB::table('t_fleet_service_log')->where('id', $logId)->update($update);

            /**
             * ⭐⭐ ONE JOB = ONE TRUTH (review, 3-Sep). When this record was filed WITH its bill,
             *    the claim carries the same odometer and job. Correct the log alone and the
             *    two halves disagree — harmless while linked (the evidence engine follows the
             *    log), but the moment the log is removed the claim resurfaces carrying the
             *    figure that was just declared wrong. So the reading is mirrored onto the
             *    claim through the same narrow door a manager would use by hand. The AMOUNT is
             *    never touched — that is the whole point of that door.
             */
            $mirrored = '';
            if (!empty($row->request_id) && (isset($update['meter']) || isset($update['maintenance_type_id']))) {
                $m = $this->correctClaim((int) $row->request_id, [
                    'meter'               => $update['meter'] ?? null,
                    'maintenance_type_id' => $update['maintenance_type_id'] ?? null,
                ], $actorId);
                $mirrored = $m['ok'] ? ' The linked expense now carries the same reading.'
                                     : ' ⚠ The linked expense could NOT be updated: ' . $m['message'];
            }

            $this->rebuildProfileStamp((int) $row->user_id);
            $this->bustCaches((int) $row->user_id);
            return ['ok' => true, 'message' => 'Service record corrected.' . $mirrored];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::amend failed', ['log' => $logId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not correct that record.'];
        }
    }

    /**
     * ⭐⭐ CORRECT THE ODOMETER (and the job) ON A MAINTENANCE **CLAIM** — including an
     *    APPROVED one. The narrow second door asked for on 3-Sep.
     *
     * ⚠⚠ WHY THIS EXISTS. `FleetFuelController::editClaim` refuses any edit once a claim is
     *    approved — *"an approved claim has money in the ledger — reverse it and file it again
     *    instead."* That guard is right about MONEY and wrong about everything else, and it
     *    locked a field that is not money at all. Live proof: AY-4771 read "Oil Change 767 km
     *    overdue" off an approved 17-Aug claim at 48,777 km. If that odometer were a typo,
     *    nobody could fix it — and the only workaround, recording a manual service at the right
     *    meter, leaves the wrong number in the history for ever.
     *
     * ⭐ THE LINE THIS DRAWS: the odometer and which job was done are OBSERVATIONS about a
     *   machine. The amount, the date and the vehicle are MONEY — they set what was spent, which
     *   period it lands in, and which bike carries the cost. Only the observations are editable
     *   here; for the rest, reverse and re-file remains the right answer.
     *
     * ⚠ Deliberately NOT a relaxation of `editClaim`. That method still refuses approved claims
     *   for every field it owns. This is a separate, narrower entrance with its own permission.
     *
     * ⭐ Nothing needs recomputing afterwards: every countdown is DERIVED from this row, so a
     *   correction self-corrects the schedule panel, the alerts, the rider's chip and the web
     *   card at once. We only have to invalidate the caches in front of them.
     */
    public function correctClaim(int $requestId, array $in, int $actorId): array
    {
        try {
            $row = DB::table('t_req_master')->where('id', $requestId)->first();
            if (!$row) return ['ok' => false, 'message' => 'That claim no longer exists.'];
            if (($row->expense_category ?? '') !== 'Maintenance') {
                return ['ok' => false, 'message' => 'Only a maintenance claim carries a service reading.'];
            }

            $update = [];

            if (array_key_exists('maintenance_type_id', $in) && $in['maintenance_type_id']) {
                $t = $this->resolveType($in['maintenance_type_id']);
                if (!$t['ok']) return ['ok' => false, 'message' => $t['message']];
                $update['maintenance_type_id'] = (int) $t['type']->id;
                // ⚠ The legacy machine flag is DERIVED from the type's bucket and is what the
                //   older rules branch on — leaving it stale would make the claim read as one
                //   kind of work to this engine and another to those.
                $update['service_type'] = $t['type']->bucket === 'regular' ? 'oil_change' : 'repair';
            }

            if (array_key_exists('meter', $in) && $in['meter'] !== null && $in['meter'] !== '') {
                if ((int) $in['meter'] <= 0) return ['ok' => false, 'message' => 'Give the odometer in kilometres.'];
                $update['meter_at_fill'] = (int) $in['meter'];
            }

            if (!$update) return ['ok' => false, 'message' => 'Nothing to change.'];

            // ⭐ The correction is part of the record, exactly as it is for a service log —
            //   without this an audit cannot tell a figure someone chose from one someone
            //   later fixed. Appended, never overwriting what the filer wrote.
            $note = trim((string) ($row->description ?? ''));
            $stamp = 'Service reading corrected ' . \Carbon\Carbon::today()->format('j M Y')
                   . ' by ' . $this->nameOf($actorId)
                   . (isset($update['meter_at_fill'])
                        ? ' (odometer ' . ($row->meter_at_fill ?? '—') . ' → ' . $update['meter_at_fill'] . ')' : '')
                   . ' — the amount was not changed.';
            $update['description'] = mb_substr(($note !== '' ? $note . "\n" : '') . $stamp, 0, 2000);
            $update['updated_by']  = $actorId;
            $update['updated_at']  = now();

            /**
             * ⚠⚠ THE FROZEN FIGURE MUST GO WITH THE READING IT WAS FROZEN FROM (review, 3-Sep).
             *    `service_due_km` is stamped at approval as "km until due, measured from THIS
             *    claim's odometer" and the claim card prints it as "done N km overdue". Correct
             *    the odometer and leave it, and the card keeps quoting a number computed from
             *    the figure just declared wrong — proven: 48,777 → 48,000 left it at −564.
             *    Cleared, the card falls back to the live derivation, which is the truth.
             */
            if (isset($update['meter_at_fill']) && Schema::hasColumn('t_req_master', 'service_due_km')) {
                $update['service_due_km'] = null;
            }

            DB::table('t_req_master')->where('id', $requestId)->update($update);

            /**
             * ⭐⭐ THE OTHER HALF OF THE MIRROR (review, 3-Sep — found NOT built while re-checking).
             *    `amend()` on a log already mirrors the reading onto its claim. This is the
             *    reverse: correcting the claim must reach the LOG it is linked to, or the pair
             *    silently disagrees — and the history and countdown follow the log, so the
             *    correction the manager just made would appear to have done nothing.
             * ⚠ Only a LIVE link, and only the two observation fields. No amount, ever.
             */
            $mirrored = '';
            try {
                if (Schema::hasColumn('t_fleet_service_log', 'request_id')) {
                    $log = DB::table('t_fleet_service_log')->where('request_id', $requestId)->first(['id', 'user_id']);
                    if ($log) {
                        $lu = [];
                        if (isset($update['meter_at_fill']))       $lu['meter'] = $update['meter_at_fill'];
                        if (isset($update['maintenance_type_id'])) $lu['maintenance_type_id'] = $update['maintenance_type_id'];
                        if ($lu) {
                            DB::table('t_fleet_service_log')->where('id', $log->id)->update($lu);
                            $this->rebuildProfileStamp((int) $log->user_id);
                            $mirrored = ' The linked service record now carries the same reading.';
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('correctClaim: log mirror failed', ['request' => $requestId, 'error' => $e->getMessage()]);
            }

            /**
             * ⚠ Keyed on the claim's OWN machine, not on what its requester holds today — a
             *   claim from July belongs to the bike it was filed against, and the rider may be
             *   on a different one now. An UNSTAMPED (pre-registry) claim is attributed by who
             *   held which machine on its date, so resolve it the same way the evidence engine
             *   will, or the correction sits behind a 5-minute cache on the wrong vehicle.
             */
            $vid = $row->vehicle_id ? (int) $row->vehicle_id : null;
            if (!$vid && $row->requester_user_id) {
                try {
                    $vid = (new VehicleResolver())->vehicleForDay((int) $row->requester_user_id,
                        $row->expense_date ? substr((string) $row->expense_date, 0, 10) : \Carbon\Carbon::today()->format('Y-m-d'));
                } catch (\Throwable $e) {
                    $vid = null;
                }
            }
            VehicleService::bumpServiceEvidence($vid ? (int) $vid : null);
            if ($row->requester_user_id) $this->bustCaches((int) $row->requester_user_id);

            return ['ok' => true, 'message' => 'Service reading corrected. The amount is unchanged.' . $mirrored];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::correctClaim failed',
                ['request' => $requestId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not correct that reading.'];
        }
    }

    /** Remove a service record that should never have been there. */
    public function remove(int $logId, int $actorId): array
    {
        try {
            if (!Schema::hasTable('t_fleet_service_log')) {
                return ['ok' => false, 'message' => 'Service records are not set up yet.'];
            }
            $row = DB::table('t_fleet_service_log')->where('id', $logId)->first();
            if (!$row) return ['ok' => false, 'message' => 'That service record no longer exists.'];

            DB::table('t_fleet_service_log')->where('id', $logId)->delete();
            // ⚠ A visit that produced this record must stop pointing at a row that is gone.
            try {
                if (Schema::hasTable(WorkshopVisitService::T_VISIT)) {
                    DB::table(WorkshopVisitService::T_VISIT)
                        ->where('service_log_id', $logId)->update(['service_log_id' => null]);
                }
            } catch (\Throwable $e) { /* the visit stays, it just loses the link */ }

            $this->rebuildProfileStamp((int) $row->user_id);
            $this->bustCaches((int) $row->user_id);

            /**
             * ⚠⚠ DELETING A SERVICE NEVER DELETES MONEY (review, 3-Sep). When this record was
             *    filed with its bill, the claim stays exactly as it is — approved, in the
             *    ledger, or in a queue. What changes is only that it stops being hidden behind
             *    this row: it resurfaces in Past services and in the evidence as an ordinary
             *    claim. The manager is told so, because "I removed it" must not be read as
             *    "the expense is gone too".
             */
            $kept = '';
            if (!empty($row->request_id)) {
                $amt = DB::table('t_req_master')->where('id', $row->request_id)->value('amount');
                $kept = ' The Rs ' . number_format((float) $amt) . ' expense filed with it is NOT removed'
                      . ' — it stays on record; reverse it from the claims flow if it should not stand.';
            }
            return ['ok' => true, 'message' => 'Service record removed.' . $kept];
        } catch (\Throwable $e) {
            Log::error('ServiceRecordService::remove failed', ['log' => $logId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not remove that record.'];
        }
    }

    /**
     * Recompute `last_service_meter` / `last_service_at` from the evidence that remains.
     *
     * ⚠⚠ REBUILT, NEVER PATCHED. An edit can move a record backwards, change its type so it no
     *    longer resets the overall clock, or remove it altogether — patching the stamp would be
     *    right for none of those. The stamp is only a fallback seed anyway (the real countdown
     *    is derived), but a stale one shows up on riders with no registered machine.
     */
    private function rebuildProfileStamp(int $riderId): void
    {
        try {
            $latest = DB::table('t_fleet_service_log as l')
                ->join('t_fleet_maintenance_types as t', 't.id', '=', 'l.maintenance_type_id')
                ->where('l.user_id', $riderId)
                ->where('t.resets_service_clock', 1)
                ->orderByDesc('l.meter')->orderByDesc('l.id')
                ->first(['l.meter', 'l.service_date']);
            DB::table('t_ops_rider_profile')->where('user_id', $riderId)->update([
                'last_service_meter' => $latest->meter ?? null,
                'last_service_at'    => $latest->service_date ?? null,
                'updated_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Profile service stamp not rebuilt', ['rider' => $riderId, 'error' => $e->getMessage()]);
        }
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

    /**
     * What actually changed, in words. Names the job and its next due, and is explicit
     * when the bike's overall clock did NOT move — otherwise recording brake shoes reads
     * as "the bike is serviced", which is the confusion per-type schedules exist to end.
     */
    public function receipt($type, int $meter, string $date, bool $movedClock): string
    {
        $backdated = $date !== \Carbon\Carbon::today()->format('Y-m-d');
        $said = [];
        $said[] = ($type ? $type->type_name : 'Service')
            . ' recorded at ' . number_format($meter) . ' km'
            . ($backdated ? ' on ' . \Carbon\Carbon::parse($date)->format('D j M') : '')
            . ($type && (int) $type->interval_km > 0
                ? ' — next due at ' . number_format($meter + (int) $type->interval_km) . ' km'
                : '');
        if ($type && !$movedClock) {
            $said[] = 'The bike\'s overall service-due clock is unchanged (only an oil service moves that)';
        }
        return implode('. ', $said);
    }

    /**
     * ⚠ The derived service state is memoised per process AND cached across requests —
     *   bump the machine's evidence version so both die, or the very next render answers
     *   from evidence gathered before this write and tells the user the service he just
     *   recorded has not happened.
     */
    public function bustCaches(int $riderId): void
    {
        try {
            $vid = (new VehicleResolver())->currentVehicleFor($riderId);
        } catch (\Throwable $e) {
            $vid = null;
        }
        VehicleService::bumpServiceEvidence($vid ? (int) $vid : null);

        // Targeted only — a global flush would also wipe unrelated caches.
        try {
            $this_ = \Carbon\Carbon::today()->format('Y-m');
            $prev  = \Carbon\Carbon::today()->subMonthNoOverflow()->format('Y-m');
            foreach ([$this_, $prev] as $m) {
                Cache::forget("fleet_fuel_month_{$m}");
                Cache::forget("fleet_fuel_rider_{$riderId}_{$m}");
            }
        } catch (\Throwable $e) {
            // caches expire on their own within CACHE_SECS
        }
    }
}
