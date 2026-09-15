<?php
/**
 * 🚚📋 THE DRIVER'S SIDE OF THE ROUTE SHEET — end to end, Sep-14-2026.
 *
 * The owner's question: once Shabib/Farooq plan the route, does the van driver SEE it in his
 * app, does he know WHO set it, does it survive a handover to another rider in between, and
 * does it carry into the wave he dispatches?
 *
 * Real endpoints, in-process, ONE transaction, rolled back. Never calls app()->terminate().
 * The dispatch engine sends no WhatsApp (verified by grep); its only push is Firebase, inert
 * on dev (missing credentials file).
 *
 * Run: php artisan tinker --execute='require base_path("scratchpad/van_driver_flow_sep14.php");'
 */
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

$STORE = 79;  // Shabib
$DRIVER = 95; // Rajab
$RIDER = 77;  // Arslan — collects cargo at the meet-up
$VAN = 4;

$pass = 0; $fail = 0; $failures = [];
$ck = function (string $n, bool $ok, $d = '') use (&$pass, &$fail, &$failures) {
    $ok ? $pass++ : $fail++; if (!$ok) $failures[] = $n;
    echo ($ok ? 'OK   ' : 'BAD  ') . $n . ($d !== '' ? '  → ' . (is_string($d) ? $d : json_encode($d)) : '') . "\n";
};
$section = fn (string $t) => print("\n=== $t\n");
$as = function (int $uid) {
    $u = \App\Models\User::find($uid) ?? \App\Models\CRM\UserModel::find($uid);
    Sanctum::actingAs($u, ['*']); auth()->setUser($u); return $u;
};
$call = function (string $m, string $uri, array $body = []) {
    $req = Request::create($uri, $m, $body, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $res = app()->handle($req);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
};
$prio = fn (int $id) => DB::table('t_crm_prod_order')->where('id', $id)->value('delivery_priority');
$status = fn (int $id) => DB::table('t_crm_prod_order')->where('id', $id)->value('order_status');
$no = fn (int $id) => DB::table('t_crm_prod_order')->where('id', $id)->value('order_number');

DB::beginTransaction();
try {
    DB::table('t_ops_vehicle_assignment')->insert(['vehicle_id' => $VAN, 'user_id' => $DRIVER,
        'assigned_on' => today()->format('Y-m-d'), 'released_on' => null, 'created_at' => now(), 'updated_at' => now()]);
    $van = app(\App\Services\Riders\VanService::class);
    $ck('seed: Rajab drives the van', $van->isVanDriver($DRIVER));

    // 3 of his own + 2 of Arslan's, all scanned aboard, priorities in id order.
    [$A, $B, $C, $D, $E] = DB::table('t_crm_prod_order')->orderByDesc('id')->limit(5)->pluck('id')->all();
    $base = ['van_user_id' => $DRIVER, 'van_vehicle_id' => $VAN, 'van_trip_id' => null, 'van_loaded_by' => $DRIVER,
             'van_loaded_packets' => json_encode([1]), 'expected_packets' => 1, 'handover_at' => null,
             'handover_scanned_by' => null, 'handover_scanned_packets' => null, 'eta_calculated_at' => null,
             'estimated_delivery_at' => null, 'order_status' => 'on_van',
             'delivery_priority_updated_by' => null, 'delivery_priority_updated_at' => null];
    foreach ([[$A, $DRIVER, 1], [$B, $DRIVER, 2], [$C, $DRIVER, 3], [$D, $RIDER, 1], [$E, $RIDER, 2]] as [$id, $r, $p]) {
        DB::table('t_crm_prod_order')->where('id', $id)->update($base + [
            'assigned_rider_user_id' => $r, 'van_loaded_at' => now()->subMinutes(30 + $p), 'delivery_priority' => $p]);
    }

    // =====================================================================
    $section('1. Shabib plans the DRIVER\'s route from the Van tab (the Route Sheet\'s save)');
    $as($STORE);
    [$st, $j] = $call('POST', '/api/rider/store/update-delivery-priorities', ['rider_id' => $DRIVER,
        'priorities' => [['order_id' => $C, 'priority' => 1], ['order_id' => $A, 'priority' => 2], ['order_id' => $B, 'priority' => 3]]]);
    $ck('save accepted', $st === 200 && (($j['success'] ?? true) !== false), [$st, $j['message'] ?? null]);
    $ck('  C=1 A=2 B=3 in the database', $prio($C) == 1 && $prio($A) == 2 && $prio($B) == 3);
    $ck('  attributed to Shabib', (int) DB::table('t_crm_prod_order')->where('id', $C)->value('delivery_priority_updated_by') === $STORE);
    $ck('  Arslan\'s cargo untouched by the driver\'s save', $prio($D) == 1 && $prio($E) == 2);

    $section('1b. …and the CARGO rider\'s route too');
    [$st, $j] = $call('POST', '/api/rider/store/update-delivery-priorities', ['rider_id' => $RIDER,
        'priorities' => [['order_id' => $E, 'priority' => 1], ['order_id' => $D, 'priority' => 2]]]);
    $ck('cargo save accepted', $st === 200, $st);
    $ck('  E=1 D=2', $prio($E) == 1 && $prio($D) == 2);
    $ck('  the driver\'s own rows untouched by it', $prio($C) == 1 && $prio($A) == 2 && $prio($B) == 3);

    // =====================================================================
    $section('2. The DRIVER opens his app: what does the manifest say?');
    $as($DRIVER);
    [$st, $m] = $call('GET', '/api/rider/van/manifest');
    $ck('manifest 200, is_van_driver', $st === 200 && ($m['is_van_driver'] ?? false) === true);
    $mineIds = array_column($m['mine'] ?? [], 'id');
    $ck('his own stops arrive IN THE STORE\'S ORDER (C, A, B)', $mineIds === [$C, $A, $B], array_map($no, $mineIds));
    $ck('  each row carries its # (1,2,3)', array_column($m['mine'], 'priority') === [1, 2, 3]);
    $ck('  ⭐ each row says WHO set it: "Shabib"', collect($m['mine'])->every(fn ($o) => ($o['route_set_by'] ?? null) === 'Shabib'),
        array_column($m['mine'], 'route_set_by'));
    $ck('  …and WHEN', !empty($m['mine'][0]['route_set_at']), $m['mine'][0]['route_set_at'] ?? null);
    $carry = collect($m['carrying'] ?? [])->firstWhere('user_id', $RIDER);
    $ck('Arslan\'s cargo group is listed in HIS planned order (E, D)',
        $carry && array_column($carry['orders'], 'id') === [$E, $D], $carry ? array_map($no, array_column($carry['orders'], 'id')) : 'no group');
    $ck('  and names Shabib on those rows too', $carry && ($carry['orders'][0]['route_set_by'] ?? null) === 'Shabib');

    // =====================================================================
    $section('3. HANDOVER IN BETWEEN — Arslan collects his two boxes at the meet-up');
    $as($RIDER);
    foreach ([$E, $D] as $id) {
        [$st, $j] = $call('POST', "/api/rider/van/orders/{$id}/handover-scan", ['scan_code' => $no($id)]);
        $ck("collect scan of {$no($id)} accepted", $st === 200 && ($j['success'] ?? false), $j['message'] ?? $st);
    }
    $ck('both are out_for_delivery now', $status($E) === 'out_for_delivery' && $status($D) === 'out_for_delivery');
    $ck('  ⭐ his planned order SURVIVED the handover (E=1, D=2)', $prio($E) == 1 && $prio($D) == 2, [$prio($E), $prio($D)]);
    $ck('  no ETA yet — he presses Dispatch himself', DB::table('t_crm_prod_order')->whereIn('id', [$D, $E])->whereNotNull('eta_calculated_at')->count() === 0);
    // ⚠ Ordering of /rider/orders is the PHONE's job (OrdersScreen sorts every group by
    //   delivery_priority, :113-117); the server contract is that each row CARRIES the number.
    [$st, $j] = $call('GET', '/api/rider/orders');
    $list = collect($j['orders'] ?? [])->whereIn('id', [$D, $E])->keyBy('id');
    $ck('his own Orders list carries E=1, D=2 for the phone to sort on',
        (int) ($list[$E]['delivery_priority'] ?? 0) === 1 && (int) ($list[$D]['delivery_priority'] ?? 0) === 2,
        ['E' => $list[$E]['delivery_priority'] ?? null, 'D' => $list[$D]['delivery_priority'] ?? null]);
    $ck('the driver\'s own C/A/B were not touched by the handover', $prio($C) == 1 && $prio($A) == 2 && $prio($B) == 3
        && $status($C) === 'on_van');

    // =====================================================================
    $section('4. The DRIVER presses "My deliveries" → wave 1 = the first two in the store\'s order');
    $as($DRIVER);
    [$st, $m] = $call('GET', '/api/rider/van/manifest');
    $pickerOrder = array_column(array_filter($m['mine'], fn ($o) => $o['status'] === 'on_van'), 'id');
    $ck('the wave picker would list C, A, B (manifest order)', $pickerOrder === [$C, $A, $B]);
    // He ticks the first two, exactly as the picker lists them.
    [$st, $j] = $call('POST', '/api/rider/van/start-leg', ['leg' => 'deliveries', 'order_ids' => [$C, $A],
        'check_loaded' => true, 'known_order_ids' => [$C, $A, $B]]);
    $ck('start-leg accepted (200)', $st === 200, [$st, $j['message'] ?? null]);
    $ck('  C and A are out_for_delivery', $status($C) === 'out_for_delivery' && $status($A) === 'out_for_delivery', [$status($C), $status($A)]);
    $ck('  B stays parked on the van', $status($B) === 'on_van', $status($B));
    $ck('  wave 1 sequence = C then A (the store\'s order carried through)', $prio($C) == 1 && $prio($A) == 2, [$prio($C), $prio($A)]);
    $ck('  B keeps its #3 (only the picked stops were touched)', $prio($B) == 3, $prio($B));
    $engineOk = ($j['dispatch']['ok'] ?? false) === true;
    echo "     (engine timed the wave: " . ($engineOk ? 'yes' : 'no — ' . ($j['dispatch']['message'] ?? 'no GPS/office on the replica')) . ")\n";
    if ($engineOk) {
        $ck('  ETAs written for C and A', DB::table('t_crm_prod_order')->whereIn('id', [$C, $A])->whereNull('eta_calculated_at')->count() === 0);
        $ck('  C is promised BEFORE A', DB::table('t_crm_prod_order')->where('id', $C)->value('estimated_delivery_at')
            <= DB::table('t_crm_prod_order')->where('id', $A)->value('estimated_delivery_at'));
    }
    $ck('  Arslan\'s already-collected stops were NOT swept into the driver\'s wave',
        DB::table('t_crm_prod_order')->whereIn('id', [$D, $E])->whereNotNull('eta_calculated_at')->count() === 0);

    $section('5. Wave 2 — the last parked stop');
    [$st, $j] = $call('POST', '/api/rider/van/start-leg', ['leg' => 'deliveries', 'order_ids' => [$B]]);
    $ck('wave 2 accepted', $st === 200, [$st, $j['message'] ?? null]);
    $ck('  B is out_for_delivery', $status($B) === 'out_for_delivery');

    // =====================================================================
    $section('6. If the DRIVER reorders it himself, it is no longer "set by the office"');
    DB::table('t_crm_prod_order')->whereIn('id', [$A, $B, $C])->update(['order_status' => 'on_van', 'eta_calculated_at' => null]);
    [$st, $j] = $call('POST', '/api/rider/store/update-delivery-priorities', ['rider_id' => $DRIVER,
        'priorities' => [['order_id' => $A, 'priority' => 1], ['order_id' => $B, 'priority' => 2], ['order_id' => $C, 'priority' => 3]]]);
    $ck('his own reorder accepted', $st === 200, $st);
    [$st, $m] = $call('GET', '/api/rider/van/manifest');
    $ck('manifest now in HIS order (A, B, C)', array_column($m['mine'], 'id') === [$A, $B, $C]);
    $ck('  and route_set_by is NULL — his own plan is not "the office"', collect($m['mine'])->every(fn ($o) => ($o['route_set_by'] ?? null) === null),
        array_column($m['mine'], 'route_set_by'));

} catch (\Throwable $e) {
    echo "\nEXCEPTION: " . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n"; $fail++;
} finally {
    DB::rollBack();
    echo "\n--- rolled back (no data kept) ---\n";
}
echo "\n==================  $pass passed / $fail failed  ==================\n";
if ($failures) echo "FAILED:\n  - " . implode("\n  - ", $failures) . "\n";
