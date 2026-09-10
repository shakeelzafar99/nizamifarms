<?php

namespace App\Services\Riders;

use App\Services\Location\PlusCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🏠 THE RIDER HOME PIN — ONE ENGINE (Sep-2026).
 *
 * Where a rider takes the company vehicle at night. It is what the overnight and
 * morning meter checks measure against (`HomeJourneyService`), so a wrong or missing
 * pin quietly removes a machine's overnight accountability.
 *
 * ⭐⭐ WHY THIS CLASS EXISTS. Until now the ONLY writer was an inline block inside
 *    `RiderProfileController::store()`. The owner asked for the same pin to be
 *    readable and editable from the Bikes tab on the web AND from Bikes on the phone.
 *    Three copies of "parse a Google link, decide if it moved, stamp who and when"
 *    is exactly how two surfaces start disagreeing about one number, so every
 *    surface now calls THIS class and nothing else touches `home_latitude`.
 *
 * ⭐ THE PIN IS NO LONGER TIED TO `company_bike` (owner ruling, 10-Sep-2026).
 *    Riders switch between a company machine and their own all the time. The old
 *    code wrote `$hasPin = $isBike && …`, so unticking the bike SILENTLY WIPED the
 *    home location and the pin had to be re-entered when the bike came back. Storage
 *    and use are now separate concerns:
 *      • STORAGE (here)               — always kept, whatever he is riding today.
 *      • USE (`HomeJourneyService`)   — still gated on actually holding a company
 *                                       vehicle that day, via the vehicle registry.
 *    So unticking a bike stops the home journey running; it does not destroy the data.
 *
 * ⚠ BLANK IS NOT "DELETE". Clearing the coordinate boxes means "leave it alone".
 *   Removal is an explicit, separate call (`clear()`). With the pin now visible on
 *   three surfaces, an empty field is far more likely to be an untouched form than a
 *   deliberate erasure.
 *
 * ⚠ WHO/WHEN IS STAMPED ONLY ON A REAL MOVE. Re-saving an unchanged profile must not
 *   refresh `home_set_at` — the date is a manager's only clue that a pin is stale, and
 *   it used to lie (see the 31-Aug incident in `RiderProfileController`).
 */
class RiderHomePinService
{
    /** Two pins within ~5 cm of each other are the same pin, not a move. */
    private const SAME_PIN_EPSILON = 0.0000005;

    /** Per-rider geofence bounds — the same 30–2000 m the /riders form has always validated. */
    public const RADIUS_MIN_M = 30;
    public const RADIUS_MAX_M = 2000;

    /** Nizami Farms Office — the fallback reference for recovering a SHORT Plus Code. */
    private const OFFICE_LAT = 33.70811597;
    private const OFFICE_LNG = 73.08868750;

    /** The audit table is added by the Sep-11 SQL; every write guards on it. */
    private static ?bool $hasLog = null;

    /** The pin columns are added by the Jul-2026 home-journey SQL. */
    private static ?bool $hasPinColumns = null;

    public function pinColumnsExist(): bool
    {
        if (self::$hasPinColumns === null) {
            try {
                self::$hasPinColumns = Schema::hasColumn('t_ops_rider_profile', 'home_latitude');
            } catch (\Throwable $e) {
                self::$hasPinColumns = false;
            }
        }
        return self::$hasPinColumns;
    }

    private function logTableExists(): bool
    {
        if (self::$hasLog === null) {
            try {
                self::$hasLog = Schema::hasTable('t_ops_rider_home_log');
            } catch (\Throwable $e) {
                self::$hasLog = false;
            }
        }
        return self::$hasLog;
    }

    /**
     * The reference a SHORT Plus Code is recovered against — the primary company
     * location. Every rider lives well inside the ±55 km window in which recovery is
     * unambiguous. Falls back to the office constant, because a missing row must not
     * turn a readable link into an unreadable one.
     */
    private function pinReference(): array
    {
        try {
            $loc = DB::table('t_ops_company_locations')
                ->where('is_primary', 1)->where('is_active', 1)
                ->first(['latitude', 'longitude']);
            if ($loc && $loc->latitude !== null && $loc->longitude !== null) {
                return ['lat' => (float) $loc->latitude, 'lng' => (float) $loc->longitude];
            }
        } catch (\Throwable $e) {
            // fall through to the constant
        }
        return ['lat' => self::OFFICE_LAT, 'lng' => self::OFFICE_LNG];
    }

