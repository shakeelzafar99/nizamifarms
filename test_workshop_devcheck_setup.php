<?php
/**
 * Put a LIVE workshop trip on the local replica so the real phone and the real browser can be
 * driven against it. Everything it writes is tagged DEVCHECK and removable by its sibling
 * teardown script.
 *
 * ⚠ Local replica only — asserted below. Prod is a different database.
 */
require 'C:/NF App/nizamifarms/vendor/autoload.php';
$app = require 'C:/NF App/nizamifarms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\WorkshopVisitService as WV;
use Illuminate\Support\Facades\DB;

if (!in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "REFUSING: not a local database\n");
    exit(1);
}
// ⚠ Pushes must stay off — the local DB holds real staff device tokens.
config(['whatsapp.firebase_credentials_path' => 'storage/__DEV_PUSHES_DISABLED_restore_from_env_bak__.json']);

$wv    = new WV();
$res   = new VehicleResolver();
$today = \Carbon\Carbon::today()->format('Y-m-d');

$RID = (int) ($argv[1] ?? 76);                 // default Kanan — the rider from the real incident
$FREE = isset($argv[2]) && $argv[2] === 'free'; // free-text workshop (no pin) vs a registered one

$rider = User::find($RID);
if (!$rider) { fwrite(STDERR, "no such rider $RID\n"); exit(1); }
$vid = (int) ($res->currentVehicleFor($RID) ?: 0);
if (!$vid) { fwrite(STDERR, "rider $RID holds no vehicle today\n"); exit(1); }

// A manager who may schedule.
$mgr = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($u->hasPermission(WV::PERMISSION)) { $mgr = $u; break; }
}
if (!$mgr) { fwrite(STDERR, "no manager with schedule_workshop\n"); exit(1); }

// Clear any DEVCHECK leftovers first so re-running is safe.
DB::table(WV::T_VISIT)->where('note', 'like', 'DEVCHECK%')->delete();

$in = [
    'vehicle_id' => $vid, 'user_id' => $RID, 'visit_date' => $today,
    'visit_time' => '15:00', 'confirm_replace' => 1,
    'note' => 'DEVCHECK — remove me',
];
if ($FREE) {
    $in['workshop'] = 'DEVCHECK Gali Motors';   // free text ⇒ no location_id ⇒ no geofence
} else {
    $loc = DB::table('t_ops_company_locations')->where('is_workshop', 1)
        ->whereNotNull('latitude')->value('id');
    if ($loc) { $in['location_id'] = (int) $loc; } else { $in['workshop'] = 'DEVCHECK Ali Motors'; }
}

$r = $wv->schedule($mgr, $in);
if (empty($r['ok'])) { fwrite(STDERR, "schedule refused: " . ($r['message'] ?? '?') . "\n"); exit(1); }
$id = (int) $r['visit_id'];
DB::table(WV::T_VISIT)->where('id', $id)->update(['status' => 'accepted']);

// Set off — force past the dispatched-orders gate, which is not what we are testing here.
$d = $wv->depart($mgr, $id, ['force' => 1]);
if (empty($d['ok'])) { fwrite(STDERR, "depart refused: " . ($d['message'] ?? '?') . "\n"); exit(1); }

$trip = $wv->tripFor($RID);
echo "DEVCHECK visit #$id for {$rider->fullname} (user $RID) on vehicle $vid\n";
echo "  state : " . ($trip['state'] ?? '?') . "  active=" . var_export($trip['is_active'] ?? null, true) . "\n";
echo "  label : " . ($trip['label'] ?? '?') . "\n";
echo "  ur    : " . ($trip['label_ur'] ?? '?') . "\n";
echo "  pin   : " . (($trip['location_id'] ?? null) ? 'registered workshop' : 'FREE TEXT (no pin)') . "\n";
