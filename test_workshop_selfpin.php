<?php
/**
 * 📍🔧 THE WORKSHOP PINS ITSELF (11-Sep-2026).
 *
 * The owner's ask: a workshop typed as free text has no pin, so the geofence can never say he
 * arrived and he reads "going to the workshop" all day. Rather than ban free text, ASK him once
 * when he has clearly stopped somewhere we cannot name — and let "yes" both stamp the arrival
 * and PIN the place, so the next visit there is automatic.
 *
 * ⚠⚠ THE HALF THAT MATTERS MOST IS THE ANTI-NAG DESIGN. The owner asked, in his own words, to
 *    "make sure this pop up doesn't stay, doesn't get buggy". So the gates get as much proof as
 *    the happy path:
 *      §2 a man RIDING PAST is never asked
 *      §3 a rubbish GPS fix never asks, and never becomes a pin
 *      §4 a place we already know (office / his home / a pinned workshop) is never asked about
 *      §5 "no" is honoured — 45 minutes AND 300 metres before he can be asked again
 *      §6 he is asked at most TWICE, ever
 *      §7 the prompt is a SERVER FACT: it vanishes by itself the moment it stops applying
 *      §8 "yes" twice does not double-stamp or create two workshops
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ NOT `head()` — Laravel ships a global `head()` the validator calls.
 *
 * Run:  php test_workshop_selfpin.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\WorkshopVisitService as WV;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else {
        $fail++; echo "  ✗ $what\n";
        if (!$raw) {
            echo "      got:  " . var_export($got, true) . "\n";
            echo "      want: " . var_export($want, true) . "\n";
        }
    }
}
function section(string $t) { echo "\n== $t ==\n"; }

// ⚠ Pushes off — see test_workshop_trip.php for why (each one blocks ~45s).
config(['whatsapp.firebase_credentials_path' => 'storage/__no_such_credentials_for_tests__.json']);

$wv   = new WV();
$res  = new VehicleResolver();
$vsvc = new VehicleService();

ok('the trip migration has run here', $wv->tripEnabled(), true);
$hasAsk = \Illuminate\Support\Facades\Schema::hasColumn(WV::T_VISIT, 'arrival_ask_at');
ok('the Sep-12 arrival columns exist here', $hasAsk, true);
if (!$hasAsk) { echo "\nPENDING-PROD-SEP12-2026-WORKSHOP-R2.sql has not run — stopping.\n"; exit(1); }

// ── fixtures ──────────────────────────────────────────────────────────
$manager = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($u->hasPermission(WV::PERMISSION)) { $manager = $u; break; }
}
$rider = null; $vid = null;
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
    if (!$v || !$vsvc->isTrackedId($v)) continue;
    $u = User::find((int) $uid);
    if ($u) { $rider = $u; $vid = $v; break; }
}
ok('a manager and a rider on a company machine exist', (bool) ($manager && $rider), null, true);
if (!$manager || !$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }
$RID = (int) $rider->id;
$today = \Carbon\Carbon::today()->format('Y-m-d');

// A spot far from anything the system knows, so the "already explained" gate cannot fire.
$LAT = 33.4101234; $LNG = 72.9301234;

/** Book a FREE-TEXT workshop (the whole point: no location_id, so nothing to geofence). */
$bookFreeText = function (string $name = 'Gali No 4 Motors') use ($wv, $manager, $vid, $RID, $today) {
    $r = $wv->schedule($manager, [
        'vehicle_id' => $vid, 'user_id' => $RID, 'visit_date' => $today,
        'visit_time' => '11:00', 'workshop' => $name, 'confirm_replace' => 1,
    ]);
    if (empty($r['ok'])) echo "      ⚠ booking refused: " . ($r['message'] ?? '?') . "\n";
    return $r;
};

/** Put N credible fixes in the past, all at one spot — i.e. he has been standing there. */
$dwell = function (float $lat, float $lng, int $minsAgoOldest = 14) use ($RID) {
    DB::table('t_ops_rider_location')->where('user_id', $RID)
        ->where('captured_at', '>=', now()->subMinutes(40))->delete();
    foreach ([$minsAgoOldest, 7, 2] as $m) {
        DB::table('t_ops_rider_location')->insert([
            'user_id' => $RID, 'latitude' => $lat, 'longitude' => $lng,
            'accuracy' => 12, 'captured_at' => now()->subMinutes($m),
            'source' => 'test', 'created_at' => now(),
        ]);
    }
};

