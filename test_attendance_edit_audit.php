<?php
/**
 * Phase 0 (Sep-6 2026) — provenance on an attendance time edit.
 * Root-script pattern; throwaway user id far outside the real range; runs inside a
 * transaction that is always rolled back, so the replica is untouched.
 *   php test_attendance_edit_audit.php
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const U    = 999903;          // the rider whose row is edited
const ACT  = 68;              // the manager doing the editing (Taimur)
const DATE = '2026-08-14';

$pass = 0; $fail = 0;
function check(string $what, $got, $want): void {
    global $pass, $fail;
    $ok = json_encode($got) === json_encode($want);
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $what
        . ($ok ? "\n" : "  got=".json_encode($got)."  want=".json_encode($want)."\n");
}

function reset_all(): void {
    DB::table('t_ops_attendance')->where('user_id', U)->delete();
}

function seed(?string $login, ?string $logout): int {
    DB::table('t_ops_attendance')->where('user_id', U)->delete();
    return (int) DB::table('t_ops_attendance')->insertGetId([
        'user_id' => U, 'attendance_date' => DATE,
        'login_time' => $login, 'logout_time' => $logout, 'created_at' => now(),
    ]);
}

/** Call the controller exactly as the route does. */
function store(array $payload): array {
    $req = Illuminate\Http\Request::create('/attendance', 'POST', $payload);
    $req->setLaravelSession(app('session.store'));
    app()->instance('request', $req);
    $res = app(\App\Http\Controllers\CRM\AttendanceController::class)->store($req);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

function auditRows(): array {
    if (!Schema::hasTable('t_sys_audit_log')) { return []; }
    return DB::table('t_sys_audit_log')
        ->where('entity_type', 'attendance')->where('user_id', ACT)
        ->orderByDesc('id')->limit(5)->get()->map(fn ($r) => (array) $r)->all();
}

Auth::guard('web')->loginUsingId(ACT);
echo "audit table present: ".var_export(Schema::hasTable('t_sys_audit_log'), true)."\n\n";

DB::beginTransaction();
try {
    // t_ops_attendance has an FK to t_sys_user, so the throwaway rider needs a row. It is
    // created inside the transaction and disappears with the rollback.
    DB::table('t_sys_user')->insert([
        'id' => U, 'fullname' => 'ZZ Test Rider', 'email' => 'zz-test-'.U.'@example.invalid',
        'password' => '-', 'created_at' => now(), 'created_by' => ACT,
    ]);

    echo "1 - overwriting a recorded checkout without a reason is refused\n";
    reset_all(); seed('10:52:00', '22:02:00');
    [$code, $j] = store(['user_id' => U, 'attendance_date' => DATE, 'logout_time' => '23:30']);
    check('http 422', $code, 422);
    check('reason_required flag', $j['reason_required'] ?? null, true);
    check('names the field', $j['fields'] ?? null, ['logout_time']);
    check('nothing was written',
        DB::table('t_ops_attendance')->where('user_id', U)->value('logout_time'), '22:02:00');

    echo "\n2 - the same edit WITH a reason saves, and records old to new\n";
    [$code, $j] = store(['user_id' => U, 'attendance_date' => DATE, 'logout_time' => '23:30',
                         'reason' => 'meter photo shows 23:30']);
    check('http 200', $code, 200);
    check('saved', $j['success'] ?? null, true);
    check('new time stored',
        DB::table('t_ops_attendance')->where('user_id', U)->value('logout_time'), '23:30:00');
    $a = auditRows()[0] ?? [];
    check('audit action', $a['action'] ?? null, 'attendance_time_edited');
    check('audit note = the reason', $a['note'] ?? null, 'meter photo shows 23:30');
    $changes = json_decode($a['changes'] ?? '[]', true);
    check('audit keeps the OLD value', $changes['logout_time']['old'] ?? null, '22:02:00');
    check('audit keeps the NEW value', $changes['logout_time']['new'] ?? null, '23:30');

    echo "\n3 - ADDING a missing checkout is not an edit, no reason asked\n";
    reset_all(); seed('10:52:00', null);
    $n0 = count(auditRows());
    [$code, $j] = store(['user_id' => U, 'attendance_date' => DATE, 'logout_time' => '19:04']);
    check('http 200', $code, 200);
    check('saved', DB::table('t_ops_attendance')->where('user_id', U)->value('logout_time'), '19:04:00');
    check('no audit row for an add', count(auditRows()), $n0);

    echo "\n4 - re-sending the SAME time is not a change\n";
    reset_all(); seed('10:52:00', '22:02:00');
    $n0 = count(auditRows());
    [$code, $j] = store(['user_id' => U, 'attendance_date' => DATE, 'logout_time' => '22:02']);
    check('http 200 (H:i vs H:i:s must not read as a change)', $code, 200);
    check('no audit row', count(auditRows()), $n0);

    echo "\n5 - both times changed at once, one audit row naming both\n";
    reset_all(); seed('10:52:00', '22:02:00');
    [$code, $j] = store(['user_id' => U, 'attendance_date' => DATE, 'login_time' => '11:15',
                         'logout_time' => '23:30', 'reason' => 'phone was dead all morning']);
    check('http 200', $code, 200);
    $changes = json_decode(auditRows()[0]['changes'] ?? '[]', true);
    check('login recorded', $changes['login_time']['old'] ?? null, '10:52:00');
    check('logout recorded', $changes['logout_time']['old'] ?? null, '22:02:00');

    echo "\n6 - a missing reason names BOTH fields\n";
    reset_all(); seed('10:52:00', '22:02:00');
    [$code, $j] = store(['user_id' => U, 'attendance_date' => DATE,
                         'login_time' => '11:15', 'logout_time' => '23:30']);
    check('http 422', $code, 422);
    check('both fields named', $j['fields'] ?? null, ['login_time', 'logout_time']);

    echo "\n7 - clearing a checkout bypass keeps who granted it and why\n";
    if (!Schema::hasColumn('t_ops_attendance', 'checkout_unlock_until')) {
        echo "  -- skipped, wave-2 columns not on this database\n";
    } else {
        reset_all();
        $id = seed('10:52:00', null);
        DB::table('t_ops_attendance')->where('id', $id)->update([
            'checkout_unlock_until'  => now()->addMinutes(10),
            'checkout_unlock_by'     => ACT,
            'checkout_unlock_reason' => 'phone died at the last drop',
        ]);
        $req = Illuminate\Http\Request::create('/attendance/checkout-unlock', 'POST',
            ['attendance_id' => $id, 'action' => 'clear', 'reason' => 'granted by mistake']);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);
        $res = app(\App\Http\Controllers\CRM\AttendanceController::class)->checkoutUnlock($req);
        check('cleared ok', json_decode($res->getContent(), true)['success'] ?? null, true);
        check('columns nulled',
            DB::table('t_ops_attendance')->where('id', $id)->value('checkout_unlock_by'), null);
        $a = auditRows()[0] ?? [];
        check('audit action', $a['action'] ?? null, 'checkout_unlock_cleared');
        $changes = json_decode($a['changes'] ?? '[]', true);
        check('the granter survives the clear', (int) ($changes['checkout_unlock_by'] ?? 0), ACT);
        check('the grant reason survives',
            $changes['checkout_unlock_reason'] ?? null, 'phone died at the last drop');
    }
} finally {
    DB::rollBack();
}

echo "\n".($fail ? "FAILED" : "PASSED")."  $pass passed, $fail failed\n";
echo "left behind: ".DB::table('t_ops_attendance')->where('user_id', U)->count()." attendance rows\n";
exit($fail ? 1 : 0);
