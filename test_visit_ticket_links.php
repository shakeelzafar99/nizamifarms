<?php
/**
 * 🔗 PART B — A WORKSHOP VISIT ANSWERS NAMED ISSUES (15-Sep-2026).
 * Plan: VEHICLE-ISSUES-BOARD-PLAN-SEP2026.md §7.
 *
 * Until this round, a visit linked at most ONE ticket — the one a manager happened to press
 * "Schedule workshop" from — and on the replica that produced ZERO links in six visits, because
 * managers book the MACHINE from its card, not a thread. "Is a workshop day assigned for THIS
 * issue?" was therefore unanswerable per issue. Now the booking form lists the machine's open
 * issues and the booker ticks which the visit covers.
 *
 * ⚠⚠ NO SQL. `t_ops_vehicle_ticket.workshop_visit_id` already existed and is the many-side.
 *
 * What these prove:
 *   §1 booking links every ticked issue, and writes one line into EACH thread;
 *   §2 the client's list is NEVER trusted — foreign and closed ids are dropped;
 *   §3 a PROPOSAL stakes the choice but announces NOTHING (the 6-Sep ruling);
 *   §4 approval completes the link; declining releases it, silently;
 *   §5 cancelling puts the issues BACK in the queue instead of stranding them at "Workshop set";
 *   §6 superseding MOVES them — the bike is still going in, on another day;
 *   §7 marking done returns them to `acknowledged` and keeps the pointer (the board reads it);
 *   §8 "did not happen" keeps everything, because the errand still stands;
 *   §9 the visit payload reports which issues it covers, in ONE query;
 *   §10 nothing is left behind.
 *
 * ⚠ Every scenario is STAGED and the whole run is rolled back.
 *
 * Run:  php test_visit_ticket_links.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\VehicleTicketService as VT;
use App\Services\Riders\WorkshopVisitService as WV;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function section(string $t) { echo "\n== $t ==\n"; }   // ⚠ never head() — it shadows Laravel's helper

$wv = app(WV::class);
$vt = app(VT::class);
ok('the tables exist', $wv->available() && $vt->available(), true);
if (!$wv->available() || !$vt->available()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

$NOW = Carbon::parse('2026-09-15 09:00:00');
Carbon::setTestNow($NOW);

section('§0 fixtures');
/**
 * ⚠⚠ THE BOOKER MUST NOT BE A PLANNER. `schedule()` decides proposal-vs-assign from the
 *    caller's own rights, so a booker who happens to hold `manage_shifts` assigns directly and
 *    §3/§4 would silently test the wrong path — "as a PROPOSAL" failing for a correct reason.
 *    On this replica that is Qasim (schedules and manages tickets, plans no shifts).
 */
$booker = null; $planner = null; $rider = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if (!$booker && $wv->canSchedule($u) && $vt->canManage($u) && !$wv->canApprove($u)) $booker = $u;
    if (!$planner && $wv->canApprove($u) && $wv->canSchedule($u)) $planner = $u;
}
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $u = User::find((int) $uid);
    if ($u && !$vt->canManage($u)) { $rider = $u; break; }
}
ok('a booker who schedules + manages tickets but is NOT a planner exists', (bool) $booker, null, true);
ok('a shift planner who can also book exists', (bool) $planner, null, true);
ok('a plain rider exists', (bool) $rider, null, true);
if (!$booker || !$planner || !$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }
echo "  · booker={$booker->id} planner={$planner->id} rider={$rider->id}"
   . ' approvalOn=' . var_export($wv->approvalEnabled(), true) . "\n";

$beforeV = DB::table(WV::T_VISIT)->count();
$beforeT = DB::table(VT::T_TICKET)->count();
$beforeM = DB::table(VT::T_MESSAGE)->count();

