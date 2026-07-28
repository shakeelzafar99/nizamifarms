<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\RiderDayReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Day Review (Jul-2026) — read API for the riders-map "Day Review" tab, which
 * replaces the old History + Dispatch Tracker + Issues tabs.
 *
 * ACCESS MODEL (deliberately preserves exactly what each tab granted before):
 *   • The BASE payload — who delivered what, dispatched → promised → delivered,
 *     on-time chips, delivered-vs-pin distance — is what the old History and
 *     Dispatch Tracker tabs showed, and they were NOT permission-gated. So the
 *     base stays open to anyone who can open riders-map.
 *   • The FORENSIC extras — GPS route, stops, gaps, pin-crossing and glitch
 *     verdicts — are what the old ⚠Issues tab showed, which WAS gated by
 *     `view_rider_reports`. They stay gated by the same permission.
 * Nobody gains or loses access relative to the tabs being removed.
 *
 * LOOKBACK: the old History tab reached ~3 months, so Day Review must too, or
 * managers lose reach. Forensics simply degrade to honest "trail expired" beyond
 * the ~20-day GPS retention — the day list itself is plain SQL and works for any
 * date in range.
 */
class RiderDayReviewController extends Controller
{
    const FORENSIC_PERMISSION = 'view_rider_reports';
    const CACHE_SECS   = 60;    // matches RiderReportsController — no recompute storms
    const MAX_BACK_DAYS = 120;  // History reached ~3 months; keep that reach

    /** Day list: tiles + one card per rider who worked. */
    public function day(Request $request, RiderDayReviewService $svc)
    {
        try {
            $date = $this->safeDate($request->query('date'));
            if ($date === null) {
                return response()->json(['success' => false, 'message' => 'Invalid date'], 422);
            }

            $range = $this->rangeState($date);
            if ($range['too_old']) {
                return response()->json([
                    'success' => true, 'date' => $date, 'too_old' => true,
                    'max_back_days' => self::MAX_BACK_DAYS,
                    'riders' => [], 'totals' => null,
                ]);
            }

            $data = Cache::remember(
                "day_review_day_{$date}",
                self::CACHE_SECS,
                fn () => $svc->daySummary($date)
            );

            return response()->json(array_merge([
                'success'    => true,
                'date'       => $date,
                'is_today'   => $range['is_today'],
                'can_forensics' => $this->canForensics(),
            ], $range['trail'], $data));
        } catch (\Throwable $e) {
            \Log::error('DayReview day failed', ['error' => $e->getMessage(), 'date' => $request->query('date')]);
            return response()->json(['success' => false, 'message' => 'Failed to load the day'], 500);
        }
    }

