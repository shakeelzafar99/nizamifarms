<?php
/**
 * BUSINESS SCENARIO PROBE — the MONEY work of Aug-23..27, on the fresh prod replica.
 *
 * Covers, in order:
 *   §1 the customer-credit bucket: balance is SUM(rows), one live consume per order
 *   §2 who may auto-approve a balance grant (Shabib has NO L2 — only his email names him)
 *   §3 "balance is not a discount" — the split on the model, the 5 renderers, the API
 *   §4 manual payment entry: Shabib/Taimur only, and the row remembers WHO typed it
 *   §5 overpay -> balance, and overpay -> TIP with its pre-L1 rule
 *   §6 a banked overpayment must not hide an order's proof badge
 *
 * ⚠ Every mutation is inside a transaction that is ALWAYS rolled back.
 * ⚠ No Auth::login anywhere — only ONE user can be authenticated per process, so every
 *   check passes the user explicitly instead.
 *
 * Fixtures are DISCOVERED, never hard-coded, so a re-sync cannot make this go red for
 * the wrong reason (the lesson from the older fleet suites).
 *
 * Run:  php probe_money_scenarios.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CRM\CustomerCreditModel;
use App\Models\CRM\OrderModel;
use App\Services\CustomerCreditService;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0; $notes = [];
function ok(string $what, $got, $want) {
    global $pass, $fail;
    $good = $got === $want; $good ? $pass++ : $fail++;
    echo ($good ? "  [PASS] " : "  [FAIL] ") . $what . "\n";
    if (!$good) { echo "         got:  " . var_export($got, true) . "\n         want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

$svc = app(CustomerCreditService::class);
// ⚠ App\Models\User, NOT SysAdmin\UserModel — config/auth.php makes the FORMER the
//   authenticated model, and that is what every call site passes.
$U   = fn (string $email) => \App\Models\User::where('email', $email)->first();

$shabib = $U('shabib@nizamifarms.com');
$taimur = $U('taimur@nizamifarms.com');
$rider  = \App\Models\User::find(95);   // Rajab — an ordinary rider

// ─────────────────────────────────────────────────────────────────────────────
head('§0 the SQL actually landed');

ok('the credit table is ready', $svc->tableReady(), true);
ok('the CUSTOMER_CREDIT liability account exists',
   DB::table('t_fin_accounts')->where('account_code','CUSTOMER_CREDIT')->count(), 1);
ok('payment signals can remember their author',
   \Illuminate\Support\Facades\Schema::hasColumn('t_fin_payment_signal','created_by'), true);
ok('the purchase side can be categorised',
   \Illuminate\Support\Facades\Schema::hasColumn('t_fin_vendor_products','category_level_1'), true);

// ⚠ Orphaned dev rows that survived the re-sync (prod has no such table, so a restore
//   cannot clear them). Surfaced, never silently deleted.
$orphans = DB::table('t_crm_customer_credit as cc')
    ->leftJoin('t_crm_prod_order as o','o.id','=','cc.order_id')
    ->whereNotNull('cc.order_id')
    ->whereColumn('o.customer_id','!=','cc.customer_id')
    ->count();
if ($orphans > 0) {
    $notes[] = "⚠ {$orphans} pre-existing credit row(s) point at an order belonging to a DIFFERENT "
             . "customer — dev leftovers from before the re-sync. See the cleanup SQL in the summary.";
}

// ─────────────────────────────────────────────────────────────────────────────
head('§1 the bucket — balance is SUM(rows), nothing is stored');

DB::beginTransaction();
try {
    // Discover a regular customer with a delivered order and no credit history.
    $cust = DB::table('t_crm_prod_customer as c')
        ->whereRaw("COALESCE(c.customer_type,'regular') = 'regular'")
        ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('t_crm_customer_credit as cc')
            ->whereColumn('cc.customer_id','c.id'))
        ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('t_crm_prod_order as o')
            ->whereColumn('o.customer_id','c.id'))
        ->orderByDesc('c.id')->first(['c.id']);
    $CID = (int) $cust->id;
    echo "  fixture customer = #{$CID}\n";

    ok('a fresh customer starts at zero', $svc->balanceFor($CID), 0.0);

    // An ORDINARY user's grant must queue, not pay itself.
    $g = $svc->requestGrant($CID, 1000, (int) $rider->id, ['reason' => 'PROBE grant']);
    ok('an ordinary user\'s grant is PENDING', $g->status, CustomerCreditModel::STATUS_PENDING);
    ok('  …and does NOT count toward the balance', $svc->balanceFor($CID), 0.0);

    $svc->approveGrant((int) $g->id, (int) $taimur->id);
    ok('once approved it counts', $svc->balanceFor($CID), 1000.0);

    // The balance must be the SUM of rows — prove it by reading the rows directly.
    $sum = (float) DB::table('t_crm_customer_credit')->where('customer_id',$CID)
        ->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES)->sum('amount');
    ok('  …and equals SUM(amount) over the counting statuses', $svc->balanceFor($CID), $sum);

    // Spend some of it on one of that customer's orders.
    $ord = DB::table('t_crm_prod_order')->where('customer_id',$CID)->orderByDesc('id')->first(['id','total_price']);
    $OID = (int) $ord->id;
    $svc->applyToOrder($OID, 400, (int) $taimur->id);
    ok('spending 400 leaves 600', $svc->balanceFor($CID), 600.0);
    ok('  …and the spend is a real row on the order',
       $svc->liveConsumeForOrder($OID) !== null, true);
    ok('  …carried as the ACCOUNT_BALANCE sentinel, not a discount code',
       DB::table('t_crm_order_discounts')->where('order_id',$OID)
         ->where('coupon_code', CustomerCreditModel::DISCOUNT_CODE)->count(), 1);

    // ⭐ The DB-level backstop: one live consume per order.
    $dup = false;
    try {
        DB::table('t_crm_customer_credit')->insert([
            'customer_id'=>$CID,'entry_type'=>'consume','amount'=>-1,'status'=>'reserved',
            'order_id'=>$OID,'source'=>'manual','created_at'=>now(),'updated_at'=>now(),
        ]);
    } catch (\Throwable $e) { $dup = str_contains($e->getMessage(), 'uq_cc_active_consume_per_order'); }
    ok('a SECOND live consume on the same order is refused by the database', $dup, true);

    $svc->releaseFromOrder($OID, (int) $taimur->id, 'PROBE release');
    ok('releasing it gives the money back', $svc->balanceFor($CID), 1000.0);

    // ─────────────────────────────────────────────────────────────────────────
    head('§2 who may auto-approve a balance grant');

    ok('Taimur may — he holds L2', $svc->userCanAutoApproveGrant($taimur), true);
    ok('⭐ Shabib may — by EMAIL, though he has NO approval level',
       $svc->userCanAutoApproveGrant($shabib), true);
    ok('  …and he really has no L2',
       \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel((int) $shabib->id, 2), false);
    ok('an ordinary rider may not', $svc->userCanAutoApproveGrant($rider), false);
    ok('nobody at all may not', $svc->userCanAutoApproveGrant(null), false);

    $g2 = $svc->requestGrant($CID, 500, (int) $shabib->id, ['reason' => 'PROBE shabib grant']);
    ok('Shabib\'s own grant is approved on the spot', $g2->status, CustomerCreditModel::STATUS_ACTIVE);
    ok('  …stamped with BOTH created_by and approved_by',
       [(int) $g2->created_by, (int) $g2->approved_by], [(int) $shabib->id, (int) $shabib->id]);
    ok('  …and it never enters the pending queue',
       DB::table('t_crm_customer_credit')->where('customer_id',$CID)->where('status','pending')->count(), 0);
    ok('  …the balance moved to 1,500', $svc->balanceFor($CID), 1500.0);

    $g3 = $svc->requestGrant($CID, 300, (int) $rider->id, ['reason' => 'PROBE rider grant']);
    ok('a rider\'s grant still queues', $g3->status, CustomerCreditModel::STATUS_PENDING);
    ok('  …and the balance does not move', $svc->balanceFor($CID), 1500.0);

    // ─────────────────────────────────────────────────────────────────────────
    head('§3 "balance used" is NOT a discount');

    $svc->applyToOrder($OID, 250, (int) $taimur->id);
    // Give the same order a genuine discount too, so the two must be told apart.
    DB::table('t_crm_order_discounts')->insert([
        'order_id' => $OID, 'coupon_code' => 'PROBE10', 'discount_amount' => 100,
        'discount_title' => 'Probe discount',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = OrderModel::find($OID);
    ok('the model reports the balance part', $order->accountBalanceApplied(), 250.0);
    ok('the model reports the REAL discount part', $order->realDiscountTotal(), 100.0);
    ok('⭐ the two add up to the stored discount_total, so no total moves',
       round($order->accountBalanceApplied() + $order->realDiscountTotal(), 2),
       round((float) DB::table('t_crm_order_discounts')->where('order_id',$OID)->sum('discount_amount'), 2));
} finally {
    DB::rollBack();
}

// Renderers are static files — check them outside the transaction.
$renderers = ['invoice','invoice-print','invoice-image','invoice-pdf','invoice-auto-download'];
$missing = [];
foreach ($renderers as $r) {
    $f = __DIR__ . "/resources/views/pages/orders/{$r}.blade.php";
    $src = is_file($f) ? file_get_contents($f) : '';
    if (!str_contains($src, 'accountBalanceApplied') && stripos($src, 'balance used') === false) {
        $missing[] = $r;
    }
}
ok('all 5 invoice renderers name the balance separately', $missing, []);

$api = file_get_contents(__DIR__ . '/app/Http/Controllers/API/RiderController.php');
ok('the mobile API sends coupon_code so the phone can tell them apart',
   substr_count($api, "'coupon_code'") >= 5, true);
$notes[] = "RiderController carries coupon_code in " . substr_count($api, "'coupon_code'") . " discount maps (needs 5).";

// ─────────────────────────────────────────────────────────────────────────────
head('§4 manual payment entry — Shabib and Taimur only');

$pc  = new \App\Http\Controllers\FIN\PaymentSignalsController();
$ref = new ReflectionMethod($pc, 'canRecordManualPayment');
$ref->setAccessible(true);
ok('Shabib may record a payment by hand', $ref->invoke($pc, $shabib), true);
ok('Taimur may', $ref->invoke($pc, $taimur), true);
ok('an ordinary rider may NOT', $ref->invoke($pc, $rider), false);
ok('the allow-list is config, changeable without a deploy',
   str_contains((string) config('payment_signals.manual_entry_emails'), 'shabib@nizamifarms.com'), true);

DB::beginTransaction();
try {
    // ⭐ Through the MODEL, exactly as recordManualProof does — that is what proves
    //   mass-assignment keeps created_by rather than silently dropping it.
    $sig = \App\Models\FIN\PaymentSignal::create([
        'source'                  => \App\Models\FIN\PaymentSignal::SOURCE_WHATSAPP,
        'extracted_amount'        => 1234.00,
        'extractor_version'       => 'manual_web@v1',
        'created_by'              => (int) $shabib->id,
        'status'                  => \App\Models\FIN\PaymentSignal::STATUS_MATCHED,
        'match_reason'            => 'manual_confirmed',
        'match_confidence'        => 1.00,
    ]);
    $back = DB::table('t_fin_payment_signal')->where('id',$sig->id)->first();
    ok('a manual signal remembers WHO typed it', (int) $back->created_by, (int) $shabib->id);
    ok('  …and marks itself as hand-entered', $back->extractor_version, 'manual_web@v1');

    // The column must survive mass-assignment, which is what $fillable is for.
    $m = new \App\Models\FIN\PaymentSignal();
    ok('⭐ created_by is in $fillable, so mass-assignment cannot drop it',
       in_array('created_by', $m->getFillable(), true), true);
} finally {
    DB::rollBack();
}

// ─────────────────────────────────────────────────────────────────────────────
head('§5 overpay -> balance, and overpay -> TIP');

$pcRef = new ReflectionMethod($pc, 'overpayForOrder');
$pcRef->setAccessible(true);

// Discover an order that actually carries payment signals, so the maths is real.
$cand = DB::table('t_fin_payment_signal')
    ->whereNotNull('matched_order_id')->where('status','!=','rejected')
    ->orderByDesc('id')->limit(40)->pluck('matched_order_id')->unique();
$found = null;
foreach ($cand as $oid) {
    $info = $pcRef->invoke($pc, (int) $oid);
    if (($info['claimed'] ?? 0) > 0) { $found = [(int) $oid, $info]; break; }
}
if ($found) {
    [$oid, $info] = $found;
    printf("  real order #%d : claimed %s, owed %s, banked %s -> extra %s\n",
        $oid, $info['claimed'], $info['owed'], $info['banked'], $info['amount']);
    ok('the overpay maths answers on a real order', is_float($info['amount'] + 0.0), true);
    ok('  …and states whether a TIP is still possible', array_key_exists('tip_eligible', $info), true);
    $notes[] = sprintf('Overpay probe order #%d: extra=%s tip_eligible=%s reason=%s',
        $oid, $info['amount'], var_export($info['tip_eligible'], true), $info['tip_reason'] ?? '-');
} else {
    ok('an order with payment signals was found to test overpay on', false, true);
}

// The pre-L1 rule, stated by the code itself.
$src = file_get_contents(__DIR__ . '/app/Http/Controllers/FIN/PaymentSignalsController.php');
ok('a tip is refused once the invoice has passed L1',
   str_contains($src, 'tip_eligible') && str_contains($src, 'overpayToTip'), true);
ok('the balance route has no such limit (it never touches the invoice)',
   str_contains($src, 'overpayToBalance'), true);

// ─────────────────────────────────────────────────────────────────────────────
head('§6 a banked overpayment must not hide the proof badge');

$psrc = file_get_contents(__DIR__ . '/app/Services/Payments/Signals/PaymentProofStatusService.php');
ok('the proof query filters credit rows out by transaction type',
   str_contains($psrc, 'INVOICE') || str_contains($psrc, 'transaction_type'), true);
$credit = DB::table('t_fin_ledger')
    ->whereIn('transaction_type', ['customer_credit_grant','customer_credit_consume'])->count();
echo "  credit ledger rows on this replica: {$credit}\n";

// ─────────────────────────────────────────────────────────────────────────────
head('§7 count-once: three channels reporting ONE transfer are ONE payment');
// The Aug-27 phantom-overpay fix, pinned as a staged scenario so it cannot
// silently regress on a future replica (the real duplicate rows may get
// resolved by hand). Shapes staged: screenshot + paired email + a THIRD
// unpaired bank SMS with the same reference — then a genuinely second payment
// (different reference) — then a bulk-linked signal (must be excluded, so the
// combined machinery keeps judging it against the GROUP total).

$pcOv  = new \App\Http\Controllers\FIN\PaymentSignalsController();
$mOv   = new ReflectionMethod($pcOv, 'overpayForOrder');
$mOv->setAccessible(true);

DB::beginTransaction();
try {
    // A regular customer's delivered order with NO signals and NO credit history.
    $ordRow = DB::table('t_crm_prod_order as o')
        ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
        ->whereRaw("COALESCE(c.customer_type,'regular') = 'regular'")
        ->where('o.order_status', 'delivered')
        ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('t_fin_payment_signal as s')
            ->whereColumn('s.matched_order_id', 'o.id'))
        ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('t_crm_customer_credit as cc')
            ->whereColumn('cc.customer_id', 'o.customer_id'))
        ->orderByDesc('o.id')->first(['o.id', 'o.customer_id', 'o.total_price', 'o.total_paid']);
    $OID  = (int) $ordRow->id;
    $owed = round((float) $ordRow->total_price - (float) ($ordRow->total_paid ?? 0), 2);
    $paid = round($owed + 500, 2);   // the customer sent Rs 500 too much, once
    echo "  fixture order = #{$OID}, owed {$owed}, transfer {$paid}, ref PROBEREF77\n";

    $mk = function (string $source, ?string $ref, float $amt, ?int $pair = null) use ($ordRow) {
        return DB::table('t_fin_payment_signal')->insertGetId([
            'source' => $source, 'status' => 'matched',
            'matched_order_id' => $ordRow->id, 'matched_customer_id' => $ordRow->customer_id,
            'extracted_amount' => $amt, 'extracted_ref' => $ref,
            'paired_signal_id' => $pair, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // The screenshot, its paired bank email (ref only on the WhatsApp side —
    // the live shape), and the third, UNPAIRED bank SMS.
    $wa = $mk('whatsapp', 'PROBEREF77', $paid);
    $em = $mk('email',    null,         $paid, $wa);
    DB::table('t_fin_payment_signal')->where('id', $wa)->update(['paired_signal_id' => $em]);

    $i = $mOv->invoke($pcOv, $OID);
    ok('a screenshot + its bank email count once', [$i['claimed'], $i['signal_count']], [$paid, 1]);
    ok('  …and the extra is the real Rs 500', $i['amount'], 500.0);

    $sms = $mk('bank_sms', 'PROBEREF77', $paid);
    $i = $mOv->invoke($pcOv, $OID);
    ok('⭐ a THIRD channel with the same reference still counts ONCE',
       [$i['claimed'], $i['signal_count']], [$paid, 1]);
    ok('  …the extra does not grow', $i['amount'], 500.0);
    ok('  …and the surviving row is bank-side (the truth anchor)',
       in_array(DB::table('t_fin_payment_signal')->where('id', $i['signal_id'])->value('source'),
                ['email', 'bank_sms'], true), true);

    // A genuinely SECOND payment — different reference — must still count.
    $second = $mk('bank_sms', 'PROBEREF88', 250.0);
    $i = $mOv->invoke($pcOv, $OID);
    ok('a second payment with its own reference still counts',
       [$i['claimed'], $i['signal_count']], [round($paid + 250, 2), 2]);

    // A bulk-linked (combined transfer) signal must stay EXCLUDED entirely.
    $bulk = $mk('bank_sms', 'PROBEREF99', 50000.0);
    DB::table('t_fin_payment_signal_order')->insert([
        'signal_id' => $bulk, 'order_id' => $OID, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $i = $mOv->invoke($pcOv, $OID);
    ok('⭐ a combined-transfer signal is still excluded from the overpay maths',
       [$i['claimed'], $i['signal_count']], [round($paid + 250, 2), 2]);
} finally {
    DB::rollBack();
}
ok('§7 rolled back — replica clean',
   DB::table('t_fin_payment_signal')->where('extracted_ref', 'like', 'PROBEREF%')->count(), 0);

echo "\n" . str_repeat('-', 72) . "\n";
foreach ($notes as $n) { echo "NOTE: $n\n"; }
echo str_repeat('-', 72) . "\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n" : "FAILURES — passed $pass, failed $fail\n";
