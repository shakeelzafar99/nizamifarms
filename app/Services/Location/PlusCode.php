<?php

namespace App\Services\Location;

/**
 * ⭐⭐ OPEN LOCATION CODE ("Plus Code") — the coordinates hiding in a Google place link.
 *
 * WHY THIS EXISTS (31 Aug 2026). Shabib tried five times to change Danish's home pin and
 * every attempt was refused. Google's Android share sheet had produced a PLACE-ID url:
 *
 *   /maps/place/Nizami+Farms,+P35Q%2B5FF,+Aabpara,+G-6%2F1…/data=!4m2!3m1!1s0x38df…:0x8415…!18m1!1e1
 *
 * There is no `@lat,lng` in it and no `!3d`/`!4d` pair, so every pattern in both URL parsers
 * (RiderController::parseCoordinatesFromGoogleMapsUrl and LocationUrlResolver::parseCoordsFromUrl)
 * returned null — and the profile save was thrown away with it.
 *
 * ⭐ But the coordinates ARE in that URL: `P35Q%2B5FF` is a Plus Code, and a Plus Code decodes
 *   OFFLINE. No API call, no key, no quota, no network failure mode. It is the cheapest and most
 *   reliable reading of that link we can possibly do.
 *
 * ⚠ A SHORT code ("P35Q+5FF") has its leading characters stripped and only makes sense next to a
 *   reference point — that is what the ", Islamabad" in the URL is doing for a human. We recover
 *   it against a caller-supplied reference (the company office). Verified stable: the office,
 *   Danish's home 8.5 km away, and Rawalpindi Saddar 13 km away all recover P35Q+5FF to the
 *   identical point, because the borrowed prefix covers a 1° cell (~110 km).
 *
 * ⚠⚠ THE LIMIT OF THAT, STATED PLAINLY: four dropped characters buy a 1° cell, so recovery is
 *   only correct while the reference is within about **±0.5° (~55 km)** of the real place.
 *   Tested: the same code recovered against Peshawar (160 km / 2° west) resolves to a DIFFERENT,
 *   equally valid point — that is the spec, not a bug. Consequence for us: pass the company
 *   office as the reference (every rider lives inside ~35 km of it — the furthest legitimate
 *   rider fix ever recorded is 35.6 km), and a short code pasted from another city will decode
 *   to a plausible-looking point near Islamabad. That is why the caller MUST show the manager
 *   the coordinates it stored plus a map link — a silent pin is not good enough here.
 *
 * Spec: https://github.com/google/open-location-code — this is a decode-only implementation
 * (encode exists solely to build the reference prefix that recovery needs).
 */
class PlusCode
{
    /** The OLC digit alphabet. Deliberately excludes vowels and easily-confused glyphs. */
    private const ALPHABET = '23456789CFGHJMPQRVWX';

    /** Characters before the '+' in a full code. */
    private const SEPARATOR_POSITION = 8;

    /** Degrees covered by the first pair. Each later pair divides by 20. */
    private const BASE_RESOLUTION = 20.0;

    /** The final character refines the last pair's cell into a 4 (lat) x 5 (lng) grid. */
    private const GRID_ROWS = 4;
    private const GRID_COLUMNS = 5;

    /**
     * Pull a Plus Code out of a URL (or any text) and decode it.
     *
     * Handles the `%2B` that a Plus Code always carries inside a URL path — Google encodes the
     * '+' because it is a reserved character there, which is exactly why a naive `/(\w+\+\w+)/`
     * over the raw URL finds nothing.
     *
     * @param  string $text            the URL or message body
     * @param  float  $refLat          reference latitude for recovering a SHORT code
     * @param  float  $refLng          reference longitude
     * @return array{latitude:float,longitude:float,plus_code:string}|null
     */
    public function fromText(?string $text, float $refLat, float $refLng): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $haystack = trim((string) $text);

        // ⚠ Search only the PATH of a URL. Google puts '+' in place of spaces there, which is
        //   harmless, but the query string carries base64 blobs (`g_ep=`, `skid=`) that can hold
        //   a '+' surrounded by alphabet characters and would produce a pin in the ocean. The
        //   `/data=` segment is dropped for the same reason.
        if (preg_match('~^https?://~i', $haystack)) {
            $haystack = preg_split('/[?#]/', $haystack)[0];
            $dataAt = stripos($haystack, '/data=');
            if ($dataAt !== false) {
                $haystack = substr($haystack, 0, $dataAt);
            }
        }

        // Decode %2B (a Plus Code's '+' is always percent-encoded in a URL path — which is
        // exactly why a naive search over the raw URL finds nothing), then look for
        // <2-8 code chars> '+' <2-3 code chars>. The alphabet excludes vowels, so this cannot
        // collide with ordinary words, and the '+' is required — a bare "P35Q" is not a code.
        $haystack = str_ireplace('%2b', '+', $haystack);
        $letters  = preg_quote(self::ALPHABET, '/');

        if (!preg_match('/\b([' . $letters . ']{2,8})\+([' . $letters . ']{2,3})\b/i', $haystack, $m)) {
            return null;
        }

        $code = strtoupper($m[1]) . '+' . strtoupper($m[2]);
        $point = $this->decode($code, $refLat, $refLng);

