<?php
/**
 * Snapshot overpayForOrder's FULL answer for every order that carries at least one
 * payment signal — including bulk-linked ones — so a change to the collapse rule can
 * be diffed order-by-order instead of trusted.
 *
 * READ-ONLY. Run:  php probe_overpay_snapshot.php <outfile.json>
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = $argv[1] ?? null;
if (!$out) { fwrite(STDERR, "usage: php probe_overpay_snapshot.php <outfile.json>\n"); exit(1); }

$pc = new \App\Http\Controllers\FIN\PaymentSignalsController();
$m  = new ReflectionMethod($pc, 'overpayForOrder');
$m->setAccessible(true);

// Every order any signal points at — direct match OR bulk link.
$direct = DB::table('t_fin_payment_signal')->whereNotNull('matched_order_id')
    ->whereNotIn('status', ['rejected'])->distinct()->pluck('matched_order_id');
$linked = DB::table('t_fin_payment_signal_order')->distinct()->pluck('order_id');
$ids = $direct->merge($linked)->unique()->sort()->values();

$snap = [];
foreach ($ids as $oid) {
    try {
        $i = $m->invoke($pc, (int) $oid);
    } catch (\Throwable $e) {
        $snap[$oid] = ['error' => $e->getMessage()];
        continue;
    }
    $snap[$oid] = [
        'amount' => $i['amount'], 'eligible' => $i['eligible'],
        'tip_eligible' => $i['tip_eligible'], 'claimed' => $i['claimed'],
        'owed' => $i['owed'], 'banked' => $i['banked'],
        'signal_count' => $i['signal_count'], 'signal_id' => $i['signal_id'],
        'reason' => $i['reason'], 'tip_reason' => $i['tip_reason'],
    ];
}
file_put_contents($out, json_encode($snap, JSON_PRETTY_PRINT));
echo "orders snapshotted: " . count($snap) . " -> $out\n";
$bulkOrders = DB::table('t_fin_payment_signal_order')->distinct()->count('order_id');
echo "of which bulk-linked (combined transfers): $bulkOrders\n";
