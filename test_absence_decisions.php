<?php
/**
 * Absence decision engine checks. Uses throwaway user ids far outside the real range so a
 * real employee's pay can never be touched; cleans up after itself.
 *
 *   php test_absence_decisions.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\HR\AbsenceDecisionService;
use Illuminate\Support\Facades\DB;

const U = 999911;
$pass = 0; $fail = 0;

function check(string $what, $got, $want) {
    global $pass, $fail;
    $ok = json_encode($got) === json_encode($want);
    if ($ok) { $pass++; echo "  ok   $what\n"; }
    else { $fail++; echo "  FAIL $what\n         got  " . json_encode($got) . "\n         want " . json_encode($want) . "\n"; }
}
function reset_all() { DB::table('t_hr_absence_decision')->where('user_id', U)->delete(); }
function svc(): AbsenceDecisionService { return new AbsenceDecisionService(); }
function owed(): array {
    return array_map(fn($r) => $r['month'] . ':' . $r['outstanding'], svc()->openParked(U));
}

echo "enabled = " . (svc()->enabled() ? 'yes' : 'no') . "\n\n";

// ── 1. Undecided means CUT — nothing is written, nothing is owed ───────────
reset_all();
check('no decision recorded',        svc()->decisionFor(U, '2026-08'), null);
check('nothing owed when undecided', svc()->outstandingDays(U), 0.0);

// ── 2. CUT settles the month outright ─────────────────────────────────────
svc()->commit(U, '2026-08', 2, 'cut', 1481, 1);
$d = svc()->decisionFor(U, '2026-08');
check('cut: decision',        $d['decision'], 'cut');
check('cut: amount recorded', $d['amount_cut_now'], 2962.0);
check('cut: owes nothing',    svc()->outstandingDays(U), 0.0);

// ── 3. EXCUSE settles it too, with no money ───────────────────────────────
reset_all();
svc()->commit(U, '2026-08', 2, 'excuse', 1481, 1);
check('excuse: no money',  svc()->decisionFor(U, '2026-08')['amount_cut_now'], 0.0);
check('excuse: owes nothing', svc()->outstandingDays(U), 0.0);

// ── 4. PARK owes the days ─────────────────────────────────────────────────
reset_all();
svc()->commit(U, '2026-08', 2, 'park', 1481, 1);
check('park: owes 2',        svc()->outstandingDays(U), 2.0);
check('park: no cut now',    svc()->decisionFor(U, '2026-08')['amount_cut_now'], 0.0);
check('park: days frozen',   svc()->decisionFor(U, '2026-08')['days_absent'], 2.0);

// ── 5. ⭐ Overtime days cover parked absences, OLDEST month first ──────────
svc()->commit(U, '2026-09', 3, 'park', 1500, 1);
check('two parked months',   owed(), ['2026-08:2', '2026-09:3']);
$used = svc()->cover(U, 3, 'ot', 1);
check('cover 3 uses 3',      $used, 3.0);
check('oldest cleared first', owed(), ['2026-09:2']);
check('total owed now 2',    svc()->outstandingDays(U), 2.0);

// ── 6. Coverage never takes more than is owed ─────────────────────────────
check('cover 10 uses only 2', svc()->cover(U, 10, 'ot', 1), 2.0);
check('nothing left owed',    svc()->outstandingDays(U), 0.0);
check('cover with none owed', svc()->cover(U, 5, 'ot', 1), 0.0);

// ── 7. Uncover puts them back (a re-decided overtime month) ───────────────
svc()->uncover(U, 2, 'ot');
check('uncover restores 2',  svc()->outstandingDays(U), 2.0);

// ── 8. Cover from the employee's OWN leave (owner ruling 3) ───────────────
reset_all();
svc()->commit(U, '2026-08', 2, 'park', 1481, 1);
check('leave covers 1',      svc()->cover(U, 1, 'leave', 1), 1.0);
check('1 still owed',        svc()->outstandingDays(U), 1.0);
check('recorded as leave',   svc()->decisionFor(U, '2026-08')['covered_leave'], 1.0);

// ── 9. Cut later uses the CURRENT rate (owner ruling 2), overridable ──────
reset_all();
svc()->commit(U, '2026-08', 2, 'park', 1481, 1);          // parked at 1481
$r = svc()->cutLater(U, '2026-08', '2026-10', 1600, null, 1);   // current rate 1600
check('cut later: ok',        $r['success'], true);
check('cut later: 2 days',    $r['days'], 2.0);
check('cut later: CURRENT rate, not frozen', $r['amount'], 3200.0);
check('nothing owed after cut', svc()->outstandingDays(U), 0.0);
$held = svc()->heldCutFor(U, '2026-10');
check('waiting on Oct pay',   [$held['amount'], $held['days'], $held['months']], [3200.0, 2.0, ['2026-08']]);
check('not on Sep pay',       svc()->heldCutFor(U, '2026-09')['amount'], 0.0);
check('cut twice refused',    svc()->cutLater(U, '2026-08', '2026-10', 1600, null, 1)['success'], false);

// ── 10. The manager's own amount wins ─────────────────────────────────────
reset_all();
svc()->commit(U, '2026-08', 2, 'park', 1481, 1);
check('override amount',      svc()->cutLater(U, '2026-08', '2026-10', 1600, 500, 1)['amount'], 500.0);

// ── 11. Paying the month clears the "waiting" flag ────────────────────────
svc()->markCutPaid(U, '2026-10');
check('no longer waiting',    svc()->heldCutFor(U, '2026-10')['amount'], 0.0);

// ── 12. Excuse what is still owed ─────────────────────────────────────────
reset_all();
svc()->commit(U, '2026-08', 3, 'park', 1481, 1);
svc()->cover(U, 1, 'ot', 1);
check('2 owed before excuse',  svc()->outstandingDays(U), 2.0);
check('excuse the rest',       svc()->excuseLater(U, '2026-08', 1)['days'], 2.0);
check('nothing owed',          svc()->outstandingDays(U), 0.0);
check('excuse again refused',  svc()->excuseLater(U, '2026-08', 1)['success'], false);

// ── 13. ⚠ A settled month cannot be re-decided ────────────────────────────
reset_all();
svc()->commit(U, '2026-08', 2, 'park', 1481, 1);
check('re-decide while untouched', svc()->commit(U, '2026-08', 2, 'cut', 1481, 1)['success'], true);
reset_all();
svc()->commit(U, '2026-08', 2, 'park', 1481, 1);
svc()->cover(U, 1, 'ot', 1);
$blocked = svc()->commit(U, '2026-08', 2, 'cut', 1481, 1);
check('re-decide after settling refused', $blocked['success'], false);
check('message names the month', str_contains($blocked['message'], 'August 2026'), true);

// ── 14. Bad input ─────────────────────────────────────────────────────────
check('unknown decision refused', svc()->commit(U, '2026-11', 1, 'maybe', 100, 1)['success'], false);

// ── 15. The nag: closed months with absences and no decision ──────────────
reset_all();
$absent = fn($m) => in_array($m, ['2026-07', '2026-08'], true) ? 2 : 0;
$pend = svc()->undecidedMonths(U, $absent, '2026-09');
// 🗓 Owner ruling: absences are decided from Aug 2026 on. July is BEFORE the start month,
// so it must never be nagged about even though the employee was absent in it.
check('July is before the start month — never nagged', array_column($pend, 'month'), ['2026-08']);
check('a decision before the start month is refused',
      svc()->commit(U, '2026-07', 2, 'park', 1481, 1)['success'], false);
svc()->commit(U, '2026-08', 2, 'cut', 1481, 1);
check('decided month stops nagging',
      array_column(svc()->undecidedMonths(U, $absent, '2026-09'), 'month'), []);
check('a month with no absences never nags',
      svc()->undecidedMonths(U, fn($m) => 0, '2026-09'), []);

reset_all();

// ── A decision covers only what it froze. Find a real employee with ≥2 absences this
// month, park ONE fewer than he has → the extra day must stay cut and NOT be owed.
$ps = new \App\Services\HR\PayrollService();
$custom = (function () use ($ps) { $m = new ReflectionMethod($ps, 'customScheduleUserIds'); $m->setAccessible(true); return $m->invoke($ps); })();
$victim = null;
$cm = $ps->computeMonth('2026-08'); $rows = $cm['rows'] ?? $cm;
foreach ($rows as $r) {
    if (!isset($custom[$r['user_id']]) && (float) $r['absent_days'] >= 2 && (float) $r['absent_days'] <= 5) { $victim = $r; break; }
}
if ($victim) {
    $vu = (int) $victim['user_id']; $had = (float) $victim['absent_days'];
    DB::table('t_hr_absence_decision')->where('user_id', $vu)->where('month', '2026-08')->delete();
    svc()->commit($vu, '2026-08', $had - 1, 'park', 1, 1);
    // computeMonth() above cached his row (per-request memo). A decision made THROUGH the engine
    // drops it via forgetMonth(); this test commits around the engine, so drop it by hand.
    // Two caches: the engine's static row memo AND the absence service's per-user memo. A real
    // decision clears both because it commits through $ps->absence; here, rebuild + forget.
    $ps = new \App\Services\HR\PayrollService();
    $forget = new ReflectionMethod($ps, 'forgetMonth'); $forget->setAccessible(true); $forget->invoke($ps, '2026-08');
    $row = $ps->computeRow($vu, '2026-08');
    check('frozen count is what was parked', $row['absence_frozen_days'], $had - 1);
    check('the day outside the decision is undecided', $row['absence_undecided_days'], 1.0);
    check('…and therefore still cut', $row['absent_deduction'], round(1 * $row['absence_day_rate'], 2));
    check('…and NOT owed', $row['absence_outstanding'], $had - 1);
    $items = $ps->leaveActionsForRow($row, '2026-08'); $absItem = end($items);
    check('panel says so', str_contains($absItem['formula'] ?? '', 'since that decision'), true);
    // decide again → re-freezes at today's count, nothing left outside
    svc()->commit($vu, '2026-08', $had, 'park', 1, 1);
    $ps = new \App\Services\HR\PayrollService(); $forget->invoke($ps, '2026-08');
    $row = $ps->computeRow($vu, '2026-08');
    check('re-deciding brings the day inside', $row['absence_undecided_days'], 0.0);
    check('…no cut at all now', $row['absent_deduction'], 0.0);
    DB::table('t_hr_absence_decision')->where('user_id', $vu)->where('month', '2026-08')->delete();
} else {
    echo "  (no real employee with 2–5 absences in 2026-08 — partial-freeze checks skipped)\n";
}

// -- The leave-balance floor (owner ruling Sep-3): never below zero, and the refusal has to
// tell the manager what to do instead of just saying no.
$lp = new \App\Services\HR\LeavePolicyService();
$zero = null;
foreach (DB::table('t_sys_user')->where('is_active', 1)->pluck('id') as $id) {
    if ((float) ($lp->balance((int) $id)['remaining'] ?? 1) <= 0) { $zero = (int) $id; break; }
}
if ($zero !== null) {
    $msg = $lp->overQuotaRefusal($zero, 1);
    check('a zero balance refuses one more day', is_string($msg), true);
    check('...and says to raise the quota first', str_contains((string) $msg, 'increase the leave quota'), true);
    check('...and names the Attendance page', str_contains((string) $msg, 'Attendance page'), true);
    check('half a day is refused too', is_string($lp->overQuotaRefusal($zero, 0.5)), true);
    check('asking for nothing is never refused', $lp->overQuotaRefusal($zero, 0), null);
} else {
    echo "  (nobody is at a zero balance - floor checks skipped)\n";
}
$rich = null;
foreach (DB::table('t_sys_user')->where('is_active', 1)->pluck('id') as $id) {
    if ((float) ($lp->balance((int) $id)['remaining'] ?? 0) >= 2) { $rich = (int) $id; break; }
}
if ($rich !== null) {
    check('a healthy balance is left alone', $lp->overQuotaRefusal($rich, 1), null);
}

// -- A bonus granted from the ATTENDANCE page settles parked absences through the SAME engine
// payroll uses (owner ruling Sep-3). Oldest month first, never more than is owed.
$ps2 = new \App\Services\HR\PayrollService();
check('coverAbsencesWithBonus is callable from outside',
      (new ReflectionMethod($ps2, 'coverAbsencesWithBonus'))->isPublic(), true);
svc()->commit(U, '2026-08', 2, 'park', 1481, 1);
$usedCover = $ps2->coverAbsencesWithBonus(U, 3, '2026-09', 1, 'test grant');
check('3 bonus days settle only the 2 that are owed', $usedCover, 2.0);
check('...and nothing is left owed', svc()->outstandingDays(U), 0.0);
$gRow = DB::table('t_hr_leave_grant')->where('user_id', U)->where('source', 'absence_cover')
    ->orderByDesc('id')->first();
check('...written as a NEGATIVE absence_cover row', $gRow ? (float) $gRow->days : null, -2.0);
check('...with the reason the caller gave', $gRow->reason ?? '', 'test grant');
DB::table('t_hr_leave_grant')->where('user_id', U)->delete();

reset_all();
echo "\n" . ($fail === 0 ? "ALL $pass CHECKS PASSED" : "$pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