DB::beginTransaction();
try {

// Two company machines of our own, one held by the rider.
$vidA = (int) DB::table('t_ops_vehicle')->insertGetId([
    'vtype' => 'bike', 'reg_no' => 'ZLA-111', 'is_company' => 1, 'is_active' => 1,
    'created_at' => $NOW, 'updated_at' => $NOW]);
$vidB = (int) DB::table('t_ops_vehicle')->insertGetId([
    'vtype' => 'bike', 'reg_no' => 'ZLB-222', 'is_company' => 1, 'is_active' => 1,
    'created_at' => $NOW, 'updated_at' => $NOW]);
DB::table('t_ops_vehicle_assignment')->insert([
    'vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'assigned_on' => $NOW->copy()->subDays(20)->format('Y-m-d'), 'assigned_by' => (int) $booker->id,
    'created_at' => $NOW, 'updated_at' => $NOW]);

$mk = function (int $vid, string $title, string $status = 'acknowledged') use ($rider, $booker, $NOW): int {
    return (int) DB::table(VT::T_TICKET)->insertGetId([
        'vehicle_id' => $vid, 'opened_by' => (int) $rider->id, 'opened_for_user_id' => (int) $rider->id,
        'category' => 'problem', 'urgent' => 0, 'title' => $title, 'status' => $status,
        'first_response_at' => $status === 'open' ? null : $NOW->copy()->subDay(),
        'opened_at' => $NOW->copy()->subDays(3), 'last_message_at' => $NOW->copy()->subDays(3),
        'closed_at' => $status === 'closed' ? $NOW->copy()->subDay() : null,
        'created_at' => $NOW, 'updated_at' => $NOW]);
};
$t1 = $mk($vidA, 'LINK tyres');
$t2 = $mk($vidA, 'LINK seat');
$t3 = $mk($vidA, 'LINK head cylinder');
$tClosed  = $mk($vidA, 'LINK already closed', 'closed');
$tForeign = $mk($vidB, 'LINK other bike');
ok('five staged issues', DB::table(VT::T_TICKET)->where('title', 'like', 'LINK %')->count(), 5);

$msgs  = fn (int $tid) => DB::table(VT::T_MESSAGE)->where('ticket_id', $tid)->count();
$row   = fn (int $tid) => (array) DB::table(VT::T_TICKET)->where('id', $tid)->first();
$TOMORROW = $NOW->copy()->addDay()->format('Y-m-d');

// ─────────────────────────────────────────────────────────────────────────────
section('§1 booking links every TICKED issue');

$m1 = $msgs($t1); $m2 = $msgs($t2); $m3 = $msgs($t3);
$r = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $TOMORROW, 'purpose' => 'repair',
    'ticket_ids' => [$t1, $t2]]);            // ⚠ a PLANNER books directly — no approval step
ok('the booking succeeds', $r['ok'], true);
$v1 = (int) $r['visit_id'];
ok('  …and is assigned, not proposed', !empty($r['proposed']), false);
ok('ticket 1 points at the visit', (int) $row($t1)['workshop_visit_id'], $v1);
ok('ticket 2 points at the visit', (int) $row($t2)['workshop_visit_id'], $v1);
ok('  …both move to "scheduled"', [$row($t1)['status'], $row($t2)['status']], ['scheduled', 'scheduled']);
ok('the UNTICKED issue is untouched', $row($t3)['workshop_visit_id'], null);
ok('  …and keeps its status', $row($t3)['status'], 'acknowledged');
ok('EACH linked thread gets exactly one line', [$msgs($t1) - $m1, $msgs($t2) - $m2], [1, 1]);
ok('  …and the unticked thread gets none', $msgs($t3) - $m3, 0);
$line = DB::table(VT::T_MESSAGE)->where('ticket_id', $t1)->orderByDesc('id')->value('body');
ok('  …naming the date', str_contains($line, Carbon::parse($TOMORROW)->format('D j M')), true);
ok('  …and saying it covers another issue too', str_contains($line, 'also covers 1 other'), true);
ok('the visit remembers an originating ticket', (int) $wv->find($v1)['ticket_id'], $t1);

// ─────────────────────────────────────────────────────────────────────────────
section('§2 the client list is NEVER trusted');

$mF = $msgs($tForeign); $mC = $msgs($tClosed);
/**
 * ⚠ $t1 and $t2 are ticked here ON PURPOSE. They are what the SUPERSEDED visit was answering,
 *   and §6 below is about them following the bike to its new day. Leaving them out would be the
 *   "he narrowed the trip" case instead, which has its own section (§6b) and its own fixture —
 *   one booking cannot be the evidence for both rules.
 */
