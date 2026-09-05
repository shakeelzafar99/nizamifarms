<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ABSENCES — deduct, park, or excuse.
 *
 * An absent day used to have one outcome: the salary was cut, automatically and silently.
 * The owner wants three (Sep-2 2026):
 *
 *   CUT     deduct now. Today's behaviour, and still what an UNDECIDED month does, so
 *           nothing changes unless somebody chooses otherwise.
 *   PARK    no cut now; the days stay OWED. They are settled later by bonus days the
 *           employee earns from overtime, by his own leave, by a cut, or by excusing them.
 *   EXCUSE  no cut, nothing owed.
 *
 * ⭐⭐ `days_absent` is FROZEN when the decision is made — the same discipline the overtime
 * carry uses for `minutes_earned`. A later attendance edit can move what the grid shows for
 * that month, but it can never quietly change what was agreed and owed.
 *
 * ⭐ Outstanding days are settled OLDEST MONTH FIRST — the same FIFO rule advances and
 * carried overtime already use, so a manager only has to learn it once.
 *
 * ⚠ A month whose parked days have already been settled cannot be re-decided. Reversing a
 * coverage that a later month's overtime paid for is the partial-spend problem that broke
 * the first cut of the carry service; here it is refused outright with a message rather than
 * unwound with arithmetic nobody can audit.
 *
 * ⚠ Schema::hasTable-guarded throughout: before absence_decisions_sep2026.sql runs, every
 * month reads as undecided, which means CUT — exactly today's behaviour.
 */
class AbsenceDecisionService
{
    public const DECISIONS = ['cut', 'park', 'excuse'];

    /**
     * 🗓 Owner ruling (Sep-2026): absences are decided from AUGUST 2026 onwards. Everything
     * before that was settled the old way — the pay went out with the cut already applied —
     * so nagging about it would ask for a decision that can no longer change any money.
     * It also keeps the nag cheap: one closed month to scan instead of the whole history.
     */
    public const START_MONTH = '2026-08';

    private ?bool $hasTableMemo = null;
    private ?bool $hasReceiptColMemo = null;

    /** user_id => rows, so a grid of 11 employees is not 11 queries. */
    private array $rowMemo = [];

    public function enabled(): bool
    {
        if ($this->hasTableMemo === null) {
            try {
                $this->hasTableMemo = Schema::hasTable('t_hr_absence_decision');
            } catch (\Throwable $e) {
                $this->hasTableMemo = false;
            }
        }
        return $this->hasTableMemo;
    }

    /** Is the payslip column for a later cut deployed yet? */
    public function receiptColumnExists(): bool
    {
        if ($this->hasReceiptColMemo === null) {
            try {
                $this->hasReceiptColMemo = Schema::hasColumn('t_hr_payroll_payment', 'held_absence_deduction');
            } catch (\Throwable $e) {
                $this->hasReceiptColMemo = false;
            }
        }
        return $this->hasReceiptColMemo;
    }

    // =====================================================================
    //  READ
    // =====================================================================

