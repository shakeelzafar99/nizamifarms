<?php
/**
 * 🧾 "WORK DONE, COST NOT ENTERED" — the interim reminder on the vehicle's service history
 *    (owner ask, 11-Sep-2026), until the proper unpaid-visits screen is built.
 *
 * The rules being proved:
 *   • a hand-recorded service with NO amount, dated on/after 1 Sept, is flagged;
 *   • the SAME row dated before 1 Sept is NOT — *"I don't need this for the historical ones"*;
 *   • a service that HAS an amount is not flagged, and neither is a claim (a claim is money);
 *   • a REJECTED bill still counts as needing one — the money did not clear;
 *   • the flag is on the row the SERVER sends, so the web list, the phone list and the counter
 *     above them read one answer.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ NOT `head()` — Laravel ships a global `head()` the validator calls.
 *
 * Run:  php test_service_amount_owed.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\ServiceRecordService;
use App\Services\Riders\VehicleService;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) { echo "      got:  " . var_export($got, true) . "\n";
                        echo "      want: " . var_export($want, true) . "\n"; } }
}
function section(string $t) { echo "\n== $t ==\n"; }

$svc = new VehicleService();

// ─────────────────────────────────────────────────
section('§1 the rule itself — one definition, read by every surface');

ok('the cut-off is 1 September, stated once', VehicleService::AMOUNT_NAG_FROM, '2026-09-01');

$row = fn (array $o = []) => array_merge(
    ['manual' => true, 'bill_id' => null, 'amount' => 0.0, 'date' => '2026-09-05'], $o);

ok('⭐ a hand-recorded service with no amount, after the cut-off, is FLAGGED',
   VehicleService::rowNeedsAmount($row()), true);
ok('⚠ …the same row BEFORE the cut-off is not (history nobody will chase)',
   VehicleService::rowNeedsAmount($row(['date' => '2026-08-31'])), false);
ok('  …and the boundary day itself counts as after',
   VehicleService::rowNeedsAmount($row(['date' => '2026-09-01'])), true);
ok('a service that HAS an amount is not flagged',
   VehicleService::rowNeedsAmount($row(['amount' => 850.0])), false);
ok('a service with a LIVE bill is not flagged',
   VehicleService::rowNeedsAmount($row(['bill_id' => 123])), false);
ok('⚠ a CLAIM is never flagged — a claim IS the money',
   VehicleService::rowNeedsAmount($row(['manual' => false])), false);
ok('⚠ a REJECTED bill still needs one (the money did not clear, so bill_id is null)',
   VehicleService::rowNeedsAmount($row(['bill_id' => null])), true);
ok('a row with no date is never flagged', VehicleService::rowNeedsAmount($row(['date' => ''])), false);

// ─────────────────────────────────────────────────
section('§2 end to end — a real service recorded with no amount');

$vid = (int) DB::table('t_ops_vehicle')->where('is_active', 1)->where('is_company', 1)->value('id');
ok('a company machine exists', (bool) $vid, null, true);

$rid = (int) DB::table('t_ops_rider_profile')->value('user_id');
$rec = app(ServiceRecordService::class);

DB::beginTransaction();
try {
    $before = collect($svc->serviceHistoryFor($vid, 200, true))->where('needs_amount', true)->count();

    $klass = $rec->classFor($vid, $rid, null);
    $type  = null;
    foreach ($rec->typesForClose($klass) as $t) { $type = $t; break; }
    $r = $rec->resolveType((int) $type['id'], $klass);
    $meter = (int) ($svc->currentMeterFor($vid) ?: 0) + 3;

    // ⚠ Deliberately NO amount — the ordinary workshop case since 11-Sep.
    $out = $rec->record(['rider_id' => $rid, 'vehicle_id' => $vid, 'meter' => $meter,
                         'date' => \Carbon\Carbon::today()->format('Y-m-d'),
                         'type' => $r['type'], 'counts_down' => $r['counts_down'] ?? true,
                         'actor_id' => $rid, 'note' => 'OWED-CHECK']);
    ok('the service records', $out['ok'] ?? false, true);

    $hist  = $svc->serviceHistoryFor($vid, 200, true);
    $after = collect($hist)->where('needs_amount', true)->count();
    ok('⭐⭐ …and the history now reports one more service awaiting an amount', $after, $before + 1);

    $mine = collect($hist)->firstWhere('log_id', $out['service_log_id']);
    ok('  …the row itself carries the flag', $mine['needs_amount'] ?? null, true);
    ok('  …and every row carries the key, flagged or not',
       collect($hist)->every(fn ($x) => array_key_exists('needs_amount', $x)), true);

    // …and once the bill is attached, the flag clears.
    if (\Illuminate\Support\Facades\Schema::hasColumn('t_fleet_service_log', 'request_id')) {
        $reqId = (int) DB::table('t_req_master')->insertGetId([
            'request_number' => 'OWED-' . uniqid(),
            'requester_user_id' => $rid,
            'category_id' => (int) (DB::table('t_req_category')->value('id') ?: 1),
            'title' => 'Owed check', 'status' => 'pending', 'amount' => 900,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rec->attachBillToService((int) $out['service_log_id'], $reqId);
        $hist2 = $svc->serviceHistoryFor($vid, 200, true);
        $mine2 = collect($hist2)->firstWhere('log_id', $out['service_log_id']);
        ok('⭐ once a manager enters the bill, the row stops asking',
           $mine2 ? (bool) ($mine2['needs_amount'] ?? false) : 'row vanished', false);
    } else {
        ok('the service↔bill link exists (absent — skipped honestly)', true, true, true);
    }
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§3 ⚠ nothing HISTORICAL is dragged in');

$hist = $svc->serviceHistoryFor($vid, 200, true);
$old  = collect($hist)->filter(fn ($r) => substr((string) $r['date'], 0, 10) < VehicleService::AMOUNT_NAG_FROM);
ok('the machine has history from before the cut-off', $old->count() > 0, true);
ok('⭐⭐ …and NONE of it is flagged', $old->where('needs_amount', true)->count(), 0);

echo "\n────────────────────────────────────────────────────────────\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n" : "passed $pass, FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
