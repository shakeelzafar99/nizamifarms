<?php
/**
 * OLD-APK COMPATIBILITY — can the web files ship WITHOUT a new APK? (Aug-28 2026)
 *
 * Everything built in this batch is server-side except the two mobile screens. The
 * question that matters to the owner is whether an ALREADY-INSTALLED app keeps working
 * — and, better, whether it gets the fixes for free.
 *
 * The rule this batch has followed throughout: payload keys are ADDITIVE, and no
 * existing key changes shape. These assert exactly that, against the real payloads.
 *
 * ⚠ The field lists below are lifted from what the INSTALLED screens actually read
 *   (grepped from FleetScreen.js / AttendanceScreen.js). If a future change drops one,
 *   this fails rather than the phone failing in someone's hand.
 *
 * Run:  php test_old_apk_compat.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\FleetFuelService;
use Illuminate\Http\Request;
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

// ─────────────────────────────────────────────────────────────────────────────
head('§1 Bikes — the rider drill-down the installed app renders');

// Exactly what FleetScreen's renderDay()/renderClaim() touch today.
// ALWAYS present — the app reads these unconditionally.
$DAY_KEYS = ['date', 'status', 'detail', 'work_km', 'meter_start', 'meter_end',
             'offduty_km', 'offduty_since', 'incl_ride_home', 'handover',
             'shared_km', 'shared_with', 'transfer_km', 'claims'];
// CONDITIONAL by design, and always were: `unattributed_km` is set only on a
// company-bike month, `transfer_with` only when a transfer leg exists. The app
// reads both behind truthy checks, so absent === falsy === nothing rendered.
// Listing them as required would fail on a perfectly healthy payload.
$DAY_OPTIONAL = ['unattributed_km', 'transfer_with'];
$CLAIM_KEYS = ['id', 'kind', 'amount', 'status', 'source', 'meter_distance', 'petrol_rate',
               'meter_at_fill', 'litres', 'maintenance_type', 'service_type',
               'km_since_fill', 'km_since_fill_by', 'km_since_fill_odd',
               'km_since_service', 'service_early_by', 'service_late_by', 'service_interval',
               'service_due_km_at_approval', 'overdue_now_km', 'flag', 'photo',
               'approval_notes', 'approval_actions'];

$svc = new FleetFuelService();
$rider = null; $dayWithClaims = null; $twoBikeDay = null;
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $r = $svc->riderMonth((int) $uid, '2026-08', true);
    foreach (($r['days'] ?? []) as $d) {
        if (!empty($d['claims']) && $dayWithClaims === null) { $dayWithClaims = $d; $rider = $r; }
        if (count($d['machines_today'] ?? []) > 1 && $twoBikeDay === null) { $twoBikeDay = $d; }
    }
    if ($dayWithClaims && $twoBikeDay) break;
}
ok('found a real day carrying claims', $dayWithClaims !== null, true, true);
ok('found a real TWO-machine day', $twoBikeDay !== null, true, true);

$missing = array_values(array_diff($DAY_KEYS, array_keys($dayWithClaims ?? [])));
ok('every DAY key the installed app reads is still present', $missing, []);
// The optional pair must still be ABSENT-OR-VALID, never some new shape.
$badOptional = [];
foreach ($DAY_OPTIONAL as $k) {
    if (array_key_exists($k, $dayWithClaims) && $dayWithClaims[$k] !== null
        && !is_numeric($dayWithClaims[$k]) && !is_string($dayWithClaims[$k])) {
        $badOptional[] = $k;
    }
}
ok('the conditional keys are absent or scalar (never a new shape)', $badOptional, []);

$claim = $dayWithClaims['claims'][0] ?? [];
$missingC = array_values(array_diff($CLAIM_KEYS, array_keys($claim)));
ok('every CLAIM key the installed app reads is still present', $missingC, []);

// Shapes the old renderer assumes.
ok('days is still a list of day objects', is_array($rider['days'] ?? null), true);
ok('claims is still a plain list', array_is_list($dayWithClaims['claims']), true);
ok('meter_start is still a scalar, not an object',
   $dayWithClaims['meter_start'] === null || is_numeric($dayWithClaims['meter_start']), true);

// The new keys are ADDITIVE — an old app simply never looks at them.
ok('machines_today is additive and a plain list',
   array_is_list($twoBikeDay['machines_today']), true);
ok('the day scalars still name the LAST machine (old behaviour preserved)',
   $twoBikeDay['vehicle_id'],
   (int) $twoBikeDay['machines_today'][count($twoBikeDay['machines_today']) - 1]['vehicle_id']);

// ⚠ THE POINT: an old app showing the last machine's readings is what it did BEFORE
//   this change too. Nothing it renders has moved.
ok('meter_start/meter_end on a two-bike day are unchanged scalars',
   is_numeric($twoBikeDay['meter_start']) || $twoBikeDay['meter_start'] === null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 Attendance — the rider\'s own screen');

$RAJAB = 95;
\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($RAJAB);
$ctrl = new \App\Http\Controllers\API\RiderController();
$req = Request::create('/api/rider/attendance/monthly', 'GET', ['month' => '2026-08-01']);
$req->setUserResolver(fn () => \App\Models\SysAdmin\UserModel::find($RAJAB));
$payload = json_decode($ctrl->getMonthlyAttendance($req)->getContent(), true);

$HIST_KEYS = ['id','date','date_formatted','login_time','login_time_formatted','logout_time',
              'logout_time_formatted','status','late_minutes','notes','picture_start','picture_end',
              'meter_start','meter_end','meter_distance','meter_warning','checkin_latitude',
              'checkin_longitude','checkin_distance_from_base','is_remote_checkin',
              'checkout_latitude','checkout_longitude'];
$row = null;
foreach ($payload['history'] ?? [] as $h) { if (!empty($h['id'])) { $row = $h; break; } }
ok('endpoint still succeeds', $payload['success'] ?? false, true);
ok('every history key the installed app reads is present',
   array_values(array_diff($HIST_KEYS, array_keys($row ?? []))), []);
ok('petrol_requests is STILL an attendance-id map, not a list',
   is_array($payload['petrol_requests']) &&
     (empty($payload['petrol_requests']) || !array_is_list($payload['petrol_requests'])), true);
ok('petrol_rate still sent', array_key_exists('petrol_rate', $payload), true);
ok('petrol_window_days still sent', array_key_exists('petrol_window_days', $payload), true);
ok('the rider window is unchanged for the phone', $payload['petrol_window_days'], 5);

// ─────────────────────────────────────────────────────────────────────────────
head('§3 Vendors — the phone gets MORE, and nothing changes shape');

$vc = new \App\Http\Controllers\FIN\VendorController();
$vreq = Request::create('/api/vendors', 'GET', ['status' => 'active', 'business_unit_id' => 'all']);
$vreq->headers->set('Accept', 'application/json');
$vres = json_decode($vc->index($vreq)->getContent(), true);
ok('response still has success/vendors/total_balance/pagination',
   array_values(array_diff(['success','vendors','total_balance','pagination'], array_keys($vres))), []);
ok('vendors is still a plain list the old app can map over', array_is_list($vres['vendors']), true);
ok('…and now carries every vendor, not 20',
   count($vres['vendors']), DB::table('t_fin_vendors')->where('is_active', 1)->count());
ok('pagination still present for anything that reads it',
   array_values(array_diff(['current_page','last_page','per_page','total'],
                array_keys($vres['pagination']))), []);
// The old app sends status=null for "All"; axios drops it, so the server sees nothing.
$vreq2 = Request::create('/api/vendors', 'GET', ['business_unit_id' => 'all']);
$vreq2->headers->set('Accept', 'application/json');
$vres2 = json_decode($vc->index($vreq2)->getContent(), true);
ok('an old app sending no status still gets active-only (unchanged)',
   count($vres2['vendors']), DB::table('t_fin_vendors')->where('is_active', 1)->count());

// ─────────────────────────────────────────────────────────────────────────────
head('§4 ⚠⚠ THE ONE DELIBERATE EXCEPTION — Record service needs the new APK');

/**
 * ⚠⚠ READ THIS BEFORE SHIPPING WEB-ONLY. Everything above says "the installed app
 *    keeps working". Since 2-Sep-2026 that is NO LONGER TRUE for ONE action.
 *
 * Owner ruling: a "Record service" carrying an odometer but no `maintenance_type_id`
 * is REJECTED rather than guessed (it used to be filed against the shortest
 * clock-resetting type, which silently misfiled `t_fleet_service_log` #8). Every APK
 * built before the FleetScreen type picker posts exactly that payload.
 *
 * So the deploy order is: build the APK, upload the web files, install the APK. In
 * the gap, a manager records a service from the WEB (which has always asked for the
 * type). Nothing else on the phone is affected — the refusal is scoped to this one
 * endpoint, and only when a meter is sent.
 *
 * This section asserts the exception EXISTS and stays scoped, so a future session
 * reading "ALL GREEN" cannot conclude that web-only is safe for this batch.
 */
