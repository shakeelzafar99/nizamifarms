<?php
/**
 * THE SEAM: does the WORKSHOP approval obey the SHIFT rules? (Sep-07 2026)
 *
 * Approving a workshop day writes a real one-day row into `t_ops_user_shift_assignment`
 * (WorkshopVisitService::applyShiftLocation) WITHOUT going through the gated ShiftController
 * engine. That is a second door onto somebody's working day, and the shift-authority round
 * would have left it unwatched. These checks pin the door shut and prove the ordinary
 * workshop day still works exactly as before.
 *
 * What they prove:
 *   §1 a plain rider's workshop day still pins — nothing about today's behaviour changed;
 *   §2 a planner cannot pin HIMSELF (Farooq holds a bike, so this is live, not theoretical);
 *   §3 a planner cannot pin somebody ABOVE him on the ladder;
 *   §4 the Adjust picker cannot move a rider onto a shift outside his allowed list;
 *   §5 nor onto a shift TYPE that is still waiting for approval;
 *   §6 in every refusal the VISIT is still approved and the reason is told to the human;
 *   §7 two planners pressing Approve at once — the second is refused, not repeated;
 *   §8 when NOBODY can approve, silence is never turned into an auto-decline.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 *
 * Run:  php test_shift_workshop_seam.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Ops\ShiftAuthorityService as A;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\WorkshopVisitService as WV;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

$wv = app(WV::class);
$auth = new A();

head('§0 fixtures');
ok('the workshop tables exist', $wv->available(), true);
ok('the approval round is applied', $wv->approvalEnabled(), true);
ok('the shift-authority tables exist', $auth->available(), true);
if (!$wv->available() || !$wv->approvalEnabled() || !$auth->available()) { echo "prerequisites missing — stopping\n"; exit(1); }

$ladder = DB::table(A::T_AUTHORITY)->orderByDesc('rank')->get();
if ($ladder->count() < 3) { echo "ladder not seeded — stopping\n"; exit(1); }
$top = User::find((int) $ladder[0]->user_id);
$mid = User::find((int) $ladder[1]->user_id);

// The planner who ALSO holds a bike — the live self-approval case.
$plannerWithBike = null;
foreach ($ladder as $l) {
    $held = DB::table('t_ops_vehicle_assignment')->where('user_id', $l->user_id)->whereNull('released_on')->count();
    if ($held) { $plannerWithBike = User::find((int) $l->user_id); break; }
}
ok('a planner who also holds a bike exists (the self-approval case is live)', (bool) $plannerWithBike, null, true);

// A booker who can schedule but is NOT a planner (Qasim's shape).
$booker = null;
foreach (User::where('is_active', 1)->get() as $u) {
    if ($wv->canSchedule($u) && !$wv->canApprove($u)) { $booker = $u; break; }
}
ok('a booker who is not a planner exists (Qasim\'s shape)', (bool) $booker, null, true);

// A plain rider with a bike, not on the ladder.
$res = new VehicleResolver();
$rider = null; $riderVid = 0;
// ⚠ COMPANY machine (owner ruling, 10-Sep): `schedule()` refuses a personal bike outright.
$vsvcSeam = new \App\Services\Riders\VehicleService();
foreach (DB::table('t_ops_rider_profile')->where('active', 1)->pluck('user_id') as $uid) {
    if (DB::table(A::T_AUTHORITY)->where('user_id', $uid)->exists()) continue;
    $v = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
    if (!$v || !$vsvcSeam->isTrackedId($v)) continue;
    $u = User::find((int) $uid);
    if ($u && (int) $u->is_active === 1) { $rider = $u; $riderVid = (int) $v; break; }
}
ok('a plain rider with a COMPANY bike exists', (bool) $rider, null, true);
if (!$booker || !$rider || !$top) { echo "fixtures missing — stopping\n"; exit(1); }

$soon = now()->addDays(3)->format('Y-m-d');
$T = 't_ops_user_shift_assignment';
$pins = fn (int $uid, string $d) => DB::table($T)->where('user_id', $uid)
    ->whereDate('effective_from', $d)->whereNotNull('workshop_visit_id')->count();

DB::beginTransaction();
try {

/**
 * ⚠ PROD HAS NO LOCATION TICKED AS A WORKSHOP (and neither does this replica), which is
 *   why nothing pins there today. Tick one inside the transaction so the pin path can be
 *   exercised at all — the rollback puts it back.
 */
