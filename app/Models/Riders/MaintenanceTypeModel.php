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

    protected $fillable = [
        'type_name', 'bucket', 'interval_km', 'resets_service_clock',
        'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'interval_km'          => 'integer',
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

    /** "every 1,200 km" / "as conditions" — one phrasing, used on every screen. */
    public function getDueLabelAttribute(): string
    {
        return $this->interval_km > 0
            ? 'every ' . number_format($this->interval_km) . ' km'
            : 'as conditions';
    }
}
