<?php
/**
 * Sep-6 2026 — the two RiderController paths whose late calculation moved onto
 * ShiftResolutionService::lateForDay() must still answer, and must still agree with the
 * web engine day for day. Cheap insurance against the consolidation having broken a screen
 * that no unit test covers.
 *
 *   php smoke_rider_attendance.php
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

$month = date('Y-m');
$shift = app(\App\Services\ShiftResolutionService::class);
$ctl   = app(\App\Http\Controllers\API\RiderController::class);

// Riders with attendance this month.
$riders = DB::table('t_ops_attendance')
    ->whereBetween('attendance_date', [$month.'-01', date('Y-m-t')])
    ->whereNotNull('login_time')->where('login_time', '!=', '')
    ->distinct()->limit(6)->pluck('user_id');

echo "month $month · riders ".count($riders)."\n\n";

foreach ($riders as $uid) {
    $uid = (int) $uid;
    $name = DB::table('t_sys_user')->where('id', $uid)->value('fullname');
    echo "— $name (u$uid)\n";

    // 1. The rider's OWN month (getMonthlyAttendance).
    // ⚠ Auth::user() in the controllers reads the DEFAULT guard, which is sanctum here —
    // not 'web'. Setting only the web guard leaves the sanctum guard's cached user in
    // place and the next call answers as the PREVIOUS person.
    $riderModel = \App\Models\User::find($uid);
    Auth::guard('web')->setUser($riderModel);
    Auth::setUser($riderModel);
    $req = Illuminate\Http\Request::create('/api/rider/attendance/monthly', 'GET',
        ['month' => $month]);
    $req->setUserResolver(fn () => $riderModel);
    app()->instance('request', $req);
    try {
        $res = $ctl->getMonthlyAttendance($req);
        $j = json_decode($res->getContent(), true);
        check('  own month answers', $j['success'] ?? null, true);
        $hist = $j['history'] ?? [];
        check('  it has day rows', is_array($hist) && count($hist) > 0, true);
        // The per-day late it prints must equal the engine's per-day figure.
        $bad = 0;
        foreach ($hist as $row) {
            $d = substr((string) ($row['date'] ?? ''), 0, 10);
            if (!$d || empty($row['login_time'])) { continue; }
            $att = DB::table('t_ops_attendance')->where('user_id', $uid)
                ->whereDate('attendance_date', $d)
                ->first(['login_time', 'late_minutes', 'expected_shift_start']);
            if (!$att) { continue; }
            $eng = $shift->lateForDay($uid, $d, $att->login_time, $att->late_minutes,
                                      $att->expected_shift_start, ($row['status'] ?? '') === 'half_day');
            if ((int) ($row['late_minutes'] ?? 0) !== (int) $eng['minutes']) { $bad++; }
        }
        check('  every day agrees with the engine', $bad, 0);
    } catch (\Throwable $e) {
        check('  own month answers', 'threw: '.$e->getMessage(), true);
    }

    // 2. The MANAGER's per-day view of the same rider.
    // ⚠ A FRESH manager model every iteration. Eloquent caches the loaded relations on the
    // instance, and hasMobilePermission() reads them — reusing one across logins made this
    // endpoint answer "you do not have permission" purely as an artifact of the loop. In
    // production each request resolves its own user, so this only bites a script like this.
    $mgr = 68;
    $mgrModel = \App\Models\User::find($mgr);
    Auth::guard('web')->setUser($mgrModel);
    Auth::setUser($mgrModel);
    $req2 = Illuminate\Http\Request::create('/api/rider/store-attendance/employee-details', 'GET',
        ['user_id' => $uid, 'month' => $month]);
    $req2->setUserResolver(fn () => $mgrModel);
    app()->instance('request', $req2);
    try {
        $res2 = $ctl->getStoreAttendanceEmployeeDetails($req2);
        $j2 = json_decode($res2->getContent(), true);
        check('  manager per-day answers', $j2['success'] ?? null, true);
        if (empty($j2['success'])) { echo "         msg: ".substr((string)($j2['message'] ?? '?'),0,140)."
"; }
        $recs = $j2['daily_records'] ?? [];
        check('  it has day rows', is_array($recs), true);
        $bad2 = 0;
        foreach ($recs as $row) {
            $d = substr((string) ($row['attendance_date'] ?? ''), 0, 10);
            if (!$d || empty($row['login_time'])) { continue; }
            $att = DB::table('t_ops_attendance')->where('user_id', $uid)
                ->whereDate('attendance_date', $d)
                ->first(['login_time', 'late_minutes', 'expected_shift_start']);
            if (!$att) { continue; }
            // ⚠ default_shift null here — this screen has never guessed 09:00.
            $eng = $shift->lateForDay($uid, $d, $att->login_time, $att->late_minutes,
                                      $att->expected_shift_start, !empty($row['is_half_day']),
                                      ['default_shift' => null]);
            if ((int) ($row['late_minutes'] ?? 0) !== (int) $eng['minutes']) { $bad2++; }
        }
        check('  every day agrees with the engine', $bad2, 0);
    } catch (\Throwable $e) {
        check('  manager per-day answers', 'threw: '.$e->getMessage(), true);
    }
    echo "\n";
}

// ── The manager's DAILY board ────────────────────────────────────────────────────────
// Sep-6 2026: this used to be a FIFTH inline copy of the late rule — it ignored the frozen
// snapshot and only counted lateness once the rider had checked OUT, so a rider still on
// duty read as 0 minutes late all day. It now goes through lateForDay() like everything else.
echo "— the manager's daily board (getStoreAttendanceDaily)\n";
$mgrModel = \App\Models\User::find(68);
Auth::guard('web')->setUser($mgrModel);
Auth::setUser($mgrModel);
$boardRows = 0; $boardBad = 0; $openDayLate = 0;
foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-2 days')),
          date('Y-m-d', strtotime('-3 days'))] as $d) {
    $req = Illuminate\Http\Request::create('/api/rider/store-attendance/daily', 'GET', ['date' => $d]);
    $req->setUserResolver(fn () => $mgrModel);
    app()->instance('request', $req);
    try {
        $j = json_decode(app(\App\Http\Controllers\API\RiderController::class)
            ->getStoreAttendanceDaily($req)->getContent(), true);
    } catch (\Throwable $e) {
        check('  board answers for ' . $d, 'threw: ' . $e->getMessage(), true);
        continue;
    }
    foreach (($j['attendance'] ?? []) as $row) {
        if (empty($row['login_time'])) { continue; }
        $att = DB::table('t_ops_attendance')->where('user_id', $row['user_id'])
            ->whereDate('attendance_date', $d)
            ->first(['login_time', 'logout_time', 'late_minutes', 'expected_shift_start']);
        if (!$att) { continue; }
        $eng = $shift->lateForDay((int) $row['user_id'], $d, $att->login_time, $att->late_minutes,
                                  $att->expected_shift_start, !empty($row['is_half_day']));
        $boardRows++;
        if ((int) $row['late_minutes'] !== (int) $eng['minutes']) { $boardBad++; }
        // The behaviour that was broken: a rider with no checkout must still show his lateness.
        if (empty($att->logout_time) && $eng['minutes'] > 0 && (int) $row['late_minutes'] > 0) {
            $openDayLate++;
        }
    }
}
check('  every row matches the engine', $boardBad, 0);
check('  it looked at some rows', $boardRows > 0, true);
echo "  -- rows {$boardRows}; still-on-duty rows correctly showing lateness: {$openDayLate}\n\n";

echo ($fail ? "FAILED" : "PASSED") . "  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
