<?php
/**
 * 🛰️ A FIX OUTSIDE PAKISTAN IS NOT A FIX — the SERVER half (16-Sep-2026).
 *
 * The incident: three riders' phones reported Tiananmen Square, Beijing, at 4–32 m "accuracy" on
 * one day (a mislocated office Wi-Fi point, repeated for hours by a tracker that never asked the
 * GPS). Farooq's check-in was STORED as a remote check-in 3,878 km away; the live map painted two
 * riders in China; two deliveries were stamped there. Every installed APK will keep sending such
 * fixes until it is updated, so the server has to refuse them itself.
 *
 * What must hold:
 *   • a heartbeat outside the box never enters t_ops_rider_location — it becomes a FAILURE row
 *     with the coordinates, answered 200 (a 4xx makes the old APK retry the same lie);
 *   • a fix the phone says is older than 15 min is not stored as "now";
 *   • a check-in outside the box is not a location at all — no coordinates, no "remote";
 *   • …unless the office Wi-Fi vouches for him, in which case he is checked in AT that office;
 *   • the Wi-Fi also settles a coarse or remote fix — but never overrides a SHARP fix elsewhere.
 *
 * Everything runs inside a transaction that is rolled back. Run: php test_gps_plausibility.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\LocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function section(string $t) { echo "\n== $t ==\n"; }

$BEIJING = ['lat' => 39.9073198, 'lng' => 116.3911138];
$OFFICE  = DB::table('t_ops_company_locations')->where('is_active', 1)->orderBy('id')->first();
ok('an active company location exists to test against', (bool) $OFFICE, null, true);

section('§1 the box');
ok('the office is inside',   LocationService::isPlausibleFix($OFFICE->latitude, $OFFICE->longitude), true);
ok('Karachi is inside',      LocationService::isPlausibleFix(24.86, 67.01), true);
ok('Gwadar is inside',       LocationService::isPlausibleFix(25.12, 62.32), true);
ok('Gilgit is inside',       LocationService::isPlausibleFix(35.92, 74.31), true);
ok('Beijing is outside',     LocationService::isPlausibleFix($BEIJING['lat'], $BEIJING['lng']), false);
ok('Riyadh is outside',      LocationService::isPlausibleFix(24.7538, 46.6927), false);
ok('Ottawa is outside',      LocationService::isPlausibleFix(45.42, -75.69), false);
ok('garbage is outside',     LocationService::isPlausibleFix('x', null), false);
ok('the rider sentence is Roman Urdu and names Wi-Fi', str_contains(LocationService::phantomFixMessage(), 'Wi-Fi'), true);

$beforeLoc  = DB::table('t_ops_rider_location')->count();
$beforeFail = DB::table('t_ops_location_failures')->count();
$beforeCfg  = DB::table('t_fin_config')->count();

DB::beginTransaction();
try {
    // A rider and a today-row so the heartbeat door is open.
    $riderId = (int) DB::table('t_ops_rider_profile')->orderBy('user_id')->value('user_id');
    $rider   = User::find($riderId);
    ok('a rider exists', (bool) $rider, null, true);
    DB::table('t_ops_attendance')->where('user_id', $riderId)->whereDate('attendance_date', now()->toDateString())->delete();
    DB::table('t_ops_attendance')->insert([
        'user_id' => $riderId, 'attendance_date' => now()->toDateString(),
        'login_time' => '09:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Auth::setUser($rider);
    $ctrl = app(\App\Http\Controllers\API\RiderController::class);
    $hb = function (array $body) use ($ctrl) {
        $req = Request::create('/api/rider/location-heartbeat', 'POST', $body);
        $req->headers->set('Accept', 'application/json');
        return json_decode($ctrl->locationHeartbeat($req)->getContent(), true);
    };

    section('§2 the heartbeat door');
    $r = $hb(['latitude' => $BEIJING['lat'], 'longitude' => $BEIJING['lng'], 'accuracy' => 9.1,
              'source' => 'native_fused', 'fix_age_s' => 3, 'provider' => 'fused', 'mocked' => false, 'device_model' => 'TestPhone X']);
    ok('Beijing is answered 200 with success:true — the old APK must not retry it', $r['success'] ?? null, true);
    ok('  …stored:false, code phantom_fix', [$r['stored'] ?? null, $r['code'] ?? null], [false, 'phantom_fix']);
    ok('  …with the Roman-Urdu warning the current APK already renders', str_contains($r['gps_warning'] ?? '', 'BAHAR'), true);
    ok('  …and NO row entered the location table', DB::table('t_ops_rider_location')->count(), $beforeLoc);
    $failRow = DB::table('t_ops_location_failures')->where('user_id', $riderId)->orderByDesc('id')->first();
    ok('  …but a failure row did, reason phantom_fix', $failRow->failure_reason ?? null, 'phantom_fix');
    ok('    …carrying the refused coordinates', round((float) ($failRow->last_known_lat ?? 0), 3), round($BEIJING['lat'], 3));
    ok('    …and the provider, mock flag and handset for the post-mortem',
       str_contains((string) ($failRow->error_message ?? ''), 'prov=fused') && str_contains((string) $failRow->error_message, 'model=TestPhone X'), true);

    $r = $hb(['latitude' => $OFFICE->latitude, 'longitude' => $OFFICE->longitude, 'accuracy' => 40, 'source' => 'native_fused', 'fix_age_s' => 1200]);
    ok('a fix the phone says is 20 min old is not stored as "now"', [$r['stored'] ?? null, $r['code'] ?? null], [false, 'stale_fix']);
    ok('  …no row', DB::table('t_ops_rider_location')->count(), $beforeLoc);

    // ⚠ Not a global COUNT: the handler prunes old rows on insert, so the table can shrink while
    //   a row is added. Watch the newest id for THIS rider instead.
    $maxId = (int) DB::table('t_ops_rider_location')->max('id');
    $r = $hb(['latitude' => $OFFICE->latitude, 'longitude' => $OFFICE->longitude, 'accuracy' => 40, 'source' => 'native_fused', 'fix_age_s' => 4]);
    ok('a real, fresh fix is stored exactly as before', $r['success'] ?? null, true);
    $newest = DB::table('t_ops_rider_location')->where('user_id', $riderId)->where('id', '>', $maxId)->orderByDesc('id')->first();
    ok('  …one row, for this rider, with the source', $newest->source ?? null, 'native_fused');
    $maxId = (int) DB::table('t_ops_rider_location')->max('id');
    $r = $hb(['latitude' => $OFFICE->latitude, 'longitude' => $OFFICE->longitude, 'accuracy' => 40, 'source' => 'js']);
    ok('an OLD APK (no fix_age_s at all) is stored as before',
       DB::table('t_ops_rider_location')->where('user_id', $riderId)->where('id', '>', $maxId)->exists(), true);

    section('§3 the check-in door (processCheckinLocation)');
    $m = new ReflectionMethod($ctrl, 'processCheckinLocation'); $m->setAccessible(true);
    $prop = new ReflectionProperty($ctrl, 'lastCheckinPhantom'); $prop->setAccessible(true);
    $ci = function (array $body) use ($ctrl, $m, $riderId) {
        $req = Request::create('/api/rider/attendance/check-in', 'POST', $body);
        return $m->invoke($ctrl, $req, $riderId);
    };

    $res = $ci(['latitude' => $BEIJING['lat'], 'longitude' => $BEIJING['lng'], 'accuracy' => 32.4, 'source' => 'fresh_gps', 'method' => 1]);
    ok('Beijing at 32 m — the exact incident — is NOT a location', $res, null);
    ok('  …and the controller remembers it was a phantom (so checkIn can say why)', (bool) $prop->getValue($ctrl), true);
    ok('  …and a failure row was written from the check-in gate',
       DB::table('t_ops_location_failures')->where('user_id', $riderId)->where('failure_source', 'checkin_gate')->where('failure_reason', 'phantom_fix')->exists(), true);

    // Office Wi-Fi configured for the active location under test.
    $bssid = 'aa:bb:cc:dd:ee:01';
    DB::table('t_fin_config')->where('config_key', 'OFFICE_WIFI_BSSIDS')->delete();
    DB::table('t_fin_config')->insert(['config_key' => 'OFFICE_WIFI_BSSIDS', 'config_value' => json_encode([(string) $OFFICE->id => [$bssid, 'aa:bb:cc:dd:ee:02']]), 'created_at' => now()]);
    ok('officeForWifi resolves a configured BSSID to its location', LocationService::officeForWifi(strtoupper($bssid))->id ?? null, (int) $OFFICE->id);
    ok('  …ignores the Android placeholder', LocationService::officeForWifi('02:00:00:00:00:00'), null);
    ok('  …and an unknown one', LocationService::officeForWifi('11:22:33:44:55:66'), null);

    $res = $ci(['latitude' => $BEIJING['lat'], 'longitude' => $BEIJING['lng'], 'accuracy' => 9.1, 'source' => 'phantom', 'method' => 4, 'wifi_bssid' => $bssid]);
    ok('Beijing + the office Wi-Fi ⇒ checked in AT the office', is_array($res), true);
    ok('  …not remote', $res['db_fields']['is_remote_checkin'] ?? null, 0);
    ok('  …on the office\'s own coordinates, never Beijing\'s', round((float) ($res['db_fields']['checkin_latitude'] ?? 0), 4), round((float) $OFFICE->latitude, 4));
    ok('  …and nothing lingers as a phantom for checkIn to refuse', $prop->getValue($ctrl), null);

    // A coarse cell fix 1.1 km away — the Sep-5 Orchard Lacarne shape — settled by the Wi-Fi.
    $far = ['lat' => (float) $OFFICE->latitude + 0.010, 'lng' => (float) $OFFICE->longitude];   // ≈1.1 km north
    $res = $ci(['latitude' => $far['lat'], 'longitude' => $far['lng'], 'accuracy' => 700, 'source' => 'network', 'method' => 4, 'wifi_bssid' => $bssid]);
    ok('a ±700 m fix 1.1 km away + office Wi-Fi ⇒ present, not remote', $res['db_fields']['is_remote_checkin'] ?? null, 0);
    $res = $ci(['latitude' => $far['lat'], 'longitude' => $far['lng'], 'accuracy' => 700, 'source' => 'network', 'method' => 4]);
    ok('  …the same fix WITHOUT the Wi-Fi is still remote (nothing widened)', $res['db_fields']['is_remote_checkin'] ?? null, 1);

    // A SHARP fix 5 km away with the Wi-Fi claimed: the sharp fix wins — the router's reach is tens of metres.
    $farther = ['lat' => (float) $OFFICE->latitude + 0.045, 'lng' => (float) $OFFICE->longitude];
    $res = $ci(['latitude' => $farther['lat'], 'longitude' => $farther['lng'], 'accuracy' => 8, 'source' => 'fresh_gps', 'method' => 1, 'wifi_bssid' => $bssid]);
    ok('a SHARP fix 5 km away is NOT overridden by a Wi-Fi claim', $res['db_fields']['is_remote_checkin'] ?? null, 1);

    section('§4b the other doors: check-out / home meter strip a phantom up front');
    $dm = new ReflectionMethod($ctrl, 'dropPhantomFromRequest'); $dm->setAccessible(true);
    $req = Request::create('/api/rider/attendance/check-out', 'POST', ['latitude' => $BEIJING['lat'], 'longitude' => $BEIJING['lng'], 'accuracy' => 8]);
    ok('a phantom is stripped from the check-out request', $dm->invoke($ctrl, $req, $riderId, 'checkout'), true);
    ok('  …so every sub-step (checkout rule, home-journey arming, stored position) sees NO location',
       [$req->input('latitude'), $req->input('longitude'), $req->input('accuracy')], [null, null, null]);
    ok('  …and a failure row names the door',
       DB::table('t_ops_location_failures')->where('user_id', $riderId)->where('failure_source', 'checkout')->where('failure_reason', 'phantom_fix')->exists(), true);
    $req = Request::create('/api/rider/attendance/check-out', 'POST', ['latitude' => $OFFICE->latitude, 'longitude' => $OFFICE->longitude, 'accuracy' => 8]);
    ok('a real fix is left exactly as it came', $dm->invoke($ctrl, $req, $riderId, 'checkout'), false);
    ok('  …untouched', (float) $req->input('latitude'), (float) $OFFICE->latitude);
    $req = Request::create('/x', 'POST', []);
    ok('no coordinates at all ⇒ nothing to strip, nothing logged', $dm->invoke($ctrl, $req, $riderId, 'checkout'), false);

    section('§4 dormant when unconfigured');
    DB::table('t_fin_config')->where('config_key', 'OFFICE_WIFI_BSSIDS')->delete();
    ok('no config row ⇒ no office for any BSSID', LocationService::officeForWifi($bssid), null);
    $res = $ci(['latitude' => $BEIJING['lat'], 'longitude' => $BEIJING['lng'], 'accuracy' => 9.1, 'source' => 'phantom', 'method' => 4, 'wifi_bssid' => $bssid]);
    ok('  …so Beijing + a Wi-Fi nobody configured is still no location', $res, null);
} finally {
    DB::rollBack();
}

section('§5 nothing left behind');
ok('location rows (the transaction rolled back the prune too)', DB::table('t_ops_rider_location')->count(), $beforeLoc);
ok('failure rows', DB::table('t_ops_location_failures')->count(), $beforeFail);
ok('config rows', DB::table('t_fin_config')->count(), $beforeCfg);

echo "\n────────────────────────────────────────────\n";
echo ($fail === 0 ? "✅  " : "❌  ") . "$pass passed, $fail failed\n";
echo "────────────────────────────────────────────\n";
exit($fail === 0 ? 0 : 1);
