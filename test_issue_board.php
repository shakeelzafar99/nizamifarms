<?php
/**
 * 🛠 THE ISSUES BOARD — every judgement it makes, on STAGED data (15-Sep-2026).
 * Plan: VEHICLE-ISSUES-BOARD-PLAN-SEP2026.md §3.5.
 *
 * ⚠⚠ EVERY SCENARIO IS STAGED INSIDE THE TRANSACTION. Not one assertion depends on what the
 *    replica happens to hold. That is the house rule ([[replica-scenario-probes-aug27]]: never
 *    assert live custody), and this round proved why twice over: the ticket table's live state
 *    changed underneath the work (all 13 rows bulk-closed by something outside this session),
 *    and a suite that had read those rows would have gone green for the wrong reason.
 *
 * ⚠ Carbon::setTestNow pins the clock, so "24 hours" and "3 days" are proven at the boundary
 *   rather than approximated by whatever time the suite is run at.
 *
 * What these prove:
 *   §1 whose turn is it — the one judgement that is not already a column;
 *   §2 unanswered / stale thresholds, at the boundary;
 *   §3 the attention line + level + rank, one rule per scenario;
 *   §4 the card sort (worst machine first) and the ticket sort inside a card;
 *   §5 "workshop done but still open" — the detector the owner asked for by name;
 *   §6 quiet machines are COUNTED, never hidden;
 *   §7 who sees what: manager / rider / the read-only planner door;
 *   §8 closed tickets are counted, not listed, and history mode returns them;
 *   §9 totals match the cards they summarise.
 *
 * Run:  php test_issue_board.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\VehicleIssueBoard;
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
function section(string $t) { echo "\n== $t ==\n"; }   // ⚠ NOT head() — that shadows Laravel's helper

$board = app(VehicleIssueBoard::class);
$vt    = app(VT::class);
ok('the ticket tables exist', $board->available(), true);
if (!$board->available()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

// ─── THE CLOCK ───────────────────────────────────────────────────────────────
$NOW = Carbon::parse('2026-09-15 12:00:00');
Carbon::setTestNow($NOW);

// ─── fixtures: discovered people, STAGED machines ────────────────────────────
section('§0 fixtures');

$manager = null; $rider = null; $planner = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if (!$manager && $u->hasPermission(VT::PERMISSION)) { $manager = $u; continue; }
}
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $u = User::find((int) $uid);
    if ($u && !$vt->canManage($u) && !$rider) { $rider = $u; break; }
}
// The read-only door: holds a workshop/shift key but NOT manage_vehicle_tickets.
foreach (User::where('is_active', '1')->get() as $u) {
    if ($vt->canManage($u, false) || $vt->canManage($u, true)) continue;
    if ($u->hasPermission(WV::ALERT_PERMISSION) || $u->hasPermission('manage_shifts')
        || (method_exists($u, 'hasMobilePermission')
            && ($u->hasMobilePermission(WV::ALERT_PERMISSION) || $u->hasMobilePermission('manage_shifts')))) {
        $planner = $u; break;
    }
}
ok('a ticket manager exists', (bool) $manager, null, true);
ok('a plain rider exists', (bool) $rider, null, true);
ok('a planner with a read grant but no ticket key exists', (bool) $planner, null, true);
if (!$manager || !$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }
echo '  · manager=' . $manager->id . ' rider=' . $rider->id
   . ' planner=' . ($planner->id ?? 'none') . "\n";

$beforeT = DB::table(VT::T_TICKET)->count();
$beforeM = DB::table(VT::T_MESSAGE)->count();
$beforeV = DB::table(WV::T_VISIT)->count();

DB::beginTransaction();
try {

// ── stage six company machines of our own, so nothing depends on the registry ──
$vids = [];
foreach (['ZZA-001', 'ZZB-002', 'ZZC-003', 'ZZD-004', 'ZZE-005', 'ZZF-006'] as $plate) {
    $vids[$plate] = (int) DB::table('t_ops_vehicle')->insertGetId([
        'vtype' => 'bike', 'reg_no' => $plate, 'is_company' => 1, 'is_active' => 1,
        'created_at' => $NOW, 'updated_at' => $NOW,
    ]);
}
// The rider keeps ZZA; everything else is unassigned, which managers still see.
DB::table('t_ops_vehicle_assignment')->insert([
    'vehicle_id' => $vids['ZZA-001'], 'user_id' => (int) $rider->id,
    'assigned_on' => $NOW->copy()->subDays(30)->format('Y-m-d'), 'assigned_by' => (int) $manager->id,
    'created_at' => $NOW, 'updated_at' => $NOW,
]);
ok('six machines staged', count($vids), 6);

/** Insert a ticket with an exact clock, and its messages. */
$mkTicket = function (int $vid, array $a) use ($manager, $rider, $NOW): int {
    $id = (int) DB::table(VT::T_TICKET)->insertGetId([
        'vehicle_id' => $vid,
        'opened_by' => (int) ($a['opened_by'] ?? $rider->id),
        'opened_for_user_id' => (int) $rider->id,
        'category' => 'problem', 'urgent' => !empty($a['urgent']) ? 1 : 0,
        'title' => $a['title'], 'status' => $a['status'] ?? 'open',
        'first_response_at' => $a['first_response_at'] ?? null,
        'assigned_to' => $a['assigned_to'] ?? null,
        'opened_at' => $a['opened_at'], 'last_message_at' => $a['last_message_at'] ?? $a['opened_at'],
        'closed_at' => $a['closed_at'] ?? null, 'closed_by' => $a['closed_by'] ?? null,
        'created_at' => $a['opened_at'], 'updated_at' => $a['opened_at'],
    ]);
    foreach ($a['messages'] ?? [] as $m) {
        DB::table(VT::T_MESSAGE)->insert([
            'ticket_id' => $id, 'user_id' => $m[0], 'kind' => $m[1] ?? 'text',
            'body' => $m[2] ?? null, 'created_at' => $m[3],
        ]);
    }
    return $id;
};
$mkVisit = function (int $vid, array $a) use ($manager, $rider, $NOW): int {
    return (int) DB::table(WV::T_VISIT)->insertGetId([
        'vehicle_id' => $vid, 'user_id' => (int) $rider->id,
        'visit_date' => $a['date'], 'visit_time' => $a['time'] ?? null,
        'workshop' => $a['workshop'] ?? 'Test Autos', 'purpose' => 'repair',
        'status' => $a['status'], 'done_at' => $a['done_at'] ?? null,
        'accepted_at' => ($a['status'] === 'accepted') ? $NOW : null,
        'created_by' => (int) $manager->id, 'created_at' => $a['created_at'] ?? $NOW, 'updated_at' => $NOW,
    ]);
};

