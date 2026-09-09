<?php
/**
 * SHIFT AUTHORITY over the REAL HTTP endpoints (Sep-06 2026).
 *
 * The service tests (test_shift_authority.php) prove the rules. This proves the WIRING:
 * routes, session auth, the controller gate, the JSON shapes the screens actually read,
 * and the two different permission gates on the one controller.
 *
 * ⚠ LOCAL ONLY. Drives http://127.0.0.1:8931 with forged local sessions
 *   ([[local-login-allowed-for-verification]]). Never point this at production.
 * ⚠ It WRITES (that is the point) and cleans up after itself in §9.
 *
 * Run:  php artisan serve --port=8931     (in another shell)
 *       php test_shift_authority_http.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const BASE = 'http://127.0.0.1:8931';

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

/** Forge a web session for a user and return [cookie, csrf]. */
function session_for(int $userId): array {
    $cookie = trim(shell_exec('php ' . escapeshellarg(__DIR__ . '/forge_session.php') . ' ' . $userId));
    $cookie = trim(substr($cookie, strrpos($cookie, "\n") ?: 0));
    // The CSRF token the blade prints is the session's own _token.
    $html = http('GET', '/shift-planner', null, $cookie)['body'];
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
    return [$cookie, $m[1] ?? ''];
}

function http(string $method, string $path, ?array $body, string $cookie, string $csrf = ''): array {
    $ch = curl_init(BASE . $path);
    $headers = ['Cookie: ' . $cookie, 'Accept: application/json'];
    if ($csrf) $headers[] = 'X-CSRF-TOKEN: ' . $csrf;
    if ($body !== null) { $headers[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $out, 'json' => json_decode((string) $out, true)];
}

// ─────────────────────────────────────────────────────────────────────────────
head('§0 the server is up and the ladder is seeded');

$probe = http('GET', '/shift-planner', null, '');
ok('the local server answers', $probe['code'] > 0, true);
if ($probe['code'] === 0) { echo "\nStart it first:  php artisan serve --port=8931\n"; exit(1); }

$rows = DB::table('t_ops_shift_authority')->orderByDesc('rank')->get();
if ($rows->count() < 3) { echo "\nLadder not seeded — run shift_authority_sep2026.sql.\n"; exit(1); }
$TOP = (int) $rows[0]->user_id; $MID = (int) $rows[1]->user_id; $BOT = (int) $rows[2]->user_id;
$name = fn ($id) => DB::table('t_sys_user')->where('id', $id)->value('fullname');
echo "  · top={$TOP} " . $name($TOP) . " · middle={$MID} " . $name($MID) . " · bottom={$BOT} " . $name($BOT) . "\n";

[$cTop, $tTop] = session_for($TOP);
[$cMid, $tMid] = session_for($MID);
[$cBot, $tBot] = session_for($BOT);
ok('all three sessions carry a CSRF token', $tTop && $tMid && $tBot, true);

$tpl = (int) DB::table('t_ops_shift_template')->where('active', 1)
    ->when(true, fn ($q) => $q)->orderBy('id')->value('id');
$tomorrow = now()->addDay()->format('Y-m-d');
$created = [];

// ─────────────────────────────────────────────────────────────────────────────
head('§1 the Shift rules page is Taimur-only (owner ruling 6-Sep)');

ok('the top of the ladder opens it', http('GET', '/shift-rules', null, $cTop)['code'], 200);
ok('⚠ the middle rung (who does the uploads) is refused', http('GET', '/shift-rules', null, $cMid)['code'], 403);
ok('the bottom rung is refused', http('GET', '/shift-rules', null, $cBot)['code'], 403);
ok('…and so is the data behind it', http('GET', '/shift-rules/data', null, $cMid)['code'], 403);
ok('…and every write on it', http('POST', '/shift-rules/ladder', ['user_ids' => []], $cMid, $tMid)['code'], 403);

$d = http('GET', '/shift-rules/data', null, $cTop)['json'];
ok('the page gets its ladder', count($d['ladder'] ?? []) >= 3, true);
ok('…the staff list for "add a person"', count($d['staff'] ?? []) > 0, true);
ok('…and the switch, off by default', $d['self_assign'], false);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 the approvals endpoint is open to every planner (ladder-gated, not page-gated)');

ok('the middle rung may poll it', http('GET', '/shift-rules/approvals', null, $cMid)['code'], 200);
ok('the bottom rung may poll it', http('GET', '/shift-rules/approvals', null, $cBot)['code'], 200);
ok('…and gets an empty queue', count(http('GET', '/shift-rules/approvals', null, $cBot)['json']['pending'] ?? []), 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§3 the planner grid tells the screen what it may do');

$w = http('GET', '/shift-planner/week?filter=all', null, $cBot)['json'];
$rowFor = function ($data, $uid) { foreach ($data['riders'] ?? [] as $r) if ((int) $r['user_id'] === $uid) return $r; return null; };
$rTop = $rowFor($w, $TOP);
ok('the bottom rung SEES the top of the ladder in the grid…', (bool) $rTop, true);
ok('…locked, not hidden (owner ruling)', $rTop['can_change'] ?? null, false);
ok('…with a reason for the padlock tooltip', (bool) ($rTop['lock_reason'] ?? null), true);
ok('the bottom rung cannot open his own row either', $rowFor($w, $BOT)['can_change'] ?? null, false);
ok('⚙ and he is not told the rules page exists', $w['can_manage_rules'] ?? null, false);

$wTop = http('GET', '/shift-planner/week?filter=all', null, $cTop)['json'];
ok('the top may change his own row (Q3)', $rowFor($wTop, $TOP)['can_change'] ?? null, true);
ok('⚙ and he gets the Shift rules button', $wTop['can_manage_rules'] ?? null, true);

$wMid = http('GET', '/shift-planner/week?filter=all', null, $cMid)['json'];
ok('the middle rung may reach the bottom rung…', $rowFor($wMid, $BOT)['can_change'] ?? null, true);
ok('…and is told it will need approval', $rowFor($wMid, $BOT)['needs_approval'] ?? null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§4 the writes — refused, queued, applied');

$r = http('POST', '/shifts/assign', [
    'user_id' => $MID, 'shift_template_id' => $tpl, 'mode' => 'until_changed', 'effective_from' => $tomorrow,
], $cMid, $tMid);
ok('⚠ setting your OWN shift is refused over HTTP', $r['code'], 403);
ok('…with the message the popup shows', str_contains((string) ($r['json']['message'] ?? ''), "can't set your own shift"), true);

$r = http('POST', '/shifts/assign', [
    'user_id' => $MID, 'shift_template_id' => $tpl, 'mode' => 'until_changed', 'effective_from' => $tomorrow,
], $cBot, $tBot);
ok('reaching UP the ladder is refused', $r['code'], 403);

$before = DB::table('t_ops_user_shift_assignment')->where('user_id', $BOT)->count();
$r = http('POST', '/shifts/assign', [
    'user_id' => $BOT, 'shift_template_id' => $tpl, 'mode' => 'until_changed', 'effective_from' => $tomorrow,
], $cMid, $tMid);
ok('changing the rung below is accepted…', $r['code'], 200);
ok('…as PENDING, not as done', $r['json']['pending'] ?? null, true);
ok('…and the message says who was asked', str_contains((string) ($r['json']['message'] ?? ''), $name($TOP)), true);
ok('⚠⚠ NOTHING was written to the shift tables',
   DB::table('t_ops_user_shift_assignment')->where('user_id', $BOT)->count(), $before);
$reqId = (int) ($r['json']['request_id'] ?? 0);
$created[] = $reqId;
ok('a request id came back', $reqId > 0, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 it lands in the right person\'s queue');

$q = http('GET', '/shift-rules/approvals', null, $cTop)['json'];
$mine = array_values(array_filter($q['pending'] ?? [], fn ($p) => (int) $p['id'] === $reqId));
ok('the top of the ladder sees the card', count($mine), 1);
ok('…naming the person, the shift and who asked',
   ($mine[0]['person'] ?? '') === $name($BOT) && ($mine[0]['asked_by'] ?? '') === $name($MID), true);
ok('the bottom rung (whose shift it is) does NOT see it',
   count(array_filter(http('GET', '/shift-rules/approvals', null, $cBot)['json']['pending'] ?? [],
                      fn ($p) => (int) $p['id'] === $reqId)), 0);
ok('the person who asked sees it under "mine", to withdraw',
   count(array_filter(http('GET', '/shift-rules/approvals', null, $cMid)['json']['mine'] ?? [],
                      fn ($p) => (int) $p['id'] === $reqId)), 1);

$r = http('POST', '/shift-rules/requests/' . $reqId . '/approve', [], $cMid, $tMid);
ok('the person who asked cannot approve it himself', $r['code'], 422);
$r = http('POST', '/shift-rules/requests/' . $reqId . '/approve', [], $cBot, $tBot);
ok('nor can the person it is about', $r['code'], 422);

// ─────────────────────────────────────────────────────────────────────────────
head('§6 approving applies it for real');

$r = http('POST', '/shift-rules/requests/' . $reqId . '/approve', [], $cTop, $tTop);
ok('the top approves', $r['json']['success'] ?? null, true);
ok('an assignment row now exists',
   DB::table('t_ops_user_shift_assignment')->where('user_id', $BOT)->count() > $before, true);
ok('the request is closed', DB::table('t_ops_shift_change_request')->where('id', $reqId)->value('status'), 'approved');
ok('the card has left the queue',
   count(array_filter(http('GET', '/shift-rules/approvals', null, $cTop)['json']['pending'] ?? [],
                      fn ($p) => (int) $p['id'] === $reqId)), 0);
ok('the audit log names the APPROVER, not the asker',
   (int) DB::table('t_ops_shift_assignment_log')->where('user_id', $BOT)->orderByDesc('id')->value('actor_user_id'), $TOP);

// ─────────────────────────────────────────────────────────────────────────────
head('§7 shift types');

$code = 'zz_http_test_' . substr(md5((string) microtime(true)), 0, 6);
$r = http('POST', '/shifts', [
    'shift_name' => 'HTTP test shift', 'shift_code' => $code,
    'shift_start' => '17:00', 'working_days' => [1, 2, 3, 4, 5],
], $cMid, $tMid);
ok('a planner may propose one', $r['json']['success'] ?? null, true);
ok('…and it is a PROPOSAL, not a shift', $r['json']['pending'] ?? null, true);
$newTplId = (int) ($r['json']['data']['id'] ?? 0);
ok('it is stored as proposed',
   DB::table('t_ops_shift_template')->where('id', $newTplId)->value('approval_status'), 'proposed');

$r = http('POST', '/shifts/assign', [
    'user_id' => $BOT, 'shift_template_id' => $newTplId, 'mode' => 'one_day', 'effective_from' => $tomorrow,
], $cTop, $tTop);
ok('⚠ nobody can be put on it while it waits — not even the top', $r['code'], 403);

$w2 = http('GET', '/shift-planner/week?filter=all', null, $cMid)['json'];
ok('…and it is not offered in the planner picker',
   count(array_filter($w2['templates'] ?? [], fn ($t) => (int) $t['id'] === $newTplId)), 0);

$r = http('PUT', '/shifts/' . $tpl, [
    'shift_name' => 'hijacked', 'shift_code' => 'hijack_' . $code,
    'shift_start' => '23:00', 'working_days' => [1],
], $cMid, $tMid);
ok('⚠⚠ the back door is shut — a planner cannot re-time an existing type', $r['code'], 403);

$r = http('POST', '/shift-rules/types/' . $newTplId . '/approve', [], $cMid, $tMid);
ok('a planner cannot approve his own type', $r['code'], 403);
$r = http('POST', '/shift-rules/types/' . $newTplId . '/approve', [], $cTop, $tTop);
ok('the top approves it', $r['json']['success'] ?? null, true);
ok('…and it becomes assignable',
   DB::table('t_ops_shift_template')->where('id', $newTplId)->value('approval_status'), 'approved');

// ─────────────────────────────────────────────────────────────────────────────
head('§8 the reusable popup asks the same question');

$s = http('GET', '/shifts/user-summary?user_id=' . $TOP, null, $cMid)['json'];
ok('the attendance popup is told the row is locked', $s['can_change'] ?? null, false);
$s = http('GET', '/shifts/user-summary?user_id=' . $BOT, null, $cMid)['json'];
ok('…and that this one needs approval', $s['needs_approval'] ?? null, true);

$l = http('GET', '/shifts/list?for_user_id=' . $BOT, null, $cMid)['json'];
ok('the type list still answers for a picker', $l['success'] ?? null, true);
ok('…and never offers a waiting type as assignable',
   count(array_filter($l['data'] ?? [], fn ($t) => !empty($t['pending']) && empty($t['approval_status']) === false
        && $t['approval_status'] !== 'proposed')), 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§8a changing a shift the rider has ALREADY been told about');

/**
 * ⭐⭐ Owner ruling 7-Sep: overwriting a change the rider was told about must not be silent.
 *    The first attempt is refused as a QUESTION naming what would be undone; only a client
 *    that then sends `confirm_replace` gets through.
 * ⚠ Narrow on purpose: only a TEMPORARY override or an UPCOMING primary counts. Ordinary
 *   day-to-day assigning must not nag, and §4 above proves it still does not.
 */
// A plain rider — not on the ladder, so no approval queue muddies this.
$rider = (int) DB::table('t_ops_rider_profile as p')
    ->join('t_sys_user as u', 'u.id', '=', 'p.user_id')
    ->where('p.active', 1)->where('u.is_active', 1)
    ->whereNotIn('p.user_id', DB::table('t_ops_shift_authority')->pluck('user_id'))
    ->orderBy('p.user_id')->value('p.user_id');
$tpl2 = (int) DB::table('t_ops_shift_template')->where('active', 1)
    ->where('id', '<>', $tpl)->orderBy('id')->value('id');
ok('a plain rider and a second shift type exist', $rider > 0 && $tpl2 > 0, true);

$d1 = now()->addDays(3)->format('Y-m-d');
$r = http('POST', '/shifts/assign', [
    'user_id' => $rider, 'shift_template_id' => $tpl, 'mode' => 'one_day', 'effective_from' => $d1,
], $cTop, $tTop);
ok('a first one-day change goes through with no nagging', $r['json']['success'] ?? null, true);

// Pretend the rider was told and confirmed it — which is what the engine does for real.
DB::table('t_ops_user_shift_assignment')->where('user_id', $rider)
  ->whereDate('effective_from', $d1)->whereNotNull('effective_to')
  ->update(['notified_at' => now(), 'acknowledged_at' => now()]);

$r = http('POST', '/shifts/assign', [
    'user_id' => $rider, 'shift_template_id' => $tpl2, 'mode' => 'one_day', 'effective_from' => $d1,
], $cTop, $tTop);
ok('changing that same day is REFUSED first', $r['code'], 409);
ok('…as a question, not an error', $r['json']['needs_confirmation'] ?? null, true);
ok('…naming what he was told', str_contains((string) ($r['json']['message'] ?? ''), 'already been told'), true);
ok('…saying he confirmed it', str_contains((string) ($r['json']['message'] ?? ''), 'he has confirmed it'), true);
ok('…and telling the manager, in Roman Urdu, that he must be told again',
   str_contains((string) ($r['json']['message'] ?? ''), 'dobara batana parega'), true);

$r = http('POST', '/shifts/assign', [
    'user_id' => $rider, 'shift_template_id' => $tpl2, 'mode' => 'one_day',
    'effective_from' => $d1, 'confirm_replace' => 1,
], $cTop, $tTop);
ok('confirming lets it through', $r['json']['success'] ?? null, true);

DB::table('t_ops_user_shift_assignment')->where('user_id', $rider)->whereDate('effective_from', $d1)->delete();
DB::table('t_ops_shift_assignment_log')->where('user_id', $rider)
  ->where('created_at', '>=', now()->subMinutes(5))->delete();

head('§8b ONE ENGINE — the legacy shift door is gone for good');

/**
 * ⚰ `POST /riders/shift` wrote raw `t_ops_rider_profile.shift_start/end` with NO permission
 *    check of any kind. Retired 7-Sep (owner ruling: one endpoint, one engine). This pins it
 *    shut: if anyone ever re-adds the route, this fails loudly.
 * ⚠ 405, not 404 — `GET /riders/{id}` still matches the path for GET. What matters is that
 *   no POST handler exists, so nothing can write through it.
 */
$legacy = http('POST', '/riders/shift', ['user_id' => $BOT, 'shift_start' => '09:00', 'shift_end' => '17:00'], $cTop, $tTop);
ok('the legacy door refuses to write', in_array($legacy['code'], [404, 405], true), true);
ok('…and the real engine is still the way in', http('POST', '/shifts/assign', [], $cTop, $tTop)['code'], 422);
// ⚠ Done in PHP, not by shelling out to grep/wc — those are not on the PATH of the shell
//   PHP spawns here, and the failure was silently counting as a pass.
$callers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/resources'));
foreach ($it as $f) {
    if (!$f->isFile() || !str_ends_with($f->getFilename(), '.php')) continue;
    $body = file_get_contents($f->getPathname());
    // A real call, not the tombstone comments that explain the retirement.
    if (preg_match('#(fetch|url|action|post)\s*\(?\s*[\x27"]/riders/shift#', $body)) {
        $callers[] = $f->getPathname();
    }
}
ok('…and nothing in the views still calls it', $callers, []);

head('§9 cleanup — this script wrote to the replica, so it puts it back');

DB::table('t_ops_shift_change_request')->whereIn('id', array_filter($created))->delete();
DB::table('t_ops_shift_assignment_log')->where('user_id', $BOT)
  ->where('created_at', '>=', now()->subMinutes(10))->delete();
DB::table('t_ops_user_shift_assignment')->where('user_id', $BOT)
  ->where('effective_from', $tomorrow)->delete();
if ($newTplId) DB::table('t_ops_shift_template')->where('id', $newTplId)->delete();
ok('test requests removed', DB::table('t_ops_shift_change_request')->whereIn('id', array_filter($created))->count(), 0);
ok('test template removed', DB::table('t_ops_shift_template')->where('shift_code', $code)->count(), 0);
ok('the ladder is untouched', DB::table('t_ops_shift_authority')->count(), 3);

echo "\n────────────────────────────────\n";
echo ($fail === 0 ? "ALL GOOD" : "FAILURES") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
