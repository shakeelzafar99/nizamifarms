<?php

namespace App\Models\Riders;

use App\Models\Shared\BaseModel;

/**
 * A named maintenance type — "Oil Change", "Brake Shoe", "General Repair".
 *
 * ⭐ THE RULE THAT MATTERS: this is a LABEL, never a rule key. The machine flag
 * stays `t_req_master.service_type` (oil_change | repair), derived from this
 * row's `bucket`. Nothing anywhere may branch on `type_name` — renaming
 * "Oil Change" to "Full Service" must change pixels and nothing else. Two rules
 * read the derived flag:
 *   • BikeServiceClock — an approved regular service resets the bike's clock
 *   • FuelClaimRules   — company bike + regular service ⇒ meter reading required
 *
 * `resets_service_clock` exists because the manager's list has several REGULAR
 * types on different schedules (oil 1,200 km, brake shoe 10,000 km). Before this,
 * any oil_change reset the one clock; letting a brake-shoe job do that would make
 * a bike look serviced when its oil is overdue. Only the oil services carry it.
 *
 * ⭐⭐ VEHICLE CLASS (Sep-2026). A job is ONE row carrying a number per class:
 * `interval_km` is the BIKE standard, `interval_km_van` the VAN one, and
 * `applies_to` (bike|van|both) says which classes are offered it at all. A van's
 * Oil Change is the SAME type as a bike's with a different number — never a second
 * "Oil Change (van)" row, because claims, service logs and workshop visits all
 * store `maintenance_type_id`, so a second row would orphan the van's own history
 * and split every report by id.
 *
 * ⭐ `basis` (km|time) chooses which pair is in force: a time-based job counts down
 * from the last service DATE using `interval_days` / `interval_days_van`, for work
 * that ages rather than wears. Asked once at creation; default km.
 *
 * ⚠ Nothing outside this model may reach for a raw interval column — ask
 * `scheduleForClass()`, so "which number applies to this machine" has one answer.
 *
 * Read through MaintenanceTypeService — nothing else should query this table to
 * decide what a picker shows or what a claim means.
 */
class MaintenanceTypeModel extends BaseModel
{
    protected $table = 't_fleet_maintenance_types';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /** The two buckets. These map 1:1 onto the legacy service_type values. */
    public const BUCKET_REGULAR = 'regular';
    public const BUCKET_REPAIR  = 'repair';

    public const BUCKETS = [self::BUCKET_REGULAR, self::BUCKET_REPAIR];

    /**
     * The vehicle classes, matching `t_ops_vehicle.vtype` exactly. The registry has
     * only ever had these two and hard-codes them in three places, so a column pair
     * beats a join table here — a third class is a change everywhere already.
     *
     * ⚠⚠ CLASS IS `vtype`, NEVER `is_company`. They are different axes and mixing
     *    them is a bug this codebase has already shipped once — see the Sep-4 round
     *    where a company BIKE was drawn as a van. `is_company` answers "do we pay
     *    for it", which is what decides whether a machine is TRACKED at all; `vtype`
     *    answers "what is it", which is what decides WHICH schedule it follows.
     */
    public const CLASS_BIKE = 'bike';
    public const CLASS_VAN  = 'van';
    public const CLASSES    = [self::CLASS_BIKE, self::CLASS_VAN];

    /** Which classes may be offered this job. */
    public const APPLIES_BIKE = 'bike';
    public const APPLIES_VAN  = 'van';
    public const APPLIES_BOTH = 'both';
    public const APPLIES      = [self::APPLIES_BIKE, self::APPLIES_VAN, self::APPLIES_BOTH];

    /** What the countdown is measured in. */
    public const BASIS_KM   = 'km';
    public const BASIS_TIME = 'time';
    public const BASES      = [self::BASIS_KM, self::BASIS_TIME];

    /**
     * Months are stored as days so ONE column answers "how long". 30 is the
     * convention in both directions — the editor multiplies by it, `intervalLabel()`
     * divides by it — so "every 6 months" round-trips unchanged.
     */
    public const DAYS_PER_MONTH = 30;

    /** A class name that is safe to index with, whatever arrived. */
    public static function normaliseClass(?string $class): string
    {
        return $class === self::CLASS_VAN ? self::CLASS_VAN : self::CLASS_BIKE;
    }