        return $point === null ? null : $point + ['plus_code' => $code];
    }

    /**
     * Decode a full ("8J5MP35Q+5FF") or short ("P35Q+5FF") Plus Code to the CENTRE of the area
     * it names. A short code is recovered against the reference point first.
     *
     * @return array{latitude:float,longitude:float}|null
     */
    public function decode(?string $code, float $refLat, float $refLng): ?array
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || substr_count($code, '+') !== 1) {
            return null;
        }

        $plusAt = strpos($code, '+');
        $digits = str_replace('+', '', $code);
        $after  = strlen($code) - $plusAt - 1;

        // Every character must be a real OLC digit. '0' padding ("8J5M0000+") names an area
        // kilometres across — refuse it rather than drop a pin in the middle of a suburb.
        if (!preg_match('/^[' . preg_quote(self::ALPHABET, '/') . ']+$/', $digits)) {
            return null;
        }

        // ⚠ STRUCTURE IS CHECKED BEFORE RECOVERY, LENGTH ONLY AFTER IT. A short code
        //   ("P35Q+5FF") is 7 digits long; testing it against the 10-11 digit rule up front
        //   rejected every short code — i.e. every code Google actually shares — which is the
        //   whole reason this class exists. Characters are dropped in PAIRS, so the count
        //   before the separator must be even and at most 8; 2-3 after it.
        if ($plusAt < 2 || $plusAt > self::SEPARATOR_POSITION || $plusAt % 2 !== 0) {
            return null;
        }
        if ($after < 2 || $after > 3) {
            return null;
        }

        $dropped = self::SEPARATOR_POSITION - $plusAt;

        if ($dropped > 0) {
            $digits = $this->recoverPrefix($digits, $dropped, $refLat, $refLng);
            if ($digits === null) {
                return null;
            }
        }

        // Now it must be a complete 10- (pair precision, ~14 m) or 11-character
        // (grid-refined, ~3 m) code.
        if (strlen($digits) < 10 || strlen($digits) > 11) {
            return null;
        }

        return $this->decodeFull($digits, $dropped, $refLat, $refLng);
    }

    /** Borrow the missing leading characters from the reference point's own code. */
    private function recoverPrefix(string $digits, int $dropped, float $refLat, float $refLng): ?string
    {
        $refDigits = str_replace('+', '', $this->encode($refLat, $refLng));
        if (strlen($refDigits) < $dropped) {
            return null;
        }
        return substr($refDigits, 0, $dropped) . $digits;
    }

    /**
     * Decode a complete 10- or 11-digit code, then apply the spec's proximity correction:
     * a recovered code names the NEAREST matching cell to the reference, which can sit one
     * cell the other side of a boundary from the prefix we borrowed.
     */
    private function decodeFull(string $digits, int $dropped, float $refLat, float $refLng): ?array
    {
        [$lat, $lng] = $this->decodeToCentre($digits);

        if ($dropped > 0) {
            // Size of the area the borrowed prefix covers, in degrees.
            $resolution = pow(self::BASE_RESOLUTION, 2 - ($dropped / 2));
            $half = $resolution / 2;

            if ($lat - $refLat > $half) {
                $lat -= $resolution;
            } elseif ($refLat - $lat > $half) {
                $lat += $resolution;
            }
            if ($lng - $refLng > $half) {
                $lng -= $resolution;
            } elseif ($refLng - $lng > $half) {
                $lng += $resolution;
            }
        }

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }
        if ($lat == 0.0 && $lng == 0.0) {
            return null;                       // null-island guard, same as both URL parsers
        }

        return ['latitude' => round($lat, 7), 'longitude' => round($lng, 7)];
    }

    /** Pair-by-pair decode of a full code (no separator) to its area centre. */
    private function decodeToCentre(string $digits): array
    {
        $lat = -90.0;
        $lng = -180.0;
        $resolution = self::BASE_RESOLUTION;

        $pairs = min(10, strlen($digits));
        for ($i = 0; $i + 1 < $pairs; $i += 2) {
            $lat += strpos(self::ALPHABET, $digits[$i]) * $resolution;
            $lng += strpos(self::ALPHABET, $digits[$i + 1]) * $resolution;
            $resolution /= self::BASE_RESOLUTION;
        }

        // $resolution is now one twentieth of the last pair's cell; undo that for the cell size.
        $cell = $resolution * self::BASE_RESOLUTION;

        if (strlen($digits) > 10) {
            $g = strpos(self::ALPHABET, $digits[10]);
            $rowHeight = $cell / self::GRID_ROWS;
            $colWidth  = $cell / self::GRID_COLUMNS;
            $lat += intdiv($g, self::GRID_COLUMNS) * $rowHeight;
            $lng += ($g % self::GRID_COLUMNS) * $colWidth;
            return [$lat + $rowHeight / 2, $lng + $colWidth / 2];
        }

        return [$lat + $cell / 2, $lng + $cell / 2];
    }

    /**
     * Encode a point to an 11-character code. Only used to build the prefix that short-code
     * recovery borrows — this class is not a general-purpose encoder.
     */
    public function encode(float $lat, float $lng): string
    {
        $lat = min(89.999999, max(-90.0, $lat));
        $lng = fmod(fmod($lng, 360.0) + 540.0, 360.0) - 180.0;

        $la = $lat + 90.0;
        $lo = $lng + 180.0;
        $resolution = self::BASE_RESOLUTION;
        $code = '';

        for ($i = 0; $i < 5; $i++) {
            $dLa = (int) floor($la / $resolution);
            $dLo = (int) floor($lo / $resolution);
            $code .= self::ALPHABET[$dLa] . self::ALPHABET[$dLo];
            $la -= $dLa * $resolution;
            $lo -= $dLo * $resolution;
            $resolution /= self::BASE_RESOLUTION;
        }

        $cell = $resolution * self::BASE_RESOLUTION;
        $row = (int) floor($la / ($cell / self::GRID_ROWS));
        $col = (int) floor($lo / ($cell / self::GRID_COLUMNS));
        $code .= self::ALPHABET[min(19, $row * self::GRID_COLUMNS + $col)];

        return substr($code, 0, self::SEPARATOR_POSITION) . '+' . substr($code, self::SEPARATOR_POSITION);
    }
}
