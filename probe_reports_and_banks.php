<?php
/**
 * BUSINESS SCENARIO PROBE — the three remaining Aug-25..27 rounds, on the prod replica:
 *   §1 Category Report: Level-1 SALES vs PURCHASES (needs the SQL that just ran)
 *   §2 Freezer columns on the same report
 *   §3 Daily Closing "Payment Follow-ups" chase board
 *   §4 Bank attribution: the untagged-movement door
 *
 * READ-ONLY throughout.
 * Run:  php probe_reports_and_banks.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CategorySalesPurchaseService;
use App\Services\Payments\OnlineFollowUpService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0; $notes = [];
function ok(string $what, $got, $want) {
    global $pass, $fail;
    $good = $got === $want; $good ? $pass++ : $fail++;
    echo ($good ? "  [PASS] " : "  [FAIL] ") . $what . "\n";
    if (!$good) { echo "         got:  " . var_export($got, true) . "\n         want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

// ─────────────────────────────────────────────────────────────────────────────
head('§1 Category Report — sales vs purchases, Level 1');

$svc   = app(CategorySalesPurchaseService::class);
$start = Carbon::parse('2026-08-01');
$end   = Carbon::parse('2026-08-27');

$rep = $svc->report($start, $end, 'week', true, []);
ok('the report builds against real data', is_array($rep), true);
ok('  …with periods, categories and a filled grid',
   count($rep['periods'] ?? []) > 0 && count($rep['categories'] ?? []) > 0
   && count($rep['cells'] ?? []) > 0, true);

$byCat = [];
foreach ($rep['cells'] as $c) {
    $k = $c['category'];
    if (!isset($byCat[$k])) { $byCat[$k] = [0.0, 0.0, 0]; }
    $byCat[$k][0] += (float) $c['sold_rs'];
    $byCat[$k][1] += (float) $c['bought_rs'];
    $byCat[$k][2] += (int)   $c['orders'];
}
uasort($byCat, fn ($a, $b) => $b[0] <=> $a[0]);

printf("  %-14s %14s %14s %8s\n", 'category', 'sold Rs', 'bought Rs', 'orders');
$totS = 0.0; $totP = 0.0;
foreach ($byCat as $c => [$s, $p, $o]) {
    printf("  %-14s %14s %14s %8d\n", $c, number_format($s,2), number_format($p,2), $o);
    $totS += $s; $totP += $p;
}
printf("  %-14s %14s %14s\n", 'TOTAL', number_format($totS,2), number_format($totP,2));

ok('both sides carry real money', $totS > 0 && $totP > 0, true);

// ⭐ The whole point of the SQL: the purchase side must be categorised, not one lump.
$untaggedPurch = $byCat['Untagged'][1] ?? 0.0;
ok('⭐ purchases are categorised — Untagged is not the whole purchase side',
   $totP > 0 && $untaggedPurch < ($totP * 0.5), true);
printf("  Untagged share of purchases: %.1f%%\n", $totP > 0 ? ($untaggedPurch / $totP * 100) : 0);

ok('every vendor product carries a category',
   (int) DB::table('t_fin_vendor_products')->whereNull('category_level_1')->count(), 0);

$notes[] = sprintf('Aug 1-27: sold Rs %s vs bought Rs %s over %d orders; Untagged purchases Rs %s.',
    number_format($totS,2), number_format($totP,2), $rep['orders_total'] ?? 0, number_format($untaggedPurch,2));

$untaggedVendors = DB::table('t_fin_vendors')->whereNull('default_category_level_1')
    ->where('is_active', 1)->pluck('vendor_name')->all();
$notes[] = count($untaggedVendors) . ' active vendors have no default category (tag them on the Vendors page): '
         . implode(', ', array_slice($untaggedVendors, 0, 8)) . (count($untaggedVendors) > 8 ? ' …' : '');

// ─────────────────────────────────────────────────────────────────────────────
head('§2 the freezer columns');

echo "  freezer history begins: " . var_export($svc->freezerHistoryStart(), true) . "\n";
ok('the report carries a freezer block', isset($rep['freezer']), true);
$fz = $rep['freezer'] ?? [];
ok('  …keyed by category', is_array($fz) && count($fz) > 0, true);
foreach (array_slice($fz, 0, 6, true) as $cat => $v) {
    echo "    " . str_pad((string) $cat, 12) . ' ' . json_encode($v) . "\n";
}
$flow = $svc->freezerFlowByPeriod($start, $end, 'week');
ok('freezer IN/OUT answers per period', is_array($flow), true);
$notes[] = 'Freezer block covers ' . count($fz) . ' categories, flow over ' . count($flow) . ' periods.';

// ─────────────────────────────────────────────────────────────────────────────
head('§3 Daily Closing — Payment Follow-ups chase board');

$fu    = app(OnlineFollowUpService::class);
$board = $fu->build('all');
ok('the chase board builds', is_array($board), true);

printf("  window: last %s days from %s\n", $board['window_days'] ?? '?', $board['window_from'] ?? '?');
printf("  to chase: %d (Rs %s)   ·   proof already in: %d (Rs %s)   ·   settled: %d\n",
    $board['chase_count'] ?? 0, number_format($board['chase_amount'] ?? 0, 2),
    $board['proof_in_count'] ?? 0, number_format($board['proof_in_amount'] ?? 0, 2),
    $board['settled_count'] ?? 0);

ok('⭐ the chase list splits into the two tiers',
   isset($board['chase_primary'], $board['chase_secondary']), true);
printf("    primary (new customers): %d (Rs %s)\n",
    $board['chase_primary_count'] ?? 0, number_format($board['chase_primary_amount'] ?? 0, 2));
printf("    secondary (day 2-3):     %d (Rs %s)\n",
    $board['chase_secondary_count'] ?? 0, number_format($board['chase_secondary_amount'] ?? 0, 2));
ok('  …and the two tiers add up to the whole chase list',
   ($board['chase_primary_count'] ?? 0) + ($board['chase_secondary_count'] ?? 0),
   $board['chase_count'] ?? -1);

// ⭐ Owner's rule: shop customers are chased through their own flow, never here.
$shopLeak = 0;
foreach ($board['chase'] ?? [] as $r) {
    $cid = is_array($r) ? ($r['customer_id'] ?? null) : ($r->customer_id ?? null);
    if ($cid && DB::table('t_crm_prod_customer')->where('id', $cid)->value('customer_type') === 'shop') {
        $shopLeak++;
    }
}
ok('  ⭐ no shop customer leaks into the chase list', $shopLeak, 0);

// ⭐ It is PROOF, not approval, that takes an order off the chase list.
ok('  ⭐ proof-in is tracked separately from settled',
   isset($board['proof_in_count'], $board['settled_count']), true);

$legacy = $fu->legacyMobilePayload($board);
ok('the mobile payload renders from the same board', is_array($legacy), true);
$notes[] = sprintf('Chase board: %d to chase (Rs %s) = %d new + %d day-2/3; %d already have proof.',
    $board['chase_count'] ?? 0, number_format($board['chase_amount'] ?? 0, 2),
    $board['chase_primary_count'] ?? 0, $board['chase_secondary_count'] ?? 0,
    $board['proof_in_count'] ?? 0);

// ─────────────────────────────────────────────────────────────────────────────
head('§4 bank attribution — the untagged door');

$ba   = app(\App\Services\FIN\BankAttributionService::class);
$bank = $ba->bankAccountIds();
ok('the service knows which accounts are banks', count($bank) > 0, true);
echo "  online/bank chart accounts: " . implode(', ', $bank) . "\n";

$onlineId = $bank[0] ?? null;
if ($onlineId) {
    ok('an online account requires a bank to be named', $ba->requiresBank($onlineId), true);
    ok('  …and refuses a nonsense bank id', $ba->isValidBank(99999), false);
    $problem = $ba->problemWith($onlineId, null);
    ok('  …and says what is wrong when none is given', is_string($problem) && $problem !== '', true);
    $notes[] = 'Untagged prompt: ' . $problem;
}

$untagged = DB::table('t_fin_ledger')
    ->where(fn ($q) => $q->whereIn('from_account_id', $bank)->orWhereIn('to_account_id', $bank))
    ->whereNull('receiving_account_id')->count();
$tagged = DB::table('t_fin_ledger')
    ->where(fn ($q) => $q->whereIn('from_account_id', $bank)->orWhereIn('to_account_id', $bank))
    ->whereNotNull('receiving_account_id')->count();
printf("  online ledger rows: %d tagged with a bank, %d UNTAGGED\n", $tagged, $untagged);
$notes[] = sprintf('⚠ %d online ledger rows carry no bank tag (invisible to any per-bank split) '
                 . 'vs %d tagged. The 3 ENFORCE_* flags stay off until the APK ships.', $untagged, $tagged);

echo "\n" . str_repeat('-', 72) . "\n";
foreach ($notes as $n) { echo "NOTE: $n\n"; }
echo str_repeat('-', 72) . "\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n" : "FAILURES — passed $pass, failed $fail\n";