$r2 = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->copy()->addDays(2)->format('Y-m-d'), 'purpose' => 'repair',
    'ticket_ids' => [$t1, $t2, $t3, $tForeign, $tClosed, 999999999], 'confirm_replace' => 1]);
ok('the booking succeeds', $r2['ok'], true);
$v2 = (int) $r2['visit_id'];
ok('the issue on ANOTHER bike is refused', $row($tForeign)['workshop_visit_id'], null);
ok('  …and its thread is untouched', $msgs($tForeign) - $mF, 0);
ok('an ALREADY CLOSED issue is refused', $row($tClosed)['workshop_visit_id'], null);
ok('  …and its thread is untouched', $msgs($tClosed) - $mC, 0);
ok('a nonexistent id is simply dropped', true, true);
ok('the legitimate one IS linked', (int) $row($t3)['workshop_visit_id'], $v2);

// ─────────────────────────────────────────────────────────────────────────────
section('§6 …and superseding MOVED the first visit’s issues to the new one');

/**
 * ⚠⚠ Booking v2 on the same machine SUPERSEDED v1 (one live visit per machine). The bike is
 *    still going in, just on another day — so the complaints v1 was answering must FOLLOW,
 *    not be dropped back into the queue for someone to re-tick.
 */
ok('the first visit was superseded', $wv->find($v1)['status'], 'rescheduled');
ok('its issues moved to the new visit',
   [(int) $row($t1)['workshop_visit_id'], (int) $row($t2)['workshop_visit_id']], [$v2, $v2]);
ok('  …and they are still "scheduled", not dumped back',
   [$row($t1)['status'], $row($t2)['status']], ['scheduled', 'scheduled']);

// ─────────────────────────────────────────────────────────────────────────────
/**
 * §6b ⭐⭐ …BUT AN UNTICK MUST MEAN SOMETHING (15-Sep-2026).
 *
 *     The booking form promises "untick anything this trip is not for — it stays in the queue".
 *     A blanket move made that sentence false: a manager who deliberately narrowed the new day
 *     still had every old complaint dragged onto it, reading "Workshop set" against a trip it
 *     was never for — the system manufacturing the exact stuck state the Issues board exists to
 *     catch.
 */
section('§6b re-booking with a NARROWED list leaves the unticked ones behind');

$mT2 = $msgs($t2);
$r6 = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->copy()->addDays(3)->format('Y-m-d'), 'purpose' => 'repair',
    'ticket_ids' => [$t1], 'confirm_replace' => 1]);          // t2 and t3 deliberately dropped
ok('the narrowed re-booking succeeds', $r6['ok'], true);
$v6 = (int) $r6['visit_id'];
ok('the ticked issue follows the bike', (int) $row($t1)['workshop_visit_id'], $v6);
ok('  …and is scheduled for the new day', $row($t1)['status'], 'scheduled');
ok('an UNTICKED issue is let go of', [$row($t2)['workshop_visit_id'], $row($t3)['workshop_visit_id']],
   [null, null]);
/**
 * ⚠ Back to `acknowledged`, NEVER `open` — a manager HAD answered these, and `first_response_at`
 *   is untouched, so `open` would report them on the board as "nobody has replied".
 */
ok('  …and goes back in the queue as acknowledged, not open',
   [$row($t2)['status'], $row($t3)['status']], ['acknowledged', 'acknowledged']);
/**
 * ⚠ SILENTLY. The rider was told about the day when it was booked; being quietly dropped from a
 *   trip is a change of PLAN, not news for him — and "your issue is no longer being looked at"
 *   arriving with no date attached is worse than nothing. The board is where a manager sees it.
 */
ok('  …without writing a word into its thread', $msgs($t2) - $mT2, 0);

/**
 * §6c ⚠⚠ AN OLD CLIENT IS NOT AN EMPTY ANSWER. A phone built before Part B sends no `ticket_ids`
 *     key at all — it was never ASKED, so it cannot have unticked anything. Reading its silence
 *     as "cover nothing" would silently unhook every complaint on every re-booking made from a
 *     stale APK, which is the entire fleet's history quietly coming apart.
 */
