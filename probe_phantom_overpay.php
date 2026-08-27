<?php
/**
 * Scan real prod-replica data for PHANTOM overpayments — orders the approvals screen
 * would report as overpaid because ONE payment was recorded by more than one channel
 * and only two of them collapsed into a pair.
 *
 * READ-ONLY. No transaction needed — nothing is written.
 * Run:  php probe_phantom_overpay.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$pc  = new \App\Http\Controllers\FIN\PaymentSignalsController();
$m   = new ReflectionMethod($pc, 'overpayForOrder');
$m->setAccessible(true);

// Every order that carries more than one live signal — the only way an extra can appear.
$orderIds = DB::table('t_fin_payment_signal')
    ->whereNotNull('matched_order_id')
    ->whereNotIn('status', ['rejected'])
    ->groupBy('matched_order_id')
    ->havingRaw('COUNT(*) > 1')
    ->pluck('matched_order_id');

echo "Orders carrying more than one payment signal: " . count($orderIds) . "\n\n";

$phantom = []; $genuine = [];
foreach ($orderIds as $oid) {
    $info = $m->invoke($pc, (int) $oid);
    if (($info['amount'] ?? 0) <= 0.5) { continue; }

    $sigs = DB::table('t_fin_payment_signal')
        ->where('matched_order_id', $oid)->whereNotIn('status', ['rejected'])
        ->get(['id','source','extracted_amount','extracted_ref','paired_signal_id','created_at']);

    // Duplicate-by-reference: same non-empty ref on signals that are NOT paired together.
    $byRef = [];
    foreach ($sigs as $s) {
        $r = trim((string) $s->extracted_ref);
        if ($r !== '') { $byRef[$r][] = $s; }
    }
    $dupRef = false; $unpairedDup = [];
    foreach ($byRef as $r => $group) {
        if (count($group) < 2) { continue; }
        foreach ($group as $a) {
            foreach ($group as $b) {
                if ($a->id >= $b->id) { continue; }
                if ((int) $a->paired_signal_id !== (int) $b->id
                    && (int) $b->paired_signal_id !== (int) $a->id) {
                    $dupRef = true; $unpairedDup[] = "{$a->id}({$a->source}) vs {$b->id}({$b->source}) ref={$r}";
                }
            }
        }
    }

    $ord = DB::table('t_crm_prod_order')->where('id',$oid)->first(['order_number','order_status','total_price']);
    $row = [
        'order' => $ord->order_number ?? $oid, 'id' => (int) $oid,
        'status' => $ord->order_status ?? '?',
        'owed' => $info['owed'], 'claimed' => $info['claimed'], 'extra' => $info['amount'],
        'signals' => count($sigs), 'dup' => $unpairedDup,
    ];
    if ($dupRef) { $phantom[] = $row; } else { $genuine[] = $row; }
}

echo "=== PHANTOM: same payment reference counted twice without being paired ===\n";
if (!$phantom) { echo "  (none)\n"; }
foreach ($phantom as $r) {
    printf("  %-10s #%-6d %-12s owed %10s  claimed %10s  -> EXTRA %10s  (%d signals)\n",
        $r['order'], $r['id'], $r['status'], number_format($r['owed'],2),
        number_format($r['claimed'],2), number_format($r['extra'],2), $r['signals']);
    foreach ($r['dup'] as $d) { echo "             duplicate: $d\n"; }
}

echo "\n=== other reported extras (no duplicate reference — may be real) ===\n";
if (!$genuine) { echo "  (none)\n"; }
foreach (array_slice($genuine, 0, 15) as $r) {
    printf("  %-10s #%-6d %-12s owed %10s  claimed %10s  -> extra %10s  (%d signals)\n",
        $r['order'], $r['id'], $r['status'], number_format($r['owed'],2),
        number_format($r['claimed'],2), number_format($r['extra'],2), $r['signals']);
}

$totalPhantom = array_sum(array_column($phantom, 'extra'));
echo "\n" . str_repeat('-',72) . "\n";
printf("Phantom extras: %d order(s), Rs %s of credit that could be banked in error.\n",
       count($phantom), number_format($totalPhantom, 2));
printf("Other extras:   %d order(s).\n", count($genuine));

// How common is an unpaired third channel generally?
$triples = DB::table('t_fin_payment_signal')
    ->whereNotNull('matched_order_id')->whereNotIn('status',['rejected'])
    ->groupBy('matched_order_id')->havingRaw('COUNT(*) >= 3')->pluck('matched_order_id');
echo "Orders with 3+ live signals (the shape that defeats pair-collapse): " . count($triples) . "\n";
