<?php
/**
 * BIKE TICKETS — the rules, on real data (Phase 1, Sep-02 2026).
 * Plan: VEHICLE-TICKETS-AND-WORKSHOP-PLAN-SEP2026.md §2.
 *
 * What these prove:
 *   §1 who may OPEN one — and that a rider cannot open against someone elses bike;
 *   §2 the thread: the opening text is the first message, not a special case;
 *   §3 who may READ — including the point of the whole design, that the history
 *      travels with the MACHINE across a handover;
 *   §4 replies: a manager reply acknowledges and assigns; a rider reply does not;
 *   §5 CLOSING is managers-only (owner ruling) and a rider cannot do it;
 *   §6 the 7-day re-open window (owner ruling), on both sides of the boundary;
 *   §7 unread counts are per person and never count your own messages;
 *   §8 the banner summary;
 *   §9 status precedence — a chatty manager cannot knock 'scheduled' back down.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ ONE user is authenticated per process — so this script never logs anyone in and
 *   passes the user objects explicitly instead. That is also closer to what the
 *   service actually promises: the rules are arguments, not ambient state.
 *
 * Run:  php test_vehicle_tickets.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleTicketService as VT;
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

$svc = new VT();
ok('the ticket tables exist (run vehicle_tickets_sep2026.sql first)', $svc->available(), true);
if (!$svc->available()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

// ─── fixtures: DISCOVERED, never hard-coded ──────────────────────────────────
head('§0 fixtures');

$manager = null; $rider = null; $otherRider = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if (!$manager && $u->hasPermission(VT::PERMISSION)) $manager = $u;
}
$res = new VehicleResolver();
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = $res->currentVehicleFor((int) $uid);
    if (!$v) continue;
    $u = User::find((int) $uid);
    if (!$u) continue;
    if (!$rider)                                  { $rider = $u; continue; }
    if (!$otherRider && (int) $u->id !== (int) $rider->id) { $otherRider = $u; break; }
}
ok('a manager holding manage_vehicle_tickets exists', (bool) $manager, null, true);
ok('two riders with registered machines exist', (bool) ($rider && $otherRider), null, true);
if (!$manager || !$rider || !$otherRider) { echo "\nfixtures missing — stopping.\n"; exit(1); }

$riderVid  = (int) $res->currentVehicleFor((int) $rider->id);
$otherVid  = (int) $res->currentVehicleFor((int) $otherRider->id);
ok('…on two different machines', $riderVid !== $otherVid, true);
echo "  · manager={$manager->id} rider={$rider->id}(v{$riderVid}) other={$otherRider->id}(v{$otherVid})\n";

// A rider who is NOT a manager, so the manager-only rules are actually exercised.
ok('the rider is NOT a manager (so the gates below mean something)',
   $svc->canManage($rider), false);

$before = DB::table(VT::T_TICKET)->count();
$beforeMsgs = DB::table(VT::T_MESSAGE)->count();

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
head('§1 opening');

$r = $svc->open($rider, ['title' => 'Front brake is loose', 'body' => 'It grabs late.', 'urgent' => true]);
ok('a rider can open one against the bike he holds', $r['ok'], true);
$t1 = (int) $r['ticket_id'];
$row = $svc->find($t1);
ok('  …and the bike was resolved from the registry, not the client',
   (int) $row['vehicle_id'], $riderVid);
ok('  …he is recorded as the subject', (int) $row['opened_for_user_id'], (int) $rider->id);
ok('  …it starts open', $row['status'], 'open');
ok('  …and urgent is carried', (int) $row['urgent'], 1);

$bad = $svc->open($rider, ['vehicle_id' => $otherVid, 'title' => 'Not my bike']);
ok('a rider CANNOT open one against someone elses bike', $bad['ok'], false);
ok('  …and is told why', (bool) preg_match('/not assigned to you/i', $bad['message']), null, true);

$noTitle = $svc->open($rider, ['title' => '   ']);
ok('a blank title is refused', $noTitle['ok'], false);

$mgr = $svc->open($manager, ['vehicle_id' => $otherVid, 'title' => 'Manager raised this']);
ok('a manager can open one for any bike', $mgr['ok'], true);
$t2 = (int) $mgr['ticket_id'];
ok('  …and the rider it concerns is filled in from the registry',
   (int) $svc->find($t2)['opened_for_user_id'], (int) $otherRider->id);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 the thread');

$thread = $svc->thread($t1);
ok('the opening description IS the first message', count($thread), 1);
ok('  …authored by the rider', (int) $thread[0]['user_id'], (int) $rider->id);
ok('  …as plain text', $thread[0]['kind'], 'text');

// ─────────────────────────────────────────────────────────────────────────────
head('§3 who may read — the history follows the MACHINE');

ok('the opener can read it', $svc->mayRead($rider, $svc->find($t1)), true);
ok('a manager can read anything', $svc->mayRead($manager, $svc->find($t1)), true);
ok('an unrelated rider cannot', $svc->mayRead($otherRider, $svc->find($t1)), false);

// ⭐ THE POINT OF THE DESIGN: hand the bike over, and the new keeper inherits the
//   complaint history — staged inside the rolled-back transaction rather than
//   asserted against live custody (the fixture-drift lesson).
DB::table('t_ops_vehicle_assignment')->where('vehicle_id', $riderVid)->whereNull('released_on')
    ->update(['released_on' => now()->subDay()->format('Y-m-d')]);
DB::table('t_ops_vehicle_assignment')->insert([
    'vehicle_id' => $riderVid, 'user_id' => (int) $otherRider->id,
    'assigned_on' => now()->subDay()->format('Y-m-d'), 'assigned_by' => (int) $manager->id,
    'created_at' => now(), 'updated_at' => now(),
]);
VehicleResolver::flush(); RiderDayLegs::flush();
ok('after a handover the NEW keeper can read the bikes tickets',
   $svc->mayRead($otherRider, $svc->find($t1)), true);
/**
 * ⭐⭐ OWNER RULING, 5-Sep-2026 — this assertion is the OPPOSITE of what it used to be.
 *
 *    "Since the bike moved to someone else, the new owner should be the one seeing this
 *     ticket until it's moved back."
 *
 *    A ticket belongs to the MACHINE, so `opened_by` is no longer a standalone grant. The
 *    old rule is exactly what put a handed-back bike's ticket on the previous rider's phone
 *    an hour after it became someone else's. The row still records who raised it — the
 *    history never moves — but the VIEW follows the registry.
 */
