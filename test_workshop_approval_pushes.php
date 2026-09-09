<?php
/**
 * WHO IS TOLD WHAT, at each step of the workshop approval flow (6-Sep-2026).
 *
 * ⭐⭐ THIS IS THE HIGHEST-RISK CLAIM IN THE WHOLE ROUND. Everything else about the flow can
 *    be right and the feature is still defeated if one push tells the RIDER about a day no
 *    planner has approved. So the recipients are asserted, not reasoned about.
 *
 * ⚠⚠ RUN WITH THE FIREBASE CREDENTIALS MOVED ASIDE. These are live events on a live team's
 *    tokens. With the credentials gone, FirebaseService logs exactly who it WOULD have told
 *    ("Skipping user push", "Skipping push notification") and sends nothing — which is what
 *    this reads. The script refuses to run if the credentials are in place.
 *
 * Run:  php test_workshop_approval_pushes.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\FirebaseService;
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

// ── the safety gate ──────────────────────────────────────────────────────────
$creds = base_path(config('whatsapp.firebase_credentials_path', 'firebase-service-account.json'));
if ($creds && file_exists($creds)) {
    echo "\n⛔ REFUSING TO RUN: the Firebase credentials are in place at\n   $creds\n"
       . "   These pushes go to the real team. Move the file aside first:\n"
       . "     mv <that file> <that file>.testaside\n   …and put it back afterwards.\n";
    exit(2);
}
echo "✓ Firebase credentials are absent — nothing can leave this machine.\n";

/**
 * Read back the recipients FirebaseService logged, from the tail of laravel.log.
 * ⚠ The offset is taken BEFORE the call and the file re-read AFTER, because reading
 *   `filesize()` immediately can miss the last lines — the 5-Sep round produced a false
 *   positive exactly that way (a "push to 95" that was the PREVIOUS call's line).
 */
$logPath = storage_path('logs/laravel.log');
function capture(callable $fn): array {
    global $logPath;
    $before = file_exists($logPath) ? filesize($logPath) : 0;
    $fn();
    clearstatcache(true, $logPath);
    usleep(200000);
    $fh = fopen($logPath, 'rb');
    fseek($fh, $before);
    $tail = stream_get_contents($fh);
    fclose($fh);

    $users = [];
    foreach (explode("\n", $tail) as $line) {
        if (str_contains($line, 'Skipping user push') && preg_match('/"user_id":(\d+)/', $line, $m)) {
            $users[] = (int) $m[1];
        }
    }
    $groups = [];
    foreach (explode("\n", $tail) as $line) {
        if (str_contains($line, 'Skipping push notification')
            && preg_match('/"permissions":\[([^\]]*)\]/', $line, $m)) {
            foreach (explode(',', $m[1]) as $p) $groups[] = trim($p, ' "');
        }
    }
    return ['users' => array_values(array_unique($users)), 'groups' => array_values(array_unique($groups))];
}

$wv = new WV();
$fb = app(FirebaseService::class);
ok('the approval columns exist', $wv->approvalEnabled(), true);
if (!$wv->approvalEnabled()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

head('§0 fixtures');
$booker = null; $planner = null; $rider = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    $s = (bool) $u->hasPermission(WV::PERMISSION);
    $p = (bool) $u->hasPermission(WV::APPROVE_PERMISSION);
    if (!$booker && $s && !$p) $booker = $u;
    if (!$planner && $p)       $planner = $u;
}
$res = new VehicleResolver();
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    if ($res->currentVehicleFor((int) $uid)) { $rider = User::find((int) $uid); break; }
}
ok('a booker, a planner and a rider were found', (bool) ($booker && $planner && $rider), null, true);
if (!$booker || !$planner || !$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }
$vid = (int) $res->currentVehicleFor((int) $rider->id);
echo "  · booker={$booker->id} planner={$planner->id} rider={$rider->id} vehicle={$vid}\n";

