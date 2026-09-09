<?php
/**
 * Customer Balances — audit screen regression suite (Sep-2026).
 *
 *   php test_customer_balances_audit.php              # main suite (as Taimur)
 *   php test_customer_balances_audit.php nonapprover  # the refusal checks
 *
 * ⚠ Two personas, two processes ON PURPOSE. Only ONE user may be authenticated
 *   per process in this app — logout()+loginUsingId() does NOT switch user, the
 *   first one stays active — so a refusal check written inline after an
 *   approver check would silently run as the approver and pass for the wrong
 *   reason. (See replica-scenario-probes.)
 *
 * Every mutation is inside a transaction that is ALWAYS rolled back. The suite
 * prints the row count and the ledger account balance afterwards so you can see
 * the replica was left exactly as it was found.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$PASS = 0; $FAIL = 0;

function chk(string $label, $got, $want): void {
    global $PASS, $FAIL;
    $ok = (is_float($want) || is_float($got))
        ? abs((float) $got - (float) $want) < 0.005
        : $got === $want;
    if ($ok) { $PASS++; printf("  ok   %-56s %s\n", $label, json_encode($got)); }
    else     { $FAIL++; printf("  FAIL %-56s got %s want %s\n", $label, json_encode($got), json_encode($want)); }
}
function section(string $t): void { echo "\n── $t " . str_repeat('─', max(0, 60 - strlen($t))) . "\n"; }

$credit = app(App\Services\CustomerCreditService::class);
$report = app(App\Services\CRM\CustomerCreditReportService::class);
$mode   = $argv[1] ?? 'main';

// =====================================================================
// PERSONA 2 — someone WITHOUT balance-correction rights
// =====================================================================
if ($mode === 'nonapprover') {
    $uid = null;
    foreach (DB::table('t_sys_user')->orderBy('id')->limit(300)->pluck('id') as $id) {
        $u = App\Models\User::find($id);
        if (!$u || $credit->userCanAutoApproveGrant($u)) continue;
        $roles = DB::table('t_sys_user_role as ur')->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $id)->pluck('r.type')->all();
        if ($roles && !in_array('rider', $roles, true)) { $uid = $id; break; }
    }
    if (!$uid) { echo "no non-approver staff account found — skipping\n"; exit(0); }

    Auth::guard('web')->loginUsingId($uid);
    section('A non-approver may LOOK but never correct (user #' . $uid . ')');
    chk('canManage is false', $credit->userCanAutoApproveGrant(auth()->user()), false);

    $ctl = app(App\Http\Controllers\CRM\CustomerCreditController::class);
    $live = DB::table('t_crm_customer_credit')->where('status', 'active')->orderBy('id')->first();
    if (!$live) { echo "no active credit row to test against\n"; exit(0); }

    DB::beginTransaction();
    try {
        $req = fn (array $d = []) => Illuminate\Http\Request::create('/', 'POST', $d);
        chk('void refused',     $ctl->void($req(['reason' => 'suite']), $live->id)->getStatusCode(), 403);
        chk('zero-out refused', $ctl->zeroOut($req(['reason' => 'suite']), (int) $live->customer_id)->getStatusCode(), 403);
        chk('approve refused',  $ctl->approve($req(), $live->id)->getStatusCode(), 403);
        chk('reject refused',   $ctl->reject($req(), $live->id)->getStatusCode(), 403);
        chk('the entry is untouched', DB::table('t_crm_customer_credit')->where('id', $live->id)->value('status'), 'active');

        // Requesting a grant IS allowed — it lands PENDING and must not count.
        $before = $credit->balanceFor((int) $live->customer_id);
        $ctl->grant($req(['amount' => 5000, 'mode' => 'online']), (int) $live->customer_id);
        chk('their grant does not move the balance', $credit->balanceFor((int) $live->customer_id), $before);
        chk('it waits in the pending queue', DB::table('t_crm_customer_credit')
            ->where('customer_id', $live->customer_id)->where('status', 'pending')->count(), 1);
    } finally { DB::rollBack(); }

    printf("\nPASS %d  FAIL %d\n", $PASS, $FAIL);
    exit($FAIL === 0 ? 0 : 1);
}

// =====================================================================
// PERSONA 1 — an approver (Taimur)
// =====================================================================
$taimur = DB::table('t_sys_user')->where('email', 'taimur@nizamifarms.com')->value('id')
       ?? DB::table('t_crm_customer_credit')->whereNotNull('created_by')->value('created_by');
Auth::guard('web')->loginUsingId($taimur);
echo "Running as #{$taimur} (" . (auth()->user()->fullname ?? '?') . ")\n";

$acc = fn (string $code) => (float) DB::table('t_fin_accounts')->where('account_code', $code)->value('current_balance');
$startRows   = DB::table('t_crm_customer_credit')->count();
$startAcc    = $acc('CUSTOMER_CREDIT');
// Compared against ITSELF at the end, never against a hard-coded 0: entries
// removed for real through the screen are legitimate history, and a suite that
// insisted the replica be pristine would cry wolf the first time someone used
// the feature it is testing.
$startVoided = DB::table('t_crm_customer_credit')->where('status', 'voided')->count();

// ── 1. ONE balance formula ───────────────────────────────────────────
section('The balance formula lives in ONE place');
$all = $credit->customerBalances();
chk('customerBalances agrees with a raw SUM',
    round(array_sum($all), 2),
    round((float) DB::table('t_crm_customer_credit')->whereIn('status', ['active', 'reserved'])->sum('amount'), 2));
chk('sorted highest first', array_values($all) === array_values(array_reverse(array_sort_desc_check($all))), true);
foreach (array_slice(array_keys($all), 0, 3) as $cid) {
    chk("balanceFor(#$cid) matches the batched read", $credit->balanceFor($cid), $all[$cid]);
}
$min = 5000.0;
chk('min filter keeps only what qualifies',
    count(array_filter($credit->customerBalances($min), fn ($v) => $v < $min)), 0);
chk('balancesForMany agrees for the same ids',
    $credit->balancesForMany(array_slice(array_keys($all), 0, 3)),
    array_slice($all, 0, 3, true));

// ── 2. Reconciliation ────────────────────────────────────────────────
section('Bucket vs books');
$rec = $report->reconciliation();
chk('posted rows equal the ledger account', $rec['ok'], true);
chk('difference is zero', $rec['difference'], 0.0);
chk('reserved is reported separately', array_key_exists('reserved', $rec), true);

// ── 3. Review flags ──────────────────────────────────────────────────
section('Flags catch the wrong entries, and only those');
$flagged = $report->flaggedCreditIds();
echo "  flagged ids: " . json_encode($flagged) . "\n";
foreach ($flagged as $id) {
    $row = DB::table('t_crm_customer_credit')->where('id', $id)->first();
    $tot = (float) DB::table('t_crm_prod_order')->where('id', $row->order_id)->value('total_price');
    $ok  = $tot > 0 && (abs((float) $row->amount) >= $tot - 5.0);
    chk("#$id really is at/above its order total", $ok, true);
}
$flagMap = $report->flagsForCreditIds(
    DB::table('t_crm_customer_credit')->pluck('id')->all()
);
$selfApproved = 0;
foreach ($flagMap as $list) {
    foreach ($list as $f) { if ($f['key'] === 'self_approved') { chk('self-approval is informational, never red', $f['level'], 'info'); $selfApproved++; break; } }
}
chk('self-approval was actually seen', $selfApproved > 0, true);

// ── 4. Log, day totals, daily summary ────────────────────────────────
section('The log reads like a statement');
$act = $report->activity([], 1, 200);
chk('every row is present', $act['total'], DB::table('t_crm_customer_credit')->count());
$sumAdded = 0.0;
foreach ($act['days'] as $d) { $sumAdded += $d['added']; }
chk('day subtotals add up to the grants',
    round($sumAdded, 2),
    round((float) DB::table('t_crm_customer_credit')->where('entry_type', 'grant')->whereIn('status', ['active', 'reserved'])->sum('amount'), 2));
$daily = $report->daily('2026-01-01', '2026-12-31');
chk('daily running total ends at the held total', $daily['rows'][0]['held'] ?? 0.0, round(array_sum($all), 2));
chk('bank is read from the ledger, not the empty column',
    count(array_filter($act['rows'], fn ($r) => $r['entry_type'] === 'grant' && $r['counts'] && !$r['bank'])), 0);

$one = array_key_first($all);
$solo = $report->activity(['customer_id' => $one], 1, 50);
chk('running balance appears for a single customer', $solo['rows'][0]['running_balance'], $all[$one]);
chk('and is withheld across customers', $act['rows'][0]['running_balance'], null);

// ── 5. Correcting a wrong entry ──────────────────────────────────────
section('Removing one wrong entry, through the shared service');
$target = DB::table('t_crm_customer_credit')->where('status', 'active')->where('entry_type', 'grant')
    ->orderByDesc('amount')->first();
DB::beginTransaction();
try {
    $ccB = $acc('CUSTOMER_CREDIT'); $bkB = $acc('ONLINE');
    $ledB = DB::table('t_fin_ledger')->count();
    $custB = $credit->balanceFor((int) $target->customer_id);

    $row = $credit->voidEntry($target->id, $taimur, 'Suite: phantom overpayment');
    chk('row is voided', $row->status, 'voided');
    chk('who and why are recorded', (int) $row->voided_by === (int) $taimur && $row->voided_reason !== '', true);
    chk('customer balance drops by exactly that amount', $custB - $credit->balanceFor((int) $target->customer_id), (float) $target->amount);
    chk('liability drops', $ccB - $acc('CUSTOMER_CREDIT'), (float) $target->amount);
    chk('bank drops', $bkB - $acc('ONLINE'), (float) $target->amount);
    chk('a mirror row is posted, nothing deleted', DB::table('t_fin_ledger')->count() - $ledB, 1);
    chk('the original posting survives', DB::table('t_fin_ledger')->where('id', $target->ledger_transaction_id)->exists(), true);
    chk('books still agree after the correction', $report->reconciliation()['ok'], true);

    $after = $report->activity(['customer_id' => (int) $target->customer_id], 1, 50);
    $voided = null;
    foreach ($after['rows'] as $r) { if ($r['id'] === (int) $target->id) { $voided = $r; break; } }
    chk('the entry is still visible in the log', $voided !== null, true);
    chk('shown as removed', $voided['status_label'] ?? null, 'Removed');
    chk('and no longer counts', $voided['counts'] ?? null, false);

    chk('voiding twice posts nothing more', (function () use ($credit, $target, $taimur, $ledB) {
        $credit->voidEntry($target->id, $taimur, 'again');
        return DB::table('t_fin_ledger')->count() - $ledB;
    })(), 1);
} finally { DB::rollBack(); }

// ── 6. Customers list ────────────────────────────────────────────────
section('The customers list filter respects pagination');
$ctl = new App\Http\Controllers\CRM\CustomerController();
$page = function (array $q) use ($ctl, $app) {
    $req = Illuminate\Http\Request::create('/customers', 'GET', $q);
    $app->instance('request', $req);
    return $ctl->index($req)->getData()['customers'];
};
// Merged-away records are hidden from this list by design, so the baseline is
// the visible population — not every row in the table.
chk('unfiltered still lists everyone visible', $page([])->total(),
    DB::table('t_crm_prod_customer')->whereNull('merged_into_customer_id')->count());
chk('has-a-balance matches the service', $page(['balance' => 'any'])->total(), count($all));
chk('band filter matches the service', $page(['balance' => '5000'])->total(), count($credit->customerBalances(5000.0)));
chk('a shop can never hold one', $page(['balance' => 'any', 'customer_type' => 'shop'])->total(), 0);
$sorted = $page(['sort_by' => 'balance', 'sort_dir' => 'desc']);
chk('balance sort is highest-first', $sorted->items()[0]->id, array_key_first($all));
chk('and asc flips it', $page(['sort_by' => 'balance', 'sort_dir' => 'asc'])->items()[0]->id, array_key_last($all));
chk('page size is still 15', $sorted->perPage(), 15);

// ── 7. HTTP ──────────────────────────────────────────────────────────
section('Every route answers');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
foreach ([
    '/customers/balances',
    '/customers/balances/overview',
    '/customers/balances/data?min=1000&sort=balance_desc',
    '/customers/balances/data?flagged=1',
    '/customers/balances/activity?flagged=1',
    '/customers/balances/daily',
    '/customers/balances/export',
    '/customers?balance=any',
    '/approvals/online',
] as $uri) {
    $req = Illuminate\Http\Request::create($uri, 'GET');
    $req->headers->set('X-Requested-With', 'XMLHttpRequest');
    $app->instance('request', $req);
    chk('GET ' . $uri, $kernel->handle($req)->getStatusCode(), 200);
}

// ── 8. Left as found ─────────────────────────────────────────────────
section('The replica is unchanged');
chk('row count', DB::table('t_crm_customer_credit')->count(), $startRows);
chk('ledger account', $acc('CUSTOMER_CREDIT'), $startAcc);
chk('no NEW voids left behind', DB::table('t_crm_customer_credit')->where('status', 'voided')->count(), $startVoided);

printf("\nPASS %d  FAIL %d\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);

/** Helper: a copy sorted descending, to prove customerBalances() already is. */
function array_sort_desc_check(array $a): array { asort($a); return $a; }
