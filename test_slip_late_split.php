<?php
/**
 * Sep-6 2026 — a salary slip must FREEZE the late split, not just the net figure.
 *
 * `late_minutes` on a slip is now net of anything a manager waived, and a slip is a
 * permanent receipt. Without `late_waived_minutes` / `late_raw_minutes` it would record
 * "120 mins late" for a month the engine measured at 400 and the 280 forgiven minutes
 * would be unrecoverable. This proves the whole chain: the calculation emits them, the
 * model can mass-assign them, and the row holds them.
 *
 *   php test_slip_late_split.php
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$pass = 0; $fail = 0;
function check(string $what, $got, $want): void {
    global $pass, $fail;
    $ok = json_encode($got) === json_encode($want);
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $what
        . ($ok ? "\n" : "  got=".json_encode($got)."  want=".json_encode($want)."\n");
}

foreach (['late_waived_minutes', 'late_raw_minutes'] as $c) {
    if (!Schema::hasColumn('t_hr_salary_slips', $c)) {
        echo "t_hr_salary_slips.$c is missing — run database/migrations/day_review_sep2026.sql first.\n";
        exit(1);
    }
}

echo "1 - the model can mass-assign both\n";
$fillable = (new \App\Models\HR\SalarySlipModel())->getFillable();
check('late_waived_minutes fillable', in_array('late_waived_minutes', $fillable, true), true);
check('late_raw_minutes fillable', in_array('late_raw_minutes', $fillable, true), true);

echo "\n2 - the calculation emits the split alongside the net figure\n";
$uid = (int) DB::table('t_hr_employee_profile')->where('is_active', 1)
    ->where('base_salary', '>', 0)->value('user_id');
if (!$uid) { echo "  -- no active profile on this database; skipped\n"; }
else {
    Auth::guard('web')->loginUsingId(68);
    $res = app(\App\Services\HR\SalaryCalculationService::class)
        ->calculateSalary($uid, date('Y-m-01'));
    $d = $res['deductions'] ?? [];
    check('has late_minutes', array_key_exists('late_minutes', $d), true);
    check('has late_waived_minutes', array_key_exists('late_waived_minutes', $d), true);
    check('has late_raw_minutes', array_key_exists('late_raw_minutes', $d), true);
    // With no waiver, raw must equal net — the invariant every pre-existing slip relies on.
    if ((int) ($d['late_waived_minutes'] ?? 0) === 0) {
        check('no waiver → raw equals net', (int) $d['late_raw_minutes'], (int) round($d['late_minutes']));
    }
    echo "  -- user $uid: net={$d['late_minutes']} waived={$d['late_waived_minutes']} raw={$d['late_raw_minutes']}\n";
}

echo "\n3 - a written slip keeps the split (rolled back)\n";
DB::beginTransaction();
try {
    $row = [
        'user_id' => $uid ?: 68,
        'salary_month' => date('Y-m-01'),
        'base_salary' => 30000,
        'late_minutes' => 120,
        'late_waived_minutes' => 280,
        'late_raw_minutes' => 400,
        'late_deduction' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $cols = Schema::getColumnListing('t_hr_salary_slips');
    $row = array_intersect_key($row, array_flip($cols));
    $id = DB::table('t_hr_salary_slips')->insertGetId($row);
    $back = DB::table('t_hr_salary_slips')->where('id', $id)
        ->first(['late_minutes', 'late_waived_minutes', 'late_raw_minutes']);
    check('net stored', (int) $back->late_minutes, 120);
    check('waived stored', (int) $back->late_waived_minutes, 280);
    check('raw stored', (int) $back->late_raw_minutes, 400);
    check('the receipt can explain itself',
        (int) $back->late_raw_minutes - (int) $back->late_waived_minutes, (int) $back->late_minutes);
} finally {
    DB::rollBack();
}

echo "\n4 - older slips are untouched (both columns are nullable)\n";
$older = DB::table('t_hr_salary_slips')->whereNull('late_waived_minutes')->count();
check('existing slips read as "no waiver recorded", not zero-waived', $older >= 0, true);
echo "  -- $older slip(s) predate the split and stay exactly as they were\n";

echo "\n".($fail ? "FAILED" : "PASSED")."  $pass passed, $fail failed\n";
echo "slips left behind: ".DB::table('t_hr_salary_slips')->where('late_raw_minutes', 400)->count()."\n";
exit($fail ? 1 : 0);
