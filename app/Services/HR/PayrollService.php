<?php

namespace App\Services\HR;

use App\Models\HR\EmployeeProfileModel;
use App\Models\Request\RequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G payroll engine — computes one employee's month under the NEW policy:
 *   net = base − absent-days deduction − late salary cut − open advances (+ manual add-ons)
 * Lateness and overtime are handled as LEAVES, not money, except when the rider has no leaves
 * to absorb a late penalty (then it becomes a salary cut), and always for >480-min lateness.
 *
 * PURE COMPUTATION — no writes. Reuses the harness-proven attendance summary
 * (SalaryCalculationService::attendanceSummary), the real late total, OvertimeService, and
 * LeavePolicyService, so every number matches the attendance screens.
 */
class PayrollService
{
    private SalaryCalculationService $salary;
    private OvertimeService $ot;
    private LeavePolicyService $leave;
    private \App\Services\ShiftResolutionService $shift;

    public function __construct()
    {
        $this->salary = new SalaryCalculationService();
        $this->ot = new OvertimeService();
        $this->leave = new LeavePolicyService();
        $this->shift = new \App\Services\ShiftResolutionService();
    }

    private function cfg(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function targetHours(): float { return (float) $this->cfg('SHIFT_TARGET_HOURS', 9); }
    private function lateBufferMin(): int { return (int) $this->cfg('LATE_MONTHLY_BUFFER_MINS', 150); }
    private function lateStepMin(): int   { return (int) $this->cfg('LATE_LEAVE_DEDUCT_STEP_MINS', 480); }

    // ── Business-unit resolution (memoized) ──────────────────────────────────
    private static ?int $nfBuIdMemo = null;
    private static ?int $khaasBuIdMemo = null;
    private static bool $buResolved = false;

    private function resolveBuIds(): void
    {
        if (self::$buResolved) { return; }
        self::$buResolved = true;
        try {
            self::$nfBuIdMemo    = ((int) (DB::table('t_fin_business_units')->where('code', 'NF')->value('id') ?? 0)) ?: null;
            self::$khaasBuIdMemo = ((int) (DB::table('t_fin_business_units')->where('code', 'KHAAS')->value('id') ?? 0)) ?: null;
        } catch (\Throwable $e) { /* leave null → treated as NF everywhere */ }
    }
    private function nfBuId(): ?int { $this->resolveBuIds(); return self::$nfBuIdMemo; }
    private function khaasBuId(): ?int { $this->resolveBuIds(); return self::$khaasBuIdMemo; }

    /** 'NF' | 'KHAAS' for a stored business_unit_id (null / NF id / unknown → 'NF'). */
    private function buCodeFor(?int $buId): string
    {
        $kh = $this->khaasBuId();
        return ($buId && $kh && $buId === $kh) ? 'KHAAS' : 'NF';
    }

    /**
     * The BU id to STAMP on a payment/ledger row for an employee: their tag, or the
     * real NF id (never null, so strict `= nfId` BU scopes match). Null only if the
     * business-unit table/row is missing entirely.
     */
    private function stampBuId(?int $profileBuId): ?int
    {
        return $profileBuId ?: $this->nfBuId();
    }

    // ── Schema guards (safe before the custom-schedule SQL is applied) ────────
    private static ?bool $payrollPeriodColsMemo = null;
    private function payrollHasPeriodCols(): bool
    {
        if (self::$payrollPeriodColsMemo === null) {
            try {
                self::$payrollPeriodColsMemo = Schema::hasColumn('t_hr_payroll_payment', 'period_key')
                    && Schema::hasColumn('t_hr_payroll_payment', 'business_unit_id');
            } catch (\Throwable $e) {
                self::$payrollPeriodColsMemo = false;
            }
        }
        return self::$payrollPeriodColsMemo;
    }

    private static ?bool $profileScheduleColsMemo = null;
    private function profileHasScheduleCols(): bool
    {
        if (self::$profileScheduleColsMemo === null) {
            try {
                self::$profileScheduleColsMemo = Schema::hasColumn('t_hr_employee_profile', 'pay_schedule')
                    && Schema::hasColumn('t_hr_employee_profile', 'business_unit_id');
            } catch (\Throwable $e) {
                self::$profileScheduleColsMemo = false;
            }
        }
        return self::$profileScheduleColsMemo;
    }

    // =========================================================================
    //  LEAVE ACTIONS (overtime bonus / late penalty)
    // =========================================================================
    //
    // A month's leave consequences are DECISIONS, not silent side-effects of paying: an
    // employee can earn +N bonus leaves (overtime) and/or owe −1 leave (lateness), and a
    // manager either gives/deducts it or waives it. The decision IS the leave-grant row —
    // there is no second table and no schema change:
    //
    //   no row             → pending   (nobody has decided yet)
    //   row with days ≠ 0  → applied   (given / deducted; days + who + when are on the row)
    //   row with days = 0  → waived    (an explicit "no", recorded so it can't come back)
    //
    // The key is exactly grantOnce()'s dedupe key (user + source + effective_date + reason),
    // so a decision made on the Leave-actions panel and the one payroll would write at pay
    // time are the SAME row. Deciding here and then paying (or the reverse) cannot
    // double-apply, in either order, from either screen.

    /** The two leave consequences a month can carry. */
    public const LEAVE_KINDS = ['overtime', 'late_penalty'];

    /**
     * The deterministic reason payroll writes for a month. Part of the dedupe key, so it
     * must stay byte-identical to what payRow() has always written — a manager's own bonus
     * adjustment also lands in source='overtime' but carries free text, and that difference
     * is the only thing separating "this month's payroll decision" from "a manual tweak".
     */
    public function leaveActionReason(string $kind, string $month): string
    {
        $label = date('M Y', strtotime($month . '-01'));
        return ($kind === 'overtime' ? 'Overtime bonus ' : 'Late penalty ') . $label;
    }

    /** month => [user_id => [kind => decision]] — one query per month, dropped on write. */
    private static array $leaveDecisionMemo = [];

    private function leaveDecisions(string $month): array
    {
        if (isset(self::$leaveDecisionMemo[$month])) {
            return self::$leaveDecisionMemo[$month];
        }
        $map = [];
        try {
            $rows = DB::table('t_hr_leave_grant as g')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'g.created_by')
                ->whereDate('g.effective_date', $month . '-01')
                ->whereIn('g.source', self::LEAVE_KINDS)
                ->get(['g.id', 'g.user_id', 'g.days', 'g.source', 'g.reason',
                       'g.created_at', 'u.fullname as by_name']);
            foreach ($rows as $r) {
                // Only payroll's own deterministic reason counts as this month's decision;
                // a manager's manual bonus adjustment shares source='overtime' but not this.
                if ((string) $r->reason !== $this->leaveActionReason($r->source, $month)) {
                    continue;
                }
                $days = (float) $r->days;
                $map[(int) $r->user_id][$r->source] = [
                    'days'    => $days,
                    'status'  => $days == 0.0 ? 'waived' : 'applied',
                    'by_name' => $r->by_name,
                    'at'      => $r->created_at ? substr((string) $r->created_at, 0, 10) : null,
                ];
            }
        } catch (\Throwable $e) {
            $map = [];   // no grant table yet → everything reads as pending
        }
        return self::$leaveDecisionMemo[$month] = $map;
    }

    private function forgetLeaveDecisions(string $month): void
    {
        unset(self::$leaveDecisionMemo[$month]);
    }

    /** Minutes → "12h 10m" / "45m". */
    private function fmtMins(int $m): string
    {
        $m = max(0, $m);
        $h = intdiv($m, 60);
        return $h > 0 ? ($h . 'h ' . ($m % 60) . 'm') : ($m . 'm');
    }

    /**
     * Is this month finished? A leave action only becomes decidable once it can no longer
     * change — granting a bonus day on the 20th would silently lose the day the employee
     * goes on to earn by the 31st (the grant row is deduped per month, so the later,
     * bigger figure would never be written).
     */
    public function monthIsClosed(string $month): bool
    {
        return $month < date('Y-m');
    }

    /**
     * One decidable item: what the month recommends, what was decided, and the derivation.
     * `detail` is what the manager reads to understand WHERE the number came from; `drill`
     * names the existing attendance date-breakdown that lists the underlying days.
     */
    private function leaveActionShape(string $kind, int $recommended, ?array $decision, array $detail): array
    {
        $signed = $kind === 'overtime' ? $recommended : -$recommended;
        $status = $decision['status'] ?? 'pending';
        return [
            'kind'             => $kind,
            'recommended_days' => $signed,
            'status'           => $status,
            'applied_days'     => $decision ? (float) $decision['days'] : null,
            'decided_by'       => $decision['by_name'] ?? null,
            'decided_at'       => $decision['at'] ?? null,
            // The month was decided on a different figure than it now recommends (e.g. it was
            // paid mid-month and more overtime accrued afterwards). Surfaced, never auto-fixed.
            'changed'          => $decision && $status === 'applied'
                && (float) $decision['days'] !== (float) $signed,
        ] + $detail;
    }

    /**
     * The leave actions for one already-computed payroll row. Returns [] when the month
     * asks nothing of this employee and nothing was ever decided for them.
     */
    public function leaveActionsForRow(array $row, string $month): array
    {
        $uid = (int) $row['user_id'];
        $dec = $this->leaveDecisions($month)[$uid] ?? [];
        $out = [];

        // ── Overtime → whole bonus days (the divisor lives in OvertimeService).
        $otRec = (int) $row['bonus_leaves'];
        $otMin = (int) $row['overtime_minutes'];
        if ($otRec > 0 || isset($dec['overtime'])) {
            $per = $this->ot->minutesPerBonusDay();
            $left = $per > 0 ? $otMin % $per : 0;
            $out[] = $this->leaveActionShape('overtime', $otRec, $dec['overtime'] ?? null, [
                'headline' => '+' . $otRec . ' bonus leave' . ($otRec === 1 ? '' : 's'),
                'basis'    => $this->fmtMins($otMin) . ' worked past the daily target',
                'formula'  => $this->fmtMins($otMin) . ' ÷ ' . $this->fmtMins($per) . ' = ' . $otRec
                    . ' whole day' . ($otRec === 1 ? '' : 's')
                    . ($left > 0 ? ' · ' . $this->fmtMins($left) . ' left over (not carried forward)' : ''),
                'drill'    => 'month_overtime',
                'minutes'  => $otMin,
            ]);
        }

        // ── Lateness → −1 leave (only in the band between the free buffer and the cut line).
        // The RAW recommendation, so a settled month still shows what the rule asked for.
        $lateRec = (int) ($row['late_leave_recommended'] ?? $row['late_leave_deduct']);
        $lateMin = (int) $row['late_minutes'];
        if ($lateRec > 0 || isset($dec['late_penalty'])) {
            $buf = (int) $row['late_buffer_min'];
            $step = (int) $row['late_step_min'];
            $out[] = $this->leaveActionShape('late_penalty', $lateRec, $dec['late_penalty'] ?? null, [
                'headline' => '−' . max(1, $lateRec) . ' leave',
                'basis'    => $this->fmtMins($lateMin) . ' late across the month',
                'formula'  => 'over the ' . $this->fmtMins($buf) . ' free buffer, under the '
                    . $this->fmtMins($step) . ' salary-cut line → −1 leave, no pay cut',
                'drill'    => 'month_late',
                'minutes'  => $lateMin,
            ]);
        }

        return $out;
    }

    /**
     * The whole month's leave actions — the payload behind the Leave-actions card, the
     * review panel, and the attendance banner. All three read this one method so they can
     * never disagree about what is pending.
     */
    public function leaveActionsMonth(string $month): array
    {
        $paid = $this->paidMap($month);
        $payVis = $this->payrollVisibilityMap();
        $customIds = $this->customScheduleUserIds();
        $users = DB::table('t_sys_user')->where('is_active', 1)->orderBy('fullname')->pluck('id');

        $rows = [];
        $sum = [
            'give_pending' => 0, 'deduct_pending' => 0,
            'given' => 0, 'deducted' => 0, 'waived' => 0,
            'pending_count' => 0, 'settled_count' => 0,
            'late_cut_total' => 0.0, 'late_cut_count' => 0,
        ];

        foreach ($users as $uid) {
            $uid = (int) $uid;
            if (isset($customIds[$uid])) { continue; }              // custom periods grant nothing
            if (!($payVis[$uid]['on'] ?? true)) { continue; }
            try {
                $row = $this->computeRow($uid, $month);
            } catch (\Throwable $e) {
                \Log::warning('Leave actions computeRow failed', ['user_id' => $uid, 'month' => $month, 'error' => $e->getMessage()]);
                continue;
            }

            // Money context only — a late SALARY cut is not a leave action and has no button
            // here; it is deducted when the salary is paid. Shown so the month's total
            // picture ("what is being added or taken away") is honest.
            if ((float) $row['late_computed_cut'] > 0) {
                $sum['late_cut_total'] += (float) $row['late_computed_cut'];
                $sum['late_cut_count']++;
            }

            $actions = $this->leaveActionsForRow($row, $month);
            if (!$actions) { continue; }

            foreach ($actions as $a) {
                if ($a['status'] === 'pending') {
                    $sum['pending_count']++;
                    if ($a['kind'] === 'overtime') { $sum['give_pending'] += (int) $a['recommended_days']; }
                    else { $sum['deduct_pending'] += abs((int) $a['recommended_days']); }
                } else {
                    $sum['settled_count']++;
                    if ($a['status'] === 'waived') { $sum['waived']++; }
                    elseif ($a['kind'] === 'overtime') { $sum['given'] += (float) $a['applied_days']; }
                    else { $sum['deducted'] += abs((float) $a['applied_days']); }
                }
            }

            $rows[] = [
                'user_id'     => $uid,
                'fullname'    => $row['fullname'],
                'designation' => $row['designation'],
                'paid'        => isset($paid[$uid]),
                'actions'     => $actions,
            ];
        }

        $sum['late_cut_total'] = round($sum['late_cut_total'], 2);

        return [
            'month'       => $month,
            'month_label' => date('F Y', strtotime($month . '-01')),
            'closed'      => $this->monthIsClosed($month),
            'rows'        => $rows,
            'summary'     => $sum,
        ];
    }