    private function rows(int $userId): array
    {
        if (!$this->enabled()) {
            return [];
        }
        if (!isset($this->rowMemo[$userId])) {
            try {
                $this->rowMemo[$userId] = DB::table('t_hr_absence_decision as d')
                    ->leftJoin('t_sys_user as u', 'u.id', '=', 'd.decided_by')
                    ->where('d.user_id', $userId)
                    ->orderBy('d.month')
                    ->get(['d.month', 'd.days_absent', 'd.decision', 'd.day_rate_at_decision',
                           'd.amount_cut_now', 'd.days_covered_ot', 'd.days_covered_leave',
                           'd.days_cut', 'd.days_excused', 'd.amount_cut_later', 'd.cut_in_month',
                           'd.cut_paid_at', 'd.decided_at', 'd.notes', 'u.fullname as decided_by_name'])
                    ->map(fn ($r) => [
                        'month'            => (string) $r->month,
                        'days_absent'      => (float) $r->days_absent,
                        'decision'         => (string) $r->decision,
                        'day_rate'         => (float) $r->day_rate_at_decision,
                        'amount_cut_now'   => (float) $r->amount_cut_now,
                        'covered_ot'       => (float) $r->days_covered_ot,
                        'covered_leave'    => (float) $r->days_covered_leave,
                        'days_cut'         => (float) $r->days_cut,
                        'days_excused'     => (float) $r->days_excused,
                        'amount_cut_later' => (float) $r->amount_cut_later,
                        'cut_in_month'     => $r->cut_in_month,
                        'cut_paid_at'      => $r->cut_paid_at,
                        'decided_at'       => $r->decided_at ? substr((string) $r->decided_at, 0, 10) : null,
                        'decided_by_name'  => $r->decided_by_name,
                        'notes'            => $r->notes,
                        'outstanding'      => self::outstandingOf(
                            (string) $r->decision, (float) $r->days_absent,
                            (float) $r->days_covered_ot + (float) $r->days_covered_leave
                            + (float) $r->days_cut + (float) $r->days_excused
                        ),
                    ])->all();
            } catch (\Throwable $e) {
                $this->rowMemo[$userId] = [];
            }
        }
        return $this->rowMemo[$userId];
    }

    /** Only a PARKED row can owe anything; cut and excuse are settled the moment they are made. */
    private static function outstandingOf(string $decision, float $days, float $resolved): float
    {
        return $decision === 'park' ? max(0, round($days - $resolved, 1)) : 0.0;
    }

    /** This month's decision, or null when nobody has decided (which means CUT). */
    public function decisionFor(int $userId, string $month): ?array
    {
        foreach ($this->rows($userId) as $r) {
            if ($r['month'] === $month) { return $r; }
        }
        return null;
    }

    /** Parked rows that still owe days, oldest month first. */
    public function openParked(int $userId): array
    {
        return array_values(array_filter($this->rows($userId), fn ($r) => $r['outstanding'] > 0));
    }

    /** Total days still owed across every parked month. */
    public function outstandingDays(int $userId): float
    {
        return round(array_sum(array_column($this->openParked($userId), 'outstanding')), 1);
    }

    /** Every recorded decision, newest first — the employee page's absence history. */
    public function historyFor(int $userId): array
    {
        return array_reverse($this->rows($userId));
    }

    /**
     * A later cut that has been STAMPED to this month's pay but not paid yet. computeRow adds
     * it as its own deduction line so the money is visible before Pay is pressed.
     *
     * @return array{amount:float,days:float,months:array<int,string>}
     */
    public function heldCutFor(int $userId, string $month): array
    {
        $amount = 0.0; $days = 0.0; $months = [];
        foreach ($this->rows($userId) as $r) {
            if ($r['cut_in_month'] === $month && $r['cut_paid_at'] === null && $r['days_cut'] > 0) {
                $amount += $r['amount_cut_later'];
                $days   += $r['days_cut'];
                $months[] = $r['month'];
            }
        }
        return ['amount' => round($amount, 2), 'days' => round($days, 1), 'months' => $months];
    }

