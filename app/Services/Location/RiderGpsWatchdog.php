<?php

namespace App\Services\Location;

use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rider GPS "defibrillator".
 *
 * Finds riders who are ON DUTY (checked in, not checked out today) but whose
 * last GPS heartbeat is stale — a sign the OS/OEM snoozed their location
 * foreground service — and sends each a silent, high-priority FCM data push
 * that tells the app to restart tracking (handled in notificationService.js →
 * NativeLocationService.restartTracking()).
 *
 * Triggered opportunistically (piggybacked on the rider location-heartbeat
 * endpoint, lock-protected to ~once/min via runThrottled) so it works without a
 * reliable cron. The on-device WorkManager watchdog is the backstop for when
 * ALL riders are silent (no heartbeat → nothing triggers the server sweep).
 */
class RiderGpsWatchdog
{
    /** A rider is "silent" if no heartbeat in this many minutes. */
    private const SILENT_AFTER_MIN = 12;

    /** Don't ping the same rider more often than this (give it time to recover). */
    private const PING_COOLDOWN_MIN = 10;

    /** The whole sweep runs at most once per this many seconds across all callers. */
    private const SWEEP_EVERY_SEC = 60;

    /**
     * Run the sweep at most once per SWEEP_EVERY_SEC across all callers. Safe to
     * call from a request path — designed to be invoked inside app()->terminating().
     */
    public function runThrottled(): void
    {
        $lock = Cache::lock('rider_gps_watchdog:sweep', 30);
        if (!$lock->get()) {
            return; // another caller is already sweeping
        }

        try {
            $last = Cache::get('rider_gps_watchdog:last_run', 0);
            if (is_numeric($last) && (time() - (int) $last) < self::SWEEP_EVERY_SEC) {
                return; // swept very recently
            }
            Cache::put('rider_gps_watchdog:last_run', time(), now()->addMinutes(5));
            $this->sweep();
        } catch (\Throwable $e) {
            Log::warning('RiderGpsWatchdog sweep failed', ['error' => $e->getMessage()]);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Find silent on-duty riders and ping them. Returns a small summary
     * (also handy for a manual/tinker run).
     *
     * @return array{on_duty:int, silent:int, pinged:int}
     */
    public function sweep(): array
    {
        $today = now()->format('Y-m-d');

        $riderIds = DB::table('t_ops_attendance')
            ->whereDate('attendance_date', $today)
            ->whereNotNull('login_time')
            ->whereNull('logout_time')
            ->pluck('user_id')
            ->all();

        if (empty($riderIds)) {
            return ['on_duty' => 0, 'silent' => 0, 'pinged' => 0];
        }

        $lastByRider = DB::table('t_ops_rider_location')
            ->select('user_id', DB::raw('MAX(captured_at) as last_at'))
            ->whereIn('user_id', $riderIds)
            ->where('captured_at', '>=', now()->subHours(24))
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id')
            ->all();

        $silent   = 0;
        $pinged   = 0;
        $firebase = app(FirebaseService::class);

        foreach ($riderIds as $uid) {
            $lastAt = $lastByRider[$uid] ?? null;
            // abs(): Laravel 11 ships Carbon 3, whose diffInMinutes is signed.
            $ageMin = $lastAt ? (int) abs(now()->diffInMinutes(Carbon::parse($lastAt))) : 99999;

            if ($ageMin < self::SILENT_AFTER_MIN) {
                continue; // fresh — nothing to do
            }
            $silent++;

            $cooldownKey = 'rider_gps_watchdog:pinged:' . $uid;
            if (Cache::has($cooldownKey)) {
                continue; // pinged recently, let it recover before pinging again
            }
            Cache::put($cooldownKey, 1, now()->addMinutes(self::PING_COOLDOWN_MIN));

            $sent = $firebase->pingRiderToWake((int) $uid, [
                'reason'  => 'gps_stale',
                'age_min' => (string) $ageMin,
            ]);

            if ($sent > 0) {
                $pinged++;
                Log::info('RiderGpsWatchdog: pinged silent rider', [
                    'user_id' => $uid,
                    'age_min' => $ageMin,
                    'devices' => $sent,
                ]);
            }
        }

        return ['on_duty' => count($riderIds), 'silent' => $silent, 'pinged' => $pinged];
    }
}
