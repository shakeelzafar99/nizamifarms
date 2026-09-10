<?php
/**
 * WORKSHOP DAY — PROPOSE → PLANNER APPROVES → RIDER TOLD  (Sep-06 2026).
 * Plan: WORKSHOP-APPROVAL-FLOW-PLAN-SEP2026.md
 *
 * The ruling being proved: a booked workshop day does NOT reach the rider. It goes to the
 * shift planners; one of them approves; only then is his day pinned and only then is he
 * told — and told that it is a LOCATION change, not a time change.
 *
 * What these prove:
 *   §1 the booking decision is made from the caller's RIGHTS, not his payload;
 *   §2 a proposal is invisible to the rider — every rider-facing reader, by construction;
 *   §3 a proposal pins NOTHING and writes NOTHING into the ticket thread he reads;
 *   §4 approving is the only thing that pins, tells him, and touches the thread;
 *   §5 Adjust: workshop, appointment time, and his START TIME that day (Danish's case);
 *   §6 declining keeps the reason, tells the booker, and the rider never knew;
 *   §7 escalation without a cron: nudge at 17:00, auto-DECLINE in the morning, never
 *      auto-approve — and a proposal made TODAY for TODAY is left alone;
 *   §8 a proposal follows a handover and STAYS a proposal;
 *   §9 supersede / cancel / accept / complete all refuse or behave correctly on a proposal;
 *  §10 the inline "add a workshop" writer: coordinates required, a Maps PLACE url refused;
 *  §11 the controller doors, including that a rider cannot reach any of them.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ Firebase credentials must be moved aside before running — the pushes here are real
 *   events and this team's tokens are live.
 *
 * Run:  php test_workshop_approval.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Location\CompanyLocationsService;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleTicketService as VT;
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

$wv = new WV();
$vt = new VT();
ok('the workshop table exists', $wv->available(), true);
ok('the approval columns exist (run workshop_approval_sep2026.sql first)', $wv->approvalEnabled(), true);
if (!$wv->approvalEnabled()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

// ─── fixtures: DISCOVERED ────────────────────────────────────────────────────
head('§0 fixtures');

/**
 * ⭐ THE TWO KINDS OF MANAGER THIS ROUND IS ABOUT.
 *   booker  = holds schedule_workshop but NOT manage_shifts   → Qasim
 *   planner = holds manage_shifts                             → Shabib / Farooq / Taimur
 * Discovered from the live permission tables, so the test proves the real configuration
 * rather than a shape invented here.
 */
$booker = null; $planner = null; $rider = null; $otherRider = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    $sched = (bool) $u->hasPermission(WV::PERMISSION);
    $plan  = (bool) $u->hasPermission(WV::APPROVE_PERMISSION);
    if (!$booker  && $sched && !$plan) $booker  = $u;
    if (!$planner && $plan)            $planner = $u;
}
ok('a BOOKER exists who is not a planner (Qasim-shaped)', (bool) $booker, null, true);
ok('a PLANNER exists (Shabib / Farooq / Taimur-shaped)', (bool) $planner, null, true);
if (!$booker || !$planner) { echo "\nfixtures missing — stopping.\n"; exit(1); }

/**
 * ⚠ COMPANY machines only (owner ruling, 10-Sep-2026): a workshop day cannot be booked for a
 *   personal bike at all, so a fixture that grabbed the first rider with ANY machine could pick
 *   one and fail every assertion below for the right reason in the wrong place.
 */
$res  = new VehicleResolver();
$vsvc = new \App\Services\Riders\VehicleService();
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $vv = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
    if (!$vv || !$vsvc->isTrackedId($vv)) continue;
    $u = User::find((int) $uid);
    if (!$u) continue;
    if (!$rider) { $rider = $u; continue; }
    if (!$otherRider && (int) $u->id !== (int) $rider->id) { $otherRider = $u; break; }
}
ok('two riders with COMPANY machines exist', (bool) ($rider && $otherRider), null, true);
if (!$rider || !$otherRider) { echo "\nfixtures missing — stopping.\n"; exit(1); }

$vid  = (int) $res->currentVehicleFor((int) $rider->id);
$vid2 = (int) $res->currentVehicleFor((int) $otherRider->id);
$soon = \Carbon\Carbon::today()->addDays(3)->format('Y-m-d');
echo "  · booker={$booker->id} planner={$planner->id} rider={$rider->id}(v{$vid}) date={$soon}\n";
ok('the booker really cannot approve', $wv->canApprove($booker), false);
ok('the planner really can approve', $wv->canApprove($planner), true);
ok('a rider can neither book nor approve',
   [$wv->canSchedule($rider), $wv->canApprove($rider)], [false, false]);

$beforeVisits  = DB::table(WV::T_VISIT)->count();
$beforeTickets = DB::table(VT::T_TICKET)->count();
$beforeLocs    = DB::table('t_ops_company_locations')->count();