$workshopLoc = (int) DB::table('t_ops_company_locations')->where('is_active', 1)->orderBy('id')->value('id');
if (\App\Services\LocationService::hasWorkshopColumn()) {
    DB::table('t_ops_company_locations')->where('id', $workshopLoc)->update(['is_workshop' => 1]);
}
$wv = app(WV::class); // fresh instance — the workshop-location list is memoized
ok('a location is ticked as a workshop for this run', (bool) $workshopLoc, null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§1 the ordinary case is untouched — a plain rider still gets pinned');

$p = $wv->schedule($booker, ['vehicle_id' => $riderVid, 'user_id' => $rider->id,
    'visit_date' => $soon, 'visit_time' => '09:00', 'location_id' => $workshopLoc]);
ok('the booker raises a PROPOSAL', $p['proposed'] ?? null, true);
$vid = (int) $p['visit_id'];
ok('⚠ nothing is pinned while it waits', $pins((int) $rider->id, $soon), 0);

$a = $wv->approve($top, $vid);
ok('the top of the ladder approves it', $a['ok'], true);
ok('…and the rider IS pinned to the workshop', $pins((int) $rider->id, $soon), 1);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 a planner cannot pin HIMSELF (the rule the owner cared about most)');

if ($plannerWithBike) {
    $selfVid = (int) ($res->currentVehicleFor((int) $plannerWithBike->id) ?: 0);
    $sp = $wv->schedule($booker, ['vehicle_id' => $selfVid, 'user_id' => $plannerWithBike->id,
        'visit_date' => $soon, 'visit_time' => '10:00', 'location_id' => $workshopLoc]);
    $selfVisit = (int) $sp['visit_id'];
    ok('a visit can be booked for a planner who holds a bike', $selfVisit > 0, true);

    $sa = $wv->approve($plannerWithBike, $selfVisit);
    ok('he can still APPROVE the errand itself', $sa['ok'], true);
    ok('⚠⚠ but his own shift row is NOT written', $pins((int) $plannerWithBike->id, $soon), 0);
    ok('…and he is told exactly why',
       str_contains(strtolower((string) ($sa['message'] ?? '')), 'set your own shift'), true);

    /**
     * …and the gate does NOT stand in the way of someone above him. It may still not pin —
     * this planner has no shift resolving on that date, which is a different (pre-existing)
     * refusal with its own message — so the assertion is about WHICH reason comes back, not
     * about a row appearing. Asserting the row would make this test depend on whether one
     * particular person happens to work that weekday.
     */
    DB::table('t_ops_workshop_visit')->where('id', $selfVisit)->update(['status' => 'proposed']);
    $sa2 = $wv->approve($top, $selfVisit);
    ok('the top of the ladder is not blocked by the shift rules',
       $sa2['ok'] && !str_contains(strtolower((string) $sa2['message']), 'set your own shift'), true);
} else {
    echo "  (skipped — no ladder member holds a bike right now)\n";
}

// ─────────────────────────────────────────────────────────────────────────────
head('§3 a planner cannot pin somebody ABOVE him on the ladder');

// Put the plain rider ON the ladder, above the middle rung, and try again.
DB::table(A::T_AUTHORITY)->insert([
    'user_id' => $rider->id, 'rank' => (int) $ladder[0]->rank + 1, 'needs_approval' => 0,
    'updated_at' => now(),
]);
DB::table($T)->where('user_id', $rider->id)->whereDate('effective_from', $soon)
  ->whereNotNull('workshop_visit_id')->delete();
DB::table('t_ops_workshop_visit')->where('id', $vid)->update(['status' => 'proposed']);
$up = $wv->approve($mid, $vid);
ok('the approval itself still goes through', $up['ok'], true);
ok('⚠ but no shift row is written for someone above him', $pins((int) $rider->id, $soon), 0);
ok('…and the note names who can', str_contains((string) ($up['message'] ?? ''), 'NOT moved'), true);
DB::table(A::T_AUTHORITY)->where('user_id', $rider->id)->delete();

// ─────────────────────────────────────────────────────────────────────────────
head('§4 the Adjust picker cannot break an allowed-shift list');

$tplIds = DB::table('t_ops_shift_template')->where('active', 1)->orderBy('id')
    ->pluck('id')->map(fn ($i) => (int) $i)->all();
[$allowedTpl, $forbiddenTpl] = [$tplIds[0], $tplIds[1]];
DB::table(A::T_AUTHORITY)->insert([
    'user_id' => $rider->id, 'rank' => 0, 'needs_approval' => 0,
    'allowed_template_ids' => json_encode([$allowedTpl]), 'updated_at' => now(),
]);
DB::table($T)->where('user_id', $rider->id)->whereDate('effective_from', $soon)
  ->whereNotNull('workshop_visit_id')->delete();
DB::table('t_ops_workshop_visit')->where('id', $vid)->update(['status' => 'proposed']);

$bad = $wv->approve($mid, $vid, ['shift_template_id' => $forbiddenTpl]);
ok('approving with a FORBIDDEN shift does not pin it', $pins((int) $rider->id, $soon), 0);
ok('…and says the list is the reason',
   str_contains(strtolower((string) ($bad['message'] ?? '')), 'can only be put on'), true);

DB::table('t_ops_workshop_visit')->where('id', $vid)->update(['status' => 'proposed']);
$good = $wv->approve($mid, $vid, ['shift_template_id' => $allowedTpl]);
ok('the ALLOWED shift goes through', $pins((int) $rider->id, $soon), 1);
ok('…on the shift that was chosen',
   (int) DB::table($T)->where('user_id', $rider->id)->whereDate('effective_from', $soon)
        ->whereNotNull('workshop_visit_id')->value('shift_template_id'), $allowedTpl);
DB::table(A::T_AUTHORITY)->where('user_id', $rider->id)->delete();

// ─────────────────────────────────────────────────────────────────────────────
head('§5 a shift TYPE still waiting for approval is never offered or accepted');

DB::table('t_ops_shift_template')->where('id', $forbiddenTpl)->update(['approval_status' => 'proposed']);
$fresh = app(WV::class); // the column check is memoized per instance
ok('the Adjust picker drops it',
   in_array($forbiddenTpl, array_column($fresh->shiftTemplates(), 'id'), true), false);
DB::table($T)->where('user_id', $rider->id)->whereDate('effective_from', $soon)
  ->whereNotNull('workshop_visit_id')->delete();
DB::table('t_ops_workshop_visit')->where('id', $vid)->update(['status' => 'proposed']);
$prop = $fresh->approve($mid, $vid, ['shift_template_id' => $forbiddenTpl]);
ok('…and refuses it if it is sent anyway', $prop['ok'], false);
ok('…without pinning anything', $pins((int) $rider->id, $soon), 0);
DB::table('t_ops_shift_template')->where('id', $forbiddenTpl)->update(['approval_status' => 'approved']);

// ─────────────────────────────────────────────────────────────────────────────
head('§6 a refused pin never silently loses the errand');

DB::table('t_ops_workshop_visit')->where('id', $vid)->update(['status' => 'proposed']);
$fin = $wv->approve($top, $vid);
ok('the visit ends up scheduled', DB::table('t_ops_workshop_visit')->where('id', $vid)->value('status'), 'scheduled');
ok('and pinned', $pins((int) $rider->id, $soon), 1);

// ─────────────────────────────────────────────────────────────────────────────
head('§7 two planners pressing Approve at once');

// The visit is already 'scheduled' from §6 — that is exactly the state the loser of a
// race sees, so approving again must be refused rather than repeating every side effect.
$again = $wv->approve($mid, $vid);
ok('the second approval is refused, not repeated', $again['ok'], false);
ok('…and says somebody already answered, not "you cannot"',
   str_contains(strtolower((string) $again['message']), 'already'), true);

// ─────────────────────────────────────────────────────────────────────────────
head('§7b changing a day the rider has ALREADY been told about (owner ruling 7-Sep)');

$statusOf = fn (int $id) => (string) DB::table('t_ops_workshop_visit')->where('id', $id)->value('status');

// A clean approved day for the plain rider, on a fresh date.
// ⚠ `confirm_replace` here only because §1–§6 above left this bike with a live visit; this
//   booking is the FIXTURE, not the thing under test. The gate itself is tested below.
$day2 = now()->addDays(6)->format('Y-m-d');
$firstId = (int) $wv->schedule($booker, ['vehicle_id' => $riderVid, 'user_id' => $rider->id,
    'visit_date' => $day2, 'visit_time' => '09:00', 'location_id' => $workshopLoc,
    'confirm_replace' => 1])['visit_id'];
$wv->approve($top, $firstId);
ok('he has an approved day, pinned', $pins((int) $rider->id, $day2), 1);

// …now somebody asks for a different day for the same bike.
$day3 = now()->addDays(7)->format('Y-m-d');

/**
 * ⭐⭐ TOLD BEFORE, NOT AFTER. The first attempt is REFUSED — the booker is shown exactly what
 *    would change and has to say yes. Nothing is written until he does.
 */
$blocked = $wv->schedule($booker, ['vehicle_id' => $riderVid, 'user_id' => $rider->id,
    'visit_date' => $day3, 'visit_time' => '11:00', 'location_id' => $workshopLoc]);
ok('the first attempt is refused, not booked', $blocked['ok'], false);
ok('…as a QUESTION, not an error', $blocked['needs_confirmation'] ?? null, true);
ok('…naming the approved day it would change',
   str_contains((string) $blocked['message'], 'already has an approved workshop day'), true);
ok('…and saying, in Roman Urdu, that the rider must be told again',
   str_contains((string) $blocked['message'], 'dobara batana parega'), true);
ok('…and for a REQUEST it says nothing changes until approval',
   str_contains((string) $blocked['message'], 'Approval ke baad'), true);
ok('⚠ nothing was written by the refused attempt',
   DB::table('t_ops_workshop_visit')->where('visit_date', $day3)->count(), 0);

// He says yes.
$second = $wv->schedule($booker, ['vehicle_id' => $riderVid, 'user_id' => $rider->id,
    'visit_date' => $day3, 'visit_time' => '11:00', 'location_id' => $workshopLoc,
    'confirm_replace' => 1]);
$secondId = (int) $second['visit_id'];
ok('confirming books it', $secondId > 0, true);

ok('⚠⚠ the approved day is NOT killed by a mere request', $statusOf($firstId), 'scheduled');
ok('…it keeps its pin, so his day is untouched while the request waits', $pins((int) $rider->id, $day2), 1);
ok('…and nothing is pinned for the requested day yet', $pins((int) $rider->id, $day3), 0);

$card = null;
foreach ($wv->pendingApprovals($top) as $c) { if ((int) $c['id'] === $secondId) { $card = $c; break; } }
ok('the approver gets a card for it', (bool) $card, null, true);
ok('…which says WHAT approving would replace', (bool) ($card['replaces'] ?? null), true);
ok('…naming the day the rider already has',
   str_contains((string) ($card['replaces']['label'] ?? ''), \Carbon\Carbon::parse($day2)->format('D j M')), true);

$sw = $wv->approve($top, $secondId);
ok('approving performs the swap', $sw['ok'], true);
ok('…the old day is retired', $statusOf($firstId), 'rescheduled');
ok('…its pin is gone', $pins((int) $rider->id, $day2), 0);
ok('…and the new day is pinned instead', $pins((int) $rider->id, $day3), 1);

/**
 * ⭐ The rider's push must NAME the old date — that is the point of the ruling. Sending it
 *   needs Firebase, so this asserts the FACT the push is built from: the superseded row is
 *   reachable from the new visit, which is exactly what FirebaseService reads to say
 *   "Pehle 12 Sep ka plan tha, ab woh CANCEL hai."
 */
$oldRow = DB::table('t_ops_workshop_visit')->where('superseded_by', $secondId)
    ->where('status', 'rescheduled')->first(['visit_date']);
ok('the push can find the old date to name it', (bool) $oldRow, null, true);
ok('…and it is the day he had been told about', substr((string) $oldRow->visit_date, 0, 10), $day2);

// A DECLINE must leave the standing day alone.
$day4 = now()->addDays(8)->format('Y-m-d');
$third = (int) $wv->schedule($booker, ['vehicle_id' => $riderVid, 'user_id' => $rider->id,
    'visit_date' => $day4, 'location_id' => $workshopLoc, 'confirm_replace' => 1])['visit_id'];
$wv->decline($top, $third, 'Not that day.');
ok('declining a replacement leaves the approved day standing', $statusOf($secondId), 'scheduled');
ok('…and its pin untouched', $pins((int) $rider->id, $day3), 1);

// ─────────────────────────────────────────────────────────────────────────────
head('§8 silence is never turned into a decision when nobody can approve');

$before = DB::table('t_ops_workshop_visit')->where('status', 'declined')->count();
DB::table('t_sys_role_permissions')->where('permission_key', WV::APPROVE_PERMISSION)->update(['is_allowed' => 0]);
DB::table('t_sys_role_mobile_permission')->whereIn('mobile_permission_id', function ($q) {
    $q->from('t_sys_mobile_permission')->select('id')->where('permission_code', WV::APPROVE_PERMISSION);
})->delete();
// A proposal whose day has already passed — the sweep's usual auto-decline candidate.
DB::table('t_ops_workshop_visit')->where('id', $vid)
  ->update(['status' => 'proposed', 'visit_date' => now()->subDay()->format('Y-m-d')]);
$swept = app(WV::class)->escalateProposals();
ok('the sweep declines nothing when no role can approve', count($swept['declined']), 0);
ok('…and the proposal is left standing, visibly',
   DB::table('t_ops_workshop_visit')->where('id', $vid)->value('status'), 'proposed');
ok('…so no extra declines were recorded',
   DB::table('t_ops_workshop_visit')->where('status', 'declined')->count(), $before);

} finally {
    DB::rollBack();
}

head('§9 nothing leaked');
ok('no workshop pins left for the test day', $pins((int) $rider->id, $soon), 0);
ok('the ladder is untouched', DB::table(A::T_AUTHORITY)->count(), $ladder->count());
ok('every shift type is approved again',
   DB::table('t_ops_shift_template')->where('approval_status', '<>', 'approved')->count(), 0);

echo "\n────────────────────────────────\n";
echo ($fail === 0 ? "ALL GOOD" : "FAILURES") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
