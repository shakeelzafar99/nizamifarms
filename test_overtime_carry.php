<?php
/**
 * Carried-overtime engine checks. Uses a throwaway user id far outside the real range so it
 * can never touch a real employee's entitlement; cleans up after itself.
 *
 *   php test_overtime_carry.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\HR\OvertimeCarryService;
use Illuminate\Support\Facades\DB;

const U = 999901;          // throwaway
$pass = 0; $fail = 0;

function check(string $what, $got, $want) {
    global $pass, $fail;
    $ok = json_encode($got) === json_encode($want);
    if ($ok) { $pass++; echo "  ok   $what\n"; }
    else { $fail++; echo "  FAIL $what\n         got  " . json_encode($got) . "\n         want " . json_encode($want) . "\n"; }
}
function lots(OvertimeCarryService $s, string $month = '2099-01'): array {
    return array_map(fn($l) => $l['earned_month'] . ':' . $l['minutes'], $s->openLotsBefore(U, $month));
}
function reset_all() {
    DB::table('t_hr_overtime_carry')->where('user_id', U)->delete();
}

$s = new OvertimeCarryService();
echo "perDay = " . $s->perDay() . " minutes\n\n";

// ── 1. The headline case: 1005 min in August → 1 day, 465 carried ──────────
reset_all(); $s = new OvertimeCarryService();
$p = $s->preview(U, '2026-08', 1005);
check('Aug preview: days',        $p['days'], 1);
check('Aug preview: carry_out',   $p['carry_out'], 465);
$s->commit(U, '2026-08', 1005, 'apply', 1);
$s = new OvertimeCarryService();
check('Aug committed lots',       lots($s), ['2026-08:465']);
check('carriedIn to Sep',         $s->carriedIn(U, '2026-09'), 465);

// ── 2. A quiet month keeps the carry alive (ruling: never expires) ─────────
$p = $s->preview(U, '2026-09', 0);
check('Sep with no OT: days',     $p['days'], 0);
check('Sep with no OT: carry',    $p['carry_out'], 465);
$s->commit(U, '2026-09', 0, 'apply', 1);
$s = new OvertimeCarryService();
check('carry survives a quiet month', lots($s), ['2026-08:465']);

// ── 3. FIFO: the carry pays for the next day, remainder is the NEWEST month ─
$p = $s->preview(U, '2026-10', 200);
check('Oct 200m + 465 carried: available', $p['available'], 665);
check('Oct: days',                $p['days'], 1);
check('Oct: carry_out',           $p['carry_out'], 125);
check('Oct: remainder is OCT (oldest spent first)',
      array_map(fn($l) => $l['earned_month'] . ':' . $l['minutes'], $p['carry_out_lots']), ['2026-10:125']);
$s->commit(U, '2026-10', 200, 'apply', 1);
$s = new OvertimeCarryService();
check('after Oct, only Oct remains', lots($s), ['2026-10:125']);

// ── 4. ⭐ A carry that SPANS two months (the reason lots exist) ────────────
reset_all(); $s = new OvertimeCarryService();
$s->commit(U, '2026-08', 217, 'apply', 1);
$s = new OvertimeCarryService();
$p = $s->preview(U, '2026-09', 200);
check('spanning: available',      $p['available'], 417);
check('spanning: no day granted', $p['days'], 0);
check('spanning: carry names BOTH months',
      array_map(fn($l) => $l['earned_month'] . ':' . $l['minutes'], $p['carry_out_lots']),
      ['2026-08:217', '2026-09:200']);
$s->commit(U, '2026-09', 200, 'apply', 1);
$s = new OvertimeCarryService();
check('spanning: both lots stored', lots($s), ['2026-08:217', '2026-09:200']);
check('describeLots wording', $s->describeLots($s->openLotsBefore(U, '2099-01')),
      '3h 37m from Aug + 3h 20m from Sep');

// ── 5. Waive forfeits this month AND the carry (ruling 1) ─────────────────
$s->commit(U, '2026-10', 100, 'waive', 1);
$s = new OvertimeCarryService();
check('waive forfeits everything', lots($s), []);
check('waive: carriedIn is 0',     $s->carriedIn(U, '2026-11'), 0);

// ── 6. Flipping the waive back to apply restores exactly what was forfeited ─
$s->commit(U, '2026-10', 100, 'apply', 1);
$s = new OvertimeCarryService();
check('flip back restores + banks Oct', lots($s), ['2026-08:217', '2026-09:200', '2026-10:100']);
check('flip back: total',          $s->carriedIn(U, '2026-11'), 517);

// ── 7. Re-deciding the SAME month does not double-count ───────────────────
$s->commit(U, '2026-10', 100, 'apply', 1);
$s = new OvertimeCarryService();
check('re-apply is idempotent',    lots($s), ['2026-08:217', '2026-09:200', '2026-10:100']);

// ── 8. Crossing the line with the carry grants the day ────────────────────
$p = $s->preview(U, '2026-11', 100);
check('Nov: 517 + 100 = 617 -> 1 day', $p['days'], 1);
check('Nov: carry_out 77',        $p['carry_out'], 77);
$s->commit(U, '2026-11', 100, 'apply', 1);
$s = new OvertimeCarryService();
check('Nov: only the newest 77 survive', lots($s), ['2026-11:77']);

// ── 9. Manual forfeit needs a reason, then clears (ruling 2) ──────────────
$r = $s->forfeitAll(U, '   ', 1);
check('forfeit without reason refused', $r['success'], false);
$r = $s->forfeitAll(U, 'Left the company', 1);
check('manual forfeit succeeds',   $r['success'], true);
$s = new OvertimeCarryService();
check('nothing carried after forfeit', lots($s), []);
$r = $s->forfeitAll(U, 'again', 1);
check('forfeit with nothing open refused', $r['success'], false);

// ── 10. Before the START_MONTH nothing is credited ────────────────────────
reset_all(); $s = new OvertimeCarryService();
$s->commit(U, '2026-07', 500, 'apply', 1);
$s = new OvertimeCarryService();
check('July (before Aug start) banks nothing', lots($s), []);

reset_all();
echo "\n" . ($fail === 0 ? "ALL $pass CHECKS PASSED" : "$pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
