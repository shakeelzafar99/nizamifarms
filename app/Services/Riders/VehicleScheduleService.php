<?php

namespace App\Services\Riders;

use App\Models\Riders\MaintenanceTypeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ "THIS VEHICLE DOES **THIS JOB** EVERY N" — the exceptions table (Sep-2026).
 *
 * WHY IT EXISTS
 * The old per-vehicle control (`t_ops_vehicle.service_interval_km`) was ONE number
 * that named no job, so the engine had to guess which countdown the manager meant.
 * It guessed "the shortest clock-resetting type", and that guess MOVED on 22-Aug
 * when an unrelated checkbox changed — a bike's 1,200 landed on a job whose own
 * standard says 2,000, and nobody had touched that job. The Aug-27 resolver stopped
 * honouring the guess, which is why typing a number into that prompt has changed
 * nothing on any screen since. A control whose effect cannot be predicted is not a
 * control, and a scalar cannot say "…except on this bike" because it does not name
 * a job.
 *
 * ⭐ THE TWO LEVELS (owner ruling, 10-Sep-2026):
 *      1. the CLASS STANDARD — every job's own number for bikes and for vans. This
 *         is what "company default" has always meant, now said per job.
 *      2. the VEHICLE OVERRIDE — a row here, for the exceptions only.
 *   Changing a standard therefore has to ASK about the vehicles holding their own
 *   value for that job (`exceptionsFor`), which is what `clearFor` then acts on.
 *
 * ⚠ Guarded on Schema::hasTable throughout: the web files may be uploaded before the
 *   SQL runs, and that must degrade to "no vehicle has an exception" rather than 500
 *   every fleet screen.
 */
class VehicleScheduleService
{
    public const TABLE = 't_ops_vehicle_service_schedule';

    private static ?bool $tableExists = null;

    /** vehicleId => [typeId => ['km' => ?int, 'days' => ?int]] */
    private static array $memo = [];