ok('  …and the original reporter can NO LONGER read it — it went with the bike',
   $svc->mayRead($rider, $svc->find($t1)), false);
ok('  …nor does it appear in his list',
   in_array($t1, array_column($svc->listFor($rider, ['status' => 'all', 'limit' => 50]), 'id'), true), false);

// …and "until it's moved back": give the machine back, and it is his to see again. The
// rest of the suite continues with him holding it, as it did before this section.
DB::table('t_ops_vehicle_assignment')->where('vehicle_id', $riderVid)->whereNull('released_on')
    ->update(['released_on' => now()->format('Y-m-d')]);
DB::table('t_ops_vehicle_assignment')->insert([
    'vehicle_id' => $riderVid, 'user_id' => (int) $rider->id,
    'assigned_on' => now()->format('Y-m-d'), 'assigned_by' => (int) $manager->id,
    'created_at' => now(), 'updated_at' => now(),
]);
VehicleResolver::flush(); RiderDayLegs::flush();
ok('  …and when the bike comes BACK to him, so does the ticket',
   $svc->mayRead($rider, $svc->find($t1)), true);
ok('  …while the interim keeper no longer sees it',
   $svc->mayRead($otherRider, $svc->find($t1)), false);

// ─────────────────────────────────────────────────────────────────────────────
head('§4 replies');

$rep = $svc->reply($manager, $t1, ['kind' => 'text', 'body' => 'Bringing it in tomorrow.']);
ok('a manager can reply', $rep['ok'], true);
$row = $svc->find($t1);
ok('  …which acknowledges the ticket', $row['status'], 'acknowledged');
ok('  …stamps how long the rider waited', (bool) $row['first_response_at'], null, true);
ok('  …and assigns it to him', (int) $row['assigned_to'], (int) $manager->id);

$rep2 = $svc->reply($rider, $t1, ['kind' => 'text', 'body' => 'Thanks.']);
ok('the rider can reply on his own ticket', $rep2['ok'], true);
ok('  …and that does NOT change the status', $svc->find($t1)['status'], 'acknowledged');

$nope = $svc->reply($otherRider, $t2, ['kind' => 'text', 'body' => '']);
ok('an empty text reply is refused', $nope['ok'], false);
$noMedia = $svc->reply($manager, $t1, ['kind' => 'photo']);
ok('a photo reply with no file is refused', $noMedia['ok'], false);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 closing is managers-only (owner ruling)');