$askedAt = fn (int $id) => DB::table(WV::T_VISIT)->where('id', $id)->value('arrival_ask_at');

// ─────────────────────────────────────────────────
section('§1 ⭐ a free-text workshop asks him, and "yes" pins it');

DB::beginTransaction();
try {
    $r = $bookFreeText(); $id = (int) $r['visit_id'];
    ok('a free-text visit is booked with NO pin',
       (bool) DB::table(WV::T_VISIT)->where('id', $id)->value('location_id'), false);
    $wv->depart($rider, $id);

    // The geofence genuinely cannot help here — that is the bug being fixed.
    ok('⚠ the geofence cannot stamp it', $wv->stampArrival($RID, $LAT, $LNG), false);

    $dwell($LAT, $LNG);
    ok('⭐ …so he is ASKED once', $wv->maybeAskArrival($RID, $LAT, $LNG, 12), true);

    $p = $wv->arrivalPromptFor($RID);
    ok('  …and the phone is given the question', (bool) $p, null, true);
    ok('  …naming the place he was sent to', str_contains((string) ($p['title'] ?? ''), 'Gali No 4 Motors'), true);
    ok('  …in Roman Urdu, as the riders read', str_contains((string) ($p['title'] ?? ''), 'pahunch gaye'), true);

    // …and "Haan" does BOTH jobs at once.
    $out = $wv->confirmArrivalHere($rider, $id, $LAT, $LNG);
    ok('⭐⭐ "Haan" is accepted', $out['ok'] ?? false, true);
    ok('  …the arrival is stamped', (bool) DB::table(WV::T_VISIT)->where('id', $id)->value('arrived_at'), true);
    ok('  …recorded as the RIDER\'s answer, not a geofence',
       DB::table(WV::T_VISIT)->where('id', $id)->value('arrived_source'), 'rider_confirmed');
    ok('  ⭐⭐ …and the workshop is now PINNED', (bool) ($out['location_id'] ?? null), null, true);

    $loc = DB::table('t_ops_company_locations')->where('id', $out['location_id'])
        ->first(['latitude', 'longitude', 'is_workshop']);
    ok('  …at his own position', round((float) $loc->latitude, 4), round($LAT, 4));
    ok('  …and ticked as a workshop', (int) $loc->is_workshop, 1);

    ok('  …the visit now points at it',
       (int) DB::table(WV::T_VISIT)->where('id', $id)->value('location_id'), (int) $out['location_id']);
    ok('  ⭐ …so the trip finally reads "at the workshop"', $wv->tripFor($RID)['state'], WV::TRIP_AT);
    ok('  ⚠ …and the question is GONE', $wv->arrivalPromptFor($RID), null);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§2 ⚠ a man riding PAST is never asked');

DB::beginTransaction();
try {
    $r = $bookFreeText(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);

    // Fixes strung out along a road — he is moving, not stopped.
    DB::table('t_ops_rider_location')->where('user_id', $RID)
        ->where('captured_at', '>=', now()->subMinutes(40))->delete();
    foreach ([[0.000, 14], [0.004, 7], [0.009, 2]] as [$off, $m]) {
        DB::table('t_ops_rider_location')->insert([
            'user_id' => $RID, 'latitude' => $LAT + $off, 'longitude' => $LNG,
            'accuracy' => 10, 'captured_at' => now()->subMinutes($m),
            'source' => 'test', 'created_at' => now(),
        ]);
    }
    ok('⭐ moving ⇒ NOT asked', $wv->maybeAskArrival($RID, $LAT + 0.009, $LNG, 10), false);
    ok('  …and nothing was written', $askedAt($id), null);

    // A single fix is not a stop either — one reading cannot tell standing from passing.
    DB::table('t_ops_rider_location')->where('user_id', $RID)
        ->where('captured_at', '>=', now()->subMinutes(40))->delete();
    DB::table('t_ops_rider_location')->insert([
        'user_id' => $RID, 'latitude' => $LAT, 'longitude' => $LNG, 'accuracy' => 8,
        'captured_at' => now()->subMinutes(2), 'source' => 'test', 'created_at' => now(),
    ]);
    ok('⚠ one lone fix is not a stop', $wv->maybeAskArrival($RID, $LAT, $LNG, 8), false);

    // Stopped, but only for three minutes — not yet a dwell.
    DB::table('t_ops_rider_location')->where('user_id', $RID)
        ->where('captured_at', '>=', now()->subMinutes(40))->delete();
    foreach ([3, 1] as $m) {
        DB::table('t_ops_rider_location')->insert([
            'user_id' => $RID, 'latitude' => $LAT, 'longitude' => $LNG, 'accuracy' => 8,
            'captured_at' => now()->subMinutes($m), 'source' => 'test', 'created_at' => now(),
        ]);
    }
    ok('⚠ a three-minute pause is not a stop either', $wv->maybeAskArrival($RID, $LAT, $LNG, 8), false);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§3 ⚠⚠ a rubbish GPS fix never asks — and never becomes a pin');

DB::beginTransaction();
try {
    $r = $bookFreeText(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);
    $dwell($LAT, $LNG);

    ok('⭐ a 2 km fix is refused outright', $wv->maybeAskArrival($RID, $LAT, $LNG, 2000), false);
    ok('  …nothing written', $askedAt($id), null);
    ok('  ⚠ …and a null accuracy is still allowed (many phones report none)',
       $wv->maybeAskArrival($RID, $LAT, $LNG, null), true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§4 ⚠ a place we can already name is never asked about');

DB::beginTransaction();
try {
    $r = $bookFreeText(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);

    $office = DB::table('t_ops_company_locations')->where('is_active', 1)
        ->whereNotNull('latitude')->whereNotNull('longitude')->first(['latitude', 'longitude']);
    if ($office) {
        $dwell((float) $office->latitude, (float) $office->longitude);
        ok('⭐ standing at a KNOWN location ⇒ not asked',
           $wv->maybeAskArrival($RID, (float) $office->latitude, (float) $office->longitude, 10), false);
    } else {
        ok('a pinned company location exists (none — skipped honestly)', true, true, true);
    }

    // …and his own home is not a workshop either.
    $home = DB::table('t_ops_rider_profile')->where('user_id', $RID)
        ->first(['home_latitude', 'home_longitude']);
    if ($home && $home->home_latitude !== null) {
        $dwell((float) $home->home_latitude, (float) $home->home_longitude);
        ok('⭐ standing at HIS OWN HOME ⇒ not asked',
           $wv->maybeAskArrival($RID, (float) $home->home_latitude, (float) $home->home_longitude, 10), false);
    } else {
        ok('this rider has a home pin (none — skipped honestly)', true, true, true);
    }
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§5 ⚠⚠ "Nahi" is honoured — 45 minutes AND 300 metres');

DB::beginTransaction();
try {
    $r = $bookFreeText(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);
    $dwell($LAT, $LNG);
    ok('he is asked', $wv->maybeAskArrival($RID, $LAT, $LNG, 10), true);

    $wv->declineArrivalHere($rider, $id);
    ok('⭐ straight after "Nahi" he is NOT asked again', $wv->maybeAskArrival($RID, $LAT, $LNG, 10), false);

    // Time passes, but he has not moved — still no.
    DB::table(WV::T_VISIT)->where('id', $id)->update(['arrival_ask_at' => now()->subMinutes(90)]);
    ok('  ⚠ 90 minutes later, SAME SPOT ⇒ still not asked (time alone is not enough)',
       $wv->maybeAskArrival($RID, $LAT, $LNG, 10), false);

    // He moves, but only minutes have passed — still no.
    DB::table(WV::T_VISIT)->where('id', $id)->update([
        'arrival_ask_at' => now()->subMinutes(5),
        'arrival_ask_lat' => $LAT, 'arrival_ask_lng' => $LNG,
    ]);
    $far = $LAT + 0.01; // ~1.1 km
    $dwell($far, $LNG);
    ok('  ⚠ moved far but only 5 minutes ago ⇒ still not asked (distance alone is not enough)',
       $wv->maybeAskArrival($RID, $far, $LNG, 10), false);

    // Both conditions — now it may ask again.
    DB::table(WV::T_VISIT)->where('id', $id)->update([
        'arrival_ask_at' => now()->subMinutes(60),
        'arrival_ask_lat' => $LAT, 'arrival_ask_lng' => $LNG,
    ]);
    ok('  ⭐ …only 60 minutes AND 1 km later is he asked again',
       $wv->maybeAskArrival($RID, $far, $LNG, 10), true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§6 ⚠⚠ asked at most TWICE, ever');

DB::beginTransaction();
try {
    $r = $bookFreeText(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);
    $dwell($LAT, $LNG);

    ok('ask 1', $wv->maybeAskArrival($RID, $LAT, $LNG, 10), true);
    DB::table(WV::T_VISIT)->where('id', $id)->update([
        'arrival_ask_at' => now()->subMinutes(60), 'arrival_ask_lat' => $LAT + 0.02,
    ]);
    ok('ask 2', $wv->maybeAskArrival($RID, $LAT, $LNG, 10), true);
    DB::table(WV::T_VISIT)->where('id', $id)->update([
        'arrival_ask_at' => now()->subMinutes(60), 'arrival_ask_lat' => $LAT + 0.02,
    ]);
    ok('⭐⭐ there is no third time, whatever he does', $wv->maybeAskArrival($RID, $LAT, $LNG, 10), false);
    ok('  …and the counter says why', (int) DB::table(WV::T_VISIT)->where('id', $id)->value('arrival_ask_count'), 2);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§7 ⭐⭐ the prompt is a SERVER fact — it disappears on its own');

DB::beginTransaction();
try {
    $r = $bookFreeText(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);
    $dwell($LAT, $LNG);
    $wv->maybeAskArrival($RID, $LAT, $LNG, 10);
    ok('the question is on the phone', (bool) $wv->arrivalPromptFor($RID), null, true);

    // Somebody else answers it — a manager, or the geofence once a pin appears.
    DB::table(WV::T_VISIT)->where('id', $id)->update([
        'arrived_at' => now(), 'arrived_source' => 'manager',
    ]);
    ok('⭐ once the arrival exists, the question is gone', $wv->arrivalPromptFor($RID), null);

    // …and a stale ask expires by itself rather than sitting there all afternoon.
    DB::table(WV::T_VISIT)->where('id', $id)->update([
        'arrived_at' => null, 'arrived_source' => null,
        'arrival_ask_at' => now()->subHours(4),
    ]);
    ok('⭐ a four-hour-old question has expired', $wv->arrivalPromptFor($RID), null);

    // …and closing the visit ends it too.
    DB::table(WV::T_VISIT)->where('id', $id)->update(['arrival_ask_at' => now()]);
    ok('  (it is back while it is fresh)', (bool) $wv->arrivalPromptFor($RID), null, true);
    DB::table(WV::T_VISIT)->where('id', $id)->update(['status' => 'done', 'done_at' => now()]);
    ok('⭐ closing the visit ends the question', $wv->arrivalPromptFor($RID), null);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§8 ⚠ "Haan" twice does not double-stamp or make two workshops');

DB::beginTransaction();
try {
    $r = $bookFreeText('Double Tap Motors'); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);

    $a = $wv->confirmArrivalHere($rider, $id, $LAT, $LNG);
    $firstAt = DB::table(WV::T_VISIT)->where('id', $id)->value('arrived_at');
    ok('the first answer stamps it', (bool) $firstAt, null, true);

    $b = $wv->confirmArrivalHere($rider, $id, $LAT + 0.004, $LNG);
    ok('⭐ the second is accepted without complaint', $b['ok'] ?? false, true);
    ok('  …and says it was already done', $b['already'] ?? null, true);
    ok('  ⭐⭐ …the arrival time is UNCHANGED',
       (string) DB::table(WV::T_VISIT)->where('id', $id)->value('arrived_at'), (string) $firstAt);
    ok('  ⭐⭐ …and there is exactly ONE workshop of that name',
       DB::table('t_ops_company_locations')->whereRaw('LOWER(location_name) = ?', ['double tap motors'])->count(), 1);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§9 ⚠ a PINNED workshop is never asked about — the geofence owns it');

DB::beginTransaction();
try {
    $pinned = DB::table('t_ops_company_locations')->where('is_workshop', 1)
        ->whereNotNull('latitude')->whereNotNull('longitude')->first(['id', 'latitude', 'longitude']);
    if (!$pinned) {
        ok('a pinned workshop exists (none — skipped honestly)', true, true, true);
    } else {
        $r = $wv->schedule($manager, [
            'vehicle_id' => $vid, 'user_id' => $RID, 'visit_date' => $today,
            'visit_time' => '11:00', 'location_id' => (int) $pinned->id, 'confirm_replace' => 1,
        ]);
        if (empty($r['ok'])) { echo "      ⚠ booking refused: " . ($r['message'] ?? '?') . "\n"; }
        $id = (int) ($r['visit_id'] ?? 0);
        $wv->depart($rider, $id);
        $dwell($LAT, $LNG);
        ok('⭐ a visit WITH a pin is never answered by a question',
           $wv->maybeAskArrival($RID, $LAT, $LNG, 10), false);
    }
} finally { DB::rollBack(); }

echo "\n────────────────────────────────────────────────────────────\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n"
                 : "passed $pass, FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