DB::beginTransaction();
try {

// ⚠ The replica has ZERO locations ticked as a workshop (the very hole this round found on
//   prod), so the pin tests would silently prove nothing. Make two inside the transaction.
$wsA = (int) DB::table('t_ops_company_locations')->insertGetId([
    'location_name' => 'TEST Workshop A', 'latitude' => 33.6867, 'longitude' => 73.0331,
    'radius_meters' => 300, 'is_primary' => 0, 'is_workshop' => 1, 'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);
$wsB = (int) DB::table('t_ops_company_locations')->insertGetId([
    'location_name' => 'TEST Workshop B', 'latitude' => 33.7081, 'longitude' => 73.0886,
    'radius_meters' => 300, 'is_primary' => 0, 'is_workshop' => 1, 'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);
$notWs = (int) DB::table('t_ops_company_locations')->where('is_workshop', 0)->where('is_active', 1)->value('id');
ok('two test workshops and one ordinary location are available',
   (bool) ($wsA && $wsB && $notWs), null, true);

$pinRow = fn (int $visitId) => DB::table('t_ops_user_shift_assignment')
    ->where('workshop_visit_id', $visitId)->first();
$statusOf = fn (int $visitId) => (string) DB::table(WV::T_VISIT)->where('id', $visitId)->value('status');

// ═══════════════════════════════════════════════════════════════════════════════
head('§1 the booking decision comes from RIGHTS, not from the payload');

$r1 = $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                              'visit_date' => $soon, 'location_id' => $wsA]);
ok('a booker who is not a planner gets a PROPOSAL', $r1['proposed'] ?? null, true);
ok('  …the row says so', $statusOf((int) $r1['visit_id']), 'proposed');
ok('  …and it records who asked',
   (int) DB::table(WV::T_VISIT)->where('id', $r1['visit_id'])->value('proposed_by'), (int) $booker->id);
ok('  …and the message says the rider has NOT been told',
   str_contains($r1['message'], 'has NOT been told'), true);

/**
 * ⚠⚠ THE PAYLOAD IS NOT BELIEVED. A crafted request cannot buy an assignment — the flag is
 *    only ever read for a caller who already holds manage_shifts.
 */
$r2 = $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                              'visit_date' => $soon, 'location_id' => $wsA,
                              'assign_now' => 1, 'send_for_approval' => 0]);
ok('a booker sending assign_now is STILL a proposal', $statusOf((int) $r2['visit_id']), 'proposed');

$r3 = $wv->schedule($planner, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                               'visit_date' => $soon, 'location_id' => $wsA]);
ok('a PLANNER booking with no flag keeps the OLD behaviour — assigned at once',
   $statusOf((int) $r3['visit_id']), 'scheduled');
ok('  …which is what keeps an old APK in a planner’s hand working', $r3['proposed'] ?? null, false);
ok('  …and it pinned his day', $r3['shift_location_set'] ?? null, true);

/**
 * ⚠ `confirm_replace` — since 7-Sep the server REFUSES, as a question, any booking that would
 *   change a day the rider has already been told about (r3 above is approved), and hands back
 *   `needs_confirmation` + what would change. A real screen asks and re-sends; so does this.
 *   The refusal itself is asserted in `test_shift_workshop_seam.php` §7b.
 */
$r4blocked = $wv->schedule($planner, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                      'visit_date' => $soon, 'location_id' => $wsA,
                                      'send_for_approval' => 1]);
ok('  …but first he is asked, because r3 is already approved',
   $r4blocked['needs_confirmation'] ?? null, true);

$r4 = $wv->schedule($planner, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                               'visit_date' => $soon, 'location_id' => $wsA,
                               'send_for_approval' => 1, 'confirm_replace' => 1]);
ok('a planner who chooses "send for approval" gets a proposal',
   $statusOf((int) $r4['visit_id']), 'proposed');

/**
 * ⭐⭐ OWNER RULING 7-Sep — A PROPOSAL MAY NOT KILL AN APPROVED DAY.
 *    This is the reverse of what this test asserted before. Until this round a new booking
 *    superseded the old one "whatever its state", so merely ASKING for a different day
 *    retired a visit the rider had already been told about and deleted his shift pin — and
 *    nobody told him. He kept the old date in his head with nothing behind it, and the
 *    replacement might never be approved.
 *    Now: the approved day stands, untouched and still pinned, until somebody approves the
 *    replacement. `approve()` is what swaps them and tells the rider it moved (§8b).
 */
ok('  ⭐ the already-approved booking is NOT superseded by a mere request',
   $statusOf((int) $r3['visit_id']), 'scheduled');
ok('  …and it KEEPS its pin, so his day is unchanged while the request waits',
   $pinRow((int) $r3['visit_id']) !== null, true);
ok('  …and the booker was warned before he sent it',
   (bool) count(array_filter($r4['warnings'] ?? [],
       fn ($w) => str_contains($w, 'already has an approved workshop day'))), true);

$prop = (int) $r4['visit_id'];

/**
 * ⚠ Now retire that approved day so the sections below test a proposal ON ITS OWN. They are
 *   about "a proposal is invisible to the rider"; leaving a live visit standing would have
 *   them measuring the live one instead of the proposal.
 */
$wv->cancel($planner, (int) $r3['visit_id'], 'clearing the slate for the invisibility checks');

// ═══════════════════════════════════════════════════════════════════════════════
head('§2 a proposal is invisible to the RIDER — by construction');

ok('it is not a LIVE status', in_array('proposed', WV::LIVE_STATUSES, true), false);
ok('his "next visit" does not see it', $wv->nextForUser((int) $rider->id), null);
ok('the day-of outcome prompt does not see it', $wv->awaitingOutcomeFor((int) $rider->id), null);
$rSum = $wv->summaryFor($rider);
ok('his banner does not see it', $rSum['count'], 0);
ok('the planner grid excludes it unless asked',
   isset($wv->mapForRange([(int) $rider->id], $soon, $soon)[(int) $rider->id . '|' . $soon]), false);