    /**
     * Give / deduct (apply) or waive ONE leave action. Idempotent-safe: re-deciding the same
     * way changes nothing, and changing your mind rewrites the same row rather than stacking
     * a second one.
     */
    public function decideLeaveAction(int $userId, string $month, string $kind, string $decision, int $actorId): array
    {
        if (!in_array($kind, self::LEAVE_KINDS, true)) {
            return ['success' => false, 'message' => 'Unknown leave action.'];
        }
        if (!in_array($decision, ['apply', 'waive'], true)) {
            return ['success' => false, 'message' => 'Choose to apply or waive.'];
        }
        if (!$this->monthIsClosed($month)) {
            return ['success' => false, 'message' => date('F', strtotime($month . '-01'))
                . " hasn't finished yet — overtime and lateness can still change. You can decide once the month ends (paying a salary mid-month still works as before)."];
        }

        try {
            $row = $this->computeRow($userId, $month);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not read this employee’s month.'];
        }
        // RAW recommendation — `late_leave_deduct` is already zeroed once a decision exists,
        // which would make flipping a waive back to "deduct" impossible.
        $rec = $kind === 'overtime'
            ? (int) $row['bonus_leaves']
            : (int) ($row['late_leave_recommended'] ?? $row['late_leave_deduct']);
        $existing = $this->leaveDecisions($month)[$userId][$kind] ?? null;
        if ($rec <= 0 && $existing === null) {
            return ['success' => false, 'message' => 'There is nothing to decide here for this month.'];
        }
        // A settled row keeps its own figure when only the decision flips (waive → give),
        // so re-giving restores exactly what was waived rather than a stale recomputation.
        if ($rec <= 0 && $existing !== null) {
            $rec = (int) abs((float) $existing['days']);
        }
        if ($decision === 'apply' && $rec <= 0) {
            return ['success' => false, 'message' => 'There is nothing to give for this month.'];
        }

        $days = $decision === 'waive' ? 0 : ($kind === 'overtime' ? $rec : -$rec);
        $reason = $this->leaveActionReason($kind, $month);

        try {
            $this->recordLeaveDecision($userId, $days, $kind, $reason, $month . '-01', $actorId, true);
        } catch (\Throwable $e) {
            \Log::error('decideLeaveAction failed', ['user_id' => $userId, 'month' => $month, 'kind' => $kind, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not save that: ' . $e->getMessage()];
        }
        $this->forgetMonth($month);

        \Log::info('Payroll leave action decided', [
            'user_id' => $userId, 'month' => $month, 'kind' => $kind,
            'decision' => $decision, 'days' => $days, 'by' => $actorId,
        ]);

        $name = $row['fullname'];
        $label = date('F', strtotime($month . '-01'));
        if ($decision === 'waive') {
            $msg = $kind === 'overtime'
                ? "$name's $label overtime bonus was skipped."
                : "$name keeps the leave — the $label late penalty was waived.";
        } else {
            $msg = $kind === 'overtime'
                ? "+$rec leave" . ($rec === 1 ? '' : 's') . " given to $name for $label overtime."
                : "−$rec leave deducted from $name for $label lateness.";
        }
        return ['success' => true, 'message' => $msg];
    }

    /**
     * Apply every PENDING recommendation for the month in one go (gives bonuses AND takes
     * late penalties — the recommended outcome). Never touches an already-decided item, so
     * a waive a manager made deliberately is not silently reversed by this button.
     */
    public function applyAllPendingLeaveActions(string $month, int $actorId): array
    {
        if (!$this->monthIsClosed($month)) {
            return ['success' => false, 'message' => date('F', strtotime($month . '-01')) . " hasn't finished yet."];
        }
        $data = $this->leaveActionsMonth($month);
        $given = 0; $deducted = 0; $failed = 0;
        foreach ($data['rows'] as $r) {
            foreach ($r['actions'] as $a) {
                if ($a['status'] !== 'pending' || (int) $a['recommended_days'] === 0) { continue; }
                $res = $this->decideLeaveAction((int) $r['user_id'], $month, $a['kind'], 'apply', $actorId);
                if (!empty($res['success'])) {
                    if ($a['kind'] === 'overtime') { $given += (int) $a['recommended_days']; }
                    else { $deducted += abs((int) $a['recommended_days']); }
                } else {
                    $failed++;
                }
            }
        }
        if (!$given && !$deducted && !$failed) {
            return ['success' => true, 'message' => 'Nothing was pending.', 'given' => 0, 'deducted' => 0];
        }
        $bits = [];
        if ($given) { $bits[] = '+' . $given . ' bonus leave' . ($given === 1 ? '' : 's') . ' given'; }
        if ($deducted) { $bits[] = '−' . $deducted . ' leave' . ($deducted === 1 ? '' : 's') . ' deducted'; }
        if ($failed) { $bits[] = $failed . ' could not be saved'; }
        return ['success' => true, 'message' => ucfirst(implode(', ', $bits)) . '.', 'given' => $given, 'deducted' => $deducted];
    }

    // ── Small date helpers for custom periods ────────────────────────────────
    /** Inclusive calendar-day span (0 when the dates are invalid or reversed). */
    private function calendarDays(string $start, string $end): int
    {
        $a = strtotime($start); $b = strtotime($end);
        if ($a === false || $b === false || $b < $a) { return 0; }
        return (int) floor(($b - $a) / 86400) + 1;
    }

    /** Two inclusive Y-m-d ranges intersect (lexicographic compare is safe for Y-m-d). */
    private function rangesOverlap(string $s1, string $e1, string $s2, string $e2): bool
    {
        return $s1 <= $e2 && $s2 <= $e1;
    }

    /** 'Y-m' → [firstDay, lastDay] as Y-m-d. */
    private function monthBounds(string $month): array
    {
        $start = date('Y-m-01', strtotime($month . '-01'));
        return [$start, date('Y-m-t', strtotime($start))];
    }

    /** Human range label: "3–9 Aug 2026" / "28 Jul – 3 Aug 2026" / cross-year full. */
    private function fmtRange(string $start, string $end): string
    {
        $s = strtotime($start); $e = strtotime($end);
        if ($s === false || $e === false) { return $start . ' – ' . $end; }
        if (date('Y-m', $s) === date('Y-m', $e)) { return date('j', $s) . '–' . date('j M Y', $e); }
        if (date('Y', $s) === date('Y', $e))     { return date('j M', $s) . ' – ' . date('j M Y', $e); }
        return date('j M Y', $s) . ' – ' . date('j M Y', $e);
    }

    /**
     * Compute the payroll row for one employee + month. Returns a flat array the page and the
     * pay/approve steps both consume. `overrides` lets the manager tweak numbers (free-form late
     * deduction, bonuses, allowances) before paying.
     *
     * @param array $overrides ['late_deduction'=>?, 'bonuses'=>?, 'allowances'=>?, 'other'=>?, 'other_desc'=>?, 'base_salary'=>?]
     */
    public function computeRow(int $userId, string $month, array $overrides = []): array
    {
        // Per-request memo. One page load now computes the month TWICE (the grid, then the
        // leave actions), and this is pure computation over ~12 queries per employee. Only
        // the plain (no-override) call is cached, and every write path drops it.
        $memoKey = $overrides ? null : ($userId . '|' . $month);
        if ($memoKey !== null && isset(self::$rowMemo[$memoKey])) {
            return self::$rowMemo[$memoKey];
        }

        $profile = EmployeeProfileModel::with('user')->where('user_id', $userId)->first();
        $fullname = $profile && $profile->user ? $profile->user->fullname
            : (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'Employee');

        $base = $overrides['base_salary'] ?? ($profile->base_salary ?? 0);
        $base = (float) $base;
        $configured = $base > 0;

        // Pay schedule + business-unit tag (guarded — null before the SQL is applied).
        $paySchedule   = $profile?->pay_schedule ?? 'monthly';
        $rateType      = $profile?->rate_type ?? 'monthly';
        $profileBuId   = $profile?->business_unit_id ?? null;

        $startDate = date('Y-m-01', strtotime($month . '-01'));
        $endDate = date('Y-m-t', strtotime($startDate));
        $today = date('Y-m-d');
        $effectiveEnd = ($endDate > $today) ? $today : $endDate;

        // Unified attendance (same numbers the slip + attendance screens use).
        $att = $this->salary->attendanceSummary($userId, $month);
        $workingDays = (int) ($att['working_days'] ?? 0);
        $presentDays = (int) ($att['present_days'] ?? 0);
        $absentDays  = (int) ($att['absent_days'] ?? 0);
        $leaveDays   = (int) ($att['leave_days'] ?? 0);
        $lateMinutes = (int) ($att['late_minutes'] ?? 0);

        // The per-day / per-hour RATE divides by the FULL month's working days (a fixed monthly
        // daily rate), NOT the days elapsed so far. Using elapsed days inflates every deduction
        // mid-month — e.g. 1 absence = base/12 (Rs 2,917) instead of base/27 (Rs 1,296). At month
        // end elapsed == full, so this leaves a month-end payment unchanged; it only corrects the
        // mid-month preview / any mid-month payout. ($workingDays stays elapsed for the attendance
        // context; only the rate divisor changes.)
        $fullMonthWorkingDays = $this->shift->calculateWorkingDays($userId, $startDate, $endDate);
        $rateDivisor = $fullMonthWorkingDays > 0 ? $fullMonthWorkingDays : $workingDays;
        $perDay  = $rateDivisor > 0 ? $base / $rateDivisor : 0.0;
        $perHour = $rateDivisor > 0 ? $base / ($rateDivisor * max(0.1, $this->targetHours())) : 0.0;

        // ── Absent deduction (unapproved absences only; leave + not-needed already excluded).
        $absentDeduction = round($absentDays * $perDay, 2);

        // ── Lateness → leaves, with salary-cut fallbacks (owner policy).
        $buffer = $this->lateBufferMin();
        $step   = $this->lateStepMin();
        $lateHours = $lateMinutes / 60;
        $lateLeaveDeduct = 0;      // whole leaves to remove from the ledger on approve
        $lateComputedCut = 0.0;    // suggested salary cut (all late hours × per-hour)
        $lateFlag = null;          // 'no_leaves' | 'over_step'
        $remainingLeaves = (float) ($this->leave->balance($userId)['remaining'] ?? 0);
        // Judge "has a leave to give up?" against the balance BEFORE this month's own penalty.
        // Once the penalty is taken the balance is one lower, and re-running the rule on that
        // would flip a settled month to "no leaves left" — i.e. the month would start
        // recommending a salary cut because of the very leave it just deducted.
        $lateDecision = $this->leaveDecisions($month)[$userId]['late_penalty'] ?? null;
        if ($lateDecision !== null) {
            $remainingLeaves -= (float) $lateDecision['days'];   // days is negative when applied
        }
        if ($lateMinutes > $step) {
            $lateComputedCut = round($lateHours * $perHour, 2);
            $lateFlag = 'over_step';
        } elseif ($lateMinutes > $buffer) {
            if ($remainingLeaves >= 1) {
                $lateLeaveDeduct = 1;
            } else {
                $lateComputedCut = round($lateHours * $perHour, 2);
                $lateFlag = 'no_leaves';
            }
        }

        // ⭐ A late penalty ALREADY DECIDED for this month (given or waived) must never also
        // become a salary cut. Deducting the leave drops the balance, and the very next
        // recompute would see "no leaves left" and take the money too — the employee would
        // be punished twice for the same lateness, once in leaves and once in cash. (Owner
        // rule: a waived late costs nothing at all — there is no money fallback.)
        // `over_step` is excluded: lateness beyond the cut line is a salary cut by policy and
        // never had a leave to settle in the first place.
        // The RAW recommendation is kept: it is what the Leave-actions panel shows and what a
        // manager flipping a waive back to "deduct" must be able to re-apply. Only the
        // EFFECTIVE figures (what paying would write) collapse to nothing.
        $lateLeaveRecommended = $lateLeaveDeduct;
        $lateDecided = $lateDecision !== null;
        if ($lateDecided && $lateFlag !== 'over_step') {
            $lateLeaveDeduct = 0;
            $lateComputedCut = 0.0;
            $lateFlag = null;
        }

        // The manager may override the salary cut (free-form). Default = computed.
        $lateDeduction = array_key_exists('late_deduction', $overrides) && $overrides['late_deduction'] !== null && $overrides['late_deduction'] !== ''
            ? round((float) $overrides['late_deduction'], 2)
            : $lateComputedCut;

        // ── Overtime → bonus leaves (÷ target hours; whole days only). No pay.
        // The conversion lives in OvertimeService so the attendance/report screens show the
        // SAME number this grants — the formula used to be duplicated here, which is exactly
        // how a display and a payment silently drift apart.
        $otMinutes = $this->ot->overtimeMinutes($userId, $startDate, $effectiveEnd);
        $bonusLeaves = $this->ot->bonusLeaves($otMinutes);

        // ── Open salary advances (unsettled) — auto-deducted at pay, settled on pay.
        // Scoped to THIS month: an advance given for August is recovered from August, never
        // from whichever month happens to be paid first.
        $advances = $this->openAdvances($userId, $month);
        $advanceTotal = round(array_sum(array_column($advances, 'amount')), 2);

        // Unsettled advances belonging to OTHER months. Deliberately NOT in $advanceTotal,
        // deductions or net — they are shown as a chip so month-scoping can never make money
        // that has left the building invisible on the grid.
        $otherOpenAdvanceTotal = round(
            array_sum(array_column($this->openAdvances($userId), 'amount')) - $advanceTotal, 2
        );

        // ── Advance REQUESTS still awaiting a decision. NOT money: no ledger row exists, so these
        // are deliberately kept OUT of $advanceTotal, deductions and net pay. They are surfaced
        // only as an "asked for, not given" chip so a manager doesn't pay twice by accident.
        $pendingRequests = $this->pendingAdvanceRequests($userId);
        $pendingRequestTotal = round(array_sum(array_column($pendingRequests, 'amount')), 2);

        // ── Manual add-ons.
        $bonuses = round((float) ($overrides['bonuses'] ?? 0), 2);
        $allowances = round((float) ($overrides['allowances'] ?? 0), 2);
        $other = round((float) ($overrides['other'] ?? 0), 2);

        $totalDeductions = round($absentDeduction + $lateDeduction + $advanceTotal, 2);
        $net = round($base + $bonuses + $allowances + $other - $totalDeductions, 2);

        $result = [
            'user_id'          => $userId,
            'fullname'         => $fullname,
            'employee_code'    => $profile->employee_code ?? null,
            'designation'      => $profile->designation ?? null,
            'month'            => $month,
            'configured'       => $configured,
            'pay_schedule'     => $paySchedule,
            'rate_type'        => $rateType,
            'business_unit_id' => $this->stampBuId($profileBuId ? (int) $profileBuId : null), // resolved (NF id, never null)
            'bu_code'          => $this->buCodeFor($profileBuId ? (int) $profileBuId : null), // 'NF' | 'KHAAS'
            'base_salary'      => round($base, 2),
            'per_day'          => round($perDay, 2),
            'per_hour'         => round($perHour, 2),
            'working_days'     => $workingDays,
            'present_days'     => $presentDays,
            'absent_days'      => $absentDays,
            'leave_days'       => $leaveDays,
            'absent_deduction' => $absentDeduction,
            'late_minutes'     => $lateMinutes,
            'late_leave_deduct' => $lateLeaveDeduct,       // leaves removed (ledger) on approve
            'late_leave_recommended' => $lateLeaveRecommended, // raw rule output, ignores any decision
            'late_computed_cut' => $lateComputedCut,       // suggested salary cut
            'late_deduction'    => $lateDeduction,          // effective (may be overridden)
            'late_flag'         => $lateFlag,               // null | no_leaves | over_step
            'late_settled'      => $lateDecided,            // this month's late leave is already decided
            'late_buffer_min'   => $buffer,
            'late_step_min'     => $step,
            'overtime_minutes'  => $otMinutes,
            'bonus_leaves'      => $bonusLeaves,            // leaves granted (ledger) on approve
            'advances'          => $advances,
            'advance_total'     => $advanceTotal,
            // Open advances tagged to other months — display only, never part of net pay.
            'other_open_advance_total' => max(0, $otherOpenAdvanceTotal),
            'pending_requests'      => $pendingRequests,      // asked for, NOT given (no money)
            'pending_request_total' => $pendingRequestTotal,  // never part of deductions/net
            'bonuses'           => $bonuses,
            'allowances'        => $allowances,
            'other'             => $other,
            'loan_installment'  => 0,                       // loans PARKED (module broken)
            'total_deductions'  => $totalDeductions,
            'net_salary'        => max(0, $net),
            'net_raw'           => $net,                    // before the max(0) clamp (for the ⚠ negative case)
        ];

        if ($memoKey !== null) {
            self::$rowMemo[$memoKey] = $result;
        }
        return $result;
    }

    /** user|month => computed row (per-request; dropped whenever an input changes). */
    private static array $rowMemo = [];

    /** Drop every cached row for a month (a decision or a payment changed the inputs). */
    private function forgetMonth(string $month): void
    {
        $this->forgetLeaveDecisions($month);
        foreach (array_keys(self::$rowMemo) as $k) {
            if (str_ends_with($k, '|' . $month)) {
                unset(self::$rowMemo[$k]);
            }
        }
    }

    /** Cash + online-bank options for the "pay from" picker (web modal + mobile). */
    public function fundingOptions(): array
    {
        $cash = null;
        try {
            $c = \App\Models\FIN\ConfigModel::getNFCashAccount();
            if ($c) { $cash = ['id' => $c->id, 'name' => $c->account_name]; }
        } catch (\Throwable $e) { /* none */ }

        $banks = [];
        try {
            $banks = DB::table('t_fin_online_receiving_accounts')
                ->where('is_active', 1)->orderBy('sort_order')->orderBy('bank_name')
                ->get(['id', 'name', 'bank_name', 'account_last4'])
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'label' => trim(($b->name ?: $b->bank_name) . ($b->account_last4 ? ' ••' . $b->account_last4 : '')),
                ])->toArray();
        } catch (\Throwable $e) { /* none */ }

        return ['cash' => $cash, 'banks' => $banks];
    }