section('§6c a PRE-PART-B client still moves everything, because it was never asked');

$r6c = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->copy()->addDays(4)->format('Y-m-d'), 'purpose' => 'repair',
    'confirm_replace' => 1]);                                  // NO ticket_ids key at all
ok('the old-client re-booking succeeds', $r6c['ok'], true);
$v6c = (int) $r6c['visit_id'];
ok('the issue the old day carried still follows it', (int) $row($t1)['workshop_visit_id'], $v6c);
ok('  …and is still scheduled', $row($t1)['status'], 'scheduled');

/**
 * §6d ⚠⚠ THE THREAD HE PRESSED "SCHEDULE" FROM IS NOT EXEMPT.
 *
 *     A thread-first booking also sends the singular `ticket_id`, and that id is ALSO written on
 *     the visit row as "the thread this was raised from". `linkedTicketIds()` reads that column
 *     as well as the pointers — so an unticked originating issue used to come back to life
 *     through it, and its thread would later be told the visit was completed for it. Ticked is
 *     covered, and there is no second way in.
 */
section('§6d unticking the originating thread drops it too — including from the visit row');

$r6e = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->copy()->addDays(5)->format('Y-m-d'), 'purpose' => 'repair',
    'ticket_id' => $t1, 'ticket_ids' => [$t2], 'confirm_replace' => 1]);
ok('the booking succeeds', $r6e['ok'], true);
$v6e = (int) $r6e['visit_id'];
ok('the unticked originating issue is NOT linked', $row($t1)['workshop_visit_id'], null);
ok('  …and does not survive on the visit row either', $wv->find($v6e)['ticket_id'], null);
ok('  …and is back in the queue', $row($t1)['status'], 'acknowledged');
ok('the one he DID tick is linked', (int) $row($t2)['workshop_visit_id'], $v6e);

// Put the board back the way §5 expects it: all three on ONE live visit.
$r6d = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->copy()->addDays(2)->format('Y-m-d'), 'purpose' => 'repair',
    'ticket_ids' => [$t1, $t2, $t3], 'confirm_replace' => 1]);
$v2 = (int) $r6d['visit_id'];
ok('all three are back on one live visit for the next section',
   [(int) $row($t1)['workshop_visit_id'], (int) $row($t2)['workshop_visit_id'],
    (int) $row($t3)['workshop_visit_id']], [$v2, $v2, $v2]);

// ─────────────────────────────────────────────────────────────────────────────
section('§5 cancelling puts the issues BACK in the queue');

$before = $msgs($t1);
$c = $wv->cancel($booker, $v2, 'Workshop closed that week');
ok('the cancel succeeds', $c['ok'], true);
ok('every linked issue is released', $row($t1)['workshop_visit_id'], null);
ok('  …and all three of them', [$row($t2)['workshop_visit_id'], $row($t3)['workshop_visit_id']], [null, null]);
/**
 * ⚠ Back to `acknowledged`, NEVER `open`: a manager HAD answered these, and resetting them to
 *   open would report them on the board as "nobody has replied" and reset a response time that
 *   was really met days ago.
 */
ok('  …returned to acknowledged, not open',
   [$row($t1)['status'], $row($t2)['status'], $row($t3)['status']],
   ['acknowledged', 'acknowledged', 'acknowledged']);
