<?php

namespace App\Services\Location;

use App\Services\LocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 📍 ADD A WORKSHOP WITHOUT LEAVING THE BOOKING FORM (owner + team ruling, 6-Sep-2026):
 *    "when assigning a workshop [they] should see the locations and in case no locations
 *     can add locations from both their phones and the web from the same form without
 *     having to leave. It can also be done from the locations where we add for attendance."
 *
 * ⭐⭐ ONE WRITER, THREE DOORS. The fleet modal on the web, the sheet on the phone and the
 *    Locations admin page all end here. A workshop is not an ordinary location — it is the
 *    only kind that can be pinned to a rider's day — so the rules about what makes one
 *    usable are written once rather than in each form.
 *
 * ⚠⚠ COORDINATES ARE MANDATORY, AND THAT IS THE ENTIRE POINT. A workshop exists in this
 *    table for exactly one reason: so a rider checking in AT it is measured against IT
 *    (WorkshopVisitService::applyShiftLocation → getUserShift → calculateDistanceFromBase).
 *    A workshop with no coordinates is a name that pins nothing and silently marks the
 *    rider remote — which is precisely the failure this whole round was built to end.
 *
 * ⚠⚠ A GOOGLE MAPS *PLACE* URL CARRIES NO COORDINATES (Sep-1 trap,
 *    [[google-maps-place-url-no-coords]]). `LocationUrlResolver` says so explicitly with
 *    `needs_review`, and this refuses it by name rather than storing a location that looks
 *    right and cannot work. The phone therefore offers "use my current location" instead —
 *    the manager adding a workshop is usually standing in it.
 */
class CompanyLocationsService
{
    public const TABLE = 't_ops_company_locations';

    /** Sensible default for a workshop: it is a building, not a delivery zone. */
    public const DEFAULT_RADIUS = 300;

