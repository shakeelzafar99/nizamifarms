<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CARRIED OVERTIME — the minutes left over once whole bonus-leave days are granted.
 *
 * Overtime becomes whole days (`floor(minutes / 540)`); everything under the line used to be
 * thrown away. On real Jun–Aug 2026 attendance that was 70.7 hours — 22 % of all overtime
 * worked — and it quietly rewarded stopping once you passed a multiple of 9 hours. Now the
 * remainder carries forward until it is used or explicitly forfeited.
 *
 * ⭐⭐ SHAPE: the table holds one row per DECIDED MONTH, and "which month do the leftover
 * minutes belong to" is DERIVED by replaying those rows in order. It is not stored, because a
 * single month's leftovers can be spent across two later months and a partial spend has no
 * natural owner to record it against — the replay never has that problem, and the breakdown
 * can never drift out of step with the days actually granted.
 *
 * The owner requires the breakdown because a carry can legitimately span months: 217 m from
 * August plus 200 m earned in September is 417 m, still under the 540 m line, so nothing is
 * granted and BOTH months are still owed to the employee.
 *
 * Owner rulings (Sep-2 2026): carried minutes never expire; skipping a month forfeits its
 * carry (with a warning first); consumption is oldest-first; nothing before August 2026 is
 * credited; custom-schedule staff are untouched.
 *
 * ⚠ Schema::hasTable-guarded throughout: before overtime_carry_sep2026.sql runs this reports
 * "no carry" everywhere and payroll behaves exactly as it does today.
 */
class OvertimeCarryService
{
    /** Nothing before this month is credited (owner ruling). */
    public const START_MONTH = '2026-08';

    private ?bool $hasTableMemo = null;

    /** user_id => decision rows, so a grid of 11 employees is not 11 queries. */
    private array $rowMemo = [];

    public function enabled(): bool
    {
        if ($this->hasTableMemo === null) {
            try {
                $this->hasTableMemo = Schema::hasTable('t_hr_overtime_carry');
            } catch (\Throwable $e) {
                $this->hasTableMemo = false;
            }
        }
        return $this->hasTableMemo;
    }

    /** Minutes in one whole bonus-leave day — the divisor stays OvertimeService's. */
    public function perDay(): int
    {
        return (new OvertimeService())->minutesPerBonusDay();
    }

    // =====================================================================
    //  THE REPLAY — the one place the queue is derived
    // =====================================================================

    /** Decision rows for a user, oldest first. */
    private function rows(int $userId): array
    {
        if (!$this->enabled()) {
            return [];
        }
        if (!isset($this->rowMemo[$userId])) {
            try {
                $this->rowMemo[$userId] = DB::table('t_hr_overtime_carry')
                    ->where('user_id', $userId)
                    ->orderBy('month')
                    ->get(['month', 'minutes_earned', 'days_granted', 'decision', 'forfeited_at'])
                    ->map(fn ($r) => [
                        'month'          => (string) $r->month,
                        'minutes_earned' => (int) $r->minutes_earned,
                        'days_granted'   => (int) $r->days_granted,
                        'decision'       => (string) $r->decision,
                        'forfeited'      => $r->forfeited_at !== null,
                    ])->all();
            } catch (\Throwable $e) {
                $this->rowMemo[$userId] = [];
            }
        }
        return $this->rowMemo[$userId];
    }

    /**
     * Replay every decision BEFORE $month and return the surviving minutes, each still
     * labelled with the month it was earned in.
     *
     * @return array<int,array{earned_month:string,minutes:int}>
     */
    public function openLotsBefore(int $userId, string $month): array
    {
        $per = $this->perDay();
        $queue = [];
        foreach ($this->rows($userId) as $r) {
            if ($r['month'] >= $month) {
                break;                       // rows are ordered; nothing later can apply
            }
            // A waived month, or one caught by a manual forfeit, wipes everything banked so
            // far and contributes nothing of its own. That IS what forfeiting means.
            if ($r['decision'] === 'waive' || $r['forfeited']) {
                $queue = [];
                continue;
            }
            if ($r['minutes_earned'] > 0) {
                $queue[] = ['earned_month' => $r['month'], 'minutes' => $r['minutes_earned']];
            }
            $queue = self::consume($queue, $r['days_granted'] * $per);
        }
        return $queue;
    }

    /** Spend $minutes from the front of the queue (oldest first) and return what survives. */
    private static function consume(array $queue, int $minutes): array
    {
        $out = [];
        foreach ($queue as $lot) {
            $take = min($lot['minutes'], max(0, $minutes));
            $minutes -= $take;
            $rem = $lot['minutes'] - $take;
            if ($rem > 0) {
                $out[] = ['earned_month' => $lot['earned_month'], 'minutes' => $rem];
            }
        }
        return $out;
    }

