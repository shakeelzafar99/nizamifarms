<?php
// Replays VanService::custodyState + manualChangeBlock against the shapes from the Sep-3 run
// and the Aug-21 fixtures. Run: php artisan tinker --execute='require "scratchpad/custody_check.php";'
use App\Services\Riders\VanService;
$now = now();
$mk = function (array $o) use ($now) {
    return (object) array_merge([
        'id' => 1, 'order_number' => 'T-1', 'order_status' => 'on_van',
        'assigned_rider_user_id' => 77, 'van_user_id' => 95,
        'van_loaded_at' => $now->copy()->subHours(1)->toDateTimeString(),
        'handover_at' => null,
    ], $o);
};
$cases = [
  // name, order, expect needs_handover, expect block('delivered') !== null, expect block('out_for_delivery') !== null
  ['Arslan box on van, uncollected (SH-22585 @19:27)', $mk([]), true, true, true],
  ['same box after Collect scan (@19:28)',           $mk(['order_status'=>'out_for_delivery','handover_at'=>$now->copy()->subMinutes(5)->toDateTimeString()]), false, false, false],
  ["driver's own on_van stop",                        $mk(['assigned_rider_user_id'=>95]), false, true, true],
  ["driver's own OFD stop (wave picker)",             $mk(['assigned_rider_user_id'=>95,'order_status'=>'out_for_delivery']), false, false, false],
  ['tagged On Van, never load-scanned',               $mk(['van_loaded_at'=>null,'van_user_id'=>null]), false, false, false],
  ['stale pointers (loaded 30h ago) — on_van branch has NO freshness bound (pre-existing)', $mk(['van_loaded_at'=>$now->copy()->subHours(30)->toDateTimeString()]), false, true, true],
  ['two-hop: on_hold, loaded, uncollected → OFD',     $mk(['order_status'=>'on_hold']), false, false, true],
  ['two-hop: on_hold → delivered (deliberately open)',$mk(['order_status'=>'on_hold']), false, false, true],
  ['ordinary OFD order, no van',                      $mk(['order_status'=>'out_for_delivery','van_loaded_at'=>null,'van_user_id'=>null]), false, false, false],
];
$pass = 0; $fail = 0;
foreach ($cases as [$name, $o, $needs, $blockDel, $blockOfd]) {
    $c = VanService::custodyState($o);
    $d = VanService::manualChangeBlock($o, 'delivered') !== null;
    $f = VanService::manualChangeBlock($o, 'out_for_delivery') !== null;
    $ok = $c['needs_handover'] === $needs && $d === $blockDel && $f === $blockOfd;
    $ok ? $pass++ : $fail++;
    printf("%s %-52s needs=%d(%d) blockDel=%d(%d) blockOfd=%d(%d)\n", $ok ? 'OK ' : 'BAD', $name,
        $c['needs_handover'], $needs, $d, $blockDel, $f, $blockOfd);
}
// Wording: the rider-facing refusal must name a door that exists
$m = VanService::manualChangeBlock($mk([]), 'delivered');
echo "delivered refusal: $m\n";
echo (strpos($m, 'van panel') === false && strpos($m, 'handover scan') !== false) ? "OK  wording names real doors\n" : "BAD wording\n";
echo "PASS=$pass FAIL=$fail\n";