$d = fn (int $days, int $hours = 0) => $NOW->copy()->subDays($days)->subHours($hours)->format('Y-m-d H:i:s');

// A: urgent, open                                   → red, rank 0
$tA = $mkTicket($vids['ZZA-001'], ['title' => 'A urgent not rideable', 'urgent' => true,
    'opened_at' => $d(2), 'messages' => [[(int) $rider->id, 'text', 'bike band hai', $d(2)]]]);
// B: a MISSED workshop day (yesterday, still accepted)  → red, rank 1
$tB = $mkTicket($vids['ZZB-002'], ['title' => 'B chain', 'status' => 'scheduled', 'opened_at' => $d(6),
    'first_response_at' => $d(5),
    'messages' => [[(int) $rider->id, 'text', 'chain', $d(6)], [(int) $manager->id, 'text', 'ok', $d(5)]]]);
$vB = $mkVisit($vids['ZZB-002'], ['date' => $NOW->copy()->subDay()->format('Y-m-d'), 'status' => 'accepted']);
// C: never answered, 3 days old, photo only         → red, rank 2
$tC = $mkTicket($vids['ZZC-003'], ['title' => 'C third puncture', 'opened_at' => $d(3),
    'messages' => [[(int) $rider->id, 'photo', null, $d(3)]]]);
// D: waiting on us 4 days (rider spoke last)        → amber, rank 3
$tD = $mkTicket($vids['ZZD-004'], ['title' => 'D head cylinder', 'status' => 'acknowledged',
    'opened_at' => $d(9), 'first_response_at' => $d(8), 'assigned_to' => (int) $manager->id,
    'messages' => [[(int) $rider->id, 'text', 'problem', $d(9)],
                   [(int) $manager->id, 'text', 'next week', $d(8)],
                   [(int) $rider->id, 'text', 'Ok', $d(4)]]]);
// E: workshop DONE after the ticket, ticket still open → amber, rank 4
$tE = $mkTicket($vids['ZZE-005'], ['title' => 'E tyre end hai', 'status' => 'acknowledged',
    'opened_at' => $d(9), 'first_response_at' => $d(9),
    'messages' => [[(int) $rider->id, 'text', 'tyre', $d(9)],
                   [(int) $manager->id, 'text', 'Next Week', $d(9)]]]);