ok('  …and INCLUDES it when the planner asks',
   $wv->mapForRange([(int) $rider->id], $soon, $soon, true)[(int) $rider->id . '|' . $soon]['proposed'] ?? null, true);
ok('the default list excludes it',
   in_array($prop, array_column($wv->listVisits(['user_id' => (int) $rider->id]), 'id'), true), false);
ok('  …and a manager sees it when he asks for it',
   in_array($prop, array_column($wv->listVisits(['user_id' => (int) $rider->id, 'include_proposed' => true]), 'id'), true), true);
ok('his own HISTORY (include_done) still excludes it',
   in_array($prop, array_column($wv->listVisits(['user_id' => (int) $rider->id, 'include_done' => true]), 'id'), true), false);

// The day-before reminder sweep must never wake a rider about a proposal.
$tomorrow = \Carbon\Carbon::today()->addDay()->format('Y-m-d');
$pTom = (int) $wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                      'visit_date' => $tomorrow, 'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
ok('a proposal for TOMORROW is not in the rider reminder sweep',
   in_array($pTom, array_column($wv->dueReminders(), 'id'), true), false);

// ═══════════════════════════════════════════════════════════════════════════════
head('§3 a proposal pins nothing and says nothing in the thread he reads');

ok('no shift row was written', $pinRow($prop), null);
ok('his day still resolves to his normal place',
   (int) (new \App\Services\ShiftResolutionService())->getUserShift((int) $rider->id, $soon)['location_id'] === $wsA,
   false);

// A ticket-raised proposal must leave the rider's ticket thread untouched.
$tid = (int) DB::table(VT::T_TICKET)->insertGetId([
    'vehicle_id' => $vid, 'opened_by' => (int) $rider->id, 'opened_for_user_id' => (int) $rider->id,
    'title' => 'TEST approval thread', 'status' => 'open', 'category' => 'other', 'urgent' => 0,
    'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$msgsBefore = DB::table(VT::T_MESSAGE)->where('ticket_id', $tid)->count();
$pT = (int) $wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                    'visit_date' => $soon, 'location_id' => $wsA,
                                    'ticket_id' => $tid])['visit_id'];
ok('a proposal writes NOTHING into the ticket thread',
   DB::table(VT::T_MESSAGE)->where('ticket_id', $tid)->count(), $msgsBefore);
ok('  …and does not move the ticket to "scheduled"',
   (string) DB::table(VT::T_TICKET)->where('id', $tid)->value('status'), 'open');

// ═══════════════════════════════════════════════════════════════════════════════
head('§4 approving is the only thing that pins, tells him, and writes the thread');

ok('a BOOKER cannot approve', $wv->approve($booker, $prop)['ok'], false);
ok('a RIDER cannot approve', $wv->approve($rider, $prop)['ok'], false);

$ap = $wv->approve($planner, $prop);
ok('a planner can', $ap['ok'], true);
ok('  …the row is now scheduled', $statusOf($prop), 'scheduled');
ok('  …stamped with who and when',
   (int) DB::table(WV::T_VISIT)->where('id', $prop)->value('approved_by'), (int) $planner->id);
ok('  …NOW his day is pinned to the workshop', (int) ($pinRow($prop)->location_id ?? 0), $wsA);
ok('  …and the message says he has been told', str_contains($ap['message'], 'has been told'), true);
ok('approving twice is refused, and says so plainly',
   str_contains($wv->approve($planner, $prop)['message'], 'already approved'), true);

// the ticket line lands on approval, not before
$pT2 = $wv->approve($planner, $pT);
ok('the ticket thread hears about it ON APPROVAL',
   DB::table(VT::T_MESSAGE)->where('ticket_id', $tid)->count() > $msgsBefore, true);
ok('  …and says "approved for", not "set for"',
   str_contains((string) DB::table(VT::T_MESSAGE)->where('ticket_id', $tid)
        ->orderByDesc('id')->value('body'), 'approved for'), true);

ok('a proposal for a day that has PASSED cannot be approved', (function () use ($wv, $booker, $planner, $vid) {
    $past = \Carbon\Carbon::today()->addDays(2)->format('Y-m-d');
    // ⚠ confirm_replace: fixture only — this bike still carries a live visit from §1.
    $id = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'visit_date' => $past,
                                        'confirm_replace' => 1])['visit_id'];
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($past)->addDays(2));
    $r = $wv->approve($planner, $id);
    \Carbon\Carbon::setTestNow();
    return $r['ok'];
})(), false);

// ═══════════════════════════════════════════════════════════════════════════════
head('§5 Adjust — the planner may change the place, the appointment AND his start time');

$adj = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                     'visit_date' => $soon, 'location_id' => $wsA,
                                     'visit_time' => '09:00', 'confirm_replace' => 1])['visit_id'];
$otherTpl = (int) DB::table('t_ops_shift_template')->where('active', 1)
    ->where('id', '!=', (int) ((new \App\Services\ShiftResolutionService())
        ->getUserShift((int) $rider->id, $soon)['shift_id'] ?: 0))
    ->value('id');
$adjRes = $wv->approve($planner, $adj, [
    'location_id' => $wsB, 'visit_time' => '10:30', 'shift_template_id' => $otherTpl,
]);
ok('the adjusted approval succeeds', $adjRes['ok'], true);
$row = DB::table(WV::T_VISIT)->where('id', $adj)->first();
ok('  …the workshop changed', (int) $row->location_id, $wsB);
ok('  …the appointment time changed', substr((string) $row->visit_time, 0, 5), '10:30');
ok('  …and the pin points at the NEW workshop', (int) ($pinRow($adj)->location_id ?? 0), $wsB);
ok('  ⭐ …and his START TIME that day is the template the planner chose (Danish’s case)',
   (int) ($pinRow($adj)->shift_template_id ?? 0), $otherTpl);
