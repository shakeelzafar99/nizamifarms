<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * The vehicle registry (Aug-2026) — bikes and the van as real, assignable rows.
 *
 * WHY THIS EXISTS
 * A meter reading belongs to a MACHINE, but every rule in the system keys off the
 * RIDER. The moment a rider borrows another bike, the morning continuity check,
 * the odometer window, the service clock and the running-cost figures all misfire
 * at once (Danish on Arslan's bike, Jul-29). This class is the missing entity.
 *
 * ⭐ PHASE A IS DELIBERATELY INERT. Nothing in here is consulted by any meter,
 *    fuel or alert rule yet — those still read the rider, exactly as before, until
 *    `VEHICLE_RULES` is flipped to 'Y' in Phase C. What this class does today is
 *    let the fleet be recorded and assigned, so the data can be checked BEFORE
 *    anything depends on it.
 *
 * ⭐ EVERY PUBLIC METHOD IS SAFE BEFORE THE SQL IS RUN. `available()` gates the
 *    whole class on the table existing, so an upload-before-SQL degrades to "no
 *    Vehicles view" rather than throwing. Same discipline as MaintenanceTypeService.
 *
 * THE ONE BEHAVIOUR-AFFECTING WRITE is the `company_bike` mirror in assign() —
 * see the comment there. It is intended, it is what makes the flag stay true, and
 * previewAssign() exists so the manager is told in words before he commits.
 */
class VehicleService
{
    public const T_VEHICLE = 't_ops_vehicle';
    public const T_ASSIGN  = 't_ops_vehicle_assignment';
    public const T_PHOTO   = 't_ops_vehicle_photo';

    /** Readings below this are dropped-digit typos — the bikes here are 5-figure. */
    public const MIN_METER = 1000;

    /**
     * ⚠ PLAUSIBLE ROWS ONLY — never a raw MAX/MIN over a meter column.
     * One typo'd row (26,261 → 56,403 in a day) otherwise becomes "current
     * odometer" forever and every service chip reads wildly overdue.
     *
     * This is the canonical copy. Two older copies say the same thing and must
     * stay in step until Phase C repoints them here:
     *   • FuelClaimRules::odometerWindow()
     *   • FleetFuelService::currentMeters()
     */
    public const SANE_ROW_SQL = 'meter_start > 1000
        AND (meter_end  IS NULL OR (meter_end  >= meter_start AND meter_end  - meter_start <= 500))
        AND (meter_home IS NULL OR (meter_home >= meter_start AND meter_home - meter_start <= 700))';

    // =================================================================
    // AVAILABILITY
    // =================================================================

    /** Has batch 13 been run? Cached per process — Schema::hasTable hits information_schema. */
    public function available(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = Schema::hasTable(self::T_VEHICLE)
               && Schema::hasTable(self::T_ASSIGN)
               && Schema::hasTable(self::T_PHOTO);
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /** Is the Phase-C behaviour switch on? Nothing in Phase A reads this yet. */
    public function rulesEnabled(): bool
    {
        return strtoupper((string) $this->cfg('VEHICLE_RULES', 'N')) === 'Y';
    }

    /** Km allowed for the handover ride on a transfer day (owner ruling: ~20). */
    public function transferGraceKm(): float
    {
        return (float) $this->cfg('VEHICLE_TRANSFER_GRACE_KM', 20);
    }

    // =================================================================
    // READS
    // =================================================================

    /**
     * The fleet, each row carrying its current keeper, that keeper's odometer and
     * the service state. Retired vehicles are excluded unless asked for.
     */
    public function all(bool $includeRetired = false): array
    {
        if (!$this->available()) return [];

        try {
            $q = DB::table(self::T_VEHICLE . ' as v')
                ->leftJoin(self::T_ASSIGN . ' as a', function ($j) {
                    $j->on('a.vehicle_id', '=', 'v.id')->whereNull('a.released_on');
                })
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                // ⭐ The keeper's home pin, so the list can nag until it is set —
                //    see `needs_home_pin` in shape().
                ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'a.user_id')
                ->select('v.*', 'a.id as assignment_id', 'a.user_id as keeper_user_id',
                         'a.assigned_on', 'u.fullname as keeper_name',
                         'p.home_latitude as keeper_home_lat', 'p.home_longitude as keeper_home_lng');
            if (!$includeRetired) $q->where('v.is_active', 1);

            $rows = $q->orderByDesc('v.is_active')
                      ->orderByDesc('v.vtype')      // van first, then bikes
                      ->orderByDesc('v.is_company')
                      ->orderByRaw('COALESCE(v.reg_no, v.nickname)')
                      ->get();

            $defaultInterval = (int) $this->cfg('BIKE_SERVICE_INTERVAL_KM', 1200);
            $photoCounts     = $this->photoCounts();
            $thumbs          = $this->latestPhotoPaths();

            $out = [];
            foreach ($rows as $r) {
                $meter = $this->currentMeter((int) $r->id, $r->keeper_user_id ? (int) $r->keeper_user_id : null);
                $shaped = $this->shape($r, $meter, $defaultInterval, (int) ($photoCounts[$r->id] ?? 0));
                $shaped['first_photo_url'] = $this->publicUrl($thumbs[$r->id] ?? null);
                $out[] = $shaped;
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('VehicleService::all failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** One vehicle, with keeper, service state, assignment history and photos. */
    public function find(int $id): ?array
    {
        if (!$this->available()) return null;

        try {
            $r = DB::table(self::T_VEHICLE . ' as v')
                ->leftJoin(self::T_ASSIGN . ' as a', function ($j) {
                    $j->on('a.vehicle_id', '=', 'v.id')->whereNull('a.released_on');
                })
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'a.user_id')
                ->where('v.id', $id)
                ->select('v.*', 'a.id as assignment_id', 'a.user_id as keeper_user_id',
                         'a.assigned_on', 'u.fullname as keeper_name',
                         'p.home_latitude as keeper_home_lat', 'p.home_longitude as keeper_home_lng')
                ->first();
            if (!$r) return null;

            $meter = $this->currentMeter($id, $r->keeper_user_id ? (int) $r->keeper_user_id : null);
            $shaped = $this->shape($r, $meter, (int) $this->cfg('BIKE_SERVICE_INTERVAL_KM', 1200),
                                   count($this->photosFor($id)));

            $shaped['history'] = $this->historyFor($id);
            $shaped['photos']  = $this->photosFor($id);
            return $shaped;
        } catch (\Throwable $e) {
            Log::warning('VehicleService::find failed', ['id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** The open assignment row for a vehicle, or null when nobody holds it. */
    public function keeperOf(int $vehicleId)
    {
        if (!$this->available()) return null;
        try {
            return DB::table(self::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)->whereNull('released_on')
                ->orderByDesc('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The vehicle a rider normally has (the mirror column), or null. */
    public function defaultVehicleFor(int $userId): ?int
    {
        if (!$this->available()) return null;
        try {
            $v = DB::table('t_ops_rider_profile')->where('user_id', $userId)->value('default_vehicle_id');
            return $v ? (int) $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Who has had this vehicle, newest first. */
    public function historyFor(int $vehicleId): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table(self::T_ASSIGN . ' as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('t_sys_user as ab', 'ab.id', '=', 'a.assigned_by')
                ->where('a.vehicle_id', $vehicleId)
                ->orderByDesc('a.assigned_on')->orderByDesc('a.id')
                ->get(['a.id', 'a.user_id', 'a.assigned_on', 'a.released_on', 'a.note',
                       'u.fullname as rider_name', 'ab.fullname as assigned_by_name'])
                ->map(fn ($r) => [
                    'id'          => (int) $r->id,
                    'user_id'     => (int) $r->user_id,
                    'rider_name'  => $r->rider_name,
                    'assigned_on' => $r->assigned_on ? substr((string) $r->assigned_on, 0, 10) : null,
                    'released_on' => $r->released_on ? substr((string) $r->released_on, 0, 10) : null,
                    'is_current'  => $r->released_on === null,
                    'assigned_by' => $r->assigned_by_name,
                    'note'        => $r->note,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * ⭐ THE MACHINE'S fuel + maintenance claims for a month — Phase C4 (owner
     *    ruling Aug-4: *"under Vehicles it's showing HIS fuel and maintenance,
     *    it should show the BIKE's — and who requested each one"*).
     *
     * ⚠ WHY THIS IS NOT SIMPLY `WHERE vehicle_id = ?`. The column has only been
     *   stamped since batch 13 (Aug-2026). A hard filter would show an empty
     *   screen for every earlier month and quietly lose the bike's real history.
     *   So two sources are unioned, in this order of trust:
     *     1. rows STAMPED with this vehicle — the fact, frozen at filing;
     *     2. unstamped rows filed by whoever HELD the bike on that claim's date
     *        — the same date-window reconstruction the odometer uses, and the
     *        reason a bike's cost history survives a change of keeper.
     *
     * ⭐ Every row names WHO filed it, so a rider looking at a machine he has just
     *    been given is never confused by a predecessor's claims (they read
     *    "by Waseem"), and never sees his OWN claims for a different bike here.
     *
     * Returns newest first. Read-only; never throws.
     */
    public function claimsForVehicle(int $vehicleId, string $from, string $to): array
    {
        if (!$this->available()) return [];

        try {
            $hasVehicleCol = Schema::hasColumn('t_req_master', 'vehicle_id');
            $hasTypeCol    = Schema::hasColumn('t_req_master', 'maintenance_type_id');

            $cols = ['r.id', 'r.expense_category', 'r.amount', 'r.meter_at_fill', 'r.service_type',
                     'r.status', 'r.expense_date', 'r.created_at', 'r.description',
                     'r.requester_user_id', 'u.fullname as requester_name'];
            if ($hasTypeCol)    $cols[] = 'r.maintenance_type_id';
            if ($hasVehicleCol) $cols[] = 'r.vehicle_id';

            $base = fn () => DB::table('t_req_master as r')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'r.requester_user_id')
                ->whereIn('r.expense_category', ['Petrol', 'Maintenance'])
                ->whereNotIn('r.status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(r.expense_date, DATE(r.created_at)) BETWEEN ? AND ?', [$from, $to]);

            $rows = collect();

            // 1. stamped rows — unambiguous
            if ($hasVehicleCol) {
                $rows = $base()->where('r.vehicle_id', $vehicleId)->get($cols);
            }

            // 2. unstamped rows, attributed by who held it on the claim's date
            $seen = $rows->pluck('id')->flip();
            foreach ($this->assignmentWindows($vehicleId) as $w) {
                $q = $base()
                    ->where('r.requester_user_id', $w['user_id'])
                    ->whereRaw('COALESCE(r.expense_date, DATE(r.created_at)) >= ?', [$w['from']])
                    ->when($w['to'], fn ($q2) => $q2->whereRaw(
                        'COALESCE(r.expense_date, DATE(r.created_at)) <= ?', [$w['to']]));
                if ($hasVehicleCol) $q->whereNull('r.vehicle_id');

                foreach ($q->get($cols) as $r) {
                    if (isset($seen[$r->id])) continue;
                    $seen[$r->id] = true;
                    $rows->push($r);
                }
            }

            $types = [];
            try {
                if (app(\App\Services\Riders\MaintenanceTypeService::class)->available()) {
                    $types = DB::table('t_fleet_maintenance_types')->pluck('type_name', 'id')->toArray();
                }
            } catch (\Throwable $e) {
                $types = [];
            }

            return $rows
                ->sortByDesc(fn ($r) => [substr((string) ($r->expense_date ?: $r->created_at), 0, 10), $r->id])
                ->values()
                ->map(function ($r) use ($types, $hasVehicleCol) {
                    $kind = $r->expense_category === 'Petrol' ? '⛽ Fuel' : '🔧 Maintenance';
                    if ($r->expense_category === 'Maintenance') {
                        $named = isset($r->maintenance_type_id) && $r->maintenance_type_id
                            ? ($types[$r->maintenance_type_id] ?? null) : null;
                        if ($named) $kind = '🔧 ' . $named;
                    }
                    return [
                        'id'         => (int) $r->id,
                        'date'       => substr((string) ($r->expense_date ?: $r->created_at), 0, 10),
                        'category'   => $r->expense_category,
                        'kind'       => $kind,
                        'amount'     => (float) $r->amount,
                        'meter'      => $r->meter_at_fill !== null ? (int) $r->meter_at_fill : null,
                        'status'     => (string) $r->status,
                        'is_pending' => $r->status === 'pending',
                        // ⭐ who filed it — the confusion-killer
                        'by_user_id' => (int) $r->requester_user_id,
                        'by_name'    => $r->requester_name,
                        // false = attributed by assignment window, not stamped
                        'stamped'    => $hasVehicleCol && !empty($r->vehicle_id),
                    ];
                })->all();
        } catch (\Throwable $e) {
            Log::warning('claimsForVehicle failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** Dated condition photos, newest first. */
    public function photosFor(int $vehicleId): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table(self::T_PHOTO . ' as p')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'p.uploaded_by')
                ->where('p.vehicle_id', $vehicleId)
                ->orderByDesc('p.taken_on')->orderByDesc('p.id')
                ->get(['p.id', 'p.photo_path', 'p.taken_on', 'p.context', 'p.note',
                       'p.assignment_id', 'u.fullname as by_name'])
                ->map(fn ($r) => [
                    'id'        => (int) $r->id,
                    'url'       => $this->publicUrl($r->photo_path),
                    'taken_on'  => $r->taken_on ? substr((string) $r->taken_on, 0, 10) : null,
                    'context'   => (string) $r->context,
                    'label'     => self::CONTEXT_LABELS[$r->context] ?? 'Condition',
                    'note'      => $r->note,
                    'by_name'   => $r->by_name,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public const CONTEXT_LABELS = [
        'handover_in'  => '🔑 Given to rider',
        'handover_out' => '↩️ Returned',
        'condition'    => '📷 Condition',
        'service'      => '🔧 Service',
        'damage'       => '⚠️ Damage',
    ];

    // =================================================================
    // WRITES — vehicles
    // =================================================================

    /**
     * Create or update a machine. Returns ['ok'=>bool,'message'=>?string,'id'=>?int].
     * reg_no is normalised (upper, single hyphen) so "ay 4771" and "AY-4771" cannot
     * become two vehicles; it stays NULLable because the van has no plate yet.
     */
    public function saveVehicle(array $data, ?int $id, ?int $actorId = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');

        $reg      = $this->normaliseReg($data['reg_no'] ?? null);
        $nickname = trim((string) ($data['nickname'] ?? '')) ?: null;

        if ($reg === null && $nickname === null) {
            return $this->fail('Give the vehicle a plate number or a name.');
        }

        try {
            if ($reg !== null) {
                $clash = DB::table(self::T_VEHICLE)->where('reg_no', $reg)
                    ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                if ($clash) return $this->fail('There is already a vehicle with plate ' . $reg . '.');
            }

            $row = [
                'vtype'      => in_array($data['vtype'] ?? 'bike', ['bike', 'van'], true) ? $data['vtype'] : 'bike',
                'reg_no'     => $reg,
                'nickname'   => $nickname,
                'is_company' => !empty($data['is_company']) ? 1 : 0,
                'make_model' => trim((string) ($data['make_model'] ?? '')) ?: null,
                'notes'      => trim((string) ($data['notes'] ?? '')) ?: null,
                'is_active'  => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ];

            // 0 / null means "follow the company default" — never wipe an existing
            // per-vehicle interval by omitting the field.
            if (array_key_exists('service_interval_km', $data)) {
                $iv = (int) ($data['service_interval_km'] ?? 0);
                $row['service_interval_km'] = $iv > 0 ? $iv : null;
            }

            if ($id) {
                $exists = DB::table(self::T_VEHICLE)->where('id', $id)->exists();
                if (!$exists) return $this->fail('That vehicle no longer exists.');
                DB::table(self::T_VEHICLE)->where('id', $id)->update($row);
            } else {
                $row['created_by'] = $actorId;
                $row['created_at'] = now();
                $id = (int) DB::table(self::T_VEHICLE)->insertGetId($row);
            }

            return ['ok' => true, 'message' => null, 'id' => (int) $id];
        } catch (\Throwable $e) {
            Log::error('VehicleService::saveVehicle failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->fail('Could not save that vehicle.');
        }
    }

    /**
     * Set (or clear) the vehicle's FIXED overnight base.
     *
     * Only a machine that sleeps somewhere of its own needs this — the van parks
     * away from the office. A bike left NULL keeps today's behaviour exactly:
     * it sleeps wherever its keeper does, measured against that rider's own home
     * pin. Clearing the base restores that.
     */
    public function setBase(int $vehicleId, ?float $lat, ?float $lng, ?int $radiusM, ?int $actorId = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');

        $clearing = ($lat === null || $lng === null);
        if (!$clearing && (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0))) {
            return $this->fail('That does not look like a valid location.');
        }

        try {
            DB::table(self::T_VEHICLE)->where('id', $vehicleId)->update([
                'base_latitude'  => $clearing ? null : $lat,
                'base_longitude' => $clearing ? null : $lng,
                'base_radius_m'  => $clearing ? null : (($radiusM && $radiusM > 0) ? $radiusM : null),
                'base_set_by'    => $clearing ? null : $actorId,
                'base_set_at'    => $clearing ? null : now(),
                'updated_by'     => $actorId,
                'updated_at'     => now(),
            ]);
            return ['ok' => true, 'message' => $clearing
                ? 'Base cleared — this vehicle now follows its rider\'s home.'
                : 'Base location saved.', 'id' => $vehicleId];
        } catch (\Throwable $e) {
            Log::error('VehicleService::setBase failed', ['id' => $vehicleId, 'error' => $e->getMessage()]);
            return $this->fail('Could not save the base location.');
        }
    }

    // =================================================================
    // WRITES — assignment
    // =================================================================

    /**
     * What WILL happen if this vehicle is given to this rider — computed without
     * writing anything, so the modal can state the consequences in words before
     * the manager commits. Returns a list of plain sentences.
     */
    public function previewAssign(int $vehicleId, int $userId, ?string $onDate = null): array
    {
        if (!$this->available()) return ['ok' => false, 'lines' => [], 'warnings' => []];

        $date  = $this->safeDate($onDate);
        $lines = [];
        $warn  = [];

        try {
            $v = DB::table(self::T_VEHICLE)->where('id', $vehicleId)->first();
            if (!$v) return ['ok' => false, 'lines' => [], 'warnings' => ['That vehicle no longer exists.']];

            $name     = $this->displayName($v);
            $rider    = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: ('user ' . $userId);
            $profile  = DB::table('t_ops_rider_profile')->where('user_id', $userId)->first();
            $current  = $this->keeperOf($vehicleId);

            if ($current && (int) $current->user_id === $userId) {
                return ['ok' => true, 'lines' => [$name . ' is already with ' . $rider . '.'], 'warnings' => [],
                        'no_change' => true];
            }

            $lines[] = 'From ' . $date . ', ' . $name . '\'s meter readings and service record follow ' . $rider . '.';

            if ($current) {
                $prev = DB::table('t_sys_user')->where('id', $current->user_id)->value('fullname') ?: 'the current rider';
                $lines[] = $prev . ' is released from ' . $name . ' on the same date.';
            }

            // The rider's existing machine, if any — he can only have one default.
            $his = $this->openAssignmentForUser($userId);
            if ($his && (int) $his->vehicle_id !== $vehicleId) {
                $hisV = DB::table(self::T_VEHICLE)->where('id', $his->vehicle_id)->first();
                if ($hisV) {
                    $lines[] = $rider . ' is released from ' . $this->displayName($hisV)
                             . ', which becomes unassigned.';
                }
            }

            // Transfer-day allowance — only says anything when there is a previous
            // holder, because that is the only case with a handover ride.
            if ($current) {
                $lines[] = 'The handover ride on ' . $date . ' is allowed up to '
                         . rtrim(rtrim(number_format($this->transferGraceKm(), 1), '0'), '.')
                         . ' km — it is recorded as transfer travel, not personal use.';
            }

            // What changes, and — just as importantly — what does not change YET.
            // Saying "his fuel rules change now" while VEHICLE_RULES is still off
            // would be a plain lie to the manager pressing the button.
            if ($profile) {
                $was = (int) ($profile->company_bike ?? 0);
                $now = (int) $v->is_company;
                if ($was !== $now) {
                    $what = $now === 1
                        ? $rider . ' counts as riding a COMPANY vehicle: the company buys his fuel, and a '
                          . 'meter reading is required on his fuel and service claims'
                        : $rider . ' counts as riding his OWN vehicle, so the company-bike fuel rules '
                          . 'do not apply to him';

                    $warn[] = $this->rulesEnabled()
                        ? ucfirst($what) . '.'
                        : 'Once vehicle rules are switched on, ' . $what . '. Until then his fuel and meter '
                          . 'rules are unchanged — this assignment is recorded, not yet enforced.';
                }

                // Overnight checks need a pin somewhere. Silence here is the trap
                // the plan called out — a company bike with no pin quietly has no
                // overnight accountability at all.
                $hasBase = $v->base_latitude !== null && $v->base_longitude !== null;
                $hasPin  = $profile->home_latitude !== null && $profile->home_longitude !== null;
                if ((int) $v->is_company === 1 && !$hasBase && !$hasPin) {
                    $warn[] = $rider . ' has no home location set, and ' . $name . ' has no base of its own — '
                            . 'so the overnight and morning meter checks will have nowhere to measure against. '
                            . 'Set his home pin on the Riders page, or give the vehicle a base.';
                }
            } else {
                $warn[] = $rider . ' has no rider profile, so meter tracking will not apply to him.';
            }

            return ['ok' => true, 'lines' => $lines, 'warnings' => $warn, 'no_change' => false];
        } catch (\Throwable $e) {
            Log::warning('VehicleService::previewAssign failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'lines' => [], 'warnings' => ['Could not work out the consequences.']];
        }
    }

    /**
     * Give a vehicle to a rider.
     *
     * Order matters and is all inside one transaction:
     *   1. same rider already holds it        → no-op (idempotent; a double-tap
     *                                            must not create a second row)
     *   2. release the vehicle's current keeper on this date
     *   3. release the rider from whatever he was holding (one default per rider —
     *      the mirror column can only carry one id)
     *   4. open the new assignment row
     *   5. point the rider's profile at it (`default_vehicle_id`)
     *
     * ⭐ WHAT THIS DELIBERATELY DOES **NOT** TOUCH: `t_ops_rider_profile.company_bike`.
     *
     *    That flag is read by a dozen LIVE call sites — FuelClaimRules::ridesCompanyBike,
     *    HomeJourneyService::riderHomePin (which arms the whole overnight meter flow),
     *    WorkJourneyService::workIssues, FleetFuelService, the attendance surfaces.
     *    Writing it from an assignment would mean pressing "Assign" silently changes
     *    whose fuel the company buys, who is asked for a meter, and whose overnight
     *    checks arm — while `VEHICLE_RULES` is still OFF and this phase is supposed to
     *    record the fleet, not govern it.
     *
     *    It is also unfixable in the release direction: when a rider hands a bike back
     *    he holds nothing, and BOTH answers are wrong. Leaving it at 1 keeps the per-km
     *    petrol block lifted for someone with no company bike (a money risk, since
     *    isPerKmRider() short-circuits on this flag); forcing it to 0 disarms the
     *    overnight flow for a rider who may simply have had his bike in the workshop
     *    for a day. There is no safe automatic answer, which is the tell that the
     *    write does not belong here.
     *
     *    Phase C settles it properly: the rules read the VEHICLE through
     *    VehicleResolver, `company_bike` stops being consulted, and no mirror is
     *    needed. Until then the flag stays exactly as the owner set it, and
     *    previewAssign() says plainly that fuel treatment changes only once vehicle
     *    rules are switched on.
     */
    public function assign(int $vehicleId, int $userId, ?string $onDate = null,
                           ?int $actorId = null, ?string $note = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');

        $date = $this->safeDate($onDate);

        try {
            $v = DB::table(self::T_VEHICLE)->where('id', $vehicleId)->first();
            if (!$v) return $this->fail('That vehicle no longer exists.');
            if ((int) $v->is_active !== 1) return $this->fail('That vehicle is retired — reactivate it first.');

            $profile = DB::table('t_ops_rider_profile')->where('user_id', $userId)->first();
            if (!$profile) return $this->fail('That user has no rider profile, so a vehicle cannot be assigned to him.');

            $current = $this->keeperOf($vehicleId);
            if ($current && (int) $current->user_id === $userId) {
                return ['ok' => true, 'message' => 'Already assigned to that rider.', 'id' => (int) $current->id,
                        'changed' => false];
            }

            $newId = null;
            DB::transaction(function () use ($vehicleId, $userId, $date, $actorId, $note, $v, &$newId) {
                // ⚠ RE-READ INSIDE THE TRANSACTION, and lock the rows. The check
                //   above ran outside it, so two managers pressing Assign on the
                //   same bike within the same second would each see "no change
                //   needed", each insert, and leave the vehicle with TWO open
                //   assignments — a state nothing else in this class expects.
                //   lockForUpdate serialises them; the second one now sees the
                //   first one's row and closes it properly.
                $current = DB::table(self::T_ASSIGN)
                    ->where('vehicle_id', $vehicleId)->whereNull('released_on')
                    ->orderByDesc('id')->lockForUpdate()->first();

                // Someone else got there first with the same rider — nothing to do.
                if ($current && (int) $current->user_id === $userId) {
                    $newId = (int) $current->id;
                    return;
                }

                // 2. release the current keeper of THIS vehicle
                if ($current) {
                    $this->closeAssignment((int) $current->id, $date, $actorId);
                    $this->clearMirrorIfPointsAt((int) $current->user_id, $vehicleId);
                }

                // 3. release the RIDER from anything else he holds. Locked for the
                //    same reason as above.
                $his = DB::table(self::T_ASSIGN)->where('user_id', $userId)
                    ->whereNull('released_on')->orderByDesc('id')->lockForUpdate()->first();
                if ($his && (int) $his->vehicle_id !== $vehicleId) {
                    $this->closeAssignment((int) $his->id, $date, $actorId);
                }

                // 4. the new assignment
                $newId = (int) DB::table(self::T_ASSIGN)->insertGetId([
                    'vehicle_id'  => $vehicleId,
                    'user_id'     => $userId,
                    'assigned_on' => $date,
                    'assigned_by' => $actorId,
                    'note'        => $note ? mb_substr($note, 0, 255) : null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

                // 5. the mirror — `default_vehicle_id` ONLY. That column is new and
                //    nothing in the live rules reads it yet, so this stays inert.
                //    company_bike is deliberately untouched (block comment above).
                DB::table('t_ops_rider_profile')->where('user_id', $userId)->update([
                    'default_vehicle_id' => $vehicleId,
                    'updated_at'         => now(),
                ]);
            });

            Log::info('Vehicle assigned', [
                'vehicle_id' => $vehicleId, 'to_user' => $userId,
                'from_user'  => $current->user_id ?? null,
                'on'         => $date, 'by' => $actorId,
            ]);

            return ['ok' => true, 'message' => $this->displayName($v) . ' assigned.', 'id' => $newId,
                    'changed' => true];
        } catch (\Throwable $e) {
            Log::error('VehicleService::assign failed', [
                'vehicle_id' => $vehicleId, 'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
            return $this->fail('Could not assign that vehicle.');
        }
    }

    /** Take a vehicle back without giving it to anyone else. */
    public function release(int $vehicleId, ?string $onDate = null, ?int $actorId = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');

        $date = $this->safeDate($onDate);
        try {
            $current = $this->keeperOf($vehicleId);
            if (!$current) return ['ok' => true, 'message' => 'Nobody holds that vehicle.', 'changed' => false];

            DB::transaction(function () use ($current, $vehicleId, $date, $actorId) {
                $this->closeAssignment((int) $current->id, $date, $actorId);
                $this->clearMirrorIfPointsAt((int) $current->user_id, $vehicleId);
            });

            Log::info('Vehicle released', ['vehicle_id' => $vehicleId, 'from_user' => $current->user_id, 'on' => $date]);
            return ['ok' => true, 'message' => 'Released.', 'changed' => true];
        } catch (\Throwable $e) {
            Log::error('VehicleService::release failed', ['id' => $vehicleId, 'error' => $e->getMessage()]);
            return $this->fail('Could not release that vehicle.');
        }
    }

    // =================================================================
    // WRITES — photos
    // =================================================================

    /**
     * Record a dated condition photo. `$path` is already-stored relative path
     * (the controller does the upload, matching storeMeterPicture's convention).
     * Never blocks an assignment — a handover with no photo is still a handover.
     */
    public function addPhoto(int $vehicleId, string $path, ?string $takenOn, string $context,
                             ?string $note, ?int $actorId, ?int $assignmentId = null): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');
        if (!array_key_exists($context, self::CONTEXT_LABELS)) $context = 'condition';

        try {
            $id = (int) DB::table(self::T_PHOTO)->insertGetId([
                'vehicle_id'    => $vehicleId,
                'assignment_id' => $assignmentId,
                'photo_path'    => $path,
                'taken_on'      => $this->safeDate($takenOn),
                'context'       => $context,
                'note'          => $note ? mb_substr($note, 0, 255) : null,
                'uploaded_by'   => $actorId,
                'created_at'    => now(),
            ]);
            return ['ok' => true, 'message' => null, 'id' => $id];
        } catch (\Throwable $e) {
            Log::error('VehicleService::addPhoto failed', ['vehicle_id' => $vehicleId, 'error' => $e->getMessage()]);
            return $this->fail('Could not save that photo.');
        }
    }

    /** Remove a photo row. The file is left on disk — cheap, and undelete is manual. */
    public function deletePhoto(int $photoId): array
    {
        if (!$this->available()) return $this->fail('Vehicles are not set up yet (SQL batch 13).');
        try {
            DB::table(self::T_PHOTO)->where('id', $photoId)->delete();
            return ['ok' => true, 'message' => 'Photo removed.', 'id' => $photoId];
        } catch (\Throwable $e) {
            return $this->fail('Could not remove that photo.');
        }
    }

    // =================================================================
    // internals
    // =================================================================

    /**
     * The vehicle's current odometer.
     *
     * Forward-compatible on purpose: once attendance rows carry `vehicle_id`
     * (Phase B) this reads the MACHINE's own history directly. Until then it is
     * reconstructed from the assignment timeline.
     *
     * ⚠ THE FALLBACK MUST BE DATE-SCOPED, and getting this wrong is not academic.
     *   A first version simply took the CURRENT keeper's highest reading. That is
     *   right only while nobody has ever swapped: the moment BCN-5755 was hosted
     *   over to Danish, the card claimed it read 33,732 km — Danish's OWN bike —
     *   when the machine itself was at 34,071, and the service-due figure inherited
     *   the error. A rider's readings belong to whatever he was riding AT THE TIME.
     *
     *   So each keeper only contributes readings from the window he actually held
     *   this vehicle: [assigned_on, released_on], open-ended while he still has it.
     *   Danish's pre-handover months land on his own bike, where they belong, and
     *   BCN-5755 keeps Arslan's 34,071.
     */
    private function currentMeter(int $vehicleId, ?int $keeperUserId): ?int
    {
        try {
            // 1. The machine's own rows (Phase B onwards).
            $byVehicle = (int) DB::table('t_ops_attendance')
                ->where('vehicle_id', $vehicleId)
                ->whereRaw(self::SANE_ROW_SQL)
                ->selectRaw('MAX(GREATEST(COALESCE(meter_end,0), COALESCE(meter_home,0), COALESCE(meter_start,0))) AS m')
                ->value('m');

            $byFill = (int) DB::table('t_req_master')
                ->where('vehicle_id', $vehicleId)
                ->whereNotNull('meter_at_fill')
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->max('meter_at_fill');

            $best = max($byVehicle, $byFill);

            // 2. Reconstruct from who held it when.
            foreach ($this->assignmentWindows($vehicleId) as $w) {
                $att = (int) DB::table('t_ops_attendance')
                    ->where('user_id', $w['user_id'])
                    ->where('attendance_date', '>=', $w['from'])
                    ->when($w['to'], fn ($q) => $q->where('attendance_date', '<=', $w['to']))
                    ->whereRaw(self::SANE_ROW_SQL)
                    ->selectRaw('MAX(GREATEST(COALESCE(meter_end,0), COALESCE(meter_home,0), COALESCE(meter_start,0))) AS m')
                    ->value('m');

                $fill = (int) DB::table('t_req_master')
                    ->where('requester_user_id', $w['user_id'])
                    ->whereNotNull('meter_at_fill')
                    ->where('meter_at_fill', '>', self::MIN_METER)
                    ->whereNotIn('status', ['cancelled', 'rejected'])
                    ->whereRaw('COALESCE(expense_date, DATE(created_at)) >= ?', [$w['from']])
                    ->when($w['to'], fn ($q) => $q->whereRaw('COALESCE(expense_date, DATE(created_at)) <= ?', [$w['to']]))
                    ->max('meter_at_fill');

                $best = max($best, $att, $fill);
            }

            return $best > self::MIN_METER ? $best : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ⭐ THE MACHINE'S odometer window around a date — floor (highest plausible
     *    reading before it) and ceiling (lowest after) — for validating a claim's
     *    meter. Phase C: `FuelClaimRules::odometerWindow` delegates here when the
     *    rules are on and the rider holds a registered vehicle.
     *
     * ⚠ WHY. The rider-keyed window breaks the moment a bike changes hands:
     *   Danish's own history peaks ~33,700, so his first fill on DCR-799
     *   (~24,800) reads "lower than this bike's 33,700" and is refused — the
     *   right reading rejected because the window was watching the MAN. Worse,
     *   the mistake it exists to catch (typing the OLD bike's meter) would
     *   PASS. Built from the machine's own series, both come out right.
     *
     * Sources, same trust order as `claimsForVehicle`:
     *   • attendance meters of whoever HELD the bike, inside their window;
     *   • claims STAMPED with this vehicle;
     *   • unstamped claims by a keeper inside his window (pre-Aug history).
     *
     * A machine with no readings yet returns [null, null] — no constraint, which
     * is correct for a fresh bike. Errors return null so the caller can fall
     * back to the rider-keyed window rather than losing the check entirely.
     */
    /**
     * ⭐ THE MACHINE'S fuel average, monthly (owner ruling Aug-4).
     *
     * "This month vs the last 3 months" replaced the old single figure, for two
     * reasons the owner named himself:
     *   • a LIFETIME average is impossible — the bikes' first kilometres predate
     *     any tracking, so the number would be a guess wearing a suit;
     *   • the old figure was keyed to the RIDER's month, so Danish — given
     *     DCR-799 on day one — saw a dash even though the BIKE has a month of
     *     real fills and meters behind it.
     *
     * km for a month = the machine's highest plausible reading before the month
     * ends minus the same before it starts — literally two calls to
     * `meterWindowFor`'s floor, so the odometer, the claim validation and this
     * average all read ONE series and cannot disagree.
     *
     * Fuel Rs comes from `claimsForVehicle` (stamped + window-attributed), the
     * same rows the profile lists — the average is always explainable by the
     * list under it.
     *
     * Returns ['fuel_rs','km','rs_per_km'] — km/rs_per_km null when the meters
     * cannot support the answer (too few readings, or a senseless span). A dash
     * is better than a confident fiction.
     */
    public function monthlyFuelStats(int $vehicleId, string $month): array
    {
        $out = ['month' => $month, 'fuel_rs' => 0.0, 'km' => null, 'rs_per_km' => null];
        if (!$this->available()) return $out;

        try {
            $start = Carbon::parse($month . '-01')->startOfMonth();
            $end   = $start->copy()->endOfMonth();

            $fuel = 0.0;
            foreach ($this->claimsForVehicle($vehicleId, $start->format('Y-m-d'), $end->format('Y-m-d')) as $c) {
                if (($c['category'] ?? '') === 'Petrol') $fuel += (float) $c['amount'];
            }
            $out['fuel_rs'] = round($fuel, 2);

            $before = $this->meterWindowFor($vehicleId, $start->format('Y-m-d'));
            $after  = $this->meterWindowFor($vehicleId, $end->copy()->addDay()->format('Y-m-d'));
            $lo = $before['floor'] ?? null;
            $hi = $after['floor'] ?? null;

            // 2,000 km/month is far beyond any bike here — beyond it the delta is
            // a typo'd meter, not a distance. Same instinct as MAX_FORWARD_JUMP.
            if ($lo !== null && $hi !== null && $hi > $lo && ($hi - $lo) <= 2000) {
                $out['km'] = $hi - $lo;
                if ($out['km'] >= 20 && $fuel > 0) {
                    $out['rs_per_km'] = round($fuel / $out['km'], 2);
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('monthlyFuelStats failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return $out;
        }
    }

    /**
     * The comparison the profile shows: the selected month vs the THREE months
     * before it, POOLED (total fuel ÷ total km — an average of averages would
     * let one weak month distort the figure).
     */
    public function fuelAverages(int $vehicleId, string $month): array
    {
        $this_ = $this->monthlyFuelStats($vehicleId, $month);

        // ⚠ MATCHED MONTHS ONLY for the ratio. Pooling ALL fuel over only the
        //   months whose kilometres were measurable inflated the figure wildly
        //   (BCN: Rs 68,500 over 1,522 km read as Rs 45/km — three months of
        //   fuel, one month of distance). A month with no usable meter delta
        //   contributes to NEITHER side of the division.
        $fuelAll = 0.0; $fuelMatched = 0.0; $km = 0; $haveKm = false;
        try {
            $c = Carbon::parse($month . '-01');
            for ($i = 1; $i <= 3; $i++) {
                $m = $this->monthlyFuelStats($vehicleId, $c->copy()->subMonthsNoOverflow($i)->format('Y-m'));
                $fuelAll += (float) $m['fuel_rs'];
                if ($m['km'] !== null) {
                    $km          += (int) $m['km'];
                    $fuelMatched += (float) $m['fuel_rs'];
                    $haveKm       = true;
                }
            }
        } catch (\Throwable $e) { /* the comparison is a nicety */ }

        return [
            'this_month' => $this_,
            'last3' => [
                'fuel_rs'   => round($fuelAll, 2),   // total SPEND — still honest as a total
                'km'        => $haveKm ? $km : null,
                'rs_per_km' => ($haveKm && $km >= 20 && $fuelMatched > 0)
                    ? round($fuelMatched / $km, 2) : null,
            ],
        ];
    }

    /**
     * ⭐ THE CURRENT KEEPER'S STINT on this machine (owner ruling Aug-4): his
     *    kilometres and his Rs/km since HE took it — set against the bike's
     *    last-3-months baseline this answers "is the rider driving it badly
     *    while the bike itself was fine?". A monthly figure cannot: a mid-month
     *    handover mixes two riders' days into one number.
     *
     * km  = the machine's reading now minus its reading at the handover.
     *       When the stint predates tracking (Arslan has had BCN since January,
     *       and January has no readings) the baseline falls back to the first
     *       plausible reading INSIDE the stint — fewer km than the truth, never
     *       more, so the average errs against flattery.
     * fuel = HIS petrol rows out of `claimsForVehicle` — the same attribution
     *        the list on screen shows, so the figure is always explainable.
     *
     * Null when there is no keeper; km/ratio null while the stint is too new to
     * measure (day one: a dash, not a guess).
     */
    public function keeperStintStats(int $vehicleId): ?array
    {
        if (!$this->available()) return null;
        try {
            $a = $this->keeperOf($vehicleId);
            if (!$a) return null;

            $since = substr((string) $a->assigned_on, 0, 10);
            $uid   = (int) $a->user_id;
            $days  = max(1, Carbon::parse($since)->diffInDays(Carbon::today()) + 1);

            $startWin = $this->meterWindowFor($vehicleId, $since);
            $endWin   = $this->meterWindowFor($vehicleId, Carbon::tomorrow()->format('Y-m-d'));
            $base = $startWin['floor'] ?? $startWin['ceil'] ?? null;   // handover meter, or first reading in the stint
            $now  = $endWin['floor'] ?? null;

            $km = null;
            if ($base !== null && $now !== null && $now >= $base) {
                $delta = $now - $base;
                // ~300 km/day is beyond any rider here — beyond it a typo'd
                // meter is masquerading as distance.
                if ($delta <= $days * 300 + 50) $km = $delta;
            }

            $fuel = 0.0;
            foreach ($this->claimsForVehicle($vehicleId, $since, Carbon::today()->format('Y-m-d')) as $c) {
                if (($c['category'] ?? '') === 'Petrol' && (int) ($c['by_user_id'] ?? 0) === $uid) {
                    $fuel += (float) $c['amount'];
                }
            }

            return [
                'user_id'   => $uid,
                'name'      => DB::table('t_sys_user')->where('id', $uid)->value('fullname'),
                'since'     => $since,
                'days'      => $days,
                'km'        => $km,
                'fuel_rs'   => round($fuel, 2),
                'rs_per_km' => ($km !== null && $km >= 20 && $fuel > 0) ? round($fuel / $km, 2) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('keeperStintStats failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function meterWindowFor(int $vehicleId, string $date): ?array
    {
        if (!$this->available()) return null;
        try {
            $sane = 'meter_start > ' . self::MIN_METER . '
                     AND (meter_end IS NULL OR (meter_end >= meter_start AND meter_end - meter_start <= 500))
                     AND (meter_home IS NULL OR (meter_home >= meter_start AND meter_home - meter_start <= 700))';

            $floorC = [0];
            $ceilC  = [];

            $windows = $this->assignmentWindows($vehicleId);
            foreach ($windows as $w) {
                // BEFORE the date, clamped to his window.
                $before = DB::table('t_ops_attendance')
                    ->where('user_id', $w['user_id'])
                    ->where('attendance_date', '>=', $w['from'])
                    ->when($w['to'], fn ($q) => $q->where('attendance_date', '<=', $w['to']))
                    ->where('attendance_date', '<', $date)
                    ->whereRaw($sane)
                    ->selectRaw('MAX(GREATEST(COALESCE(meter_end,0), COALESCE(meter_home,0), COALESCE(meter_start,0))) AS m')
                    ->value('m');
                if ((int) $before > 0) $floorC[] = (int) $before;

                // AFTER the date, still inside his window.
                $after = DB::table('t_ops_attendance')
                    ->where('user_id', $w['user_id'])
                    ->where('attendance_date', '>=', $w['from'])
                    ->when($w['to'], fn ($q) => $q->where('attendance_date', '<=', $w['to']))
                    ->where('attendance_date', '>', $date)
                    ->whereRaw($sane)
                    ->selectRaw('MIN(COALESCE(NULLIF(meter_start,0), NULLIF(meter_end,0), NULLIF(meter_home,0))) AS m')
                    ->value('m');
                if ((int) $after > self::MIN_METER) $ceilC[] = (int) $after;

                // Unstamped legacy claims by this keeper inside his window.
                if (Schema::hasColumn('t_req_master', 'vehicle_id')) {
                    $legacyQ = fn () => DB::table('t_req_master')
                        ->where('requester_user_id', $w['user_id'])
                        ->whereNull('vehicle_id')
                        ->whereNotNull('meter_at_fill')
                        ->where('meter_at_fill', '>', self::MIN_METER)
                        ->whereNotIn('status', ['cancelled', 'rejected'])
                        ->whereRaw('COALESCE(expense_date, DATE(created_at)) >= ?', [$w['from']])
                        ->when($w['to'], fn ($q) => $q->whereRaw(
                            'COALESCE(expense_date, DATE(created_at)) <= ?', [$w['to']]));
                    $lb = $legacyQ()->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$date])->max('meter_at_fill');
                    if ((int) $lb > 0) $floorC[] = (int) $lb;
                    $la = $legacyQ()->whereRaw('COALESCE(expense_date, DATE(created_at)) > ?', [$date])->min('meter_at_fill');
                    if ((int) $la > self::MIN_METER) $ceilC[] = (int) $la;
                }
            }

            // Claims stamped to THIS machine — whoever filed them.
            if (Schema::hasColumn('t_req_master', 'vehicle_id')) {
                $stampedQ = fn () => DB::table('t_req_master')
                    ->where('vehicle_id', $vehicleId)
                    ->whereNotNull('meter_at_fill')
                    ->where('meter_at_fill', '>', self::MIN_METER)
                    ->whereNotIn('status', ['cancelled', 'rejected']);
                $sb = $stampedQ()->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$date])->max('meter_at_fill');
                if ((int) $sb > 0) $floorC[] = (int) $sb;
                $sa = $stampedQ()->whereRaw('COALESCE(expense_date, DATE(created_at)) > ?', [$date])->min('meter_at_fill');
                if ((int) $sa > self::MIN_METER) $ceilC[] = (int) $sa;
            }

            $floor = max($floorC);
            return [
                'floor' => $floor > self::MIN_METER ? $floor : null,
                'ceil'  => $ceilC ? min($ceilC) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('meterWindowFor failed', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return null;   // caller falls back to the rider-keyed window
        }
    }

    /** Every [from, to] window this vehicle has been held in. `to` null = still held. */
    private function assignmentWindows(int $vehicleId): array
    {
        try {
            return DB::table(self::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)
                ->orderBy('assigned_on')
                ->get(['user_id', 'assigned_on', 'released_on'])
                ->map(fn ($a) => [
                    'user_id' => (int) $a->user_id,
                    'from'    => substr((string) $a->assigned_on, 0, 10),
                    'to'      => $a->released_on ? substr((string) $a->released_on, 0, 10) : null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Shape one DB row into the payload both web and mobile read. */
    private function shape($r, ?int $meter, int $defaultInterval, int $photoCount): array
    {
        $interval = (int) ($r->service_interval_km ?: $defaultInterval);
        $last     = $r->last_service_meter !== null ? (int) $r->last_service_meter : null;

        $since = ($meter !== null && $last !== null) ? $meter - $last : null;
        $dueIn = ($since !== null && $interval > 0) ? $interval - $since : null;

        return [
            'id'         => (int) $r->id,
            'vtype'      => (string) $r->vtype,
            'reg_no'     => $r->reg_no,
            'nickname'   => $r->nickname,
            'name'       => $this->displayName($r),
            'is_company' => (int) $r->is_company === 1,
            'make_model' => $r->make_model,
            'is_active'  => (int) $r->is_active === 1,
            'notes'      => $r->notes,

            'keeper_user_id' => $r->keeper_user_id ? (int) $r->keeper_user_id : null,
            'keeper_name'    => $r->keeper_name,
            'assigned_on'    => $r->assigned_on ? substr((string) $r->assigned_on, 0, 10) : null,
            'assignment_id'  => $r->assignment_id ? (int) $r->assignment_id : null,

            'current_meter' => $meter,
            'service' => [
                'interval_km'        => $interval,
                'last_service_meter' => $last,
                'last_service_at'    => $r->last_service_at ? substr((string) $r->last_service_at, 0, 10) : null,
                'since_km'           => $since,
                'due_in_km'          => $dueIn,
                'state'              => $dueIn === null ? 'unknown' : ($dueIn < 0 ? 'overdue' : ($dueIn <= 150 ? 'due_soon' : 'ok')),
            ],

            'base' => [
                'has_base'  => $r->base_latitude !== null && $r->base_longitude !== null,
                'latitude'  => $r->base_latitude !== null ? (float) $r->base_latitude : null,
                'longitude' => $r->base_longitude !== null ? (float) $r->base_longitude : null,
                'radius_m'  => $r->base_radius_m !== null ? (int) $r->base_radius_m : null,
            ],

            'photo_count' => $photoCount,

            /**
             * ⭐ STANDING NAG: this machine's overnight and morning meter checks
             *    have nowhere to measure from (owner ruling Aug-4: "an alert
             *    should stay telling the manager a home location needs to be set,
             *    and go away once entered").
             *
             *    True only when it MATTERS: a COMPANY machine, actually held by
             *    someone, with neither its own base (the van's parking) nor the
             *    keeper's home pin. A personal bike or an unassigned one is
             *    nobody's problem, so it stays quiet — an alert that cries wolf
             *    gets ignored, and this one has a real job.
             *
             *    It clears itself the moment the pin is saved; there is nothing
             *    to dismiss and nothing to remember.
             */
            'needs_home_pin' => (int) $r->is_company === 1
                && !empty($r->keeper_user_id)
                && $r->base_latitude === null
                && (!property_exists($r, 'keeper_home_lat') || $r->keeper_home_lat === null),
        ];
    }

    /** "AY-4771", or the nickname when there is no plate (the van). */
    private function displayName($v): string
    {
        $reg  = trim((string) ($v->reg_no ?? ''));
        $nick = trim((string) ($v->nickname ?? ''));
        if ($reg !== '' && $nick !== '') return $reg;
        if ($reg !== '') return $reg;
        return $nick !== '' ? $nick : ('Vehicle #' . ($v->id ?? '?'));
    }

    private function photoCounts(): array
    {
        try {
            return DB::table(self::T_PHOTO)->selectRaw('vehicle_id, COUNT(*) AS n')
                ->groupBy('vehicle_id')->pluck('n', 'vehicle_id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Newest photo per vehicle, for the card thumbnail. One query for the whole
     * grid — a per-card lookup would be a query per vehicle on every load.
     */
    private function latestPhotoPaths(): array
    {
        try {
            $rows = DB::table(self::T_PHOTO)
                ->orderBy('vehicle_id')->orderByDesc('taken_on')->orderByDesc('id')
                ->get(['vehicle_id', 'photo_path']);
            $out = [];
            foreach ($rows as $r) {
                if (!isset($out[$r->vehicle_id])) $out[$r->vehicle_id] = $r->photo_path;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function openAssignmentForUser(int $userId)
    {
        try {
            return DB::table(self::T_ASSIGN)->where('user_id', $userId)
                ->whereNull('released_on')->orderByDesc('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Close an assignment row. released_on can never precede assigned_on — a
     * backdated handover would otherwise produce a row that ended before it began
     * and break every "who had it on date X" read.
     */
    private function closeAssignment(int $assignmentId, string $date, ?int $actorId): void
    {
        $row = DB::table(self::T_ASSIGN)->where('id', $assignmentId)->first(['assigned_on']);
        $on  = $date;
        if ($row && $row->assigned_on && substr((string) $row->assigned_on, 0, 10) > $date) {
            $on = substr((string) $row->assigned_on, 0, 10);
        }
        DB::table(self::T_ASSIGN)->where('id', $assignmentId)->update([
            'released_on' => $on,
            'released_by' => $actorId,
            'updated_at'  => now(),
        ]);
    }

    /** Drop the profile mirror only when it still points at the vehicle being taken. */
    private function clearMirrorIfPointsAt(int $userId, int $vehicleId): void
    {
        DB::table('t_ops_rider_profile')
            ->where('user_id', $userId)
            ->where('default_vehicle_id', $vehicleId)
            ->update(['default_vehicle_id' => null, 'updated_at' => now()]);
        // company_bike is deliberately NOT reset here — see assign()'s block comment.
    }

    private function normaliseReg($raw): ?string
    {
        $s = strtoupper(trim((string) $raw));
        if ($s === '') return null;
        $s = preg_replace('/[\s\-_]+/', '-', $s);
        return mb_substr($s, 0, 32);
    }

    /** A real Y-m-d, never in the future (an open row dated tomorrow reads as a bug). */
    private function safeDate($raw): string
    {
        try {
            $c = $raw ? Carbon::parse($raw) : Carbon::today();
        } catch (\Throwable $e) {
            $c = Carbon::today();
        }
        if ($c->gt(Carbon::today())) $c = Carbon::today();
        return $c->format('Y-m-d');
    }

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

    private function cfg(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'id' => null];
    }
}