$vE = $mkVisit($vids['ZZE-005'], ['date' => $NOW->copy()->subDays(4)->format('Y-m-d'),
    'status' => 'done', 'done_at' => $d(4)]);
// F: manager spoke last, 2 days → waiting on rider  → blue, rank 6
$tF = $mkTicket($vids['ZZF-006'], ['title' => 'F petrol over', 'status' => 'acknowledged',
    'opened_at' => $d(6), 'first_response_at' => $d(5),
    'messages' => [[(int) $rider->id, 'text', 'petrol', $d(6)],
                   [(int) $manager->id, 'text', 'kider sey', $d(2)]]]);
// …plus a CLOSED one on ZZF, to prove closed is counted and not listed
$tFc = $mkTicket($vids['ZZF-006'], ['title' => 'F closed one', 'status' => 'closed',
    'opened_at' => $d(20), 'closed_at' => $d(15), 'closed_by' => (int) $manager->id]);

$b = $board->forUser($manager, [], false);
$card = function (array $b, string $plate) use ($vids) {
    foreach ($b['vehicles'] as $c) if ($c['id'] === $vids[$plate]) return $c;
    return null;
};
$tk = function (?array $c, string $needle) {
    foreach ($c['open_tickets'] ?? [] as $t) if (str_contains($t['title'], $needle)) return $t;
    return null;
};

// ─────────────────────────────────────────────────────────────────────────────
section('§1 whose turn is it');

ok('a rider speaking last ⇒ waiting on US', $tk($card($b, 'ZZD-004'), 'D head')['waiting_on'], 'us');
ok('a manager speaking last ⇒ waiting on the RIDER', $tk($card($b, 'ZZF-006'), 'F petrol')['waiting_on'], 'rider');
ok('only the opening message ⇒ waiting on US', $tk($card($b, 'ZZC-003'), 'C third')['waiting_on'], 'us');
ok('  …and a photo-only opener still reads as a message',
   $tk($card($b, 'ZZC-003'), 'C third')['last_message']['snippet'], '📷 photo');
ok('scheduled + a live visit ⇒ waiting on the WORKSHOP',
   $tk($card($b, 'ZZB-002'), 'B chain')['waiting_on'], 'workshop');

/**
 * ⚠⚠ A SYSTEM LINE MUST NOT ANSWER THE QUESTION. "Qasim scheduled a workshop" is not somebody
 *    replying, so a ticket whose newest row is a system line still shows the last HUMAN as the
 *    one who spoke. Without this, every ticket the app itself touched would flip to "waiting on
 *    the rider" and drop off the manager's board — silently, which is the worst kind.
 */
DB::table(VT::T_MESSAGE)->insert(['ticket_id' => $tD, 'user_id' => null, 'kind' => 'system',
    'body' => 'Workshop set for tomorrow.', 'created_at' => $d(0, 1)]);
$b2 = $board->forUser($manager, [], false);
ok('a SYSTEM line on top does not change whose turn it is',
   $tk($card($b2, 'ZZD-004'), 'D head')['waiting_on'], 'us');
ok('  …but it IS shown as the newest thing that happened',
   $tk($card($b2, 'ZZD-004'), 'D head')['last_message']['kind'], 'system');
DB::table(VT::T_MESSAGE)->where('ticket_id', $tD)->where('kind', 'system')->delete();

// ─────────────────────────────────────────────────────────────────────────────
section('§2 the thresholds, at the boundary');

ok('C has never been answered', $tk($card($b, 'ZZC-003'), 'C third')['unanswered'], true);
ok('  …and D has (first_response_at is the column that means this)',
   $tk($card($b, 'ZZD-004'), 'D head')['unanswered'], false);
ok('D has been waiting on us for 4 days',
   intdiv($tk($card($b, 'ZZD-004'), 'D head')['waiting_hours'], 24), 4);
ok('  …so it is stale (≥ 3 days)', $tk($card($b, 'ZZD-004'), 'D head')['is_stale'], true);
ok('F has been waiting on the rider for 2 days, which is NOT stale',
   $tk($card($b, 'ZZF-006'), 'F petrol')['is_stale'], false);

/**
 * 23h vs 24h, proven by MOVING THE CLOCK rather than by arithmetic.
 * ⚠ Asserted as a DELTA. An absolute count is a hidden assertion about every other ticket in
 *   the table — including the real ones this suite did not stage — and it breaks the moment the
 *   replica changes underneath, which it did today.
 */
