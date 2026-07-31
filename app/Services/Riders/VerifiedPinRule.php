<?php

namespace App\Services\Riders;

/**
 * THE "was this delivery made at the customer's verified pin?" rule — one implementation,
 * every surface.
 *
 * Before this class the same question was answered in five places with three different
 * answers: the store's Delivered Orders screen and the dispatch tracker's rows compared raw
 * distance to 500 m; that tracker's own day-summary counter used 1000 m (so its header could
 * disagree with the ⚠ marks on the rows underneath it); and only Day Review allowed for how
 * good the GPS fix actually was. A manager reading two screens saw two verdicts on one
 * delivery. Every caller now asks this class instead of doing its own arithmetic.
 *
 * The rule — accurate, and deliberately a little forgiving:
 *
 *     at verified  ⟺  distance − slack ≤ at_verified_m     (rider_reports.at_verified_m, 500 m)
 *     slack        =  min(reported accuracy, coarse_fix_m)  (rider_reports.coarse_fix_m, 150 m)
 *
 * A drop can only be placed as precisely as the fix that recorded it, so the fix's own error
 * bar is allowed to reach the radius before we call a rider "away from the pin" — capped at
 * coarse_fix_m so a wildly bad fix can never excuse a genuine miss. When the phone reported no
 * accuracy (older APKs, and every row written before the GPS-accuracy hardening) the slack is 0
 * and the verdict is identical to the old raw comparison — no history is silently re-judged.
 *
 * A coarse fix is REPORTED as coarse (`fix_coarse` + `note`) rather than as the rider having
 * moved: the screens say "the GPS was vague", which is a different accusation from "he was
 * somewhere else".
 *
 * Only the VERIFIED-pin question lives here. Comparisons against a geocoded address (a machine
 * guess of where the customer probably is) are a different question with a different tolerance
 * and stay with their callers.
 */
final class VerifiedPinRule
{
    /** Radius that counts as "at the pin". */
    public static function thresholdM(): float
    {
        return (float) config('rider_reports.at_verified_m', 500);
    }

    /** Ceiling on forgiveness — and the line above which a fix is called coarse. */
    public static function coarseFixM(): float
    {
        return (float) config('rider_reports.coarse_fix_m', 150);
    }

    /** How much of the distance the fix's own error bar is allowed to explain. */
    public static function slackM(?float $accuracyM): float
    {
        if ($accuracyM === null || $accuracyM <= 0) {
            return 0.0;   // nothing reported ⇒ no forgiveness ⇒ old behaviour exactly
        }
        return min($accuracyM, self::coarseFixM());
    }

    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** "620m" / "1.2km" — the format the delivered-orders screens already show. */
    public static function display(float $metres): string
    {
        return $metres < 1000
            ? ((int) round($metres)) . 'm'
            : round($metres / 1000, 2) . 'km';
    }

    /**
     * Judge one delivery against one verified pin.
     *
     * @param  mixed  $dropLat     where the rider stood when he pressed delivered
     * @param  mixed  $pinLat      the customer's verified pin
     * @param  mixed  $accuracyM   accuracy the phone reported with that fix (null = unknown)
     * @return array|null  NULL when it cannot be measured — no delivery GPS, or no pin. Callers
     *                     must treat null as "couldn't measure", never as "away from the pin".
     *   [
     *     'distance_m'       => int,     raw metres between the two points
     *     'slack_m'          => int,     how much of it the fix's error bar explains
     *     'at_verified'      => bool,    THE verdict
     *     'fix_coarse'       => bool,    fix was worse than coarse_fix_m
     *     'accuracy_m'       => ?float,
     *     'distance_display' => string,  "620m" / "1.2km"
     *     'note'             => ?string, plain-language reason, when there is one to give
     *   ]
     */
    public static function judge($dropLat, $dropLng, $pinLat, $pinLng, $accuracyM = null): ?array
    {
        $dLat = self::coord($dropLat);
        $dLng = self::coord($dropLng);
        $pLat = self::coord($pinLat);
        $pLng = self::coord($pinLng);
        if ($dLat === null || $dLng === null || $pLat === null || $pLng === null) {
            return null;
        }

        $acc      = is_numeric($accuracyM) ? (float) $accuracyM : null;
        $distance = self::distanceM($dLat, $dLng, $pLat, $pLng);
        $slack    = self::slackM($acc);
        $limit    = self::thresholdM();
        $atPin    = ($distance - $slack) <= $limit;
        $coarse   = $acc !== null && $acc > self::coarseFixM();

        // Say WHY, but only when the answer isn't obvious from the distance alone.
        $note = null;
        if ($coarse) {
            $note = 'GPS fix was coarse (±' . (int) round($acc) . 'm)';
        } elseif ($atPin && $distance > $limit && $acc !== null) {
            $note = 'within tolerance for a ±' . (int) round($acc) . 'm fix';
        }

        return [
            'distance_m'       => (int) round($distance),
            'slack_m'          => (int) round($slack),
            'at_verified'      => $atPin,
            'fix_coarse'       => $coarse,
            'accuracy_m'       => $acc,
            'distance_display' => self::display($distance),
            'note'             => $note,
        ];
    }

    /**
     * A usable coordinate, or null. Mirrors the !empty() test the call sites already used:
     * 0 / '' / null all mean "not set" (no Nizami Farms delivery happens on the equator, and a
     * 0/0 pin is a data hole, not a location worth measuring against).
     */
    private static function coord($v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return $f === 0.0 ? null : $f;
    }
}