    public function available(): bool
    {
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Schema::hasTable(self::TABLE);
            } catch (\Throwable $e) {
                self::$tableExists = false;
            }
        }
        return self::$tableExists;
    }

    /**
     * Every override this vehicle carries, keyed by maintenance type id.
     *
     * ⚠ Memoised per process: `serviceScheduleFor` asks once per type per render and
     *   the alert sweep asks again for the same machine, so an unmemoised read here
     *   would put a query behind every row of every fleet screen. Cleared by flush().
     */
    public function forVehicle(?int $vehicleId): array
    {
        if (!$vehicleId || !$this->available()) return [];
        if (array_key_exists($vehicleId, self::$memo)) return self::$memo[$vehicleId];

        $out = [];
        try {
            foreach (DB::table(self::TABLE)->where('vehicle_id', $vehicleId)
                        ->get(['maintenance_type_id', 'interval_km', 'interval_days']) as $r) {
                $out[(int) $r->maintenance_type_id] = [
                    'km'   => ($r->interval_km   !== null && (int) $r->interval_km   > 0) ? (int) $r->interval_km   : null,
                    'days' => ($r->interval_days !== null && (int) $r->interval_days > 0) ? (int) $r->interval_days : null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Vehicle service schedule read failed',
                         ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return self::$memo[$vehicleId] = [];
        }
        return self::$memo[$vehicleId] = $out;
    }

    /** This vehicle's override for one job, or null. */
    public function forVehicleType(?int $vehicleId, ?int $typeId): ?array
    {
        if (!$typeId) return null;
        return $this->forVehicle($vehicleId)[(int) $typeId] ?? null;
    }

    /**
     * Save the whole set for one vehicle in one go — the editor posts every job it
     * showed, so a job left blank must have its row REMOVED rather than kept at its
     * old value. Partial saves are how a screen and its table drift apart.
     *
     * @param  array<int, array{km?:?int, days?:?int}> $rows  typeId => figures
     * @return array{ok:bool, saved:int, cleared:int, message:string}
     */
    public function saveFor(int $vehicleId, array $rows, ?int $actorId): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'saved' => 0, 'cleared' => 0,
                    'message' => 'Per-vehicle schedules are not set up on this database yet.'];
        }

        $saved = 0; $cleared = 0;
        try {
            DB::transaction(function () use ($vehicleId, $rows, $actorId, &$saved, &$cleared) {
                foreach ($rows as $typeId => $v) {
                    $typeId = (int) $typeId;
                    if ($typeId <= 0) continue;

                    $km   = isset($v['km'])   && (int) $v['km']   > 0 ? (int) $v['km']   : null;
                    $days = isset($v['days']) && (int) $v['days'] > 0 ? (int) $v['days'] : null;

                    if ($km === null && $days === null) {
                        // Blank = "follow the standard". The row goes, so a later change
                        // to the standard reaches this vehicle too.
                        $cleared += DB::table(self::TABLE)
                            ->where('vehicle_id', $vehicleId)
                            ->where('maintenance_type_id', $typeId)->delete();
                        continue;
                    }

                    DB::table(self::TABLE)->updateOrInsert(
                        ['vehicle_id' => $vehicleId, 'maintenance_type_id' => $typeId],
                        ['interval_km' => $km, 'interval_days' => $days,
                         'updated_by' => $actorId, 'updated_at' => now()]
                    );
                    $saved++;
                }
            });
        } catch (\Throwable $e) {
            Log::error('Vehicle service schedule save failed',
                       ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'saved' => 0, 'cleared' => 0,
                    'message' => 'Could not save this vehicle schedule.'];
        }

        $this->bust($vehicleId);

        $bits = [];
        if ($saved)   $bits[] = $saved . ' job' . ($saved === 1 ? '' : 's') . ' on its own schedule';
        if ($cleared) $bits[] = $cleared . ' back to the standard';

        return ['ok' => true, 'saved' => $saved, 'cleared' => $cleared,
                'message' => $bits ? ('Saved — ' . implode(', ', $bits) . '.')
                                   : 'Saved — this vehicle follows the standard for every job.'];
    }

    /**
     * ⭐⭐ "WHO WILL IGNORE THIS?" — the vehicles of one class holding their own value
     *    for one job. Asked BEFORE a class standard changes, so the manager decides
     *    what happens to them instead of finding out later.
     *
     * @return array<int, array{vehicle_id:int, name:string, interval_km:?int, interval_days:?int}>
     */
    public function exceptionsFor(int $typeId, string $class): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table(self::TABLE . ' as s')
                ->join(VehicleService::T_VEHICLE . ' as v', 'v.id', '=', 's.vehicle_id')
                ->where('s.maintenance_type_id', $typeId)
                ->where('v.is_active', 1)
                // ⚠ Company machines only, to match what is TRACKED at all — a personal
                //   bike is not on the company schedule, so it can never be an exception
                //   to one (owner ruling, 10-Sep).
                ->where('v.is_company', 1)
                ->where('v.vtype', MaintenanceTypeModel::normaliseClass($class))
                ->orderByRaw('COALESCE(v.reg_no, v.nickname)')
                ->get(['s.vehicle_id', 's.interval_km', 's.interval_days',
                       'v.reg_no', 'v.nickname'])
                ->map(fn ($r) => [
                    'vehicle_id'    => (int) $r->vehicle_id,
                    'name'          => $r->reg_no ?: ($r->nickname ?: ('Vehicle #' . $r->vehicle_id)),
                    'interval_km'   => $r->interval_km   !== null ? (int) $r->interval_km   : null,
                    'interval_days' => $r->interval_days !== null ? (int) $r->interval_days : null,
                ])->values()->all();
        } catch (\Throwable $e) {
            Log::warning('Vehicle schedule exceptions failed',
                         ['type' => $typeId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * "Put every vehicle on the new standard" — CLEAR the exceptions rather than
     * stamping the new figure onto each.
     *
     * ⭐ Clearing means "follow the standard", so the NEXT change reaches them too;
     *   stamping would silently re-create the same divergence one change later. Same
     *   reasoning as the old fleet-wide default, kept deliberately.
     * ⚠ Scoped to exactly the rows `exceptionsFor` listed — this write is
     *   irreversible and unaudited, and must never exceed what the manager was shown.
     */
    public function clearFor(int $typeId, string $class): int
    {
        if (!$this->available()) return 0;
        try {
            $ids = array_column($this->exceptionsFor($typeId, $class), 'vehicle_id');
            if (!$ids) return 0;
            $n = DB::table(self::TABLE)
                ->where('maintenance_type_id', $typeId)
                ->whereIn('vehicle_id', $ids)->delete();
            foreach ($ids as $vid) $this->bust((int) $vid);
            return $n;
        } catch (\Throwable $e) {
            Log::warning('Vehicle schedule clear failed',
                         ['type' => $typeId, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /** Every vehicle carrying at least one exception — the "who has their own" summary. */
    public function vehiclesWithOverrides(): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table(self::TABLE . ' as s')
                ->join(VehicleService::T_VEHICLE . ' as v', 'v.id', '=', 's.vehicle_id')
                ->where('v.is_active', 1)->where('v.is_company', 1)
                ->groupBy('s.vehicle_id', 'v.reg_no', 'v.nickname', 'v.vtype')
                ->orderByRaw('COALESCE(v.reg_no, v.nickname)')
                ->selectRaw('s.vehicle_id, v.reg_no, v.nickname, v.vtype, COUNT(*) AS n')
                ->get()
                ->map(fn ($r) => [
                    'vehicle_id' => (int) $r->vehicle_id,
                    'name'       => $r->reg_no ?: ($r->nickname ?: ('Vehicle #' . $r->vehicle_id)),
                    'vtype'      => (string) $r->vtype,
                    'jobs'       => (int) $r->n,
                ])->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The derived schedule is memoised per process AND cached across requests — both
     * must die when an override changes, or the panel keeps printing the old number
     * for up to five minutes and the change reads as broken.
     */
    private function bust(int $vehicleId): void
    {
        unset(self::$memo[$vehicleId]);
        try {
            VehicleService::bumpServiceEvidence($vehicleId);
            VehicleService::flushServiceMemo();
        } catch (\Throwable $e) {
            // a stale cache is a display lag, never a failed save
        }
    }

    /** Tests and long-running processes. */
    public static function flush(): void
    {
        self::$memo = [];
        self::$tableExists = null;
    }
}
