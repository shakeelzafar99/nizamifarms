<?php
/**
 * devfixture.php make|drop
 *
 * Create (or remove) a LOCAL-ONLY workshop + a proposed visit, so the real approval card can
 * be driven on the device. Everything it writes is tagged `DEVCHECK` so `drop` can find it.
 *
 * ⚠⚠ LOCAL ONLY — refuses unless APP_ENV=local AND the database host is localhost.
 * ⚠ Pushes are already inert (FIREBASE_CREDENTIALS_PATH points at a missing file), but this
 *   goes through the REAL service on purpose: the point is to exercise Qasim's actual
 *   booking path, not to hand-insert a row the code would never have produced.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\WorkshopVisitService as WV;
use Illuminate\Support\Facades\DB;

if (config('app.env') !== 'local') { fwrite(STDERR, "refusing: APP_ENV is not local\n"); exit(1); }
$host = config('database.connections.' . config('database.default') . '.host');
if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "refusing: db host '$host' is not localhost\n"); exit(1);
}
if (file_exists(config('whatsapp.firebase_credentials_path'))) {
    fwrite(STDERR, "refusing: Firebase credentials are present — pushes would reach real phones\n"); exit(1);
}

$mode = $argv[1] ?? 'make';
$TAG  = 'DEVCHECK';

if ($mode === 'drop') {
    $ids = DB::table(WV::T_VISIT)->where('note', 'like', "%$TAG%")->pluck('id')->all();
    foreach ($ids as $id) {
        DB::table('t_ops_user_shift_assignment')->where('workshop_visit_id', $id)->delete();
    }
    $v = DB::table(WV::T_VISIT)->where('note', 'like', "%$TAG%")->delete();
    $l = DB::table('t_ops_company_locations')->where('location_name', 'like', "%$TAG%")->delete();
    echo "removed: $v visit(s), $l location(s)\n";
    exit(0);
}

// ── the workshop, with coordinates so a day CAN be pinned to it ──────────────
$loc = DB::table('t_ops_company_locations')->where('location_name', 'like', "%$TAG%")->value('id');
if (!$loc) {
    $loc = DB::table('t_ops_company_locations')->insertGetId([
        'location_name' => "$TAG Ali Motors", 'latitude' => 33.6867, 'longitude' => 73.0331,
        'radius_meters' => 300, 'is_primary' => 0, 'is_workshop' => 1, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ── the booker: Qasim-shaped — schedule_workshop but NOT a shift planner ─────
$booker = null;
foreach (User::where('is_active', '1')->orderBy('id')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($u->hasPermission(WV::PERMISSION) && !$u->hasPermission(WV::APPROVE_PERMISSION)) { $booker = $u; break; }
}
if (!$booker) { fwrite(STDERR, "no Qasim-shaped booker found\n"); exit(1); }

// ── a rider on a COMPANY machine ────────────────────────────────────────────
$res = new VehicleResolver(); $vs = new VehicleService();
$rider = null; $vid = null;
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
    if ($v && $vs->isTrackedId($v)) { $rider = (int) $uid; $vid = $v; break; }
}
if (!$rider) { fwrite(STDERR, "no rider on a company machine\n"); exit(1); }

$date = \Carbon\Carbon::today()->addDays(4)->format('Y-m-d');
$wv   = new WV();
$r = $wv->schedule($booker, [
    'vehicle_id' => $vid, 'user_id' => $rider, 'visit_date' => $date,
    'visit_time' => '11:00', 'location_id' => $loc, 'purpose' => 'service',
    'note' => "$TAG — local UI check, safe to delete", 'confirm_replace' => 1,
]);

echo json_encode([
    'booker'   => $booker->id . ' ' . $booker->fullname,
    'rider'    => $rider, 'vehicle' => $vid, 'date' => $date, 'location_id' => $loc,
    'ok'       => $r['ok'] ?? false,
    'proposed' => $r['proposed'] ?? null,
    'visit_id' => $r['visit_id'] ?? null,
    'message'  => $r['message'] ?? null,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