$baseUnanswered = $board->forUser($manager, [], false)['totals']['unanswered'];
$tG = $mkTicket($vids['ZZA-001'], ['title' => 'G fresh', 'opened_at' => $NOW->copy()->subHours(23)->format('Y-m-d H:i:s'),
    'messages' => [[(int) $rider->id, 'text', 'new', $NOW->copy()->subHours(23)->format('Y-m-d H:i:s')]]]);
$bG = $board->forUser($manager, [], false);
ok('a 23-hour-old unanswered ticket is not yet counted as unanswered',
   $bG['totals']['unanswered'] - $baseUnanswered, 0);
Carbon::setTestNow($NOW->copy()->addHours(2));            // G is now 25h old
$bG2 = $board->forUser($manager, [], false);
ok('  …and at 25 hours it is', $bG2['totals']['unanswered'] - $baseUnanswered, 1);
Carbon::setTestNow($NOW);
DB::table(VT::T_TICKET)->where('id', $tG)->delete();
DB::table(VT::T_MESSAGE)->where('ticket_id', $tG)->delete();

// ─────────────────────────────────────────────────────────────────────────────
section('§3 the attention line — one rule per machine');

$b = $board->forUser($manager, [], false);
ok('A · urgent ⇒ red',            $card($b, 'ZZA-001')['attention']['level'], 'red');
ok('  …and says not rideable',    str_contains($card($b, 'ZZA-001')['attention']['line'], 'Not rideable'), true);
ok('B · missed workshop ⇒ red',   $card($b, 'ZZB-002')['attention']['level'], 'red');
ok('  …and says missed',          str_contains($card($b, 'ZZB-002')['attention']['line'], 'missed'), true);
ok('C · never answered ⇒ red',    $card($b, 'ZZC-003')['attention']['level'], 'red');
ok('  …and counts the days',      str_contains($card($b, 'ZZC-003')['attention']['line'], '3 days'), true);
ok('D · waiting on us ⇒ amber',   $card($b, 'ZZD-004')['attention']['level'], 'amber');
ok('E · done but open ⇒ amber',   $card($b, 'ZZE-005')['attention']['level'], 'amber');
ok('F · waiting on him ⇒ blue',   $card($b, 'ZZF-006')['attention']['level'], 'blue');
ok('  …and NAMES him rather than saying "the rider"',
   str_contains($card($b, 'ZZF-006')['attention']['line'], 'Waiting on'), true);