$typeSvc = app(\App\Services\Riders\MaintenanceTypeService::class);
$scheduled = array_filter($typeSvc->options(), fn ($t) => (int) ($t['interval_km'] ?? 0) > 0);
ok('this database HAS scheduled maintenance types (so the rule is live)',
   count($scheduled) > 0, true);

$fcSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/FleetFuelController.php');
// ⚠ The refusal itself lives in ServiceRecordService since Phase 3 — three callers with
//   different permission gates share the rule. Assert it wherever it is.
$recSrc = file_get_contents(__DIR__ . '/app/Services/Riders/ServiceRecordService.php');
ok('an untyped meter is refused, not guessed',
   str_contains($recSrc, 'Choose which service was done'), true);
ok('  …and the message tells the user to update the app',
   (bool) preg_match('/update the app/i', $recSrc), true);
ok('  …and the old substitute-type lookup is gone',
   str_contains($fcSrc, 'no service type given, treated as the routine service'), false);

// Scope: the SCHEDULE-only call (⚙️ This bike) carries no type and must still work
// for an old APK — it never recorded a service, so the ruling does not touch it.
$fsPos = strpos($fcSrc, 'public function markServiced');
$fsEnd = strpos($fcSrc, 'public function setDefaultInterval');
$body  = substr($fcSrc, $fsPos, max(0, $fsEnd - $fsPos));
ok('the type is demanded ONLY when a meter is sent',
   (bool) preg_match('/if\s*\(\$request->filled\(.meter.\)\)\s*\{[^}]*?THE TYPE IS REQUIRED/s', $body), true);
ok('  …so an old APK can still set a bike\'s schedule',
   (bool) preg_match('/filled\(.interval_km.\)/', $body), true);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "ALL GREEN" : "FAILURES") . " — passed {$pass}, failed {$fail}\n";
if ($fail === 0) {
    echo "\n⚠ NOTE: §4 — this batch is NOT web-only. Build and install the APK with it.\n";
}
exit($fail === 0 ? 0 : 1);
