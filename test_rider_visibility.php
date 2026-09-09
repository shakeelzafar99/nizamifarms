<?php
/**
 * Sep-6 2026 (owner ruling, round 3) — what a RIDER may see of his own overtime and lateness.
 *
 *   HE SEES     the same MINUTES management sees, per day and per month, and which days have
 *               already been checked.
 *   HE DOES NOT the bonus DAYS those minutes might earn — until management has actually
 *               granted them. `bonus_leaves` off computeRow is a RECOMMENDATION a manager can
 *               still skip, and a rider promised "+2 bonus leaves" who then gets none has been
 *               told something nobody agreed to.
 *
 *   php test_rider_visibility.php
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\HR\DayReviewService;
use App\Services\HR\OvertimeService;
use App\Services\HR\PayrollService;
use App\Services\ShiftResolutionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function check(string $what, $got, $want): void {
    global $pass, $fail;
    $ok = json_encode($got) === json_encode($want);
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $what
        . ($ok ? "\n" : "  got=".json_encode($got)."  want=".json_encode($want)."\n");
}

/** ⚠ Auth::user() reads the DEFAULT guard (sanctum), which caches — set both, freshly. */
function actAs(int $uid): \App\Models\User {
    $u = \App\Models\User::find($uid);
    Auth::guard('web')->setUser($u);
    Auth::setUser($u);
    return $u;
}
function call(object $ctl, string $method, array $params, \App\Models\User $u) {
    $r = Illuminate\Http\Request::create('/x', 'GET', $params);
    $r->setUserResolver(fn () => $u);
    app()->instance('request', $r);
    return json_decode($ctl->{$method}($r)->getContent(), true);
}

$pay   = app(\App\Http\Controllers\API\PayrollController::class);
$rider = app(\App\Http\Controllers\API\RiderController::class);
$ot    = app(OvertimeService::class);
$shift = app(ShiftResolutionService::class);

// A month/rider where the engine recommends at least one bonus day.
$target = null;
foreach (['2026-08', '2026-09'] as $m) {
    foreach (DB::table('t_ops_attendance')->whereBetween('attendance_date', [$m.'-01', date('Y-m-t', strtotime($m.'-01'))])
                 ->distinct()->pluck('user_id') as $uid) {
        try {
            $row = app(PayrollService::class)->computeRow((int) $uid, $m);
            if (($row['bonus_leaves'] ?? 0) > 0) { $target = [(int) $uid, $m]; break 2; }
        } catch (\Throwable $e) { /* skip */ }
    }
}
if (!$target) { echo "no month recommends a bonus day on this database; cannot test the rule.\n"; exit(1); }
[$uid, $month] = $target;
$name = DB::table('t_sys_user')->where('id', $uid)->value('fullname');
echo "rider: $name (u$uid) · month $month\n\n";

echo "1 - the rider sees the SAME overtime minutes as management\n";
$mgrRow = app(PayrollService::class)->computeRow($uid, $month);
$u = actAs($uid);
$mine = call($pay, 'mySalary', ['month' => $month], $u)['row'] ?? [];
check('overtime minutes match', (int) $mine['overtime_minutes'], (int) $mgrRow['overtime_minutes']);
check('late minutes match', (int) $mine['late_minutes'], (int) $mgrRow['late_minutes']);
check('and the waived split travels', array_key_exists('late_waived_minutes', $mine), true);

echo "\n2 - ⭐ the bonus DAYS are hidden until management grants them\n";
$decided = null;
foreach (app(PayrollService::class)->leaveActionsForRow($mgrRow, $month) as $a) {
    if ($a['kind'] === 'overtime') { $decided = $a['status']; }
}
echo "  -- management recommends {$mgrRow['bonus_leaves']} day(s); decision is '" . ($decided ?? 'none') . "'\n";
check('the recommendation is still reported separately',
    (int) $mine['bonus_leaves_recommended'], (int) $mgrRow['bonus_leaves']);
