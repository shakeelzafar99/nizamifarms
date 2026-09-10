<?php

namespace App\Services\Riders;

use App\Models\Riders\MaintenanceTypeModel;
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
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⭐⭐ SEP-2026: PHASE 2 LANDED, AND THE QUESTION GREW A CLASS.
 *
 * `resolveFor()` is now the real entry point and the order is:
 *
 *   0. this VEHICLE's own row for this JOB   (t_ops_vehicle_service_schedule)
 *   1. the JOB's standard FOR THIS CLASS     (bike vs van columns on the type)
 *   2. the bike's legacy scalar   ┐ km only, kept read-only so nobody's old
 *   3. the rider's legacy scalar  │ setting silently disappears. All three
 *   4. the company config key     ┘ writers are retired; these are fallbacks.
 *   5. COMPANY_DEFAULT_KM
 *
 * Two things follow from the class, and they are the whole point:
 *   • a van is judged against van numbers, never a bike's;
 *   • a job with no number for this class has NO countdown and raises NO alert,
 *     instead of quietly borrowing the other class's figure.
 *
 * ⚠⚠ STEPS 2-5 ARE KILOMETRES, so they apply to km-based jobs ONLY. A time-based
 *    job with no days figure is "as conditions" — falling back to a kilometre
 *    number would silently change what the countdown MEANS, which is worse than
 *    having no countdown.
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
     * The same idea for a TIME-based job (owner ruling, 10-Sep-2026: three days).
     * ⚠ Deliberately NOT derived from DUE_SOON_KM — they are different units
     *   answering to different judgement, and tying them together would make one
     *   of the two move whenever the other was tuned.
     */
    public const DUE_SOON_DAYS = 3;

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

    /** The same rule for a time-based job. Same four words, so consumers do not branch. */
    public static function stateForDays(?int $dueInDays): string
    {
        if ($dueInDays === null) return 'unknown';
        if ($dueInDays < 0) return 'overdue';
        return $dueInDays <= self::DUE_SOON_DAYS ? 'due_soon' : 'ok';
    }

    /**
     * ⭐⭐ THE ONE ANSWER, class-aware and basis-aware (Sep-2026).
     *
     * @param  ?int    $vehicleId  the machine, if the registry can name one
     * @param  ?string $class      'bike' | 'van' — normally $vehicle->vtype
     * @param  object|null $type   a maintenance type row (model or stdClass)
     * @param  ?int    $riderId    for the legacy per-rider fallback only
     *
     * @return array{basis:string, km:?int, days:?int, has:bool, source:string,
     *               from_type:bool, label:string, source_label:?string,
     *               standard_km:?int, standard_days:?int}
     */
    public function resolveFor(?int $vehicleId, ?string $class, $type, ?int $riderId = null): array
    {
        $class = MaintenanceTypeModel::normaliseClass($class);

        // The job's own standard for this class — and whether it applies at all.
        $std = $this->standardFor($type, $class);
        $basis = $std['basis'];

        $blank = [
            'basis' => $basis, 'km' => null, 'days' => null, 'has' => false,
            'source' => 'none', 'from_type' => false,
            'label' => MaintenanceTypeModel::intervalLabel($basis, null, null),
            'source_label' => null,
            'standard_km' => $std['km'], 'standard_days' => $std['days'],
        ];

        // Not offered to this kind of machine ⇒ there is nothing to answer.
        if (!$std['applies']) {
            return $blank;
        }

        // ── 0. this vehicle's own row for this job ────────────────────────────
        $typeId = is_object($type) ? (int) ($type->id ?? 0) : 0;
        $ovr = (new VehicleScheduleService())->forVehicleType($vehicleId, $typeId ?: null);
        if ($ovr) {
            if ($basis === MaintenanceTypeModel::BASIS_TIME && $ovr['days'] !== null) {
                return $this->shape($basis, null, $ovr['days'], 'vehicle_job', false, $std);
            }
            if ($basis === MaintenanceTypeModel::BASIS_KM && $ovr['km'] !== null) {
                return $this->shape($basis, $ovr['km'], null, 'vehicle_job', false, $std);
            }
        }

        // ── 1. the job's standard for this class ──────────────────────────────
        if ($basis === MaintenanceTypeModel::BASIS_TIME) {
            // ⚠ No kilometre fallback for a time job — see the class note above.
            return $std['days'] !== null
                ? $this->shape($basis, null, $std['days'], 'type', true, $std)
                : $blank;
        }
        if ($std['km'] !== null) {
            return $this->shape($basis, $std['km'], null, 'type', true, $std);
        }

        // ── 2-5. the legacy kilometre fallbacks, for a job carrying no standard ──
        $legacy = $this->explain($vehicleId, null, $riderId);
        return $this->shape($basis, (int) $legacy['km'], null, $legacy['source'], false, $std);
    }

    /** One shape, built once, so no branch above can forget a key. */
    private function shape(string $basis, ?int $km, ?int $days, string $source,
                           bool $fromType, array $std): array
    {
        $out = [
            'basis'        => $basis,
            'km'           => $km,
            'days'         => $days,
            /**
             * ⚠⚠ "HAS A SCHEDULE" MEANS A REAL ONE — a standard for this class, or this
             *    vehicle's own exception. NOT the legacy kilometre fallback.
             *
             *    Caught by test_record_service_typed §7: with `has` set from "is there a
             *    number", the company-default fallback gave *every* type a countdown, so
             *    "Misc / Overhauling" and "General Repair" — the two jobs that exist
             *    precisely BECAUSE they are done as conditions arise — appeared on the
             *    schedule panel with an invented 1,000 km due point. Worse, the recorder
             *    refuses those same jobs ("no due date to reset"), so the panel would have
             *    shown a countdown nothing on earth could reset. That is the Brake Shoe
             *    bug of Aug-3 turned inside out.
             *
             *    The number is still RETURNED for callers that legitimately want a
             *    last-resort figure (the frozen `service_due_km`); it simply does not
             *    count as the job having a schedule.
             */
            'has'          => in_array($source, ['type', 'vehicle_job'], true)
                                && ($km !== null || $days !== null),
            'source'       => $source,
            'from_type'    => $fromType,
            'label'        => MaintenanceTypeModel::intervalLabel($basis, $km, $days),
            'source_label' => null,
            'standard_km'   => $std['km'],
            'standard_days' => $std['days'],
        ];
        $out['source_label'] = self::sourceLabel($out);
        return $out;
    }

    /**
     * The type's own standard for a class, tolerant of both a model and a raw
     * stdClass from a `DB::table()` select — the fleet screens use the latter for
     * speed, and both must reach the same answer.
     *
     * @return array{applies:bool, basis:string, km:?int, days:?int}
     */
    private function standardFor($type, string $class): array
    {
        if (!$type) {
            return ['applies' => true, 'basis' => MaintenanceTypeModel::BASIS_KM,
                    'km' => null, 'days' => null];
        }
        if ($type instanceof MaintenanceTypeModel) {
            $s = $type->scheduleForClass($class);
            return ['applies' => $type->appliesToClass($class), 'basis' => $s['basis'],
                    'km' => $s['km'], 'days' => $s['days']];
        }

        // Raw row. Missing columns = the migration has not run ⇒ bike numbers for
        // everyone, which is exactly the pre-feature behaviour.
        $applies = $type->applies_to ?? MaintenanceTypeModel::APPLIES_BIKE;
        $basis   = $type->basis ?? MaintenanceTypeModel::BASIS_KM;
        $ok = ($applies === MaintenanceTypeModel::APPLIES_BOTH) || ($applies === $class);

        $pos = fn ($v) => ($v !== null && (int) $v > 0) ? (int) $v : null;
        if ($basis === MaintenanceTypeModel::BASIS_TIME) {
            $d = $class === MaintenanceTypeModel::CLASS_VAN
                ? ($type->interval_days_van ?? null) : ($type->interval_days ?? null);
            return ['applies' => $ok, 'basis' => $basis, 'km' => null, 'days' => $pos($d)];
        }
        $km = $class === MaintenanceTypeModel::CLASS_VAN
            ? ($type->interval_km_van ?? null) : ($type->interval_km ?? null);
        return ['applies' => $ok, 'basis' => MaintenanceTypeModel::BASIS_KM,
                'km' => $pos($km), 'days' => null];
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
            // ⭐ Sep-2026: the real per-vehicle per-job exception. Worth naming
            //   loudly — it is the one number on the screen a person chose by hand.
            case 'vehicle_job': return 'this vehicle\'s own schedule';
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
        VehicleScheduleService::flush();
        MaintenanceTypeService::flushSchemaMemo();
    }
}