ok('  …bounded to that one day', [(string) $pinRow($adj)->effective_from, (string) $pinRow($adj)->effective_to],
   [$soon, $soon]);
ok('a place that is not ticked as a workshop is refused',
   str_contains($wv->approve($planner, (int) $wv->schedule($booker,
        ['vehicle_id' => $vid, 'visit_date' => $soon, 'confirm_replace' => 1])['visit_id'],
        ['location_id' => $notWs])['message'], 'not ticked as a workshop'), true);

// ═══════════════════════════════════════════════════════════════════════════════
head('§5b 📍 "will he mark attendance at his regular place, or at the workshop?" (owner ask 10-Sep)');

/**
 * ⭐⭐ THE DECISION THAT USED TO BE INFERRED. Before this, picking a registered workshop MEANT
 *    "his day starts there" — two different questions fused into one control. A planner who
 *    wanted "check in at LaCarne as usual, ride over at 11" could not say so, and the rider was
 *    told his place had changed when it had not.
 */
$attProp = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                         'visit_date' => $soon, 'location_id' => $wsA,
                                         'confirm_replace' => 1])['visit_id'];
$card = collect($wv->pendingApprovals($planner))->firstWhere('id', $attProp);
ok('the approval card carries the question', isset($card['attendance']), true);
ok('  …it IS asked for a future day', $card['attendance']['asked'] ?? null, true);
ok('  …a workshop is chosen, so it can be pinned', $card['attendance']['can_pin'] ?? null, true);
ok('  ⭐ …and it names his REGULAR place, or the planner cannot weigh the two',
   is_string($card['attendance']['regular_label'] ?? null)
   && $card['attendance']['regular_label'] !== '', true);
ok('  …the default is the pre-10-Sep inference, so an untouched card behaves as before',
   $card['attendance']['value'] ?? null, 'workshop');

/* ⭐ "He checks in as usual and rides over" — the answer that had no way of being given. */
$rA = $wv->approve($planner, $attProp, ['attendance_at' => 'regular']);
ok('approving with "at his regular place" succeeds', $rA['ok'], true);
ok('  ⭐ …and NOTHING is pinned, even though a workshop was picked', $pinRow($attProp), null);
ok('  …the message does not claim his place moved',
   str_contains((string) $rA['message'], 'checks in at the workshop'), false);
/**
 * ⚠⚠ FOUND ON THE DEVICE (10-Sep). The planner's own answer came back as
 *    "⚠ His check-in place was not changed" — a decision reported as a failure. The ⚠ is
 *    reserved for a pin that was WANTED and did not happen.
 */
ok('  ⚠ …and a DECISION is never reported as a warning',
   str_contains((string) $rA['message'], '⚠'), false);
ok('  ⭐ …it states what will actually happen that morning',
   str_contains((string) $rA['message'], 'checks in as usual'), true);

/* …and the other answer still does what it always did. */
$attProp2 = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                          'visit_date' => $soon, 'location_id' => $wsA,
                                          'confirm_replace' => 1])['visit_id'];
ok('approving with "at the workshop" pins his day',
   $wv->approve($planner, $attProp2, ['attendance_at' => 'workshop'])['ok'], true);
ok('  …to that workshop', (int) ($pinRow($attProp2)->location_id ?? 0), $wsA);

/* ⚠ A typed workshop name has no coordinates to measure an arrival against. */
$attProp3 = (int) $wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                          'visit_date' => $soon, 'workshop' => 'Ali Motors',
                                          'confirm_replace' => 1])['visit_id'];
$rC = $wv->approve($planner, $attProp3, ['attendance_at' => 'workshop']);
ok('"at the workshop" with no REGISTERED workshop is refused', $rC['ok'], false);
ok('  …and says what to do about it',
   str_contains((string) $rC['message'], 'pick a registered workshop'), true);
$cardC = collect($wv->pendingApprovals($planner))->firstWhere('id', $attProp3);
ok('  …the card had already said the choice was unavailable',
   [$cardC['attendance']['can_pin'] ?? null, $cardC['attendance']['value'] ?? null], [false, 'regular']);

/**
 * ⚠⚠ TODAY IS NOT A QUESTION. He has already started his day somewhere; moving his check-in
 *    place backwards would mark him late — or remote — for a place nobody had told him to go
 *    to when he clocked in. `book()` forces `regular` for today, and `approve()` refuses to
 *    take anything else from a client that sends it anyway.
 */
$attToday = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                          'visit_date' => \Carbon\Carbon::today()->format('Y-m-d'),
                                          'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
$cardT = collect($wv->pendingApprovals($planner))->firstWhere('id', $attToday);
ok('a SAME-DAY request does not ask the question', $cardT['attendance']['asked'] ?? null, false);
ok('  …and answers "his regular place"', $cardT['attendance']['value'] ?? null, 'regular');
ok('  ⭐ …and sending "workshop" anyway does NOT move his check-in place',
   (function () use ($wv, $planner, $attToday, $pinRow) {
       $wv->approve($planner, $attToday, ['attendance_at' => 'workshop']);
       return $pinRow($attToday);
   })(), null);

