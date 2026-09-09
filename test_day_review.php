<?php
/**
 * Day review (Sep-6 2026) — the verify-overtime / waive-late layer.
 * Root-script pattern. Throwaway user, everything inside a transaction that is always
 * rolled back, so the replica is untouched.
 *
 *   php test_day_review.php
 *
 * The rule under test above all others: an UNREVIEWED day counts IN FULL.
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
use Illuminate\Support\Facades\Schema;

const U   = 999904;
const ACT = 68;                 // Taimur

$pass = 0; $fail = 0;
function check(string $what, $got, $want): void {
    global $pass, $fail;
    $ok = json_encode($got) === json_encode($want);
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $what
        . ($ok ? "\n" : "  got=".json_encode($got)."  want=".json_encode($want)."\n");
}

/** Every cache that could hide a change, dropped together. */
function flushAll(): void {
    app(DayReviewService::class)->forget();
    OvertimeService::forgetRanges();
    app(PayrollService::class)->forgetAll();
}

function svc(): DayReviewService { return app(DayReviewService::class); }
function shift(): ShiftResolutionService { return app(ShiftResolutionService::class); }
function ot(): OvertimeService { return app(OvertimeService::class); }

/** Seed one attendance day. $shiftStart is the frozen expected start. */
function day(string $date, ?string $in, ?string $out, ?string $shiftStart = '09:00:00', $lateSnap = null): int {
    DB::table('t_ops_attendance')->where('user_id', U)->whereDate('attendance_date', $date)->delete();
    $id = (int) DB::table('t_ops_attendance')->insertGetId([
        'user_id' => U, 'attendance_date' => $date,
        'login_time' => $in, 'logout_time' => $out,
        'expected_shift_start' => $shiftStart, 'late_minutes' => $lateSnap,
        'created_at' => now(),
    ]);
    flushAll();
    return $id;
}

if (!Schema::hasTable('t_hr_day_review')) {
    echo "t_hr_day_review is not on this database — run database/migrations/day_review_sep2026.sql first.\n";
    exit(1);
}

Auth::guard('web')->loginUsingId(ACT);
$START = svc()->startDate();
echo "start date: $START\n";
// Dates safely inside the reviewable window and in the past.
$D1 = date('Y-m-d', strtotime($START . ' +3 days'));
$D2 = date('Y-m-d', strtotime($START . ' +4 days'));
$MONTH = substr($START, 0, 7);
if ($D2 >= date('Y-m-d')) { echo "⚠ start date is too close to today for this test\n"; exit(1); }
echo "using $D1 and $D2 (month $MONTH)\n\n";

