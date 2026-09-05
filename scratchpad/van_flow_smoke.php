<?php
// 🚚 Van handover natural-flow smoke (Sep-2026). Runs the REAL endpoints in-process
// as Arslan (u77) and Rajab (u95) inside ONE transaction, then rolls back.
// Run: php artisan tinker --execute='require base_path("scratchpad/van_flow_smoke.php");'
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

$RIDER = 77; $DRIVER = 95; $ORDER = 16732; // SH-22585 — the Sep-3 box
$pass = 0; $fail = 0;
$check = function (string $name, bool $ok, $detail = '') use (&$pass, &$fail) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'OK  ' : 'BAD ') . $name . ($detail !== '' ? '  → ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
};
$as = function (int $uid) {
    $u = \App\Models\User::find($uid) ?? \App\Models\CRM\UserModel::find($uid);
    Sanctum::actingAs($u, ['*']);
    auth()->setUser($u);
    return $u;
};
$call = function (string $method, string $uri, array $body = []) {
    $req = Request::create($uri, $method, $body, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $res = app()->handle($req);
    $j = json_decode($res->getContent(), true);
    return [$res->getStatusCode(), $j];
};

DB::beginTransaction();
try {
    // Put SH-22585 back to the 19:27 shape: loaded on Rajab's van, uncollected.
    DB::table('t_crm_prod_order')->where('id', $ORDER)->update([
        'order_status' => 'on_van', 'assigned_rider_user_id' => $RIDER,
        'van_user_id' => $DRIVER, 'van_loaded_at' => now()->subMinutes(40), 'van_loaded_by' => 74,
        'van_loaded_packets' => json_encode([1]), 'handover_at' => null, 'handover_scanned_by' => null,
        'handover_scanned_packets' => null, 'delivery_scanned_at' => now()->subMinutes(2),
        'delivery_scanned_by' => $RIDER, 'delivery_scanned_packets' => json_encode([1]),
        'handover_help_note' => null, 'expected_packets' => 1,
    ]);
    // A stop for the driver, not yet reached.
    $stops = app(\App\Services\Riders\VanStopService::class);
    $van   = app(\App\Services\Riders\VanService::class);
    $trip  = $van->openTrip($DRIVER) ?: null;
    $set = $stops->setStop($DRIVER, ['name' => 'Smoke point', 'latitude' => 33.6, 'longitude' => 73.1], null, true);
    $check('stop set for driver', (bool) ($set['ok'] ?? false), $set['message'] ?? '');

    $as($RIDER);
    [$st, $j] = $call('GET', "/api/rider/orders/$ORDER");
    $vanBlock = $j['order']['van'] ?? null;
    $check('order-details carries van block', $st === 200 && is_array($vanBlock), $vanBlock);
    $check('  needs_handover = true', ($vanBlock['needs_handover'] ?? null) === true);
    $check('  driver name = Rajab', str_contains((string) ($vanBlock['driver_name'] ?? ''), 'Rajab'));
    $check('  stop rides along (label + coords)', !empty($vanBlock['stop']['label']) && ($vanBlock['stop']['latitude'] ?? null) == 33.6);

    [$st, $j] = $call('GET', "/api/rider/orders/$ORDER/live-flags");
    $check('live-flags carries van.needs_handover', $st === 200 && (($j['flags']['van']['needs_handover'] ?? null) === true));

    [$st, $j] = $call('POST', "/api/rider/orders/$ORDER/delivery-scan-mark", ['scanned_count' => 1, 'scan_code' => 'SH-22585|1/1']);
    $check('delivery-scan-mark refused on an on-van box (422 on_van)', $st === 422 && ($j['code'] ?? '') === 'on_van', $j['message'] ?? '');

    [$st, $j] = $call('POST', "/api/rider/orders/$ORDER/mark-delivered", ['scan_code' => 'SH-22585|1/1', 'scanned_indices' => [1], 'scanned_at' => now()->subMinutes(2)->getTimestampMs()]);
    $check('mark-delivered refused while on van, Roman-Urdu, names the order-page door', $st === 422 && str_contains($j['message'] ?? '', 'handover scan karein'), $j['message'] ?? '');

    [$st, $j] = $call('POST', "/api/rider/van/orders/$ORDER/handover-help", ['reason' => 'label phata hua']);
    $note = DB::table('t_crm_prod_order')->where('id', $ORDER)->value('handover_help_note');
    $check('handover-help accepted + note stored', $st === 200 && is_string($note) && str_contains($note, 'label phata hua'), $note);

    // Store panel shows the note under the uncollected row + can_manage for Farooq
    $as(74);
    [$st, $j] = $call('GET', '/api/rider/van/store-panel');
    $row = null;
    foreach (($j['vans'] ?? []) as $v) foreach (($v['carrying'] ?? []) as $g) foreach (($g['orders'] ?? []) as $o) if ((int) $o['id'] === $ORDER) $row = $o;
    $check('store-panel row carries help_note', $row && str_contains((string) ($row['help_note'] ?? ''), 'label phata hua'), $row['help_note'] ?? null);
    $check('store-panel can_manage for Farooq', ($j['can_manage'] ?? null) === true);

    // Driver arrives → riders owed a scan are told ONCE.
    $as($DRIVER);
    [$st, $j] = $call('POST', '/api/rider/van/stops/reached', ['latitude' => 33.6, 'longitude' => 73.1]);
    $stopRow = $stops->currentStop($DRIVER);
    $check('reached stop', $st === 200 && !empty($stopRow->reached_at));
    $check('arrival_notified_at latched once', !empty($stopRow->arrival_notified_at), $stopRow->arrival_notified_at ?? null);
    [$st2, $j2] = $call('POST', '/api/rider/van/stops/reached', []);
    $check('second "I\'m here" is idempotent (no second claim)', $st2 === 200);

    // Late handover scan from the ORDER PAGE, away from the van.
    $as($RIDER);
    [$st, $j] = $call('POST', "/api/rider/van/orders/$ORDER/handover-scan", ['scan_code' => 'SH-22585|1/1', 'source' => 'order_page', 'near_van' => false, 'latitude' => 33.53, 'longitude' => 73.17]);
    $o = DB::table('t_crm_prod_order')->where('id', $ORDER)->first();
    $hist = DB::table('t_crm_order_status_history')->where('order_id', $ORDER)->orderByDesc('id')->first();
    $check('order-page handover scan accepted', $st === 200 && ($j['complete'] ?? false) === true, $j['message'] ?? '');
    $check('  status → OFD with "late scan" note', $o->order_status === 'out_for_delivery' && str_contains((string) $hist->notes, 'late scan'), $hist->notes);
    $check('  pre-handover delivery scan stamp CLEARED', $o->delivery_scanned_at === null);
    $check('  help note cleared by the scan', $o->handover_help_note === null);

    [$st, $j] = $call('GET', "/api/rider/orders/$ORDER");
    $check('order-details now needs_handover = false', ($j['order']['van']['needs_handover'] ?? null) === false);

    // A saved scan captured BEFORE the handover must be refused as scan_required.
    [$st, $j] = $call('POST', "/api/rider/orders/$ORDER/mark-delivered", ['scan_code' => 'SH-22585|1/1', 'scanned_indices' => [1], 'scanned_at' => now()->subMinutes(30)->getTimestampMs()]);
    $check('mark-delivered with a PRE-handover scan → 422 scan_required', $st === 422 && ($j['code'] ?? '') === 'scan_required', $j['message'] ?? '');

    // Old APK: no scanned_at → rule inert (must get past the scan gate; we only assert it is not the pre-handover refusal).
    [$st, $j] = $call('POST', "/api/rider/orders/$ORDER/mark-delivered", ['scan_code' => 'WRONG-1|1/1']);
    $check('old APK shape is not judged on scanned_at (wrong-package 422 is the ordinary gate)', $st === 422 && !str_contains($j['message'] ?? '', 'van par hua tha'), $j['message'] ?? '');

    // Manager override on a fresh uncollected box: reset and override as Farooq.
    DB::table('t_crm_prod_order')->where('id', $ORDER)->update(['order_status' => 'on_van', 'handover_at' => null, 'handover_help_note' => '19:00 — phone band']);
    $as(74);
    [$st, $j] = $call('POST', "/api/rider/van/orders/$ORDER/handover-override", ['reason' => 'phone band']);
    $o = DB::table('t_crm_prod_order')->where('id', $ORDER)->first();
    $check('manager no-scan override → OFD, handover stamped, note cleared', $st === 200 && $o->order_status === 'out_for_delivery' && $o->handover_at && $o->handover_help_note === null);

    // Unload door.
    DB::table('t_crm_prod_order')->where('id', $ORDER)->update(['order_status' => 'on_van', 'handover_at' => null, 'handover_help_note' => 'x']);
    [$st, $j] = $call('POST', "/api/rider/van/orders/$ORDER/unload", []);
    $o = DB::table('t_crm_prod_order')->where('id', $ORDER)->first();
    $check('unload → processing, pointers + note cleared', $st === 200 && $o->order_status === 'processing' && $o->van_user_id === null && $o->handover_help_note === null);

    // Rider may NOT override/unload (assign_riders gate).
    $as($RIDER);
    [$st] = $call('POST', "/api/rider/van/orders/$ORDER/handover-override", ['reason' => 'x']);
    $check('rider cannot use the override door (403)', $st === 403);
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
} finally {
    DB::rollBack();
}
echo "PASS=$pass FAIL=$fail (transaction rolled back)\n";
