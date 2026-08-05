<?php

namespace App\Services\Riders;

use App\Models\Riders\MaintenanceTypeModel;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the maintenance-type list is allowed to decide, in one place.
 *
 * Aug-2026. The manager wanted regular maintenance split into named categories
 * he can edit himself (Oil Change 1,200 km / Oil + Tuning 2,500 km / Brake Shoe
 * 10,000 km / Chain Set / Misc), with repair types he adds as he goes.
 *
 * ⭐ The type is a LABEL on top of the existing machine flag, never a replacement
 * for it — see MaintenanceTypeModel. This service is the only thing that turns a
 * chosen type into `service_type`, and the only thing that builds the picker.
 *
 * ⚠ Guarded on Schema::hasTable throughout: the web files may be uploaded before
 * batch 12 is run, and that must degrade to today's plain Regular/Repair picker
 * rather than 500 every bike form.
 */
class MaintenanceTypeService
{
    private ?bool $tableExists = null;

    public function available(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = Schema::hasTable('t_fleet_maintenance_types');
            } catch (\Throwable $e) {
                $this->tableExists = false;
            }
        }
        return $this->tableExists;
    }

    /**
     * The picker list — active types, repair last, in the manager's own order.
     * Shape is deliberately flat and small: it rides along on payloads the bike
     * screens already fetch rather than needing an endpoint of its own (the same
     * lesson as pay_sources — a separate endpoint would have its own permission
     * gate and lock out the very users who need it).
     *
     * @return array<int, array<string, mixed>>
     */
    public function options(bool $includeInactive = false): array
    {
        if (!$this->available()) {
            return [];
        }
        try {
            $q = MaintenanceTypeModel::query();
            if (!$includeInactive) {
                $q->where('is_active', 1);
            }
            return $q->orderByRaw("CASE WHEN bucket = 'regular' THEN 0 ELSE 1 END")
                ->orderBy('sort_order')->orderBy('type_name')
                ->get()
                ->map(fn (MaintenanceTypeModel $t) => [
                    'id'                   => $t->id,
                    'name'                 => $t->type_name,
                    'bucket'               => $t->bucket,
                    'service_type'         => $t->service_type,
                    'interval_km'          => $t->interval_km,
                    'due_label'            => $t->due_label,
                    'resets_service_clock' => (bool) $t->resets_service_clock,
                    'is_active'            => (bool) $t->is_active,
                    'sort_order'           => $t->sort_order,
                ])->all();
        } catch (\Throwable $e) {
            \Log::warning('Maintenance type options failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** One type by id, active or not (history must still resolve a retired type). */
    public function find($id): ?MaintenanceTypeModel
    {
        if (!$this->available() || !$id) {
            return null;
        }
        try {
            return MaintenanceTypeModel::find((int) $id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Turn what a form submitted into the pair actually stored.
     *
     * The type id is authoritative when present — the server derives service_type
     * from its bucket and IGNORES whatever service_type the client sent, so a
     * stale or hand-crafted payload cannot pair "Oil Change" with `repair` and
     * quietly dodge the meter requirement.
     *
     * With no type id it falls through to the raw service_type: that is every
     * installed APK, and the web forms until this ships.
     *
     * @return array{0: ?string, 1: ?int}  [service_type, maintenance_type_id]
     */
    public function resolve($typeId, ?string $rawServiceType): array
    {
        $type = $this->find($typeId);
        if ($type) {
            return [$type->service_type, $type->id];
        }

        $legacy = in_array($rawServiceType, ['oil_change', 'repair', 'general'], true)
            ? $rawServiceType
            : null;

        return [$legacy, null];
    }

    /**
     * Does an approved claim of this type reset the bike's service-due clock?
     * Untyped rows keep the pre-Aug-2026 answer: any oil change resets it.
     */
    public function resetsClock($typeId, ?string $serviceType): bool
    {
        $type = $this->find($typeId);
        if ($type) {
            return (bool) $type->resets_service_clock;
        }
        return in_array($serviceType, ['oil_change', 'general'], true);
    }

    /**
     * What to show on a claim row. Falls back to the bucket label so the 108
     * existing untyped Maintenance rows read exactly as they do today.
     */
    public function labelFor($typeId, ?string $serviceType): ?string
    {
        $type = $this->find($typeId);
        return $type ? $type->type_name : MaintenanceTypeModel::bucketLabel($serviceType);
    }

    /**
     * Names for a set of type ids in one query — for list payloads that would
     * otherwise call find() per row.
     *
     * @return array<int, string>
     */
    public function namesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$this->available() || !$ids) {
            return [];
        }
        try {
            return MaintenanceTypeModel::whereIn('id', $ids)
                ->pluck('type_name', 'id')->map(fn ($v) => (string) $v)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
