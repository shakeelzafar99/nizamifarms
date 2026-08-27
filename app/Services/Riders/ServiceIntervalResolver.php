<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐ "HOW OFTEN IS JOB **T** DUE ON BIKE **V**?" — one answer, every surface
 *    (Aug-27 2026).
 *
 * WHY IT EXISTS
 * The same question was being answered SEVEN different ways: the schedule list, the
 * overdue chip, the alerts, the frozen `service_due_km` record, the early/late flag, the
 * rider-keyed fallback and the Record-service prompt each had their own fallback order.
 * A manager saw "Oil + Tuning every 1,200 km" in one panel and "every 2,000 km" in the
 * prompt on the SAME page, and the permanent audit figure was frozen against a third
 * reading. One question with seven answers is not a display bug; it is seven rules.
 *
 * ⭐⭐ THE ORDER, AND WHY THE TYPE WINS
 *   1. the TYPE's own schedule   (`t_fleet_maintenance_types.interval_km`)
 *   2. the BIKE's fallback       (`t_ops_vehicle.service_interval_km`)
 *   3. the RIDER's legacy one    (`t_ops_rider_profile.service_interval_km`)
 *   4. the company default       (`t_fin_config BIKE_SERVICE_INTERVAL_KM`)
 *   5. `COMPANY_DEFAULT_KM`
 *
 * "Oil + Tuning every 2,000 km" is a fact about that JOB, and it is the number the
 * manager actually typed when he configured the type. A single per-bike scalar cannot
 * express "…except on this bike", because it does not name a job — so it is a FALLBACK
 * for jobs that carry no schedule of their own (Misc / Overhauling), never a rewrite of
 * one that does.
 *
 * ⚠⚠ THE BUG THIS ENDS. `serviceScheduleFor` used to apply the per-bike scalar to
 *    "whichever type is the shortest `resets_service_clock = 1`" — a target it inferred
 *    rather than being told. On 22-Aug a manager set Oil Change to 1,000 and unticked its
 *    clock flag; that silently promoted Oil + Tuning to the target, so AY-4771's 1,200
 *    jumped onto a job whose own schedule says 2,000. Nobody had edited Oil + Tuning.
 *    An override that migrates when an unrelated checkbox changes cannot be reasoned
 *    about by anyone, which is why it is gone.
 *
 * ⭐ Real per-bike-per-job schedules ("this bike does oil every 800 km") need a row per
 *   pair, not a scalar — that is Phase 2 (`t_ops_vehicle_service_schedule`). It slots in
 *   as step 0 here and no consumer has to change again.
 */
class ServiceIntervalResolver
{
    /**
     * The last-resort figure, in ONE place.
     *
     * ⚠ It was `1200` in six files and `3000` in `FleetFuelController` — and the 3,000 was
     *   the one shipped to the browser as the "Company default (N km)" button and the
     *   mobile placeholder. Harmless only while the config row exists; the day it is
     *   deleted the screen offers one number and every calculation uses another.
     */
    public const COMPANY_DEFAULT_KM = 1200;

    /** Inside this many km of the due point, a job reads as "due soon". */
    public const DUE_SOON_KM = 150;

    /**
     * ⭐ THE state rule, in ONE place (Aug-27 2026). `overdue` / `due_soon` / `ok` /
     *   `unknown` was decided by four separate hand-written ternaries (two in
     *   VehicleService, two in FleetFuelService), each carrying its own literal 150.
     *   Alerts fire off this state, so two copies drifting apart would mean a banner
     *   nagging about a job the schedule list calls fine — the exact class of bug the
     *   interval work just closed, one field over.
     */
    public static function stateFor(?int $dueInKm): string
    {
        if ($dueInKm === null) return 'unknown';
        if ($dueInKm < 0) return 'overdue';
        return $dueInKm <= self::DUE_SOON_KM ? 'due_soon' : 'ok';
    }

    private static array $vehicleMemo = [];
    private static array $riderMemo = [];
    private static array $typeMemo = [];
    private static ?int $configMemo = null;