$riderClose = $svc->close($rider, $t1, 'all good now');
ok('a RIDER cannot close his own ticket', $riderClose['ok'], false);
ok('  …and is told a manager must', (bool) preg_match('/manager/i', $riderClose['message']), null, true);
ok('  …and it really is still open', $svc->find($t1)['status'], 'acknowledged');

$close = $svc->close($manager, $t1, 'Brake adjusted');
ok('a manager can close', $close['ok'], true);
$row = $svc->find($t1);
ok('  …status is closed', $row['status'], 'closed');
ok('  …the closer is recorded', (int) $row['closed_by'], (int) $manager->id);
ok('  …with his note', $row['close_note'], 'Brake adjusted');
$sys = array_values(array_filter($svc->thread($t1), fn ($m) => $m['kind'] === 'system'));
ok('  …and a system line records it in the thread', count($sys) >= 1, true);
ok('  …naming who closed it and why',
   (bool) preg_match('/closed by .*brake adjusted/i', end($sys)['body']), null, true);

ok('closing twice is refused', $svc->close($manager, $t1, null)['ok'], false);

// ─────────────────────────────────────────────────────────────────────────────
head('§6 the 7-day re-open window (owner ruling)');

$reply = $svc->reply($rider, $t1, ['kind' => 'text', 'body' => 'It is doing it again.']);
ok('a rider reply INSIDE the window re-opens it', $reply['ok'], true);
ok('  …and says so', $reply['reopened'], true);
ok('  …status is back to open', $svc->find($t1)['status'], 'open');
ok('  …the close note is cleared, so it cannot read as still-closed',
   $svc->find($t1)['close_note'], null);
$sys = array_values(array_filter($svc->thread($t1), fn ($m) => $m['kind'] === 'system'));
ok('  …and the thread records the re-open',
   (bool) preg_match('/re-opened by a reply/i', end($sys)['body']), null, true);

// Close it again and age the closure past the window.
$svc->close($manager, $t1, 'Fixed properly');
DB::table(VT::T_TICKET)->where('id', $t1)
    ->update(['closed_at' => now()->subDays(VT::REOPEN_WINDOW_DAYS + 1)]);
$late = $svc->reply($rider, $t1, ['kind' => 'text', 'body' => 'Still bad.']);
ok('a rider reply OUTSIDE the window is refused', $late['ok'], false);
ok('  …and points him at a new ticket',
   (bool) preg_match('/new ticket/i', $late['message']), null, true);
ok('  …and it stayed closed', $svc->find($t1)['status'], 'closed');

// ⚠ A manager adding a note to a long-closed ticket must NOT silently reopen it.
$mgrNote = $svc->reply($manager, $t1, ['kind' => 'text', 'body' => 'Invoice attached later.']);
ok('a MANAGER may still annotate a closed ticket', $mgrNote['ok'], true);
ok('  …without reopening his own close', $svc->find($t1)['status'], 'closed');

$re = $svc->reopen($manager, $t1);
ok('a manager can deliberately re-open', $re['ok'], true);
ok('  …status open again', $svc->find($t1)['status'], 'open');
ok('re-opening an open ticket is refused', $svc->reopen($manager, $t1)['ok'], false);
ok('a rider cannot re-open', $svc->reopen($rider, $t1)['ok'], false);

// ─────────────────────────────────────────────────────────────────────────────
head('§7 unread is per person, and never your own words');

$counts = $svc->unreadCounts((int) $manager->id, [$t1]);
$mine   = $svc->unreadCounts((int) $rider->id, [$t1]);
ok('the manager has unread messages on it', ($counts[$t1] ?? 0) > 0, true);
$svc->markRead((int) $manager->id, $t1);
ok('  …until he reads it', $svc->unreadCounts((int) $manager->id, [$t1])[$t1] ?? 0, 0);

$svc->reply($rider, $t1, ['kind' => 'text', 'body' => 'One more thing.']);
ok('a new reply makes it unread again for the manager',
   ($svc->unreadCounts((int) $manager->id, [$t1])[$t1] ?? 0) > 0, true);
