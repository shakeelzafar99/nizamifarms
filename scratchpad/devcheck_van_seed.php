<?php
/**
 * DEVCHECK seed/restore for the 14-Sep device walkthrough.
 *
 * ⚠ Writes to the LOCAL replica only (nizamifarms_db on localhost) — never prod.
 * ⚠ Uses DIRECT DB updates, never changeStatus(): the real transition emits customer-app
 *   webhooks (CUSTOMER_APP_WEBHOOKS_ENABLED=true) and touches inventory. We only need the
 *   rows to LOOK like a van day for the UI.
 * ⚠ Snapshots every row it touches to scratchpad/devcheck_van_snapshot.json and restores
 *   from that file exactly — the Aug rule: restore by the same predicate you wrote by.
 *
 * Run:  php artisan tinker --execute='$MODE="seed"; require base_path("scratchpad/devcheck_van_seed.php");'
 *       php artisan tinker --execute='$MODE="restore"; require base_path("scratchpad/devcheck_van_seed.php");'
 */
use Illuminate\Support\Facades\DB;

$MODE = $MODE ?? 'status';
$SNAP = base_path('scratchpad/devcheck_van_snapshot.json');
$DRIVER = 95;   // Rajab
$CARGO  = 77;   // Arslan Aslam
$VAN    = 4;    // CAD-2958

$COLS = ['id','order_status','assigned_rider_user_id','van_user_id','van_vehicle_id','van_trip_id',
         'van_loaded_at','van_loaded_by','van_loaded_packets','handover_at','handover_scanned_by',
         'handover_scanned_packets','delivery_priority','eta_calculated_at','estimated_delivery_at',
         'expected_packets'];

if ($MODE === 'seed') {
    if (file_exists($SNAP)) { echo "A snapshot already exists — restore first.\n"; return; }

    // Pick real open orders: 2 of Rajab's own + 2 of Arslan's.
    $mine = DB::table('t_crm_prod_order')->where('assigned_rider_user_id', $DRIVER)
        ->whereNotIn('order_status', ['delivered','completed','cancelled','refunded'])
        ->orderByDesc('id')->limit(2)->pluck('id')->all();
    $cargo = DB::table('t_crm_prod_order')->where('assigned_rider_user_id', $CARGO)
        ->whereNotIn('order_status', ['delivered','completed','cancelled','refunded'])
        ->orderByDesc('id')->limit(2)->pluck('id')->all();

    // Fall back to any open orders if a rider has none today.
    if (count($mine) < 2 || count($cargo) < 2) {
        $spare = DB::table('t_crm_prod_order')
            ->whereNotIn('order_status', ['delivered','completed','cancelled','refunded'])
            ->orderByDesc('id')->limit(6)->pluck('id')->all();
        while (count($mine) < 2 && $spare)  { $mine[]  = array_shift($spare); }
        while (count($cargo) < 2 && $spare) { $cargo[] = array_shift($spare); }
    }
    $ids = array_values(array_unique(array_merge($mine, $cargo)));

    $snap = [
        'orders'      => DB::table('t_crm_prod_order')->whereIn('id', $ids)->get($COLS)->map(fn($r)=>(array)$r)->all(),
        'assign_ids'  => [],
        'trip_ids'    => [],
        'handover_ids'=> [],
    ];

    $assignId = DB::table('t_ops_vehicle_assignment')->insertGetId([
        'vehicle_id' => $VAN, 'user_id' => $DRIVER,
        'assigned_on' => today()->format('Y-m-d'), 'released_on' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $snap['assign_ids'][] = $assignId;

    $base = ['van_user_id' => $DRIVER, 'van_vehicle_id' => $VAN, 'van_trip_id' => null,
             'van_loaded_by' => $DRIVER, 'van_loaded_packets' => json_encode([1]),
             'expected_packets' => 1, 'handover_at' => null, 'handover_scanned_by' => null,
             'handover_scanned_packets' => null, 'eta_calculated_at' => null,
             'estimated_delivery_at' => null];

    foreach ($mine as $i => $oid) {
        DB::table('t_crm_prod_order')->where('id', $oid)->update($base + [
            'order_status' => 'on_van', 'assigned_rider_user_id' => $DRIVER,
            'van_loaded_at' => now()->subMinutes(40 - $i), 'delivery_priority' => $i + 1,
        ]);
    }
    foreach ($cargo as $i => $oid) {
        DB::table('t_crm_prod_order')->where('id', $oid)->update($base + [
            'order_status' => 'on_van', 'assigned_rider_user_id' => $CARGO,
            'van_loaded_at' => now()->subMinutes(35 - $i), 'delivery_priority' => $i + 1,
        ]);
    }

    file_put_contents($SNAP, json_encode($snap, JSON_PRETTY_PRINT));
    echo "SEEDED. driver=u{$DRIVER} cargo_rider=u{$CARGO}\n";
    echo "  his own : " . implode(', ', $mine) . "\n";
    echo "  cargo   : " . implode(', ', $cargo) . "\n";
    echo "  snapshot: {$SNAP}\n";
    return;
}

if ($MODE === 'restore') {
    if (!file_exists($SNAP)) { echo "No snapshot to restore from.\n"; return; }
    $snap = json_decode(file_get_contents($SNAP), true);

    foreach ($snap['orders'] as $row) {
        $id = $row['id']; unset($row['id']);
        DB::table('t_crm_prod_order')->where('id', $id)->update($row);
    }
    // Anything the WALKTHROUGH itself created for this driver, by the same predicate.
    $trips = DB::table('t_ops_van_trip')->where('van_user_id', $DRIVER)
        ->whereDate('trip_date', today()->format('Y-m-d'))->pluck('id')->all();
    if ($trips) {
        DB::table('t_ops_van_handover')->whereIn('trip_id', $trips)->delete();
        DB::table('t_ops_van_trip')->whereIn('id', $trips)->delete();
    }
    DB::table('t_ops_van_handover')->where('van_user_id', $DRIVER)
        ->whereDate('created_at', today()->format('Y-m-d'))->delete();
    DB::table('t_ops_vehicle_assignment')->whereIn('id', $snap['assign_ids'])->delete();

    unlink($SNAP);
    echo "RESTORED " . count($snap['orders']) . " orders; removed "
       . count($trips) . " trip(s) and the seeded assignment.\n";
    return;
}

// status
echo "van drivers today: " . json_encode(app(\App\Services\Riders\VanService::class)->todaysDrivers()) . "\n";
echo "orders on_van    : " . DB::table('t_crm_prod_order')->where('order_status','on_van')->count() . "\n";
echo "open trips       : " . DB::table('t_ops_van_trip')->whereNull('ended_at')->count() . "\n";
echo "snapshot present : " . (file_exists($SNAP) ? 'YES' : 'no') . "\n";