DB::beginTransaction();
try {
    DB::table('t_sys_user')->insert([
        'id' => U, 'fullname' => 'ZZ Review Rider', 'email' => 'zz-rev-'.U.'@example.invalid',
        'password' => '-', 'created_at' => now(), 'created_by' => ACT,
    ]);

    echo "1 - an UNREVIEWED day counts in full (the rule everything rests on)\n";
    day($D1, '09:00:00', '20:00:00');              // 11h worked, 9h target => 120 min OT
    check('overtime as computed', ot()->overtimeForRange(U, $D1, $D1)['total'], 120);
    check('no review yet', svc()->reviewFor(U, $D1, 'overtime'), null);
    check('effectiveOvertime passes it through', svc()->effectiveOvertime(U, $D1, 120), 120);

    echo "\n2 - VERIFIED changes nothing at all\n";
    $r = svc()->record(U, $D1, 'overtime', 'verified', ACT);
    check('accepted', $r['success'], true);
    flushAll();
    check('total unchanged', ot()->overtimeForRange(U, $D1, $D1)['total'], 120);
    check('status reads verified', svc()->reviewFor(U, $D1, 'overtime')['verdict'], 'verified');

    echo "\n3 - ADJUSTED lowers the day, and the month follows\n";
    $r = svc()->record(U, $D1, 'overtime', 'adjusted', ACT, ['minutes' => 45, 'reason' => 'meter photo shows 18:45']);
    check('accepted', $r['success'], true);
    flushAll();
    check('day now 45', ot()->overtimeForRange(U, $D1, $D1)['total'], 45);
    $det = ot()->overtimeForRange(U, $D1, $D1)['details'][$D1];
    check('raw is still remembered', $det['raw_minutes'], 120);
    check('effective is what shows', $det['minutes'], 45);
    check('the verdict travels with the day', $det['review']['verdict'], 'adjusted');

    echo "\n4 - adjusting ABOVE the computed figure is refused\n";
    $r = svc()->record(U, $D1, 'overtime', 'adjusted', ACT, ['minutes' => 500, 'reason' => 'x']);
    check('refused', $r['success'], false);
    check('says the real figure', str_contains($r['message'], '120'), true);

    echo "\n5 - an adjustment with no reason is refused\n";
    $r = svc()->record(U, $D1, 'overtime', 'adjusted', ACT, ['minutes' => 30]);
    check('refused', $r['success'], false);

    echo "\n6 - WAIVED overtime means the day was not overtime at all\n";
    $r = svc()->record(U, $D1, 'overtime', 'waived', ACT, ['reason' => 'he was at the workshop, not delivering']);
    check('accepted', $r['success'], true);
    flushAll();
    $res = ot()->overtimeForRange(U, $D1, $D1);
    check('total is 0', $res['total'], 0);
    check('the day is OUT of dates', isset($res['dates'][$D1]), false);
    check('but still in details, so the drill can show it', isset($res['details'][$D1]), true);

    echo "\n7 - re-verifying restores the day (a verdict is never one-way)\n";
    svc()->record(U, $D1, 'overtime', 'verified', ACT);
    flushAll();
    check('back to 120', ot()->overtimeForRange(U, $D1, $D1)['total'], 120);
    check('one row per day+kind, updated in place',
        DB::table('t_hr_day_review')->where('user_id', U)->where('kind', 'overtime')->count(), 1);

    echo "\n8 - LATE: a waive removes minutes, and the day stays visible\n";
    day($D2, '10:12:00', '18:00:00');              // 72 min late against a 09:00 shift
    check('late as computed', shift()->sumLateOvertimeMinutes(U, $D2, $D2)['late_minutes'], 72);
    $r = svc()->record(U, $D2, 'late', 'waived', ACT, ['waived' => 60, 'reason' => 'told me the night before']);
    check('accepted', $r['success'], true);
    flushAll();
    $tot = shift()->sumLateOvertimeMinutes(U, $D2, $D2);
    check('12 min still count', $tot['late_minutes'], 12);
    check('60 recorded as waived', $tot['late_waived_minutes'], 60);
    check('the month remembers what it really was', $tot['late_raw_minutes'], 72);
    check('still counts as a late DAY', $tot['late_days'], 1);
    $bd = shift()->lateDaysBreakdown(U, $D2, $D2);
    check('the drill still lists the day', count($bd), 1);
    check('drill shows what counts', $bd[0]['minutes'], 12);
    check('drill shows what it was', $bd[0]['raw_minutes'], 72);
    check('drill shows the waive', $bd[0]['waived'], 60);

    echo "\n9 - waiving more minutes than the day had is refused\n";
    $r = svc()->record(U, $D2, 'late', 'waived', ACT, ['waived' => 999, 'reason' => 'x']);
    check('refused', $r['success'], false);
    check('names the real figure', str_contains($r['message'], '72'), true);

    echo "\n10 - a FULL waive leaves 0 minutes but the day is still on the screen\n";
    svc()->record(U, $D2, 'late', 'waived', ACT, ['waived' => 72, 'reason' => 'bike broke down, told me at 9']);
    flushAll();
    check('0 minutes count', shift()->sumLateOvertimeMinutes(U, $D2, $D2)['late_minutes'], 0);
    check('but it is still a late day', shift()->sumLateOvertimeMinutes(U, $D2, $D2)['late_days'], 1);
    check('and still in the drill', count(shift()->lateDaysBreakdown(U, $D2, $D2)), 1);

    echo "\n11 - the SUPERSEDE rule: editing the day retires the verdict\n";
    day($D1, '09:00:00', '20:00:00');
    svc()->record(U, $D1, 'overtime', 'adjusted', ACT, ['minutes' => 30, 'reason' => 'checked']);
    flushAll();
    check('adjustment is live', ot()->overtimeForRange(U, $D1, $D1)['total'], 30);
    // Someone types a different checkout.
    DB::table('t_ops_attendance')->where('user_id', U)->whereDate('attendance_date', $D1)
        ->update(['logout_time' => '22:00:00']);
    flushAll();
    $hit = svc()->supersedeIfChanged(U, $D1, 'out 20:00 → 22:00 by Taimur');
    check('the review was retired', $hit, true);
    flushAll();
    check('the day counts in FULL again', ot()->overtimeForRange(U, $D1, $D1)['total'], 780 - 540);
    check('and reads as unreviewed', svc()->reviewFor(U, $D1, 'overtime'), null);
    $items = svc()->itemsFor(U, $D1, $D1, false);
    check('it is back in the queue', $items[0]['status'], 'pending');

    echo "\n12 - an UNCHANGED day is not superseded by an unrelated save\n";
    svc()->record(U, $D1, 'overtime', 'verified', ACT);
    flushAll();
    check('nothing retired', svc()->supersedeIfChanged(U, $D1, 'no change'), false);
    check('the verdict survives', svc()->reviewFor(U, $D1, 'overtime')['verdict'], 'verified');

    echo "\n13 - a day still open cannot be judged for overtime\n";
    day($D2, '09:00:00', null);
    $r = svc()->record(U, $D2, 'overtime', 'verified', ACT);
    check('refused', $r['success'], false);
    check('says why', str_contains(strtolower($r['message']), 'checked out'), true);

    echo "\n14 - nor while his checkout unlock is still running\n";
    if (Schema::hasColumn('t_ops_attendance', 'checkout_unlock_until')) {
        day($D2, '09:00:00', '21:00:00');
        DB::table('t_ops_attendance')->where('user_id', U)->whereDate('attendance_date', $D2)
            ->update(['checkout_unlock_until' => now()->addMinutes(9)]);
        flushAll();
        $r = svc()->record(U, $D2, 'overtime', 'verified', ACT);
        check('refused', $r['success'], false);
        check('says why', str_contains(strtolower($r['message']), 'unlocked'), true);
        DB::table('t_ops_attendance')->where('user_id', U)->whereDate('attendance_date', $D2)
            ->update(['checkout_unlock_until' => null]);
        flushAll();
    } else {
        echo "  -- skipped, wave-2 columns not on this database\n";
    }

    echo "\n15 - nothing before the start date is reviewable, and it says so\n";
    $old = date('Y-m-d', strtotime($START . ' -5 days'));
    day($old, '09:00:00', '20:00:00');
    $r = svc()->record(U, $old, 'overtime', 'verified', ACT);
    check('refused', $r['success'], false);
    check('the reason is the start date, not "nothing to review"',
        str_contains($r['message'], $START), true);
    check('and it is invisible to reviewFor', svc()->reviewFor(U, $old, 'overtime'), null);

    echo "\n16 - a future day is refused\n";
    $r = svc()->record(U, date('Y-m-d', strtotime('+2 days')), 'overtime', 'verified', ACT);
    check('refused', $r['success'], false);

    echo "\n17 - the queue holds only ACTIONABLE days\n";
    DB::table('t_ops_attendance')->where('user_id', U)->delete();
    DB::table('t_hr_day_review')->where('user_id', U)->delete();
    day($D1, '09:00:00', '20:00:00');              // overtime, no lateness
    day($D2, '10:12:00', '17:00:00');              // late, no overtime
    $clean = date('Y-m-d', strtotime($START . ' +5 days'));
    day($clean, '09:00:00', '17:30:00');           // on time, under target: nothing to do
    flushAll();
    $items = svc()->itemsFor(U, $START, date('Y-m-d'), false);
    $kinds = array_map(fn ($i) => $i['date'].':'.$i['kind'], $items);
    sort($kinds);
    check('exactly the two days that need a judgement', $kinds, [$D1.':overtime', $D2.':late']);
    check('the clean day is nowhere in it', in_array($clean.':overtime', $kinds, true), false);

    echo "\n18 - the month summary counts what a manager still owes\n";
    $s = svc()->summary(U, $MONTH);
    check('enabled', $s['enabled'], true);
    check('1 overtime day', $s['overtime']['days'], 1);
    check('1 pending', $s['overtime']['pending'], 1);
    check('1 late day', $s['late']['days'], 1);
    svc()->record(U, $D1, 'overtime', 'verified', ACT);
    svc()->record(U, $D2, 'late', 'waived', ACT, ['waived' => 30, 'reason' => 'traffic, told me']);
    flushAll();
    $s = svc()->summary(U, $MONTH);
    check('overtime reviewed', $s['overtime']['reviewed'], 1);
    check('nothing pending', $s['overtime']['pending'], 0);
    check('the waive is summed', $s['late']['waived_minutes'], 30);

    echo "\n19 - payroll sees the same numbers (one engine)\n";
    DB::table('t_hr_employee_profile')->updateOrInsert(['user_id' => U], ['base_salary' => 30000]);
    flushAll();
    $row = app(PayrollService::class)->computeRow(U, $MONTH);
    check('late minutes are net of the waive', $row['late_minutes'], 72 - 30);
    check('and the row carries the split', $row['late_waived_minutes'], 30);
    check('raw is remembered', $row['late_raw_minutes'], 72);
    check('the review standing is on the row', $row['day_review']['late']['waived_minutes'], 30);

    echo "\n20 - editing a day through the REAL endpoint retires its review\n";
    // Test 11 proved the service rule; this proves the controller actually calls it, which is
    // the part that would silently rot if someone added a fifth way to edit a time.
    day($D1, '09:00:00', '20:00:00');
    svc()->record(U, $D1, 'overtime', 'verified', ACT);
    flushAll();
    check('verdict is live', svc()->reviewFor(U, $D1, 'overtime')['verdict'], 'verified');
    $req = Illuminate\Http\Request::create('/attendance', 'POST', [
        'user_id' => U, 'attendance_date' => $D1, 'logout_time' => '22:30',
        'reason' => 'he showed me the delivery list',
    ]);
    $req->setLaravelSession(app('session.store'));
    app()->instance('request', $req);
    $res = app(\App\Http\Controllers\CRM\AttendanceController::class)->store($req);
    $j = json_decode($res->getContent(), true);
    flushAll();
    check('the save succeeded', $j['success'] ?? null, true);
    check('and it says the day goes back for a fresh look', $j['review_requeued'] ?? null, true);
    check('the verdict is gone', svc()->reviewFor(U, $D1, 'overtime'), null);
    check('the day counts on its NEW figure', ot()->overtimeForRange(U, $D1, $D1)['total'], 810 - 540);

    echo "\n21 - a stale waive can never push the month negative\n";
    // The day is edited DOWN to less lateness than was already waived.
    DB::table('t_ops_attendance')->where('user_id', U)->whereDate('attendance_date', $D2)
        ->update(['login_time' => '09:05:00', 'late_minutes' => null]);
    flushAll();
    $tot = shift()->sumLateOvertimeMinutes(U, $D2, $D2);
    check('never below zero', $tot['late_minutes'] >= 0, true);
    check('waive is clamped to the day', $tot['late_waived_minutes'] <= 5, true);
} finally {
    DB::rollBack();
}

echo "\n".($fail ? "FAILED" : "PASSED")."  $pass passed, $fail failed\n";
echo "left behind: reviews=".DB::table('t_hr_day_review')->where('user_id', U)->count()
    ."  attendance=".DB::table('t_ops_attendance')->where('user_id', U)->count()."\n";
exit($fail ? 1 : 0);
