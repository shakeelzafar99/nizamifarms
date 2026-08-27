<?php
/**
 * BUSINESS SCENARIO PROBE — Rajab's real historical own-bike fuel, on the fresh prod replica.
 *
 * The owner's question: on a day he was holding the company VAN, can his OWN bike's
 * kilometres still be seen and filed — by HIM on the phone, and by a MANAGER on the web?
 *
 * Real data on this replica (not synthetic):
 *   Aug-13..22, Aug-27  attendance meters = his own bike (vehicle 9)
 *   Aug-23, Aug-24      attendance meters = the VAN (vehicle 4), while the meter log
 *                       separately records his own bike running 14 km and 45 km
 *   Aug-23              the van's fuel was ALREADY paid Rs 3,000 cash (REQ-202608-0305)
 *
 * READ-ONLY. The one filing test runs inside a transaction that is always rolled back.
 * Run:  php probe_rajab_real_days.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\FuelClaimRules;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

const RAJAB   = 95;
const VAN     = 4;
const OWNBIKE = 9;
const MANAGER = 79;   // Shabib — the man who typed the own-bike meter rows
const CAT_EXPENSE = 3;

$pass = 0; $fail = 0; $notes = [];
function ok(string $what, $got, $want) {
    global $pass, $fail;
    $good = $got === $want;
    $good ? $pass++ : $fail++;
    echo ($good ? "  [PASS] " : "  [FAIL] ") . $what . "\n";
    if (!$good) {
        echo "         got:  " . var_export($got, true) . "\n";
        echo "         want: " . var_export($want, true) . "\n";
    }
}
function head(string $t) { echo "\n== $t ==\n"; }

$legsSvc  = app(RiderDayLegs::class);
$rules    = app(FuelClaimRules::class);
$resolver = app(VehicleResolver::class);

echo "Machines as the system names them:  van = " . $resolver->labelFor(VAN)
   . "   own bike = " . $resolver->labelFor(OWNBIKE) . "\n";

$att = fn (string $d) => (int) DB::table('t_ops_attendance')
    ->where('user_id', RAJAB)->where('attendance_date', $d)->value('id');
$find = function (array $rows, int $vid) {
    foreach ($rows as $r) { if ((int)($r['vehicle_id'] ?? 0) === $vid) return $r; }
    return null;
};

// ─────────────────────────────────────────────────────────────────────────────
head('A. the day as the ENGINE reconstructs it (RiderDayLegs)');

$days = ['2026-08-13','2026-08-22','2026-08-23','2026-08-24','2026-08-27'];
$legsByDay = [];
foreach ($days as $d) {
    $L = $legsByDay[$d] = $legsSvc->forDay(RAJAB, $d);
    $desc = [];
    foreach ($L as $l) {
        $desc[] = sprintf('%s %skm%s', $l['label'], (float)$l['km'],
                          !empty($l['is_company']) ? ' [company]' : ' [his]');
    }
    printf("  %s : %s\n", $d, $desc ? implode('  +  ', $desc) : '(no legs)');
}

ok('Aug-23 is seen as a TWO-machine day', count($legsByDay['2026-08-23']), 2);
ok('Aug-24 is seen as a TWO-machine day', count($legsByDay['2026-08-24']), 2);

$own23 = $find($legsByDay['2026-08-23'], OWNBIKE);
$van23 = $find($legsByDay['2026-08-23'], VAN);
ok('  …his own bike is there, with its own kilometres', (float)($own23['km'] ?? -1), 14.0);
ok('  …and is NOT the company\'s', (bool)($own23['is_company'] ?? true), false);
ok('  …the van is there too', (float)($van23['km'] ?? -1), 167.0);
ok('  …and IS the company\'s', (bool)($van23['is_company'] ?? false), true);
ok('Aug-24 own bike kilometres', (float)($find($legsByDay['2026-08-24'], OWNBIKE)['km'] ?? -1), 45.0);

$row = DB::table('t_ops_vehicle_meter_log')->where('log_date','2026-08-23')->where('vehicle_id',OWNBIKE)->first();
ok('the Aug-23 own-bike log names NO driver, yet is still counted as his',
   $row && $row->driver_user_id === null && $own23 !== null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('B. the CLAIM GUARD on those real days');

$c = $rules->checkMeteredPetrol(RAJAB, '2026-08-23', 14.0, $att('2026-08-23'), OWNBIKE, null);
ok('Aug-23 his own bike\'s 14 km: ALLOWED', (bool)($c['ok'] ?? false), true);

$c = $rules->checkMeteredPetrol(RAJAB, '2026-08-23', 167.0, $att('2026-08-23'), VAN, null);
ok('Aug-23 the VAN\'s 167 km: REFUSED (the Rs 1,586.50 double-pay hole)', (bool)($c['ok'] ?? true), false);
$notes[] = 'Aug-23 van refusal: ' . ($c['message'] ?? '(none)');

$c = $rules->checkMeteredPetrol(RAJAB, '2026-08-23', 167.0, $att('2026-08-23'), null, null);
ok('  …refused even when no machine is named', (bool)($c['ok'] ?? true), false);

$c = $rules->checkMeteredPetrol(RAJAB, '2026-08-24', 45.0, $att('2026-08-24'), OWNBIKE, null);
ok('Aug-24 his own bike\'s 45 km: ALLOWED', (bool)($c['ok'] ?? false), true);

$c = $rules->checkMeteredPetrol(RAJAB, '2026-08-23', 99.0, $att('2026-08-23'), OWNBIKE, null);
ok('a forged distance on his own bike is REFUSED', (bool)($c['ok'] ?? true), false);

// ⭐ The money proof: the van's fuel that day was ALREADY paid in cash.
$cash = DB::table('t_req_master')->where('id', 2838)->first();
ok('the van\'s Aug-23 fuel was already paid Rs 3,000 cash', (float)($cash->amount ?? 0), 3000.0);
ok('  …so the refused per-km claim would have been a SECOND payment',
   $cash && (int)$cash->vehicle_id === VAN && $cash->status === 'approved', true);
$notes[] = sprintf('Double-pay avoided on Aug-23: 167 km x Rs 9.5 = Rs %s on top of the Rs 3,000 cash fill',
                   number_format(167 * 9.5, 2));

// ─────────────────────────────────────────────────────────────────────────────
head('C. what RAJAB sees on his PHONE (the real mobile endpoint)');

Auth::guard('web')->loginUsingId(RAJAB);
$ctrl = new \App\Http\Controllers\API\RiderController();
$req  = Request::create('/api/rider/attendance/monthly', 'GET', ['month' => '2026-08-01']);
$req->setUserResolver(fn () => \App\Models\SysAdmin\UserModel::find(RAJAB));
$payload = json_decode($ctrl->getMonthlyAttendance($req)->getContent(), true);

ok('the phone gets its month', (bool)($payload['success'] ?? false), true);
echo "  claim window = " . (int)($payload['petrol_window_days'] ?? 0) . " days, rate = Rs "
   . ($payload['petrol_rate'] ?? '?') . "/km\n";

$byDate = [];
foreach ($payload['history'] ?? [] as $h) { if (!empty($h['date'])) $byDate[$h['date']] = $h; }
$h23 = $byDate['2026-08-23'] ?? null;

ok('Aug-23 appears in his month', $h23 !== null, true);
ok('  …the phone is shown BOTH machines', count($h23['vehicles'] ?? []), 2);
$pOwn = $find($h23['vehicles'] ?? [], OWNBIKE);
$pVan = $find($h23['vehicles'] ?? [], VAN);
ok('  …his own bike leg is offered as claimable', (bool)($pOwn['can_claim'] ?? false), true);
ok('  …the van leg is marked the company\'s', (bool)($pVan['is_company'] ?? false), true);
ok('  …and the van offers NO claim button', (bool)($pVan['can_claim'] ?? true), false);
ok('  …the headline distance installed APKs read is HIS 14 km, not the van\'s 167',
   (float)($h23['meter_distance'] ?? -1), 14.0);
ok('  …and the readings shown are his bike\'s, so the figures agree',
   [(int)($h23['meter_start'] ?? 0), (int)($h23['meter_end'] ?? 0)], [6667, 6681]);
$notes[] = 'Phone Aug-23 own-bike leg: ' . json_encode($pOwn);

// The historical days he was actually paid for must all still read as claimed.
$paid = $payload['petrol_requests'] ?? [];
ok('his 8 historical own-bike claims are all still shown on the phone', count($paid), 8);
$allOwn = true;
foreach ($paid as $p) { if ((int)($p['vehicle_id'] ?? 0) !== OWNBIKE) $allOwn = false; }
ok('  …and every one of them is stamped to his own bike', $allOwn, true);

// ─────────────────────────────────────────────────────────────────────────────
// ⚠ Sections D (manager sees) and E (manager files) live in probe_manager_files.php.
//   Only ONE user may be authenticated per process — logging in a second user here
//   leaves the FIRST one active, so a manager check written inline silently runs as
//   the rider and 403s. Run:  php probe_manager_files.php 79
head('F. historical reach — how far back can each door go?');

$today = \Carbon\Carbon::parse('2026-08-27');
foreach (['2026-08-13','2026-08-22','2026-08-23','2026-08-24'] as $d) {
    $L   = $legsSvc->forDay(RAJAB, $d);
    $own = $find($L, OWNBIKE);
    $c   = $own ? $rules->checkMeteredPetrol(RAJAB, $d, (float)$own['km'], $att($d), OWNBIKE, null) : null;
    $age = $today->diffInDays(\Carbon\Carbon::parse($d));
    printf("  %s (%2d days back)  own bike %5s km  ->  %s%s\n", $d, $age,
        $own ? (float)$own['km'] : '-',
        $c === null ? 'no leg' : (($c['ok'] ?? false) ? 'RIDER CAN CLAIM' : 'rider refused'),
        ($c && !($c['ok'] ?? false)) ? '  (' . mb_substr((string)($c['message'] ?? ''), 0, 70) . ')' : '');
}

echo "\n" . str_repeat('-', 70) . "\n";
foreach ($notes as $n) { echo "NOTE: $n\n"; }
echo str_repeat('-', 70) . "\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n" : "FAILURES — passed $pass, failed $fail\n";