    /**
     * @param array $in {location_name, latitude?, longitude?, maps_url?, radius_meters?}
     * @return array{ok: bool, message: string, location?: array}
     */
    public function createWorkshop(array $in, ?int $actorId = null): array
    {
        if (!LocationService::hasWorkshopColumn()) {
            return ['ok' => false, 'message' => 'Workshops are not set up on this install yet '
                . '(workshop_as_location_sep2026.sql has not been run).'];
        }

        $name = trim((string) ($in['location_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'message' => 'Give the workshop a name (up to 100 characters).'];
        }

        $coords = $this->resolveCoords($in);
        if (!empty($coords['error'])) {
            return ['ok' => false, 'message' => $coords['error']];
        }

        $radius = (int) ($in['radius_meters'] ?? self::DEFAULT_RADIUS);
        // Matches the Locations page's own bounds, so the two doors cannot disagree about
        // what is a legal radius.
        if ($radius < 100 || $radius > 10000) $radius = self::DEFAULT_RADIUS;

        try {
            /**
             * ⚠ A name already in use is almost always the same place typed twice by two
             *   managers in two forms. Hand back the existing row instead of creating a
             *   second workshop with the same name — the picker would then show two and
             *   nobody could tell which one pins.
             */
            $dupe = DB::table(self::TABLE)->whereRaw('LOWER(location_name) = ?', [mb_strtolower($name)])->first();
            if ($dupe) {
                $fixed = [];
                // ⭐ If it exists but is not ticked as a workshop, ticking it IS the fix the
                //   manager was reaching for — and is the single most likely reason his
                //   picker was empty (0 locations are ticked on prod today).
                if (empty($dupe->is_workshop)) $fixed['is_workshop'] = 1;
                if (empty($dupe->is_active))   $fixed['is_active']   = 1;
                if ($fixed) {
                    DB::table(self::TABLE)->where('id', $dupe->id)->update($fixed + ['updated_at' => now()]);
                    Log::info('Existing location ticked as a workshop', [
                        'id' => (int) $dupe->id, 'by' => $actorId, 'changed' => array_keys($fixed)]);
                }
                return [
                    'ok'       => true,
                    'location' => $this->shape((int) $dupe->id),
                    'message'  => $fixed
                        ? '"' . $dupe->location_name . '" already existed — it is now marked as a workshop.'
                        : '"' . $dupe->location_name . '" is already a workshop, so it was used as it is.',
                ];
            }

            $id = (int) DB::table(self::TABLE)->insertGetId([
                'location_name'  => $name,
                'latitude'       => $coords['latitude'],
                'longitude'      => $coords['longitude'],
                'radius_meters'  => $radius,
                // ⚠ NEVER primary, NEVER a handover point. A workshop is not anybody's base
                //   (LocationService::isAssignableOffice bars it from the office picker for
                //   exactly this reason) and it is not a van meet-up point either.
                'is_primary'     => 0,
                'is_workshop'    => 1,
                'is_active'      => 1,
                ...(LocationService::hasHandoverPointColumn() ? ['is_handover_point' => 0] : []),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            Log::info('Workshop location created', ['id' => $id, 'name' => $name, 'by' => $actorId]);
            return [
                'ok'       => true,
                'location' => $this->shape($id),
                'message'  => '"' . $name . '" added as a workshop. It can now be pinned to a rider\'s day.',
            ];
        } catch (\Throwable $e) {
            Log::error('createWorkshop failed', ['name' => $name, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not save that workshop.'];
        }
    }

    /**
     * Coordinates from whichever of the three ways the manager had to hand: the phone's own
     * GPS, a typed pair, or a Maps link he pasted on the desk.
     *
     * @return array{latitude?: float, longitude?: float, error?: string}
     */
    private function resolveCoords(array $in): array
    {
        $resolver = new LocationUrlResolver();

        // 1) Explicit numbers — the phone's "use my current location", and typed pairs.
        if (isset($in['latitude'], $in['longitude'])
            && $in['latitude'] !== '' && $in['longitude'] !== '') {
            $ok = $resolver->validateCoords($in['latitude'], $in['longitude']);
            if (!$ok) {
                return ['error' => 'Those coordinates are not a real place — check the latitude and longitude.'];
            }
            return ['latitude' => $ok['latitude'], 'longitude' => $ok['longitude']];
        }

        // 2) A pasted Maps link, or "33.6867, 73.0331" typed into the same box.
        $text = trim((string) ($in['maps_url'] ?? ''));
        if ($text !== '') {
            $res = $resolver->resolveFromText($text);
            if ($res && empty($res['needs_review'])) {
                return ['latitude' => $res['latitude'], 'longitude' => $res['longitude']];
            }
            if ($res && !empty($res['needs_review'])) {
                /**
                 * ⚠⚠ THE SEP-1 TRAP, refused by name. A "place" link (maps.app.goo.gl/… or a
                 *    /place/ URL) names a business and carries NO numbers, so accepting it
                 *    would create a workshop that can never pin anybody.
                 */
                return ['error' => 'That Google Maps link points at a named place and carries no '
                    . 'coordinates, so it cannot be used for check-in. Open it in Maps, press and '
                    . 'hold the exact spot to drop a pin, and paste THAT link — or type the '
                    . 'latitude and longitude. On the phone, just use "Use my current location".'];
            }
            return ['error' => 'No coordinates could be read from that. Paste a Google Maps link with '
                . 'a dropped pin, or type the latitude and longitude.'];
        }

        return ['error' => 'A workshop needs coordinates, otherwise a rider checking in there is '
            . 'still measured against his normal place and marked remote. Use your current location, '
            . 'paste a Maps pin link, or type the latitude and longitude.'];
    }

    /** The row as every picker wants it. */
    private function shape(int $id): array
    {
        $r = DB::table(self::TABLE)->where('id', $id)->first();
        return [
            'id'        => (int) $id,
            'name'      => $r->location_name ?? '',
            'latitude'  => isset($r->latitude) ? (float) $r->latitude : null,
            'longitude' => isset($r->longitude) ? (float) $r->longitude : null,
            'radius'    => isset($r->radius_meters) ? (int) $r->radius_meters : null,
        ];
    }
}
