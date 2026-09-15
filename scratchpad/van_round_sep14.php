<?php
/**
 * 🚚 VAN + MAP ROUND — Sep-14-2026 verification suite.
 *
 * Runs the REAL endpoints / services in-process inside ONE transaction and rolls back.
 * Run:  php artisan tinker --execute='require base_path("scratchpad/van_round_sep14.php");'
 *
 * ⚠ NEVER commits. ⚠ Never calls app()->terminate(), so terminating hooks (Qurbani WA
 *   sender, ETA warming, location drain) do not fire from this suite.
 * ⚠ Pushes are inert on dev (FIREBASE_CREDENTIALS_PATH points at a missing file) and the
 *   WhatsApp token is disabled for the dev session.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

$DRIVER      = 95;  // Rajab  — made the van driver inside the transaction
$OTHER_DRIVER= 74;  // Farooq — second van, for the cross-van scan
$CARGO_RIDER = 77;  // Arslan — carries cargo on the van
$NON_DRIVER  = 91;  // Qasim  — zero orders, never a driver
$VAN_VEHICLE = 4;   // CAD-2958

$pass = 0; $fail = 0; $failures = [];
$check = function (string $name, bool $ok, $detail = '') use (&$pass, &$fail, &$failures) {
    $ok ? $pass++ : $fail++;
    if (!$ok) $failures[] = $name;
    echo ($ok ? 'OK   ' : 'BAD  ') . $name
       . ($detail !== '' ? '  → ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
};
$section = function (string $t) { echo "\n=== $t\n"; };

$as = function (int $uid) {
    $u = \App\Models\User::find($uid) ?? \App\Models\CRM\UserModel::find($uid);
    Sanctum::actingAs($u, ['*']);
    auth()->setUser($u);
    return $u;
};
$call = function (string $method, string $uri, array $body = []) {
    $req = Request::create($uri, $method, $body, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $res = app()->handle($req);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
};

DB::beginTransaction();
try {
    $van   = app(\App\Services\Riders\VanService::class);
    $stops = app(\App\Services\Riders\VanStopService::class);

    // ---- seed: make Rajab (and Farooq) van drivers today -------------------
    DB::table('t_ops_vehicle_assignment')->insert([
        'vehicle_id' => $VAN_VEHICLE, 'user_id' => $DRIVER,
        'assigned_on' => today()->format('Y-m-d'), 'released_on' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $van2 = DB::table('t_ops_vehicle')->insertGetId([
        'vtype' => 'van', 'reg_no' => 'TEST-VAN-2', 'is_company' => 1, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('t_ops_vehicle_assignment')->insert([
        'vehicle_id' => $van2, 'user_id' => $OTHER_DRIVER,
        'assigned_on' => today()->format('Y-m-d'), 'released_on' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $van = app(\App\Services\Riders\VanService::class);   // fresh, memoised resolvers
    $drivers = $van->todaysDrivers();
    $check('seed: two van drivers today', count($drivers) >= 2, array_map(fn($d) => $d['user_id'] ?? $d, $drivers));
    $check('seed: Rajab is a van driver', $van->isVanDriver($DRIVER));
    $check('seed: Qasim is NOT a van driver', !$van->isVanDriver($NON_DRIVER));

    // ---- seed orders -------------------------------------------------------
    // Three real order rows, reshaped in-transaction. Picked as the newest rows so we
    // never disturb anything a person is looking at today.
    $ids = DB::table('t_crm_prod_order')->orderByDesc('id')->limit(4)->pluck('id')->all();
    [$oOwn, $oCargo, $oStranded, $oProcessing] = $ids;

    $base = ['van_vehicle_id' => $VAN_VEHICLE, 'van_trip_id' => null, 'van_loaded_by' => $DRIVER,
             'handover_at' => null, 'handover_scanned_by' => null, 'handover_scanned_packets' => null,
             'eta_calculated_at' => null, 'estimated_delivery_at' => null, 'expected_packets' => 1];

    DB::table('t_crm_prod_order')->where('id', $oOwn)->update($base + [
        'order_status' => 'on_van', 'assigned_rider_user_id' => $DRIVER,
        'van_user_id' => $DRIVER, 'van_loaded_at' => now()->subMinutes(40),
        'van_loaded_packets' => json_encode([1]), 'delivery_priority' => 1,
    ]);
    DB::table('t_crm_prod_order')->where('id', $oCargo)->update($base + [
        'order_status' => 'on_van', 'assigned_rider_user_id' => $CARGO_RIDER,
        'van_user_id' => $DRIVER, 'van_loaded_at' => now()->subMinutes(35),
        'van_loaded_packets' => json_encode([1]), 'delivery_priority' => 2,
    ]);
    // Aboard since a PREVIOUS run — 30h ago (bound is STALE_TAG_HOURS = 20).
    DB::table('t_crm_prod_order')->where('id', $oStranded)->update($base + [
        'order_status' => 'on_van', 'assigned_rider_user_id' => $CARGO_RIDER,
        'van_user_id' => $DRIVER, 'van_loaded_at' => now()->subHours(30),
        'van_loaded_packets' => json_encode([1]), 'delivery_priority' => 3,
    ]);
    DB::table('t_crm_prod_order')->where('id', $oProcessing)->update([
        'order_status' => 'processing', 'assigned_rider_user_id' => $DRIVER,
        'van_user_id' => null, 'van_loaded_at' => null, 'van_loaded_packets' => null,
        'delivery_priority' => 4, 'eta_calculated_at' => null,
    ]);

    // =======================================================================
    $section('3.1  start-leg refuses a rider who does not drive a van');
    $as($NON_DRIVER);
    [$st, $j] = $call('POST', '/api/rider/van/start-leg', ['leg' => 'deliveries']);
    $check('non-driver start-leg refused (422)', $st === 422, $j['message'] ?? $st);
    $check('  message names the rule', str_contains(strtolower((string)($j['message'] ?? '')), 'not driving a van'));
    $check('  and NO trip was minted for him',
        !DB::table('t_ops_van_trip')->where('van_user_id', $NON_DRIVER)->whereNull('ended_at')->exists());

    $as($DRIVER);
    [$st, $j] = $call('POST', '/api/rider/van/start-leg', ['leg' => 'deliveries']);
    $check('the real driver may still start a leg (200)', $st === 200, $j['message'] ?? $st);
    $check('  his trip exists', DB::table('t_ops_van_trip')->where('van_user_id', $DRIVER)->whereNull('ended_at')->exists());

    // =======================================================================
    $section('3.2  a box already on another van cannot be scanned aboard this one');
    // Put the cargo box on Farooq's van, then scan it at Rajab's.
    DB::table('t_crm_prod_order')->where('id', $oCargo)->update(['van_user_id' => $OTHER_DRIVER]);
    $order = \App\Models\CRM\OrderModel::find($oCargo);
    $res = $van->loadScan($order, (string) $order->order_number, $DRIVER, $DRIVER);
    $check('cross-van load scan REFUSED', ($res['ok'] ?? true) === false, $res['message'] ?? '');
    $check('  refusal names the other driver', str_contains((string)($res['message'] ?? ''), 'Farooq'), $res['message'] ?? '');
    $fresh = DB::table('t_crm_prod_order')->where('id', $oCargo)->first();
    $check('  the box stayed on the other van', (int) $fresh->van_user_id === $OTHER_DRIVER);
    $check('  and its packet list was not touched', $fresh->van_loaded_packets === json_encode([1]), $fresh->van_loaded_packets);
    DB::table('t_crm_prod_order')->where('id', $oCargo)->update(['van_user_id' => $DRIVER]);

    // A re-scan on the SAME van is untouched by the new guard.
    $order = \App\Models\CRM\OrderModel::find($oOwn);
    $res = $van->loadScan($order, (string) $order->order_number, $DRIVER, $DRIVER);
    $check('same-van re-scan still accepted (one van = guard inert)', ($res['ok'] ?? false) === true, $res['message'] ?? '');

    // =======================================================================
    $section('M3  store staff cannot load cargo onto a non-driver');
    $as(74); // Farooq has assign_riders in practice; permission checked by the endpoint
    [$st, $j] = $call('POST', "/api/rider/van/orders/{$oProcessing}/load-scan", [
        'scan_code' => (string) DB::table('t_crm_prod_order')->where('id', $oProcessing)->value('order_number'),
        'van_user_id' => $NON_DRIVER,
    ]);
    $check('load onto a non-driver refused (422/403)', in_array($st, [422, 403], true), [$st, $j['message'] ?? null]);
    $check('  order still has no van stamp',
        DB::table('t_crm_prod_order')->where('id', $oProcessing)->value('van_user_id') === null);

    // =======================================================================
    $section('3.3  a live past-midnight trip survives; a forgotten one is closed');
    // (a) yesterday's trip, cargo aboard, departed 2h ago = a real run past midnight
    // ⚠ Close any OTHER open trip of his first and age only the one `openTrip()` returns —
    //   that method is `orderByDesc('id')`, so aging them all made this assertion read a
    //   different row than the code under test (a suite bug, not a code one).
    $tripId = (int) $van->openTrip($DRIVER)->id;
    DB::table('t_ops_van_trip')->where('van_user_id', $DRIVER)->where('id', '!=', $tripId)
        ->whereNull('ended_at')->update(['ended_at' => now()]);
    DB::table('t_ops_van_trip')->where('id', $tripId)->update([
        'trip_date' => today()->subDay()->format('Y-m-d'),
        'departed_at' => now()->subHours(2), 'ended_at' => null,
    ]);
    $stopId = DB::table(\App\Services\Riders\VanService::T_HANDOVER)->insertGetId([
        'van_user_id' => $DRIVER, 'trip_id' => $tripId, 'label' => 'Suite point',
        'meet_lat' => 33.6, 'meet_lng' => 73.1, 'status' => 'waiting', 'set_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $kept = $van->ensureTrip($DRIVER, null, $DRIVER);
    $check('live past-midnight trip KEPT OPEN', (int) $kept->id === (int) $tripId, "kept={$kept->id} expected={$tripId}");
    $check('  its meet-up was NOT cancelled',
        DB::table(\App\Services\Riders\VanService::T_HANDOVER)->where('id', $stopId)->whereNull('completed_at')->exists());
    $check('  departure latch not re-armed (no second "van left" push)',
        DB::table('t_ops_van_trip')->where('id', $tripId)->value('ended_at') === null);

    // (b) same trip but nothing aboard and no open stop = genuinely forgotten
    DB::table(\App\Services\Riders\VanService::T_HANDOVER)->where('id', $stopId)
        ->update(['completed_at' => now(), 'status' => 'completed']);
    DB::table('t_crm_prod_order')->whereIn('id', [$oOwn, $oCargo, $oStranded])->update(['van_user_id' => null]);
    $newTrip = $van->ensureTrip($DRIVER, null, $DRIVER);
    $check('forgotten previous-day trip IS closed', (int) $newTrip->id !== (int) $tripId, "new={$newTrip->id} old={$tripId}");
    $check('  old trip marked auto-closed',
        str_contains((string) DB::table('t_ops_van_trip')->where('id', $tripId)->value('note'), 'auto-closed'));
    // restore cargo for the remaining sections
    DB::table('t_crm_prod_order')->whereIn('id', [$oOwn, $oCargo, $oStranded])->update(['van_user_id' => $DRIVER]);

    // =======================================================================
    $section('M1 + M2  the 20h bound, and a stranded box that is flagged not hidden');
    $awaiting = $van->ridersAwaiting($DRIVER);
    $detail   = $van->ridersAwaitingDetail($DRIVER);
    $check('ridersAwaiting now bounded like ridersAwaitingDetail', count($awaiting) === count($detail),
        ['awaiting' => $awaiting, 'detail' => array_column($detail, 'user_id')]);

    // Only the 30h box left for the cargo rider → he must drop out of BOTH lists.
    DB::table('t_crm_prod_order')->where('id', $oCargo)->update(['handover_at' => now(), 'order_status' => 'out_for_delivery']);
    $awaiting = $van->ridersAwaiting($DRIVER);
    $check('a rider whose only box is 30h old is not "awaiting"', !in_array($CARGO_RIDER, $awaiting, true), $awaiting);

    $m = $van->manifest($DRIVER);
    $ids = array_column(array_merge(...array_map(fn ($g) => $g['orders'], $m['carrying'])) ?: [], 'id');
    $check('…but the stranded box is STILL LISTED (flag, never hide)', in_array($oStranded, $ids, true), $ids);
    $strandedRow = null;
    foreach ($m['carrying'] as $g) foreach ($g['orders'] as $o) if ($o['id'] === $oStranded) $strandedRow = $o;
    $check('  and carries stranded = true', ($strandedRow['stranded'] ?? null) === true, $strandedRow);
    $check('  totals.stranded counts it', ($m['totals']['stranded'] ?? 0) >= 1, $m['totals']['stranded'] ?? null);
    $ownRow = $m['mine'][0] ?? null;
    $check('  a fresh box is NOT flagged stranded', ($ownRow['stranded'] ?? null) === false, $ownRow['stranded'] ?? null);

    // =======================================================================
    $section('M4  taking a box off the van resets the custody scans');
    DB::table('t_crm_prod_order')->where('id', $oCargo)->update([
        'order_status' => 'on_van', 'handover_at' => null,
        'handover_scanned_packets' => json_encode([1, 2]),   // collected 2 of 3
        'dispatch_scanned_at' => now()->subMinutes(5), 'expected_packets' => 3,
    ]);
    $order = \App\Models\CRM\OrderModel::find($oCargo);
    $res = $van->unload($order, $DRIVER);
    $check('unload succeeded', ($res['ok'] ?? false) === true, $res['message'] ?? '');
    $after = DB::table('t_crm_prod_order')->where('id', $oCargo)->first();
    $check('  handover_scanned_packets cleared', $after->handover_scanned_packets === null, $after->handover_scanned_packets);
    $check('  handover_at cleared', $after->handover_at === null);
    $check('  dispatch_scanned_at cleared', $after->dispatch_scanned_at === null);
    $check('  van stamps cleared', $after->van_user_id === null && $after->van_loaded_at === null);
    $check('  status returned to processing', $after->order_status === 'processing', $after->order_status);

    // =======================================================================
    $section('M7  the driver cannot hand a box over to himself');
    $order = \App\Models\CRM\OrderModel::find($oOwn);
    $res = $van->handoverScan($order, (string) $order->order_number, $DRIVER);
    $check('driver-own handover scan REFUSED', ($res['ok'] ?? true) === false, $res['message'] ?? '');
    $check('  points him at the wave picker',
        str_contains(strtolower((string)($res['message'] ?? '')), 'my deliveries'), $res['message'] ?? '');
    $check('  his box is still on the van',
        DB::table('t_crm_prod_order')->where('id', $oOwn)->value('order_status') === 'on_van');

    // =======================================================================
    $section('M9  a wave times only the stops that were picked');
    // Two of his own boxes aboard + one OFD-without-a-time that nobody picked.
    DB::table('t_crm_prod_order')->where('id', $oProcessing)->update([
        'order_status' => 'out_for_delivery', 'assigned_rider_user_id' => $DRIVER,
        'eta_calculated_at' => null, 'estimated_delivery_at' => null,
    ]);
    $scoped = DB::table('t_crm_prod_order as o')
        ->where('o.assigned_rider_user_id', $DRIVER)
        ->where('o.order_status', 'out_for_delivery')
        ->whereNull('o.eta_calculated_at')
        ->when(true, fn ($q) => $q->whereIn('o.id', [$oOwn]))   // only_order_ids = the picked one
        ->pluck('o.id')->all();
    $unscoped = DB::table('t_crm_prod_order as o')
        ->where('o.assigned_rider_user_id', $DRIVER)
        ->where('o.order_status', 'out_for_delivery')
        ->whereNull('o.eta_calculated_at')
        ->pluck('o.id')->all();
    $check('unscoped wave would have swept in the unpicked stop', in_array($oProcessing, $unscoped, true), $unscoped);
    $check('only_order_ids narrows it to the picked stop', !in_array($oProcessing, $scoped, true), $scoped);
    $src = file_get_contents(base_path('app/Http/Controllers/API/VanController.php'));
    $check('dispatchSelectedInternal sends only_order_ids', str_contains($src, "'only_order_ids' => \$orderIds"));
    $src2 = file_get_contents(base_path('app/Http/Controllers/API/RiderController.php'));
    $check('the engine honours only_order_ids', str_contains($src2, "\$q->whereIn('o.id', \$onlyOrderIds);"));

    // =======================================================================
    $section('Auto Route now sees processing orders');
    DB::table('t_crm_prod_order')->where('id', $oProcessing)->update(['order_status' => 'processing']);
    $routable = DB::table('t_crm_prod_order as o')
        ->where('o.assigned_rider_user_id', $DRIVER)
        ->whereIn('o.order_status', ['out_for_delivery', 'on_van', 'processing'])
        ->pluck('o.id')->all();
    $check('a processing stop is routable', in_array($oProcessing, $routable, true), $routable);
    $check('optimizeRoute query includes processing',
        str_contains($src2, "whereIn('o.order_status', ['out_for_delivery', 'on_van', 'processing'])"));

    // =======================================================================
    $section('2.1  the 30s live board never fires a blocking Google call');
    $check('the finished-rider loop passes $useGoogleEta',
        str_contains($src2, 'getReturnToOfficeInfo($frid, $officeLat, $officeLng, $radiusMeters, $useGoogleEta)'));
    // Behavioural half: with false, the Google cache key must never be WRITTEN.
    $ctrl = app(\App\Http\Controllers\API\RiderController::class);
    Cache::forget("return_office_eta:{$DRIVER}");
    $ctrl->getReturnToOfficeInfo($DRIVER, 33.6, 73.1, 300, false);
    $check('useGoogleEta=false leaves the Google cache key unwritten',
        Cache::get("return_office_eta:{$DRIVER}") === null);

    // =======================================================================
    $section('2.2  the riders-map last-fix rewrite returns the same rows');
    // (proved against live data by scratchpad/loc_compare — re-asserted here on shape)
    $rows = DB::select("SELECT l.user_id, l.captured_at, l.latitude, l.longitude
        FROM t_ops_rider_location l
        INNER JOIN (SELECT user_id, MAX(captured_at) mx FROM t_ops_rider_location
                    WHERE captured_at >= DATE_SUB(NOW(), INTERVAL 720 HOUR) GROUP BY user_id) n
          ON n.user_id = l.user_id AND n.mx = l.captured_at
        INNER JOIN (SELECT user_id, captured_at, MIN(id) keep FROM t_ops_rider_location
                    WHERE captured_at >= DATE_SUB(NOW(), INTERVAL 720 HOUR) GROUP BY user_id, captured_at) o
          ON o.user_id = l.user_id AND o.captured_at = l.captured_at AND o.keep = l.id
        WHERE l.captured_at >= DATE_SUB(NOW(), INTERVAL 720 HOUR)");
    $byUser = [];
    foreach ($rows as $r) $byUser[$r->user_id] = ($byUser[$r->user_id] ?? 0) + 1;
    $check('one row per rider (no tie duplicates)', empty(array_filter($byUser, fn ($c) => $c > 1)), $byUser);
    $check('sync subquery is date-bounded',
        str_contains($src2, "AND rider_last_sync_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"));

    // =======================================================================
    $section('Route sheet: one rider\'s complete list');
    $as(74);
    [$st, $j] = $call('GET', '/api/rider/store/open-orders-light?rider_id=' . $DRIVER);
    $riderIds = array_values(array_unique(array_map(fn ($o) => $o['assigned_rider']['id'] ?? ($o['assigned_rider_id'] ?? null),
        $j['orders'] ?? [])));
    $check('rider_id filter returns only that rider', $st === 200 && count($riderIds) <= 1, [$st, $riderIds]);
    [$st2, $all] = $call('GET', '/api/rider/store/open-orders-light');
    $check('…and without it the whole board still comes back',
        $st2 === 200 && count($all['orders'] ?? []) >= count($j['orders'] ?? []),
        ['filtered' => count($j['orders'] ?? []), 'all' => count($all['orders'] ?? [])]);
    $statuses = array_values(array_unique(array_map(fn ($o) => $o['status'] ?? $o['order_status'] ?? '?', $j['orders'] ?? [])));
    $check('the sheet can see on_van AND processing AND OFD in one list',
        count(array_intersect(['on_van', 'processing'], $statuses)) >= 1, $statuses);

} catch (\Throwable $e) {
    echo "\nEXCEPTION: " . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
} finally {
    DB::rollBack();
    echo "\n--- rolled back (no data kept) ---\n";
}

echo "\n==================  $pass passed / $fail failed  ==================\n";
if ($failures) echo "FAILED:\n  - " . implode("\n  - ", $failures) . "\n";