ok('  …but NOT for the rider who wrote it',
   $svc->unreadCounts((int) $rider->id, [$t1])[$t1] ?? 0, 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§8 the banner summary');

$sum = $svc->summaryFor($manager);
ok('the manager sees open tickets', $sum['count'] >= 1, true);
ok('  …and is told he can act', $sum['can_manage'], true);

/**
 * ⭐⭐ latest_id must be the newest MESSAGE id, not the newest ticket id. The mobile
 *    banner only re-fires when this number goes UP, so keyed to the ticket it would
 *    announce a ticket once and then go silent — and the rider waiting for an answer,
 *    the person the feature exists for, would never be told he had one.
 */
$maxMsg = (int) DB::table(VT::T_MESSAGE)
    ->whereIn('ticket_id', array_column($svc->listFor($manager, ['status' => 'open']), 'id'))
    ->max('id');
ok('  …latest_id tracks the newest MESSAGE, so a reply re-fires the banner',
   (int) $sum['latest_id'], $maxMsg);

// ⚠ NOT $before — that is the row-count snapshot §10 checks the rollback against.
$markBefore = (int) $svc->summaryFor($manager)['latest_id'];
$svc->reply($rider, $t1, ['kind' => 'text', 'body' => 'Any update?']);
ok('  …and it moves when a new message arrives',
   (int) $svc->summaryFor($manager)['latest_id'] > $markBefore, true);

$riderSum = $svc->summaryFor($rider);
ok('the rider sees only what concerns him',
   count(array_filter($svc->listFor($rider, ['status' => 'all']),
         fn ($x) => $x['opened_by'] !== (int) $rider->id
                 && $x['opened_for_user_id'] !== (int) $rider->id
                 && !in_array($x['vehicle_id'], $svc->ownMachineIds((int) $rider->id), true))), 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§9 a chatty manager cannot undo a scheduled visit (Phase 2 guard)');

DB::table(VT::T_TICKET)->where('id', $t2)->update(['status' => 'scheduled']);
$svc->reply($manager, $t2, ['kind' => 'text', 'body' => 'Confirmed with the workshop.']);
ok('replying on a SCHEDULED ticket leaves it scheduled', $svc->find($t2)['status'], 'scheduled');

// ─────────────────────────────────────────────────────────────────────────────
head('§11 the CONTROLLER — the door both surfaces come through');

/**
 * The service is where the rules live, but the controller is where the web/mobile
 * split is, and where an upload becomes a message. Exercised as the MANAGER, since
 * one process may authenticate one user (see [[replica-scenario-probes-aug27]]).
 */
\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($manager->id);
$ctl = app(\App\Http\Controllers\CRM\VehicleTicketController::class);

$mk = function (string $method, string $uri, array $params = []) {
    $r = \Illuminate\Http\Request::create($uri, $method, $params);
    $r->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());
    return $r;
};
$json = fn ($resp) => json_decode($resp->getContent(), true);

$listRes = $json($ctl->index($mk('GET', '/tickets', ['status' => 'all'])));
ok('index answers', $listRes['success'] ?? false, true);
ok('  …and tells the client whether it may act', $listRes['can_manage'], true);
ok('  …and that the feature is set up', $listRes['available'], true);

$openRes = $json($ctl->store($mk('POST', '/tickets', [
    'vehicle_id' => $riderVid, 'title' => 'Controller test', 'body' => 'via HTTP',
])));
ok('store opens one', $openRes['success'] ?? false, true);
$t3 = (int) $openRes['ticket_id'];

$showRes = $json($ctl->show($mk('GET', "/tickets/$t3"), $t3));
ok('show returns the ticket and its thread', count($showRes['messages'] ?? []), 1);
ok('  …shaped the same way the list shapes it', $showRes['ticket']['id'] ?? 0, $t3);
ok('  …and says the manager may close it', $showRes['can_close'], true);

$replyRes = $json($ctl->reply($mk('POST', "/tickets/$t3/reply", ['body' => 'On it.']), $t3));
ok('reply posts a message', $replyRes['success'] ?? false, true);
ok('  …and the thread grew', count($json($ctl->show($mk('GET', "/tickets/$t3"), $t3))['messages']), 2);

$emptyReply = $ctl->reply($mk('POST', "/tickets/$t3/reply", ['body' => '   ']), $t3);
ok('an empty reply with no attachment is refused', $emptyReply->getStatusCode(), 422);

$missing = $ctl->show($mk('GET', '/tickets/99999999'), 99999999);
ok('an unknown ticket is a clean 404', $missing->getStatusCode(), 404);

$closeRes = $json($ctl->close($mk('POST', "/tickets/$t3/close", ['note' => 'done']), $t3));
ok('close works through the controller', $closeRes['success'] ?? false, true);
ok('  …and closing again is refused', $ctl->close($mk('POST', "/tickets/$t3/close"), $t3)->getStatusCode(), 422);

$alertRes = $json($ctl->alerts($mk('GET', '/tickets/alerts')));
ok('the banner endpoint answers', $alertRes['success'] ?? false, true);
ok('  …with the keys the banners read',
   array_values(array_diff(['count', 'unread', 'latest_id', 'latest', 'can_manage'], array_keys($alertRes))), []);

// ─────────────────────────────────────────────────────────────────────────────
head('§12 tickets never creep into WhatsApp');

/**
 * Owner: "this conversation … doesn’t creep into the regular messages right?"
 * The thread lives in its own tables; nothing here reads or writes a WhatsApp row.
 */
$waTables = array_values(array_filter(['t_wa_conversations', 't_wa_messages', 't_wa_conversation', 't_wa_message'],
    fn ($t) => \Illuminate\Support\Facades\Schema::hasTable($t)));
$waBefore = [];
foreach ($waTables as $t) $waBefore[$t] = DB::table($t)->count();
// The full flow: open with text, photo reply, voice reply, close.
// ⚠ §3 handed the machine to $otherRider and then BACK to $rider (the "until it's moved
//   back" ruling), so the keeper here is $rider again — the manager opens it FOR him.
//   The replier below must be the keeper: since 5-Sep visibility follows the registry, a
//   non-keeper's reply is refused and the message count would come up short.
$iso = $svc->open($manager, ['vehicle_id' => $riderVid, 'opened_for_user_id' => (int) $rider->id,
                             'title' => 'Isolation probe', 'body' => 'text']);
ok('the probe ticket opened', $iso['ok'] ?? false, true);
$svc->reply($manager, (int) $iso['ticket_id'], ['kind' => 'photo', 'media_path' => 'vehicle-tickets/x/p.jpg', 'media_mime' => 'image/jpeg']);
$svc->reply($rider, (int) $iso['ticket_id'], ['kind' => 'voice', 'media_path' => 'vehicle-tickets/x/v.m4a', 'media_mime' => 'audio/mp4', 'duration_ms' => 3000]);
$svc->close($manager, (int) $iso['ticket_id'], 'done');
foreach ($waTables as $t) {
    ok("no row was written to $t", DB::table($t)->count(), $waBefore[$t]);
}
// opening text + photo + voice + the close system line = 4
ok('every message of the thread is in the TICKET table',
   DB::table(VT::T_MESSAGE)->where('ticket_id', (int) $iso['ticket_id'])->count(), 4);
$ctlSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/VehicleTicketController.php')
        . file_get_contents(__DIR__ . '/app/Services/Riders/VehicleTicketService.php');
ok('the ticket code never calls WhatsAppService / Meta upload',
   (bool) preg_match('/WhatsAppService|uploadMediaToWhatsApp|ConversationModel|MessageModel/', $ctlSrc), false);
ok('ticket media is stored under vehicle-tickets/, not the WhatsApp media folder',
   (bool) preg_match('#vehicle-tickets/#', $ctlSrc) && !preg_match('#whatsapp-media#', $ctlSrc), true);

// ──────────────────────────────────────────────────────────────────────────────
head('§15 a rider holding TWO machines can still report a fault');
/**
 * ⚠⚠ FOUND ON A REAL PHONE (3-Sep). A rider driving the company van STILL OWNS his own
 *    bike, so `ownMachineIds` returns two and the service rightly declines to guess which
 *    one a fault is about. But the refusal read "No bike is assigned to you right now" —
 *    flatly untrue — and the app offered no way to choose, so he could report a fault on
 *    NEITHER machine. Two machines is an ordinary Tuesday here, not an edge case.
 *
 * ⭐ The contract now: name them in the refusal, ship `my_machines` so the app can ask,
 *    and accept the ticket the moment he picks one.
 */
$multi = null;
foreach (User::where('is_active', '1')->pluck('id') as $uid) {
    if (count($svc->myMachines((int) $uid)) > 1) { $multi = User::find((int) $uid); break; }
}
if ($multi) {
    $mine = $svc->myMachines((int) $multi->id);
    echo "  · multi-machine rider={$multi->id} holds " . count($mine) . "
";
    $blind = $svc->open($multi, ['title' => 'noise from the brake', 'category' => 'problem']);
    ok('opening WITHOUT choosing is refused', $blind['ok'], false);
    ok('  …and the refusal does NOT claim he has no bike',
       str_contains($blind['message'], 'No bike is assigned'), false);
    ok('  …it names every machine he holds', array_reduce($mine,
       fn ($c, $m) => $c && ($m['name'] === null || str_contains($blind['message'], $m['name'])), true), true);
    foreach ($mine as $m) {
        $pick = $svc->open($multi, ['vehicle_id' => $m['id'], 'title' => 'noise from the brake']);
        ok("  …and once he picks {$m['name']}, the ticket opens", $pick['ok'], true);
        ok('    …against the machine he actually chose',
           (int) $svc->find((int) $pick['ticket_id'])['vehicle_id'], (int) $m['id']);
    }
} else {
    echo "  · no rider currently holds two machines — asserting the contract instead
";
}
// True either way: the labelled list is what lets the app ask, so it must never be
// guesswork, and a manager gets none (he picks a RIDER; the registry answers the machine).
ok('myMachines labels what it returns', array_reduce($svc->myMachines((int) $rider->id),
   fn ($c, $m) => $c && array_key_exists('id', $m) && array_key_exists('name', $m), true), true);
$ctlSrc2 = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/VehicleTicketController.php');
ok('the list endpoint ships my_machines so the app can offer the choice',
   str_contains($ctlSrc2, "'my_machines'"), true);
$screen = __DIR__ . '/../NizamiFarmsMobile/src/screens/VehicleTicketsScreen.js';
if (is_file($screen)) {
    $js = file_get_contents($screen);
    ok('the app reads my_machines', str_contains($js, 'my_machines'), true);
    // ⚠ Verify the picker is MOUNTED, not merely that a variable exists — a Phase-1
    //   banner once shipped "patched" with only its import landing.
    ok('  …and actually MOUNTS a picker when there is a choice',
       str_contains($js, 'needsMachineChoice ? (') && str_contains($js, 'myMachines.map'), true);
    ok('  …and blocks submit until he has chosen',
       str_contains($js, 'needsMachineChoice && !nt?.vehicle_id'), true);
}

} finally {
    DB::rollBack();
    VehicleResolver::flush();
    RiderDayLegs::flush();
}

