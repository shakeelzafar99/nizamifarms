<?php

namespace App\Services\Location;

use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for turning a customer's location reply into
 * coordinates. Combines every format we've seen in the wild:
 *
 *   - native WhatsApp location pins (handled by the caller, validated here)
 *   - Google Maps short links (goo.gl / maps.app.goo.gl / g.co) — expanded
 *     by following the redirect chain
 *   - long Maps URLs: ?q=/?ll=/?destination=/?daddr=/?saddr=, @lat,lng,
 *     /place/lat,lng, the !3d!4d dropped-pin form, /dir/ and /maps/ paths
 *   - plain text coordinates like "33.6844, 73.0479"
 *
 * Every candidate pair is sanity-checked against real lat/lng ranges so junk
 * data tokens inside Maps URLs never produce a bogus pin.
 */
class LocationUrlResolver
{
    /**
     * Resolve a free-text reply (may contain a URL or plain coordinates).
     *
     * @return array|null  ['latitude'=>float,'longitude'=>float,'source_url'=>?string]
     *                     OR ['needs_review'=>true,'source_url'=>string] when a Maps
     *                     URL was found but no coordinates could be extracted,
     *                     OR null when the text has no location at all.
     */
    public function resolveFromText(?string $text): ?array
    {
        if (empty($text)) {
            return null;
        }
        $text = trim($text);

        $url = $this->extractMapsUrl($text);
        if ($url) {
            $expanded = $this->expandShortLink($url);
            $coords = $this->parseCoordsFromUrl($expanded);
            if ($coords) {
                $coords['source_url'] = $url;
                return $coords;
            }
            // A real Maps link, but it only resolves to a named place / Place ID
            // with no coordinates in the URL — flag for a human to eyeball.
            return ['needs_review' => true, 'source_url' => $url];
        }

        $plain = $this->parsePlainCoords($text);
        if ($plain) {
            $plain['source_url'] = null;
            return $plain;
        }

        return null;
    }

    /** Validate a raw lat/lng pair (e.g. from a native WhatsApp pin). */
    public function validateCoords($lat, $lng): ?array
    {
        return $this->accept($lat, $lng);
    }

    /** Pull the first Google Maps URL out of a block of text. */
    public function extractMapsUrl(?string $body): ?string
    {
        if (empty($body)) {
            return null;
        }
        $re = '~https?://(?:maps\.google\.[a-z.]+|www\.google\.[a-z.]+/maps|google\.[a-z.]+/maps|goo\.gl/maps|maps\.app\.goo\.gl|g\.co/maps|goo\.gl)[^\s)\]"<]*~i';
        if (preg_match($re, $body, $m)) {
            return $m[0];
        }
        return null;
    }

    /**
     * Follow a shortened Maps URL to its final long URL so coordinates become
     * visible. Returns the input unchanged if it isn't short or can't be resolved.
     */
    public function expandShortLink(string $url): string
    {
        $shortDomains = ['goo.gl', 'maps.app.goo.gl', 'g.co'];
        $needsResolve = false;
        foreach ($shortDomains as $d) {
            if (stripos($url, $d) !== false) {
                $needsResolve = true;
                break;
            }
        }
        if (!$needsResolve || !function_exists('curl_init')) {
            return $url;
        }
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; NizamiFarms-LocResolver/1.0)');
            curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 400 && !empty($finalUrl)) {
                return (string) $finalUrl;
            }
        } catch (\Throwable $e) {
            Log::debug('LocationUrlResolver: expandShortLink failed (non-fatal)', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
        }
        return $url;
    }

    /**
     * Extract coordinates from a (preferably already-expanded) Maps URL.
     * Patterns ordered most-precise first.
     */
    public function parseCoordsFromUrl(?string $url): ?array
    {
        if (empty($url)) {
            return null;
        }
        $url = trim((string) $url);

        // ?q= / ?ll= / ?destination= / ?daddr= / ?saddr=
        if (preg_match('/[?&](?:q|ll|destination|daddr|saddr)=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i', $url, $m)) {
            if ($r = $this->accept($m[1], $m[2])) return $r;
        }
        // !3d<lat>!4d<lng> — the exact dropped pin (most accurate on long shares)
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m)) {
            if ($r = $this->accept($m[1], $m[2])) return $r;
        }
        // @lat,lng — map viewport centre
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            if ($r = $this->accept($m[1], $m[2])) return $r;
        }
        // /place/lat,lng
        if (preg_match('/\/place\/(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            if ($r = $this->accept($m[1], $m[2])) return $r;
        }
        // /dir/<lat>,<lng> or /dir//<lat>,<lng>
        if (preg_match('/\/dir\/(?:[^\/]*\/)?(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            if ($r = $this->accept($m[1], $m[2])) return $r;
        }
        // /maps/<lat>,<lng>
        if (preg_match('/\/maps\/(-?\d+\.\d+),(-?\d+\.\d+)(?:[\/,?]|$)/', $url, $m)) {
            if ($r = $this->accept($m[1], $m[2])) return $r;
        }

        return null;
    }

    /** Parse a bare "lat, lng" (or "lat lng") string. */
    public function parsePlainCoords(?string $text): ?array
    {
        if (empty($text)) {
            return null;
        }
        // Require decimals so we don't grab phone numbers / order ids.
        if (preg_match('/(-?\d{1,3}\.\d{3,})\s*[,\s]\s*(-?\d{1,3}\.\d{3,})/', $text, $m)) {
            return $this->accept($m[1], $m[2]);
        }
        return null;
    }

    /** Reject obviously-bad pairs (null island, out of range). */
    private function accept($lat, $lng): ?array
    {
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat == 0.0 && $lng == 0.0) return null;
        if ($lat < -90.0 || $lat > 90.0) return null;
        if ($lng < -180.0 || $lng > 180.0) return null;
        return ['latitude' => $lat, 'longitude' => $lng];
    }
}