$beforeVisits = DB::table(WV::T_VISIT)->count();
DB::beginTransaction();
try {

$ws = (int) DB::table('t_ops_company_locations')->insertGetId([
    'location_name' => 'TEST Push Workshop', 'latitude' => 33.6867, 'longitude' => 73.0331,
    'radius_meters' => 300, 'is_primary' => 0, 'is_workshop' => 1, 'is_active' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);
$soon = \Carbon\Carbon::today()->addDays(3)->format('Y-m-d');
$mk = fn () => (int) $wv->schedule($booker, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                             'visit_date' => $soon, 'location_id' => $ws])['visit_id'];

// ═══════════════════════════════════════════════════════════════════════════════
head('§1 BOOKED (proposed) — the planners hear, the rider does NOT');

$v1 = $mk();
$c = capture(fn () => $fb->notifyWorkshopVisit('proposed', $v1, (int) $booker->id));
ok('⭐⭐ the RIDER is told NOTHING', in_array((int) $rider->id, $c['users'], true), false);
ok('  …in fact no individual is pushed at all', $c['users'], []);
ok('  …the SHIFT PLANNERS are the audience', $c['groups'], [WV::APPROVE_PERMISSION]);
ok('  …and not the fleet alert group, which would include the wrong people',
   in_array(WV::ALERT_PERMISSION, $c['groups'], true), false);

// ═══════════════════════════════════════════════════════════════════════════════
head('§2 APPROVED — now, and only now, the rider is told');

$wv->approve($planner, $v1);
$c = capture(fn () => $fb->notifyWorkshopVisit('approved', $v1, (int) $planner->id));
ok('⭐ the RIDER is told', in_array((int) $rider->id, $c['users'], true), true);
ok('  …and so is the person who asked for it', in_array((int) $booker->id, $c['users'], true), true);
ok('  …the planner who just pressed it is not told about his own action',
   in_array((int) $planner->id, $c['users'], true), false);
ok('  …and no group is woken for a decision that is already made', $c['groups'], []);

// ═══════════════════════════════════════════════════════════════════════════════
head('§3 DECLINED — only the person who asked');

$v2 = $mk();
$wv->decline($planner, $v2, 'He is on the Faizabad run.');
$c = capture(fn () => $fb->notifyWorkshopVisit('declined', $v2, (int) $planner->id));
ok('the booker is told', in_array((int) $booker->id, $c['users'], true), true);
ok('⭐⭐ the RIDER is not — he never knew there was a question',
   in_array((int) $rider->id, $c['users'], true), false);
ok('  …and nobody else is woken', count($c['users']), 1);

// ═══════════════════════════════════════════════════════════════════════════════
head('§4 the 17:00 nudge and the morning auto-decline');

$v3 = $mk();
$c = capture(fn () => $fb->notifyWorkshopVisit('approval_reminder', $v3, 0));
ok('the nudge goes to the planners', $c['groups'], [WV::APPROVE_PERMISSION]);
ok('  …and never to the rider', in_array((int) $rider->id, $c['users'], true), false);

DB::table(WV::T_VISIT)->where('id', $v3)->update([
    'status' => 'declined', 'decline_reason' => 'Not approved in time — nobody approved it before the day.',
]);
$c = capture(fn () => $fb->notifyWorkshopVisit('auto_declined', $v3, 0));
ok('the auto-decline tells the booker', in_array((int) $booker->id, $c['users'], true), true);
ok('⭐⭐ …and NOT the rider — silence must never send him anywhere, or tell him it did',
   in_array((int) $rider->id, $c['users'], true), false);

// ═══════════════════════════════════════════════════════════════════════════════
head('§5 assigned directly by a planner — today’s behaviour, unchanged');

$v4 = (int) $wv->schedule($planner, ['vehicle_id' => $vid, 'user_id' => $rider->id,
                                     'visit_date' => $soon, 'location_id' => $ws])['visit_id'];
ok('a planner’s own booking is assigned, not proposed',
   (string) DB::table(WV::T_VISIT)->where('id', $v4)->value('status'), 'scheduled');
$c = capture(fn () => $fb->notifyWorkshopVisit('scheduled', $v4, (int) $planner->id));
ok('the rider is told straight away', in_array((int) $rider->id, $c['users'], true), true);
ok('  …and the fleet alert group too, exactly as before', $c['groups'], [WV::ALERT_PERMISSION]);

// ═══════════════════════════════════════════════════════════════════════════════
head('§6 DONE — the loop closes out loud (nobody used to be told)');

DB::table(WV::T_VISIT)->where('id', $v4)->update(['status' => 'done', 'done_at' => now()]);
$c = capture(fn () => $fb->notifyWorkshopVisit('done', $v4, (int) $booker->id));
ok('the rider hears that it is recorded', in_array((int) $rider->id, $c['users'], true), true);
ok('  …and whoever pressed it is not told about his own action',
   in_array((int) $booker->id, $c['users'], true), false);

} finally {
    DB::rollBack();
}

head('§7 nothing left behind');
ok('visit count back to where it started', DB::table(WV::T_VISIT)->count(), $beforeVisits);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "✅" : "❌") . "  $pass passed, $fail failed\n";
echo "⚠ REMEMBER to put the Firebase credentials file back.\n";
exit($fail === 0 ? 0 : 1);
