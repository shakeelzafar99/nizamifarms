<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\RiderDayReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Rider Reports — Phase 2 read API (Jul-2026).
 *
 * Powers the web "Issues" tab (manager daily exceptions) and the per-rider
 * Report Card. Fully REAL-TIME: every open computes the requested day live from
 * the source tables (60s cache) — no nightly job, no stored snapshot, so there
 * is never any staleness or inconsistency. Only recent dates are offered
 * (config live_window_days), which also keeps it inside the ~20-day GPS window.
 *
 * Gated by the `view_rider_reports` permission.
 */
class RiderReportsController extends Controller
{
    const PERMISSION = 'view_rider_reports';
    const LIVE_CACHE_SECS = 60;    // avoid recompute storms on rapid reloads

    /** Web entry — gated by the role permission. */
    public function index(Request $request, RiderDayReportService $svc)
    {
        $user = auth()->user();
        if (!$user || !$user->hasPermission(self::PERMISSION)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->build($request, $svc);
    }

    /** Shared real-time builder (permission already checked by the caller). */
    private function build(Request $request, RiderDayReportService $svc)
    {
        try {
            $window = (int) config('rider_reports.live_window_days', 7);
            $date = $request->query('date') ?: Carbon::today()->format('Y-m-d');
            $date = Carbon::parse($date)->format('Y-m-d');   // guard bad input

            $daysAgo = Carbon::parse($date)->diffInDays(Carbon::today(), false);
            if ($daysAgo < 0 || $daysAgo > $window) {
                return response()->json([
                    'success' => true, 'date' => $date, 'realtime' => true,
                    'out_of_window' => true, 'window_days' => $window,
                    'config' => $this->cfgPublic(), 'riders' => [],
                ]);
            }

            $riders = Cache::remember(
                "rider_reports_live_{$date}",
                self::LIVE_CACHE_SECS,
                fn () => $this->buildLive($svc, $date)
            );

            $riderIds = $this->riderRoleUserIds();
            foreach ($riders as &$r) {
                $r['is_rider'] = in_array($r['user_id'], $riderIds, true);
            }
            unset($r);

            return response()->json([
                'success'     => true,
                'date'        => $date,
                'realtime'    => true,
                'window_days' => $window,
                'config'      => $this->cfgPublic(),
                'riders'      => array_values($riders),
            ]);
        } catch (\Throwable $e) {
            \Log::error('RiderReports index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load reports'], 500);
        }
    }

    private function cfgPublic(): array
    {
        return [
            'at_verified_m'        => (int) config('rider_reports.at_verified_m'),
            'late_manager_minutes' => (int) config('rider_reports.late_manager_minutes'),
            'late_card_minutes'    => (int) config('rider_reports.late_card_minutes'),
        ];
    }

    // ---- per-rider Timeline (chronological day) -----------------------

