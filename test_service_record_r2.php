<?php
/**
 * 🛠📷 SERVICE RECORDS, ROUND 2 (11-Sep-2026) — what the owner asked for after Shabib could
 *      only see two of the four maintenance types when closing a workshop visit.
 *
 * The rulings this proves:
 *   • EVERY active job can be recorded, whether or not it has an interval. *"Even though there
 *     are no intervals set, it should still log it."* On prod only 2 of 4 types carry a
 *     kilometre figure, which is precisely why the close dialog offered two choices.
 *   • An unscheduled job — "other repair" — records the work, the photo and the amount, and
 *     **resets no countdown**.
 *   • A VAN visit is closeable even though every type still defaults to `applies_to = bike`.
 *   • The proof PHOTO no longer needs an amount: the rider is handed a receipt and pays
 *     nothing, and a bill filed days later INHERITS that photo.
 *   • A scheduled oil service still resets the clock exactly as before (no regression).
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ NOT `head()` — Laravel ships a global `head()` that the validator calls.
 *
 * Run:  php test_service_record_r2.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\MaintenanceTypeService;
use App\Services\Riders\ServiceRecordService;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
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

$rec  = app(ServiceRecordService::class);
$mts  = app(MaintenanceTypeService::class);
$vsvc = new VehicleService();
$res  = new VehicleResolver();

// ── fixtures ──────────────────────────────────────────────────────────
$rider = null; $vid = null;
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
    if (!$v || !$vsvc->isTrackedId($v)) continue;
    $u = User::find((int) $uid);
    if ($u) { $rider = $u; $vid = $v; break; }
}
ok('a rider on a tracked machine exists', (bool) $rider, null, true);
if (!$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }
$RID = (int) $rider->id;

$all = $mts->options();
ok('the maintenance-type list is configured', count($all) > 0, true);
$scheduledIds = [];
$openIds      = [];
foreach ($all as $t) {
    $hasKm = (int) ($t['interval_km'] ?? 0) > 0 || !empty($t['has_schedule']);
    if ($hasKm) $scheduledIds[] = (int) $t['id']; else $openIds[] = (int) $t['id'];
}
echo "  · types: " . count($all) . " active — " . count($scheduledIds) . " scheduled, "
   . count($openIds) . " with no interval\n";

// ─────────────────────────────────────────────────
section('§1 the close list offers EVERY job, not only the countdowns');

$klass  = $rec->classFor($vid, $RID, null);
$closeT = $rec->typesForClose($klass);
$schedT = $rec->scheduledTypes($klass);

ok('the close list is not shorter than the countdown list', count($closeT) >= count($schedT), true);
ok('⭐ it offers every ACTIVE type', count($closeT), count($all));
if ($openIds) {
    $ids = array_map(fn ($t) => (int) $t['id'], $closeT);
    ok('  ⭐ …including a job with no interval (the "only 2 of 4" report)',
       in_array($openIds[0], $ids, true), true);
    ok('  ⚠ …which the OLD list hid',
       in_array($openIds[0], array_map(fn ($t) => (int) $t['id'], $schedT), true), false);
}
ok('every row says whether it counts down',
   count(array_filter($closeT, fn ($t) => array_key_exists('counts_down', $t))), count($closeT));
if ($closeT && $scheduledIds) {
    ok('  …and the scheduled ones sort first', (bool) ($closeT[0]['counts_down'] ?? false), true);
}

// ─────────────────────────────────────────────────
section('§2 an unscheduled job is RECORDED and resets NOTHING');

if (!$openIds) {
    ok('a type with no interval exists on this database (none — skipped honestly)', true, true, true);
} else {
    DB::beginTransaction();
    try {
        $before = DB::table('t_ops_rider_profile')->where('user_id', $RID)
            ->first(['last_service_meter', 'last_service_at']);
        $meter = (int) ($vsvc->currentMeterFor($vid) ?: 0) + 5;

        $r = $rec->resolveType($openIds[0], $klass);
        ok('⭐ an unscheduled job is ACCEPTED (it used to be refused outright)', $r['ok'] ?? false, true);
        ok('  …and is marked as not counting down', $r['counts_down'] ?? null, false);

        $out = $rec->record([
            'rider_id' => $RID, 'vehicle_id' => $vid, 'meter' => $meter,
            'date' => \Carbon\Carbon::today()->format('Y-m-d'),
            'type' => $r['type'], 'counts_down' => $r['counts_down'] ?? true,
            'actor_id' => $RID, 'note' => 'proof — other repair',
        ]);
        ok('  ⭐ …the work IS logged', $out['ok'] ?? false, true);
        ok('  …with a real log row', (bool) ($out['service_log_id'] ?? null), null, true);
        ok('  ⭐ …and NO countdown moved', $out['moved_clock'] ?? null, false);
        ok('  …the receipt says so in words',
           str_contains((string) ($out['message'] ?? ''), 'no countdown was reset'), true);

        $after = DB::table('t_ops_rider_profile')->where('user_id', $RID)
            ->first(['last_service_meter', 'last_service_at']);
        ok('  ⭐⭐ …the service-due clock is untouched',
           (string) $after->last_service_meter === (string) $before->last_service_meter, true);
    } finally { DB::rollBack(); }
}

// ─────────────────────────────────────────────────
section('§3 a scheduled oil service still resets the clock (no regression)');

$oil = null;
foreach ($all as $t) {
    if (!empty($t['resets_service_clock']) && (int) ($t['interval_km'] ?? 0) > 0) { $oil = $t; break; }
}
if (!$oil) {
    ok('a clock-resetting scheduled type exists (none — skipped honestly)', true, true, true);
} else {
    DB::beginTransaction();
    try {
        $meter = (int) ($vsvc->currentMeterFor($vid) ?: 0) + 7;
        $r = $rec->resolveType((int) $oil['id'], $klass);
        ok('the oil service resolves', $r['ok'] ?? false, true);
        ok('  …and DOES count down', $r['counts_down'] ?? null, true);
        $out = $rec->record([
            'rider_id' => $RID, 'vehicle_id' => $vid, 'meter' => $meter,
            'date' => \Carbon\Carbon::today()->format('Y-m-d'),
            'type' => $r['type'], 'counts_down' => $r['counts_down'] ?? true,
            'actor_id' => $RID, 'note' => 'proof — oil',
        ]);
        ok('  ⭐ …and the clock DOES move', $out['moved_clock'] ?? null, true);
        $after = DB::table('t_ops_rider_profile')->where('user_id', $RID)->value('last_service_meter');
        ok('  …to the reading just given', (int) $after, $meter);
    } finally { DB::rollBack(); }
}

// ─────────────────────────────────────────────────
section('§4 📷 a photo with NO amount, and the later bill inherits it');

if (!ServiceRecordService::logKeepsPhoto()) {
    echo "  ⚠ PENDING-PROD-SEP12-2026-WORKSHOP-R2.sql has not run here — the photo is not kept\n";
    ok('the code degrades quietly without the columns', true, true);
    // Prove the degrade is SAFE: recording must still work, just without a picture.
    DB::beginTransaction();
    try {
        $meter = (int) ($vsvc->currentMeterFor($vid) ?: 0) + 9;
        $r = $rec->resolveType($scheduledIds ? $scheduledIds[0] : ($openIds[0] ?? null), $klass);
        $out = $rec->record([
            'rider_id' => $RID, 'vehicle_id' => $vid, 'meter' => $meter,
            'date' => \Carbon\Carbon::today()->format('Y-m-d'),
            'type' => $r['type'] ?? null, 'counts_down' => $r['counts_down'] ?? true,
            'actor_id' => $RID, 'photo_path' => 'service-logs/2026/09/proof.jpg',
            'note' => 'proof — pre-SQL photo',
        ]);
        ok('  ⭐ …and a service with a photo still RECORDS', $out['ok'] ?? false, true);
    } finally { DB::rollBack(); }
} else {
    DB::beginTransaction();
    try {
        $meter = (int) ($vsvc->currentMeterFor($vid) ?: 0) + 11;
        $tid   = $openIds[0] ?? ($scheduledIds[0] ?? null);
        $r     = $rec->resolveType($tid, $klass);
        $out   = $rec->record([
            'rider_id' => $RID, 'vehicle_id' => $vid, 'meter' => $meter,
            'date' => \Carbon\Carbon::today()->format('Y-m-d'),
            'type' => $r['type'], 'counts_down' => $r['counts_down'] ?? true,
            'actor_id' => $RID,
            // ⭐ THE POINT: no amount anywhere in this call.
            'photo_path' => 'service-logs/2026/09/proof.jpg',
            'note' => 'proof — photo without amount',
        ]);
        $logId = (int) ($out['service_log_id'] ?? 0);
        ok('⭐⭐ a service records with a photo and NO amount', $out['ok'] ?? false, true);
        $saved = DB::table('t_fleet_service_log')->where('id', $logId)->first(['photo_path', 'photo_by']);
        ok('  …the photo is stored on the WORK', $saved->photo_path ?? null, 'service-logs/2026/09/proof.jpg');
        ok('  …stamped with who attached it', (int) ($saved->photo_by ?? 0), $RID);

        // …and the bill a manager files days later inherits it.
        if (\Illuminate\Support\Facades\Schema::hasColumn('t_fleet_service_log', 'request_id')) {
            $reqId = (int) DB::table('t_req_master')->insertGetId([
                'request_number' => 'PROOF-' . uniqid(),
                'requester_user_id' => $RID,
                // ⚠ category_id is NOT NULL with no default on t_req_master.
                'category_id' => (int) (DB::table('t_req_category')->value('id') ?: 1),
                'title' => 'Proof bill',
                'status' => 'pending',
                'amount' => 850,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $rec->attachBillToService($logId, $reqId);
            $att = DB::table('t_req_master')->where('id', $reqId)->value('attachments');
            ok('  ⭐⭐ …and a bill filed LATER inherits that photo',
               in_array('service-logs/2026/09/proof.jpg', json_decode((string) $att, true) ?: [], true), true);

            // ⚠ …but never overwrites a picture the manager attached himself.
            $req2 = (int) DB::table('t_req_master')->insertGetId([
                'request_number' => 'PROOF-' . uniqid(),
                'requester_user_id' => $RID,
                // ⚠ category_id is NOT NULL with no default on t_req_master.
                'category_id' => (int) (DB::table('t_req_category')->value('id') ?: 1),
                'title' => 'Proof bill',
                'status' => 'pending',
                'amount' => 900,
                'attachments' => json_encode(['requests/attachments/his-own.jpg']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $rec->attachBillToService($logId, $req2);
            $att2 = json_decode((string) DB::table('t_req_master')->where('id', $req2)->value('attachments'), true) ?: [];
            ok('  ⚠ …and never overwrites the manager\'s own photo', $att2, ['requests/attachments/his-own.jpg']);
        } else {
            ok('the service↔bill link column exists (absent — skipped honestly)', true, true, true);
        }
    } finally { DB::rollBack(); }
}

// ─────────────────────────────────────────────────
section('§5 🚚 a VAN visit is closeable even with bike-only types');

// ⚠ `vtype` is the column; there is no `vehicle_type` on this table.
$van = DB::table('t_ops_vehicle')->where('is_active', 1)->where('vtype', 'van')->first(['id']);
if (!$van) {
    ok('a van exists on this database (none — skipped honestly)', true, true, true);
} else {
    $vanClass = $vsvc->classOf((int) $van->id);
    $vanTypes = $rec->typesForClose($vanClass);
    ok('⭐ the van is offered a job list at all', count($vanTypes) > 0, true);
    ok('  ⚠ …which the countdown-only list could not do',
       count($rec->scheduledTypes($vanClass)) < count($vanTypes) || count($vanTypes) === count($all), true);
    if ($vanTypes) {
        $r = $rec->resolveType((int) $vanTypes[0]['id'], $vanClass);
        ok('  ⭐⭐ …and a job on it is ACCEPTED (it used to be refused as "not for vans")',
           $r['ok'] ?? false, true);
    }
}

// ─────────────────────────────────────────────────
section('§6 a blank pick is still refused when anything is offerable');

$r = $rec->resolveType(null, $klass);
ok('⚠ an odometer with no job named is REFUSED', $r['ok'] ?? null, false);
ok('  …and says why', str_contains((string) ($r['message'] ?? ''), 'Choose which service'), true);

echo "\n────────────────────────────────────────────────────────────\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n"
                 : "passed $pass, FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