// ═══════════════════════════════════════════════════════════════════════════════
head('§6 declining — the reason travels back, the rider never knew');

$dec = (int) $wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                     'visit_date' => $soon, 'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
ok('a booker cannot decline', $wv->decline($booker, $dec, 'no')['ok'], false);
$dr = $wv->decline($planner, $dec, 'He is on the Faizabad run that morning.');
ok('a planner can', $dr['ok'], true);
ok('  …the row is declined', $statusOf($dec), 'declined');
ok('  …the reason is kept',
   (string) DB::table(WV::T_VISIT)->where('id', $dec)->value('decline_reason'),
   'He is on the Faizabad run that morning.');
ok('  …nothing was ever pinned', $pinRow($dec), null);
/**
 * ⚠ Asserts the DECLINED proposal is not what he sees — not that he sees nothing. Since the
 *   7-Sep ruling a proposal no longer supersedes an approved day, so this rider may still be
 *   holding an earlier approved visit, and he should be: declining a request must leave
 *   whatever he already had exactly where it was.
 */
ok('  …and the rider’s next visit is still not it',
   (int) (($wv->nextForUser((int) $otherRider->id)['id'] ?? 0)) === $dec, false);
ok('declining twice is refused', $wv->decline($planner, $dec, 'again')['ok'], false);

/**
 * ⚠ A declined request is still LISTED to managers (Qasim should see that he was turned down),
 *   so every renderer needs to be able to tell it apart from a plan. Both surfaces drew it as
 *   "scheduled — the rider has been told" until these flags existed.
 */
$decRow = array_values(array_filter(
    $wv->listVisits(['vehicle_id' => $vid2, 'include_proposed' => true]),
    fn ($v) => $v['id'] === $dec))[0] ?? null;
ok('a declined request is still listed to managers, flagged as declined',
   [$decRow['is_declined'] ?? null, $decRow['is_proposed'] ?? null], [true, false]);
ok('  …naming who said no', $decRow['declined_by_name'] ?? null, $planner->fullname);
ok('  …and carrying the reason to show him',
   $decRow['decline_reason'] ?? null, 'He is on the Faizabad run that morning.');

// ═══════════════════════════════════════════════════════════════════════════════
head('§7 escalation with no cron — nudge, then auto-DECLINE, never auto-approve');

$realNow = \Carbon\Carbon::now();
$tm = \Carbon\Carbon::today()->addDay()->format('Y-m-d');
$esc = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                     'visit_date' => $tm, 'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];

\Carbon\Carbon::setTestNow($realNow->copy()->setTime(12, 0));
ok('at midday nobody is nudged yet', in_array($esc, $wv->escalateProposals()['nudge'], true), false);

\Carbon\Carbon::setTestNow($realNow->copy()->setTime(17, 5));
ok('at 17:05 the planners are nudged', in_array($esc, $wv->escalateProposals()['nudge'], true), true);
ok('  …exactly once', in_array($esc, $wv->escalateProposals()['nudge'], true), false);
ok('  …and it is still only a proposal — silence never approves', $statusOf($esc), 'proposed');

/**
 * ⏰ OWNER RULING (6-Sep review): the planners get until just before his SHIFT, not just
 *    until midnight — a lead (WORKSHOP_APPROVAL_LEAD_MIN, default 60) before the earlier of
 *    his shift start and the appointment, because he has to hear before he leaves home.
 */
$cut = $wv->approvalCutoffFor(['user_id' => (int) $rider->id, 'visit_date' => $tm, 'visit_time' => null]);
$shiftStart = (new \App\Services\ShiftResolutionService())->getUserShift((int) $rider->id, $tm)['shift_start'] ?? null;
ok('the cut-off is an hour before his shift start that day',
   $cut->format('Y-m-d H:i'), \Carbon\Carbon::parse($tm . ' ' . ($shiftStart ?: '08:00'))->subMinutes(60)->format('Y-m-d H:i'));
ok('  …and the appointment wins when it is EARLIER than his shift',
   $wv->approvalCutoffFor(['user_id' => (int) $rider->id, 'visit_date' => $tm, 'visit_time' => '06:00'])->format('H:i'), '05:00');

\Carbon\Carbon::setTestNow($cut->copy()->subMinutes(5));
ok('five minutes BEFORE the cut-off it is still waiting',
   in_array($esc, $wv->escalateProposals()['declined'], true), false);
ok('  …and a planner can still approve it', (function () use ($wv, $planner, $booker, $vid2, $otherRider, $tm, $wsA, $statusOf) {
    /**
     * ⚠ The clock is moved to just before THIS rider's cut-off BEFORE the booking, not after
     *   it. Since 7-Sep `schedule()` refuses a proposal that is already past its own cut-off
     *   (it would be auto-declined on the next poll — "dead on arrival", owner ruling), and
     *   this rider's cut-off can fall earlier in the day than the one the outer test used.
     *   The assertion is unchanged: five minutes before the cut-off, a planner can still say yes.
     */
    $cutX = $wv->approvalCutoffFor(['user_id' => (int) $otherRider->id, 'visit_date' => $tm, 'visit_time' => null]);
    \Carbon\Carbon::setTestNow($cutX->copy()->subMinutes(5));
    $x = (int) $wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                       'visit_date' => $tm, 'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
    $r = $wv->approve($planner, $x);
    return $r['ok'] && $statusOf($x) === 'scheduled';
})(), true);