    // ---------------------------------------------------------------- reading

    /**
     * The stored pin for one rider, with WHO set it and WHEN — the accountability the
     * owner asked for, resolved to a name here so every surface prints the same thing.
     *
     * @return array{lat:?float,lng:?float,radius_m:?int,set_by:?int,set_by_name:?string,
     *               set_at:?string,has_pin:bool,maps_url:?string,effective_radius_m:int}
     */
    public function get(int $userId): array
    {
        $empty = [
            'lat' => null, 'lng' => null, 'radius_m' => null,
            'set_by' => null, 'set_by_name' => null, 'set_at' => null,
            'has_pin' => false, 'maps_url' => null,
            'effective_radius_m' => $this->defaultRadius(),
        ];
        if (!$this->pinColumnsExist()) {
            return $empty;
        }

        $p = DB::table('t_ops_rider_profile')->where('user_id', $userId)
            ->first(['home_latitude', 'home_longitude', 'home_radius_m', 'home_set_by', 'home_set_at']);
        if (!$p || $p->home_latitude === null || $p->home_longitude === null) {
            return $empty;
        }

        $name = null;
        if ($p->home_set_by !== null) {
            try {
                $name = DB::table('t_sys_user')->where('id', $p->home_set_by)->value('fullname');
            } catch (\Throwable $e) {
                $name = null;
            }
        }

        $lat = (float) $p->home_latitude;
        $lng = (float) $p->home_longitude;

        return [
            'lat' => $lat,
            'lng' => $lng,
            'radius_m' => $p->home_radius_m !== null ? (int) $p->home_radius_m : null,
            'set_by' => $p->home_set_by !== null ? (int) $p->home_set_by : null,
            'set_by_name' => $name,
            'set_at' => $p->home_set_at,
            'has_pin' => true,
            'maps_url' => $this->mapsUrl($lat, $lng),
            'effective_radius_m' => ($p->home_radius_m && (int) $p->home_radius_m > 0)
                ? (int) $p->home_radius_m
                : $this->defaultRadius(),
        ];
    }

    /** A link a manager can tap to SEE the stored pin before he trusts it. */
    public function mapsUrl(float $lat, float $lng): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='
            . rawurlencode(number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', ''));
    }

    /** Global fallback radius (owner: 300 m is right). */
    public function defaultRadius(): int
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', 'HOME_RADIUS_M')->value('config_value');
            if ($v !== null && (int) $v > 0) {
                return (int) $v;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 300;
    }