head('§14 rider-facing copy is Roman Urdu (owner ruling)');
/**
 * Owner ruling 2-Sep: every notification/banner a RIDER must act on reads in Roman Urdu /
 * very simple English. A banner he cannot read is a banner that does not work. Manager-facing
 * lines stay English on purpose. This pins the ones with a BUTTON behind them.
 */
$fb = file_get_contents(__DIR__ . '/app/Services/FirebaseService.php');
ok('the rider is told about a reply in Roman Urdu', str_contains($fb, 'Aap ke bike ka jawab aaya'), true);
ok('  …and about a closed ticket', str_contains($fb, 'Bike ka masla hal ho gaya'), true);
ok('  …and asked to confirm a workshop visit', str_contains($fb, 'Workshop jana hai — confirm karein'), true);
ok('  …and reminded the day before', str_contains($fb, 'Kal workshop jana hai'), true);
ok('  …and told when it is cancelled', str_contains($fb, 'Workshop cancel ho gaya'), true);
// ⚠ The MANAGER lines must NOT have been translated — that audience works in English.
ok('manager-facing push copy is still English',
   str_contains($fb, 'Workshop visit set') && str_contains($fb, 'Bike problem reported'), true);

/**
 * ⭐⭐ AND THE FURNITURE IS ENGLISH (owner ruling, 3-Sep) — the other half of the rule.
 *
 *    The first cut translated the whole screen and it read as clutter: statuses, the
 *    open/closed filter and the OK/Cancel buttons were all Roman Urdu. The owner's line
 *    is that Roman Urdu is for SENTENCES A RIDER MUST ACT ON, not for labels he reads
 *    past. Both directions are asserted here, because either one drifting is a bug.
 */