    /**
     * Months that have absences and NO decision yet — the nag. Deliberately keeps nagging
     * after the month ends and into the next one (owner ruling): an undecided month silently
     * defaults to a cut, and that default should be a choice, not a thing that happened.
     *
     * Only CLOSED months are listed; a running month can still change.
     *
     * @return array<int,array{month:string,days:float}>
     */
    public function undecidedMonths(int $userId, callable $absentDaysFor, string $upToMonth, int $lookBack = 2): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $out = [];
        $m = date('Y-m', strtotime($upToMonth . '-01 -1 month'));   // last CLOSED month
        for ($i = 0; $i < $lookBack; $i++) {
            // ⚠ A month that has already been PAID needs no nagging: pressing Pay applied the
            // default, which is the cut. There is nothing left to decide, and asking would
            // invite a "decision" that can no longer change the money.
            if ($m < self::START_MONTH) { break; }   // nothing before the start month is ours to decide
            if ($this->decisionFor($userId, $m) === null && !$this->monthWasPaid($userId, $m)) {
                $days = (float) $absentDaysFor($m);
                if ($days > 0) { $out[] = ['month' => $m, 'days' => $days]; }
            }
            $m = date('Y-m', strtotime($m . '-01 -1 month'));
        }
        return array_reverse($out);   // oldest first
    }

    /** Has this employee's salary for the month already gone out? */
    private function monthWasPaid(int $userId, string $month): bool
    {
        try {
            return DB::table('t_hr_payroll_payment')
                ->where('user_id', $userId)->where('pay_month', $month)
                ->where('status', 'paid')->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // =====================================================================
    //  WRITE
    // =====================================================================

    /**
     * Record the decision for one employee-month. Called inside the caller's transaction so
     * the decision and any money it moves are written together.
     *
     * Re-deciding is allowed only while nothing has been settled against a parked row —
     * see the class note on why a settled row is refused rather than unwound.
     */
    public function commit(int $userId, string $month, float $days, string $decision, float $dayRate, int $actorId, ?string $note = null): array
    {
        if (!$this->enabled()) {
            return ['success' => false, 'message' => 'Absence decisions are not switched on yet.'];
        }
        if (!in_array($decision, self::DECISIONS, true)) {
            return ['success' => false, 'message' => 'Choose deduct, park or excuse.'];
        }
        if ($month < self::START_MONTH) {
            return ['success' => false, 'message' => 'Absence decisions start from '
                . date('F Y', strtotime(self::START_MONTH . '-01')) . '. Earlier months were already settled.'];
        }
        $existing = $this->decisionFor($userId, $month);
        if ($existing !== null) {
            $resolved = $existing['covered_ot'] + $existing['covered_leave']
                      + $existing['days_cut'] + $existing['days_excused'];
            if ($resolved > 0) {
                return ['success' => false, 'message' => 'Some of ' . date('F Y', strtotime($month . '-01'))
                    . "'s parked days have already been settled, so that month can't be re-decided. "
                    . 'Undo the settlement first.'];
            }
        }

        DB::table('t_hr_absence_decision')->updateOrInsert(
            ['user_id' => $userId, 'month' => $month],
            [
                'days_absent'          => round($days, 1),
                'decision'             => $decision,
                'day_rate_at_decision' => round($dayRate, 2),
                'amount_cut_now'       => $decision === 'cut' ? round($days * $dayRate, 2) : 0,
                'decided_by'           => $actorId,
                'decided_at'           => now(),
                'notes'                => $note,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]
        );
        unset($this->rowMemo[$userId]);
        return ['success' => true];
    }

    /**
     * Spend $days against the oldest parked months first and return how many were used.
     * `$source` is 'ot' (bonus days earned from overtime) or 'leave' (his own balance).
     *
     * This is what makes parking worth doing: the owner parks the days precisely so the
     * employee can work them off, so coverage is automatic when the overtime is granted.
     */
    public function cover(int $userId, float $days, string $source, int $actorId): float
    {
        if (!$this->enabled() || $days <= 0) {
            return 0.0;
        }
        $col = $source === 'leave' ? 'days_covered_leave' : 'days_covered_ot';
        $left = round($days, 1);
        $used = 0.0;
        foreach ($this->openParked($userId) as $r) {
            if ($left <= 0) { break; }
            $take = min($r['outstanding'], $left);
            DB::table('t_hr_absence_decision')
                ->where('user_id', $userId)->where('month', $r['month'])
                ->update([$col => DB::raw('`' . $col . '` + ' . round($take, 1)), 'updated_at' => now()]);
            $left = round($left - $take, 1);
            $used = round($used + $take, 1);
        }
        if ($used > 0) {
            unset($this->rowMemo[$userId]);
            \Log::info('Parked absences covered', [
                'user_id' => $userId, 'days' => $used, 'source' => $source, 'by' => $actorId,
            ]);
        }
        return $used;
    }

    /** Undo coverage previously applied from one source (used when a decision is re-decided). */
    public function uncover(int $userId, float $days, string $source): void
    {
        if (!$this->enabled() || $days <= 0) { return; }
        $col = $source === 'leave' ? 'days_covered_leave' : 'days_covered_ot';
        $left = round($days, 1);
        // Newest first — the mirror image of how it was spent.
        foreach (array_reverse($this->rows($userId)) as $r) {
            if ($left <= 0) { break; }
            $has = $r[$source === 'leave' ? 'covered_leave' : 'covered_ot'];
            if ($has <= 0) { continue; }
            $give = min($has, $left);
            DB::table('t_hr_absence_decision')
                ->where('user_id', $userId)->where('month', $r['month'])
                ->update([$col => DB::raw('`' . $col . '` - ' . round($give, 1)), 'updated_at' => now()]);
            $left = round($left - $give, 1);
        }
        unset($this->rowMemo[$userId]);
    }

    /**
     * Charge the days still owed for one parked month against a chosen month's pay.
     *
     * Owner ruling: the CURRENT day rate is used, not the rate frozen when the days were
     * parked — and the manager can override the amount, because he is the one who decides
     * what a late charge should really be.
     */
    public function cutLater(int $userId, string $month, string $inMonth, float $currentDayRate, ?float $amountOverride, int $actorId): array
    {
        $r = $this->decisionFor($userId, $month);
        if (!$r || $r['outstanding'] <= 0) {
            return ['success' => false, 'message' => 'There is nothing owed for that month.'];
        }
        if ($r['cut_in_month'] !== null && $r['cut_paid_at'] === null) {
            return ['success' => false, 'message' => 'A charge for that month is already waiting on '
                . date('F Y', strtotime($r['cut_in_month'] . '-01')) . "'s pay."];
        }
        $days = $r['outstanding'];
        $amount = $amountOverride !== null ? round(max(0, $amountOverride), 2) : round($days * $currentDayRate, 2);
        DB::table('t_hr_absence_decision')
            ->where('user_id', $userId)->where('month', $month)
            ->update([
                'days_cut'         => DB::raw('`days_cut` + ' . round($days, 1)),
                'amount_cut_later' => $amount,
                'cut_in_month'     => $inMonth,
                'updated_at'       => now(),
            ]);
        unset($this->rowMemo[$userId]);
        \Log::info('Parked absence charged', [
            'user_id' => $userId, 'from_month' => $month, 'in_month' => $inMonth,
            'days' => $days, 'amount' => $amount, 'by' => $actorId,
        ]);
        return ['success' => true, 'days' => $days, 'amount' => $amount];
    }

    /** Forgive whatever is still owed for one parked month. */
    public function excuseLater(int $userId, string $month, int $actorId, ?string $note = null): array
    {
        $r = $this->decisionFor($userId, $month);
        if (!$r || $r['outstanding'] <= 0) {
            return ['success' => false, 'message' => 'There is nothing owed for that month.'];
        }
        DB::table('t_hr_absence_decision')
            ->where('user_id', $userId)->where('month', $month)
            ->update([
                'days_excused' => DB::raw('`days_excused` + ' . round($r['outstanding'], 1)),
                'notes'        => $note ?: $r['notes'],
                'updated_at'   => now(),
            ]);
        unset($this->rowMemo[$userId]);
        \Log::info('Parked absence excused', [
            'user_id' => $userId, 'month' => $month, 'days' => $r['outstanding'], 'by' => $actorId,
        ]);
        return ['success' => true, 'days' => $r['outstanding']];
    }

    /** Stamp the waiting charges as paid, once the pay that carries them has gone out. */
    public function markCutPaid(int $userId, string $inMonth): void
    {
        if (!$this->enabled()) { return; }
        DB::table('t_hr_absence_decision')
            ->where('user_id', $userId)->where('cut_in_month', $inMonth)->whereNull('cut_paid_at')
            ->update(['cut_paid_at' => now(), 'updated_at' => now()]);
        unset($this->rowMemo[$userId]);
    }

    /** Drop the whole decision for a month (used when a payment is undone). */
    public function forget(int $userId, string $month): void
    {
        if (!$this->enabled()) { return; }
        DB::table('t_hr_absence_decision')->where('user_id', $userId)->where('month', $month)->delete();
        unset($this->rowMemo[$userId]);
    }
}