check('status is named', in_array($mine['bonus_leaves_status'], ['pending', 'applied', 'waived'], true), true);
if ($decided === 'applied') {
    check('GRANTED → he sees the days', (float) $mine['bonus_leaves'] > 0, true);
} else {
    check('NOT granted → he sees zero, not the recommendation', (float) $mine['bonus_leaves'], 0.0);
    check('even though the engine recommends some', (int) $mgrRow['bonus_leaves'] > 0, true);
}

echo "\n3 - his own month carries per-day overtime and the verdicts\n";
$hist = call($rider, 'getMonthlyAttendance', ['month' => $month], $u)['history'] ?? [];
check('there are day rows', count($hist) > 0, true);
$withOt = array_values(array_filter($hist, fn ($d) => (int) ($d['overtime_minutes'] ?? 0) > 0));
if ($withOt) {
    $d = $withOt[0];
    $eng = $ot->overtimeForRange($uid, $d['date'], $d['date'])['total'];
    check('a day\'s overtime matches the engine', (int) $d['overtime_minutes'], (int) $eng);
    check('the verdict slot exists', array_key_exists('overtime_review', $d), true);
    // ⚠ The rule that must never break: no bonus-day figure anywhere in a rider's day row.
    check('no bonus-day figure leaks into the day row',
        array_key_exists('bonus_leaves', $d) || array_key_exists('bonus_days', $d), false);
} else {
    echo "  -- no overtime day in this month; per-day checks skipped\n";
}

echo "\n4 - his month summary matches the engine\n";
$sum = call($rider, 'getMonthlyAttendance', ['month' => $month], $u)['summary'] ?? [];
$start = $month . '-01';
$end = min(date('Y-m-t', strtotime($start)), date('Y-m-d'));
check('overtime total matches', (int) ($sum['overtime_minutes'] ?? -1), (int) $ot->overtimeForRange($uid, $start, $end)['total']);
$lateEng = $shift->sumLateOvertimeMinutes($uid, $start, $end);
check('late total matches', (int) ($sum['late_minutes'] ?? -1), (int) $lateEng['late_minutes']);
check('waived travels', (int) ($sum['late_waived_minutes'] ?? -1), (int) ($lateEng['late_waived_minutes'] ?? 0));
check('no bonus-day figure in the summary either',
    array_key_exists('bonus_leaves', $sum) || array_key_exists('overtime_bonus_leaves', $sum), false);

echo "\n5 - a verdict a manager records becomes visible to the rider (rolled back)\n";
$svc = app(DayReviewService::class);
$day = null;
foreach ($svc->itemsFor($uid, max($start, $svc->startDate()), $end, false) as $it) {
    if ($it['kind'] === 'overtime' && $it['status'] === 'pending' && empty($it['not_ready'])) { $day = $it; break; }
}
if (!$day) { echo "  -- no reviewable overtime day in this month; skipped\n"; }
else {
    DB::beginTransaction();
    try {
        $res = $svc->record($uid, $day['date'], 'overtime', 'verified', 68);
        check('the manager could record it', $res['success'], true);
        $svc->forget(); OvertimeService::forgetRanges(); app(PayrollService::class)->forgetAll();
        $u = actAs($uid);
        $hist2 = call($rider, 'getMonthlyAttendance', ['month' => $month], $u)['history'] ?? [];
        $seen = null;
        foreach ($hist2 as $d) { if ($d['date'] === $day['date']) { $seen = $d; } }
        check('the rider sees it as checked', $seen['overtime_review']['verdict'] ?? null, 'verified');
        // ⚠ A verdict must not hand him a bonus day — that is still management's to give.
        $mine2 = call($pay, 'mySalary', ['month' => $month], $u)['row'] ?? [];
        if ($decided !== 'applied') {
            check('verifying still grants him nothing', (float) $mine2['bonus_leaves'], 0.0);
        }
    } finally {
        DB::rollBack();
        $svc->forget(); OvertimeService::forgetRanges(); app(PayrollService::class)->forgetAll();
    }
}

echo "\n".($fail ? "FAILED" : "PASSED")."  $pass passed, $fail failed\n";
echo "day-review rows left behind: ".DB::table('t_hr_day_review')->count()."\n";
exit($fail ? 1 : 0);
