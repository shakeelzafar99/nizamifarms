<?php
/**
 * SIMULATION (read-only, changes NO code and NO data): what would the reported
 * overpayments become if the collapse also treated "same transaction reference AND
 * same amount" as one payment, in addition to the paired_signal_id link?
 *
 * The live rule collapses ONLY via paired_signal_id, so a third channel reporting the
 * same transfer (or a same-channel duplicate) is counted as a second payment.
 *
 * Run:  php probe_overpay_fix_sim.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$pc  = new \App\Http\Controllers\FIN\PaymentSignalsController();
$mOv = new ReflectionMethod($pc, 'overpayForOrder'); $mOv->setAccessible(true);
$mSg = new ReflectionMethod($pc, 'orderSignals');    $mSg->setAccessible(true);

const BANKISH = ['email', 'bank_sms'];

/** Collapse exactly as production does today: the pair link, and nothing else. */
function collapseLive($signals): array {
    $kept = [];
    foreach ($signals as $s) {
        $mate = $s->paired_signal_id ? (int) $s->paired_signal_id : null;
        $key  = $mate ? min((int)$s->id,$mate) . '-' . max((int)$s->id,$mate) : 'solo-' . $s->id;
        if (!isset($kept[$key])) { $kept[$key] = $s; continue; }
        if (in_array($s->source, BANKISH, true) && !in_array($kept[$key]->source, BANKISH, true)) {
            $kept[$key] = $s;
        }
    }
    return $kept;
}

/**
 * Proposed: the pair link OR (same transaction reference AND same amount).
 *
 * ⚠ The reference must be read from the WHOLE pair, not from the row the pair-collapse
 *   happened to keep. The live collapse prefers the bank-side row, and on this data that
 *   row is often the one with a NULL reference — so reading the ref off the survivor
 *   alone misses the duplicate (it corrected only 4 of the 9 real cases).
 */
function collapseProposed($signals): array {
    // 1. group by the pair link, keeping every member so no evidence is lost
    $groups = [];
    foreach ($signals as $s) {
        $mate = $s->paired_signal_id ? (int) $s->paired_signal_id : null;
        $key  = $mate ? min((int)$s->id,$mate) . '-' . max((int)$s->id,$mate) : 'solo-' . $s->id;
        $groups[$key][] = $s;
    }

    // 2. one representative per group (bank-side preferred) + the group's best reference
    $reps = [];
    foreach ($groups as $members) {
        $rep = $members[0]; $ref = '';
        foreach ($members as $m) {
            if (in_array($m->source, BANKISH, true) && !in_array($rep->source, BANKISH, true)) {
                $rep = $m;
            }
            if ($ref === '') { $ref = trim((string) $m->extracted_ref); }
        }
        $reps[] = ['sig' => $rep, 'ref' => $ref];
    }

    // 3. two groups describing the same transfer collapse into one.
    //    Amount is part of the key so a short/truncated reference cannot merge two
    //    genuinely different payments.
    $out = [];
    foreach ($reps as $r) {
        $amt = number_format((float) $r['sig']->extracted_amount, 2, '.', '');
        $key = $r['ref'] !== '' ? 'ref:' . strtoupper($r['ref']) . '@' . $amt : 'id:' . $r['sig']->id;
        if (!isset($out[$key])) { $out[$key] = $r['sig']; continue; }
        if (in_array($r['sig']->source, BANKISH, true) && !in_array($out[$key]->source, BANKISH, true)) {
            $out[$key] = $r['sig'];
        }
    }
    return $out;
}

$orderIds = DB::table('t_fin_payment_signal')
    ->whereNotNull('matched_order_id')->whereNotIn('status', ['rejected'])
    ->groupBy('matched_order_id')->havingRaw('COUNT(*) > 1')->pluck('matched_order_id');

$changed = []; $unchanged = 0; $invented = [];
foreach ($orderIds as $oid) {
    $sigs = $mSg->invoke($pc, (int) $oid);
    if ($sigs->isEmpty()) { continue; }

    // Mirror the exclusions the real method applies before collapsing.
    $bulk = DB::table('t_fin_payment_signal_order')->whereIn('signal_id', $sigs->pluck('id')->all())
        ->pluck('signal_id')->flip();
    $sigs = $sigs->reject(fn ($s) => $bulk->has($s->id)
        || (method_exists($s, 'isGuess') && $s->isGuess()))->values();
    if ($sigs->isEmpty()) { continue; }

    $ord  = DB::table('t_crm_prod_order')->where('id', $oid)->first(['order_number','total_price','total_paid']);
    $owed = round((float)$ord->total_price - (float)($ord->total_paid ?? 0), 2);

    $cl = 0.0; foreach (collapseLive($sigs)     as $s) { $cl += (float) $s->extracted_amount; }
    $cp = 0.0; foreach (collapseProposed($sigs) as $s) { $cp += (float) $s->extracted_amount; }

    $banked = 0.0;
    if (\Schema::hasTable('t_crm_customer_credit')) {
        $banked = round((float) DB::table('t_crm_customer_credit')->where('order_id', $oid)
            ->where('entry_type','grant')->where('source','overpayment')
            ->where('status','!=','voided')->sum('amount'), 2);
    }
    $extraLive = round($cl - $owed - $banked, 2);
    $extraProp = round($cp - $owed - $banked, 2);

    if (abs($extraLive - $extraProp) > 0.01) {
        $changed[] = ['order'=>$ord->order_number,'id'=>(int)$oid,'owed'=>$owed,
                      'live'=>$extraLive,'prop'=>$extraProp];
    } else { $unchanged++; }

    if ($extraProp > $extraLive + 0.01) { $invented[] = $ord->order_number; }
}

echo "=== orders whose reported overpayment CHANGES under the proposal ===\n";
printf("%-11s %-8s %12s %14s %14s\n", 'order','id','owed','offered NOW','offered AFTER');
$recovered = 0.0;
foreach ($changed as $c) {
    printf("%-11s #%-7d %12s %14s %14s\n", $c['order'], $c['id'],
        number_format($c['owed'],2), number_format($c['live'],2), number_format($c['prop'],2));
    $recovered += ($c['live'] - $c['prop']);
}
echo "\n" . str_repeat('-',68) . "\n";
printf("Orders corrected: %d   ·   phantom credit no longer offered: Rs %s\n",
       count($changed), number_format($recovered,2));
printf("Orders unaffected: %d\n", $unchanged);
echo count($invented) === 0
    ? "Safety: the proposal never invents a NEW overpayment. [OK]\n"
    : ("WARNING — proposal would CREATE extras on: " . implode(', ', $invented) . "\n");
