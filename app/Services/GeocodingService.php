<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GeocodingService
{
    /**
     * Geocode an address with the Google Geocoding API.
     *
     * Returns ['latitude', 'longitude', 'precision'] or null.
     * `precision` is 'exact' | 'street' | 'area' — see classifyPrecision(). Older
     * callers that only read latitude/longitude are unaffected by the extra key.
     *
     * WHY THIS REPLACED NOMINATIM (Jul-2026)
     * The old engine walked a ladder of ever-vaguer variations whose LAST rung was
     * literally "<city>, Pakistan". Whatever came back was saved as the customer's
     * coordinates with no note of how vague it was, so:
     *   - 1,611 customers ended up on three points (1,036 of them on the Islamabad
     *     city centroid) spanning H-13, Sohan, Burma Town, F-11, E-9, Aabpara —
     *     unrelated sectors, all "located" in the middle of town;
     *   - with no country restriction one customer geocoded to Ottawa, Canada, and
     *     a single-stop dispatch was timed at 5,815 minutes (4 days);
     *   - addresses Nominatim simply did not know (named apartment buildings are
     *     its weak spot here) returned nothing, so the stop had no location at all
     *     and dispatch quietly dropped it from the route.
     *
     * Google covers Pakistani addresses far better AND reports how precise each
     * answer is, which is what makes the tiers in CustomerLocationResolver
     * possible. Deliberately ONE call per address: no ladder means no mechanism
     * that can silently degrade a house into a city. Anything vaguer than an area
     * is refused outright rather than stored (see rejection rules below).
     *
     * Same project and key as the Directions calls that already power dispatch.
     *
     * ADDRESS CLEANING (Jul-28-2026)
     * The address is cleaned before it is sent — see cleanAddressForGeocoding()
     * for the rules and the evidence. If the cleaned query finds nothing we retry
     * ONCE with the customer's original wording, so cleaning can only ever rescue
     * an address, never lose one. Every attempt is written to t_crm_geocode_log
     * so the rules can be judged on real traffic rather than on intuition.
     *
     * @param array $context Optional audit context for the log row:
     *   customer_id / order_id / trigger / user_id. Callers that pass nothing
     *   behave exactly as before and simply produce no log row.
     */
    public static function geocodeAddress(string $address, string $city = null, array $context = []): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $cleaned    = self::cleanAddressForGeocoding($address);
        $wasCleaned = ($cleaned !== $address && $cleaned !== '');
        $firstTry   = $wasCleaned ? $cleaned : $address;

        $result = self::attemptGeocode($firstTry, $city, $address, $wasCleaned, false, $context);
        if ($result !== null) {
            return $result;
        }

        // Safety net. Cleaning rewrote the text and that rewrite got us nowhere,
        // so ask again with exactly what the customer wrote. Any row where this
        // second attempt succeeds is a cleaning rule doing harm — the log column
        // `used_original_fallback` exists to make those visible.
        if ($wasCleaned) {
            return self::attemptGeocode($address, $city, $address, true, true, $context);
        }

        return null;
    }

    /**
     * Remove the number-marker noise Google cannot parse, and nothing else.
     *
     * THE EVIDENCE (Jul-28-2026, live Geocoding API, same key and parameters):
     *   "House no# 373, Street no# 25, Sector E11-4, Islamabad"
     *      -> "E 11/4 E-11, Islamabad"  APPROXIMATE, partial_match  => REFUSED
     *   "House 373, Street 25, Sector E11-4, Islamabad"
     *      -> "373 Street 25, NPF E 11/4 E-11, Islamabad"  ROOFTOP  => the house
     * Same address, same key. The only difference is the "no#" tokens: Google
     * cannot read them, so it throws away the house AND street it could not
     * parse, returns the sector centroid, and marks it a partial match — exactly
     * the shape the anti-centroid rule refuses. Removing four characters is the
     * difference between no pin at all and the exact rooftop.
     *
     * WHAT IT DELIBERATELY DOES NOT DO — the risk here is destroying meaning, so
     * the rules are narrow on purpose:
     *   - It never touches landmarks or directions ("near NUST Gate-4",
     *     "opposite the mosque"). That text is what actually finds the door, and
     *     Google uses it; the log shows landmark addresses matching exactly.
     *   - It never reorders, never drops trailing segments, never "corrects"
     *     sector spellings. "Sector E11-4" outperformed "E-11/4" in testing, so
     *     second-guessing the customer's own wording is not a win.
     *   - It only strips a marker sitting between a KNOWN unit word and a DIGIT.
     *     Both anchors are required, which is why ordinary prose survives:
     *     "North Banigala" keeps its "no", "St. Mary's" keeps its "St".
     *
     * The result is used for the Google query ONLY. t_crm_prod_customer.address1
     * is never rewritten — the rider reads that at the door and it keeps every
     * word the customer gave us.
     */
    public static function cleanAddressForGeocoding(string $address): string
    {
        $s = trim($address);
        if ($s === '') {
            return '';
        }

        // Unit words that legitimately precede a number in a Pakistani address.
        $units = 'house|hno|h|street|st|str|flat|plot|shop|office|room|apartment'
               . '|apt|building|bldg|block|lane|gali|makan|villa|suite|unit';

        // "House no# 373" / "H no.373" / "Street no: 25" / "Flat #7" -> "<word> <n>"
        // The (?=\d) lookahead is the safety catch: with no number following, the
        // unit word is left completely alone.
        $s = preg_replace(
            '/\b(' . $units . ')\b[\s.,]*(?:(?:no|number|nr)\b[.:]?\s*)?#?\s*(?=\d)/i',
            '$1 ',
            $s
        ) ?? $s;

        // A bare "#" glued to a number anywhere else ("Plot #12", "# 373"). In an
        // address a hash before a digit is always a number marker.
        $s = preg_replace('/#\s*(?=\d)/', '', $s) ?? $s;

        // Tidy only the punctuation the removals above can leave behind.
        $s = preg_replace('/\s{2,}/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+,/', ',', $s) ?? $s;
        $s = preg_replace('/,\s*(?=,)/', '', $s) ?? $s;
        $s = trim($s, " \t\n\r\0\x0B,");

        // Never hand back an empty string: if the rules somehow ate everything,
        // the original wording is strictly better than nothing.
        return $s === '' ? trim($address) : $s;
    }

    /**
     * One Google call for one query string. Holds the behaviour geocodeAddress()
     * had before cleaning existed; the caller decides which string to try.
     */
    private static function attemptGeocode(
        string $address,
        ?string $city,
        string $originalAddress,
        bool $wasCleaned,
        bool $isFallback,
        array $context
    ): ?array {
        $query = $address;
        // Only append the city when the address does not already name it, so we
        // don't send Google "…, Islamabad, Islamabad".
        if (!empty($city) && stripos($address, trim($city)) === false) {
            $query .= ', ' . trim($city);
        }

        // Audit fields shared by every exit path below.
        $audit = [
            'customer_id'            => $context['customer_id'] ?? null,
            'order_id'               => $context['order_id'] ?? null,
            'trigger_source'         => $context['trigger'] ?? 'unknown',
            'created_by'             => $context['user_id'] ?? null,
            'address_original'       => $originalAddress,
            'address_query'          => $query,
            'was_cleaned'            => $wasCleaned ? 1 : 0,
            'used_original_fallback' => $isFallback ? 1 : 0,
        ];

        // 30-day cache, unchanged in spirit. A previous FAILURE is cached as false
        // for a day so a bad address does not hammer the API on every order save.
        $cacheKey = 'geocode_v2_' . md5(strtolower($query));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            self::logAttempt($audit + [
                'outcome'        => 'cache_hit',
                'precision_tier' => is_array($cached) ? ($cached['precision'] ?? null) : null,
                'latitude'       => is_array($cached) ? ($cached['latitude'] ?? null) : null,
                'longitude'      => is_array($cached) ? ($cached['longitude'] ?? null) : null,
            ], $context);
            return $cached ?: null;
        }

        $key = config('services.google_maps.geocoding_key');
        if (empty($key)) {
            Log::warning('Geocoding skipped - no Google key configured', ['address' => $address]);
            self::logAttempt($audit + ['outcome' => 'no_key'], $context);
            return null;
        }

        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $query,
                // Hard country restriction: the Ottawa result could not happen with this.
                'components' => 'country:PK',
                // Bias (not a restriction) toward the delivery area, which breaks
                // ties between identically-named streets in Islamabad/Rawalpindi.
                'bounds' => self::searchBounds(),
                'region' => 'pk',
                'key' => $key,
            ]);

            if (!$response->successful()) {
                Log::warning('Geocoding HTTP failure', ['address' => $address, 'status' => $response->status()]);
                self::logAttempt($audit + [
                    'outcome'       => 'transport_error',
                    'google_status' => 'HTTP_' . $response->status(),
                ], $context);
                return null; // Transport problem — do NOT cache, let it retry.
            }

            $body   = $response->json();
            $status = $body['status'] ?? 'UNKNOWN';

            if ($status === 'ZERO_RESULTS') {
                Cache::put($cacheKey, false, now()->addDay());
                Log::warning('Geocoding failed - Google found nothing', ['address' => $address, 'city' => $city]);
                self::logAttempt($audit + [
                    'outcome'       => 'zero_results',
                    'google_status' => $status,
                ], $context);
                return null;
            }

            if ($status !== 'OK' || empty($body['results'][0])) {
                // OVER_QUERY_LIMIT / REQUEST_DENIED are configuration or billing
                // problems, not bad addresses — never cached as a failure.
                Log::error('Geocoding rejected by Google', [
                    'address' => $address,
                    'status'  => $status,
                    'error'   => $body['error_message'] ?? null,
                ]);
                self::logAttempt($audit + [
                    'outcome'       => 'api_error',
                    'google_status' => $status,
                ], $context);
                return null;
            }

            $result    = $body['results'][0];
            $lat       = $result['geometry']['location']['lat'] ?? null;
            $lng       = $result['geometry']['location']['lng'] ?? null;
            $precision = self::classifyPrecision($result);

            // Google's raw verdict, recorded on every remaining exit path so a
            // rejection can be re-judged later without paying for the call again.
            $audit += [
                'google_status'   => $status,
                'matched_address' => $result['formatted_address'] ?? null,
                'location_type'   => $result['geometry']['location_type'] ?? null,
                'result_types'    => implode(',', array_slice($result['types'] ?? [], 0, 8)),
                'partial_match'   => !empty($result['partial_match']) ? 1 : 0,
            ];

            if ($lat === null || $lng === null || $precision === null) {
                // City-level or vaguer. Storing this is what created the centroid
                // clusters, so we treat it as "no location": the stop is then
                // honestly flagged and someone is asked for a pin.
                Cache::put($cacheKey, false, now()->addDay());
                Log::warning('Geocoding result too vague to use', [
                    'address'       => $address,
                    'matched'       => $result['formatted_address'] ?? null,
                    'types'         => $result['types'] ?? [],
                    'location_type' => $result['geometry']['location_type'] ?? null,
                    'partial_match' => !empty($result['partial_match']),
                ]);
                self::logAttempt($audit + [
                    'outcome'   => 'rejected_vague',
                    'latitude'  => $lat,
                    'longitude' => $lng,
                ], $context);
                return null;
            }

            // Distance guard: a plausible-looking answer on the wrong side of the
            // country is worse than no answer, because it gets timed as real.
            $km = self::kmFromCentre((float) $lat, (float) $lng);
            $maxKm = (float) self::configValue('GEOCODE_MAX_KM_FROM_OFFICE', 60);
            if ($km !== null && $km > $maxKm) {
                Cache::put($cacheKey, false, now()->addDay());
                Log::warning('Geocoding result rejected - too far from the office', [
                    'address'  => $address,
                    'matched'  => $result['formatted_address'] ?? null,
                    'km_away'  => round($km, 1),
                    'limit_km' => $maxKm,
                ]);
                self::logAttempt($audit + [
                    'outcome'        => 'rejected_too_far',
                    'precision_tier' => $precision,
                    'latitude'       => $lat,
                    'longitude'      => $lng,
                    'km_from_office' => round($km, 2),
                ], $context);
                return null;
            }

            $coords = [
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
                'precision' => $precision,
                // What Google thinks it matched, so a confirm screen can show the
                // person WHICH address they are about to accept. Callers that
                // don't want it simply ignore the key; entries cached before this
                // key existed are read with `?? null`.
                'matched_address' => $result['formatted_address'] ?? null,
            ];

            Cache::put($cacheKey, $coords, now()->addDays(30));

            Log::info('Geocoding successful', [
                'original_address' => $address,
                'matched'          => $result['formatted_address'] ?? null,
                'precision'        => $precision,
                'coords'           => ['latitude' => $coords['latitude'], 'longitude' => $coords['longitude']],
            ]);

            self::logAttempt($audit + [
                'outcome'        => 'accepted',
                'precision_tier' => $precision,
                'latitude'       => $coords['latitude'],
                'longitude'      => $coords['longitude'],
                'km_from_office' => $km !== null ? round($km, 2) : null,
            ], $context);

            return $coords;

        } catch (\Exception $e) {
            Log::error('Geocoding error', ['address' => $address, 'error' => $e->getMessage()]);
            self::logAttempt($audit + [
                'outcome'       => 'api_error',
                'google_status' => 'EXCEPTION',
            ], $context);
            return null;
        }
    }

    /**
     * Write one row to the geocode audit log (t_crm_geocode_log).
     *
     * Two hard rules, both deliberate:
     *   1. NEVER throws. Geocoding is called inside order creation and Shopify
     *      conversion; a logging problem — a missing table on a server where the
     *      SQL has not been run yet, a column length, anything — must not fail an
     *      order. Every failure here is swallowed to the Laravel log instead.
     *   2. Silent when there is no context. Callers that pass no audit context
     *      (the batch tool's inner calls, ad-hoc use) write nothing, so the table
     *      stays a record of real business events rather than a firehose.
     */
    private static function logAttempt(array $row, array $context): void
    {
        if (empty($context)) {
            return;
        }

        try {
            // Column widths are enforced here rather than trusted: an address is
            // free text typed by a customer and a truncation error thrown mid
            // order-save would be a self-inflicted outage.
            foreach ([
                'address_original' => 255, 'address_query'   => 300,
                'matched_address'  => 300, 'result_types'    => 255,
                'google_status'    => 32,  'location_type'   => 32,
                'trigger_source'   => 32,  'outcome'         => 32,
                'precision_tier'   => 16,
            ] as $col => $len) {
                if (!empty($row[$col]) && is_string($row[$col])) {
                    $row[$col] = mb_substr($row[$col], 0, $len);
                }
            }

            \DB::table('t_crm_geocode_log')->insert($row + [
                'google_status'          => null,
                'matched_address'        => null,
                'location_type'          => null,
                'result_types'           => null,
                'partial_match'          => 0,
                'precision_tier'         => null,
                'latitude'               => null,
                'longitude'              => null,
                'km_from_office'         => null,
                'used_original_fallback' => 0,
                'was_cleaned'            => 0,
                'created_at'             => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Geocode audit log write failed (non-fatal)', [
                'error'   => $e->getMessage(),
                'outcome' => $row['outcome'] ?? null,
            ]);
        }
    }

    /**
     * How precise is this Google result? Returns 'exact' | 'street' | 'area', or
     * NULL meaning "too vague to store".
     *
     * Google describes a match two ways and we use both: `location_type` (how the
     * point was derived) and `types` (what kind of thing was matched).
     *
     *   exact  — ROOFTOP, or a premise / street_address / subpremise /
     *            establishment: an actual building or house number.
     *   street — RANGE_INTERPOLATED or a `route`: right street, house inferred.
     *   area   — sector / society / neighbourhood: right neighbourhood, ~1 km.
     *   null   — locality ("Islamabad"), a province, or a country. This is the
     *            exact class of answer that produced 1,611 centroid rows, so it
     *            is refused rather than saved.
     *
     * `partial_match` means Google had to interpret the input loosely; we accept
     * it only when the match is still street-level or better, and downgrade an
     * "exact" partial match to street because the house number is the part most
     * likely to have been guessed.
     */
    private static function classifyPrecision(array $result): ?string
    {
        $types        = array_map('strval', $result['types'] ?? []);
        $locationType = (string) ($result['geometry']['location_type'] ?? '');
        $partial      = !empty($result['partial_match']);

        $has = fn(array $wanted) => count(array_intersect($wanted, $types)) > 0;

        // Vaguer than a neighbourhood: unusable, whatever else it says.
        if ($has(['locality', 'administrative_area_level_1', 'administrative_area_level_2', 'country', 'postal_code'])
            && !$has(['premise', 'subpremise', 'street_address', 'route', 'establishment',
                      'point_of_interest', 'sublocality', 'sublocality_level_1', 'neighborhood'])) {
            return null;
        }

        $precision = null;
        if ($locationType === 'ROOFTOP'
            || $has(['premise', 'subpremise', 'street_address', 'establishment', 'point_of_interest'])) {
            $precision = \App\Services\Location\CustomerLocationResolver::PRECISION_EXACT;
        } elseif ($locationType === 'RANGE_INTERPOLATED' || $has(['route', 'intersection'])) {
            $precision = \App\Services\Location\CustomerLocationResolver::PRECISION_STREET;
        } elseif ($has(['sublocality', 'sublocality_level_1', 'neighborhood', 'colloquial_area'])) {
            $precision = \App\Services\Location\CustomerLocationResolver::PRECISION_AREA;
        } elseif ($locationType === 'GEOMETRIC_CENTER') {
            // Centre of some shape Google matched — treat as area, never exact.
            $precision = \App\Services\Location\CustomerLocationResolver::PRECISION_AREA;
        }

        if ($precision === null) {
            return null;
        }

        if ($partial && $precision === \App\Services\Location\CustomerLocationResolver::PRECISION_EXACT) {
            return \App\Services\Location\CustomerLocationResolver::PRECISION_STREET;
        }
        if ($partial && $precision === \App\Services\Location\CustomerLocationResolver::PRECISION_AREA) {
            return null; // A loose guess at an area is not worth storing.
        }

        return $precision;
    }

    /** Office centre used to bias the search and to sanity-check the answer. */
    private static function centre(): array
    {
        return [
            (float) self::configValue('GEOCODE_CENTER_LAT', 33.70811597),
            (float) self::configValue('GEOCODE_CENTER_LNG', 73.08868750),
        ];
    }

    /** Roughly a 60 km box around the office, as Google's "bounds" bias. */
    private static function searchBounds(): string
    {
        [$lat, $lng] = self::centre();
        $pad = 0.55; // ~60 km in latitude; longitude is narrower at this latitude.
        return ($lat - $pad) . ',' . ($lng - $pad) . '|' . ($lat + $pad) . ',' . ($lng + $pad);
    }

    /** Straight-line km from the office centre, or null if it can't be worked out. */
    private static function kmFromCentre(float $lat, float $lng): ?float
    {
        [$cLat, $cLng] = self::centre();
        if (!$cLat || !$cLng) {
            return null;
        }
        $earthKm = 6371.0;
        $dLat = deg2rad($lat - $cLat);
        $dLng = deg2rad($lng - $cLng);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($cLat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
        return $earthKm * 2 * asin(min(1.0, sqrt($a)));
    }

    /** Read a t_fin_config value with a default (never throws). */
    private static function configValue(string $key, $default)
    {
        try {
            $v = \DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Geocode-on-save fallback (Jul-2026). When a Google Maps URL carries NO
     * extractable coordinates — e.g. a share link that resolves to
     * `maps?q=<ADDRESS TEXT>&ftid=0x..:0x..` (an address + opaque place-ID, no
     * lat/lng anywhere) — the verified pin used to save URL-only with NULL
     * coords, invisible to every distance report and to the pin lock
     * (Ahmed Mujtaba / SH-21269, Jul-19). This pulls the address out of the URL
     * and geocodes it so lat/lng is never silently left empty. Result is
     * APPROXIMATE (address-level), so callers should tell the user to drop an
     * exact pin. Returns ['latitude','longitude'] or null.
     */
    public static function geocodeFromMapsUrl(?string $url, array $context = []): ?array
    {
        if (empty($url)) {
            return null;
        }
        // Pull an address-like query out of the URL (q= / query= / destination= / daddr=).
        // parse_str decodes %XX and '+' → space for us.
        $addr = null;
        $parts = parse_url($url);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            foreach (['q', 'query', 'destination', 'daddr', 'saddr'] as $k) {
                if (!empty($q[$k]) && is_string($q[$k])) {
                    $addr = trim($q[$k]);
                    break;
                }
            }
        }
        if ($addr === null || $addr === '') {
            return null;
        }
        // If the query value is itself a coordinate pair, this isn't our job —
        // the URL coordinate parser already handles that. Never geocode "33.7,73.0".
        if (preg_match('/^-?\d+\.\d+\s*,\s*-?\d+\.\d+$/', $addr)) {
            return null;
        }
        return self::geocodeAddress($addr, null, $context + ['trigger' => 'url_fallback']);
    }

    /**
     * Should we (re)geocode this customer when an order comes in?
     *
     * TRUE when they have no VERIFIED pin AND no Google-sourced geocode. Keyed on
     * the verified pin — not merely "is the geocode slot empty" — for two reasons:
     *
     *  1. A customer with a human pin needs nothing: that pin wins in every
     *     consumer, so geocoding them is wasted work.
     *  2. A customer holding an OLD-ENGINE pin (geocode_provider NULL) is the case
     *     the empty-slot check missed entirely. Those pins came from the Nominatim
     *     ladder that could silently answer with a city centre, so they must be
     *     refreshed rather than trusted forever. This is what makes the fix
     *     self-healing: every customer who still lacks a human pin is upgraded on
     *     their next order, and no historical correction run is needed.
     *
     * @param object $customer needs latitude / longitude / geocoded_latitude /
     *   geocoded_longitude / geocode_provider.
     */
    public static function needsGeocodeRefresh($customer): bool
    {
        if (!$customer) {
            return false;
        }

        // A human pin is the top of the ladder — never second-guess it.
        if (!empty($customer->latitude) && !empty($customer->longitude)) {
            return false;
        }

        $hasCoords = !empty($customer->geocoded_latitude) && !empty($customer->geocoded_longitude);
        $fromGoogle = ($customer->geocode_provider ?? null) === 'google';

        return !($hasCoords && $fromGoogle);
    }

    /**
     * Geocode a customer's address and save it.
     *
     * Writes ONLY geocoded_* columns. The verified pin (latitude/longitude) is the
     * team's own record and is never written by a machine — the whole tier design
     * depends on that separation, because the verified columns are what the pin
     * lock, the 150 m checkout rule and region assignment treat as truth.
     *
     * Also stores HOW GOOD the answer is (geocode_precision) and who produced it
     * (geocode_provider), so dispatch can route on a rooftop match confidently,
     * mark an area-level one as approximate, and refuse to invent a time from a
     * city centre.
     *
     * @param bool $forceUpdate re-geocode even if coordinates already exist —
     *   pass true after the address has been edited.
     * @param array $context audit context for t_crm_geocode_log — pass at least
     *   ['trigger' => '…'] so the log can tell an order-create attempt from a
     *   Shopify conversion from a manual address edit.
     */
    public static function geocodeCustomer(int $customerId, bool $forceUpdate = false, array $context = []): bool
    {
        $customer = \DB::table('t_crm_prod_customer')->find($customerId);

        if (!$customer) {
            return false;
        }

        // Skip if already has geocoded coordinates (unless force update)
        // Note: We check geocoded_latitude, NOT latitude (which is for verified locations)
        if (!$forceUpdate && $customer->geocoded_latitude && $customer->geocoded_longitude) {
            return true; // Already has geocoded coordinates
        }

        // Get address
        $address = $customer->address1 ?? $customer->address ?? null;
        $city = $customer->city ?? null;

        if (empty($address)) {
            return false;
        }

        $coords = self::geocodeAddress($address, $city, $context + [
            'customer_id' => $customerId,
            'trigger'     => 'unknown',
        ]);

        if ($coords) {
            // Success - save coordinates
            \DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->update([
                    'geocoded_latitude' => $coords['latitude'],
                    'geocoded_longitude' => $coords['longitude'],
                    'geocode_precision' => $coords['precision'] ?? null,
                    'geocode_provider' => 'google',
                    'geocoded_at' => now(),
                    'updated_at' => now(),
                ]);

            return true;
        }

        // Failed (or the answer was too vague to trust). Stamp the attempt so
        // reports can tell "never tried" from "tried and could not place it", and
        // clear any stale precision left from an earlier attempt.
        //
        // Note this no longer carries `whereNull('geocoded_at')`: that made the
        // stamp stick to the FIRST attempt only, so the timestamp said the
        // address had not been re-checked in months when it had been. The
        // API-call rate is protected by the failure cache in geocodeAddress(),
        // not by this row.
        \DB::table('t_crm_prod_customer')
            ->where('id', $customerId)
            ->update([
                'geocode_precision' => null,
                'geocoded_at' => now(),
                'updated_at' => now(),
            ]);

        return false;
    }

    /**
     * Batch geocode customers without geocoded coordinates
     * Returns count of successfully geocoded customers
     */
    public static function batchGeocodeCustomers(int $limit = 100): array
    {
        // Find customers without geocoded coordinates (not verified - those are separate)
        $customers = \DB::table('t_crm_prod_customer')
            ->whereNull('geocoded_latitude')
            ->whereNotNull('address1')
            ->where('address1', '!=', '')
            ->limit($limit)
            ->get(['id', 'address1', 'city']);

        $success = 0;
        $failed = 0;
        $processed = [];

        foreach ($customers as $customer) {
            $result = self::geocodeCustomer($customer->id, false, ['trigger' => 'batch']);

            if ($result) {
                $success++;
            } else {
                $failed++;
            }
            
            $processed[] = [
                'id' => $customer->id,
                'address' => $customer->address1,
                'success' => $result,
            ];

            // Be polite to the API in a bulk loop. The old 1.1s was Nominatim's
            // hard 1-request-per-second policy; Google has no such rule, so this
            // is now just throttling rather than a requirement.
            usleep(200000); // 0.2s between requests
        }

        return [
            'total' => count($customers),
            'success' => $success,
            'failed' => $failed,
            'processed' => $processed,
        ];
    }
    
    /**
     * Get the best available coordinates for a customer.
     * Priority: 1) Verified location, 2) Geocoded location.
     *
     * The ladder itself is unchanged; the answer now also carries the confidence
     * tier and a plain-English label, so a caller can say "approximate" instead of
     * presenting a machine guess as a confirmed doorstep. Existing keys
     * (latitude / longitude / source) keep their old values and meaning.
     */
    public static function getCustomerCoordinates(int $customerId): ?array
    {
        $customer = \DB::table('t_crm_prod_customer')
            ->where('id', $customerId)
            ->select('latitude', 'longitude', 'geocoded_latitude', 'geocoded_longitude',
                     'geocode_precision')
            ->first();

        if (!$customer) {
            return null;
        }

        $loc = \App\Services\Location\CustomerLocationResolver::resolve($customer);

        if (!$loc['routable']) {
            return null;
        }

        return [
            'latitude'    => $loc['latitude'],
            'longitude'   => $loc['longitude'],
            'source'      => $loc['tier'] === \App\Services\Location\CustomerLocationResolver::TIER_VERIFIED
                ? 'verified_location'
                : 'geocoded_address',
            'tier'        => $loc['tier'],
            'approximate' => $loc['approximate'],
            'label'       => $loc['label'],
        ];
    }
}

