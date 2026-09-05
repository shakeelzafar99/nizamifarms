<?php
/**
 * DEV-ONLY consistency check for the Reports per-employee grouping (Sep-2026).
 * NEVER upload this file to production — it is a local harness, not app code.
 *
 * Run from this folder:   php test_reports_by_employee.php
 *
 * Proves, against the local replica, that the new `by_employee` groupings cannot disagree
 * with the numbers they drill into:
 *   • salaries: per-employee sums == per-date sums == section total == monthly summary card
 *     (and the NF/Khaas split survives the regrouping);
 *   • expenses: per-category employee sums == category total, and the row COUNTS match too;
 *   • the expense headline itself is unmoved by the t_req_master join.
 * Re-run this before changing ReportsController::salariesDetail() or the expenses grouping.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Act as a user who CAN see Khaas so the drill is exercised in full.
$u = App\Models\User::find(68) ?: App\Models\User::find(67);
Illuminate\Support\Facades\Auth::guard('web')->setUser($u);
Illuminate\Support\Facades\Auth::shouldUse('web');
echo "acting as: {$u->fullname} (id {$u->id})\n\n";

$c = new App\Http\Controllers\API\ReportsController();
$fail = 0;
$money = fn($v) => number_format((float)$v, 2, '.', ',');
$eq = function ($a, $b) { return abs(((float)$a) - ((float)$b)) < 0.01; };

foreach (['2026-06','2026-07','2026-08'] as $m) {
    $req = Illuminate\Http\Request::create('/x', 'GET', ['month' => $m]);
    $resp = $c->getMonthDetails($req);
    $d = json_decode($resp->getContent(), true);
    if (empty($d['success'])) { echo "$m: FAILED — ".($d['error'] ?? '?')."\n"; $fail++; continue; }
    $d = $d['data'];

    echo "=== $m ({$d['month_name']}) ===\n";

    // --- Salaries -------------------------------------------------------
    $s = $d['salaries'];
    $byDate = array_sum(array_column($s['by_date'], 'total'));
    $byEmp  = array_sum(array_column($s['by_employee'], 'total'));
    $cntD = 0; foreach ($s['by_date'] as $r) { $cntD += count($r['items']); }
    $cntE = array_sum(array_column($s['by_employee'], 'count'));
    $ok = $eq($byDate, $s['total']) && $eq($byEmp, $s['total']) && $cntD === $s['count'] && $cntE === $s['count'];
    printf("  salaries  total %14s | by_date %14s | by_employee %14s | rows %d/%d/%d | people %d  %s\n",
        $money($s['total']), $money($byDate), $money($byEmp), $s['count'], $cntD, $cntE,
        $s['employee_count'], $ok ? 'OK' : '*** MISMATCH ***');
    if (!$ok) $fail++;
    // NF/Khaas split must also survive the regrouping
    $enf = array_sum(array_column($s['by_employee'], 'total_nf'));
    $ekh = array_sum(array_column($s['by_employee'], 'total_khaas'));
    $ok2 = $eq($enf, $s['total_nf']) && $eq($ekh, $s['total_khaas']);
    printf("            NF %s vs %s | KHAAS %s vs %s  %s\n", $money($enf), $money($s['total_nf']),
        $money($ekh), $money($s['total_khaas']), $ok2 ? 'OK' : '*** MISMATCH ***');
    if (!$ok2) $fail++;

    // --- Expenses -------------------------------------------------------
    $e = $d['expenses'];
    $catSum = array_sum(array_column($e['by_category'], 'total'));
    $ok3 = $eq($catSum, $e['total']);
    printf("  expenses  total %14s | by_category %14s | %d cats  %s\n",
        $money($e['total']), $money($catSum), count($e['by_category']), $ok3 ? 'OK' : '*** MISMATCH ***');
    if (!$ok3) $fail++;

    $bad = []; $multi = 0;
    foreach ($e['by_category'] as $cat) {
        $et = array_sum(array_column($cat['by_employee'], 'total'));
        $ec = array_sum(array_column($cat['by_employee'], 'count'));
        $ei = 0; foreach ($cat['by_employee'] as $emp) { $ei += count($emp['items']); }
        if (!$eq($et, $cat['total']) || $ec !== $cat['count'] || $ei !== count($cat['items'])
            || $cat['employee_count'] !== count($cat['by_employee'])) {
            $bad[] = $cat['category'];
        }
        if ($cat['employee_count'] > 1) $multi++;
    }
    printf("            per-category employee sums: %s | multi-person cats: %d of %d\n",
        $bad ? ('*** MISMATCH: '.implode(', ', $bad).' ***') : 'all OK', $multi, count($e['by_category']));
    if ($bad) $fail++;

    // Headline consistency with the monthly summary card
    $sreq = Illuminate\Http\Request::create('/x', 'GET', ['months' => 12]);
    $sum = json_decode($c->getMonthlySummary($sreq)->getContent(), true)['data'] ?? [];
    foreach ($sum as $row) {
        if (($row['month'] ?? '') === $m || ($row['month_key'] ?? '') === $m) {
            $okS = $eq($row['salaries'] ?? 0, $s['total']) && $eq($row['expenses'] ?? 0, $e['total']);
            printf("            summary card: salaries %s vs %s | expenses %s vs %s  %s\n",
                $money($row['salaries'] ?? 0), $money($s['total']),
                $money($row['expenses'] ?? 0), $money($e['total']), $okS ? 'OK' : '*** MISMATCH ***');
            if (!$okS) $fail++;
            break;
        }
    }
    echo "\n";
}

// Petrol spot check against the raw SQL the plan quoted.
$req = Illuminate\Http\Request::create('/x', 'GET', ['month' => '2026-08']);
$d = json_decode($c->getMonthDetails($req)->getContent(), true)['data'];
foreach ($d['expenses']['by_category'] as $cat) {
    if ($cat['category'] === 'Petrol') {
        echo "Aug Petrol — {$cat['count']} rows, ".number_format($cat['total'])." over {$cat['employee_count']} people:\n";
        foreach ($cat['by_employee'] as $emp) {
            printf("   %-16s %3d  %10s\n", $emp['employee'], $emp['count'], number_format($emp['total']));
        }
    }
}
echo "\n", $fail ? "*** $fail CHECK(S) FAILED ***\n" : "ALL INVARIANTS PASSED\n";