    /**
     * Who changed this rider's home, newest first — the full trail, not just the last
     * writer. Returns [] when the audit table has not been created yet.
     */
    public function history(int $userId, int $limit = 20): array
    {
        if (!$this->logTableExists()) {
            return [];
        }
        try {
            return DB::table('t_ops_rider_home_log as l')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.changed_by')
                ->where('l.user_id', $userId)
                ->orderByDesc('l.id')
                ->limit(max(1, min(100, $limit)))
                ->get([
                    'l.id', 'l.action', 'l.old_latitude', 'l.old_longitude',
                    'l.new_latitude', 'l.new_longitude', 'l.resolved_via',
                    'l.source', 'l.changed_by', 'l.created_at', 'u.fullname as changed_by_name',
                ])
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'action' => $r->action,
                    'old' => $r->old_latitude !== null
                        ? ['lat' => (float) $r->old_latitude, 'lng' => (float) $r->old_longitude] : null,
                    'new' => $r->new_latitude !== null
                        ? ['lat' => (float) $r->new_latitude, 'lng' => (float) $r->new_longitude] : null,
                    'resolved_via' => $r->resolved_via,
                    'source' => $r->source,
                    'by' => $r->changed_by_name ?: ('user ' . $r->changed_by),
                    'at' => $r->created_at,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ---------------------------------------------------------------- resolving

    /**
     * ⭐ THE PARSER, IN ONE PLACE. Turns whatever a manager pasted or typed into
     * coordinates. Accepts, in order of preference:
     *
     *   1. a Google Maps link — short (`maps.app.goo.gl/…`) or full, resolved and
     *      then read by the app-wide parser (7 URL patterns);
     *   2. a PLUS CODE anywhere in that link or pasted on its own (`P35Q+5FF`) —
     *      Google's share sheet increasingly hands out place-ID URLs that carry no
     *      coordinates at all, and the Plus Code in the path is the only thing left
     *      to read (31-Aug-2026 incident);
     *   3. bare coordinates pasted into the same box (`33.6402495, 73.1109461`);
     *   4. coordinates typed into separate lat/lng fields.
     *
     * ⚠ Never throws and never guesses. A link it cannot read comes back
     *   `ok => false` with a human reason, and the CALLER must then leave the stored
     *   pin alone — losing a good pin to a bad paste is the exact bug this replaced.
     *
     * @return array{ok:bool,lat:?float,lng:?float,via:?string,error:?string,resolved_url:?string}
     */
    public function resolve(?string $text, $lat = null, $lng = null): array
    {
        $fail = fn (string $msg) => [
            'ok' => false, 'lat' => null, 'lng' => null,
            'via' => null, 'error' => $msg, 'resolved_url' => null,
        ];

        $text = trim((string) $text);

        if ($text !== '') {
            // --- 1/2. It looks like a URL: resolve it, then parse, then Plus Code.
            if (preg_match('~^https?://~i', $text) || stripos($text, 'goo.gl') !== false
                || stripos($text, 'google.com/maps') !== false) {
                $resolved = $text;
                $coords = null;
                try {
                    $api = app(\App\Http\Controllers\API\RiderController::class);
                    $resolved = $api->resolveGoogleMapsUrl($text);
                    $coords = $api->parseCoordinatesFromGoogleMapsUrl($resolved);
                } catch (\Throwable $e) {
                    $coords = null;
                }

                if ($coords) {
                    return $this->validated((float) $coords['latitude'], (float) $coords['longitude'], 'link', $resolved);
                }

                // PLUS CODE FALLBACK — try the RESOLVED url first, then the original.
                $hit = $this->plusCodeFrom($resolved) ?? $this->plusCodeFrom($text);
                if ($hit) {
                    return $this->validated((float) $hit['latitude'], (float) $hit['longitude'], 'pluscode', $resolved);
                }

                return $fail('That Maps link has no coordinates in it. Open the pin in Google Maps, '
                    . 'tap Share → Copy link (or long-press the exact spot to drop a pin first), '
                    . 'or type the coordinates.');
            }

            // --- 3. Bare "lat, lng" (or "lat lng") pasted into the link box.
            if (preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $text, $m)) {
                return $this->validated((float) $m[1], (float) $m[2], 'coords', null);
            }

            // --- 2b. A Plus Code pasted on its own, with no URL around it.
            $hit = $this->plusCodeFrom($text);
            if ($hit) {
                return $this->validated((float) $hit['latitude'], (float) $hit['longitude'], 'pluscode', null);
            }

            return $fail('That does not look like a Google Maps link, a Plus Code or a pair of '
                . 'coordinates, so the home pin was not changed.');
        }

        // --- 4. Typed lat/lng fields.
        $hasLat = $lat !== null && $lat !== '';
        $hasLng = $lng !== null && $lng !== '';
        if ($hasLat && $hasLng) {
            if (!is_numeric($lat) || !is_numeric($lng)) {
                return $fail('The coordinates must be numbers.');
            }
            return $this->validated((float) $lat, (float) $lng, 'coords', null);
        }
        // Exactly one of the two typed: say so, rather than the misleading "nothing entered".
        if ($hasLat || $hasLng) {
            return $fail('Both latitude and longitude are needed, so the home pin was not changed.');
        }

        return $fail('Nothing was entered, so the home pin was not changed.');
    }

    /** Plus Code lookup against the company reference. Never throws. */
    private function plusCodeFrom(?string $text): ?array
    {
        try {
            $ref = $this->pinReference();
            return (new PlusCode())->fromText($text, $ref['lat'], $ref['lng']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ⚠ A coordinate that decodes but lands in the sea (or in the wrong hemisphere,
     *   the classic swapped lat/lng) must not become somebody's home. Range check is
     *   the last gate before the write.
     */
    private function validated(float $lat, float $lng, string $via, ?string $resolvedUrl): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return [
                'ok' => false, 'lat' => null, 'lng' => null, 'via' => null,
                'error' => 'Those coordinates are out of range, so the home pin was not changed.',
                'resolved_url' => $resolvedUrl,
            ];
        }
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
            return [
                'ok' => false, 'lat' => null, 'lng' => null, 'via' => null,
                'error' => 'That link resolved to 0,0 — the middle of the ocean — so the home pin '
                    . 'was not changed.',
                'resolved_url' => $resolvedUrl,
            ];
        }
        return [
            'ok' => true, 'lat' => $lat, 'lng' => $lng,
            'via' => $via, 'error' => null, 'resolved_url' => $resolvedUrl,
        ];
    }

    // ---------------------------------------------------------------- writing

    /**
     * ⭐ THE ONE WRITER. Saves a resolved pin against a rider.
     *
     * `moved` in the result tells the caller whether anything actually changed, so a
     * surface can say "saved" honestly instead of announcing a move that never
     * happened.
     *
     * @param  string $source  'web-riders' | 'web-bikes' | 'mobile-bikes'
     * @return array{ok:bool,moved:bool,pin:array,error:?string}
     */
    public function save(
        int $userId,
        float $lat,
        float $lng,
        ?int $radius,
        int $actorId,
        string $source,
        ?string $rawInput = null,
        string $via = 'coords'
    ): array {
        if (!$this->pinColumnsExist()) {
            return ['ok' => false, 'moved' => false, 'pin' => $this->get($userId),
                    'error' => 'The home-location columns are not on this database yet.'];
        }
        if (!$this->riderExists($userId)) {
            return ['ok' => false, 'moved' => false, 'pin' => $this->get($userId),
                    'error' => 'That rider does not exist.'];
        }

        $prior = DB::table('t_ops_rider_profile')->where('user_id', $userId)
            ->first(['home_latitude', 'home_longitude']);

        $moved = $prior === null
            || $prior->home_latitude === null
            || $prior->home_longitude === null
            || abs((float) $prior->home_latitude - $lat) > self::SAME_PIN_EPSILON
            || abs((float) $prior->home_longitude - $lng) > self::SAME_PIN_EPSILON;

        $data = [
            'home_latitude'  => $lat,
            'home_longitude' => $lng,
            'updated_at'     => now(),
        ];
        // A radius of null means "use the global default" — that is the normal case and
        // must stay writable, so only a positive number overrides it.
        //
        // ⚠⚠ CLAMPED, on every surface. This number is a GEOFENCE the rider must physically
        //    satisfy: the evening arrival stamp and the morning start-meter both ask "is he
        //    within this many metres of the pin?". The /riders form validated 30–2000, but the
        //    Bikes endpoints did not, so a fat-fingered "3" from the phone would have made a
        //    rider's home unreachable — every morning "not at home", every evening no arrival.
        //    Same bounds as the form, so all three surfaces agree; over the cap is capped
        //    rather than refused, because a manager typing 5000 clearly meant "generous".
        $data['home_radius_m'] = ($radius !== null && $radius > 0)
            ? max(self::RADIUS_MIN_M, min(self::RADIUS_MAX_M, $radius))
            : null;

        // ⚠ Only a REAL move re-stamps who/when. See the class docblock.
        if ($moved) {
            $data['home_set_by'] = $actorId;
            $data['home_set_at'] = now();
        }

        DB::table('t_ops_rider_profile')->updateOrInsert(['user_id' => $userId], $data);

        if ($moved) {
            $this->writeLog($userId, 'set', $prior, $lat, $lng, $actorId, $source, $rawInput, $via);
            \Log::info('🏠 Rider home pin set', [
                'user_id' => $userId, 'lat' => $lat, 'lng' => $lng,
                'by' => $actorId, 'source' => $source, 'via' => $via,
            ]);
        }

        return ['ok' => true, 'moved' => $moved, 'pin' => $this->get($userId), 'error' => null];
    }

    /**
     * ⭐ REMOVAL IS EXPLICIT (owner ruling). Nothing else in the system deletes a pin —
     * in particular, unticking "company bike" no longer does.
     *
     * @return array{ok:bool,cleared:bool,pin:array,error:?string}
     */
    public function clear(int $userId, int $actorId, string $source): array
    {
        if (!$this->pinColumnsExist()) {
            return ['ok' => false, 'cleared' => false, 'pin' => $this->get($userId),
                    'error' => 'The home-location columns are not on this database yet.'];
        }

        $prior = DB::table('t_ops_rider_profile')->where('user_id', $userId)
            ->first(['home_latitude', 'home_longitude']);

        if (!$prior || $prior->home_latitude === null) {
            return ['ok' => true, 'cleared' => false, 'pin' => $this->get($userId), 'error' => null];
        }

        DB::table('t_ops_rider_profile')->where('user_id', $userId)->update([
            'home_latitude'  => null,
            'home_longitude' => null,
            'home_radius_m'  => null,
            'home_set_by'    => $actorId,
            'home_set_at'    => now(),
            'updated_at'     => now(),
        ]);

        $this->writeLog($userId, 'clear', $prior, null, null, $actorId, $source, null, 'manual');
        \Log::info('🏠 Rider home pin REMOVED', [
            'user_id' => $userId, 'by' => $actorId, 'source' => $source,
            'was' => [(float) $prior->home_latitude, (float) $prior->home_longitude],
        ]);

        return ['ok' => true, 'cleared' => true, 'pin' => $this->get($userId), 'error' => null];
    }

    /**
     * One convenience door for the surfaces: take raw form input, resolve it, save it.
     * Keeps the "resolve then write, never write a failed resolve" order in ONE place
     * so no caller can get it wrong.
     *
     * @return array{ok:bool,moved:bool,pin:array,error:?string,via:?string}
     */
    public function resolveAndSave(
        int $userId,
        ?string $text,
        $lat,
        $lng,
        ?int $radius,
        int $actorId,
        string $source
    ): array {
        $r = $this->resolve($text, $lat, $lng);
        if (!$r['ok']) {
            return ['ok' => false, 'moved' => false, 'pin' => $this->get($userId),
                    'error' => $r['error'], 'via' => null];
        }
        $saved = $this->save(
            $userId, $r['lat'], $r['lng'], $radius, $actorId, $source,
            trim((string) $text) !== '' ? trim((string) $text) : null,
            $r['via']
        );
        return $saved + ['via' => $r['via']];
    }

    // ---------------------------------------------------------------- internals

    private function riderExists(int $userId): bool
    {
        try {
            return DB::table('t_sys_user')->where('id', $userId)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The audit row. Non-fatal by contract: losing the trail must never lose the pin,
     * and the table may not exist yet on a database where the Sep-11 SQL has not run.
     */
    private function writeLog(
        int $userId,
        string $action,
        $prior,
        ?float $newLat,
        ?float $newLng,
        int $actorId,
        string $source,
        ?string $rawInput,
        string $via
    ): void {
        if (!$this->logTableExists()) {
            return;
        }
        try {
            DB::table('t_ops_rider_home_log')->insert([
                'user_id'       => $userId,
                'action'        => $action,
                'old_latitude'  => $prior->home_latitude ?? null,
                'old_longitude' => $prior->home_longitude ?? null,
                'new_latitude'  => $newLat,
                'new_longitude' => $newLng,
                'raw_input'     => $rawInput !== null ? mb_substr($rawInput, 0, 500) : null,
                'resolved_via'  => $via,
                'source'        => $source,
                'changed_by'    => $actorId,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Home-pin audit row skipped (non-fatal)', ['error' => $e->getMessage()]);
        }
    }
}