    /** One rider's day: orders + verdicts + (permitted) route/stops/gaps. */
    public function rider(Request $request, RiderDayReviewService $svc)
    {
        try {
            $date = $this->safeDate($request->query('date'));
            $rid  = (int) $request->query('rider_id');
            if ($date === null) {
                return response()->json(['success' => false, 'message' => 'Invalid date'], 422);
            }
            if ($rid <= 0) {
                return response()->json(['success' => false, 'message' => 'rider_id required'], 422);
            }

            $range = $this->rangeState($date);
            if ($range['too_old']) {
                return response()->json(['success' => true, 'date' => $date, 'too_old' => true, 'rider' => null]);
            }

            $rider = Cache::remember(
                "day_review_rider_{$rid}_{$date}",
                self::CACHE_SECS,
                fn () => $svc->riderDay($rid, $date)
            );

            if ($rider === null) {
                return response()->json([
                    'success' => true, 'date' => $date, 'rider' => null,
                    'message' => 'Nothing recorded for this rider on this day',
                ]);
            }

            // Strip the forensic layer for users without the Issues permission.
            if (!$this->canForensics()) {
                $rider = $this->stripForensics($rider);
            }

            return response()->json(array_merge([
                'success'  => true,
                'date'     => $date,
                'is_today' => $range['is_today'],
                'can_forensics' => $this->canForensics(),
                'rider'    => $rider,
            ], $range['trail']));
        } catch (\Throwable $e) {
            \Log::error('DayReview rider failed', [
                'error' => $e->getMessage(), 'rider' => $request->query('rider_id'), 'date' => $request->query('date'),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to load this rider'], 500);
        }
    }

    // ---- mobile entries (Sanctum) ------------------------------------
    //
    // Mirrors the mobile access model that already exists for these surfaces:
    // the mobile Dispatch Tracker was gated by `view_dispatch_tracker` and the
    // Issues/Timeline screens by `view_rider_reports` (MOBILE permissions, a
    // separate grant from web). So: base layer needs either of those; the
    // forensic layer needs `view_rider_reports`, enforced by the same
    // stripForensics() the web path uses.

    /** True while serving a mobile (Sanctum) call — flips which grant table canForensics() reads. */
    private bool $mobileContext = false;

    public function apiDay(Request $request, RiderDayReviewService $svc)
    {
        if (!$this->mobileBaseAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $this->mobileContext = true;
        return $this->day($request, $svc);
    }

    public function apiRider(Request $request, RiderDayReviewService $svc)
    {
        if (!$this->mobileBaseAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $this->mobileContext = true;
        return $this->rider($request, $svc);
    }

    private function mobileBaseAllowed(Request $request): bool
    {
        $u = $request->user();
        if (!$u || !method_exists($u, 'hasMobilePermission')) return false;
        return $u->hasMobilePermission('view_dispatch_tracker')
            || $u->hasMobilePermission(self::FORENSIC_PERMISSION);
    }

    // ---- helpers ------------------------------------------------------

    private function canForensics(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        // Web sessions read the WEB grant; mobile calls read the MOBILE grant.
        // They are separate tables on purpose — mixing them would silently widen
        // web access for store staff who only hold the mobile permission.
        if ($this->mobileContext) {
            return method_exists($u, 'hasMobilePermission')
                && $u->hasMobilePermission(self::FORENSIC_PERMISSION);
        }
        return (bool) $u->hasPermission(self::FORENSIC_PERMISSION);
    }

    /** Parse + clamp the requested date. Returns null when unparseable. */
    private function safeDate($raw): ?string
    {
        try {
            $d = $raw ? Carbon::parse($raw) : Carbon::today();
        } catch (\Throwable $e) {
            return null;
        }
        if ($d->gt(Carbon::today())) $d = Carbon::today();   // never the future
        return $d->format('Y-m-d');
    }

    /**
     * How far back the date is, and therefore whether a GPS trail can exist.
     * Retention is a rolling ~20 days (see the GPS notes), so beyond that the
     * honest answer is "the trail is gone", not an empty map.
     */
    private function rangeState(string $date): array
    {
        $daysAgo = Carbon::parse($date)->diffInDays(Carbon::today(), false);
        $retention = (int) config('rider_reports.trail_retention_days', 20);

        return [
            'is_today' => $daysAgo === 0,
            'too_old'  => $daysAgo > self::MAX_BACK_DAYS || $daysAgo < 0,
            'trail'    => [
                'trail_expected' => $daysAgo <= $retention,
                'days_ago'       => $daysAgo,
                'retention_days' => $retention,
            ],
        ];
    }

    /** Remove everything the old Issues tab gated, keeping the History/Dispatch view intact. */
    private function stripForensics(array $rider): array
    {
        $rider['stops'] = [];
        $rider['gaps']  = [];
        $rider['route'] = [];
        $rider['has_trail'] = false;
        $rider['forensics_hidden'] = true;

        foreach ($rider['orders'] as &$o) {
            unset($o['pin_cross'], $o['press_check'], $o['away_verdict'], $o['slice'],
                  $o['press_trail_m'], $o['door_wait_min'], $o['late_reason']);
        }
        unset($o);

        return $rider;
    }
}
