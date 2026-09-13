<?php

namespace App\Services\Riders;

use App\Models\Riders\MaintenanceTypeModel;
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
    /**
     * The jobs that can be RECORDED, for one kind of machine (class-aware Sep-2026).
     *
     * ⚠ `has_schedule`, not `interval_km > 0`: a TIME-based job counts down in days
     *   and reports 0 km by design, so the old test would have hidden it from every
     *   picker and left it with a visible countdown nothing could reset — exactly the
     *   Brake Shoe bug from Aug-3, one unit over.
     *
     * @param ?string $class 'bike' | 'van' — null keeps the pre-class behaviour
     */
    public function scheduledTypes(?string $class = null): array
    {
        try {
            $svc  = app(MaintenanceTypeService::class);
            $rows = $class === null ? $svc->options() : $svc->optionsFor($class);
            return array_values(array_filter(
                $rows,
                fn ($t) => !empty($t['has_schedule']) || (int) ($t['interval_km'] ?? 0) > 0
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐⭐ EVERY JOB THAT CAN BE RECORDED — not only the ones with a countdown
     *    (owner ruling, 11-Sep-2026).
     *
     * ⚠⚠ WHY THIS EXISTS. `scheduledTypes()` answers "which countdowns can be reset", and the
     *    close dialogs were using it as if it answered "what can a workshop have done" — two
     *    different questions. On prod only two of the four types carry a kilometre figure, so a
     *    manager closing a visit saw TWO choices and no way to record the brake shoes he had
     *    just paid for. The owner's rule: *"even though there are no intervals set, it should
     *    still log it."* Work that happened must be recordable; whether a clock moves is a
     *    SEPARATE fact, and it travels on the row as `counts_down`.
     *
     * ⭐ Scheduled jobs come FIRST so the ordinary case is still the top of the list, and every
     *   row says which kind it is, so a picker can label the rest "no countdown" rather than
     *   hiding them.
     *
     * @param ?string $class 'bike' | 'van' — null keeps the pre-class behaviour
     * @return array<int, array> each row + counts_down:bool, applies:bool
     */
    public function typesForClose(?string $class = null): array
    {
        try {
            $svc = app(MaintenanceTypeService::class);
            $all = $svc->options();

            /**
             * ⚠⚠ THE UNION, NOT `optionsFor()` ALONE — and this is the whole van bug.
             *    `optionsFor($class)` drops every type whose `applies_to` does not match, and
             *    after the Sep-10 SQL every type defaults to **bike**. So a van's list came
             *    back EMPTY and its visit could not be closed with a meter at all. Caught by
             *    the proof, not by reading the code.
             *
             * ⭐ So: start from every active job, and let the class-resolved list supply the
             *   per-class FIGURES for the ones that do apply. A job that does not apply to this
             *   machine is still offered — labelled, and counting down nothing. A van job typed
             *   as a bike job is a labelling error for a manager to fix later; it must never be
             *   the reason the work cannot be written down.
             */
            $applicable = [];
            if ($class !== null) {
                foreach ($svc->optionsFor($class) as $t) {
                    $applicable[(int) ($t['id'] ?? 0)] = $t;
                }
            }

            $out = [];
            foreach ($all as $raw) {
                $id      = (int) ($raw['id'] ?? 0);
                $applies = $class === null || isset($applicable[$id]);
                // Per-class figures when they exist, the raw row otherwise.
                $t       = $applies && isset($applicable[$id]) ? $applicable[$id] : $raw;

                $counts = $applies
                    && (!empty($t['has_schedule']) || (int) ($t['interval_km'] ?? 0) > 0);

                $out[] = $t + [
                    'counts_down' => $counts,
                    'applies'     => $applies,
                ];
            }

            usort($out, function ($a, $b) {
                if ($a['counts_down'] !== $b['counts_down']) return $a['counts_down'] ? -1 : 1;
                return strcasecmp((string) ($a['type_name'] ?? ''), (string) ($b['type_name'] ?? ''));
            });
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The class of the machine this rider is on today — for the pickers and gates. */
    public function classForRider(?int $riderId, ?string $date = null): ?string
    {
        return $this->classFor(null, $riderId, $date);
    }

    /**
     * ⭐⭐ WHICH MACHINE IS THIS SERVICE ABOUT? — THE ONE ANSWER (owner ask, 10-Sep-2026:
     *    *"the maintenance records follow the vehicle, and the registry decides which user
     *    is writing it"*).
     *
     * Every door that records work asks THIS, and nothing else:
     *   • the workshop completion         → the VISIT names the machine, explicitly;
     *   • the Bikes screen / vehicle card → the CARD is a machine, so it sends its id;
     *   • the rider's own "ho gaya?"      → the visit again;
     *   • a rider-first form with no machine in scope, or an older client
     *                                     → the registry, `vehicleForDay(rider, date)`.
     *
     * ⚠⚠ WHY AN EXPLICIT ID MUST WIN. The registry answers "what is this man on THAT DAY",
     *    which stops being the same question as "which bike was serviced" the moment the two
     *    diverge — and taking a bike to the workshop is precisely when they diverge, because
     *    the manager hands him a spare while it is in. Deriving then credits the oil change
     *    to the spare, silently, while the real machine's countdown keeps running.
     *
     * ⚠ An id that names no vehicle is IGNORED rather than trusted — it falls through to the
     *   registry, which is the old behaviour and never worse than it.
     */
    public function vehicleForRecord($explicitVehicleId, ?int $riderId, ?string $date = null): ?int
    {
        $day = $date ?: \Carbon\Carbon::today()->format('Y-m-d');
        $vid = (int) ($explicitVehicleId ?: 0);
        try {
            if ($vid > 0 && (new VehicleService())->find($vid)) return $vid;
        } catch (\Throwable $e) {
            // fall through to the registry — a lookup wobble must not lose the recording
        }
        if (!$riderId) return null;
        try {
            $d = (new VehicleResolver())->vehicleForDay((int) $riderId, $day);
            return $d ? (int) $d : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The class (bike|van) whose schedule this recording is judged against — resolved from
     * the MACHINE when one is known, and only then from the rider's day.
     * ⚠ Same precedence as `vehicleForRecord`, deliberately: the job list a form offers and
     *   the machine the record lands on must never come from two different answers.
     */
    public function classFor($vehicleId, ?int $riderId, ?string $date = null): ?string
    {
        $vid = $this->vehicleForRecord($vehicleId, $riderId, $date);
        if (!$vid) return null;
        try {
            return (new VehicleService())->classOf($vid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @internal memo for logStampsVehicle(); a class static so a test can reset it. */
    private static ?bool $logVehMemo = null;

    /**
     * Does `t_fleet_service_log` carry the machine yet? (`service_log_vehicle_sep2026.sql`)
     *
     * ⚠ Memoised per process and consulted by every reader in VehicleService too, so there
     *   is ONE answer to "may I trust the stamp" rather than six schema calls per render.
     */
    public static function logStampsVehicle(): bool
    {
        if (self::$logVehMemo !== null) return self::$logVehMemo;
        try {
            self::$logVehMemo = Schema::hasTable('t_fleet_service_log')
                && Schema::hasColumn('t_fleet_service_log', 'vehicle_id');
        } catch (\Throwable $e) {
            self::$logVehMemo = false;
        }
        return self::$logVehMemo;
    }

    /** @internal memo for logKeepsPhoto(); see logStampsVehicle() for why it is a static. */
    private static ?bool $logPhotoMemo = null;

    /**
     * Does `t_fleet_service_log` carry the proof photo yet?
     * (`PENDING-PROD-SEP12-2026-WORKSHOP-R2.sql`)
     *
     * ⚠ Before that SQL runs the photo is simply not kept — the service record itself is
     *   written exactly as before, so this file is safe to upload first.
     */
    public static function logKeepsPhoto(): bool
    {
        if (self::$logPhotoMemo !== null) return self::$logPhotoMemo;
        try {
            self::$logPhotoMemo = Schema::hasTable('t_fleet_service_log')
                && Schema::hasColumn('t_fleet_service_log', 'photo_path');
        } catch (\Throwable $e) {
            self::$logPhotoMemo = false;
        }
        return self::$logPhotoMemo;
    }

    /** Test seam — see the ALTER-inside-a-transaction trap in the workshop round. */
    public static function flushSchemaMemo(): void
    {
        self::$logVehMemo = null;
        self::$logPhotoMemo = null;
    }

    /**
     * ⭐⭐ WHICH MACHINE DOES THIS LOG ROW BELONG TO — the ONE rule every reader applies.
     *
     * The stamp when there is one (a recorded fact), the registry when there is not (a row
     * filed before the column existed). Nothing else may decide this, or the countdown, the
     * history list, the meter chain and the alert sweep start disagreeing about one row.
     *
     * @param object|array $row  needs `user_id` and `service_date`, plus `vehicle_id` when
     *                           the caller selected it (it must, once the column exists)
     */
    public static function logVehicleOf($row, ?VehicleResolver $resolver = null): ?int
    {
        $get = fn (string $k) => is_array($row) ? ($row[$k] ?? null) : ($row->$k ?? null);

        $stamped = $get('vehicle_id');
        if (self::logStampsVehicle() && $stamped !== null && (int) $stamped > 0) {
            return (int) $stamped;
        }
        $uid = (int) ($get('user_id') ?: 0);
        if (!$uid) return null;
        try {
            $res = $resolver ?: new VehicleResolver();
            $d   = substr((string) $get('service_date'), 0, 10);
            $v   = $res->vehicleForDay($uid, $d);
            return $v ? (int) $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The columns a per-machine reader must select — `vehicle_id` only once it exists. */
    public static function logVehicleCols(string $prefix = ''): array
    {
        return self::logStampsVehicle() ? [$prefix . 'vehicle_id'] : [];
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
                ->get(array_merge(['l.id', 'l.user_id', 'l.meter', 'l.service_date',
                                   'l.maintenance_type_id', 'l.request_id', 't.type_name', 't.bucket'],
                                  self::logVehicleCols('l.')));

            $out = [];
            foreach ($rows as $r) {
                if (isset($live[(int) $r->id])) continue;   // a live bill already speaks for it
                // ⚠ The machine is resolved the SAME way the countdowns resolve it — the
                //   stamp first, the registry for a row filed before the stamp existed — so
                //   the vehicle page never offers a service that belongs to a different bike.
                $vid = self::logVehicleOf($r);
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
            $cols = ['id', 'user_id', 'note'];
            if (self::logKeepsPhoto()) $cols[] = 'photo_path';
            $row = DB::table('t_fleet_service_log')->where('id', $logId)->first($cols);
            if (!$row) return;
            DB::table('t_fleet_service_log')->where('id', $logId)->update([
                'request_id' => $requestId,
                'note'       => mb_substr(trim(($row->note ? $row->note . ' · ' : '') . 'bill attached'), 0, 250),
            ]);

            /**
             * 📷⭐⭐ THE BILL INHERITS THE RIDER'S PHOTO (owner ruling, 11-Sep-2026).
             *
             * ⭐ This is the point of storing the picture on the WORK. The rider photographs
             *   the receipt at the workshop and files nothing; days later a manager enters the
             *   amount from the vehicle page — and the claim he creates now carries the same
             *   photo, so whoever approves it can see what is being paid for. Before this, the
             *   evidence and the money could never meet: a photo needed an amount, and the
             *   amount arrived long after the photo could have been taken.
             *
             * ⚠ NEVER overwrites. A manager who attached his own picture to the claim has
             *   given the better evidence; this only fills an empty hand.
             * ⚠ Non-fatal: failing to decorate a claim must not unlink a filed bill.
             */
            if (!empty($row->photo_path ?? null)) {
                try {
                    // ⚠ `t_req_master` — the requests table (RequestModel::$table), NOT the
                    //   't_sys_*' the naming convention would suggest. `attachments` is a JSON
                    //   array of storage paths, the same shape RequestController::store writes.
                    $req = DB::table('t_req_master')->where('id', $requestId)->first(['id', 'attachments']);
                    $existing = $req && !empty($req->attachments) ? json_decode($req->attachments, true) : null;
                    if ($req && empty($existing)) {
                        DB::table('t_req_master')->where('id', $requestId)
                            ->update(['attachments' => json_encode([$row->photo_path])]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('bill did not inherit the service photo',
                        ['log' => $logId, 'request' => $requestId, 'error' => $e->getMessage()]);
                }
            }

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
    public function resolveType($typeId, ?string $class = null): array
    {
        /**
         * ⚠⚠ ASKED OF EVERY SELECTABLE JOB, NOT ONLY THE SCHEDULED ONES (11-Sep-2026). This
         *    used to read `scheduledTypes()`, which on a VAN is empty — so a blank pick was
         *    waved through as "no types exist here", and `record()` below then treated a null
         *    type as "move the overall clock". A van service with no job named would have
         *    reset the bike-style countdown for nothing. If the picker can offer anything at
         *    all, a choice is required.
         */
        $selectable = $this->typesForClose($class);

        if (empty($typeId)) {
            if ($selectable) {
                // ⭐ REFUSED, never guessed (owner ruling 2-Sep).
                return ['ok' => false, 'type' => null, 'message' =>
                    'Choose which service was done — the odometer alone does not say which '
                    . 'countdown to reset. Please update the app if it does not ask you.'];
            }
            // No type list at all (pre-batch-12): nothing to choose, behave as before
            // types existed rather than blocking the action outright.
            return ['ok' => true, 'type' => null, 'counts_down' => true, 'message' => ''];
        }

        $type = app(MaintenanceTypeService::class)->find($typeId);
        if (!$type) {
            return ['ok' => false, 'type' => null, 'message' => 'That maintenance type no longer exists.'];
        }
        /**
         * ⭐⭐ WORK THAT HAPPENED IS ALWAYS RECORDABLE (owner ruling, 11-Sep-2026).
         *
         * ⚠⚠ THIS USED TO REFUSE TWICE, AND BOTH REFUSALS WERE WRONG IN THE SAME WAY. A job with
         *    no figure for this machine ("has no schedule for vans yet") and an as-conditions job
         *    ("done as conditions require") were both turned away with "file it as a maintenance
         *    request instead". But the bike HAD been to the workshop, the brake shoes HAD been
         *    changed, and the man standing there with a receipt had nowhere to put it. Worse, the
         *    two refusals were reachable only on prod, where just two of four types carry a
         *    kilometre figure — which is why a manager saw a two-item list and assumed the app
         *    was broken.
         *
         * ⭐ The question "may this be recorded?" is now always YES for an active type. The
         *   separate question "does a countdown move?" is answered by `counts_down`, which
         *   `record()` uses and the receipt states out loud. Nothing silently resets.
         * ⚠ An unreadable or inactive type is still refused — that is a mis-selection, not work.
         */
        $countsDown = $class !== null
            ? (bool) ($type->scheduleForClass($class)['has'] ?? false)
            : ((int) $type->interval_km > 0);

        return ['ok' => true, 'type' => $type, 'counts_down' => $countsDown, 'message' => ''];
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

        /**
         * ⭐⭐ THE MACHINE, RESOLVED ONCE AND FROZEN (owner ask, 10-Sep-2026). Callers that
         *    know the bike — the workshop visit, a vehicle card — pass it; everyone else
         *    falls back to the registry exactly as before. See `vehicleForRecord()` for why
         *    an explicit id has to win.
         */
        $vehicleId = $this->vehicleForRecord($in['vehicle_id'] ?? null, $riderId, $date);

        /**
         * ⚠⚠ A DROPPED DIGIT MUST NOT PASS AS A SERVICE (10-Sep-2026). Nothing checked the
         *    odometer here, and the evidence readers silently SKIP an implausible row — so
         *    "36500" typed as "3650" gave a receipt, a log row, a visit marked done, and a
         *    countdown that never reset, with nothing anywhere saying why.
         *
         * ⭐ The SAME spine every meter reading is judged against (`readingPlausibleFor`),
         *   so what this door accepts is exactly what the countdown will later count. A
         *   back-dated service at a genuinely lower odometer sits inside the machine's own
         *   range and passes; only a number the machine could never have shown is refused.
         * ⚠ Fails OPEN when the machine is unknown or the check throws — a guard must never
         *   be the reason a real service cannot be recorded.
         */
        if ($vehicleId) {
            try {
                $veh = new VehicleService();
                if (!$veh->readingPlausibleFor($vehicleId, $meter)) {
                    $cur  = $veh->currentMeterFor($vehicleId);
                    $name = $veh->find($vehicleId)['name'] ?? 'that machine';
                    return ['ok' => false, 'service_log_id' => null, 'moved_clock' => false,
                            'message' => number_format($meter) . ' km does not fit ' . $name . '\'s own readings'
                                . ($cur !== null ? ' (it was last seen at ' . number_format($cur) . ' km)' : '')
                                . '. Check the odometer — a missing digit here would record a service '
                                . 'that no countdown can use.'];
                }
            } catch (\Throwable $e) {
                // A plausibility wobble must never lose a real recording.
            }
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
                /**
                 * 📷⭐⭐ THE PROOF PHOTO BELONGS TO THE WORK, NOT TO A BILL (owner ruling,
                 *    11-Sep-2026): *"the photo is entered because when they go for the
                 *    service, the riders will get this as proof. And using this, my managers
                 *    might enter the amount."*
                 *
                 * ⚠⚠ UNTIL NOW A PHOTO COULD ONLY RIDE ON AN EXPENSE CLAIM, so the ordinary
                 *    workshop case — rider handed a receipt, no money moved, manager pays
                 *    later — had nowhere to put it. The manager then typed an amount he could
                 *    not see the evidence for. Stored here, the photo exists from the moment
                 *    the work is recorded, and any bill filed later inherits it.
                 * ⚠ Schema-guarded: safe to upload before the Sep-12 SQL runs; the photo is
                 *   simply not kept until the columns exist (the record itself is unaffected).
                 */
                $photoCols = [];
                if (!empty($in['photo_path']) && self::logKeepsPhoto()) {
                    $photoCols = [
                        'photo_path' => (string) $in['photo_path'],
                        'photo_by'   => $actorId ?: null,
                        'photo_at'   => now(),
                    ];
                }

                $logId = (int) DB::table('t_fleet_service_log')->insertGetId(
                    // ⭐ The stamp, when the column exists. Schema-guarded so this file is
                    //   safe to upload before service_log_vehicle_sep2026.sql runs.
                    (self::logStampsVehicle() && $vehicleId ? ['vehicle_id' => $vehicleId] : [])
                    + $photoCols + [
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
             *
             * ⚠⚠ AND NOW: A JOB WITH NO COUNTDOWN ON THIS MACHINE MOVES NOTHING (11-Sep-2026).
             *    Since `resolveType()` stopped refusing unscheduled work, a type with no figure
             *    for this class reaches here for the first time. `resets_service_clock` alone
             *    would have let it stamp `last_service_meter` — i.e. "Other repair" would have
             *    silently marked the oil change done. The caller passes `counts_down`; when the
             *    job does not count down on THIS machine, the work is logged and no clock moves.
             *    That is exactly the owner's ruling: *"it won't reset any countdowns."*
             */
            $countsDown = array_key_exists('counts_down', $in) ? (bool) $in['counts_down'] : true;
            $movedClock = $countsDown && (!$type || $type->resets_service_clock);
            if ($movedClock) {
                DB::table('t_ops_rider_profile')->where('user_id', $riderId)->update([
                    'last_service_meter' => $meter,
                    'last_service_at'    => $date,
                    'updated_at'         => now(),
                ]);
            }

            // ⚠ The MACHINE's caches, not just the rider's — the record may be for a bike he
            //   is not on today, which is the whole reason the stamp exists.
            $this->bustCaches($riderId, $vehicleId);

            return ['ok' => true, 'service_log_id' => $logId, 'moved_clock' => $movedClock,
                    'vehicle_id' => $vehicleId,
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
            // ⚠ The machine the row is ABOUT — a correction to a service on a bike he no
            //   longer holds must still clear that bike's countdown cache.
            $this->bustCaches((int) $row->user_id, self::logVehicleOf($row));
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
            // ⚠ Read BEFORE the delete — afterwards there is no row to resolve the machine from.
            $wasFor = self::logVehicleOf($row);

            DB::table('t_fleet_service_log')->where('id', $logId)->delete();
            // ⚠ A visit that produced this record must stop pointing at a row that is gone.
            try {
                if (Schema::hasTable(WorkshopVisitService::T_VISIT)) {
                    DB::table(WorkshopVisitService::T_VISIT)
                        ->where('service_log_id', $logId)->update(['service_log_id' => null]);
                }
            } catch (\Throwable $e) { /* the visit stays, it just loses the link */ }

            $this->rebuildProfileStamp((int) $row->user_id);
            $this->bustCaches((int) $row->user_id, $wasFor);

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
            /**
             * ⚠ TWO DIFFERENT REASONS NOTHING MOVED, and a manager must be able to tell them
             *   apart (11-Sep-2026). Either the job HAS a countdown of its own but is not the
             *   one that resets the overall clock (brake shoes), or it has no countdown on this
             *   machine at all (an "other repair", or a job with no figures for a van). Saying
             *   "only an oil service moves that" for the second case would imply a countdown
             *   exists somewhere, and a manager would go looking for it.
             */
            $hasOwn = (int) ($type->interval_km ?? 0) > 0;
            $said[] = $hasOwn
                ? 'The bike\'s overall service-due clock is unchanged (only an oil service moves that)'
                : 'Recorded as work done — no countdown was reset, because this job is not on a schedule';
        }
        return implode('. ', $said);
    }

    /**
     * ⚠ The derived service state is memoised per process AND cached across requests —
     *   bump the machine's evidence version so both die, or the very next render answers
     *   from evidence gathered before this write and tells the user the service he just
     *   recorded has not happened.
     */
    /**
     * @param ?int $vehicleId ⭐ THE MACHINE THE RECORD IS ABOUT, when the caller knows it
     *        (10-Sep-2026). This used to bump only `currentVehicleFor($rider)` — what he is
     *        holding NOW — which is the wrong machine in exactly the case that matters: the
     *        bike is at the workshop and he has been given a spare. The service then landed
     *        on the right bike and the right bike's cached countdown was never cleared.
     */
    public function bustCaches(int $riderId, ?int $vehicleId = null): void
    {
        try {
            $vid = (new VehicleResolver())->currentVehicleFor($riderId);
        } catch (\Throwable $e) {
            $vid = null;
        }
        VehicleService::bumpServiceEvidence($vid ? (int) $vid : null);
        if ($vehicleId && (int) $vehicleId !== (int) $vid) {
            VehicleService::bumpServiceEvidence((int) $vehicleId);
        }

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