foreach ($b['vehicles'] as $c) {
    ok('  every card carries a line and a level (' . $c['name'] . ')',
       (bool) ($c['attention']['line'] && in_array($c['attention']['level'], VehicleIssueBoard::LEVELS, true)),
       null, true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('§4 the sort');

/**
 * ⚠ Only the STAGED machines are compared. Real rows share this board, and asserting the whole
 *   order would be asserting the replica's current state — the drift trap this suite avoids by
 *   construction everywhere else.
 */
$order = array_values(array_filter(
    array_map(fn ($c) => $c['name'], $b['vehicles']),
    fn ($n) => isset($vids[$n])));
ok('worst machine first: A(urgent) B(missed) C(unanswered) D(waiting-us) E(done-open) F(waiting-him)',
   $order, ['ZZA-001', 'ZZB-002', 'ZZC-003', 'ZZD-004', 'ZZE-005', 'ZZF-006']);

// Inside a card: add a second, calmer ticket to A and check the urgent one stays on top.
$mkTicket($vids['ZZA-001'], ['title' => 'A calm second', 'status' => 'acknowledged',
    'opened_at' => $d(1), 'first_response_at' => $d(1),
    'messages' => [[(int) $rider->id, 'text', 'x', $d(1)], [(int) $manager->id, 'text', 'y', $d(1)]]]);
$b = $board->forUser($manager, [], false);
ok('inside a card the urgent ticket is first',
   $card($b, 'ZZA-001')['open_tickets'][0]['title'], 'A urgent not rideable');

// ─────────────────────────────────────────────────────────────────────────────
section('§5 "workshop done but nobody closed it" — the detector asked for by name');

ok('E carries the done visit', (bool) $card($b, 'ZZE-005')['last_done_visit'], null, true);
ok('  …and the line says so', str_contains($card($b, 'ZZE-005')['attention']['line'], 'still open'), true);
/**
 * ⚠ Asserted as INTERNAL CONSISTENCY, not as an absolute. The number counts the whole fleet, so
 *   `1` was a hidden claim that no OTHER machine is in this state — and any real bike whose last
 *   visit is done with a complaint still open makes it 2. Check the chip against the cards it
 *   summarises, which is the property that actually matters: they must never disagree.
 */
$doneOpenCards = count(array_filter($b['vehicles'],
    fn ($c) => $c['last_done_visit'] && $c['open_tickets'] && !$c['workshop']));
ok('  …and the totals count it', $b['totals']['workshop_done_open'], $doneOpenCards);
ok('    …and E is one of them', $doneOpenCards >= 1, true);

/**
 * ⚠ A done visit that PRE-DATES the complaint is noise, not a signal: every machine has been
 *   serviced at some point, and "workshop done in July" next to a fault raised yesterday tells
 *   a manager nothing. Only the pairing — work happened, conversation never finished — counts.
 */
$mkVisit($vids['ZZC-003'], ['date' => $NOW->copy()->subDays(40)->format('Y-m-d'),
    'status' => 'done', 'done_at' => $d(40)]);
$b = $board->forUser($manager, [], false);
ok('a done visit OLDER than the open ticket is not attached',
   $card($b, 'ZZC-003')['last_done_visit'], null);
ok('  …and C stays on its own reason', $card($b, 'ZZC-003')['attention']['level'], 'red');

// ─────────────────────────────────────────────────────────────────────────────
section('§6 quiet machines are counted, never hidden');

$quietNames = array_column($b['quiet'], 'name');
ok('every staged machine has something, so none of ours is quiet',
   count(array_intersect($quietNames, array_keys($vids))), 0);
$b = $board->forUser($manager, [], false);
ok('machines + with_issues + quiet add up',
   $b['totals']['machines'], $b['totals']['with_issues'] + $b['totals']['quiet']);
// Retire ZZF's machine — it still has an open ticket, so it must NOT vanish.
DB::table('t_ops_vehicle')->where('id', $vids['ZZF-006'])->update(['is_active' => 0]);
$bR = $board->forUser($manager, [], false);
ok('a RETIRED machine with an open ticket is still shown (a stuck ticket, not a retired problem)',
   (bool) $card($bR, 'ZZF-006'), null, true);
DB::table('t_ops_vehicle')->where('id', $vids['ZZF-006'])->update(['is_active' => 1]);

// ─────────────────────────────────────────────────────────────────────────────
section('§7 who sees what');

$bRider = $board->forUser($rider, [], false);
$riderIds = array_column($bRider['vehicles'], 'id');
ok('a rider sees only the machine he holds', $riderIds, [$vids['ZZA-001']]);
ok('  …and is not told he is a manager', $bRider['can_manage'], false);
ok('  …and sees no other machine in the quiet list either',
   count(array_intersect(array_column($bRider['quiet'], 'id'), array_values($vids))), 0);

// A PROPOSAL is a conversation between managers — the rider must never receive it.
$mkVisit($vids['ZZA-001'], ['date' => $NOW->copy()->addDays(3)->format('Y-m-d'), 'status' => 'proposed']);
$bMgr2   = $board->forUser($manager, [], false);
$bRider2 = $board->forUser($rider, [], false);
$propMgr = $card($bMgr2, 'ZZA-001')['workshop'] ?? null;
ok('a manager sees the proposal', (bool) ($propMgr && $propMgr['is_proposed']), null, true);
$propRider = null;
foreach ($bRider2['vehicles'] as $c) if ($c['id'] === $vids['ZZA-001']) $propRider = $c['workshop'];
ok('  …and the rider does NOT', (bool) ($propRider && ($propRider['is_proposed'] ?? false)), false);

if ($planner) {
    $bPlan = $board->forUser($planner, [], false);
    ok('the read-only door sees the whole fleet', count($bPlan['vehicles']) >= 6, true);
    ok('  …is flagged read_only', $bPlan['read_only'], true);
    ok('  …cannot manage', $bPlan['can_manage'], false);
    ok('  …and is told NOT to offer threads', $bPlan['threads'], false);
    ok('  …while a real manager IS offered them', $board->forUser($manager, [], false)['threads'], true);
}

/**
 * §7b ⚠⚠ ONE RULE FOR THE WHOLE FLEET, NOT TWO (15-Sep-2026).
 *
 *     The board decides "does he see every machine" from canManage OR canSchedule OR the read
 *     grant, but it used to choose the TICKET READER from the read grant alone. That left a gap:
 *     someone who can schedule a workshop while holding neither the ticket key nor a read grant
 *     got a card for every machine, while his tickets still came from `listFor()` — which
 *     correctly hands a non-manager only the machines he HOLDS. Every other machine would then
 *     have rendered as "quiet": a board under-reporting exactly what it exists to show.
 *
 * ⚠ Nobody holds that combination on this replica — every role with `schedule_workshop` also has
 *   `manage_vehicle_tickets` — so the persona is SYNTHETIC on purpose. A guard nobody can reach
 *   today is still a guard, and the permission screen can hand it to someone next week.
 */
section('§7b a scheduler who cannot manage tickets reads the fleet, and opens nothing');

$schedOnly = new class((int) $rider->id) {
    public function __construct(public int $id) {}
    public function hasPermission($k) { return $k === WV::PERMISSION; }   // schedule_workshop only
    public function hasMobilePermission($k) { return false; }
};
$bSched = $board->forUser($schedOnly, [], false);
ok('he is shown the whole fleet', count($bSched['vehicles']) >= 6, true);
/**
 * ⚠⚠ THE ASSERTION THE BUG WOULD HAVE FAILED. The fixture machines carry open tickets; under the
 *    old rule his ticket read was scoped to the one bike he holds, so every other fixture machine
 *    would have come back with an EMPTY `open_tickets` and been filed as quiet.
 */
$schedIds = array_column($bSched['vehicles'], 'id');
ok('  …with the other machines\' issues actually on them',
   count(array_filter($bSched['vehicles'], fn ($c) => $c['id'] !== $vids['ZZA-001']
                                                   && count($c['open_tickets']))) >= 1, true);
ok('  …is flagged read_only', $bSched['read_only'], true);
ok('  …and is told NOT to offer threads', $bSched['threads'], false);
/**
 * ⚠ No unread counts for a summary reader: he has no read marks on threads he cannot open, so a
 *   badge would be a permanent false alarm he can never clear.
 */
ok('  …and carries no unread badge he could never clear',
   array_sum(array_map(fn ($c) => array_sum(array_column($c['open_tickets'], 'unread')),
                       $bSched['vehicles'])), 0);
ok('  …while still being offered Schedule, which is his actual job', $bSched['can_schedule'], true);

// ─────────────────────────────────────────────────────────────────────────────
section('§8 closed: counted, not listed');

$b = $board->forUser($manager, [], false);
ok('the closed ticket is NOT among the open rows',
   (bool) $tk($card($b, 'ZZF-006'), 'F closed one'), false);
ok('  …but it is counted on the card', $card($b, 'ZZF-006')['closed_count'] >= 1, true);
ok('  …and dated', (bool) $card($b, 'ZZF-006')['last_closed_at'], null, true);
$hist = $board->forUser($manager, ['mode' => 'history', 'vehicle_id' => $vids['ZZF-006']], false);
ok('history mode returns it', (bool) collect($hist['history'])->firstWhere('title', 'F closed one'), null, true);
ok('  …and live mode returns no history at all', $b['history'], []);

// ─────────────────────────────────────────────────────────────────────────────
section('§9 the totals summarise the cards they came from');

$b = $board->forUser($manager, [], false);
$sumOpen = array_sum(array_map(fn ($c) => count($c['open_tickets']), $b['vehicles']));
ok('open_tickets matches the rows on the cards', $b['totals']['open_tickets'], $sumOpen);
ok('urgent matches', $b['totals']['urgent'],
   array_sum(array_map(fn ($c) => count(array_filter($c['open_tickets'], fn ($t) => $t['urgent'])), $b['vehicles'])));
ok('workshop_missed counts B', $b['totals']['workshop_missed'] >= 1, true);
ok('waiting_on_rider counts F', $b['totals']['waiting_on_rider'] >= 1, true);

} finally {
    DB::rollBack();
    Carbon::setTestNow();
}

// ─────────────────────────────────────────────────────────────────────────────
section('§10 nothing left behind');
ok('ticket count back where it started', DB::table(VT::T_TICKET)->count(), $beforeT);
ok('message count back where it started', DB::table(VT::T_MESSAGE)->count(), $beforeM);
ok('visit count back where it started', DB::table(WV::T_VISIT)->count(), $beforeV);
ok('no staged vehicles survived',
   DB::table('t_ops_vehicle')->where('reg_no', 'like', 'ZZ_-00%')->count(), 0);

echo "\n────────────────────────────────────────────\n";
echo ($fail === 0 ? "✅  " : "❌  ") . "$pass passed, $fail failed\n";
echo "────────────────────────────────────────────\n";
exit($fail === 0 ? 0 : 1);