/**
 * ⭐⭐ OWNER RULING, 10-Sep-2026: **THE DAY ITSELF HAS NO CUT-OFF.**
 *
 * *"for same day bookings no need for cut off time unless the rider didnt go at all and
 * checked out then we should send a notification to the managers and it should end there."*
 *
 * ⚠⚠ THIS REVERSES THE THREE ASSERTIONS THAT USED TO LIVE HERE, and the reversal is the
 *    point, so read why before putting them back. The cut-off protects ONE promise: that a
 *    rider hears about a change to WHERE HE CHECKS IN before he leaves home. Once the day has
 *    arrived that promise is already kept or already broken, and nothing an approver does can
 *    change it. Holding a same-day breakdown to "should have been decided by 07:00" meant a
 *    bike that broke at 11:00 could not be sent in until tomorrow — the case this whole round
 *    exists for.
 *
 * ⚠ Note the clock: `$cut` for TOMORROW falls at 07:00 TOMORROW, so the instant we step past
 *   it, "tomorrow" has become TODAY. That is exactly the boundary being tested.
 */
\Carbon\Carbon::setTestNow($cut->copy()->addMinute());
$sweep = $wv->escalateProposals();
ok('one minute after the old cut-off — the day has arrived, so it SURVIVES',
   in_array($esc, $sweep['declined'], true), false);
ok('  …still a live proposal for the planners', $statusOf($esc), 'proposed');
ok('  …and there is no deadline to print on their card',
   (function () use ($wv, $planner, $esc) {
       foreach ($wv->pendingApprovals($planner) as $r) {
           if ((int) $r['id'] === $esc) return $r['approve_by'];
       }
       return 'not-found';
   })(), null);
ok('  ⚠ …and approve() now ACCEPTS it on the day', (function () use ($wv, $planner, $booker, $vid, $rider, $tm, $wsA, $cut, $statusOf) {
    \Carbon\Carbon::setTestNow($cut->copy()->subMinutes(30));
    $y = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                       'visit_date' => $tm, 'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
    \Carbon\Carbon::setTestNow($cut->copy()->addMinute());
    $r = $wv->approve($planner, $y);
    return $r['ok'] && $statusOf($y) === 'scheduled';
})(), true);

/**
 * ⭐⭐ A FUTURE day still has a real cut-off — enforced AT THE DOOR, which is where it always
 *    actually mattered.
 *
 * ⚠⚠ AND A CORRECTION TO WHAT THIS SECTION USED TO CLAIM. The sweep's candidate query is
 *    `visit_date <= today`, so a proposal for a FUTURE day was never a candidate for the
 *    auto-decline in the first place — the cut-off branch only ever fired for a visit dated
 *    today. Now that today is exempt (owner ruling), that branch is unreachable in practice
 *    and the sweep's real job is closing days that have PASSED. Both facts are asserted here
 *    rather than left to be rediscovered: a probe was needed to see it, because the old
 *    assertion passed for the wrong reason (its "future" visit had become today).
 */
ok('a future day is still refused at the door once its cut-off has gone', (function () use ($wv, $booker, $vid2, $otherRider, $wsA) {
    $day = \Carbon\Carbon::today()->addDays(2)->format('Y-m-d');
    /**
     * ⚠ BOTH knobs are needed to put the cut-off on the PREVIOUS EVENING, and it took a probe
     *   to see why: cut-off = min(shift start, appointment) − lead. This rider's shift starts
     *   at 13:00, so even a 12-hour lead lands at 01:00 ON the visit day. A 06:00 appointment
     *   is what moves the minimum early enough for 12 hours to reach back over midnight.
     */
    DB::table('t_fin_config')->updateOrInsert(['config_key' => 'WORKSHOP_APPROVAL_LEAD_MIN'],
                                              ['config_value' => '720']);   // 12h
    // 06:00 − 12h = 18:00 the evening before; stand one hour past it, day still in the future.
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::today()->addDay()->setTime(19, 0));
    $r = $wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                 'visit_date' => $day, 'visit_time' => '06:00',
                                 'location_id' => $wsA, 'confirm_replace' => 1]);
    $ok = empty($r['ok']) && str_contains((string) ($r['message'] ?? ''), 'Too late');
    DB::table('t_fin_config')->where('config_key', 'WORKSHOP_APPROVAL_LEAD_MIN')
        ->update(['config_value' => '60']);
    return $ok;
})(), true);

/**
 * ⚠⚠ AND THE DAY THAT SIMPLY PASSED. With TODAY exempt from the cut-off, a proposal nobody
 *    answered would otherwise sit in the planners' queue for ever — the sweep used to catch it
 *    on its way through the cut-off test. It is now closed explicitly, with its own reason.
 */
ok('a proposal whose day has gone is closed with a reason', (function () use ($wv, $booker, $vid2, $otherRider, $wsA, $statusOf) {
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::today()->setTime(5, 0));
    $p = (int) ($wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                        'visit_date' => \Carbon\Carbon::today()->format('Y-m-d'),
                                        'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'] ?? 0);
    if (!$p) return 'no fixture';
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::today()->addDay()->setTime(9, 0));   // the day after
    $sw = $wv->escalateProposals();
    return in_array($p, $sw['declined'], true) && $statusOf($p) === 'declined'
        && str_contains((string) DB::table(WV::T_VISIT)->where('id', $p)->value('decline_reason'), 'day passed');
})(), true);

