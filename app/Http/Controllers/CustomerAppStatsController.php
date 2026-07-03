<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Customer App Stats (Jul-2026) — aggregates the structured request lines the
 * LogCustomerAppRequest middleware writes to the `customer_app` daily channel
 * (storage/logs/customer_app-YYYY-MM-DD.log).
 *
 * Purpose: OBSERVE real customer-app traffic (requests/day, busiest minute,
 * per-endpoint mix, error rate, unique customers) so the owner can size a real
 * rate limit from data. Until then the route group carries a deliberately
 * generous 600 req/min backstop.
 *
 * Read-only: parses log files on demand; no DB, no writes.
 */
class CustomerAppStatsController extends Controller
{
    /** Hard cap on parsed lines per page view — protects the shared host. */
    private const MAX_LINES = 500000;

    public function index(Request $request)
    {
        $days = (int) $request->input('days', 7);
        $days = max(1, min(30, $days));

        $totals = [
            'requests' => 0,
            'errors' => 0,       // status >= 400
            'sumDuration' => 0,
            'durationCount' => 0,
            'maxDuration' => 0,
        ];
        $perDay = [];        // 'Y-m-d' => ['count','errors','sumDur','durCnt','peakMinute']
        $perRoute = [];      // route pattern => ['count','errors','sumDur','durCnt','maxDur']
        $perMinute = [];     // 'Y-m-d H:i' => count (for the overall peak)
        $statusCounts = [];  // status code => count
        $uniqueIds = [];     // identifier => true (capped)
        $recentErrors = [];  // rolling last 25 lines with status >= 400
        $parsedLines = 0;
        $truncated = false;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $file = storage_path("logs/customer_app-{$date}.log");
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $handle = fopen($file, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                if (++$parsedLines > self::MAX_LINES) {
                    $truncated = true;
                    break 2;
                }

                // "[2026-07-03 10:00:00] production.INFO: req {json}" (may end " []")
                if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}):\d{2}\]\s+\S+:\s+req\s+(\{.*)$/', $line, $m)) {
                    continue;
                }

                $json = rtrim($m[3]);
                if (str_ends_with($json, ' []')) {
                    $json = substr($json, 0, -3);
                }
                $ctx = json_decode($json, true);
                if (!is_array($ctx)) {
                    continue;
                }

                $lineDate = $m[1];
                $minuteKey = $m[1] . ' ' . $m[2];
                $status = (int) ($ctx['s'] ?? 0);
                $duration = isset($ctx['d']) && is_numeric($ctx['d']) ? (int) $ctx['d'] : null;
                $route = (string) ($ctx['r'] ?? 'unknown');
                $isError = $status >= 400;

                // Totals
                $totals['requests']++;
                if ($isError) {
                    $totals['errors']++;
                }
                if ($duration !== null) {
                    $totals['sumDuration'] += $duration;
                    $totals['durationCount']++;
                    $totals['maxDuration'] = max($totals['maxDuration'], $duration);
                }

                // Per-day
                if (!isset($perDay[$lineDate])) {
                    $perDay[$lineDate] = ['count' => 0, 'errors' => 0, 'sumDur' => 0, 'durCnt' => 0];
                }
                $perDay[$lineDate]['count']++;
                if ($isError) {
                    $perDay[$lineDate]['errors']++;
                }
                if ($duration !== null) {
                    $perDay[$lineDate]['sumDur'] += $duration;
                    $perDay[$lineDate]['durCnt']++;
                }

                // Per-minute (overall peak)
                $perMinute[$minuteKey] = ($perMinute[$minuteKey] ?? 0) + 1;

                // Per-route
                if (!isset($perRoute[$route])) {
                    $perRoute[$route] = ['count' => 0, 'errors' => 0, 'sumDur' => 0, 'durCnt' => 0, 'maxDur' => 0];
                }
                $perRoute[$route]['count']++;
                if ($isError) {
                    $perRoute[$route]['errors']++;
                }
                if ($duration !== null) {
                    $perRoute[$route]['sumDur'] += $duration;
                    $perRoute[$route]['durCnt']++;
                    $perRoute[$route]['maxDur'] = max($perRoute[$route]['maxDur'], $duration);
                }

                // Unique identifiers (order numbers / mobiles) — capped
                if (!empty($ctx['id']) && count($uniqueIds) < 100000) {
                    $uniqueIds[(string) $ctx['id']] = true;
                }

                // Rolling recent-errors buffer
                if ($isError) {
                    $recentErrors[] = [
                        'at' => $minuteKey,
                        'route' => $route,
                        'id' => $ctx['id'] ?? null,
                        'status' => $status,
                        'ip' => $ctx['ip'] ?? null,
                    ];
                    if (count($recentErrors) > 25) {
                        array_shift($recentErrors);
                    }
                }

                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }

            fclose($handle);
        }

        // Peak minute (overall) + per-day busiest minute
        $peakMinute = ['minute' => null, 'count' => 0];
        $perDayPeak = []; // 'Y-m-d' => count
        foreach ($perMinute as $minute => $count) {
            if ($count > $peakMinute['count']) {
                $peakMinute = ['minute' => $minute, 'count' => $count];
            }
            $d = substr($minute, 0, 10);
            if ($count > ($perDayPeak[$d] ?? 0)) {
                $perDayPeak[$d] = $count;
            }
        }

        ksort($perDay);
        uasort($perRoute, fn ($a, $b) => $b['count'] <=> $a['count']);
        ksort($statusCounts);

        // Simple data-driven suggestion once there is real traffic: 10x the
        // observed peak minute, rounded up to the nearest 60, floor 120.
        $suggestedLimit = $peakMinute['count'] > 0
            ? max(120, (int) (ceil(($peakMinute['count'] * 10) / 60) * 60))
            : null;

        return view('customer-app-stats.index', [
            'days' => $days,
            'totals' => $totals,
            'perDay' => $perDay,
            'perDayPeak' => $perDayPeak,
            'perRoute' => $perRoute,
            'statusCounts' => $statusCounts,
            'uniqueIdCount' => count($uniqueIds),
            'peakMinute' => $peakMinute,
            'recentErrors' => array_reverse($recentErrors),
            'truncated' => $truncated,
            'suggestedLimit' => $suggestedLimit,
            'currentBackstop' => 600,
        ]);
    }
}