    /** Total minutes carried INTO a month. */
    public function carriedIn(int $userId, string $month): int
    {
        return (int) array_sum(array_column($this->openLotsBefore($userId, $month), 'minutes'));
    }

    /**
     * What a month WOULD do, writing nothing — the preview the grid and the leave-actions
     * panel show while the month is undecided, and the figures commit() then stores.
     *
     * @return array{own:int,carried_in:int,available:int,days:int,carry_out:int,
     *               carried_from:array,carry_out_lots:array}
     */
    public function preview(int $userId, string $month, int $ownMinutes): array
    {
        $per = $this->perDay();
        $lots = $this->openLotsBefore($userId, $month);
        $carriedIn = (int) array_sum(array_column($lots, 'minutes'));

        $queue = $lots;
        if ($ownMinutes > 0 && $month >= self::START_MONTH) {
            $queue[] = ['earned_month' => $month, 'minutes' => $ownMinutes];   // newest goes last
        }
        $available = (int) array_sum(array_column($queue, 'minutes'));
        $days = $per > 0 ? intdiv($available, $per) : 0;
        $out = self::consume($queue, $days * $per);

        // Before the table exists nothing is actually carried, so report none — otherwise the
        // grid would promise "1h 29m carried" on a prod where the minutes still evaporate.
        if (!$this->enabled()) {
            return ['own' => $ownMinutes, 'carried_in' => 0, 'available' => $ownMinutes,
                    'days' => $days, 'carry_out' => 0, 'carried_from' => [], 'carry_out_lots' => []];
        }

        return [
            'own'            => $ownMinutes,
            'carried_in'     => $carriedIn,
            'available'      => $available,
            'days'           => $days,
            'carry_out'      => (int) array_sum(array_column($out, 'minutes')),
            'carried_from'   => $lots,
            'carry_out_lots' => $out,
        ];
    }

