<?php
/**
 * SHIFT AUTHORITY — who may change whose shift, on real data (Sep-06 2026).
 * Plan + owner rulings: SHIFT-AUTHORITY-PLAN-SEP2026.md.  SQL: shift_authority_sep2026.sql.
 *
 * What these prove:
 *   §1  the ladder: down is allowed, up and across are refused, self is refused;
 *   §2  the top of the ladder — sets his own, is not bound by an allowed-shift list;
 *   §3  the own-shift switch, both positions;
 *   §4  allowed shift types bind the person, not the actor;
 *   §5  approval: WHO is asked is derived from the ladder, never stored;
 *   §6  a queued change writes NOTHING to the shift tables until it is approved;
 *   §7  approve replays through the real engine; decline and withdraw do not;
 *   §8  the lapse sweep kills a request whose day has passed;
 *   §9  cancel and end are gated too (the revert back door);
 *   §10 shift TYPES: propose, and the template-edit back door;
 *   §11 the open web door is shut — a non-planner is refused outright.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ ONE user is authenticated per process — so this script never logs anyone in and
 *   passes the user objects explicitly instead.
 *
 * Run:  php test_shift_authority.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Ops\ShiftAuthorityService as A;
use App\Services\Ops\ShiftChangeRequestService as R;
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

/** A fresh service each time — the rule cache is per-instance and we keep changing rules. */
function svc(): A { $s = new A(); return $s; }

