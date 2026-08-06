<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use App\Services\LocationService;

/**
 * Van meet-up stops (Aug-2026) — the places riders collect their orders.
 *
 * TWO KINDS, one mechanism:
 *   • PRESET  — a named spot the store keeps ("Frozen Shop turning"). Lives in
 *     `t_ops_company_locations` with `is_handover_point = 1`. May exist as a LABEL
 *     with no pin yet: the owner names the four spots, and they acquire real
 *     coordinates the first time a driver actually stands there.
 *   • AD-HOC  — the driver found a better spot and dropped a pin. Recorded on the
 *     handover row itself (`location_id NULL`, `is_adhoc = 1`) and TRIP-LOCAL by
 *     design; a manager can later promote a good one into a preset.
 *
 * ⚠ THESE ARE NOT OFFICES, even though they share the offices' table. A pinned
 *   stop must never satisfy the "check in at any office" attendance rule, and must
 *   never be assignable as somebody's work location. Both exclusions live in
 *   LocationService::nearestOfficeWithin() and CompanyLocationsController.
 */
class VanStopService
{
    public const T_LOC = 't_ops_company_locations';

    public function available(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = LocationService::hasHandoverPointColumn()
               && Schema::hasTable(VanService::T_HANDOVER);
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    // =================================================================
    // PRESETS
    // =================================================================

    /** The stops the driver picks from. Unpinned ones are included, and say so. */
    public function presets(bool $includeInactive = false): array
    {
        if (!$this->available()) return [];
        try {
            $q = DB::table(self::T_LOC)->where('is_handover_point', 1);
            if (!$includeInactive) $q->where('is_active', 1);

            return $q->orderBy('location_name')
                ->get(['id', 'location_name', 'latitude', 'longitude', 'radius_meters', 'is_active'])
                ->map(fn ($r) => [
                    'id'         => (int) $r->id,
                    'name'       => $r->location_name,
                    'latitude'   => $r->latitude !== null ? (float) $r->latitude : null,
                    'longitude'  => $r->longitude !== null ? (float) $r->longitude : null,
                    'radius_m'   => $r->radius_meters !== null ? (int) $r->radius_meters : null,
                    'has_pin'    => $r->latitude !== null && $r->longitude !== null,
                    'is_active'  => (int) $r->is_active === 1,
                ])->all();
        } catch (\Throwable $e) {
            Log::warning('VanStopService::presets failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create or rename a preset stop. The pin is OPTIONAL — a stop can be a name
     * the team already uses before anyone has recorded where it is.
     */
    public function savePreset(array $data, ?int $id, ?int $actorId = null): array
    {
        if (!$this->available()) return $this->fail('Van stops are not set up yet (SQL batch 14).');

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') return $this->fail('Give the stop a name.');

        $lat = isset($data['latitude'])  && $data['latitude']  !== '' ? (float) $data['latitude']  : null;
        $lng = isset($data['longitude']) && $data['longitude'] !== '' ? (float) $data['longitude'] : null;
        if (($lat === null) !== ($lng === null)) {
            return $this->fail('A pin needs both a latitude and a longitude.');
        }
        if ($lat !== null && !LocationService::isValidCoordinates($lat, $lng)) {
            return $this->fail('That does not look like a valid location.');
        }

        try {
            $clash = DB::table(self::T_LOC)->where('location_name', $name)
                ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
            if ($clash) return $this->fail('There is already a location called "' . $name . '".');

            $row = [
                'location_name'     => $name,
                'is_handover_point' => 1,
                // ⚠ NEVER primary: primary means "the office every rider dispatches
                //   from", and a roadside stop must never become that.
                'is_primary'        => 0,
                'is_active'         => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
                'updated_at'        => now(),
            ];
            if ($lat !== null) {
                $row['latitude']  = $lat;
                $row['longitude'] = $lng;
            }
            if (array_key_exists('radius_m', $data)) {
                $r = (int) ($data['radius_m'] ?? 0);
                $row['radius_meters'] = $r > 0 ? $r : null;
            }

            if ($id) {
                $existing = DB::table(self::T_LOC)->where('id', $id)->first();
                if (!$existing) return $this->fail('That stop no longer exists.');
                if ((int) ($existing->is_handover_point ?? 0) !== 1) {
                    return $this->fail('That location is an office, not a meet-up stop.');
                }
                DB::table(self::T_LOC)->where('id', $id)->update($row);
            } else {
                $row['created_at'] = now();
                $id = (int) DB::table(self::T_LOC)->insertGetId($row);
            }

            Log::info('Van stop saved', ['id' => $id, 'name' => $name, 'by' => $actorId]);
            return ['ok' => true, 'id' => (int) $id, 'message' => 'Stop saved.'];
        } catch (\Throwable $e) {
            Log::error('VanStopService::savePreset failed', ['error' => $e->getMessage()]);
            return $this->fail('Could not save that stop.');
        }
    }

    /**
     * Retire a stop. Never a hard delete: past handover rows reference it, and the
     * history must keep resolving to a name.
     */
    public function retirePreset(int $id, ?int $actorId = null): array
    {
        if (!$this->available()) return $this->fail('Van stops are not set up yet (SQL batch 14).');
        try {
            $row = DB::table(self::T_LOC)->where('id', $id)->where('is_handover_point', 1)->first();
            if (!$row) return $this->fail('That stop no longer exists.');

            DB::table(self::T_LOC)->where('id', $id)->update(['is_active' => 0, 'updated_at' => now()]);
            Log::info('Van stop retired', ['id' => $id, 'by' => $actorId]);
            return ['ok' => true, 'id' => $id, 'message' => 'Stop retired — past trips keep their history.'];
        } catch (\Throwable $e) {
            return $this->fail('Could not retire that stop.');
        }
    }

    /**
     * Turn a one-off spot into a permanent preset (owner ruling Aug-4).
     * The handover row keeps its own coordinate snapshot, so promoting cannot
     * rewrite where that handover actually happened.
     */
    public function promoteAdhoc(int $handoverId, string $name, ?int $actorId = null): array
    {
        if (!$this->available()) return $this->fail('Van stops are not set up yet (SQL batch 14).');
        try {
            $h = DB::table(VanService::T_HANDOVER)->where('id', $handoverId)->first();
            if (!$h) return $this->fail('That stop record no longer exists.');
            if ($h->meet_lat === null || $h->meet_lng === null) {
                return $this->fail('That stop has no pin to save.');
            }

            $res = $this->savePreset([
                'name'      => $name,
                'latitude'  => $h->meet_lat,
                'longitude' => $h->meet_lng,
            ], null, $actorId);
            if (!$res['ok']) return $res;

            // Point the row at its new home — but keep is_adhoc and the snapshot,
            // because on the day it WAS an ad-hoc stop and history should say so.
            DB::table(VanService::T_HANDOVER)->where('id', $handoverId)
                ->update(['location_id' => $res['id'], 'updated_at' => now()]);

            return ['ok' => true, 'id' => $res['id'],
                    'message' => '"' . $name . '" saved — it will appear in the stop list from now on.'];
        } catch (\Throwable $e) {
            Log::error('VanStopService::promoteAdhoc failed', ['error' => $e->getMessage()]);
            return $this->fail('Could not save that spot.');
        }
    }

    // =================================================================
    // THE TRIP'S CURRENT STOP
    // =================================================================

    /**
     * Announce where the van will stop. Either a preset (`location_id`) or an
     * ad-hoc pin (`latitude`/`longitude` + a label).
     *
     * ⭐ A preset with NO pin gets one from this call — the spots converge on
     *   their real coordinates through use, which is exactly the discovery period
     *   the owner described.
     *
     * Any previous open stop on the trip is closed first: the van is at one place
     * at a time, and two "current" stops would make the rider cards ambiguous.
     */
    /**
     * @param bool $impliesDeparture TRUE when the DRIVER is choosing where he is
     *        heading (leaving is implied — there is no separate "van left"
     *        button). FALSE when the STORE names the point for him, which is a
     *        plan and must not pretend the van has left the yard.
     */
    public function setStop(int $driverId, array $data, ?int $tripId = null, bool $impliesDeparture = true): array
    {
        if (!$this->available()) return $this->fail('Van stops are not set up yet (SQL batch 14).');

        $locationId = !empty($data['location_id']) ? (int) $data['location_id'] : null;
        $lat = isset($data['latitude'])  && $data['latitude']  !== '' ? (float) $data['latitude']  : null;
        $lng = isset($data['longitude']) && $data['longitude'] !== '' ? (float) $data['longitude'] : null;
        $label = trim((string) ($data['label'] ?? ''));

        try {
            $preset = null;
            if ($locationId) {
                $preset = DB::table(self::T_LOC)->where('id', $locationId)
                    ->where('is_handover_point', 1)->first();
                if (!$preset) return $this->fail('That stop is not on the list any more.');

                // Fall back to the preset's stored pin when the driver did not send one.
                if ($lat === null && $preset->latitude !== null) {
                    $lat = (float) $preset->latitude;
                    $lng = (float) $preset->longitude;
                }
                if ($label === '') $label = (string) $preset->location_name;
            }

            if ($lat === null || $lng === null) {
                return $this->fail($locationId
                    ? 'This stop has no location saved yet — drop a pin from where you are.'
                    : 'Choose a stop from the list, or drop a pin where you are.');
            }
            if (!LocationService::isValidCoordinates($lat, $lng)) {
                return $this->fail('That does not look like a valid location.');
            }
            if ($label === '') $label = 'Meet-up point';

            $van  = new VanService();
            $trip = $tripId ? DB::table(VanService::T_TRIP)->where('id', $tripId)->first()
                            : $van->ensureTrip($driverId, null, $driverId);

            $newId = null;
            DB::transaction(function () use ($driverId, $trip, $locationId, $lat, $lng, $label, $preset, &$newId) {
                // One current stop at a time.
                DB::table(VanService::T_HANDOVER)
                    ->where('van_user_id', $driverId)->whereNull('completed_at')
                    ->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);

                $newId = (int) DB::table(VanService::T_HANDOVER)->insertGetId([
                    'trip_id'        => $trip->id ?? null,
                    'van_user_id'    => $driverId,
                    'van_vehicle_id' => (new VanService())->vanVehicleIdFor($driverId),
                    'location_id'    => $locationId,
                    'is_adhoc'       => $locationId ? 0 : 1,
                    'label'          => mb_substr($label, 0, 100),
                    // ALWAYS snapshotted, preset or not: renaming or moving a stop
                    // later must never rewrite where a handover actually happened.
                    'meet_lat'       => $lat,
                    'meet_lng'       => $lng,
                    'status'         => 'planned',
                    'set_at'         => now(),
                    'created_by'     => $driverId,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                // A named stop that had no pin now has one.
                if ($preset && $preset->latitude === null) {
                    DB::table(self::T_LOC)->where('id', $preset->id)
                        ->update(['latitude' => $lat, 'longitude' => $lng, 'updated_at' => now()]);
                }
            });

            // The leg follows the decision — the driver never sets both.
            //
            // ⚠ …but ONLY when the van is actually going there now. This used to
            //   stamp `departed_at` unconditionally, so a store pre-setting the
            //   meet-up point while the van was still being loaded (a) made every
            //   board read "heading to the meet-up point" about a parked van,
            //   (b) told the riders the driver was on his way, and (c) SWALLOWED
            //   THE REAL DEPARTURE PING — `startLeg` decides "first leg" from
            //   `departed_at`, so once it was set the announcement never fired.
            if ($trip) {
                $update = ['updated_at' => now()];
                if ($impliesDeparture || !empty($trip->departed_at)) {
                    $update['current_leg'] = VanService::LEG_TO_STOP;
                    $update['departed_at'] = $trip->departed_at ?: now();
                }
                DB::table(VanService::T_TRIP)->where('id', $trip->id)->update($update);
            }

            return ['ok' => true, 'id' => $newId, 'label' => $label,
                    'latitude' => $lat, 'longitude' => $lng,
                    'message' => 'Meeting at ' . $label . '.'];
        } catch (\Throwable $e) {
            Log::error('VanStopService::setStop failed', ['driver' => $driverId, 'error' => $e->getMessage()]);
            return $this->fail('Could not set that stop.');
        }
    }

    /** The driver has arrived — starts the store's waiting timer. */
    public function markReached(int $driverId, ?float $lat = null, ?float $lng = null): array
    {
        if (!$this->available()) return $this->fail('Van stops are not set up yet (SQL batch 14).');
        try {
            $stop = $this->currentStop($driverId);
            if (!$stop) return $this->fail('No meet-up point is set.');
            if ($stop->reached_at) return ['ok' => true, 'message' => 'Already marked as arrived.'];

            DB::table(VanService::T_HANDOVER)->where('id', $stop->id)->update([
                'reached_at'  => now(),
                'reached_lat' => $lat,
                'reached_lng' => $lng,
                'status'      => 'waiting',
                'updated_at'  => now(),
            ]);
            return ['ok' => true, 'id' => (int) $stop->id, 'message' => 'Arrival recorded — the store can see you are waiting.'];
        } catch (\Throwable $e) {
            return $this->fail('Could not record your arrival.');
        }
    }

    /** Done here — closes the stop (the trip continues). */
    public function completeStop(int $driverId): array
    {
        if (!$this->available()) return $this->fail('Van stops are not set up yet (SQL batch 14).');
        try {
            $stop = $this->currentStop($driverId);
            if (!$stop) return ['ok' => true, 'message' => 'No open stop.'];

            DB::table(VanService::T_HANDOVER)->where('id', $stop->id)->update([
                'status' => 'done', 'completed_at' => now(), 'updated_at' => now(),
            ]);
            return ['ok' => true, 'message' => 'Stop closed.'];
        } catch (\Throwable $e) {
            return $this->fail('Could not close that stop.');
        }
    }

    /**
     * ⭐ Close the current stop when a leg change means the van is LEAVING it —
     *    and only then.
     *
     *   done       → the day is over: whatever is open finishes with the trip.
     *   deliveries → close it ONLY if he had REACHED it (he is driving away from
     *                a rendezvous he was waiting at). An UN-reached stop is the
     *                PLAN — "deliver these three, then meet at Do Talwar" — and
     *                starting the deliveries wave is exactly how that plan
     *                begins. The first version of this fix closed it here too,
     *                which wiped the rendezvous the riders had just been told
     *                about and killed the van's chained arrival time on the
     *                store board for the whole wave.
     */
    public function closeOnLegChange(int $driverId, string $leg): void
    {
        try {
            if ($leg === VanService::LEG_DONE) {
                $this->completeStop($driverId);
                return;
            }
            if ($leg === VanService::LEG_DELIVERIES) {
                $cur = $this->currentStop($driverId);
                if ($cur && $cur->reached_at) $this->completeStop($driverId);
            }
        } catch (\Throwable $e) {
            // Closing a stop must never block a leg change.
        }
    }

    /** The van's open stop, or null. */
    public function currentStop(int $driverId)
    {
        if (!$this->available()) return null;
        try {
            return DB::table(VanService::T_HANDOVER)
                ->where('van_user_id', $driverId)
                ->whereNull('completed_at')
                ->orderByDesc('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Shaped for the app: what the rider and the store both need to see. */
    public function currentStopPayload(int $driverId): ?array
    {
        $s = $this->currentStop($driverId);
        if (!$s) return null;

        return [
            'id'          => (int) $s->id,
            'label'       => $s->label ?: 'Meet-up point',
            'is_adhoc'    => (int) $s->is_adhoc === 1,
            'location_id' => $s->location_id ? (int) $s->location_id : null,
            'latitude'    => $s->meet_lat !== null ? (float) $s->meet_lat : null,
            'longitude'   => $s->meet_lng !== null ? (float) $s->meet_lng : null,
            'status'      => (string) $s->status,
            'set_at'      => $s->set_at,
            'reached_at'  => $s->reached_at,
            'waiting_minutes' => $s->reached_at
                ? max(0, (int) round((time() - strtotime((string) $s->reached_at)) / 60))
                : null,
        ];
    }

    /** Today's stops for a trip — the store's history strip. */
    public function tripStops(?int $tripId): array
    {
        if (!$this->available() || !$tripId) return [];
        try {
            return DB::table(VanService::T_HANDOVER)
                ->where('trip_id', $tripId)->orderBy('id')
                ->get(['id', 'label', 'is_adhoc', 'location_id', 'meet_lat', 'meet_lng',
                       'status', 'set_at', 'reached_at', 'completed_at'])
                ->map(fn ($s) => [
                    'id'         => (int) $s->id,
                    'label'      => $s->label ?: 'Meet-up point',
                    'is_adhoc'   => (int) $s->is_adhoc === 1,
                    'can_promote'=> (int) $s->is_adhoc === 1 && !$s->location_id && $s->meet_lat !== null,
                    'status'     => (string) $s->status,
                    'set_at'     => $s->set_at,
                    'reached_at' => $s->reached_at,
                    'completed_at' => $s->completed_at,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function fail(string $m): array
    {
        return ['ok' => false, 'message' => $m, 'id' => null];
    }
}
