<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ THE ONE WRITER for a manager correcting a rider's ATTENDANCE meter reading.
 *
 * WHY IT EXISTS
 * This logic lived inside `AttendanceController::updateMeterValues` and was reachable
 * from exactly one screen. The Vehicles page now needs the same power — a manager
 * looking at a machine's day should be able to fix the reading he is looking at — and
 * a second screen must never mean a second implementation. Two copies of a rule drift;
 * this codebase has been bitten by that repeatedly (three copies of "is this a transfer
 * day", the meter-required helper that had to be unified across three surfaces).
 *
 * ⚠⚠ THREE SUBTLETIES THAT ARE EASY TO LOSE IN A RE-IMPLEMENTATION — all preserved:
 *
 *  1. ONLY THE FIELDS ACTUALLY SENT ARE WRITTEN. A blank box in a form must never
 *     wipe a stored reading; the caller passes `null` for "leave it alone" and an
 *     explicit value (or an explicit clear) for a real change.
 *
 *  2. U5 — PROVENANCE IS NOT OVERWRITTEN BY A DIGIT FIX. Filling a MISSING or
 *     office-typed start stamps `meter_start_source = 'manager'`. But correcting a
 *     genuine `'home'` recording KEEPS the `home` stamp: the manager fixed digits, he
 *     did not re-record the reading, and the morning-flow reports read that stamp.
 *
 *  3. U4 — HOME-JOURNEY SYNC. On a company-bike day the going-home flow stores
 *     `meter_home` as the day-closing reading (a mirror of `meter_end`). Correcting
 *     `meter_end` without it leaves the journey stuck with a stale or empty
 *     `meter_home`; if the journey was still open it is closed honestly, at the
 *     moment of correction. Only bike-journey rows are touched.
 *
 * ⚠ WHAT THIS DOES NOT DO: it never touches petrol/maintenance request rows. A claim
 *   stores its own `meter_distance` / `meter_at_fill` when it is filed, so correcting
 *   an attendance reading NEVER rewrites settled money — it only changes what FUTURE
 *   claims are validated against. Payroll never reads meters at all.
 */
class MeterCorrectionService
{
    /**
     * Correct one attendance row's meter readings.
     *
     * @param  int       $attendanceId
     * @param  bool      $hasStart  was a start value sent at all?
     * @param  int|null  $start     the value (null = clear it)
     * @param  bool      $hasEnd    was an end value sent at all?
     * @param  int|null  $end       the value (null = clear it)
     * @param  int|null  $actorId   who is making the correction
     * @return array{ok:bool, message:string, meter_start?:int|null, meter_end?:int|null}
     */
    public function correct(int $attendanceId, bool $hasStart, ?int $start,
                            bool $hasEnd, ?int $end, ?int $actorId = null): array
    {
        $att = DB::table('t_ops_attendance')->where('id', $attendanceId)->first();
        if (!$att) {
            return ['ok' => false, 'message' => 'Attendance record not found.'];
        }
        if (!$hasStart && !$hasEnd) {
            return ['ok' => false, 'message' => 'Nothing to update.'];
        }

        // (1) Only the fields actually sent.
        $update = ['updated_by' => $actorId, 'updated_at' => now()];
        if ($hasStart) $update['meter_start'] = $start;
        if ($hasEnd)   $update['meter_end']   = $end;

        // (2) U5 — a digit fix on a genuine home recording keeps its 'home' stamp.
        if ($hasStart && $start !== null
            && Schema::hasColumn('t_ops_attendance', 'meter_start_source')
            && (string) ($att->meter_start_source ?? '') !== 'home') {
            $update['meter_start_source'] = 'manager';
            $update['meter_start_recorded_at'] = now();
        }

        // (3) U4 — keep the going-home flow consistent with the corrected close.
        if ($hasEnd && $end !== null
            && !empty($att->home_expected_by)
            && Schema::hasColumn('t_ops_attendance', 'meter_home')) {
            $update['meter_home'] = $end;
            if (empty($att->meter_home)) {
                if (empty($att->home_arrived_at)) {
                    $update['home_arrived_at'] = now();
                    $update['home_arrival_source'] = 'manager';
                }
                if (Schema::hasColumn('t_ops_attendance', 'home_meter_recorded_at')) {
                    $update['home_meter_recorded_at'] = now();
                }
            }
        }

        DB::table('t_ops_attendance')->where('id', $att->id)->update($update);

        Log::info('Meter values corrected', [
            'attendance_id' => $att->id, 'user_id' => $att->user_id, 'by' => $actorId,
            'meter_start' => $update['meter_start'] ?? $att->meter_start,
            'meter_end'   => $update['meter_end']   ?? $att->meter_end,
        ]);

        return [
            'ok' => true,
            'message' => 'Meter updated.',
            'meter_start' => $update['meter_start'] ?? $att->meter_start,
            'meter_end'   => $update['meter_end']   ?? $att->meter_end,
        ];
    }
}