/**
 * ⚠ A proposal made TODAY for TODAY, before the cut-off, survives the sweep. Killing it in the
 *   same sweep that created it would make same-day bookings impossible.
 */
\Carbon\Carbon::setTestNow(\Carbon\Carbon::today()->setTime(5, 0));   // before any shift's cut-off
$sameDay = (int) $wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                         'visit_date' => \Carbon\Carbon::today()->format('Y-m-d'),
                                         'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
ok('a proposal made TODAY for TODAY, before the cut-off, survives the sweep',
   in_array($sameDay, $wv->escalateProposals()['declined'], true), false);
ok('  …and is still waiting', $statusOf($sameDay), 'proposed');
\Carbon\Carbon::setTestNow();

// ⭐ approve() must clear the nudge flag, or the rider loses his own day-before reminder.
$nud = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                     'visit_date' => $tm, 'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
\Carbon\Carbon::setTestNow($realNow->copy()->setTime(17, 5));
$wv->escalateProposals();
ok('the nudged proposal carries reminded_at',
   (bool) DB::table(WV::T_VISIT)->where('id', $nud)->value('reminded_at'), true);
$wv->approve($planner, $nud);
ok('  ⭐ approving CLEARS it, so his own day-before reminder still fires',
   DB::table(WV::T_VISIT)->where('id', $nud)->value('reminded_at'), null);
ok('  …and the sweep now does find him', in_array($nud, array_column($wv->dueReminders(), 'id'), true), true);
\Carbon\Carbon::setTestNow();

// ═══════════════════════════════════════════════════════════════════════════════
head('§8 a proposal follows the bike, and stays a proposal');

$hp = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                    'visit_date' => $soon, 'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
$moved = $wv->onHandover($vid, (int) $rider->id, (int) $otherRider->id, (int) $planner->id);
ok('the handover moved it', $moved >= 1, true);
ok('  …to the new holder',
   (int) DB::table(WV::T_VISIT)->where('id', $hp)->value('user_id'), (int) $otherRider->id);
ok('  ⚠⚠ …and it is STILL a proposal — a handover is not a back door round approval',
   $statusOf($hp), 'proposed');
ok('  …with nothing pinned for either man', $pinRow($hp), null);

// ═══════════════════════════════════════════════════════════════════════════════
head('§9 the other doors, on a proposal');

$px = (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                    'visit_date' => $soon, 'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
ok('the rider cannot ACCEPT a proposal', $wv->accept($rider, $px)['ok'], false);
ok('a manager cannot mark it DONE', $wv->markDone($booker, $px, [])['ok'], false);
ok('nor report it not done', $wv->reportNotDone($booker, $px, 'x')['ok'], false);

$msgsNow = DB::table(VT::T_MESSAGE)->where('ticket_id', $tid)->count();
$pc = (int) $wv->schedule($booker, ['vehicle_id' => $vid2, 'user_id' => $otherRider->id,
                                    'visit_date' => $soon, 'ticket_id' => $tid,
                                    'location_id' => $wsA, 'confirm_replace' => 1])['visit_id'];
$cx = $wv->cancel($booker, $pc, 'not going after all');
ok('the booker may withdraw his own proposal', $cx['ok'], true);
ok('  …and is told nobody had been told', str_contains($cx['message'], 'Nobody had been told'), true);
ok('  ⚠ …and the rider’s thread stays silent — no plan is announced by cancelling it',
   DB::table(VT::T_MESSAGE)->where('ticket_id', $tid)->count(), $msgsNow);

// ═══════════════════════════════════════════════════════════════════════════════
head('§10 add a workshop inline — coordinates are the whole point');

$cls = new CompanyLocationsService();
ok('no coordinates at all is refused',
   str_contains($cls->createWorkshop(['location_name' => 'TEST No Coords'], (int) $booker->id)['message'],
       'needs coordinates'), true);
ok('  …and nothing was written',
   DB::table('t_ops_company_locations')->where('location_name', 'TEST No Coords')->exists(), false);

ok('⚠⚠ a Google Maps PLACE link is refused BY NAME (the Sep-1 trap)',
   str_contains($cls->createWorkshop([
        'location_name' => 'TEST Place Url',
        'maps_url' => 'https://www.google.com/maps/place/Nizami+Farms/data=!4m2!3m1!1s0x0',
   ], (int) $booker->id)['message'], 'carries no'), true);

$madePin = $cls->createWorkshop([
    'location_name' => 'TEST Pin Url',
    'maps_url' => 'https://www.google.com/maps/@33.6867,73.0331,17z',
], (int) $booker->id);
ok('a Maps link with a dropped pin works', $madePin['ok'], true);
ok('  …and it is ticked as a workshop, so the picker offers it',
   in_array((int) $madePin['location']['id'], array_column($wv->workshopLocations(), 'id'), true), true);

$madeGps = $cls->createWorkshop([
    'location_name' => 'TEST Phone GPS', 'latitude' => 33.7081, 'longitude' => 73.0886,
], (int) $booker->id);
ok('the phone’s own coordinates work', $madeGps['ok'], true);
ok('  …and it is never primary and never a handover point',
   [(int) DB::table('t_ops_company_locations')->where('id', $madeGps['location']['id'])->value('is_primary'),
    (int) DB::table('t_ops_company_locations')->where('id', $madeGps['location']['id'])->value('is_handover_point')],
   [0, 0]);
ok('  …and a workshop can never become somebody’s standing base',
   \App\Services\LocationService::isAssignableOffice((int) $madeGps['location']['id']), false);

$again = $cls->createWorkshop(['location_name' => 'TEST Phone GPS',
                               'latitude' => 33.7081, 'longitude' => 73.0886], (int) $booker->id);
ok('adding the same name twice returns the SAME row, not a second one',
   (int) $again['location']['id'], (int) $madeGps['location']['id']);
ok('⭐ an existing ordinary location typed in again is simply TICKED as a workshop',
   (function () use ($cls, $booker, $notWs) {
       $name = (string) DB::table('t_ops_company_locations')->where('id', $notWs)->value('location_name');
       $r = $cls->createWorkshop(['location_name' => $name, 'latitude' => 33.7, 'longitude' => 73.1], (int) $booker->id);
       return $r['ok'] && (int) $r['location']['id'] === $notWs
           && (int) DB::table('t_ops_company_locations')->where('id', $notWs)->value('is_workshop') === 1;
   })(), true);

// ═══════════════════════════════════════════════════════════════════════════════
head('§11 the planners’ banner');

ok('a booker sees no approval queue', $wv->pendingApprovals($booker), []);
ok('a rider sees no approval queue', $wv->pendingApprovals($rider), []);
$queue = $wv->pendingApprovals($planner);
ok('a planner sees what is waiting', count($queue) > 0, true);
ok('  …only proposals',
   array_values(array_unique(array_column($queue, 'status'))), ['proposed']);
ok('  …each carrying the warnings the booker was shown',
   array_key_exists('warnings', $queue[0]), true);
ok('  …and who asked for it', !empty($queue[0]['proposed_by_name']), true);

} finally {
    DB::rollBack();
    \Carbon\Carbon::setTestNow();
}