    /** Set / insert an employee's base salary (audited). Throws on failure. */
    public function setBaseSalary(int $userId, float $newBase, int $actorId): void
    {
        // A running-balance employee's rate carries an effective DATE (past days keep the
        // price they were worked at), so it can never be changed by this flat setter.
        if ($this->isBalanceTracked($userId)) {
            throw new \RuntimeException('This employee is on a running balance — change the rate from their card so you can set the date it applies from.');
        }
        $newBase = round($newBase, 2);
        $existing = DB::table('t_hr_employee_profile')->where('user_id', $userId)->first();
        if ($existing) {
            DB::table('t_hr_employee_profile')->where('user_id', $userId)->update([
                'previous_salary' => $existing->base_salary,
                'base_salary' => $newBase,
                'last_salary_change_date' => now()->toDateString(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('t_hr_employee_profile')->insert([
                'user_id' => $userId,
                'base_salary' => $newBase,
                'salary_effective_date' => now()->toDateString(),
                'last_salary_change_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        \Log::info('Payroll base salary set', ['user_id' => $userId, 'base' => $newBase, 'by' => $actorId]);
    }

    /** Give a mid-month advance (creates + posts a salary_advance). Returns [success, message]. */
    public function giveAdvance(int $userId, float $amount, string $funding, ?int $bankId, ?string $note, int $actorId, ?string $payrollMonth = null, ?string $moneyDate = null): array
    {
        if ($amount < 1) {
            return ['success' => false, 'message' => 'Enter an amount.'];
        }
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        // Which month recovers this, and when the money actually moved. Validated together
        // because they are only allowed to differ in one direction (see resolveAdvanceDates).
        $when = $this->resolveAdvanceDates($userId, $payrollMonth, $moneyDate);
        if (!empty($when['error'])) {
            return ['success' => false, 'message' => $when['error']];
        }
        // Advances don't exist for a running-balance employee: money handed over is a
        // payment against the balance, which is the same cash with an honest name.
        if ($this->isBalanceTracked($userId)) {
            return ['success' => false, 'message' => 'This employee is on a running balance — record a payment instead of an advance.'];
        }
        try {
            $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'salary_advance')->firstOrFail();
            $fundingAcct = $funding === 'online'
                ? \App\Models\FIN\ConfigModel::getOnlineBankAccount()
                : \App\Models\FIN\ConfigModel::getNFCashAccount();
            if (!$fundingAcct) {
                return ['success' => false, 'message' => 'Funding account not found.'];
            }

            // Tag the advance with the employee's business unit so a Khaas employee's
            // advance is bucketed under Khaas on the Expenses page (which reads the
            // request's business_unit_id). NULL = NF, matching the codebase convention.
            $empBuId = null;
            try {
                if ($this->profileHasScheduleCols()) {
                    $empBuId = DB::table('t_hr_employee_profile')->where('user_id', $userId)->value('business_unit_id');
                    $empBuId = $empBuId ? (int) $empBuId : null;
                }
            } catch (\Throwable $e) { /* no tag → NF */ }

            $attrs = [
                'request_number'   => \App\Models\Request\RequestModel::generateRequestNumber(),
                'category_id'      => $category->id,
                'requester_user_id' => $userId,
                'title'            => 'Salary advance',
                'amount'           => round($amount, 2),
                'description'      => $note ?: 'Advance given from Payroll',
                // expense_date = the day the money actually moved (the ledger entry is dated
                // from it, so the books match the bank statement). The month it is RECOVERED
                // from is payroll_month, which is allowed to differ — see resolveAdvanceDates.
                'expense_date'     => $when['money_date'],
                'business_unit_id' => $empBuId,
                'payment_source_account_id' => $fundingAcct->id,
                'receiving_account_id' => $funding === 'online' ? $bankId : null,
                'status'           => \App\Models\Request\RequestModel::STATUS_APPROVED,
                'settlement_status' => 'pending',
                'requires_level_1' => false,
                'requires_level_2' => false,
                'submitted_at'     => now(),
                'completed_at'     => now(),
                'created_by'       => $actorId,
                'updated_by'       => $actorId,
            ];
            // Guarded so the code runs unchanged before payroll_advance_month_sep2026.sql:
            // with no column, the month falls back to expense_date exactly as it used to.
            if (\App\Services\HR\SalaryCostService::hasMonthColumn()) {
                $attrs['payroll_month'] = $when['payroll_month'];
            }
            $req = \App\Models\Request\RequestModel::create($attrs);

            $post = (new \App\Services\FIN\LedgerPostingService())->postSalaryAdvanceFromRequest($req);
            if (empty($post['success'])) {
                throw new \RuntimeException($post['message'] ?? 'Ledger posting failed');
            }
            \Log::info('Payroll advance given', [
                'user_id' => $userId, 'amount' => $amount, 'request_id' => $req->id, 'by' => $actorId,
                'payroll_month' => $when['payroll_month'], 'money_date' => $when['money_date'],
            ]);
            return ['success' => true, 'message' => 'Advance of Rs ' . number_format($amount)
                . ' given for ' . date('F Y', strtotime($when['payroll_month'] . '-01')) . '.'];
        } catch (\Throwable $e) {
            \Log::error('giveAdvance failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not give advance: ' . $e->getMessage()];
        }
    }

    /**
     * Work out the two dates an advance carries, and refuse the combinations that would
     * create money nobody can ever recover. Owner rulings, Sep-2026:
     *
     *   payroll_month — the month whose pay recovers this, and whose salary cost it is.
     *                   Allowed: any PAST month, the CURRENT month, or the NEXT month
     *                   (given forward because this month is already paid). Never further
     *                   out — an advance two months ahead is a loan, not early salary.
     *
     *   money_date    — the day the cash actually left. For a past month the manager is
     *                   asked for it (the transfer really happened back then, the entry is
     *                   just late), and it must fall inside that month. For the current or
     *                   next month the money is moving now, so it is today: the ledger and
     *                   the bank statement agree, and only payroll_month looks forward.
     *
     * Refused: a month whose salary is ALREADY PAID for this employee — the advance could
     * never be deducted from it, so it would sit open forever. The message names the next
     * month that is still open, which is what the owner wants offered instead.
     *
     * @return array{payroll_month?:string,money_date?:string,error?:string}
     */
    private function resolveAdvanceDates(int $userId, ?string $payrollMonth, ?string $moneyDate): array
    {
        $today = now()->startOfDay();
        $curMonth = $today->format('Y-m');

        // No month sent (mobile, or any older client) = the current month, exactly as before.
        $month = trim((string) ($payrollMonth ?: $curMonth));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return ['error' => 'That month is not valid.'];
        }

        $maxMonth = $today->copy()->startOfMonth()->addMonth()->format('Y-m'); // next month only
        if ($month > $maxMonth) {
            return ['error' => 'An advance can only be given for a month up to '
                . date('F Y', strtotime($maxMonth . '-01')) . '. Pick a nearer month.'];
        }

        // Already paid for that month → it can never be recovered from it.
        if ($this->monthIsPaidFor($userId, $month)) {
            $next = $this->nextUnpaidMonthFor($userId, $month, $maxMonth);
            return ['error' => date('F Y', strtotime($month . '-01')) . ' salary is already paid'
                . ' for this employee, so an advance cannot be recovered from it.'
                . ($next ? ' Give it for ' . date('F Y', strtotime($next . '-01')) . ' instead.' : '')];
        }

        // Money date: asked for only when the month is in the past.
        if ($month < $curMonth) {
            if (!$moneyDate) {
                // Nothing supplied (an older client) → the last day of that month, which is
                // the safest in-month date and the modal's own default.
                $moneyDate = date('Y-m-t', strtotime($month . '-01'));
            }
            $d = null;
            try { $d = \Illuminate\Support\Carbon::parse($moneyDate)->startOfDay(); } catch (\Throwable $e) { $d = null; }
            if (!$d) {
                return ['error' => 'That date is not valid.'];
            }
            if ($d->format('Y-m') !== $month) {
                return ['error' => 'The payment date must be inside '
                    . date('F Y', strtotime($month . '-01')) . '.'];
            }
            if ($d->gt($today)) {
                return ['error' => 'The payment date cannot be in the future.'];
            }
            return ['payroll_month' => $month, 'money_date' => $d->toDateString()];
        }

        // Current or next month — the money is moving today, whatever month recovers it.
        return ['payroll_month' => $month, 'money_date' => $today->toDateString()];
    }

    /** Has this employee's salary for $month already been paid? (Either payroll table path.) */
    private function monthIsPaidFor(int $userId, string $month): bool
    {
        try {
            return DB::table('t_hr_payroll_payment')
                ->where('user_id', $userId)
                ->where('pay_month', $month)
                ->where('status', 'paid')
                ->exists();
        } catch (\Throwable $e) {
            return false; // never block a payment on a lookup failure
        }
    }

    /** The first month from $from..$max whose salary is not paid yet (null if none). */
    private function nextUnpaidMonthFor(int $userId, string $from, string $max): ?string
    {
        $m = $from;
        for ($i = 0; $i < 24 && $m <= $max; $i++) {
            if (!$this->monthIsPaidFor($userId, $m)) {
                return $m;
            }
            $m = date('Y-m', strtotime($m . '-01 +1 month'));
        }
        return null;
    }

    /**
     * Employees who may be given a salary advance — the SAME population the Payroll screen
     * shows, monthly AND custom-schedule, resolved through the one visibility rule so the
     * assistant can never offer someone the grid would not.
     *
     * Per employee it also answers the two things a caller must know BEFORE drafting:
     * whether that month's salary is already paid (an advance could never be recovered from
     * it), and whether they are on a running balance (advances don't exist for them — the
     * khata takes payments instead, which is what giveAdvance would refuse).
     *
     * @param  string|null $query   optional name filter (case-insensitive substring)
     * @param  string|null $month   'YYYY-MM' the caller intends; defaults to the current month
     * @return array<int,array{user_id:int,name:string,schedule:string,configured:bool,
     *                         month_paid:bool,balance_tracked:bool,open_advance_total:float}>
     */
    public function advanceEligibleEmployees(?string $query = null, ?string $month = null): array
    {
        $month = $month ?: now()->format('Y-m');
        $payVis = $this->payrollVisibilityMap();
        $customIds = $this->customScheduleUserIds();

        $q = DB::table('t_sys_user')->where('is_active', 1);
        if ($query !== null && trim($query) !== '') {
            $q->where('fullname', 'like', '%' . trim($query) . '%');
        }

        $out = [];
        foreach ($q->orderBy('fullname')->get(['id', 'fullname']) as $u) {
            $uid = (int) $u->id;
            $vis = $payVis[$uid] ?? ['on' => true, 'explicit' => false];
            if (!$vis['on']) {
                continue;   // hidden from Payroll → not offerable
            }
            $isCustom = isset($customIds[$uid]);
            $profile = DB::table('t_hr_employee_profile')->where('user_id', $uid)->first(['base_salary']);
            $configured = $profile && (float) $profile->base_salary > 0;
            // Mirror the grid's own rule: an unconfigured, unflagged employee is not on it.
            if (!$isCustom && !$vis['explicit'] && !$configured) {
                continue;
            }
            $out[] = [
                'user_id'            => $uid,
                'name'               => (string) $u->fullname,
                'schedule'           => $isCustom ? 'custom' : 'monthly',
                'configured'         => (bool) $configured,
                'month_paid'         => $this->monthIsPaidFor($uid, $month),
                'balance_tracked'    => $this->isBalanceTracked($uid),
                'open_advance_total' => round(array_sum(array_column($this->openAdvances($uid), 'amount')), 2),
            ];
        }
        return $out;
    }

    /** Pay a batch of rows; aggregates the per-row results. */
    public function payMany(array $items, string $month, string $funding, ?int $bankId, int $actorId): array
    {
        $paid = 0; $skipped = 0; $failed = 0; $total = 0.0; $details = [];
        foreach ($items as $item) {
            $res = $this->payRow((int) $item['user_id'], $month, [
                'funding' => $funding,
                'bank_id' => $bankId,
                'late_deduction' => $item['late_deduction'] ?? null,
                // Manual take-home override (bypasses attendance deductions + advance settling).
                'net_override' => $item['net_override'] ?? null,
                // Manager bypass toggles (default = apply the recommendation). When on, the
                // matching leave-ledger row is NOT written and the receipt records 0 for it.
                'skip_overtime'  => !empty($item['skip_overtime']),
                'skip_late_leave' => !empty($item['skip_late_leave']),
                // Batch-level choice from the pay modal: settle the leave actions with this
                // payment, or pay the money now and leave them pending for the panel.
                'defer_leave_actions' => !empty($item['defer_leave_actions']),
                'actor_id' => $actorId,
            ]);
            if (!empty($res['success'])) {
                $paid++; $total += (float) ($res['net'] ?? 0);
            } elseif (!empty($res['skipped'])) {
                $skipped++;
            } else {
                $failed++;
            }
            $details[] = ['user_id' => $item['user_id']] + $res;
        }
        return ['paid' => $paid, 'skipped' => $skipped, 'failed' => $failed, 'total' => $total, 'details' => $details];
    }

    // ── Payment status (drives the grid's Paid/Unpaid + double-pay guard) ─────────────

    /** user_id => payment record for a month (empty when the table isn't there yet). */
    public function paidMap(string $month): array
    {
        try {
            return DB::table('t_hr_payroll_payment')->where('pay_month', $month)
                ->get()->keyBy('user_id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function isPaid(int $userId, string $month): bool
    {
        try {
            return DB::table('t_hr_payroll_payment')
                ->where('user_id', $userId)->where('pay_month', $month)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Pay ONE employee for a month. Atomic (own transaction), idempotent (UNIQUE user+month),
     * and it does the four side-effects the policy demands:
     *   1. posts a `salary_payment` ledger entry from the chosen funding account,
     *   2. settles the employee's open salary advances (they were deducted from net),
     *   3. writes the late-penalty (−1) / overtime (+N) leave-ledger rows,
     *   4. records the payment (t_hr_payroll_payment).
     *
     * @param array $opts ['funding'=>'cash'|'online', 'bank_id'=>?int, 'late_deduction'=>?float, 'actor_id'=>int]
     * @return array ['success'=>bool, 'skipped'=>?string, 'net'=>?, 'ledger_id'=>?, 'message'=>?]
     */
    public function payRow(int $userId, string $month, array $opts): array
    {
        if ($this->isPaid($userId, $month)) {
            return ['success' => false, 'skipped' => 'already_paid', 'message' => 'Already paid for this month.'];
        }

        // Custom-schedule employees are paid by date range on the Custom tab, never
        // as a whole month. Both grids already hide them here; this closes the
        // direct-request hole (stale form, mobile, crafted call).
        if ($this->profileHasScheduleCols()) {
            try {
                $sched = DB::table('t_hr_employee_profile')->where('user_id', $userId)->value('pay_schedule');
                if ($sched === 'custom') {
                    return ['success' => false, 'message' => 'This employee is on a custom schedule — pay them from the Custom tab.'];
                }
            } catch (\Throwable $e) { /* treat as monthly */ }
        }

        // Reverse guard: refuse to pay a whole month when custom periods already
        // cover part of it (the employee was custom for part of the month). Keeps a
        // mid-month monthly⇄custom switch safe in both directions.
        $block = $this->monthlyBlockedByCustom($userId, $month);
        if ($block) {
            return ['success' => false, 'message' => $block];
        }

        $override = [];
        if (array_key_exists('late_deduction', $opts) && $opts['late_deduction'] !== null && $opts['late_deduction'] !== '') {
            $override['late_deduction'] = (float) $opts['late_deduction'];
        }
        $row = $this->computeRow($userId, $month, $override);
        if (!$row['configured']) {
            return ['success' => false, 'skipped' => 'no_salary', 'message' => 'No base salary set.'];
        }
        // Manual net override: the manager types the exact take-home to pay, bypassing the
        // attendance-based deductions entirely (e.g. an employee with no attendance). When set,
        // open advances are NOT auto-deducted/settled — the amount is exactly what's paid.
        $manualNet = null;
        if (array_key_exists('net_override', $opts) && $opts['net_override'] !== null && $opts['net_override'] !== '') {
            $manualNet = max(0, round((float) $opts['net_override'], 2));
        }
        $net = $manualNet !== null ? $manualNet : max(0, (float) $row['net_salary']);
        $funding = ($opts['funding'] ?? 'cash') === 'online' ? 'online' : 'cash';
        $bankId = $funding === 'online' ? ($opts['bank_id'] ?? null) : null;
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        $actorId = (int) ($opts['actor_id'] ?? auth()->id() ?? 1);

        // Manager bypass toggles: if on, don't grant/deduct that leave, and freeze 0 into the
        // receipt so the paid-detail + leave history reflect what was actually applied.
        $appliedLateLeave   = !empty($opts['skip_late_leave']) ? 0 : (int) $row['late_leave_deduct'];
        $appliedBonusLeaves = !empty($opts['skip_overtime'])   ? 0 : (int) $row['bonus_leaves'];
        // "Pay the money now, settle the leaves later": the salary is paid exactly as normal
        // but NO leave decision is recorded, so the month stays pending on the Leave-actions
        // panel (and in Attendance) for someone to decide there.
        $deferLeave = !empty($opts['defer_leave_actions']);

        try {
            return DB::transaction(function () use ($userId, $month, $row, $net, $funding, $bankId, $actorId, $appliedLateLeave, $appliedBonusLeaves, $manualNet, $deferLeave) {
                // Funding (source) account — NF Cash, or the single ONLINE ledger account tagged per bank.
                if ($funding === 'online') {
                    $source = \App\Models\FIN\ConfigModel::getOnlineBankAccount();
                    $mode = 'online';
                } else {
                    $source = \App\Models\FIN\ConfigModel::getNFCashAccount();
                    $mode = 'cash';
                }
                if (!$source) {
                    throw new \RuntimeException('Funding account not found.');
                }

                // Employee cash account (resolve by user+category first, else create).
                $empCash = \App\Models\FIN\AccountModel::where('user_id', $userId)
                    ->where('account_category', 'employee_cash')->first();
                if (!$empCash) {
                    $uname = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: ('User ' . $userId);
                    $empCash = \App\Models\FIN\AccountModel::createEmployeeCashAccount($userId, $uname);
                }

                // Post the salary payment. 'salary_payment' is an EXCLUDED employee-cash type, so the
                // BalancePostingService automatically skips the employee leg (salary is a personal
                // payment to them, not company cash they hold) and only decrements the funding account.
                // Net 0 (deductions ≥ salary) disburses nothing, so we don't post a zero-amount entry.
                $ledger = null;
                if ($net > 0) {
                    $ledger = \App\Models\FIN\LedgerModel::create([
                        'transaction_date'   => now(),
                        'transaction_type'   => 'salary_payment',
                        'description'        => 'Salary ' . date('M Y', strtotime($month . '-01')) . ' — ' . $row['fullname'],
                        'from_account_id'    => $source->id,
                        'to_account_id'      => $empCash->id,
                        'amount'             => $net,
                        'mode'               => $mode,
                        'receiving_account_id' => $bankId,
                        'business_unit_id'   => $row['business_unit_id'], // BU tag (inert for HQ; drives Expenses BU split)
                        'approval_status'    => 'approved',
                        'approval_date'      => now(),
                        'approved_by'        => $actorId,
                        'external_source'    => 'payroll',
                        'external_ref_id'    => $month . '/' . $userId,
                        'comments'           => 'Paid from: ' . $source->account_name . ($funding === 'online' ? (' (bank #' . $bankId . ')') : ''),
                        'created_by'         => $actorId,
                    ]);
                    (new \App\Services\FIN\BalancePostingService())->apply($ledger);
                }
                $ledgerId = $ledger ? $ledger->id : null;

                // Settle the advances that were deducted from this net. On a MANUAL override the
                // amount is exactly what's paid (advances weren't deducted), so leave them open.
                //
                // The update is GUARDED on the advance still being approved-and-unsettled, and we
                // insist on exactly one affected row. The advance list was computed before this
                // transaction, so between then and now it could have been voided (owner) or
                // settled (another manager on a stale grid). Either way the net we just paid is
                // wrong, so we abort the whole payment rather than settle a cancelled advance or
                // double-deduct one.
                $settledIds = [];
                if ($manualNet === null) {
                    foreach ($row['advances'] as $a) {
                        if (empty($a['request_id'])) {
                            continue;
                        }
                        $affected = DB::table('t_req_master')
                            ->where('id', $a['request_id'])
                            ->where('status', 'approved')
                            ->where(function ($q) {
                                $q->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'settled');
                            })
                            ->update([
                                'settlement_status' => 'settled',
                                'settled_at'        => now(),
                                'settled_by'        => $actorId,
                                'settlement_notes'  => 'Recovered from ' . date('M Y', strtotime($month . '-01')) . ' salary',
                                'settlement_transaction_id' => $ledgerId,
                                'updated_at'        => now(),
                            ]);
                        if ($affected !== 1) {
                            throw new \RuntimeException('the advances changed while this page was open — refresh payroll and pay again');
                        }
                        $settledIds[] = $a['request_id'];
                    }
                }

                // Leave-ledger side effects (idempotent per user+month+source via effective_date = 1st).
                //
                // A BYPASSED item now writes a 0-day row instead of nothing: the bypass is a
                // real decision ("waived"), and recording it is what stops the month sitting
                // on the Leave-actions panel as "pending" forever after it was paid. A month
                // that recommends nothing writes nothing at all.
                $effDate = $month . '-01';
                if (!$deferLeave) {
                    if ((int) $row['late_leave_deduct'] > 0) {
                        $this->grantOnce($userId, -1 * $appliedLateLeave, 'late_penalty',
                            $this->leaveActionReason('late_penalty', $month), $effDate, $actorId);
                    }
                    if ((int) $row['bonus_leaves'] > 0) {
                        $this->grantOnce($userId, $appliedBonusLeaves, 'overtime',
                            $this->leaveActionReason('overtime', $month), $effDate, $actorId);
                    }
                }

                // Stamp any manager bypass onto the receipt so a paid month explains itself later
                // (0 bonus_leaves could mean "no overtime" OR "overtime bypassed" — this disambiguates).
                $noteBits = [];
                if ($deferLeave && ((int) $row['bonus_leaves'] > 0 || (int) $row['late_leave_deduct'] > 0)) {
                    $noteBits[] = 'Leave actions left pending (decided separately)';
                }
                if (!$deferLeave && (int) $row['bonus_leaves'] > 0 && $appliedBonusLeaves === 0) {
                    $noteBits[] = 'OT bonus (+' . (int) $row['bonus_leaves'] . ' leave) bypassed';
                }
                if ((int) $row['late_leave_deduct'] > 0 && $appliedLateLeave === 0) {
                    $noteBits[] = 'Late -1 leave waived';
                }
                // A manual override pays a flat amount; freeze the deduction/advance figures to 0
                // on the receipt so it reflects what actually happened (not the bypassed calc).
                if ($manualNet !== null) {
                    $noteBits[] = 'Manual amount set by manager (attendance deductions bypassed)';
                }
                $notes = $noteBits ? implode('; ', $noteBits) : null;

                // Record the payment (blocks double-pay; drives the grid status).
                $insert = [
                    'user_id'          => $userId,
                    'pay_month'        => $month,
                    'base_salary'      => $row['base_salary'],
                    'working_days'     => $row['working_days'],
                    'present_days'     => $row['present_days'],
                    'absent_days'      => $row['absent_days'],
                    'leave_days'       => $row['leave_days'],
                    'absent_deduction' => $manualNet !== null ? 0 : $row['absent_deduction'],
                    'late_minutes'     => $row['late_minutes'],
                    'late_deduction'   => $manualNet !== null ? 0 : $row['late_deduction'],
                    'late_leave_deduct' => $appliedLateLeave,
                    'bonus_leaves'     => $appliedBonusLeaves,
                    'advance_total'    => $manualNet !== null ? 0 : $row['advance_total'],
                    'net_salary'       => $net,
                    'funding'          => $funding,
                    'bank_id'          => $bankId,
                    'ledger_id'        => $ledgerId,
                    'status'           => 'paid',
                    'notes'            => $notes,
                    'paid_at'          => now(),
                    'paid_by'          => $actorId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
                // Monthly rows carry an empty period_key (so the widened UNIQUE key is
                // byte-equivalent to the old user+month guard) + the stamped BU.
                if ($this->payrollHasPeriodCols()) {
                    $insert['period_start']     = null;
                    $insert['period_end']       = null;
                    $insert['period_key']       = '';
                    $insert['business_unit_id'] = $row['business_unit_id'];
                }
                DB::table('t_hr_payroll_payment')->insert($insert);

                // Decisions just changed — the next computeRow in this same request (a batch
                // pay walks several) must not read a stale "pending".
                $this->forgetMonth($month);

                return ['success' => true, 'net' => $net, 'ledger_id' => $ledgerId, 'settled_advances' => $settledIds];
            });
        } catch (\Throwable $e) {
            \Log::error('Payroll payRow failed', ['user_id' => $userId, 'month' => $month, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not pay: ' . $e->getMessage()];
        }
    }

    /**
     * "Staff Salaries" expense reimbursements per user for a month (the OLD way the team was paid).
     * Used only as a double-pay WARNING on the grid — it never changes any number.
     * Keyed by user_id => {total, cnt}.
     */
    private function staffSalaryExpenses(string $month): array
    {
        try {
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            return DB::table('t_req_master')
                ->where('title', 'like', '%Staff Salar%')
                ->whereIn('status', ['approved', 'completed'])
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('expense_date', [$start, $end])
                      ->orWhere(function ($q2) use ($start, $end) {
                          $q2->whereNull('expense_date')->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59']);
                      });
                })
                ->whereNotNull('requester_user_id')
                ->groupBy('requester_user_id')
                ->selectRaw('requester_user_id, SUM(amount) as total, COUNT(*) as cnt')
                ->get()->keyBy('requester_user_id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The individual "Staff Salaries" expense records behind the double-pay warning for a
     * user+month (the aggregate that staffSalaryExpenses sums). Drives the clickable chip.
     */
    public function staffExpenseDetail(int $userId, string $month): array
    {
        try {
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            return DB::table('t_req_master')
                ->where('title', 'like', '%Staff Salar%')
                ->whereIn('status', ['approved', 'completed'])
                ->where('requester_user_id', $userId)
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('expense_date', [$start, $end])
                      ->orWhere(function ($q2) use ($start, $end) {
                          $q2->whereNull('expense_date')->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59']);
                      });
                })
                ->orderBy('expense_date')
                ->get(['id', 'request_number', 'title', 'amount', 'status', 'expense_category', 'expense_date', 'created_at'])
                ->map(fn ($r) => [
                    'id'             => $r->id,
                    'request_number' => $r->request_number,
                    'title'          => $r->title,
                    'category'       => $r->expense_category,
                    'amount'         => (float) $r->amount,
                    'date'           => $r->expense_date ? substr((string) $r->expense_date, 0, 10) : substr((string) $r->created_at, 0, 10),
                    'status'         => $r->status,
                ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Insert a leave-grant row only if one for this user+source+effective_date+reason doesn't
     * already exist. The reason is part of the key because manager bonus ADJUSTMENTS (attendance
     * grant-leave, kind=bonus) also write source='overtime' rows — payroll's own reasons are
     * deterministic per month ("Overtime bonus Jul 2026"), so a retry still dedupes, but a
     * manual adjustment that happens to land on the 1st can't block the month's real grant.
     */
    private function grantOnce(int $userId, int $days, string $source, string $reason, string $effectiveDate, int $actorId): void
    {
        $this->recordLeaveDecision($userId, $days, $source, $reason, $effectiveDate, $actorId, false);
    }

    /**
     * ⭐ THE one writer of a payroll leave-grant row — both the pay flow and the
     * Leave-actions panel go through here, which is what makes the two surfaces incapable
     * of double-applying the same month.
     *
     * `$allowChange` is the difference between them:
     *   false (pay)   — a row already exists → leave it exactly as it is. Strict idempotency:
     *                   a retried payment, or a month already settled from the panel, writes
     *                   nothing and cannot overwrite a manager's deliberate decision.
     *   true  (panel) — a manager is deciding, so an existing row is UPDATED in place
     *                   (waive ⇄ give), never duplicated. `created_by` moves to whoever
     *                   made the current call, because that is who owns the decision now.
     *
     * `$days` may legitimately be 0: that is a recorded WAIVE, and it deliberately occupies
     * the dedupe key so the action reads "waived" instead of drifting back to "pending".
     */
    private function recordLeaveDecision(int $userId, int $days, string $source, string $reason, string $effectiveDate, int $actorId, bool $allowChange): string
    {
        $existing = DB::table('t_hr_leave_grant')
            ->where('user_id', $userId)->where('source', $source)
            ->whereDate('effective_date', $effectiveDate)
            ->where('reason', $reason)
            ->first(['id', 'days']);

        if ($existing) {
            if (!$allowChange || (float) $existing->days === (float) $days) {
                return 'unchanged';
            }
            DB::table('t_hr_leave_grant')->where('id', $existing->id)->update([
                'days'       => $days,
                'created_by' => $actorId,
                'updated_at' => now(),
            ]);
            return 'changed';
        }

        DB::table('t_hr_leave_grant')->insert([
            'user_id'        => $userId,
            'days'           => $days,
            'reason'         => $reason,
            'source'         => $source,
            'effective_date' => $effectiveDate,
            'created_by'     => $actorId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        return 'created';
    }

    /**
     * Unsettled approved salary advances for a user (oldest first).
     *
     * Carries the PROVENANCE of each advance (funding account, WHICH BANK, who gave it, the note)
     * so the drill-down can show WHICH advance is which — a manager voiding a wrong one must be
     * able to tell two same-amount advances apart before acting. Three extra lookups, not N+1.
     *
     * The bank matters more than the chart account: 'Online Bank' is a SINGLE account and the real
     * bank is only a tag (receiving_account_id). A void restores that bank by dropping the ledger
     * row out of BankBalanceService's approved-rows sum, so the reviewer must see which bank will
     * move before deciding. Legacy advances predating per-bank tracking have no tag — they stay
     * null and the UI says so rather than naming a bank it doesn't know.
     */
    private function openAdvances(int $userId, ?string $month = null): array
    {
        try {
            $rows = RequestModel::where('requester_user_id', $userId)
                ->whereHas('category', fn ($q) => $q->where('category_code', 'salary_advance'))
                ->where('status', 'approved')
                ->where(function ($q) {
                    $q->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'settled');
                })
                // Month-scoped (Sep-2026): an advance belongs to ONE payroll month and can only
                // be recovered from that month's pay. Before this, every open advance came off
                // whichever month was paid FIRST — so paying September before August silently
                // moved August's advance onto September. Uses the same month expression as the
                // reporting engine, so what a month is charged is exactly what it recovers.
                ->when($month !== null, fn ($q) => $q->whereRaw(
                    \App\Services\HR\SalaryCostService::monthExpr('t_req_master') . ' = ?', [$month]
                ))
                ->orderBy('created_at', 'asc')
                ->get(['id', 'amount', 'request_number', 'created_at', 'description',
                       'payment_source_account_id', 'receiving_account_id', 'created_by',
                       'ledger_transaction_id']);
            if ($rows->isEmpty()) {
                return [];
            }

            $accts = collect();
            $ids = $rows->pluck('payment_source_account_id')->filter()->unique()->all();
            if ($ids) {
                $accts = DB::table('t_fin_accounts')->whereIn('id', $ids)
                    ->get(['id', 'account_name', 'account_category'])->keyBy('id');
            }

            // Same label shape the paid-period receipts use ("Meezan Bank Limited ••4237") so the
            // advance drill and the salary receipt name a bank identically.
            $bankLabels = [];
            $bankIds = $rows->pluck('receiving_account_id')->filter()->unique()->all();
            if ($bankIds) {
                $bankLabels = DB::table('t_fin_online_receiving_accounts')->whereIn('id', $bankIds)
                    ->get(['id', 'name', 'bank_name', 'account_last4'])
                    ->mapWithKeys(fn ($b) => [$b->id => trim(($b->name ?: $b->bank_name)
                        . ($b->account_last4 ? ' ••' . $b->account_last4 : ''))])
                    ->toArray();
            }
            $userNames = [];
            $uids = $rows->pluck('created_by')->filter()->unique()->all();
            if ($uids) {
                $userNames = DB::table('t_sys_user')->whereIn('id', $uids)
                    ->pluck('fullname', 'id')->toArray();
            }

            return $rows->map(fn ($r) => [
                'request_id' => $r->id,
                'request_number' => $r->request_number,
                'amount' => (float) ($r->amount ?? 0),
                'date' => $r->created_at ? substr((string) $r->created_at, 0, 10) : null,
                'source' => $r->payment_source_account_id
                    ? ($accts[$r->payment_source_account_id]->account_name ?? null) : null,
                // Online-funded rows name the actual bank; cash rows have none by design.
                'is_online' => $r->payment_source_account_id
                    ? (($accts[$r->payment_source_account_id]->account_category ?? null)
                        === \App\Models\FIN\AccountModel::CATEGORY_BANK)
                    : false,
                'bank' => $r->receiving_account_id
                    ? ($bankLabels[$r->receiving_account_id] ?? ('Bank #' . $r->receiving_account_id))
                    : null,
                'given_by' => $r->created_by ? ($userNames[$r->created_by] ?? null) : null,
                'note' => $r->description ?: null,
                // Only a POSTED advance can be voided (the void restores its ledger row).
                'voidable' => (bool) $r->ledger_transaction_id,
            ])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Salary-advance requests still WAITING for a decision — the ones an employee raised from the
     * mobile app (or a manager entered) that were never approved, so NO money has moved and no
     * ledger row exists. These are NOT advances yet; they are asks.
     *
     * Deliberately requires ledger_transaction_id IS NULL: a request that somehow reached
     * 'pending' with money already posted is not something this queue should offer to pay again.
     *
     * @return array one entry per open request, oldest first
     */
    public function pendingAdvanceRequests(?int $userId = null): array
    {
        try {
            $q = RequestModel::whereHas('category', fn ($c) => $c->where('category_code', 'salary_advance'))
                ->where('status', RequestModel::STATUS_PENDING)
                ->whereNull('ledger_transaction_id');
            if ($userId) {
                $q->where('requester_user_id', $userId);
            }
            $rows = $q->orderBy('created_at', 'asc')
                ->get(['id', 'request_number', 'amount', 'created_at', 'description',
                       'requester_user_id', 'created_by']);
            if ($rows->isEmpty()) {
                return [];
            }

            $ids = $rows->pluck('requester_user_id')->merge($rows->pluck('created_by'))
                ->filter()->unique()->all();
            $names = $ids ? DB::table('t_sys_user')->whereIn('id', $ids)->pluck('fullname', 'id')->toArray() : [];
            $active = $ids ? DB::table('t_sys_user')->whereIn('id', $ids)->pluck('is_active', 'id')->toArray() : [];
            // An employee on a running balance takes payments, not advances — approving one
            // is refused server-side, so the sheet must offer Reject only and say why.
            $tracked = $this->balanceTrackedUserIds();
            $today = time();

            return $rows->map(function ($r) use ($names, $today, $tracked, $active) {
                $date = $r->created_at ? substr((string) $r->created_at, 0, 10) : null;
                $age = $date ? (int) floor(($today - strtotime($date)) / 86400) : 0;
                return [
                    'request_id'     => $r->id,
                    'request_number' => $r->request_number,
                    'user_id'        => (int) $r->requester_user_id,
                    'fullname'       => $names[$r->requester_user_id] ?? 'Employee',
                    'amount'         => (float) ($r->amount ?? 0),
                    'date'           => $date,
                    'age_days'       => $age,
                    'note'           => $r->description ?: null,
                    // Who typed it: the employee themselves (mobile self-request) or a manager.
                    'self_requested' => (int) $r->created_by === (int) $r->requester_user_id,
                    'raised_by'      => $names[$r->created_by] ?? null,
                    // Why this one may not be payable: they're on a running balance, or they
                    // have left the company. Both are Reject-only.
                    'balance_tracked' => isset($tracked[(int) $r->requester_user_id]),
                    'employee_active' => ((int) ($active[$r->requester_user_id] ?? 1)) === 1,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Page-level summary of everything still awaiting a decision — the banner and the
     * strip card both read THIS, so neither can disagree with the review sheet.
     *
     * ⚠ It deliberately counts EVERY pending request, not just the ones belonging to
     * employees on the monthly grid. Custom-schedule staff, people who have left, and
     * anyone hidden from Payroll all raise requests too, and those are exactly the ones
     * that sit forgotten — the card used to count only grid rows and so read "2" while
     * the sheet listed 6.
     */
    public function pendingAdvanceSummary(): array
    {
        $rows = $this->pendingAdvanceRequests();
        if (empty($rows)) {
            return ['count' => 0, 'total' => 0.0, 'oldest_days' => 0, 'oldest_name' => null, 'blocked' => 0];
        }
        $oldest = $rows[0];
        $blocked = 0;
        foreach ($rows as $r) {
            if ($r['age_days'] > $oldest['age_days']) { $oldest = $r; }
            if (!empty($r['balance_tracked']) || empty($r['employee_active'])) { $blocked++; }
        }
        return [
            'count'       => count($rows),
            'total'       => round(array_sum(array_column($rows, 'amount')), 2),
            'oldest_days' => (int) $oldest['age_days'],
            'oldest_name' => $oldest['fullname'],
            'blocked'     => $blocked,   // can only be rejected (left, or on a running balance)
        ];
    }

    /**
     * APPROVE a pending advance request and pay it in one step — the money leaves the chosen
     * account immediately, exactly as if it had been given via "+ advance".
     *
     * The funding choice (cash / which bank) is made HERE at approval time, not when the employee
     * asked, because the employee has no business choosing which company account pays them. We
     * stamp payment_source_account_id + receiving_account_id BEFORE posting so the ledger row and
     * the per-bank tag are correct, then post through the same LedgerPostingService every other
     * advance uses.
     */
    public function approveAdvanceRequest(int $requestId, string $funding, ?int $bankId, int $actorId): array
    {
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        try {
            return DB::transaction(function () use ($requestId, $funding, $bankId, $actorId) {
                $req = RequestModel::with('category')->lockForUpdate()->find($requestId);
                if (!$req) {
                    return ['success' => false, 'message' => 'Request not found.'];
                }
                if (!$req->category || $req->category->category_code !== 'salary_advance') {
                    return ['success' => false, 'message' => 'That request is not a salary advance.'];
                }
                if ($req->status !== RequestModel::STATUS_PENDING) {
                    return ['success' => false, 'message' => 'This request is already ' . $req->status . '.'];
                }
                if (!empty($req->ledger_transaction_id)) {
                    return ['success' => false, 'message' => 'This request already has a ledger entry — it has been paid.'];
                }
                if ((float) $req->amount <= 0) {
                    return ['success' => false, 'message' => 'This request has no amount — reject it instead.'];
                }
                // Same rule as "+ advance": a running-balance employee takes payments, not
                // advances, so approving one here would create a debt the khata can't see.
                if ($this->isBalanceTracked((int) $req->requester_user_id)) {
                    return ['success' => false, 'message' => 'This employee is on a running balance — record a payment on their card instead, then reject this request.'];
                }

                $fundingAcct = $funding === 'online'
                    ? \App\Models\FIN\ConfigModel::getOnlineBankAccount()
                    : \App\Models\FIN\ConfigModel::getNFCashAccount();
                if (!$fundingAcct) {
                    return ['success' => false, 'message' => 'Funding account not found.'];
                }

                // Business unit follows the EMPLOYEE (same rule as giveAdvance) so a Khaas
                // employee's advance lands in the Khaas expense bucket.
                $empBuId = null;
                try {
                    if ($this->profileHasScheduleCols()) {
                        $empBuId = DB::table('t_hr_employee_profile')->where('user_id', $req->requester_user_id)
                            ->value('business_unit_id');
                        $empBuId = $empBuId ? (int) $empBuId : null;
                    }
                } catch (\Throwable $e) { /* no tag → NF */ }

                $req->payment_source_account_id = $fundingAcct->id;
                $req->receiving_account_id = $funding === 'online' ? $bankId : null;
                $req->business_unit_id = $empBuId;
                // Payroll approval is a single owner-level act, exactly like "+ advance" (which
                // creates advances with no approval levels at all). Mark both levels satisfied so
                // the row's shape matches a payroll-given advance and it can't re-enter any queue.
                $req->requires_level_1 = false;
                $req->requires_level_2 = false;
                $req->level_1_status = RequestModel::APPROVAL_STATUS_APPROVED;
                $req->level_2_status = RequestModel::APPROVAL_STATUS_APPROVED;
                $req->status = RequestModel::STATUS_APPROVED;
                $req->settlement_status = 'pending';   // recovered from the next salary
                $req->completed_at = now();
                // ⭐ Re-stamp the dates to the APPROVAL, not the ask. The mobile app stamps
                // expense_date when the employee raises the request, so a 22-day-old August
                // request approved in September would otherwise tag August — a month that may
                // already be paid, leaving an advance nothing can ever recover. Money moves
                // today, so today is both the money date and the month that recovers it.
                // The original ask date is preserved in created_at.
                $req->expense_date = now()->toDateString();
                if (\App\Services\HR\SalaryCostService::hasMonthColumn()) {
                    $approveMonth = now()->format('Y-m');
                    // If this month is already paid for them, roll to the next open month so
                    // approving can never create an unrecoverable advance.
                    if ($this->monthIsPaidFor((int) $req->requester_user_id, $approveMonth)) {
                        $approveMonth = $this->nextUnpaidMonthFor(
                            (int) $req->requester_user_id,
                            $approveMonth,
                            now()->startOfMonth()->addMonth()->format('Y-m')
                        ) ?: $approveMonth;
                    }
                    $req->payroll_month = $approveMonth;
                }
                // postSalaryAdvanceFromRequest copies updated_by into ledger.approved_by.
                $req->updated_by = $actorId;
                $req->save();

                $post = (new \App\Services\FIN\LedgerPostingService())->postSalaryAdvanceFromRequest($req);
                if (empty($post['success'])) {
                    throw new \RuntimeException($post['message'] ?? 'Ledger posting failed');
                }

                \Log::info('Payroll approved advance request', [
                    'request_id' => $req->id, 'request_number' => $req->request_number,
                    'user_id' => $req->requester_user_id, 'amount' => $req->amount,
                    'funding' => $funding, 'bank_id' => $bankId, 'by' => $actorId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Approved — Rs ' . number_format((float) $req->amount)
                        . ' paid from ' . $fundingAcct->account_name
                        . '. It will be deducted from the next salary.',
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('approveAdvanceRequest failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not approve: ' . $e->getMessage()];
        }
    }

    /** REJECT a pending advance request. No money has moved, so this only closes the request. */
    public function rejectAdvanceRequest(int $requestId, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Please type a reason.'];
        }
        try {
            return DB::transaction(function () use ($requestId, $reason, $actorId) {
                $req = RequestModel::with('category')->lockForUpdate()->find($requestId);
                if (!$req) {
                    return ['success' => false, 'message' => 'Request not found.'];
                }
                if (!$req->category || $req->category->category_code !== 'salary_advance') {
                    return ['success' => false, 'message' => 'That request is not a salary advance.'];
                }
                if ($req->status !== RequestModel::STATUS_PENDING) {
                    return ['success' => false, 'message' => 'This request is already ' . $req->status . '.'];
                }
                // Guard: never "reject" something that already moved money.
                if (!empty($req->ledger_transaction_id)) {
                    return ['success' => false, 'message' => 'This request already has a ledger entry — void it instead.'];
                }

                $actorName = DB::table('t_sys_user')->where('id', $actorId)->value('fullname') ?: ('User ' . $actorId);
                $req->status = RequestModel::STATUS_REJECTED;
                $req->level_1_status = RequestModel::APPROVAL_STATUS_REJECTED;
                $req->level_2_status = RequestModel::APPROVAL_STATUS_REJECTED;
                $req->settlement_status = 'not_required';
                $req->rejection_reason = 'Rejected by ' . $actorName . ' on ' . now()->format('Y-m-d H:i:s') . ' — ' . $reason;
                $req->completed_at = now();
                $req->updated_by = $actorId;
                $req->save();

                \Log::info('Payroll rejected advance request', [
                    'request_id' => $req->id, 'user_id' => $req->requester_user_id,
                    'amount' => $req->amount, 'by' => $actorId, 'reason' => $reason,
                ]);
                return ['success' => true, 'message' => 'Request rejected. No money was moved.'];
            });
        } catch (\Throwable $e) {
            \Log::error('rejectAdvanceRequest failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not reject: ' . $e->getMessage()];
        }
    }

    /**
     * VOID a wrongly-given salary advance (owner action — permission-gated in the controller).
     *
     * Restores the money through the SAME engine that posted it (BalancePostingService::reverse,
     * idempotent, locks the accounts). Only the FUNDING account moves: 'salary_advance' is an
     * EXCLUDED employee-cash type, so no employee's cash balance shifts in either direction —
     * posting and voiding are exact mirrors.
     *
     * The ledger row is KEPT and marked REVERSED (audit trail), mirroring the expense-delete
     * convention; the request becomes 'cancelled', which removes it from every payroll and
     * expense surface on web AND mobile — they all filter status='approved', so no client
     * change is needed anywhere.
     *
     * ⛔ A SETTLED advance is NEVER voidable. Its money story is already closed by the salary
     * that recovered it (or a manual store settle), and `settlement_transaction_id` on a
     * payroll-settled advance points at the WHOLE MONTH'S salary_payment row — reversing that
     * would un-post an entire salary. This method never reads or touches that column; the
     * guards below refuse before it could ever matter.
     */
    public function voidAdvance(int $requestId, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Please type why this advance is being voided.'];
        }
        try {
            return DB::transaction(function () use ($requestId, $reason, $actorId) {
                $req = RequestModel::with('category')->lockForUpdate()->find($requestId);
                if (!$req) {
                    return ['success' => false, 'message' => 'Advance not found.'];
                }
                if (!$req->category || $req->category->category_code !== 'salary_advance') {
                    return ['success' => false, 'message' => 'That request is not a salary advance.'];
                }
                if ($req->status !== RequestModel::STATUS_APPROVED) {
                    return ['success' => false, 'message' => 'Only an active advance can be voided — this one is already ' . $req->status . '.'];
                }
                if ((string) $req->settlement_status === 'settled') {
                    return ['success' => false, 'message' => 'This advance was already settled ('
                        . ($req->settlement_notes ?: 'recovered from salary') . ') — it can no longer be voided.'];
                }
                // Defensive: a settlement row exists even though the flag doesn't say 'settled'.
                // Never unwind that here (it may be a shared salary row) — refuse and let a human look.
                if (!empty($req->settlement_transaction_id)) {
                    return ['success' => false, 'message' => 'This advance already has a settlement transaction — it cannot be voided automatically.'];
                }
                if (empty($req->ledger_transaction_id)) {
                    return ['success' => false, 'message' => 'This advance has no ledger entry linked, so there is nothing to restore. Please check it before changing anything.'];
                }

                $ledger = \App\Models\FIN\LedgerModel::lockForUpdate()->find($req->ledger_transaction_id);
                if (!$ledger) {
                    return ['success' => false, 'message' => 'The ledger entry for this advance is missing — please check it before changing anything.'];
                }
                if ($ledger->approval_status !== \App\Models\FIN\LedgerModel::STATUS_APPROVED) {
                    return ['success' => false, 'message' => 'The ledger entry for this advance is already ' . $ledger->approval_status . ' — nothing to restore.'];
                }

                $actorName = DB::table('t_sys_user')->where('id', $actorId)->value('fullname') ?: ('User ' . $actorId);
                $stamp = 'VOIDED by ' . $actorName . ' on ' . now()->format('Y-m-d H:i:s') . ' — Reason: ' . $reason;
                $restoredTo = \App\Models\FIN\AccountModel::find($ledger->from_account_id)?->account_name;
                // Name the tagged bank instead of the shared 'Online Bank' account: that per-bank
                // figure is what actually moves (the row drops out of BankBalanceService's sum
                // once it is no longer approved), so the confirmation must match what they'll see.
                if ($ledger->receiving_account_id) {
                    $bank = DB::table('t_fin_online_receiving_accounts')
                        ->where('id', $ledger->receiving_account_id)
                        ->first(['name', 'bank_name', 'account_last4']);
                    if ($bank) {
                        $restoredTo = trim(($bank->name ?: $bank->bank_name)
                            . ($bank->account_last4 ? ' ••' . $bank->account_last4 : ''));
                    }
                }
                $amount = (float) $ledger->amount;

                // 1. Money back, through the canonical engine.
                (new \App\Services\FIN\BalancePostingService())->reverse($ledger);

                // 2. Keep the row, mark it reversed (audit).
                $ledger->approval_status = \App\Models\FIN\LedgerModel::STATUS_REVERSED;
                $ledger->comments = ($ledger->comments ? $ledger->comments . "\n" : '') . $stamp;
                $ledger->save();

                // 3. Retire the request. 'not_required' keeps a voided advance out of every
                //    unsettled/settlement queue (it will never be recovered from a salary).
                $req->status = RequestModel::STATUS_CANCELLED;
                $req->settlement_status = 'not_required';
                $req->rejection_reason = $stamp;
                $req->updated_by = $actorId;
                $req->save();

                \Log::info('Salary advance voided', [
                    'request_id' => $req->id, 'request_number' => $req->request_number,
                    'amount' => $amount, 'ledger_id' => $ledger->id,
                    'user_id' => $req->requester_user_id, 'by' => $actorId, 'reason' => $reason,
                ]);

                return [
                    'success' => true,
                    'message' => 'Advance ' . $req->request_number . ' voided — Rs ' . number_format($amount)
                        . ' returned to ' . ($restoredTo ?: 'the funding account') . '.',
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('voidAdvance failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not void this advance: ' . $e->getMessage()];
        }
    }

    /** Compute every eligible employee's row for a month (configured-salary employees + advances). */
    public function computeMonth(string $month): array
    {
        // Active users in name order; payroll visibility resolved per the effective rule
        // (explicit "Show in Salary" flag, else attendance visibility).
        $users = DB::table('t_sys_user as u')
            ->where('u.is_active', 1)
            ->orderBy('u.fullname')
            ->pluck('u.id');
        $payVis = $this->payrollVisibilityMap();

        $paid = $this->paidMap($month);
        $staffExp = $this->staffSalaryExpenses($month);
        // Custom-schedule employees are paid by date-range on the Custom tab — keep them
        // off the monthly grid entirely. Empty before the SQL is applied → no exclusion.
        $customIds = $this->customScheduleUserIds();

        // Lookups for the paid-detail view (bank names + who paid), one query each.
        $bankLabels = [];
        $paidByNames = [];
        try {
            $bankIds = array_filter(array_map(fn ($p) => $p->bank_id ?? null, $paid));
            if ($bankIds) {
                $bankLabels = DB::table('t_fin_online_receiving_accounts')->whereIn('id', $bankIds)
                    ->get(['id', 'name', 'bank_name', 'account_last4'])
                    ->mapWithKeys(fn ($b) => [$b->id => trim(($b->name ?: $b->bank_name) . ($b->account_last4 ? ' ••' . $b->account_last4 : ''))])
                    ->toArray();
            }
            $payerIds = array_filter(array_map(fn ($p) => $p->paid_by ?? null, $paid));
            if ($payerIds) {
                $paidByNames = DB::table('t_sys_user')->whereIn('id', $payerIds)->pluck('fullname', 'id')->toArray();
            }
        } catch (\Throwable $e) { /* detail lookups are cosmetic */ }

        $rows = [];
        foreach ($users as $uid) {
            $uid = (int) $uid;
            if (isset($customIds[$uid])) { continue; }
            $vis = $payVis[$uid] ?? ['on' => true, 'explicit' => false];
            if (!$vis['on']) { continue; }
            try {
                $row = $this->computeRow($uid, $month);
                // Show the row when: the owner explicitly put them on Payroll (even with no
                // salary yet, so "＋ Set salary" is reachable), OR they have a salary / open advance.
                if ($vis['explicit'] || $row['configured'] || $row['advance_total'] > 0) {
                    $p = $paid[(int) $uid] ?? null;
                    $row['paid'] = $p !== null;
                    $row['paid_net'] = $p ? (float) $p->net_salary : null;
                    $row['paid_funding'] = $p ? $p->funding : null;
                    $row['paid_at'] = $p ? (string) $p->paid_at : null;
                    // The FROZEN figures that were actually paid (the live row recomputes daily,
                    // this is the receipt): drives the click-to-view payment detail.
                    $row['paid_detail'] = $p ? [
                        'net'               => (float) $p->net_salary,
                        'base'              => (float) $p->base_salary,
                        'working_days'      => (int) $p->working_days,
                        'present_days'      => (int) $p->present_days,
                        'absent_days'       => (int) $p->absent_days,
                        'leave_days'        => (int) $p->leave_days,
                        'absent_deduction'  => (float) $p->absent_deduction,
                        'late_minutes'      => (int) $p->late_minutes,
                        'late_deduction'    => (float) $p->late_deduction,
                        'late_leave_deduct' => (int) $p->late_leave_deduct,
                        'bonus_leaves'      => (int) $p->bonus_leaves,
                        'advance_total'     => (float) $p->advance_total,
                        'notes'             => $p->notes ?? null,
                        'funding'           => $p->funding,
                        'bank_label'        => $p->bank_id ? ($bankLabels[$p->bank_id] ?? ('Bank #' . $p->bank_id)) : null,
                        'ledger_id'         => $p->ledger_id,
                        'paid_at'           => (string) $p->paid_at,
                        'paid_by_name'      => $p->paid_by ? ($paidByNames[$p->paid_by] ?? null) : null,
                    ] : null;
                    // Double-pay guardrail: this employee already got a "Staff Salaries" expense
                    // reimbursement this month (the OLD way of paying). Warn before paying again.
                    $se = $staffExp[(int) $uid] ?? null;
                    $row['staff_expense_total'] = $se ? (float) $se->total : 0.0;
                    $row['staff_expense_count'] = $se ? (int) $se->cnt : 0;
                    // This month's leave decisions for the row (from the SAME method the
                    // Leave-actions panel renders), so the grid's overtime/late cells show a
                    // settled decision instead of a toggle that would no longer do anything.
                    $row['leave_actions'] = $this->leaveActionsForRow($row, $month);
                    $rows[] = $row;
                }
            } catch (\Throwable $e) {
                \Log::warning('Payroll computeRow failed', ['user_id' => $uid, 'month' => $month, 'error' => $e->getMessage()]);
            }
        }
        return $rows;
    }

    // =========================================================================
    //  CUSTOM-SCHEDULE PAYROLL (date-range / weekly employees)
    // =========================================================================

    /**
     * Effective payroll visibility for every active user: user_id => ['on'=>bool, 'explicit'=>bool].
     *   - explicit = the owner set a dedicated "Show in Salary" flag in the attendance
     *     Customize-User-List modal. It OVERRIDES attendance visibility AND forces the
     *     row to appear even before a salary is set (so "＋ Set salary" is reachable).
     *   - no flag (NULL) → follows attendance "Show in Attendance" (COALESCE default visible).
     * Guarded: before the show_in_payroll column exists this is exactly the old
     * attendance-visibility rule, so the grid is byte-identical.
     */
    private function payrollVisibilityMap(): array
    {
        $hasCol = false;
        try { $hasCol = Schema::hasColumn('t_ops_attendance_visibility', 'show_in_payroll'); } catch (\Throwable $e) { $hasCol = false; }
        $rows = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->get(['u.id', 'av.is_visible', $hasCol ? 'av.show_in_payroll' : DB::raw('NULL as show_in_payroll')]);
        $map = [];
        foreach ($rows as $r) {
            $attVisible = ($r->is_visible === null) ? true : ((int) $r->is_visible === 1);
            $explicit = $hasCol && $r->show_in_payroll !== null;
            $map[(int) $r->id] = [
                'on'       => $explicit ? ((int) $r->show_in_payroll === 1) : $attVisible,
                'explicit' => $explicit,
            ];
        }
        return $map;
    }

    /** Map user_id => true for employees tagged pay_schedule='custom' (empty pre-SQL). */
    private function customScheduleUserIds(): array
    {
        if (!$this->profileHasScheduleCols()) { return []; }
        try {
            return DB::table('t_hr_employee_profile')->where('pay_schedule', 'custom')
                ->pluck('user_id')->flip()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Present days (with a login) inside a range — reference only, never affects pay. */
    private function presentDaysInRange(int $userId, string $start, string $end): int
    {
        try {
            return (int) DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->whereBetween('attendance_date', [$start, $end])
                ->whereNotNull('login_time')->where('login_time', '!=', '')
                ->distinct()->count('attendance_date');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Compute a custom employee's pay for a date range. PURE COMPUTATION — no writes.
     * Gross = daily rate × calendar days, or monthly rate ÷ 30 × calendar days.
     * Attendance (present/absent) is REFERENCE ONLY (owner policy: no automatic
     * absent/late/OT effects for custom employees); open advances auto-deduct.
     *
     * @param array $overrides ['amount' => manager's final gross before advances]
     */
    public function computeCustomPeriod(int $userId, string $start, string $end, array $overrides = []): array
    {
        $start = substr($start, 0, 10);
        $end   = substr($end, 0, 10);

        $profile = EmployeeProfileModel::with('user')->where('user_id', $userId)->first();
        $fullname = $profile && $profile->user ? $profile->user->fullname
            : (DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'Employee');

        $base       = (float) ($profile?->base_salary ?? 0);
        $rateType   = ($profile?->rate_type ?? 'monthly') === 'daily' ? 'daily' : 'monthly';
        $configured = $base > 0;
        $profileBu  = $profile?->business_unit_id ? (int) $profile->business_unit_id : null;

        $today = date('Y-m-d');
        $days  = $this->calendarDays($start, $end);

        // Reference attendance for the range (clamped to today; never penalises pay).
        $workingDaysInRange = 0; $presentDays = 0; $absentDays = 0;
        try {
            $refEnd = ($end > $today) ? $today : $end;
            if ($days > 0 && $refEnd >= $start) {
                $workingDaysInRange = $this->shift->calculateWorkingDays($userId, $start, $refEnd);
                $presentDays = $this->presentDaysInRange($userId, $start, $refEnd);
                $absentDays  = count((new AttendanceYearService())
                    ->absentWorkingDates($userId, $this->shift, $start, $refEnd));
            }
        } catch (\Throwable $e) { /* reference only */ }

        // Gross: daily → rate × days; monthly → base ÷ 30 × days (flat ÷30 convention).
        $computed = $rateType === 'daily'
            ? round($base * $days, 2)
            : round(($base / 30) * $days, 2);

        $amount = (array_key_exists('amount', $overrides) && $overrides['amount'] !== null && $overrides['amount'] !== '')
            ? round((float) $overrides['amount'], 2)
            : $computed;

        // Advances: recover only what THIS period's pay can absorb (oldest first).
        // Settlement is all-or-nothing per advance, and a weekly period is often
        // smaller than an open advance — settling everything like the monthly path
        // would write off the uncovered excess invisibly. Anything that doesn't fit
        // stays OPEN (still visible, recovered by a later/bigger period or manually).
        //
        // Deliberately NOT month-scoped (unlike the monthly grid): a custom period is a date
        // RANGE, not a month, and it already recovers "what fits, oldest first". Filtering to
        // one month here would strand an advance between two periods with nothing able to
        // recover it. Custom staff keep the oldest-first rule.
        $allAdvances = $this->openAdvances($userId);
        $advances = [];            // the ones deducted from THIS pay (settled on pay)
        $advanceTotal = 0.0;
        $advanceOpenAfter = 0.0;   // left open for next time
        foreach ($allAdvances as $a) {
            if (round($advanceTotal + $a['amount'], 2) <= $amount) {
                $advances[] = $a;
                $advanceTotal = round($advanceTotal + $a['amount'], 2);
            } else {
                $advanceOpenAfter = round($advanceOpenAfter + $a['amount'], 2);
            }
        }
        $net = round($amount - $advanceTotal, 2);

        return [
            'user_id'               => $userId,
            'fullname'              => $fullname,
            'employee_code'         => $profile?->employee_code,
            'designation'           => $profile?->designation,
            'rate_type'             => $rateType,
            'base_rate'             => round($base, 2),
            'configured'            => $configured,
            'business_unit_id'      => $this->stampBuId($profileBu),
            'bu_code'               => $this->buCodeFor($profileBu),
            'start'                 => $start,
            'end'                   => $end,
            'days'                  => $days,
            'working_days_in_range' => $workingDaysInRange,
            'present_days'          => $presentDays,
            'absent_days'           => $absentDays,
            'computed_amount'       => $computed,
            'amount'                => $amount,
            'advances'              => $advances,          // deducted THIS pay (settled on pay)
            'advance_total'         => $advanceTotal,
            'advance_open_after'    => $advanceOpenAfter,  // still open after this pay
            'net_amount'            => max(0, $net),
            'net_raw'               => $net,
        ];
    }

    /**
     * Every paid payment row for a user, normalised for overlap checks.
     * period_start/end are trimmed to Y-m-d; monthly rows carry period_key=''.
     */
    private function userPaidRows(int $userId, bool $forUpdate = false): array
    {
        try {
            // The frozen receipt columns ride along so a paid CUSTOM period can explain
            // itself on the Custom tab (what was recovered, from which account, by whom)
            // without a second query per chip. All of these predate the period columns.
            $cols = [
                'id', 'pay_month', 'net_salary', 'paid_at',
                'base_salary', 'working_days', 'present_days', 'absent_days',
                'advance_total', 'funding', 'bank_id', 'ledger_id', 'notes', 'paid_by',
            ];
            $hasPeriods = $this->payrollHasPeriodCols();
            if ($hasPeriods) {
                $cols = array_merge($cols, ['period_start', 'period_end', 'period_key']);
            }
            // entry_kind separates a khata payment (a single day the money moved) from a
            // salary/period row (a stretch of days that is now COVERED). Only the latter
            // may block a new range — see customPeriodConflict.
            $hasKind = $this->payrollHasEntryKind();
            if ($hasKind) { $cols[] = 'entry_kind'; }
            $q = DB::table('t_hr_payroll_payment')->where('user_id', $userId)->where('status', 'paid');
            if ($forUpdate) { $q->lockForUpdate(); }
            $rows = $q->get($cols);
            return $rows->map(function ($r) use ($hasPeriods, $hasKind) {
                $r->period_key   = $hasPeriods ? ($r->period_key ?? '') : '';
                $r->period_start = $hasPeriods && $r->period_start ? substr((string) $r->period_start, 0, 10) : null;
                $r->period_end   = $hasPeriods && $r->period_end ? substr((string) $r->period_end, 0, 10) : null;
                $r->entry_kind   = $hasKind ? ($r->entry_kind ?? '') : '';
                return $r;
            })->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Reject a new custom range that overlaps anything already paid for the user:
     *   - a paid CUSTOM period whose range intersects it, or
     *   - a paid MONTHLY month (the whole month counts as covered).
     * Returns a human message, or null when the range is free.
     */
    private function customPeriodConflict(string $start, string $end, array $paidRows): ?string
    {
        foreach ($paidRows as $r) {
            // A khata payment is money moving on ONE day, not days being covered — it can
            // never block a period. (Tracked employees can't be paid by period at all, so
            // this only matters for a range paid before a khata was opened.)
            if (($r->entry_kind ?? '') === 'balance_payment') { continue; }
            $pk = $r->period_key ?? '';
            if ($pk !== '' && $r->period_start && $r->period_end) {
                if ($this->rangesOverlap($start, $end, $r->period_start, $r->period_end)) {
                    return 'Overlaps an already-paid period (' . $this->fmtRange($r->period_start, $r->period_end) . ').';
                }
            } else {
                [$ms, $me] = $this->monthBounds($r->pay_month);
                if ($this->rangesOverlap($start, $end, $ms, $me)) {
                    return date('F Y', strtotime($r->pay_month . '-01')) . ' was already paid as a monthly salary.';
                }
            }
        }
        return null;
    }

    /**
     * Reverse guard for the monthly path: a message if custom periods already cover
     * part of the given month, else null.
     */
    private function monthlyBlockedByCustom(int $userId, string $month): ?string
    {
        if (!$this->payrollHasPeriodCols()) { return null; }
        [$ms, $me] = $this->monthBounds($month);
        foreach ($this->userPaidRows($userId) as $r) {
            if (($r->period_key ?? '') !== '' && $r->period_start && $r->period_end
                && $this->rangesOverlap($ms, $me, $r->period_start, $r->period_end)) {
                // A khata payment blocks the month too: paying the whole month as a salary
                // on top of money already handed over on the running balance would pay twice.
                $what = (($r->entry_kind ?? '') === 'balance_payment')
                    ? 'Custom-salary payments already exist in '
                    : 'Custom periods already exist in ';
                return $what . date('F Y', strtotime($month . '-01'))
                    . '. Finish this month on the Custom tab, or switch this employee to Monthly from next month.';
            }
        }
        return null;
    }

    /**
     * Pay a custom employee for a date range. Atomic; overlap-checked inside the
     * transaction (row-locked); posts the same salary_payment ledger + settles
     * advances as the monthly path. NO leave-grant side effects. pay_month = the
     * month of the period END (so 28 Jul–3 Aug files under August everywhere).
     *
     * @param array $opts ['funding','bank_id','amount','note','actor_id']
     */
    public function payCustomPeriod(int $userId, string $start, string $end, array $opts): array
    {
        if (!$this->payrollHasPeriodCols()) {
            return ['success' => false, 'message' => 'Custom payroll needs the schema update to be applied first.'];
        }
        $start = substr($start, 0, 10);
        $end   = substr($end, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return ['success' => false, 'message' => 'Pick a valid start and end date.'];
        }
        if ($end < $start) {
            return ['success' => false, 'message' => 'End date must be on or after the start date.'];
        }
        if ($end > date('Y-m-d')) {
            return ['success' => false, 'message' => "You can't pay for days that haven't happened yet."];
        }
        if ($this->calendarDays($start, $end) > 62) {
            return ['success' => false, 'message' => 'That period is longer than two months — split it into smaller ranges.'];
        }

        $funding = ($opts['funding'] ?? 'cash') === 'online' ? 'online' : 'cash';
        $bankId  = $funding === 'online' ? ($opts['bank_id'] ?? null) : null;
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        $actorId = (int) ($opts['actor_id'] ?? auth()->id() ?? 1);

        $profile = EmployeeProfileModel::where('user_id', $userId)->first();
        if (($profile?->pay_schedule ?? 'monthly') !== 'custom') {
            return ['success' => false, 'message' => 'This employee is not on a custom schedule.'];
        }
        if ((float) ($profile?->base_salary ?? 0) <= 0) {
            return ['success' => false, 'message' => "Set this employee's rate first."];
        }
        // A running-balance employee is paid by recording payments against the balance —
        // periods would double-count the same days.
        if ($this->isBalanceTracked($userId)) {
            return ['success' => false, 'message' => 'This employee is on a running balance — record a payment instead of a period.'];
        }

        try {
            return DB::transaction(function () use ($userId, $start, $end, $funding, $bankId, $actorId, $opts) {
                // Serialize concurrent pays for this employee: lock their PROFILE row
                // (always exists for a custom employee). Locking only the payment rows
                // isn't enough — when none exist yet there is nothing to lock, and two
                // managers paying overlapping ranges at once could both pass the check.
                DB::table('t_hr_employee_profile')->where('user_id', $userId)->lockForUpdate()->first();

                // Re-check overlap inside the tx (race-safe behind the lock above).
                $paidRows = $this->userPaidRows($userId, true);
                $conflict = $this->customPeriodConflict($start, $end, $paidRows);
                if ($conflict) {
                    return ['success' => false, 'message' => $conflict];
                }

                $row = $this->computeCustomPeriod($userId, $start, $end, ['amount' => $opts['amount'] ?? null]);
                $net = max(0, (float) $row['net_amount']);
                $buId = $row['business_unit_id'];
                $label = $this->fmtRange($start, $end);

                // Funding (source) account.
                if ($funding === 'online') {
                    $source = \App\Models\FIN\ConfigModel::getOnlineBankAccount();
                    $mode = 'online';
                } else {
                    $source = \App\Models\FIN\ConfigModel::getNFCashAccount();
                    $mode = 'cash';
                }
                if (!$source) {
                    throw new \RuntimeException('Funding account not found.');
                }

                // Employee cash account (resolve by user+category first, else create).
                $empCash = \App\Models\FIN\AccountModel::where('user_id', $userId)
                    ->where('account_category', 'employee_cash')->first();
                if (!$empCash) {
                    $uname = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: ('User ' . $userId);
                    $empCash = \App\Models\FIN\AccountModel::createEmployeeCashAccount($userId, $uname);
                }

                // Post the salary payment (same excluded-type behaviour as the monthly path).
                $ledger = null;
                if ($net > 0) {
                    $ledger = \App\Models\FIN\LedgerModel::create([
                        'transaction_date'   => now(),
                        'transaction_type'   => 'salary_payment',
                        'description'        => 'Salary ' . $label . ' — ' . $row['fullname'],
                        'from_account_id'    => $source->id,
                        'to_account_id'      => $empCash->id,
                        'amount'             => $net,
                        'mode'               => $mode,
                        'receiving_account_id' => $bankId,
                        'business_unit_id'   => $buId,
                        'approval_status'    => 'approved',
                        'approval_date'      => now(),
                        'approved_by'        => $actorId,
                        'external_source'    => 'payroll',
                        'external_ref_id'    => $start . '_' . $end . '/' . $userId,
                        'comments'           => 'Paid from: ' . $source->account_name . ($funding === 'online' ? (' (bank #' . $bankId . ')') : ''),
                        'created_by'         => $actorId,
                    ]);
                    (new \App\Services\FIN\BalancePostingService())->apply($ledger);
                }
                $ledgerId = $ledger ? $ledger->id : null;

                // Settle the advances deducted from this net. Same guard as the monthly path: the
                // advance must still be approved-and-unsettled or the whole payment aborts (it
                // could have been voided by the owner since this page loaded).
                $settledIds = [];
                foreach ($row['advances'] as $a) {
                    if (empty($a['request_id'])) {
                        continue;
                    }
                    $affected = DB::table('t_req_master')
                        ->where('id', $a['request_id'])
                        ->where('status', 'approved')
                        ->where(function ($q) {
                            $q->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'settled');
                        })
                        ->update([
                            'settlement_status' => 'settled',
                            'settled_at'        => now(),
                            'settled_by'        => $actorId,
                            'settlement_notes'  => 'Recovered from ' . $label . ' salary',
                            'settlement_transaction_id' => $ledgerId,
                            'updated_at'        => now(),
                        ]);
                    if ($affected !== 1) {
                        throw new \RuntimeException('the advances changed while this page was open — refresh payroll and pay again');
                    }
                    $settledIds[] = $a['request_id'];
                }

                // Record the payment. pay_month = the END month; period_key blocks an
                // exact-duplicate range via the widened UNIQUE key.
                $payMonth = date('Y-m', strtotime($end));
                DB::table('t_hr_payroll_payment')->insert([
                    'user_id'          => $userId,
                    'pay_month'        => $payMonth,
                    'base_salary'      => $row['base_rate'],
                    'working_days'     => $row['days'],           // days paid (custom row)
                    'present_days'     => $row['present_days'],
                    'absent_days'      => $row['absent_days'],
                    'leave_days'       => 0,
                    'absent_deduction' => 0,
                    'late_minutes'     => 0,
                    'late_deduction'   => 0,
                    'late_leave_deduct' => 0,
                    'bonus_leaves'     => 0,
                    'advance_total'    => $row['advance_total'],
                    'net_salary'       => $net,
                    'funding'          => $funding,
                    'bank_id'          => $bankId,
                    'ledger_id'        => $ledgerId,
                    'status'           => 'paid',
                    'notes'            => $opts['note'] ?? null,
                    'paid_at'          => now(),
                    'paid_by'          => $actorId,
                    'period_start'     => $start,
                    'period_end'       => $end,
                    'period_key'       => $start . '_' . $end,
                    'business_unit_id' => $buId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                return ['success' => true, 'net' => $net, 'ledger_id' => $ledgerId, 'settled_advances' => $settledIds, 'pay_month' => $payMonth];
            });
        } catch (\Throwable $e) {
            \Log::error('Payroll payCustomPeriod failed', ['user_id' => $userId, 'start' => $start, 'end' => $end, 'error' => $e->getMessage()]);
            if (stripos($e->getMessage(), 'uniq_user_month_period') !== false || stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return ['success' => false, 'message' => 'This exact period was already paid.'];
            }
            return ['success' => false, 'message' => 'Could not pay: ' . $e->getMessage()];
        }
    }

    /** Every custom-schedule employee + their coverage for a month (Custom tab grid). */
    public function computeMonthCustom(string $month): array
    {
        $customIds = array_keys($this->customScheduleUserIds());
        if (empty($customIds)) { return []; }

        // Same effective payroll-visibility rule as the monthly grid.
        $users = DB::table('t_sys_user as u')
            ->where('u.is_active', 1)
            ->whereIn('u.id', $customIds)
            ->orderBy('u.fullname')
            ->pluck('u.id');
        $payVis = $this->payrollVisibilityMap();

        $rows = [];
        foreach ($users as $uid) {
            $uid = (int) $uid;
            $vis = $payVis[$uid] ?? ['on' => true, 'explicit' => false];
            if (!$vis['on']) { continue; }
            try {
                $profile  = EmployeeProfileModel::where('user_id', (int) $uid)->first();
                $fullname = DB::table('t_sys_user')->where('id', $uid)->value('fullname') ?: 'Employee';
                $base     = (float) ($profile?->base_salary ?? 0);
                $rateType = ($profile?->rate_type ?? 'monthly') === 'daily' ? 'daily' : 'monthly';
                $profileBu = $profile?->business_unit_id ? (int) $profile->business_unit_id : null;

                $advances = $this->openAdvances((int) $uid);
                $advTotal = round(array_sum(array_column($advances, 'amount')), 2);

                // Running balance (khata) employees: the card shows a balance, not periods.
                // The figures come from balanceCalendar so the card and the calendar are ONE
                // computation read at two depths and cannot drift apart.
                $balance = null; $monthSummary = null; $monthPayments = [];
                if ($this->isBalanceTracked((int) $uid)) {
                    $cal = $this->balanceCalendar((int) $uid, $month);
                    if (!empty($cal['success'])) {
                        $balance = $cal['balance'];
                        $monthSummary = $cal['summary'];
                        foreach ($cal['days'] as $day) {
                            foreach ($day['payments'] as $p) { $monthPayments[] = $p; }
                        }
                    }
                }

                // Paid custom periods filed under this month (pay_month = end month) + last end overall.
                $paid = []; $lastEnd = null;
                foreach ($this->userPaidRows((int) $uid) as $r) {
                    // Khata payments are shown as payments, never as covered periods — and a
                    // payment must not push `suggested_start` either.
                    if (($r->entry_kind ?? '') === 'balance_payment') { continue; }
                    if (($r->period_key ?? '') === '' || !$r->period_start || !$r->period_end) { continue; }
                    if ($lastEnd === null || $r->period_end > $lastEnd) { $lastEnd = $r->period_end; }
                    if ($r->pay_month === $month) {
                        $paid[] = [
                            'id'      => $r->id,
                            'start'   => $r->period_start,
                            'end'     => $r->period_end,
                            'net'     => (float) $r->net_salary,
                            'paid_at' => (string) $r->paid_at,
                            'label'   => $this->fmtRange($r->period_start, $r->period_end),
                            // Frozen receipt: what was actually paid, and how. `gross` is
                            // derived from the two frozen numbers so it can never disagree
                            // with them (the live rate may have changed since).
                            'days_paid'      => (int) ($r->working_days ?? 0),
                            'present_days'   => (int) ($r->present_days ?? 0),
                            'absent_days'    => (int) ($r->absent_days ?? 0),
                            'rate_at_pay'    => (float) ($r->base_salary ?? 0),
                            'advance_total'  => (float) ($r->advance_total ?? 0),
                            'gross'          => round((float) $r->net_salary + (float) ($r->advance_total ?? 0), 2),
                            'funding'        => $r->funding ?? null,
                            'bank_id'        => $r->bank_id ? (int) $r->bank_id : null,
                            'ledger_id'      => $r->ledger_id ? (int) $r->ledger_id : null,
                            'notes'          => $r->notes ?? null,
                            'paid_by'        => $r->paid_by ? (int) $r->paid_by : null,
                        ];
                    }
                }
                usort($paid, fn ($a, $b) => strcmp($a['start'], $b['start']));

                $rows[] = [
                    'user_id'          => (int) $uid,
                    'fullname'         => $fullname,
                    'employee_code'    => $profile?->employee_code,
                    'designation'      => $profile?->designation,
                    'configured'       => $base > 0,
                    // Every row on this tab IS custom — without this the settings modal
                    // opened from a custom card pre-selected "Monthly" (the key was simply
                    // absent), so saving it silently moved the employee off the tab.
                    'pay_schedule'     => 'custom',
                    'rate_type'        => $rateType,
                    'base_rate'        => round($base, 2),
                    'business_unit_id' => $this->stampBuId($profileBu),
                    'bu_code'          => $this->buCodeFor($profileBu),
                    'advances'         => $advances,
                    'advance_total'    => $advTotal,
                    // Khata fields — null for a period-paid custom employee, so the card
                    // renderer picks its mode off `balance_tracked` alone.
                    'balance_tracked'  => $balance !== null,
                    'balance'          => $balance,
                    'month_summary'    => $monthSummary,
                    'month_payments'   => $monthPayments,
                    'paid_periods'     => $paid,
                    'paid_total'       => round(array_sum(array_column($paid, 'net')), 2),
                    'last_period_end'  => $lastEnd,
                    'suggested_start'  => $lastEnd
                        ? date('Y-m-d', strtotime($lastEnd . ' +1 day'))
                        : date('Y-m-01', strtotime($month . '-01')),
                ];
            } catch (\Throwable $e) {
                \Log::warning('Payroll custom row failed', ['user_id' => $uid, 'month' => $month, 'error' => $e->getMessage()]);
            }
        }

        // Human labels for the paid-period receipts (bank + who paid), two queries
        // for the whole grid. Cosmetic — a failure here never blanks a row.
        try {
            $bankIds = []; $payerIds = [];
            foreach ($rows as $r) {
                foreach ($r['paid_periods'] as $p) {
                    if (!empty($p['bank_id'])) { $bankIds[$p['bank_id']] = true; }
                    if (!empty($p['paid_by'])) { $payerIds[$p['paid_by']] = true; }
                }
            }
            $bankLabels = $bankIds
                ? DB::table('t_fin_online_receiving_accounts')->whereIn('id', array_keys($bankIds))
                    ->get(['id', 'name', 'bank_name', 'account_last4'])
                    ->mapWithKeys(fn ($b) => [$b->id => trim(($b->name ?: $b->bank_name) . ($b->account_last4 ? ' ••' . $b->account_last4 : ''))])
                    ->toArray()
                : [];
            $payerNames = $payerIds
                ? DB::table('t_sys_user')->whereIn('id', array_keys($payerIds))->pluck('fullname', 'id')->toArray()
                : [];
            foreach ($rows as $i => $r) {
                foreach ($r['paid_periods'] as $j => $p) {
                    $rows[$i]['paid_periods'][$j]['bank_label'] = !empty($p['bank_id'])
                        ? ($bankLabels[$p['bank_id']] ?? ('Bank #' . $p['bank_id'])) : null;
                    $rows[$i]['paid_periods'][$j]['paid_by_name'] = !empty($p['paid_by'])
                        ? ($payerNames[$p['paid_by']] ?? null) : null;
                }
            }
        } catch (\Throwable $e) { /* labels are cosmetic */ }

        return $rows;
    }

    // =========================================================================
    //  CUSTOM RUNNING BALANCE ("khata")
    // =========================================================================
    //
    // For custom employees who don't use the app (the butchers): no attendance, no
    // periods, no advances. The manager records PAYMENTS and crosses out days the man
    // didn't come, and the account runs continuously:
    //
    //     balance = opening + payments(since anchor) − Σ day-rate over counted days
    //     + = paid ahead        − = still to pay
    //
    // ⭐ The balance is DERIVED on every read, never stored. Crossing a day, un-crossing
    // it, voiding a payment or correcting a rate simply changes the answer next time it
    // is asked — there is no snapshot that can drift out of agreement with the facts.
    //
    // ⭐ `balance_track_start` is the anchor AND the on-switch. Nothing before it is ever
    // counted — no day, no payment — which is exactly what makes "treat the past as
    // cleared and start fresh from the 1st" safe: old periods and advances are invisible
    // to this math, not merely netted to zero.
    //
    // ⚠ Monthly payroll never reaches any of this: every entry point below returns early
    // unless the employee is custom-tagged AND has an anchor date.

    private static ?bool $balanceColsMemo = null;
    private function profileHasBalanceCols(): bool
    {
        if (self::$balanceColsMemo === null) {
            try {
                self::$balanceColsMemo = Schema::hasColumn('t_hr_employee_profile', 'balance_track_start')
                    && Schema::hasColumn('t_hr_employee_profile', 'balance_opening');
            } catch (\Throwable $e) {
                self::$balanceColsMemo = false;
            }
        }
        return self::$balanceColsMemo;
    }

    private static ?bool $entryKindMemo = null;
    private function payrollHasEntryKind(): bool
    {
        if (self::$entryKindMemo === null) {
            try {
                self::$entryKindMemo = Schema::hasColumn('t_hr_payroll_payment', 'entry_kind');
            } catch (\Throwable $e) {
                self::$entryKindMemo = false;
            }
        }
        return self::$entryKindMemo;
    }

    private static ?bool $balanceTablesMemo = null;
    private function balanceTablesExist(): bool
    {
        if (self::$balanceTablesMemo === null) {
            try {
                self::$balanceTablesMemo = Schema::hasTable('t_hr_custom_absence')
                    && Schema::hasTable('t_hr_custom_rate');
            } catch (\Throwable $e) {
                self::$balanceTablesMemo = false;
            }
        }
        return self::$balanceTablesMemo;
    }

    /** Every piece of schema the khata needs. False → the feature is invisible and inert. */
    public function balanceTrackingAvailable(): bool
    {
        return $this->profileHasScheduleCols()
            && $this->payrollHasPeriodCols()
            && $this->profileHasBalanceCols()
            && $this->payrollHasEntryKind()
            && $this->balanceTablesExist();
    }

    /** user_id => true for every employee on a running balance. Empty pre-SQL. */
    private function balanceTrackedUserIds(): array
    {
        if (!$this->balanceTrackingAvailable()) { return []; }
        try {
            return DB::table('t_hr_employee_profile')->whereNotNull('balance_track_start')
                ->pluck('user_id')->flip()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Is this employee on a running balance? (custom + an anchor date). */
    public function isBalanceTracked(int $userId): bool
    {
        if (!$this->balanceTrackingAvailable()) { return false; }
        try {
            return DB::table('t_hr_employee_profile')
                ->where('user_id', $userId)
                ->whereNotNull('balance_track_start')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A monthly rate is a day rate under the same flat ÷30 convention the periods use. */
    private function dayRateFrom(float $amount, string $rateType): float
    {
        return round($rateType === 'daily' ? $amount : ($amount / 30), 4);
    }

    /**
     * Dated day-rate history, ascending — the reason a rate change can carry the date the
     * manager chooses: days keep the price that was in force ON that day, so nothing is
     * ever silently repriced. Always non-empty (opening a khata writes the first row; a
     * profile-derived fallback covers a history that somehow went missing).
     */
    private function rateTimeline(int $userId, string $trackStart, ?object $profile = null): array
    {
        $rows = [];
        try {
            $rows = DB::table('t_hr_custom_rate')->where('user_id', $userId)
                ->orderBy('effective_date')->get(['effective_date', 'day_rate'])
                ->map(fn ($r) => [
                    'from' => substr((string) $r->effective_date, 0, 10),
                    'rate' => (float) $r->day_rate,
                ])->all();
        } catch (\Throwable $e) {
            $rows = [];
        }

        if (empty($rows)) {
            $profile = $profile ?: DB::table('t_hr_employee_profile')->where('user_id', $userId)->first();
            $rateType = (($profile->rate_type ?? 'monthly') === 'daily') ? 'daily' : 'monthly';
            $rows = [[
                'from' => $trackStart,
                'rate' => $this->dayRateFrom((float) ($profile->base_salary ?? 0), $rateType),
            ]];
        }
        // A segment effective before the anchor simply applies from the anchor.
        if ($rows[0]['from'] > $trackStart) {
            array_unshift($rows, ['from' => $trackStart, 'rate' => $rows[0]['rate']]);
        }
        return $rows;
    }

    /** The day rate in force on a date (last segment starting on or before it). */
    private function rateOnDate(array $timeline, string $date): float
    {
        $rate = 0.0;
        foreach ($timeline as $seg) {
            if ($seg['from'] <= $date) { $rate = $seg['rate']; } else { break; }
        }
        return $rate;
    }

    /** Crossed-out dates for an employee as a lookup set ['Y-m-d' => true]. */
    private function absenceSet(int $userId): array
    {
        if (!$this->balanceTablesExist()) { return []; }
        try {
            $out = [];
            foreach (DB::table('t_hr_custom_absence')->where('user_id', $userId)->pluck('absent_date') as $d) {
                $out[substr((string) $d, 0, 10)] = true;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Walk an inclusive day range: what it earned, how many days counted, how many were
     * crossed. The ONE place a day is priced — every figure on the card, the calendar and
     * the month summary comes through here, so they cannot disagree.
     */
    private function walkDays(array $timeline, array $absent, string $from, string $to): array
    {
        $earned = 0.0; $counted = 0; $crossed = 0;
        if ($to >= $from) {
            $d = $from;
            $guard = 0;
            while ($d <= $to && $guard++ < 4000) {
                if (isset($absent[$d])) {
                    $crossed++;
                } else {
                    $counted++;
                    $earned += $this->rateOnDate($timeline, $d);
                }
                $d = date('Y-m-d', strtotime($d . ' +1 day'));
            }
        }
        return ['earned' => round($earned, 2), 'counted' => $counted, 'crossed' => $crossed];
    }

    /**
     * Khata payments (money actually handed over), ascending by the date it was given.
     * A voided payment is status='voided' and drops out here exactly as it drops out of
     * the Expenses page, HQ and Reports — one filter, one truth.
     */
    private function balancePaymentRows(int $userId): array
    {
        if (!$this->balanceTrackingAvailable()) { return []; }
        try {
            return DB::table('t_hr_payroll_payment')
                ->where('user_id', $userId)
                ->where('status', 'paid')
                ->where('entry_kind', 'balance_payment')
                ->orderBy('period_start')->orderBy('id')
                ->get(['id', 'period_start', 'net_salary', 'funding', 'bank_id',
                       'ledger_id', 'notes', 'paid_by', 'paid_at'])
                ->map(fn ($r) => [
                    'id'        => (int) $r->id,
                    'date'      => substr((string) $r->period_start, 0, 10),
                    'amount'    => (float) $r->net_salary,
                    'funding'   => $r->funding,
                    'bank_id'   => $r->bank_id ? (int) $r->bank_id : null,
                    'ledger_id' => $r->ledger_id ? (int) $r->ledger_id : null,
                    'notes'     => $r->notes,
                    'paid_by'   => $r->paid_by ? (int) $r->paid_by : null,
                    'paid_at'   => (string) $r->paid_at,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Human labels for payment receipts (bank + who paid), one query each. Cosmetic. */
    private function decoratePayments(array $payments): array
    {
        try {
            $bankIds = array_filter(array_column($payments, 'bank_id'));
            $payerIds = array_filter(array_column($payments, 'paid_by'));
            $bankLabels = $bankIds
                ? DB::table('t_fin_online_receiving_accounts')->whereIn('id', $bankIds)
                    ->get(['id', 'name', 'bank_name', 'account_last4'])
                    ->mapWithKeys(fn ($b) => [$b->id => trim(($b->name ?: $b->bank_name) . ($b->account_last4 ? ' ••' . $b->account_last4 : ''))])
                    ->toArray()
                : [];
            $payerNames = $payerIds
                ? DB::table('t_sys_user')->whereIn('id', $payerIds)->pluck('fullname', 'id')->toArray()
                : [];
            foreach ($payments as $i => $p) {
                $payments[$i]['bank_label'] = $p['bank_id'] ? ($bankLabels[$p['bank_id']] ?? ('Bank #' . $p['bank_id'])) : null;
                $payments[$i]['paid_by_name'] = $p['paid_by'] ? ($payerNames[$p['paid_by']] ?? null) : null;
            }
        } catch (\Throwable $e) { /* labels are cosmetic */ }
        return $payments;
    }

    /** Wording, never a bare sign: managers read "To pay" / "Paid ahead", not "−8000". */
    private function balanceShape(float $balance): array
    {
        if (abs($balance) < 0.005) {
            return ['direction' => 'settled', 'label' => 'All settled', 'amount' => 0.0];
        }
        return $balance > 0
            ? ['direction' => 'paid_ahead', 'label' => 'Paid ahead', 'amount' => round($balance, 2)]
            : ['direction' => 'to_pay',     'label' => 'To pay',     'amount' => round(-$balance, 2)];
    }

    /**
     * The running balance for a tracked employee, as of a date (default today).
     * PURE COMPUTATION — returns null when the employee isn't on a khata.
     *
     * Future days never accrue: the accrual always stops at today, so a date in the
     * future answers "what it is now", and a crossed future day is simply recorded
     * until its day comes.
     */
    public function balanceState(int $userId, ?string $asOf = null): ?array
    {
        if (!$this->balanceTrackingAvailable()) { return null; }

        try {
            $profile = DB::table('t_hr_employee_profile')->where('user_id', $userId)->first();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$profile || empty($profile->balance_track_start)) { return null; }

        $start = substr((string) $profile->balance_track_start, 0, 10);
        $today = date('Y-m-d');
        $asOf  = $asOf ? substr($asOf, 0, 10) : $today;
        $accrualEnd = ($asOf > $today) ? $today : $asOf;

        $rateType = (($profile->rate_type ?? 'monthly') === 'daily') ? 'daily' : 'monthly';
        $timeline = $this->rateTimeline($userId, $start, $profile);
        $absent   = $this->absenceSet($userId);

        $walk = $this->walkDays($timeline, $absent, $start, $accrualEnd);

        $paid = 0.0; $payCount = 0;
        foreach ($this->balancePaymentRows($userId) as $p) {
            if ($p['date'] >= $start && $p['date'] <= $asOf) {
                $paid += $p['amount'];
                $payCount++;
            }
        }
        $paid = round($paid, 2);

        $opening = round((float) ($profile->balance_opening ?? 0), 2);
        $balance = round($opening + $paid - $walk['earned'], 2);

        return array_merge($this->balanceShape($balance), [
            'tracked'      => true,
            'track_start'  => $start,
            'start_label'  => date('j M Y', strtotime($start)),
            'opening'      => $opening,
            'as_of'        => $asOf,
            'earned'       => $walk['earned'],
            'paid'         => $paid,
            'payment_count' => $payCount,
            'counted_days' => $walk['counted'],
            'crossed_days' => $walk['crossed'],
            'balance'      => $balance,          // signed; the UI uses direction + amount
            'day_rate'     => round($this->rateOnDate($timeline, $today), 2),
            'base_rate'    => round((float) ($profile->base_salary ?? 0), 2),
            'rate_type'    => $rateType,
        ]);
    }

    /**
     * One month of the khata: a day-by-day calendar plus that month's totals.
     * Every day carries the balance AFTER it, so the running account the manager
     * described is visible per day, not just as a headline.
     */
    public function balanceCalendar(int $userId, string $month): array
    {
        $state = $this->balanceState($userId);
        if (!$state) {
            return ['success' => false, 'message' => 'This employee is not on a running balance.'];
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $profile = DB::table('t_hr_employee_profile')->where('user_id', $userId)->first();
        $start   = $state['track_start'];
        $today   = date('Y-m-d');
        [$mStart, $mEnd] = $this->monthBounds($month);

        $timeline = $this->rateTimeline($userId, $start, $profile);
        $absent   = $this->absenceSet($userId);

        $payments = $this->decoratePayments($this->balancePaymentRows($userId));
        $payByDate = [];
        foreach ($payments as $p) {
            if ($p['date'] >= $start) { $payByDate[$p['date']][] = $p; }
        }

        // Walk from the anchor so each day of the shown month knows the balance after it.
        $run = $state['opening'];
        $runningByDate = [];
        $d = $start; $guard = 0;
        while ($d <= $mEnd && $guard++ < 4000) {
            foreach ($payByDate[$d] ?? [] as $p) { $run += $p['amount']; }
            if ($d <= $today && !isset($absent[$d])) { $run -= $this->rateOnDate($timeline, $d); }
            if ($d >= $mStart) { $runningByDate[$d] = round($run, 2); }
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }

        $days = [];
        $d = $mStart;
        while ($d <= $mEnd) {
            $inAccount = ($d >= $start);
            $days[] = [
                'date'       => $d,
                'day'        => (int) date('j', strtotime($d)),
                'dow'        => (int) date('N', strtotime($d)),   // 1 = Mon
                'in_account' => $inAccount,
                'crossed'    => $inAccount && isset($absent[$d]),
                'future'     => $d > $today,
                'is_today'   => $d === $today,
                'rate'       => $inAccount ? round($this->rateOnDate($timeline, $d), 2) : 0,
                'payments'   => $payByDate[$d] ?? [],
                'paid_total' => round(array_sum(array_column($payByDate[$d] ?? [], 'amount')), 2),
                'running'    => $inAccount ? ($runningByDate[$d] ?? null) : null,
            ];
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }

        // Month totals: accrual clamped to today, so the current month reads "so far".
        $from = ($start > $mStart) ? $start : $mStart;
        $to   = ($mEnd > $today) ? $today : $mEnd;
        $walk = $this->walkDays($timeline, $absent, $from, $to);
        $paidThisMonth = 0.0;
        foreach ($payments as $p) {
            if ($p['date'] >= $mStart && $p['date'] <= $mEnd && $p['date'] >= $start) {
                $paidThisMonth += $p['amount'];
            }
        }
        // Closing = the balance at the last day this month can speak for (its own end, or
        // today for a month still running). A month entirely BEFORE the anchor has no days
        // in the account at all, so it closes at the opening balance — never today's figure,
        // which would date a live number to a month the account did not yet exist in.
        $closingDate  = ($mEnd > $today) ? $today : $mEnd;
        $beforeStart  = ($mEnd < $start);
        $closing = $beforeStart ? $state['opening'] : ($runningByDate[$closingDate] ?? $state['balance']);

        return [
            'success'     => true,
            'month'       => $month,
            'month_label' => date('F Y', strtotime($mStart)),
            'prev_month'  => date('Y-m', strtotime($mStart . ' -1 month')),
            'next_month'  => date('Y-m', strtotime($mStart . ' +1 month')),
            'has_prev'    => $mStart > $start,
            'days'        => $days,
            'balance'     => $state,
            'summary'     => array_merge($this->balanceShape((float) $closing), [
                'counted'      => $walk['counted'],
                'crossed'      => $walk['crossed'],
                'earned'       => $walk['earned'],
                'paid'         => round($paidThisMonth, 2),
                'closing_date' => $closingDate,
                'is_current'   => ($mStart <= $today && $mEnd >= $today),
                'before_start' => $beforeStart,
            ]),
        ];
    }

    /**
     * Open a khata: pick the anchor + the opening balance. Everything before the anchor
     * stops existing for this employee's pay — which is how "the previous month is
     * cleared, start from the 1st" is achieved without deleting a single record.
     *
     * The anchor may not fall on a day already paid (period or monthly month), or the
     * same day would be paid twice — once as a period, once as accrued days.
     */
    public function enableBalanceTracking(int $userId, string $startDate, float $opening, int $actorId): array
    {
        if (!$this->balanceTrackingAvailable()) {
            return ['success' => false, 'message' => 'Running balances need the schema update to be applied first.'];
        }
        $startDate = substr($startDate, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || strtotime($startDate) === false) {
            return ['success' => false, 'message' => 'Pick a valid start date.'];
        }
        if ($startDate > date('Y-m-d')) {
            return ['success' => false, 'message' => "The start date can't be in the future."];
        }

        $profile = DB::table('t_hr_employee_profile')->where('user_id', $userId)->first();
        if (!$profile) {
            return ['success' => false, 'message' => 'This employee has no payroll profile yet.'];
        }
        if (($profile->pay_schedule ?? 'monthly') !== 'custom') {
            return ['success' => false, 'message' => 'Only custom-schedule employees can be put on a running balance.'];
        }
        if ((float) ($profile->base_salary ?? 0) <= 0) {
            return ['success' => false, 'message' => "Set this employee's rate first."];
        }
        if (!empty($profile->balance_track_start)) {
            return ['success' => false, 'message' => 'This employee is already on a running balance.'];
        }

        // The anchor must sit after everything already paid the old way.
        $lastCovered = null;
        foreach ($this->userPaidRows($userId) as $r) {
            if (($r->entry_kind ?? '') === 'balance_payment') { continue; }
            $end = (($r->period_key ?? '') !== '' && $r->period_end)
                ? $r->period_end
                : $this->monthBounds($r->pay_month)[1];
            if ($lastCovered === null || $end > $lastCovered) { $lastCovered = $end; }
        }
        if ($lastCovered !== null && $startDate <= $lastCovered) {
            $free = date('Y-m-d', strtotime($lastCovered . ' +1 day'));
            return ['success' => false, 'message' => 'Days up to ' . date('j M Y', strtotime($lastCovered))
                . ' are already paid. Start the balance on ' . date('j M Y', strtotime($free)) . ' or later.'];
        }

        $rateType = (($profile->rate_type ?? 'monthly') === 'daily') ? 'daily' : 'monthly';
        $base     = (float) $profile->base_salary;
        $opening  = round($opening, 2);

        try {
            return DB::transaction(function () use ($userId, $startDate, $opening, $actorId, $base, $rateType) {
                DB::table('t_hr_employee_profile')->where('user_id', $userId)->lockForUpdate()->first();

                DB::table('t_hr_employee_profile')->where('user_id', $userId)->update([
                    'balance_track_start' => $startDate,
                    'balance_opening'     => $opening,
                    'updated_at'          => now(),
                ]);

                // First rate segment, so every counted day has a price from day one.
                DB::table('t_hr_custom_rate')->updateOrInsert(
                    ['user_id' => $userId, 'effective_date' => $startDate],
                    [
                        'day_rate'    => $this->dayRateFrom($base, $rateType),
                        'base_amount' => round($base, 2),
                        'rate_type'   => $rateType,
                        'created_by'  => $actorId,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]
                );

                // Advances stop existing as a concept for this employee: whatever the manager
                // put in the opening balance IS the settlement. The ledger rows are untouched —
                // the cash-out history stays exactly where it is.
                $advances = $this->openAdvances($userId);
                $settled = 0;
                foreach ($advances as $a) {
                    if (empty($a['request_id'])) { continue; }
                    $settled += DB::table('t_req_master')
                        ->where('id', $a['request_id'])
                        ->where('status', 'approved')
                        ->where(function ($q) {
                            $q->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'settled');
                        })
                        ->update([
                            'settlement_status' => 'settled',
                            'settled_at'        => now(),
                            'settled_by'        => $actorId,
                            'settlement_notes'  => 'Converted to running balance on ' . date('j M Y', strtotime($startDate)),
                            'updated_at'        => now(),
                        ]);
                }

                \Log::info('Payroll balance tracking enabled', [
                    'user_id' => $userId, 'start' => $startDate, 'opening' => $opening,
                    'advances_converted' => $settled, 'by' => $actorId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Running balance started from ' . date('j M Y', strtotime($startDate)) . '.',
                    'advances_converted' => $settled,
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('enableBalanceTracking failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not start the running balance: ' . $e->getMessage()];
        }
    }

    /**
     * Cross a day out (it earns nothing) or restore it. Future days are allowed — the
     * manager often knows in advance — and simply wait for their date to matter.
     */
    public function toggleAbsence(int $userId, string $date, int $actorId): array
    {
        $state = $this->balanceState($userId);
        if (!$state) {
            return ['success' => false, 'message' => 'This employee is not on a running balance.'];
        }
        $date = substr($date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            return ['success' => false, 'message' => 'Pick a valid date.'];
        }
        if ($date < $state['track_start']) {
            return ['success' => false, 'message' => 'That day is before this balance started ('
                . $state['start_label'] . ') — it is not part of the account.'];
        }
        if ($date > date('Y-m-d', strtotime('+1 year'))) {
            return ['success' => false, 'message' => "That's too far ahead to mark."];
        }

        try {
            $existing = DB::table('t_hr_custom_absence')
                ->where('user_id', $userId)->where('absent_date', $date)->first();
            if ($existing) {
                DB::table('t_hr_custom_absence')->where('id', $existing->id)->delete();
                $crossed = false;
            } else {
                DB::table('t_hr_custom_absence')->insert([
                    'user_id'     => $userId,
                    'absent_date' => $date,
                    'marked_by'   => $actorId,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $crossed = true;
            }
            \Log::info('Payroll khata day toggled', [
                'user_id' => $userId, 'date' => $date, 'crossed' => $crossed, 'by' => $actorId,
            ]);
            return [
                'success' => true,
                'crossed' => $crossed,
                'balance' => $this->balanceState($userId),
            ];
        } catch (\Throwable $e) {
            \Log::error('toggleAbsence failed', ['user_id' => $userId, 'date' => $date, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not update that day.'];
        }
    }

    /**
     * Change a tracked employee's rate FROM A DATE THE MANAGER PICKS (owner ruling).
     * Days before it keep the old price; days from it — including days already crossed —
     * price at the new one. Nothing is repriced silently, and a wrong entry is fixed by
     * saving again on the same effective date.
     */
    public function changeTrackedRate(int $userId, float $newBase, string $effectiveDate, int $actorId): array
    {
        $state = $this->balanceState($userId);
        if (!$state) {
            return ['success' => false, 'message' => 'This employee is not on a running balance.'];
        }
        if ($newBase <= 0) {
            return ['success' => false, 'message' => 'Enter the new rate.'];
        }
        $effectiveDate = substr($effectiveDate, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate) || strtotime($effectiveDate) === false) {
            return ['success' => false, 'message' => 'Pick a valid date for the new rate.'];
        }
        if ($effectiveDate < $state['track_start']) {
            return ['success' => false, 'message' => 'The new rate cannot start before the balance itself ('
                . $state['start_label'] . ').'];
        }
        if ($effectiveDate > date('Y-m-d')) {
            return ['success' => false, 'message' => "A rate can't start in the future — set it on the day it applies."];
        }

        $rateType = $state['rate_type'];
        try {
            DB::transaction(function () use ($userId, $newBase, $effectiveDate, $actorId, $rateType) {
                $existing = DB::table('t_hr_employee_profile')->where('user_id', $userId)->lockForUpdate()->first();

                DB::table('t_hr_custom_rate')->updateOrInsert(
                    ['user_id' => $userId, 'effective_date' => $effectiveDate],
                    [
                        'day_rate'    => $this->dayRateFrom($newBase, $rateType),
                        'base_amount' => round($newBase, 2),
                        'rate_type'   => $rateType,
                        'created_by'  => $actorId,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]
                );

                // Keep the profile's headline rate in step (it is what the card shows and
                // what a future khata/period would start from).
                DB::table('t_hr_employee_profile')->where('user_id', $userId)->update([
                    'previous_salary'         => $existing->base_salary ?? null,
                    'base_salary'             => round($newBase, 2),
                    'last_salary_change_date' => $effectiveDate,
                    'updated_at'              => now(),
                ]);
            });
            \Log::info('Payroll khata rate changed', [
                'user_id' => $userId, 'rate' => $newBase, 'effective' => $effectiveDate, 'by' => $actorId,
            ]);
            return [
                'success' => true,
                'message' => 'Rate updated from ' . date('j M Y', strtotime($effectiveDate)) . '.',
                'balance' => $this->balanceState($userId),
            ];
        } catch (\Throwable $e) {
            \Log::error('changeTrackedRate failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not change the rate.'];
        }
    }

    /**
     * Record money handed to a tracked employee. This is a NORMAL payroll payment row
     * (entry_kind='balance_payment') plus the same `salary_payment` ledger entry the
     * monthly and period paths post — which is why the Expenses page, Ledger Hub, HQ and
     * Reports pick it up with no changes on their side.
     *
     * @param array $opts ['funding','bank_id','note','actor_id']
     */
    public function recordBalancePayment(int $userId, float $amount, string $date, array $opts): array
    {
        $state = $this->balanceState($userId);
        if (!$state) {
            return ['success' => false, 'message' => 'This employee is not on a running balance.'];
        }
        $amount = round($amount, 2);
        if ($amount < 1) {
            return ['success' => false, 'message' => 'Enter the amount paid.'];
        }
        $date = substr($date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            return ['success' => false, 'message' => 'Pick a valid payment date.'];
        }
        if ($date > date('Y-m-d')) {
            return ['success' => false, 'message' => "You can't record a payment for a future date."];
        }
        if ($date < $state['track_start']) {
            return ['success' => false, 'message' => 'That date is before this balance started ('
                . $state['start_label'] . '). Money paid earlier belongs in the opening balance.'];
        }

        $funding = ($opts['funding'] ?? 'cash') === 'online' ? 'online' : 'cash';
        $bankId  = $funding === 'online' ? ($opts['bank_id'] ?? null) : null;
        if ($funding === 'online' && !$bankId) {
            return ['success' => false, 'message' => 'Choose the bank you are paying from.'];
        }
        $actorId = (int) ($opts['actor_id'] ?? auth()->id() ?? 1);
        $note    = $opts['note'] ?? null;

        // Backdating stamps BOTH the ledger date and paid_at, so Ledger Hub, the Expenses
        // page and this screen all tell the same story about when the money moved.
        $stampAt = ($date === date('Y-m-d')) ? now() : \Carbon\Carbon::parse($date . ' 12:00:00');

        try {
            return DB::transaction(function () use ($userId, $amount, $date, $funding, $bankId, $actorId, $note, $stampAt) {
                DB::table('t_hr_employee_profile')->where('user_id', $userId)->lockForUpdate()->first();

                // Double-submit guard: the same amount, same day, seconds ago is a stutter,
                // not a second payment. A genuine repeat is fine a couple of minutes later.
                $dupe = DB::table('t_hr_payroll_payment')
                    ->where('user_id', $userId)
                    ->where('entry_kind', 'balance_payment')
                    ->where('status', 'paid')
                    ->where('period_start', $date)
                    ->where('net_salary', $amount)
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->exists();
                if ($dupe) {
                    return ['success' => false, 'message' => 'That exact payment was just recorded — refresh to see it.'];
                }

                $profile = DB::table('t_hr_employee_profile')->where('user_id', $userId)->first();
                $buId = $this->stampBuId($profile && $profile->business_unit_id ? (int) $profile->business_unit_id : null);
                $fullname = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: ('User ' . $userId);

                $source = $funding === 'online'
                    ? \App\Models\FIN\ConfigModel::getOnlineBankAccount()
                    : \App\Models\FIN\ConfigModel::getNFCashAccount();
                if (!$source) {
                    throw new \RuntimeException('Funding account not found.');
                }

                $empCash = \App\Models\FIN\AccountModel::where('user_id', $userId)
                    ->where('account_category', 'employee_cash')->first();
                if (!$empCash) {
                    $empCash = \App\Models\FIN\AccountModel::createEmployeeCashAccount($userId, $fullname);
                }

                $ledger = \App\Models\FIN\LedgerModel::create([
                    'transaction_date'     => $stampAt,
                    'transaction_type'     => 'salary_payment',
                    'description'          => 'Salary payment ' . date('j M Y', strtotime($date)) . ' — ' . $fullname,
                    'from_account_id'      => $source->id,
                    'to_account_id'        => $empCash->id,
                    'amount'               => $amount,
                    'mode'                 => $funding === 'online' ? 'online' : 'cash',
                    'receiving_account_id' => $bankId,
                    'business_unit_id'     => $buId,
                    'approval_status'      => 'approved',
                    'approval_date'        => $stampAt,
                    'approved_by'          => $actorId,
                    'external_source'      => 'payroll',
                    'external_ref_id'      => 'khata/' . $userId . '/' . $date,
                    'comments'             => 'Paid from: ' . $source->account_name . ($funding === 'online' ? (' (bank #' . $bankId . ')') : ''),
                    'created_by'           => $actorId,
                ]);
                (new \App\Services\FIN\BalancePostingService())->apply($ledger);

                // period_start = period_end = the day the money was handed over. The unique
                // key is (user, pay_month, period_key), so the random suffix lets the same
                // employee be paid more than once on one day.
                $periodKey = 'PAY_' . $date . '_' . substr(md5(uniqid('', true)), 0, 8);

                DB::table('t_hr_payroll_payment')->insert([
                    'user_id'           => $userId,
                    'pay_month'         => date('Y-m', strtotime($date)),
                    'base_salary'       => round((float) ($profile->base_salary ?? 0), 2),
                    'working_days'      => 0,
                    'present_days'      => 0,
                    'absent_days'       => 0,
                    'leave_days'        => 0,
                    'absent_deduction'  => 0,
                    'late_minutes'      => 0,
                    'late_deduction'    => 0,
                    'late_leave_deduct' => 0,
                    'bonus_leaves'      => 0,
                    'advance_total'     => 0,
                    'net_salary'        => $amount,
                    'funding'           => $funding,
                    'bank_id'           => $bankId,
                    'ledger_id'         => $ledger->id,
                    'status'            => 'paid',
                    'notes'             => $note,
                    'paid_at'           => $stampAt,
                    'paid_by'           => $actorId,
                    'period_start'      => $date,
                    'period_end'        => $date,
                    'period_key'        => $periodKey,
                    'entry_kind'        => 'balance_payment',
                    'business_unit_id'  => $buId,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                \Log::info('Payroll khata payment recorded', [
                    'user_id' => $userId, 'amount' => $amount, 'date' => $date,
                    'ledger_id' => $ledger->id, 'by' => $actorId,
                ]);

                return [
                    'success'   => true,
                    'message'   => 'Recorded Rs ' . number_format($amount) . '.',
                    'ledger_id' => $ledger->id,
                    'balance'   => $this->balanceState($userId),
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('recordBalancePayment failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not record the payment: ' . $e->getMessage()];
        }
    }

    /**
     * Undo a khata payment entered by mistake. Same shape as voiding an advance: the money
     * goes back through the balance engine, the ledger row is kept and marked reversed, and
     * the payment row flips to status='voided' — which removes it from the balance AND from
     * every money surface at once, since they all filter status='paid'.
     */
    public function voidBalancePayment(int $paymentId, string $reason, int $actorId): array
    {
        if (!$this->balanceTrackingAvailable()) {
            return ['success' => false, 'message' => 'Running balances need the schema update to be applied first.'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Please type why this payment is being voided.'];
        }

        try {
            return DB::transaction(function () use ($paymentId, $reason, $actorId) {
                $pay = DB::table('t_hr_payroll_payment')->where('id', $paymentId)->lockForUpdate()->first();
                if (!$pay) {
                    return ['success' => false, 'message' => 'Payment not found.'];
                }
                if (($pay->entry_kind ?? '') !== 'balance_payment') {
                    return ['success' => false, 'message' => 'Only a custom-salary payment can be voided here.'];
                }
                if ($pay->status !== 'paid') {
                    return ['success' => false, 'message' => 'This payment is already ' . $pay->status . '.'];
                }

                $actorName = DB::table('t_sys_user')->where('id', $actorId)->value('fullname') ?: ('User ' . $actorId);
                $stamp = 'VOIDED by ' . $actorName . ' on ' . now()->format('Y-m-d H:i:s') . ' — Reason: ' . $reason;
                $restoredTo = null;

                if ($pay->ledger_id) {
                    $ledger = \App\Models\FIN\LedgerModel::lockForUpdate()->find($pay->ledger_id);
                    if (!$ledger) {
                        return ['success' => false, 'message' => 'The ledger entry for this payment is missing — please check it before changing anything.'];
                    }
                    if ($ledger->approval_status !== \App\Models\FIN\LedgerModel::STATUS_APPROVED) {
                        return ['success' => false, 'message' => 'The ledger entry for this payment is already '
                            . $ledger->approval_status . ' — nothing to restore.'];
                    }
                    $restoredTo = \App\Models\FIN\AccountModel::find($ledger->from_account_id)?->account_name;
                    if ($ledger->receiving_account_id) {
                        $bank = DB::table('t_fin_online_receiving_accounts')
                            ->where('id', $ledger->receiving_account_id)
                            ->first(['name', 'bank_name', 'account_last4']);
                        if ($bank) {
                            $restoredTo = trim(($bank->name ?: $bank->bank_name)
                                . ($bank->account_last4 ? ' ••' . $bank->account_last4 : ''));
                        }
                    }
                    (new \App\Services\FIN\BalancePostingService())->reverse($ledger);
                    $ledger->approval_status = \App\Models\FIN\LedgerModel::STATUS_REVERSED;
                    $ledger->comments = ($ledger->comments ? $ledger->comments . "\n" : '') . $stamp;
                    $ledger->save();
                }

                DB::table('t_hr_payroll_payment')->where('id', $paymentId)->update([
                    'status'     => 'voided',
                    'notes'      => trim((string) ($pay->notes ? $pay->notes . ' | ' : '') . $stamp),
                    'updated_at' => now(),
                ]);

                \Log::info('Payroll khata payment voided', [
                    'payment_id' => $paymentId, 'user_id' => $pay->user_id,
                    'amount' => $pay->net_salary, 'by' => $actorId, 'reason' => $reason,
                ]);

                return [
                    'success' => true,
                    'message' => 'Payment of Rs ' . number_format((float) $pay->net_salary) . ' voided'
                        . ($restoredTo ? ' — returned to ' . $restoredTo : '') . '.',
                    'balance' => $this->balanceState((int) $pay->user_id),
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('voidBalancePayment failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not void this payment: ' . $e->getMessage()];
        }
    }

    /** Ensure an employee profile row exists (so tag updates have a row to write). */
    private function ensureProfile(int $userId): void
    {
        $exists = DB::table('t_hr_employee_profile')->where('user_id', $userId)->exists();
        if (!$exists) {
            DB::table('t_hr_employee_profile')->insert([
                'user_id'               => $userId,
                'base_salary'           => 0,
                'salary_effective_date' => now()->toDateString(),
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }
    }

    /** Set an employee's pay schedule (monthly|custom) + rate type (daily|monthly). Audited. */
    public function setSchedule(int $userId, string $paySchedule, ?string $rateType, int $actorId): array
    {
        if (!$this->profileHasScheduleCols()) {
            return ['success' => false, 'message' => 'Apply the schema update before changing schedules.'];
        }
        $paySchedule = $paySchedule === 'custom' ? 'custom' : 'monthly';
        $rateType = in_array($rateType, ['daily', 'monthly'], true) ? $rateType : 'monthly';
        // While a running balance is on, the schedule is frozen: switching to Monthly would
        // strand the balance (and the rate unit change would reprice the whole account).
        if ($this->isBalanceTracked($userId)) {
            return ['success' => false, 'message' => 'This employee is on a running balance — the pay schedule and rate unit stay fixed while it is on.'];
        }
        try {
            $this->ensureProfile($userId);
            DB::table('t_hr_employee_profile')->where('user_id', $userId)->update([
                'pay_schedule' => $paySchedule,
                'rate_type'    => $paySchedule === 'custom' ? $rateType : 'monthly',
                'updated_at'   => now(),
            ]);
            \Log::info('Payroll schedule set', ['user_id' => $userId, 'schedule' => $paySchedule, 'rate_type' => $rateType, 'by' => $actorId]);
            return ['success' => true, 'message' => 'Saved.'];
        } catch (\Throwable $e) {
            \Log::error('setSchedule failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not save the schedule.'];
        }
    }

    /** Tag an employee's business unit. Only NF (null) or the real Khaas id is stored. Audited. */
    public function setBusinessUnit(int $userId, ?int $buId, int $actorId): array
    {
        if (!$this->profileHasScheduleCols()) {
            return ['success' => false, 'message' => 'Apply the schema update before tagging business units.'];
        }
        $khaas = $this->khaasBuId();
        $store = ($buId && $khaas && $buId === $khaas) ? $khaas : null; // null = NF (codebase convention)
        try {
            $this->ensureProfile($userId);
            DB::table('t_hr_employee_profile')->where('user_id', $userId)->update([
                'business_unit_id' => $store,
                'updated_at'       => now(),
            ]);
            \Log::info('Payroll BU set', ['user_id' => $userId, 'bu_id' => $store, 'by' => $actorId]);
            return ['success' => true, 'message' => 'Saved.'];
        } catch (\Throwable $e) {
            \Log::error('setBusinessUnit failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not save the business unit.'];
        }
    }

    /** Whether the schedule/BU tag columns exist yet (gates the schedule gear + Custom tab). */
    public function scheduleTaggingAvailable(): bool
    {
        return $this->profileHasScheduleCols();
    }

    /** Whether Khaas tagging is offered (schema applied + a Khaas BU exists). */
    public function khaasTaggingAvailable(): bool
    {
        return $this->profileHasScheduleCols() && $this->khaasBuId() !== null;
    }

    /** The Khaas business-unit id (for the front-end BU toggle to post), or null. */
    public function khaasBuIdValue(): ?int
    {
        return $this->khaasBuId();
    }
}