$auth = svc();
ok('the authority table exists (run shift_authority_sep2026.sql first)', $auth->available(), true);
if (!$auth->available()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

// ─── fixtures: DISCOVERED from the ladder, never hard-coded ──────────────────
head('§0 fixtures');

$rows = DB::table(A::T_AUTHORITY)->orderByDesc('rank')->get();
ok('the ladder has at least three rungs', $rows->count() >= 3, true);
$top    = User::find((int) $rows[0]->user_id);
$middle = User::find((int) $rows[1]->user_id);
$bottom = User::find((int) $rows[2]->user_id);
ok('all three are real users', $top && $middle && $bottom, null, true);
if (!$top || !$middle || !$bottom) { echo "\nfixtures missing — stopping.\n"; exit(1); }
echo "  · top={$top->id} {$top->fullname} · middle={$middle->id} {$middle->fullname} · bottom={$bottom->id} {$bottom->fullname}\n";

// Somebody NOT on the ladder at all — a rider, the everyday case.
$rider = null;
foreach (DB::table('t_ops_rider_profile')->where('active', 1)->pluck('user_id') as $uid) {
    if (DB::table(A::T_AUTHORITY)->where('user_id', $uid)->exists()) continue;
    $u = User::find((int) $uid);
    if ($u && (int) $u->is_active === 1) { $rider = $u; break; }
}
ok('a rider who is NOT on the ladder exists', (bool) $rider, null, true);
if (!$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }

// Someone with no shift permission at all — the open-web-door case.
$outsider = null;
foreach (User::where('is_active', 1)->get() as $u) {
    if (!$u->hasPermission(A::PERMISSION) && !$u->hasMobilePermission(A::PERMISSION)) { $outsider = $u; break; }
}
ok('a non-planner exists (to prove the old open web door is shut)', (bool) $outsider, null, true);

$tplIds = DB::table('t_ops_shift_template')->where('active', 1)->orderBy('id')->pluck('id')
    ->map(fn ($i) => (int) $i)->all();
ok('at least two active shift types exist', count($tplIds) >= 2, true);
$tplA = $tplIds[0]; $tplB = $tplIds[1];
echo "  · rider={$rider->id} {$rider->fullname} · templates A={$tplA} B={$tplB}\n";

$assignBefore = DB::table('t_ops_user_shift_assignment')->count();

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
head('§1 the ladder — down yes, up no, across no, self no');

// ⚠ "Down the ladder" is about REACH, not about landing immediately: the bottom rung is
//   flagged needs_approval, so the correct verdict here is APPROVAL, not ALLOW. That the
//   two are different is the whole feature — §5 pins down which one and who is asked.
$d = svc()->decide($middle, (int) $bottom->id, null, 'assign');
ok('middle may reach bottom (down the ladder) — not refused', $d['verdict'] !== A::DENY, true);

$d = svc()->decide($bottom, (int) $middle->id, null, 'assign');
ok('bottom may NOT change middle (up the ladder)', $d['verdict'], A::DENY);
ok('…and is told exactly who can', str_contains((string) $d['message'], $top->fullname), true);

$d = svc()->decide($bottom, (int) $top->id, null, 'assign');
ok('bottom may NOT change the top', $d['verdict'], A::DENY);

$d = svc()->decide($middle, (int) $middle->id, null, 'assign');
ok('middle may NOT set his own shift (switch is off)', $d['verdict'], A::DENY);
ok('…and the refusal names who to ask',
   str_contains((string) $d['message'], "can't set your own shift") && str_contains((string) $d['message'], $top->fullname), true);

$d = svc()->decide($bottom, (int) $rider->id, null, 'assign');
ok('every planner may change a rider (rank 0) — the daily work is untouched', $d['verdict'], A::ALLOW);

// Two people on the SAME rung cannot change each other.
DB::table(A::T_AUTHORITY)->where('user_id', $bottom->id)->update(['rank' => (int) $rows[1]->rank]);
$d = svc()->decide($bottom, (int) $middle->id, null, 'assign');
ok('equal rank cannot change equal rank', $d['verdict'], A::DENY);
DB::table(A::T_AUTHORITY)->where('user_id', $bottom->id)->update(['rank' => (int) $rows[2]->rank]);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 the top of the ladder (owner rulings Q2, Q3)');

$d = svc()->decide($top, (int) $top->id, null, 'assign');
ok('the top may set his OWN shift with the switch off (Q3)', $d['verdict'], A::ALLOW);

$d = svc()->decide($top, (int) $middle->id, null, 'assign');
ok('the top may change anyone, with no approval above him', $d['verdict'], A::ALLOW);

// Bind the rider to template A only, then try to put him on B.
DB::table(A::T_AUTHORITY)->insert([
    'user_id' => $rider->id, 'rank' => 0, 'needs_approval' => 0,
    'allowed_template_ids' => json_encode([$tplA]), 'updated_at' => now(),
]);
$d = svc()->decide($top, (int) $rider->id, $tplB, 'assign');
ok('the top is NOT bound by an allowed-shift list he wrote (Q2)', $d['verdict'], A::ALLOW);

// ─────────────────────────────────────────────────────────────────────────────
head('§4 allowed shift types bind the PERSON, not the actor');

$d = svc()->decide($middle, (int) $rider->id, $tplB, 'assign');
ok('a planner cannot put a restricted person on a shift outside his list', $d['verdict'], A::DENY);
ok('…and the refusal names the shifts that ARE allowed',
   str_contains((string) $d['message'], (string) DB::table('t_ops_shift_template')->where('id', $tplA)->value('shift_name')), true);

$d = svc()->decide($middle, (int) $rider->id, $tplA, 'assign');
ok('…and the allowed one goes through', $d['verdict'], A::ALLOW);

// An EMPTY list must never mean "no shifts at all".
DB::table(A::T_AUTHORITY)->where('user_id', $rider->id)->update(['allowed_template_ids' => json_encode([])]);
$d = svc()->decide($middle, (int) $rider->id, $tplB, 'assign');
ok('an empty allowed-list is read as "all shifts", never as "none"', $d['verdict'], A::ALLOW);
DB::table(A::T_AUTHORITY)->where('user_id', $rider->id)->delete();

// ─────────────────────────────────────────────────────────────────────────────
head('§3 the own-shift switch');

DB::table('t_fin_config')->where('config_key', 'SHIFT_SELF_ASSIGN')->update(['config_value' => 'Y']);
$d = svc()->decide($middle, (int) $middle->id, null, 'assign');
ok('switch ON: middle may set his own shift…', $d['verdict'], A::APPROVAL);
ok('…but it still waits for the top, because his row says so', $d['approvers'], [(int) $top->id]);

DB::table('t_fin_config')->where('config_key', 'SHIFT_SELF_ASSIGN')->update(['config_value' => 'N']);
$d = svc()->decide($middle, (int) $middle->id, null, 'assign');
ok('switch OFF again: refused', $d['verdict'], A::DENY);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 approval — who is asked comes from the ladder, not from a stored list');

$d = svc()->decide($middle, (int) $bottom->id, $tplA, 'assign');
ok('middle changing bottom needs approval (bottom is flagged)', $d['verdict'], A::APPROVAL);
ok('…and the ONLY approver is the top (above BOTH of them)', $d['approvers'], [(int) $top->id]);

$d = svc()->decide($top, (int) $bottom->id, $tplA, 'assign');
ok('the top changing bottom applies at once — nobody is above him', $d['verdict'], A::ALLOW);

// A rank-0 planner asking for a change to the bottom rung: now middle CAN answer too,
// which is the point of "above both" rather than "the top, always".
$approvers = svc()->approversFor((int) $bottom->id, (int) $rider->id);
ok('a rank-0 requester widens the approvers to middle AND top',
   count($approvers) === 2 && in_array((int) $middle->id, $approvers, true), true);

// ─────────────────────────────────────────────────────────────────────────────
head('§6 a queued change writes NOTHING to the shift tables');

$req = new R(svc());
$before = DB::table('t_ops_user_shift_assignment')->where('user_id', $bottom->id)->count();
$q = $req->queue([
    'user_id' => (int) $bottom->id, 'shift_template_id' => $tplA,
    'mode' => 'until_changed', 'effective_from' => now()->addDay()->format('Y-m-d'),
], $middle, [(int) $top->id], 'web');
ok('the request is stored', $q['ok'], true);
ok('no assignment row was written',
   DB::table('t_ops_user_shift_assignment')->where('user_id', $bottom->id)->count(), $before);
ok('the message says the person has NOT been told',
   str_contains($q['message'], 'has not been told'), true);

$pending = $req->pendingFor($top);
ok('the top sees it in his queue', count($pending) >= 1, true);
ok('the bottom rung does NOT see it in his own queue', count($req->pendingFor($bottom)), 0);

// Raising the same thing twice must not stack two cards in front of the approver.
$q2 = $req->queue([
    'user_id' => (int) $bottom->id, 'shift_template_id' => $tplB,
    'mode' => 'until_changed', 'effective_from' => now()->addDay()->format('Y-m-d'),
], $middle, [(int) $top->id], 'web');
ok('asking again supersedes the first request instead of stacking',
   count($req->pendingFor($top)), count($pending));

// ─────────────────────────────────────────────────────────────────────────────
head('§7 approving replays through the real engine');

// ⚠ The engine stamps created_by / updated_by / the audit actor from the SESSION
//   (auth()->id()), which in production is always the approver pressing the button.
//   Mirror that here, or the replay writes actor 0 and proves nothing about who acted.
\Illuminate\Support\Facades\Auth::setUser($top);

$res = $req->approve($top, (int) $q2['id']);
ok('the top can approve', $res['ok'], true);
ok('an assignment row now exists',
   DB::table('t_ops_user_shift_assignment')->where('user_id', $bottom->id)->count() > $before, true);
ok('the request is closed as approved',
   DB::table(R::T)->where('id', $q2['id'])->value('status'), 'approved');
ok('the audit log records it, by the APPROVER',
   (int) DB::table('t_ops_shift_assignment_log')->where('user_id', $bottom->id)
        ->orderByDesc('id')->value('actor_user_id'), (int) $top->id);

// Someone who is not above both cannot answer.
$q3 = $req->queue([
    'user_id' => (int) $bottom->id, 'shift_template_id' => $tplA,
    'mode' => 'one_day', 'effective_from' => now()->addDays(2)->format('Y-m-d'),
], $middle, [(int) $top->id], 'web');
$bad = $req->approve($middle, (int) $q3['id']);
ok('the person who ASKED cannot approve his own request', $bad['ok'], false);
$wd = $req->withdraw($middle, (int) $q3['id']);
ok('…but he can withdraw it', $wd['ok'], true);
ok('withdrawn requests leave the queue', count($req->pendingFor($top)), 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§8 the lapse sweep (owner ruling Q5)');

$qOld = $req->queue([
    'user_id' => (int) $bottom->id, 'shift_template_id' => $tplA,
    'mode' => 'one_day', 'effective_from' => now()->subDays(2)->format('Y-m-d'),
], $middle, [(int) $top->id], 'web');
$n = $req->lapseDue();
ok('a request whose day has passed is swept', $n >= 1, true);
ok('…and is marked lapsed', DB::table(R::T)->where('id', $qOld['id'])->value('status'), 'lapsed');

$qOld2 = DB::table(R::T)->insertGetId([
    'user_id' => (int) $bottom->id, 'shift_template_id' => $tplA, 'mode' => 'one_day',
    'effective_from' => now()->subDay()->format('Y-m-d'), 'effective_to' => now()->subDay()->format('Y-m-d'),
    'requested_by' => (int) $middle->id, 'requested_at' => now(), 'source' => 'web',
    'status' => 'proposed', 'created_at' => now(),
]);
$late = $req->approve($top, (int) $qOld2);
ok('approving one whose day has passed is refused, not back-dated', $late['ok'], false);
ok('…and it is dropped rather than left hanging',
   DB::table(R::T)->where('id', $qOld2)->value('status'), 'lapsed');

// ─────────────────────────────────────────────────────────────────────────────
head('§9 the revert back door — cancel and end are gated too');

$d = svc()->decide($middle, (int) $bottom->id, null, 'cancel');
ok('cancelling a change on a person who needs approval is REFUSED, never queued', $d['verdict'], A::DENY);
ok('…and names who to ask', str_contains((string) $d['message'], $top->fullname), true);

$d = svc()->decide($middle, (int) $rider->id, null, 'cancel');
ok('cancelling on an ordinary rider is fine (the daily work)', $d['verdict'], A::ALLOW);

$d = svc()->decide($bottom, (int) $middle->id, null, 'end');
ok('ending an assignment up the ladder is refused', $d['verdict'], A::DENY);

// ─────────────────────────────────────────────────────────────────────────────
head('§10 shift TYPES — proposals and the template-edit back door');

$st = svc()->templateCreateState($middle);
ok('under the default policy a planner may PROPOSE a type', $st['can'], true);
ok('…and it lands as a proposal, not a shift', $st['proposed'], true);

$st = svc()->templateCreateState($top);
ok('the top creates one outright', $st['can'] && !$st['proposed'], true);

$e = svc()->canEditTemplate($middle, $tplA);
ok('⚠ a planner may NOT edit an existing type (changing its hours moves everyone on it)', $e['can'], false);
$e = svc()->canEditTemplate($top, $tplA);
ok('the top may', $e['can'], true);

// A proposer may still tidy up his own still-waiting proposal.
DB::table('t_ops_shift_template')->where('id', $tplB)
  ->update(['approval_status' => 'proposed', 'proposed_by' => (int) $middle->id]);
$e = svc()->canEditTemplate($middle, $tplB);
ok('…but he may edit a proposal HE raised while it waits', $e['can'], true);

$d = svc()->decide($top, (int) $rider->id, $tplB, 'assign');
ok('⚠ nobody — not even the top — may assign a type that is still waiting', $d['verdict'], A::DENY);
DB::table('t_ops_shift_template')->where('id', $tplB)
  ->update(['approval_status' => 'approved', 'proposed_by' => null]);

DB::table('t_fin_config')->where('config_key', 'SHIFT_TYPE_CREATE_POLICY')->update(['config_value' => 'top_only']);
$st = svc()->templateCreateState($middle);
ok('policy top_only: a planner cannot even propose', $st['can'], false);
DB::table('t_fin_config')->where('config_key', 'SHIFT_TYPE_CREATE_POLICY')->update(['config_value' => 'approval']);

// ─────────────────────────────────────────────────────────────────────────────
head('§11 the door that used to be open');

if ($outsider) {
    $d = svc()->decide($outsider, (int) $rider->id, $tplA, 'assign');
    ok('a logged-in non-planner is refused on the WEB side too (was wide open before)', $d['verdict'], A::DENY);
    ok('…with the permission message, not a ladder message',
       str_contains((string) $d['message'], 'do not have permission'), true);
}
$d = svc()->decide(null, (int) $rider->id, $tplA, 'assign');
ok('a null user is refused', $d['verdict'], A::DENY);

// ─────────────────────────────────────────────────────────────────────────────
head('§12 the screens ask the same question the gate answers');

$row = svc()->rowStateFor($bottom, (int) $middle->id);
ok('a locked row reports can=false…', $row['can'], false);
ok('…with a reason to show in the tooltip (owner: locked rows are SHOWN, not hidden)',
   (bool) $row['reason'], true);

$row = svc()->rowStateFor($middle, (int) $bottom->id);
ok('a row that will need approval reports can=true + needs_approval=true',
   $row['can'] && $row['needs_approval'], true);

DB::table(A::T_AUTHORITY)->insert([
    'user_id' => $rider->id, 'rank' => 0, 'needs_approval' => 0,
    'allowed_template_ids' => json_encode([$tplA]), 'updated_at' => now(),
]);
ok('allowedTemplateIdsFor filters for an ordinary planner',
   svc()->allowedTemplateIdsFor($middle, (int) $rider->id), [$tplA]);
ok('…and returns "all" for the top', svc()->allowedTemplateIdsFor($top, (int) $rider->id), null);

} finally {
    DB::rollBack();
}

// ─────────────────────────────────────────────────────────────────────────────
head('§13 nothing leaked out of the transaction');
ok('assignment rows unchanged', DB::table('t_ops_user_shift_assignment')->count(), $assignBefore);
ok('no request rows left behind', DB::table(R::T)->count(), 0);
ok('the ladder is back to three rungs', DB::table(A::T_AUTHORITY)->count(), 3);
ok('the switches are back', DB::table('t_fin_config')->where('config_key', 'SHIFT_SELF_ASSIGN')->value('config_value'), 'N');

echo "\n────────────────────────────────\n";
echo ($fail === 0 ? "ALL GOOD" : "FAILURES") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