// ═══════════════════════════════════════════════════════════════════════════════
head('§12 the TAP — a manager’s push must land somewhere he can act');

/**
 * ⚠⚠ THE SEAM THE BUTTON BUG TAUGHT US TO CHECK (6-Sep review). The phone routed every
 *    `workshop_visit` push to the RIDER's My Vehicle screen — so Farooq tapping "needs your
 *    approval" was shown a bike he does not hold. Manager-bound pushes now carry
 *    `audience=manager` and the router sends them to Fleet (or StoreShifts for a planner with
 *    no fleet key). Asserted at the source on both sides, so the two cannot drift apart.
 */
$fb  = file_get_contents(__DIR__ . '/app/Services/FirebaseService.php');
$rt  = file_get_contents(__DIR__ . '/../NizamiFarmsMobile/src/services/notificationService.js');
/**
 * ⚠⚠ THE INTENT, NOT A HEAD-COUNT. This asserted `substr_count(...) === 8`, so it went red the
 *    moment the trip round added its own manager pushes — reporting "16, want 8" as if a
 *    regression, when in fact every one of the new ones was correctly tagged. A test that has to
 *    be edited whenever the feature grows teaches people to edit it without reading it.
 *
 * What actually matters: inside `notifyWorkshopVisit`, EVERY push aimed at a permission GROUP
 * carries `$mgr` (which is `$data + ['audience' => 'manager']`), because a manager tapping one
 * must land on a screen he can act on. So: find the method, find every group send, and check
 * none of them passes the bare `$data`.
 */
$wsMethod = (function (string $src): string {
    $i = strpos($src, 'public function notifyWorkshopVisit');
    if ($i === false) return '';
    $depth = 0; $started = false;
    for ($j = $i; $j < strlen($src); $j++) {
        if ($src[$j] === '{') { $depth++; $started = true; }
        elseif ($src[$j] === '}') { $depth--; if ($started && $depth === 0) return substr($src, $i, $j - $i + 1); }
    }
    return '';
})($fb);
ok('notifyWorkshopVisit was found to inspect', $wsMethod !== '', null, true);
preg_match_all('/sendToPermissionGroup\(\s*\$?\w+\s*,\s*\[[^\]]*\]\s*,\s*\$(\w+)/s', $wsMethod, $grp);
ok('every manager-bound workshop push is tagged for the router',
   [count($grp[1]) >= 8, array_values(array_unique($grp[1]))], [true, ['mgr']]);
// The first `], $…` after each rider notifyUser is that call's own data argument.
preg_match_all('/notifyUser\(\$riderId, \[[^\]]*\], \$(\w+)/', $fb, $riderArgs);
ok('  …and none of the RIDER pushes is (his tap still opens My Vehicle)',
   [count($riderArgs[1]) >= 5, array_values(array_unique($riderArgs[1]))], [true, ['data']]);
ok('the phone router branches on that tag', str_contains($rt, "data.audience === 'manager'"), true);
ok('  …to the fleet page with the machine open, when he may open it',
   str_contains($rt, "list.includes('view_bike_costs')") && str_contains($rt, "nav.navigate('Fleet', vid ?"), true);
ok('  …and to the shifts page otherwise — Farooq holds no fleet key',
   substr_count($rt, "nav.navigate('StoreShifts')") >= 2, true);
ok('  …reading the LOGIN permission snapshot, since this runs outside React',
   str_contains($rt, "import {getPermissions} from '../utils/storage'"), true);

head('§13 nothing left behind');
ok('visit count back to where it started', DB::table(WV::T_VISIT)->count(), $beforeVisits);
ok('ticket count back to where it started', DB::table(VT::T_TICKET)->count(), $beforeTickets);
ok('location count back to where it started', DB::table('t_ops_company_locations')->count(), $beforeLocs);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "✅" : "❌") . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