    /** Has this month's overtime already been recorded (applied or waived)? */
    public function decided(int $userId, string $month): bool
    {
        foreach ($this->rows($userId) as $r) {
            if ($r['month'] === $month) { return true; }
        }
        return false;
    }
    /**
     * Minutes that WILL carry into $month once the previous month's overtime is decided.
     *
     * The carry only becomes real when a month is decided — otherwise a waive could take back
     * minutes a later month had already spent. But an ENDED month sitting undecided leaves the
     * figure invisible, and the owner is right that it should not be: August finishes, someone
     * has 1h 29m left over, and September should say so. So this reports it as PENDING, clearly
     * separate from the committed carry, and names the month that has to be settled.
     *
     * Only the immediately preceding month is examined — deciding is a monthly habit, and
     * scanning further back would cost an attendance sweep per employee per month for a case
     * that should not arise.
     *
     * @return array{month:string,minutes:int}|null
     */
    public function pendingCarryInto(int $userId, string $month): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $prev = date('Y-m', strtotime($month . '-01 -1 month'));
        if ($prev < self::START_MONTH || $prev >= now()->format('Y-m')) {
            return null;                        // before the start, or not finished yet
        }
        foreach ($this->rows($userId) as $r) {
            if ($r['month'] === $prev) {
                return null;                    // already decided — the carry is real, not pending
            }
        }
        try {
            $mins = (new OvertimeService())->overtimeMinutes(
                $userId, $prev . '-01', date('Y-m-t', strtotime($prev . '-01'))
            );
            if ($mins <= 0) {
                return null;
            }
            $left = $this->preview($userId, $prev, $mins)['carry_out'];
            return $left > 0 ? ['month' => $prev, 'minutes' => $left] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** "6h 20m from Aug" / "3h 37m from Aug + 3h 20m from Sep" — one phrasing everywhere. */
    public function describeLots(array $lots): string
    {
        if (!$lots) {
            return '';
        }
        return implode(' + ', array_map(
            fn ($l) => self::hm($l['minutes']) . ' from ' . date('M', strtotime($l['earned_month'] . '-01')),
            $lots
        ));
    }

    /** Minutes as "6h 20m" / "45m". */
    public static function hm(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? ($h . 'h ' . $m . 'm') : ($m . 'm');
    }

    // =====================================================================
    //  WRITE — only from a decided month
    // =====================================================================

    /**
     * Is a LATER month's overtime already decided? Deciding out of order would mean a later
     * month was judged against a carry this change is about to move, so the caller refuses.
     *
     * @return string|null the blocking month, or null when it is safe
     */
    public function blockingLaterMonth(int $userId, string $month): ?string
    {
        foreach ($this->rows($userId) as $r) {
            if ($r['month'] > $month) {
                return $r['month'];
            }
        }
        return null;
    }

    /**
     * Record a month's overtime decision. Called inside the caller's transaction, right beside
     * the leave grant, so the days granted and the minutes they consumed can never disagree.
     *
     * Re-deciding the same month simply overwrites its row — the replay recomputes everything
     * downstream from it, so there is nothing to unwind by hand.
     *
     * @return array the preview that was committed
     */
    public function commit(int $userId, string $month, int $ownMinutes, string $decision, int $actorId): array
    {
        $preview = $this->preview($userId, $month, $ownMinutes);
        if (!$this->enabled() || $month < self::START_MONTH) {
            return $preview;
        }
        $waived = $decision === 'waive';

        DB::table('t_hr_overtime_carry')->updateOrInsert(
            ['user_id' => $userId, 'month' => $month],
            [
                'minutes_earned' => $ownMinutes,
                'carried_in'     => $preview['carried_in'],
                'days_granted'   => $waived ? 0 : $preview['days'],
                'carried_out'    => $waived ? 0 : $preview['carry_out'],
                'decision'       => $waived ? 'waive' : 'apply',
                // Re-deciding clears any earlier forfeit stamp on THIS row, so waive → apply
                // restores exactly what the first apply would have produced.
                'forfeited_at'   => null,
                'forfeited_by'   => null,
                'forfeit_reason' => null,
                'decided_by'     => $actorId,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]
        );
        unset($this->rowMemo[$userId]);

        return $waived
            ? ['own' => $ownMinutes, 'carried_in' => $preview['carried_in'], 'available' => 0,
               'days' => 0, 'carry_out' => 0, 'carried_from' => $preview['carried_from'],
               'carry_out_lots' => []]
            : $preview;
    }

    /**
     * Manual forfeit — owner ruling 2: carried minutes end only by being used, or by someone
     * deciding they are not valid. A reason is required: this destroys overtime the employee
     * actually worked, so the row must say who did it and why.
     */
    public function forfeitAll(int $userId, string $reason, int $actorId): array
    {
        if (!$this->enabled()) {
            return ['success' => false, 'message' => 'Carried overtime is not switched on yet.'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Give a reason — this removes overtime the employee already worked.'];
        }
        $open = $this->carriedIn($userId, '9999-12');
        if ($open <= 0) {
            return ['success' => false, 'message' => 'There is no carried overtime to forfeit.'];
        }
        // Stamping every row is deliberate: in the replay a forfeited row contributes nothing
        // AND clears the queue, so the newest stamped row wipes the balance — which is what a
        // forfeit means. Rows already fully spent are unaffected either way.
        DB::table('t_hr_overtime_carry')
            ->where('user_id', $userId)
            ->whereNull('forfeited_at')
            ->update(['forfeited_at' => now(), 'forfeited_by' => $actorId,
                      'forfeit_reason' => $reason, 'updated_at' => now()]);
        unset($this->rowMemo[$userId]);

        \Log::info('Carried overtime forfeited', [
            'user_id' => $userId, 'minutes' => $open, 'by' => $actorId, 'reason' => $reason,
        ]);
        return ['success' => true, 'message' => self::hm($open) . ' of carried overtime forfeited.'];
    }

    /** Every recorded month for one employee — the employee page's carry history. */
    public function historyFor(int $userId): array
    {
        if (!$this->enabled()) {
            return [];
        }
        try {
            return DB::table('t_hr_overtime_carry as c')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'c.forfeited_by')
                ->where('c.user_id', $userId)
                ->orderByDesc('c.month')
                ->get(['c.month', 'c.minutes_earned', 'c.carried_in', 'c.days_granted',
                       'c.carried_out', 'c.decision', 'c.forfeited_at', 'c.forfeit_reason',
                       'u.fullname as forfeited_by_name'])
                ->map(fn ($r) => [
                    'month'             => (string) $r->month,
                    'minutes_earned'    => (int) $r->minutes_earned,
                    'carried_in'        => (int) $r->carried_in,
                    'days_granted'      => (int) $r->days_granted,
                    'carried_out'       => (int) $r->carried_out,
                    'decision'          => (string) $r->decision,
                    'forfeited_at'      => $r->forfeited_at,
                    'forfeit_reason'    => $r->forfeit_reason,
                    'forfeited_by_name' => $r->forfeited_by_name,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
