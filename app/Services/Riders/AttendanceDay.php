<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐ "WHICH DAY IS THIS RIDER ON RIGHT NOW?" — the past-midnight rule, in ONE place.
 *
 * THE RULE (owner-approved 28 Jul 2026, re-affirmed 1 Sep 2026): a shift that runs past
 * 00:00 still lives on YESTERDAY's attendance row. So **before 06:00**, a rider with an open
 * yesterday row (login_time set, logout_time null) is still on that shift — not on a new day.
 *
 * ⚠⚠ WHY THIS CLASS EXISTS AT ALL. The rule was implemented by copy-paste into four endpoints,
 * with a comment in each saying "keep the four in step". They did not stay in step: a FIFTH
 * door — `uploadMeterPicture` — was written later and never got it, and on **1 Sep 2026 at
 * 00:23 that dead-ended Farooq's checkout entirely**. He held a company bike, so the app made
 * him record his closing meter first; the meter endpoint asked the today-only question, found
 * no row for 1 Sep, and answered "No attendance record found for today. Please check in first."
 * `checkOut()` — which handles midnight perfectly — was never reached. His day never closed.
 * `confirmCash` had the same gap.
 *
 * ⭐ So the rule is no longer something an endpoint has to remember. Ask this class.
 *
 * ⚠ The 06:00 bound is deliberate and was re-confirmed by the owner rather than widened: the
 *   earliest shift here starts 09:30, so nothing before 06:00 can be a genuine new day, and a
 *   rider who only notices at 07:00 goes through a manager on purpose. If it ever moves, it
 *   moves HERE, once, for every door.
 */
class AttendanceDay
{
    /** Before this wall-clock time, an open yesterday shift is still today's work. */
    public const PAST_MIDNIGHT_UNTIL = '06:00:00';

    /** Are we inside the small-hours window where yesterday's shift can still be open? */
    public function inPastMidnightWindow(): bool
    {
        return now()->format('H:i:s') < self::PAST_MIDNIGHT_UNTIL;
    }

    /** This user's row for a given calendar date (null when there is none). */
    public function rowFor(int $userId, ?string $date = null): ?object
    {
        $date = $date ? substr($date, 0, 10) : now()->format('Y-m-d');

        return DB::table('t_ops_attendance')
            ->where('user_id', $userId)
            ->whereDate('attendance_date', $date)
            ->first();
    }

    /**
     * Yesterday's still-OPEN shift — but only inside the small-hours window.
     *
     * Returns null outside the window even when such a row exists, because after 06:00 a
     * forgotten checkout is a manager's decision, not a silent morning self-checkout.
     */
    public function openPastMidnightRow(int $userId): ?object
    {
        if (!$this->inPastMidnightWindow()) {
            return null;
        }

        return DB::table('t_ops_attendance')
            ->where('user_id', $userId)
            ->whereDate('attendance_date', now()->subDay()->format('Y-m-d'))
            ->whereNotNull('login_time')
            ->whereNull('logout_time')
            ->first();
    }

    /**
     * ⭐ THE question every attendance door should ask: which row is this rider's CURRENT
     * working day, and is it yesterday's?
     *
     * Today's row wins whenever it represents a real day (it has a login_time). Otherwise —
     * no row at all, or a meter-only shell with no check-in — an open yesterday shift inside
     * the window is the day he is actually on.
     *
     * @return array{0: ?object, 1: bool}  [row, crossedMidnight]
     */
    public function currentRow(int $userId): array
    {
        $today = $this->rowFor($userId);

        if ($today && $today->login_time) {
            return [$today, false];
        }

        $open = $this->openPastMidnightRow($userId);
        if ($open) {
            return [$open, true];
        }

        return [$today, false];
    }

    /**
     * The row a JUST-AFTER-CHECKOUT action belongs to — today's if there is one, otherwise
     * yesterday's row inside the window whether it is open or already closed.
     *
     * ⚠ Deliberately NOT `openPastMidnightRow`: that one requires `logout_time IS NULL`, which
     *   is exactly what a post-checkout step no longer has. The cash-held confirmation the app
     *   shows immediately after a 00:2x checkout is confirming money against the shift it just
     *   closed, so demanding an open row would 404 every single time.
     */
    public function currentOrJustClosedRow(int $userId): ?object
    {
        $today = $this->rowFor($userId);
        if ($today) {
            return $today;
        }
        if (!$this->inPastMidnightWindow()) {
            return null;
        }

        return $this->rowFor($userId, now()->subDay()->format('Y-m-d'));
    }

    /**
     * The date a shift BELONGS to, as 'Y-m-d' — normally today, yesterday when the work rolled
     * past midnight. Every per-day side effect (the shift snapshot, road distance, the delivery
     * reference for the checkout rule) must key off this, never the wall-clock date.
     */
    public function shiftDate(?object $row): string
    {
        return $row ? substr((string) $row->attendance_date, 0, 10) : now()->format('Y-m-d');
    }

    /**
     * A human label for the day being closed — "Sunday 31 Aug". Used to tell the rider WHICH
     * day his checkout is closing, which is the whole point of noticing the roll at all.
     * Returns null when the row is today's (nothing surprising to announce).
     */
    public function crossedMidnightLabel(?object $row): ?string
    {
        if (!$row) {
            return null;
        }
        $date = $this->shiftDate($row);
        if ($date === now()->format('Y-m-d')) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date)->format('l j M');
        } catch (\Throwable $e) {
            return $date;
        }
    }
}