$screen = __DIR__ . '/../NizamiFarmsMobile/src/screens/VehicleTicketsScreen.js';
if (is_file($screen)) {
    $js = file_get_contents($screen);
    foreach (['Open', 'In progress', 'Workshop set', 'Closed'] as $lbl) {
        ok("status \"$lbl\" is English", str_contains($js, "'$lbl'"), true);
    }
    ok('the open/closed filter is English', str_contains($js, "'Open only' : 'Show closed'"), true);
    ok('the generic retry line is English', str_contains($js, "'Please try again.'"), true);
    ok('  …and no Roman-Urdu retry line survives', str_contains($js, 'Dobara koshish karein'), false);
    ok('closing (managers-only) is English end to end',
       str_contains($js, "'Close issue'") && str_contains($js, "'Cancel'"), true);
    // ⚠ The other direction: what he must ACT on stays Roman Urdu.
    ok('but the "what is broken?" prompt is still Roman Urdu',
       str_contains($js, 'Kya kharabi hai?'), true);
    ok('  …and so is the empty-state instruction',
       str_contains($js, 'Bike mein kuch kharabi ho to upar tap karein'), true);
    ok('  …and the bike picker asks in Roman Urdu', str_contains($js, 'Kaun si bike?'), true);
    /**
     * ⚠⚠ ONE SCREEN, TWO AUDIENCES (caught on Taimur's phone, 3-Sep). This screen is shared:
     *    a rider reports his own fault on it, a MANAGER works the whole fleet's queue on it.
     *    So the shared strings must FORK on `canManage` rather than pick a side — Taimur's
     *    manager queue was showing the rider's button, "Masla report karein".
     */
    ok('shared copy forks on canManage', str_contains($js, 'const say = (en, ur) => (canManage ? en : ur);'), true);
    ok('  …so a manager gets the English button',
       str_contains($js, "say('＋ Report a problem', '＋ Masla report karein')"), true);
    ok('  …and the category chips fork too', str_contains($js, 'say(c.en, c.t)'), true);
    ok('  …and every category carries both labels',
       substr_count($js, "en: '") >= 4, true);
}
$banner = __DIR__ . '/../NizamiFarmsMobile/src/components/WorkshopVisitBanner.js';
if (is_file($banner)) {
    $bj = file_get_contents($banner);
    ok('the workshop banner still instructs in Roman Urdu',
       str_contains($bj, 'workshop le kar jana hai') && str_contains($bj, 'Kal workshop jana hai'), true);
    ok('  …but its failure alert is English', str_contains($bj, "'Not confirmed'"), true);
}