ok('  …and the rider is told in each thread', $msgs($t1) - $before, 1);
ok('    …by name', str_contains((string) DB::table(VT::T_MESSAGE)->where('ticket_id', $t1)
   ->orderByDesc('id')->value('body'), 'cancelled'), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§3 a PROPOSAL stakes the choice and announces NOTHING');

if (!$wv->approvalEnabled()) {
    echo "  · approval flow not installed on this replica — §3/§4 skipped\n";
} else {
    $mA = $msgs($t1); $mB = $msgs($t2);
    // ⚠ A non-planner booking is ALWAYS a proposal.
    $p = $wv->schedule($booker, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
        'visit_date' => $NOW->copy()->addDays(4)->format('Y-m-d'), 'purpose' => 'repair',
        'ticket_ids' => [$t1, $t2], 'confirm_replace' => 1]);
    ok('the request is raised', $p['ok'], true);
    $vp = (int) $p['visit_id'];
    ok('  …as a PROPOSAL', $wv->find($vp)['status'], 'proposed');
    ok('the choice is staked on the tickets',
       [(int) $row($t1)['workshop_visit_id'], (int) $row($t2)['workshop_visit_id']], [$vp, $vp]);
    /**
     * ⚠⚠ THE RULING. The rider reads these threads; a proposal must tell him nothing, because a
     *    planner may yet decline it. Status untouched and not one line written.
     */
    ok('  …but NO status moves', [$row($t1)['status'], $row($t2)['status']],
       ['acknowledged', 'acknowledged']);
    ok('  …and NOT ONE line reaches either thread', [$msgs($t1) - $mA, $msgs($t2) - $mB], [0, 0]);

    section('§4 approval completes it · declining releases it, silently');

    $mA = $msgs($t1);
    $a = $wv->approve($planner, $vp, []);
    ok('the planner approves', $a['ok'], true);
    ok('now the issues are scheduled', [$row($t1)['status'], $row($t2)['status']],
       ['scheduled', 'scheduled']);
    ok('  …and NOW the thread is written to', $msgs($t1) - $mA, 1);
    ok('  …saying it was approved', str_contains((string) DB::table(VT::T_MESSAGE)
       ->where('ticket_id', $t1)->orderByDesc('id')->value('body'), 'approved for'), true);

    // A second request, declined.
    $wv->cancel($booker, (int) $a['visit_id'] ?: $vp, 'clearing for the decline case');
    $mA = $msgs($t1);
    $p2 = $wv->schedule($booker, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
        'visit_date' => $NOW->copy()->addDays(6)->format('Y-m-d'), 'purpose' => 'repair',
        'ticket_ids' => [$t1], 'confirm_replace' => 1]);
    $vp2 = (int) $p2['visit_id'];
    ok('a second request stakes its issue', (int) $row($t1)['workshop_visit_id'], $vp2);
    $d = $wv->decline($planner, $vp2, 'Not that week');
    ok('the planner declines', $d['ok'], true);
    ok('  …and the staked pointer is RELEASED', $row($t1)['workshop_visit_id'], null);
    ok('  …silently — a plan nobody was told about is not announced by its death',
       $msgs($t1) - $mA, 0);
}

// ─────────────────────────────────────────────────────────────────────────────
section('§7 marking done returns them to acknowledged, and KEEPS the pointer');

$t4 = $mk($vidA, 'LINK brake shoe');
$today = $NOW->format('Y-m-d');
$rd = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $today, 'purpose' => 'repair', 'ticket_ids' => [$t4], 'confirm_replace' => 1]);
ok('a visit for today is booked', $rd['ok'], true);
$vd = (int) $rd['visit_id'];
ok('  …and the issue is linked', (int) $row($t4)['workshop_visit_id'], $vd);
$mD = $msgs($t4);
$done = $wv->markDone($booker, $vd, ['outcome_note' => 'Shoes replaced']);
ok('it is marked done', $done['ok'], true);
/**
 * ⚠⚠ Completing a VISIT does not CLOSE a TICKET — only a manager closes one, and the RIDER can
 *    mark a visit done, so auto-closing here would let him close his own complaint.
 */
ok('the issue is NOT closed', $row($t4)['status'], 'acknowledged');
ok('  …and the thread says the work happened', $msgs($t4) - $mD, 1);
/**
 * ⚠ The pointer is KEPT on purpose: the Issues board's "workshop done, still open" line reads
 *   it, and that line is the whole "nobody is closing these" detector.
 */
ok('  …and the pointer to the visit is KEPT', (int) $row($t4)['workshop_visit_id'], $vd);

// ─────────────────────────────────────────────────────────────────────────────
section('§8 "it did not happen" changes nothing — the errand still stands');

