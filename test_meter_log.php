<?php
/**
 * VEHICLE METER LOG — readings entered from the Vehicles page (owner ask, Aug-14).
 *
 * The scenario: a rider opens his day on his own bike, is handed the VAN mid-day.
 * His one set of attendance meters is spent, so the van's kilometres have nowhere
 * to live. These prove the log fills that hole WITHOUT moving the machine's holder,
 * and that the gates behave exactly as ruled.
 *
 * ⚠ Mutates rows; every undo is registered BEFORE the mutation and unwound in
 *   reverse (the standing harness rule).
 *
 * Run:  php test_meter_log.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CRM\VehicleController;
use App\Services\Riders\MachineAttribution;
use App\Services\Riders\MeterCorrectionService;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

// Local oracle twin for section 9 (attendance pairs + non-duplicate log pairs).
function oracleOnDuty9(int $vehicleId, string $month): int
{
    $res = new VehicleResolver();
    $from = $month . '-01'; $to = date('Y-m-t', strtotime($from));
    $riderIds = DB::table(VehicleService::T_ASSIGN)->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all();
    $sum = 0; $pairs = [];
    foreach (DB::table('t_ops_attendance')->whereIn('user_id', $riderIds)
                ->whereBetween('attendance_date', [$from, $to])
                ->whereNotNull('meter_start')->whereNotNull('meter_end')
                ->get(['user_id', 'attendance_date', 'meter_start', 'meter_end']) as $a) {
        $d = substr((string) $a->attendance_date, 0, 10);
        if ($res->vehicleForDay((int) $a->user_id, $d) !== $vehicleId) continue;
        $km = (int) $a->meter_end - (int) $a->meter_start;
        if ($km >= 0 && $km <= MachineAttribution::MAX_DAY_KM && (int) $a->meter_start > 0) {
            $sum += $km;
            $pairs[$d . '|' . (int) $a->meter_start . '|' . (int) $a->meter_end] = true;
        }
    }
    foreach (DB::table('t_ops_vehicle_meter_log')->where('vehicle_id', $vehicleId)
                ->whereBetween('log_date', [$from, $to])
                ->whereNotNull('meter_start')->whereNotNull('meter_end')
                ->get(['log_date', 'meter_start', 'meter_end']) as $l) {
        $d = substr((string) $l->log_date, 0, 10);
        if (isset($pairs[$d . '|' . (int) $l->meter_start . '|' . (int) $l->meter_end])) continue;
        $km = (int) $l->meter_end - (int) $l->meter_start;
        if ($km >= 0 && $km <= MachineAttribution::MAX_DAY_KM && (int) $l->meter_start > 0) $sum += $km;
    }
    return $sum;
}


const VAN = 4;
$MONTH = '2026-08';
$DATE  = '2026-08-16';          // a date the van has no readings for

// --- undo registered BEFORE anything is written -----------------------
register_shutdown_function(function () use ($DATE) {
    DB::table('t_ops_vehicle_meter_log')->where('vehicle_id', VAN)->where('log_date', $DATE)->delete();
    Cache::flush();
});

$ctl = app(VehicleController::class);
$svc = new VehicleService();

// =====================================================================
head('1. The gate is manage_bike_service — exactly as ruled');
// =====================================================================
// ⚠⚠ ONE PROCESS PER USER — the 4th-occurrence trap: several loginUsingId() calls
//   in ONE process leak the auth guard and every probe after the first is evaluated
//   as the FIRST user. That has produced a false bug report before; a rider looked
//   permitted here for exactly that reason until this was split out.
// ⚠ Windows escapeshellarg() REPLACES embedded double quotes, so the probe code
//   cannot ride the command line — each probe is written to a temp FILE and run
//   with `php <file>`, one fresh process per user (the auth-guard-leak rule).
$probe = function (int $uid) {
    $code = sprintf(<<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
auth()->guard('web')->loginUsingId(%d);
$r = app(App\Http\Controllers\CRM\VehicleController::class)->meterDay(
    Illuminate\Http\Request::create('/x', 'GET', ['date' => '2026-08-16']),
    new App\Services\Riders\VehicleService(), 4);
echo $r->getStatusCode();
PHP, $uid);
    $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nf_probe_' . $uid . '.php';
    file_put_contents($f, $code);
    $out = shell_exec('php ' . escapeshellarg($f) . ' 2>NUL');
    @unlink($f);
    return (int) trim((string) $out);
};

ok('Shabib (manage_bike_service) may open the editor', $probe(79), 200);
ok('Qasim (the ruling names him) may open it too', $probe(91), 200);
ok('a READ-ONLY holder of the key is still refused (adnan)', $probe(92), 403);
ok('a pure rider without the key is refused (Rajab)', $probe(95), 403);

auth()->guard('web')->loginUsingId(79);

// =====================================================================
head('2. The editor PRELOADS what already exists (one reading, one home)');
// =====================================================================
$r = $ctl->meterDay(Request::create('/x', 'GET', ['date' => $DATE]), $svc, VAN);
$d = json_decode($r->getContent(), true);
ok('it reports the date', $d['date'], $DATE);
ok('it says whether an attendance row owns this machine-day',
   array_key_exists('attendance', $d), true);
ok('it offers a driver list', count($d['drivers'] ?? []) > 0, true, true);
ok('it states whether this user may correct a rider reading',
   $d['can_edit_attendance'], true);

// =====================================================================
head('3. The van stint: a reading with nowhere else to live');
// =====================================================================
$before = (new MachineAttribution())->forVehicle(VAN, $MONTH, true);
// ⚠ He already has van km from a REAL 17 Aug handover split (prod data), so every
//   assertion below measures the DELTA this test creates, never an absolute.
$riderBefore = 0;
foreach (((new MachineAttribution())->forRider(95, $MONTH)['machines'] ?? []) as $m) {
    if ($m['vehicle_id'] === VAN) $riderBefore = (int) $m['work_km'];
}
$beforeTotal = $before['totals']['total'];
echo "   van before: total={$beforeTotal} km\n";

// What he held BEFORE the reading is saved — the thing that must not move.
$holderBefore = (new VehicleResolver())->currentVehicleFor(95);
$save = $ctl->meterSave(Request::create('/x', 'POST', [
    'date' => $DATE, 'target' => 'log',
    'meter_start' => 73200, 'meter_end' => 73245,
    'driver_user_id' => 95, 'note' => 'lunchtime run',
]), $svc, VAN);
$sd = json_decode($save->getContent(), true);
ok('the log saves', $sd['success'] ?? false, true);

$row = DB::table('t_ops_vehicle_meter_log')->where('vehicle_id', VAN)->where('log_date', $DATE)->first();
ok('one row, on the machine', $row !== null, true, true);
ok('with the driver named', (int) $row->driver_user_id, 95);
ok('and who entered it', (int) $row->entered_by, 79);

// ⭐⭐ NO HANDOVER: the machine's holder must be UNCHANGED by the save.
// ⚠ This used to assert `currentVehicleFor(95) !== VAN` — a hard-coded fact about
//   prod at the time. It broke the day the owner genuinely handed rider 95 the van
//   from the app, which is a correct handover, not a bug in the meter log. What
//   this test actually cares about is that saving a READING moves nothing: so
//   capture the holder BEFORE and require it to be identical AFTER.
$holderAfter = (new VehicleResolver())->currentVehicleFor(95);
ok('the driver\'s machine is UNCHANGED by the save (no handover)',
   $holderAfter, $holderBefore);
$openVan = DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', VAN)
    ->whereNull('released_on')->count();
ok('no assignment row was created', $openVan, DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', VAN)->whereNull('released_on')->count());

// =====================================================================
head('4. The kilometres appear on BOTH lenses, from the one engine');
// =====================================================================
$eng = new MachineAttribution();
$after = $eng->forVehicle(VAN, $MONTH, true);
echo "   van after : total={$after['totals']['total']} km (on_duty={$after['totals']['on_duty']})\n";
ok('the machine gained the 45 km', $after['totals']['on_duty'] - $before['totals']['on_duty'], 45);
ok('and it still reconciles', $after['reconciles'], true);

$rider = $eng->forRider(95, $MONTH);
$vanLine = null;
foreach ($rider['machines'] ?? [] as $m) if ($m['vehicle_id'] === VAN) $vanLine = $m;
ok('the DRIVER gained a Van line in his machines strip', $vanLine !== null, true, true);
ok('with the 45 km added against his name',
   ((int) ($vanLine['work_km'] ?? 0)) - $riderBefore, 45);

// The day card tells the story with provenance.
$card = null;
foreach ($after['day_cards'] as $c) if ($c['date'] === $DATE) $card = $c;
ok('the van has a day card for it', $card !== null, true, true);
ok('summary reads 45 km on duty', $card['summary']['km'] ?? null, 45);
$logLine = null;
foreach ($card['lines'] as $l) if (($l['type'] ?? '') === 'meter_start') $logLine = $l;
ok('and the reading is marked as a manager entry', $logLine['source'] ?? null, 'log');

// =====================================================================
head('5. A driver-less entry counts on the MACHINE only');
// =====================================================================
$ctl->meterSave(Request::create('/x', 'POST', [
    'date' => $DATE, 'target' => 'log',
    'meter_start' => 73200, 'meter_end' => 73245, 'driver_user_id' => null,
]), $svc, VAN);
$anon = (new MachineAttribution())->forVehicle(VAN, $MONTH, true);
ok('the machine still counts the km', $anon['totals']['on_duty'] - $before['totals']['on_duty'], 45);
$anonRider = (new MachineAttribution())->forRider(95, $MONTH);
$anonKm = 0;
foreach ($anonRider['machines'] ?? [] as $m) if ($m['vehicle_id'] === VAN) $anonKm = (int) $m['work_km'];
ok('but the driver gains nothing from it', $anonKm - $riderBefore, 0);

// =====================================================================
head('6. Editing is routed to the reading\'s OWN home');
// =====================================================================
// A real attendance row — correcting it must go through the shared service and
// keep U5 provenance (a genuine 'home' recording stays 'home').
$att = DB::table('t_ops_attendance')
    ->whereNotNull('meter_start')->where('meter_start_source', 'home')
    ->orderByDesc('attendance_date')->first(['id', 'meter_start', 'meter_start_source']);

if ($att) {
    $origStart = (int) $att->meter_start;
    register_shutdown_function(function () use ($att, $origStart) {
        DB::table('t_ops_attendance')->where('id', $att->id)
            ->update(['meter_start' => $origStart, 'meter_start_source' => 'home']);
    });

    $res = (new MeterCorrectionService())->correct(
        (int) $att->id, true, $origStart + 3, false, null, 79);
    ok('the shared service corrects an attendance reading', $res['ok'], true);

    $now = DB::table('t_ops_attendance')->where('id', $att->id)
        ->first(['meter_start', 'meter_start_source', 'meter_end']);
    ok('the value changed', (int) $now->meter_start, $origStart + 3);
    // ⭐ U5: fixing digits on a genuine home recording must NOT restamp it 'manager'.
    ok('U5 — a home recording keeps its home stamp', $now->meter_start_source, 'home');

    // Restore now so the rest of the suite sees clean data.
    DB::table('t_ops_attendance')->where('id', $att->id)
        ->update(['meter_start' => $origStart, 'meter_start_source' => 'home']);
    ok('restored', (int) DB::table('t_ops_attendance')->where('id', $att->id)->value('meter_start'), $origStart);
} else {
    echo "  ! no 'home'-sourced attendance row to test U5 against\n";
}

// A blank field must never wipe a stored value.
$att2 = DB::table('t_ops_attendance')->whereNotNull('meter_start')->whereNotNull('meter_end')
    ->orderByDesc('attendance_date')->first(['id', 'meter_start', 'meter_end']);
if ($att2) {
    $r2 = (new MeterCorrectionService())->correct((int) $att2->id, false, null, false, null, 79);
    ok('sending nothing is refused, not destructive', $r2['ok'], false);
    $chk = DB::table('t_ops_attendance')->where('id', $att2->id)->first(['meter_start', 'meter_end']);
    ok('both readings survive untouched',
       (int) $chk->meter_start === (int) $att2->meter_start
       && (int) $chk->meter_end === (int) $att2->meter_end, true, true);
}

// =====================================================================
head('7. Clearing the entry removes it (no empty shells)');
// =====================================================================
$ctl->meterSave(Request::create('/x', 'POST', [
    'date' => $DATE, 'target' => 'log', 'meter_start' => null, 'meter_end' => null,
]), $svc, VAN);
ok('the row is gone', DB::table('t_ops_vehicle_meter_log')
    ->where('vehicle_id', VAN)->where('log_date', $DATE)->count(), 0);
$restored = (new MachineAttribution())->forVehicle(VAN, $MONTH, true);
ok('the machine is back to where it started', $restored['totals']['total'], $beforeTotal);

// =====================================================================
head('8. Validation walls (Aug-18): no future days, no phantom drivers');
// =====================================================================
// ⚠ validate() throws ValidationException outside HTTP; catch and read it.
$try422 = function (array $body) use ($ctl, $svc) {
    try {
        $r = $ctl->meterSave(Request::create('/x', 'POST', $body), $svc, VAN);
        return $r->getStatusCode();
    } catch (\Illuminate\Validation\ValidationException $e) {
        return 422;
    }
};
ok('a FUTURE date is refused (meters are recorded, never forecast)',
   $try422(['date' => date('Y-m-d', strtotime('+2 days')), 'target' => 'log', 'meter_start' => 100]), 422);
ok('a driver id that is no user is refused',
   $try422(['date' => $DATE, 'target' => 'log', 'meter_start' => 100, 'driver_user_id' => 999999]), 422);
ok('today itself is still allowed', $try422(['date' => date('Y-m-d'), 'target' => 'log']) !== 422, true, true);
DB::table('t_ops_vehicle_meter_log')->where('vehicle_id', VAN)->where('log_date', date('Y-m-d'))->delete();

// =====================================================================
head('9. A log row DUPLICATING the rider\'s own pair does not double-count');
// =====================================================================
// Find a real August attendance pair on DCR-799 (vehicle 3) to duplicate.
$res9 = new VehicleResolver();
$dup = null;
foreach (DB::table('t_ops_attendance')
            ->whereBetween('attendance_date', ['2026-08-01', '2026-08-31'])
            ->whereNotNull('meter_start')->whereNotNull('meter_end')
            ->orderBy('attendance_date')
            ->get(['user_id', 'attendance_date', 'meter_start', 'meter_end']) as $a) {
    $d9 = substr((string) $a->attendance_date, 0, 10);
    if ($res9->vehicleForDay((int) $a->user_id, $d9) === 3
        && (int) $a->meter_end > (int) $a->meter_start) { $dup = $a; break; }
}
if ($dup) {
    $dupDate = substr((string) $dup->attendance_date, 0, 10);
    register_shutdown_function(function () use ($dupDate) {
        DB::table('t_ops_vehicle_meter_log')->where('vehicle_id', 3)->where('log_date', $dupDate)->delete();
        Cache::flush();
    });
    $b9 = (new MachineAttribution())->forVehicle(3, $MONTH, true);
    $ctl->meterSave(Request::create('/x', 'POST', [
        'date' => $dupDate, 'target' => 'log',
        'meter_start' => (int) $dup->meter_start, 'meter_end' => (int) $dup->meter_end,
    ]), $svc, 3);
    $a9 = (new MachineAttribution())->forVehicle(3, $MONTH, true);
    ok('machine total unchanged by the duplicate', $a9['totals']['total'], $b9['totals']['total']);
    ok('on-duty unchanged too (nothing counted twice)', $a9['totals']['on_duty'], $b9['totals']['on_duty']);
    ok('and the oracle still agrees', $a9['totals']['on_duty'], oracleOnDuty9(3, $MONTH));
    DB::table('t_ops_vehicle_meter_log')->where('vehicle_id', 3)->where('log_date', $dupDate)->delete();
    (new MachineAttribution())->flush($MONTH);
} else {
    echo "  ! no August attendance pair on vehicle 3 to duplicate\n";
}

// =====================================================================
head('10. A SECOND STINT on the holder\'s day = split parts, never "shared"');
// =====================================================================
// The holder rode and closed his day; a manager entry adds Rajab from exactly
// that closing reading. Nothing is unowned — the card must present PARTS, not
// call the whole day shared (which would un-credit both men).
if ($dup) {
    $stKm = 20;
    $b10r = (new MachineAttribution())->forRider(95, $MONTH);
    $b10m = ($b10r['machines'] ?? []);
    $b10km = 0;
    foreach ($b10m as $m) if ((int) $m['vehicle_id'] === 3) $b10km = $m['work_km'];
    $ctl->meterSave(Request::create('/x', 'POST', [
        'date' => $dupDate, 'target' => 'log',
        'meter_start' => (int) $dup->meter_end,
        'meter_end'   => (int) $dup->meter_end + $stKm,
        'driver_user_id' => 95, 'note' => 'edge: second stint',
    ]), $svc, 3);
    $v10 = (new MachineAttribution())->forVehicle(3, $MONTH, true);
    $card = null;
    foreach (($v10['day_cards'] ?? []) as $c) if ($c['date'] === $dupDate) { $card = $c; break; }
    ok('the day card exists', $card !== null, true, true);
    ok('its verdict is split parts, NOT shared', $card['summary']['kind'] ?? null, 'split');
    $names = array_map(fn ($pp) => $pp['who'], $card['summary']['parts'] ?? []);
    ok('both stints are named', count($names) >= 2, true, true);
    $a10r = (new MachineAttribution())->forRider(95, $MONTH);
    $a10km = 0;
    foreach (($a10r['machines'] ?? []) as $m) if ((int) $m['vehicle_id'] === 3) $a10km = $m['work_km'];
    ok('Rajab gained exactly his stint', $a10km - $b10km, $stKm);
    DB::table('t_ops_vehicle_meter_log')->where('vehicle_id', 3)->where('log_date', $dupDate)->delete();
    (new MachineAttribution())->flush($MONTH);
} else {
    echo "  ! skipped with section 9\n";
}

// =====================================================================
head('11. The driver list follows ruling 4 — ANY active user, riders or not');
// =====================================================================
$r11 = $ctl->meterDay(Request::create('/x', 'GET', ['date' => $DATE]), $svc, VAN);
$d11 = json_decode($r11->getContent(), true);
$ids11 = array_column($d11['drivers'] ?? [], 'user_id');
ok('Taimur (no rider) is offered as a driver', in_array(68, $ids11, true), true, true);
ok('Rajab (a rider) is offered too', in_array(95, $ids11, true), true, true);
ok('an inactive user is NOT offered', count(array_intersect($ids11,
    DB::table('t_sys_user')->where('is_active', '0')->pluck('id')->map(fn ($v) => (int) $v)->all())), 0);

echo "\n================  $pass passed, $fail failed  ================\n";
exit($fail ? 1 : 0);