head('§16 a manager with NOTHING open is still a manager');
/**
 * ⚠⚠ THE + OPERATOR DOES NOT OVERWRITE (found probing Qasim's own screens, 3-Sep).
 *    Both summaries early-return when there is nothing to show, and both built that
 *    answer as `$empty + ['can_manage' => ...]`. `$empty` already carries the key as
 *    false, and PHP's + keeps the LEFT operand — so the computed value was thrown away
 *    and every manager with an empty inbox was reported as not a manager.
 *    Latent (neither banner renders at count 0) but exactly the kind of thing the next
 *    consumer trusts. Asserted at the ZERO state, which is the only state that had it.
 */
DB::table(VT::T_TICKET)->whereIn('status', ['open', 'acknowledged', 'scheduled'])
    ->update(['status' => 'closed', 'closed_at' => now()]);
$zero = $svc->summaryFor($manager);
ok('the ticket summary is genuinely empty', (int) ($zero['count'] ?? -1), 0);
ok('  …and STILL reports him as a manager', $zero['can_manage'] ?? null, true);
ok('  …while a rider is still not one', $svc->summaryFor($rider)['can_manage'] ?? null, false);

head('§13 the web Roles screen can actually toggle the new keys');
/**
 * The Roles page enumerates ONLY RolePermissionController::getAvailablePermissions() —
 * a key seeded in t_sys_role_permissions but missing from that registry can never be
 * granted or revoked by anyone. Found in the Sep-2 review.
 */
$rpSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/SysAdmin/RolePermissionController.php');
ok('manage_vehicle_tickets is in the Roles registry', str_contains($rpSrc, "'manage_vehicle_tickets' =>"), true);
ok('schedule_workshop is in the Roles registry', str_contains($rpSrc, "'schedule_workshop' =>"), true);

head('§10 nothing left behind');
ok('ticket count back to where it started', DB::table(VT::T_TICKET)->count(), $before);
ok('message count back to where it started', DB::table(VT::T_MESSAGE)->count(), $beforeMsgs);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "✅" : "❌") . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