    /**
     * The interval, plus WHERE it came from — so a screen can always say why a number is
     * what it is instead of leaving a manager to guess.
     *
     * @param  ?int $typeIntervalKm  the type's own schedule (null / 0 = "as conditions")
     * @return array{km:int, source:string, type_km:?int, from_type:bool}
     *         source ∈ type | vehicle | rider | company | fallback
     */
    public function explain(?int $vehicleId, ?int $typeIntervalKm, ?int $riderId = null): array
    {
        $typeKm = ($typeIntervalKm !== null && $typeIntervalKm > 0) ? (int) $typeIntervalKm : null;

        if ($typeKm !== null) {
            return ['km' => $typeKm, 'source' => 'type', 'type_km' => $typeKm, 'from_type' => true];
        }

        $v = $this->vehicleInterval($vehicleId);
        if ($v > 0) {
            return ['km' => $v, 'source' => 'vehicle', 'type_km' => null, 'from_type' => false];
        }

        $r = $this->riderInterval($riderId);
        if ($r > 0) {
            return ['km' => $r, 'source' => 'rider', 'type_km' => null, 'from_type' => false];
        }

        $c = $this->companyDefault();
        return ['km' => $c, 'source' => $c === self::COMPANY_DEFAULT_KM && $this->configMissing()
            ? 'fallback' : 'company', 'type_km' => null, 'from_type' => false];
    }

    /** Just the number. */
    public function intervalFor(?int $vehicleId, ?int $typeIntervalKm, ?int $riderId = null): int
    {
        return $this->explain($vehicleId, $typeIntervalKm, $riderId)['km'];
    }

    /** The same answer when the caller has a type ID rather than its interval. */
    public function forTypeId(?int $vehicleId, ?int $typeId, ?int $riderId = null): array
    {
        return $this->explain($vehicleId, $this->typeInterval($typeId), $riderId);
    }

    /** The company-wide default — the ONE reader of that config key. */
    public function companyDefault(): int
    {
        if (self::$configMemo !== null) return self::$configMemo;
        try {
            $v = (int) (DB::table('t_fin_config')
                ->where('config_key', 'BIKE_SERVICE_INTERVAL_KM')->value('config_value') ?: 0);
        } catch (\Throwable $e) {
            $v = 0;
        }
        return self::$configMemo = ($v > 0 ? $v : self::COMPANY_DEFAULT_KM);
    }

    private function configMissing(): bool
    {
        try {
            return !DB::table('t_fin_config')->where('config_key', 'BIKE_SERVICE_INTERVAL_KM')->exists();
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function vehicleInterval(?int $vehicleId): int
    {
        if (!$vehicleId) return 0;
        if (array_key_exists($vehicleId, self::$vehicleMemo)) return self::$vehicleMemo[$vehicleId];
        try {
            $v = (int) (DB::table(VehicleService::T_VEHICLE)
                ->where('id', $vehicleId)->value('service_interval_km') ?: 0);
        } catch (\Throwable $e) {
            $v = 0;
        }
        return self::$vehicleMemo[$vehicleId] = $v;
    }

    private function riderInterval(?int $riderId): int
    {
        if (!$riderId) return 0;
        if (array_key_exists($riderId, self::$riderMemo)) return self::$riderMemo[$riderId];
        try {
            $v = (int) (DB::table('t_ops_rider_profile')
                ->where('user_id', $riderId)->value('service_interval_km') ?: 0);
        } catch (\Throwable $e) {
            $v = 0;
        }
        return self::$riderMemo[$riderId] = $v;
    }

    private function typeInterval(?int $typeId): ?int
    {
        if (!$typeId) return null;
        if (array_key_exists($typeId, self::$typeMemo)) return self::$typeMemo[$typeId];
        try {
            $v = DB::table('t_fleet_maintenance_types')->where('id', $typeId)->value('interval_km');
            $v = ($v !== null && (int) $v > 0) ? (int) $v : null;
        } catch (\Throwable $e) {
            $v = null;
        }
        return self::$typeMemo[$typeId] = $v;
    }

    /** A short line a screen can print beside the number, or null when it needs none. */
    public static function sourceLabel(array $explained): ?string
    {
        switch ($explained['source'] ?? '') {
            case 'vehicle':  return 'this bike\'s own schedule';
            case 'rider':    return 'the rider\'s own schedule';
            case 'company':
            case 'fallback': return 'company default';
            default:         return null;         // straight from the job's own schedule
        }
    }

    /** Tests and long-running processes. */
    public static function flush(): void
    {
        self::$vehicleMemo = [];
        self::$riderMemo = [];
        self::$typeMemo = [];
        self::$configMemo = null;
    }
}
