<?php

namespace App\Services\Location;

/**
 * "Where is this customer, and how much should we trust it?"
 *
 * One place decides that question, so dispatch, the labels the team reads, and
 * anything added later can never disagree about it.
 *
 * THREE SOURCES, IN PRIORITY ORDER — this mirrors what the code already did
 * (`latitude ?: geocoded_latitude`); the new part is that the answer now carries
 * how good it is:
 *
 *   1. VERIFIED  `t_crm_prod_customer.latitude/longitude` — a human put it there
 *      (rider at the door, a Maps link, a customer's WhatsApp location). This is
 *      the team's truth and NOTHING here ever writes it. It is load-bearing well
 *      beyond routing: the verified-pin lock, the 150 m mandatory-checkout rule,
 *      delivery-region assignment and the customer app all read it, which is
 *      exactly why a machine guess must never be promoted into it.
 *   2. GEOCODED  `geocoded_latitude/longitude` + `geocode_precision` — Google's
 *      answer for the written address. Good enough to route and time; labelled
 *      so nobody mistakes it for a confirmed doorstep.
 *   3. NOTHING   the stop still exists and still gets delivered. Callers give it
 *      an estimated leg rather than dropping it (see calculateDeliveryEtas).
 *
 * WHY A TIER AND NOT A PERCENTAGE: Google reports a category
 * (ROOFTOP / RANGE_INTERPOLATED / GEOMETRIC_CENTER / APPROXIMATE), not a
 * confidence number. "80% sure" would be invented precision; "street level,
 * house not confirmed" tells the reader what to do about it.
 *
 * Jul-2026 background: a customer with no coordinates was silently dropped from
 * a dispatch, so an un-timed stop sat ahead of timed ones and the board reported
 * "a new order was placed ahead of stops that already have times" — a stop
 * nobody had added. Meanwhile 1,611 customers carried a Nominatim pin that was
 * really the middle of Islamabad or Rawalpindi, timed as if it were an address.
 */
class CustomerLocationResolver
{
    /** A human placed this pin. */
    public const TIER_VERIFIED = 'verified';
    /** Google matched the building / house number. */
    public const TIER_GOOGLE_EXACT = 'google_exact';
    /** Google matched the street, not the house. */
    public const TIER_GOOGLE_STREET = 'google_street';
    /** Sector / society level only — or a legacy row of unknown quality. */
    public const TIER_GOOGLE_AREA = 'google_area';
    /** No coordinates we are willing to route on. */
    public const TIER_NONE = 'none';

    /** Precision values stored in t_crm_prod_customer.geocode_precision. */
    public const PRECISION_EXACT  = 'exact';
    public const PRECISION_STREET = 'street';
    public const PRECISION_AREA   = 'area';

    /**
     * Resolve one customer's usable location.
     *
     * @param object|array|null $row Anything carrying latitude / longitude /
     *   geocoded_latitude / geocoded_longitude / geocode_precision. Works with a
     *   joined query row, an Eloquent customer, or an array — callers select
     *   these columns under many different aliases, so we read them by name and
     *   tolerate absence (a missing geocode_precision reads as legacy).
     *
     * @return array{
     *   tier: string, latitude: ?float, longitude: ?float, routable: bool,
     *   approximate: bool, label: string, short: string
     * }
     */
    public static function resolve($row): array
    {
        $verifiedLat = self::num($row, 'latitude');
        $verifiedLng = self::num($row, 'longitude');

        if ($verifiedLat !== null && $verifiedLng !== null) {
            return self::answer(self::TIER_VERIFIED, $verifiedLat, $verifiedLng);
        }

        $geoLat = self::num($row, 'geocoded_latitude');
        $geoLng = self::num($row, 'geocoded_longitude');

        if ($geoLat !== null && $geoLng !== null) {
            $precision = self::str($row, 'geocode_precision');
            // A legacy row (NULL precision) predates precision tracking, so its
            // quality is unknown — grade it DOWN to area, never up. Those rows
            // came from the old ladder that could silently return a city centre.
            $tier = match ($precision) {
                self::PRECISION_EXACT  => self::TIER_GOOGLE_EXACT,
                self::PRECISION_STREET => self::TIER_GOOGLE_STREET,
                default                => self::TIER_GOOGLE_AREA,
            };
            return self::answer($tier, $geoLat, $geoLng);
        }

        return self::answer(self::TIER_NONE, null, null);
    }