    /** Web entry — one rider's day timeline. Gated by the role permission. */
    public function timeline(Request $request, RiderDayReportService $svc)
    {
        $user = auth()->user();
        if (!$user || !$user->hasPermission(self::PERMISSION)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->buildTimeline($request, $svc);
    }

    /** Mobile entry — gated by the mobile permission. */
    public function apiTimeline(Request $request, RiderDayReportService $svc)
    {
        $user = $request->user();
        if (!$user || !$user->hasMobilePermission(self::PERMISSION)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->buildTimeline($request, $svc);
    }

    /** Merge a rider's day into one time-sorted event list. */
    private function buildTimeline(Request $request, RiderDayReportService $svc)
    {
        try {
            $window = (int) config('rider_reports.live_window_days', 7);
            $date = Carbon::parse($request->query('date') ?: Carbon::today())->format('Y-m-d');
            $rid  = (int) $request->query('rider');
            if (!$rid) {
                return response()->json(['success' => false, 'message' => 'rider required'], 422);
            }
            $daysAgo = Carbon::parse($date)->diffInDays(Carbon::today(), false);
            if ($daysAgo < 0 || $daysAgo > $window) {
                return response()->json(['success' => true, 'out_of_window' => true, 'window_days' => $window, 'events' => []]);
            }

            $name = DB::table('t_sys_user')->where('id', $rid)->value('fullname') ?: 'Rider';
            $tz   = $svc->detectTzOffset();
            $att  = DB::table('t_ops_attendance')->where('user_id', $rid)
                    ->where('attendance_date', $date)->first();

            // For the timeline, span the whole shift (check-in − 15m … check-out + 15m)
            // so the full day shows — not just the delivery window.
            $bounds = null;
            if ($att && $att->login_time) {
                $from = strtotime("$date {$att->login_time}") - 900;
                $to = null;
                if ($att->logout_time) {
                    $to = strtotime("$date {$att->logout_time}");
                    if ($to < strtotime("$date {$att->login_time}")) $to += 86400;  // past midnight
                    $to += 900;
                } else {
                    $to = strtotime("$date {$att->login_time}") + 16 * 3600;         // still on duty → wide
                }
                $bounds = [$from, $to];
            }

            $rep  = $svc->computeForRider($rid, $name, $date, $tz, $bounds)
                    ?? ['orders' => [], 'stops' => [], 'gaps' => []];

            $events = [];

            // check-in
            if ($att && $att->login_time) {
                $events[] = [
                    'kind' => 'checkin', 'time' => substr($att->login_time, 0, 5),
                    'remote' => (int) ($att->is_remote_checkin ?? 0) === 1,
                    'dist_m' => $att->checkin_distance_from_base !== null ? (int) $att->checkin_distance_from_base : null,
                    '_sort' => strtotime("$date {$att->login_time}"),
                ];
            }
            // deliveries (carry the full order → frontend computes the same chips)
            foreach ($rep['orders'] as $o) {
                $events[] = [
                    'kind' => 'delivery', 'time' => date('H:i', strtotime($o['delivered_at'])),
                    'order' => $o, '_sort' => strtotime($o['delivered_at']),
                ];
            }
            // ALL stops (HQ/known/customer + unknown) so the day is complete
            foreach ($rep['stops'] as $s) {
                $events[] = [
                    'kind' => 'stop', 'time' => $s['from'], 'to' => $s['to'], 'min' => $s['min'],
                    'label' => $s['label'] ?? 'unknown', 'context' => $s['context'] ?? 'between',
                    'on_board' => $s['on_board'] ?? 0, 'is_unknown' => (int) ($s['is_unknown'] ?? 0),
                    'map_url' => $s['map_url'] ?? null, '_sort' => $s['_from_ts'] ?? strtotime("$date {$s['from']}"),
                ];
            }
            // GPS gaps
            foreach ($rep['gaps'] as $g) {
                $events[] = [
                    'kind' => 'gap', 'time' => $g['from'], 'to' => $g['to'], 'min' => $g['min'],
                    '_sort' => strtotime("$date {$g['from']}"),
                ];
            }
            // check-out (may be past midnight → push a day so it sorts last)
            if ($att && $att->logout_time) {
                $co = strtotime("$date {$att->logout_time}");
                if ($att->login_time && $co < strtotime("$date {$att->login_time}")) $co += 86400;
                $events[] = ['kind' => 'checkout', 'time' => substr($att->logout_time, 0, 5), '_sort' => $co];
            }

            usort($events, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);
            foreach ($events as &$e) { unset($e['_sort']); }
            unset($e);

            return response()->json([
                'success' => true, 'date' => $date, 'realtime' => true,
                'rider_name' => $name, 'config' => $this->cfgPublic(), 'events' => $events,
            ]);
        } catch (\Throwable $e) {
            \Log::error('RiderReports timeline failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load timeline'], 500);
        }
    }

    /**
     * Mobile API — same real-time report, gated by the MOBILE permission
     * `view_rider_reports` (Roles → Mobile Permissions). Sanctum-authenticated.
     */
    public function apiIndex(Request $request, RiderDayReportService $svc)
    {
        $user = $request->user();
        if (!$user || !$user->hasMobilePermission(self::PERMISSION)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        return $this->build($request, $svc);
    }

    /** Live compute via the service. */
    private function buildLive(RiderDayReportService $svc, string $date): array
    {
        $out = [];
        foreach ($svc->computeForDate($date) as $userId => $rep) {
            $out[$userId] = [
                'user_id'     => (int) $userId,
                'rider_name'  => $rep['day']['rider_name'] ?? 'Rider',
                'day'         => $rep['day'],   // already the public day shape from the service
                'orders'      => $rep['orders'],
                // only unknown stops matter to the report (HQ/known/customer stops are "accounted")
                'stops'       => array_values(array_map(fn ($s) => $this->stopPublic($s),
                                    array_filter($rep['stops'], fn ($s) => !empty($s['is_unknown'])))),
                'gaps'        => $rep['gaps'],
                'odd_routes'  => $rep['odd_routes'] ?? [],
                'missed_dispatch' => null,
            ];
        }

        // Merge "left without dispatch" events from the canonical detector's log
        // (t_ops_dispatch_missed, see DISPATCH-TRACKING-MODEL.md §7). A flagged
        // rider with no deliveries yet still appears, with an empty day.
        foreach ($this->missedDispatchEvents($date) as $rid => $ev) {
            if (isset($out[$rid])) {
                $out[$rid]['missed_dispatch'] = $ev;
            } else {
                $out[$rid] = [
                    'user_id' => $rid, 'rider_name' => $ev['rider_name'] ?: 'Rider',
                    'day' => ['rider_name' => $ev['rider_name'], 'delivered_count' => 0, 'eta_total' => 0,
                              'ontime_count' => 0, 'has_verified_count' => 0, 'at_verified_count' => 0],
                    'orders' => [], 'stops' => [], 'gaps' => [], 'odd_routes' => [],
                    'missed_dispatch' => $ev,
                ];
            }
        }

        // Merge attendance lateness (only when late today AND month total crosses
        // the threshold — quiet until it's a pattern).
        $lateness = $this->attendanceLateness(array_keys($out), $date);
        foreach ($out as $rid => &$r) {
            $r['lateness'] = $lateness[$rid] ?? null;
        }
        unset($r);

        return $out;
    }

    /**
     * Month-to-date attendance lateness per rider. Reuses ShiftResolutionService
     * for each rider's shift start and the same TIMESTAMPDIFF logic as the salary
     * calculation, so the numbers match the salary slips. Returns only riders who
     * are late TODAY and whose month total ≥ the configured threshold.
     */
    private function attendanceLateness(array $userIds, string $date): array
    {
        $out = [];
        if (empty($userIds)) return $out;
        try {
            $threshold = (int) config('rider_reports.late_month_threshold_min', 200);
            $monthStart = date('Y-m-01', strtotime($date));
            $shifts = (new \App\Services\ShiftResolutionService())->getUserShiftsBulk($userIds, $date);

            foreach ($userIds as $rid) {
                $shiftStart = $shifts[$rid]['shift_start'] ?? '09:00:00';
                $row = DB::selectOne(
                    "SELECT
                        COALESCE(SUM(CASE WHEN login_time > ? THEN
                            TIMESTAMPDIFF(MINUTE, CONCAT(attendance_date,' ',?), CONCAT(attendance_date,' ',login_time))
                          ELSE 0 END), 0) AS month_min,
                        COALESCE(SUM(CASE WHEN attendance_date = ? AND login_time > ? THEN
                            TIMESTAMPDIFF(MINUTE, CONCAT(attendance_date,' ',?), CONCAT(attendance_date,' ',login_time))
                          ELSE 0 END), 0) AS today_min
                       FROM t_ops_attendance
                      WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
                        AND login_time IS NOT NULL AND login_time != ''",
                    [$shiftStart, $shiftStart, $date, $shiftStart, $shiftStart, $rid, $monthStart, $date]
                );
                $today = (int) ($row->today_min ?? 0);
                $month = (int) ($row->month_min ?? 0);
                if ($today > 0 && $month >= $threshold) {
                    $out[$rid] = ['today_min' => $today, 'month_min' => $month];
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('RiderReports attendanceLateness failed (non-fatal)', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * "Left without dispatch" events for a date, from the event log written by
     * the canonical detectLeftWithoutDispatch() rule. Resolution is DERIVED per
     * the model: each undispatched order id later carries eta_calculated_at/by
     * once someone presses dispatch. Fail-safe: table absent / bad data → [].
     */
    private function missedDispatchEvents(string $date): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_ops_dispatch_missed')) {
                return [];
            }
            $rows = DB::table('t_ops_dispatch_missed as m')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'm.rider_id')
                ->where('m.issue_date', $date)
                ->select('m.*', 'u.fullname')
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $ids = json_decode($r->undispatched_order_ids ?: '[]', true) ?: [];
                $resolution = ['state' => 'unknown'];
                if (!empty($ids)) {
                    $orders = DB::table('t_crm_prod_order as o')
                        ->leftJoin('t_sys_user as du', 'du.id', '=', 'o.eta_calculated_by')
                        ->whereIn('o.id', $ids)
                        ->select('o.id', 'o.eta_calculated_at', 'o.eta_calculated_by', 'du.fullname as by_name')
                        ->get();
                    $dispatched = $orders->filter(fn ($o) => $o->eta_calculated_at !== null)
                                         ->sortBy('eta_calculated_at');
                    if ($dispatched->isEmpty()) {
                        $resolution = ['state' => 'still_undispatched'];
                    } else {
                        $first = $dispatched->first();
                        $resolution = [
                            'state'   => 'dispatched',
                            'at'      => date('H:i', strtotime($first->eta_calculated_at)),
                            'by_name' => $first->by_name,
                            'by_self' => (int) $first->eta_calculated_by === (int) $r->rider_id,
                        ];
                    }
                }
                $out[(int) $r->rider_id] = [
                    'left_at'    => $r->left_at ? date('H:i', strtotime($r->left_at)) : null,
                    'context'    => $r->context,                     // first_run | returned_then_left
                    'count'      => (int) $r->undispatched_count,
                    'resolution' => $resolution,
                    'rider_name' => $r->fullname,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            \Log::warning('RiderReports missedDispatchEvents failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function stopPublic(array $s): array
    {
        return [
            'from' => $s['from'], 'to' => $s['to'], 'min' => $s['min'],
            'lat' => $s['lat'], 'lng' => $s['lng'], 'label' => $s['label'] ?? 'unknown',
            'on_board' => $s['on_board'] ?? 0, 'context' => $s['context'] ?? 'between',
            'area' => $s['area'] ?? null, 'map_url' => $s['map_url'] ?? null,
        ];
    }

    /** user_ids that hold at least one role of type 'rider'. */
    private function riderRoleUserIds(): array
    {
        return DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('r.type', 'rider')->where('r.is_active', '1')
            ->pluck('ur.user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
