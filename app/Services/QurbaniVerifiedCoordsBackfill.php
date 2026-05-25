<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * QurbaniVerifiedCoordsBackfill — May-2026
 *
 * Sweeps t_crm_prod_customer rows where verified_location_url is set
 * but latitude/longitude are NULL, follows short Google Maps links to
 * their long form, parses the !3d/!4d (or @lat,lng / ?q=...) coords
 * out of the resolved URL, and writes lat/lng + the resolved URL back
 * to the customer.
 *
 * Two callers share this service so the behaviour stays in sync:
 *   - The one-off CLI: php artisan qurbani:backfill-verified-coords
 *   - The "Run backfill" button on the Qurbani Settings page (the
 *     button is the only path most users will ever take, since they
 *     don't shell into prod).
 *
 * Each customer is its own transaction — a single network failure can
 * never corrupt the rest of the run. cURL has a hard 8s timeout +
 * 5-redirect cap per row so a flaky short-link server can't hang the
 * whole process.
 */
class QurbaniVerifiedCoordsBackfill
{
    /**
     * Run the backfill.
     *
     * @param int  $limit  0 = no cap (process every candidate). The UI
     *                     button passes a finite cap to keep the HTTP
     *                     request below the PHP timeout.
     * @param bool $dryRun true = report what would happen, write nothing.
     *
     * Returns:
     *   [
     *     'candidates'         => int,   // rows matching the candidate filter
     *     'processed'          => int,   // rows we actually attempted
     *     'fixed'              => int,   // wrote lat/lng successfully
     *     'resolved_no_coords' => int,   // followed redirect but couldn't parse
     *     'network_errors'     => int,   // cURL failures
     *     'long_unparseable'   => int,   // non-short URLs that already fail
     *     'unresolved'         => array, // [{id, name, reason}, ...] (capped to 50)
     *     'dry_run'            => bool,
     *   ]
     */
    public function run(int $limit = 0, bool $dryRun = false): array
    {
        $q = DB::table('t_crm_prod_customer')
            ->whereNotNull('verified_location_url')
            ->where(function ($w) {
                $w->whereNull('latitude')->orWhereNull('longitude')
                  ->orWhere('latitude', 0)->orWhere('longitude', 0);
            })
            ->orderBy('id');

        $candidates = (int) $q->count();

        if ($limit > 0) $q->limit($limit);
        $rows = $q->get(['id', 'first_name', 'last_name', 'verified_location_url']);

        $fixed = 0;
        $resolvedNoCoords = 0;
        $networkError = 0;
        $longUnparseable = 0;
        $unresolved = [];

        foreach ($rows as $r) {
            $orig = trim((string) $r->verified_location_url);
            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));

            if (!$this->looksLikeShortLink($orig)) {
                // Long URL but parser still failed — try one more parse
                // to confirm before flagging as unresolved.
                $coords = $this->parseCoords($orig);
                if ($coords) {
                    if (!$dryRun) $this->writeCoords((int) $r->id, $orig, $coords);
                    $fixed++;
                } else {
                    $unresolved[] = ['id' => (int) $r->id, 'name' => $name, 'reason' => 'long_unparseable'];
                    $longUnparseable++;
                }
                continue;
            }

            $long = $this->resolveShort($orig);
            if ($long === null) {
                $networkError++;
                $unresolved[] = ['id' => (int) $r->id, 'name' => $name, 'reason' => 'network_error'];
                continue;
            }
            $coords = $this->parseCoords($long);
            if (!$coords) {
                $resolvedNoCoords++;
                $unresolved[] = ['id' => (int) $r->id, 'name' => $name, 'reason' => 'resolved_but_unparseable'];
                continue;
            }
            if (!$dryRun) $this->writeCoords((int) $r->id, $long, $coords);
            $fixed++;
        }

        return [
            'candidates'         => $candidates,
            'processed'          => $rows->count(),
            'fixed'              => $fixed,
            'resolved_no_coords' => $resolvedNoCoords,
            'network_errors'     => $networkError,
            'long_unparseable'   => $longUnparseable,
            'unresolved'         => array_slice($unresolved, 0, 50),
            'unresolved_count'   => count($unresolved),
            'dry_run'            => $dryRun,
        ];
    }

    private function looksLikeShortLink(string $url): bool
    {
        $u = strtolower($url);
        return str_contains($u, 'maps.app.goo.gl')
            || str_contains($u, 'goo.gl/maps')
            || str_contains($u, 'g.co/maps');
    }

    private function resolveShort(string $url): ?string
    {
        if (!function_exists('curl_init')) return null;
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; NizamiFarms-CoordsBackfill/1.0)');
            curl_exec($ch);
            $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 400 && !empty($final) && $final !== $url) {
                return (string) $final;
            }
        } catch (\Throwable $e) {
            Log::debug('coords-backfill resolveShort failed', ['url' => $url, 'error' => $e->getMessage()]);
        }
        return null;
    }

    private function parseCoords(string $url): ?array
    {
        $accept = function ($lat, $lng) {
            $lat = (float) $lat; $lng = (float) $lng;
            if ($lat == 0.0 && $lng == 0.0) return null;
            if ($lat < -90.0 || $lat > 90.0) return null;
            if ($lng < -180.0 || $lng > 180.0) return null;
            return ['lat' => $lat, 'lng' => $lng];
        };
        // Prefer !3d/!4d (true dropped pin) over @lat,lng (viewport centre).
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m)) {
            if ($r = $accept($m[1], $m[2])) return $r;
        }
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            if ($r = $accept($m[1], $m[2])) return $r;
        }
        if (preg_match('/[?&](?:q|ll|destination|daddr|saddr)=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i', $url, $m)) {
            if ($r = $accept($m[1], $m[2])) return $r;
        }
        if (preg_match('/\/place\/(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            if ($r = $accept($m[1], $m[2])) return $r;
        }
        return null;
    }

    private function writeCoords(int $customerId, string $longUrl, array $coords): void
    {
        DB::transaction(function () use ($customerId, $longUrl, $coords) {
            DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->update([
                    'verified_location_url' => $longUrl,
                    'latitude'              => $coords['lat'],
                    'longitude'             => $coords['lng'],
                    'updated_at'            => now(),
                ]);
        });
    }
}