    /**
     * Can we hand this stop to Google as a waypoint and believe the answer?
     * True for a verified pin and for every Google tier — an area-level pin is
     * still the right neighbourhood, which beats pretending the stop is absent.
     */
    public static function isRoutable(string $tier): bool
    {
        return $tier !== self::TIER_NONE;
    }

    /**
     * Should a time derived from this tier be shown with a "≈"? Verified and
     * exact are treated as real times; anything vaguer is a good guess and the
     * screens say so.
     */
    public static function isApproximate(string $tier): bool
    {
        return !in_array($tier, [self::TIER_VERIFIED, self::TIER_GOOGLE_EXACT], true);
    }

    /**
     * Plain-English label for the team. No jargon, no percentages: whoever reads
     * it should immediately know whether to act.
     */
    public static function label(string $tier): string
    {
        return match ($tier) {
            self::TIER_VERIFIED      => 'Verified pin',
            self::TIER_GOOGLE_EXACT  => 'Google location — exact address',
            self::TIER_GOOGLE_STREET => 'Google location — street level (house not confirmed)',
            self::TIER_GOOGLE_AREA   => 'Approximate location — area only, time may be off',
            default                  => 'No location pin',
        };
    }

    /** Short chip text for a crowded order card. */
    public static function shortLabel(string $tier): string
    {
        return match ($tier) {
            self::TIER_VERIFIED      => 'Verified pin',
            self::TIER_GOOGLE_EXACT  => 'Google pin',
            self::TIER_GOOGLE_STREET => 'Google pin — street',
            self::TIER_GOOGLE_AREA   => 'Approximate — area only',
            default                  => 'No location pin',
        };
    }

    /**
     * One sentence explaining what a dispatcher is about to get. Only stops that
     * need attention produce one; a verified or exact stop stays silent, because
     * an alert nobody needs is an alert people learn to ignore.
     */
    public static function dispatchNote(string $tier, ?int $noPinLegMinutes = null): ?string
    {
        switch ($tier) {
            case self::TIER_GOOGLE_STREET:
                return 'Google found the street but not the house — the time is close, the exact door is not confirmed.';
            case self::TIER_GOOGLE_AREA:
                return 'Only the area is known, so the delivery time is a rough guess.';
            case self::TIER_NONE:
                $mins = $noPinLegMinutes ? (int) $noPinLegMinutes : 15;
                return "No location pin, so we allowed about {$mins} minutes to reach this stop. "
                     . 'The rider will follow the written address.';
        }
        return null;
    }

    /**
     * Stable message key for the note above, so the app can show its own wording
     * (Roman Urdu) without parsing English. The server ALWAYS sends the English
     * sentence too: a client that doesn't know a key must fall back to it rather
     * than showing nothing.
     */
    public static function noteKey(string $tier): ?string
    {
        return match ($tier) {
            self::TIER_GOOGLE_STREET => 'note_street',
            self::TIER_GOOGLE_AREA   => 'note_area',
            self::TIER_NONE          => 'note_no_pin_leg',
            default                  => null,
        };
    }

    /** Chip key for the order card, same fallback rule as noteKey(). */
    public static function chipKey(string $tier): string
    {
        return 'chip_' . $tier;
    }

    /** Shape every answer identically so callers never branch on missing keys. */
    private static function answer(string $tier, ?float $lat, ?float $lng): array
    {
        return [
            'tier'        => $tier,
            'latitude'    => $lat,
            'longitude'   => $lng,
            'routable'    => self::isRoutable($tier),
            'approximate' => self::isApproximate($tier),
            'label'       => self::label($tier),
            'short'       => self::shortLabel($tier),
        ];
    }

    /**
     * Read a numeric field, treating 0 / '' / '0.0000000' as absent.
     *
     * Deliberate: a real Nizami Farms delivery is never at latitude 0 or
     * longitude 0 (that is the Atlantic), and the columns are decimals that
     * legacy rows sometimes hold as zero rather than NULL. The old callers
     * relied on PHP truthiness (`$order->latitude ?: ...`) which behaved the
     * same way — keeping that means this swap changes no existing decision.
     */
    private static function num($row, string $key): ?float
    {
        $v = self::raw($row, $key);
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return $f === 0.0 ? null : $f;
    }

    private static function str($row, string $key): ?string
    {
        $v = self::raw($row, $key);
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private static function raw($row, string $key)
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }
        if (is_object($row)) {
            return $row->{$key} ?? null;
        }
        return null;
    }
}