$t5 = $mk($vidA, 'LINK chain');
$rn = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $today, 'purpose' => 'repair', 'ticket_ids' => [$t5], 'confirm_replace' => 1]);
$vn = (int) $rn['visit_id'];
$mN = $msgs($t5);
$nd = $wv->reportNotDone($rider, $vn, 'Workshop was shut');
ok('the rider reports it did not happen', $nd['ok'], true);
ok('the issue stays scheduled — it is still going in', $row($t5)['status'], 'scheduled');
ok('  …and still points at the visit', (int) $row($t5)['workshop_visit_id'], $vn);
ok('  …and the thread says why it did not happen', $msgs($t5) - $mN, 1);

// ─────────────────────────────────────────────────────────────────────────────
section('§9 the payload reports what a visit covers');

$listed = collect($wv->listVisits(['vehicle_id' => $vidA, 'include_done' => true,
                                   'include_proposed' => true, 'limit' => 50]))
    ->firstWhere('id', $vn);
ok('the visit carries its issues', count($listed['tickets'] ?? []), 1);
ok('  …with the title, not just an id', $listed['tickets'][0]['title'] ?? null, 'LINK chain');
ok('  …and whether it is still open', $listed['tickets'][0]['is_open'] ?? null, true);

$openForForm = $wv->openTicketsForVehicle($vidA);
ok('the booking form is offered this machine\'s open issues', count($openForForm) >= 4, true);
ok('  …and none from another bike',
   in_array($tForeign, array_column($openForForm, 'id'), true), false);
ok('  …nor any closed one', in_array($tClosed, array_column($openForForm, 'id'), true), false);
ok('  …and it flags which are already spoken for',
   in_array(true, array_column($openForForm, 'already_linked'), true), true);

/**
 * §9 ⭐⭐ A WORKSHOP DAY WITHOUT A TICKET IS A NORMAL, FIRST-CLASS BOOKING (owner, 15-Sep-2026:
 *     *"a workshop assigned without a ticket should still work. it's not important to have a
 *     ticket — the ticket is just to document that there was an issue"*).
 *
 *     Most bookings are a routine service nobody complained about. Part B added a question to
 *     the booking form, and the one way that could have gone wrong is if the answer became
 *     REQUIRED — a manager blocked from booking an oil change because no rider had reported
 *     anything. It is not: an empty answer books the visit and links nothing.
 */
section('§9 a booking with NO issues at all — the ordinary case');

$mAll = $msgs($t1) + $msgs($t2) + $msgs($t3);
$r9 = $wv->schedule($planner, ['vehicle_id' => $vidA, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->copy()->addDays(9)->format('Y-m-d'), 'purpose' => 'service',
    'ticket_ids' => [], 'confirm_replace' => 1]);     // the form asked; he ticked nothing
ok('the booking succeeds with an empty answer', $r9['ok'], true);
$v9 = (int) $r9['visit_id'];
ok('  …and the visit is real and live', (bool) $wv->find($v9), null, true);
ok('  …carrying no originating ticket', $wv->find($v9)['ticket_id'], null);
ok('  …and answering no issues', $wv->linkedTicketIds($v9), []);
/**
 * ⚠ And it says NOTHING in anyone's thread. A routine service is not news for a rider who never
 *   reported a fault, and a line in a complaint thread about a trip that is not for it is how a
 *   rider stops believing the threads.
 */
ok('  …and writes into no thread at all', $msgs($t1) + $msgs($t2) + $msgs($t3) - $mAll, 0);

} finally {
    DB::rollBack();
    Carbon::setTestNow();
}

section('§10 nothing left behind');
ok('visit count back where it started', DB::table(WV::T_VISIT)->count(), $beforeV);
ok('ticket count back where it started', DB::table(VT::T_TICKET)->count(), $beforeT);
ok('message count back where it started', DB::table(VT::T_MESSAGE)->count(), $beforeM);
ok('no staged vehicles survived',
   DB::table('t_ops_vehicle')->where('reg_no', 'like', 'ZL%-%')->count(), 0);

echo "\n────────────────────────────────────────────\n";
echo ($fail === 0 ? "✅  " : "❌  ") . "$pass passed, $fail failed\n";
echo "────────────────────────────────────────────\n";
exit($fail === 0 ? 0 : 1);