    protected $fillable = [
        'type_name', 'bucket', 'applies_to', 'basis',
        'interval_km', 'interval_km_van', 'interval_days', 'interval_days_van',
        'resets_service_clock', 'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    /**
     * ⚠ The four interval columns are deliberately NOT cast to integer: `(int) null`
     *   is 0, and 0 is indistinguishable from "as conditions" once it has been cast.
     *   "This job has no van number yet" and "this job is done as conditions arise"
     *   are different answers and the van's whole day-one state depends on telling
     *   them apart. `scheduleForClass()` does the narrowing, once.
     */
    protected $casts = [
        'resets_service_clock' => 'boolean',
        'is_active'            => 'boolean',
        'sort_order'           => 'integer',
    ];

    /**
     * bucket → the legacy machine flag. The ONLY place this mapping exists.
     * 'regular' → 'oil_change' keeps every existing rule, query and installed APK
     * working unchanged; renaming the stored value would have meant touching the
     * service clock, the meter rule, FleetFuelService and 5 filing screens.
     */
    public static function serviceTypeForBucket(?string $bucket): ?string
    {
        return match ($bucket) {
            self::BUCKET_REGULAR => 'oil_change',
            self::BUCKET_REPAIR  => 'repair',
            default              => null,
        };
    }

    /** Human label for a bucket, used wherever a row has no type of its own. */
    public static function bucketLabel(?string $serviceType): ?string
    {
        return match ($serviceType) {
            'oil_change', 'general' => 'Regular service',
            'repair'                => 'Repair',
            default                 => null,
        };
    }

    public function getServiceTypeAttribute(): ?string
    {
        return self::serviceTypeForBucket($this->bucket);
    }

    /** Is this job offered to that kind of machine at all? */
    public function appliesToClass(?string $class): bool
    {
        $class = self::normaliseClass($class);
        $a     = (string) ($this->applies_to ?: self::APPLIES_BIKE);
        return $a === self::APPLIES_BOTH || $a === $class;
    }

    /**
     * ⭐⭐ THE ONE ANSWER TO "what is this job's standard on that kind of machine?".
     *
     * Returns a fixed shape so no caller has to know which of four columns is in
     * force, and so a job with no number for this class reads as "as conditions"
     * (no countdown, no alert) rather than silently borrowing the other class's
     * figure — which is exactly what the van did before this existed.
     *
     * @return array{basis:string, km:?int, days:?int, has:bool, label:string}
     */
    public function scheduleForClass(?string $class): array
    {
        $class = self::normaliseClass($class);
        $basis = (string) ($this->basis ?: self::BASIS_KM);

        if (!$this->appliesToClass($class)) {
            return ['basis' => $basis, 'km' => null, 'days' => null, 'has' => false,
                    'label' => 'not for ' . ($class === self::CLASS_VAN ? 'vans' : 'bikes')];
        }

        if ($basis === self::BASIS_TIME) {
            $d = $class === self::CLASS_VAN ? $this->interval_days_van : $this->interval_days;
            $d = ($d !== null && (int) $d > 0) ? (int) $d : null;
            return ['basis' => self::BASIS_TIME, 'km' => null, 'days' => $d,
                    'has' => $d !== null, 'label' => self::intervalLabel(self::BASIS_TIME, null, $d)];
        }

        $km = $class === self::CLASS_VAN ? $this->interval_km_van : $this->interval_km;
        $km = ($km !== null && (int) $km > 0) ? (int) $km : null;
        return ['basis' => self::BASIS_KM, 'km' => $km, 'days' => null,
                'has' => $km !== null, 'label' => self::intervalLabel(self::BASIS_KM, $km, null)];
    }

    /**
     * "every 1,200 km" / "every 6 months" / "as conditions" — ONE phrasing, used on
     * every screen and emitted by the server so a phone never composes its own.
     */
    public static function intervalLabel(string $basis, ?int $km, ?int $days): string
    {
        if ($basis === self::BASIS_TIME) {
            if (!$days || $days <= 0) return 'as conditions';
            if ($days % self::DAYS_PER_MONTH === 0) {
                $m = intdiv($days, self::DAYS_PER_MONTH);
                return 'every ' . $m . ' month' . ($m === 1 ? '' : 's');
            }
            return 'every ' . number_format($days) . ' day' . ($days === 1 ? '' : 's');
        }
        return ($km && $km > 0) ? 'every ' . number_format($km) . ' km' : 'as conditions';
    }

    /**
     * "every 1,200 km" / "as conditions" for the BIKE standard.
     *
     * ⚠ Kept for the handful of callers with no machine in scope (history rows,
     *   the pre-class fallback). Anything that knows which machine it is talking
     *   about must use `scheduleForClass()` instead, or a van will be labelled with
     *   a bike's number — the bug this whole round exists to end.
     */
    public function getDueLabelAttribute(): string
    {
        return self::intervalLabel(
            (string) ($this->basis ?: self::BASIS_KM),
            $this->interval_km !== null ? (int) $this->interval_km : null,
            $this->interval_days !== null ? (int) $this->interval_days : null
        );
    }
}
