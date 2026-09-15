<?php
/**
 * End-to-end check of the multi-bill reminder, against the REAL services and
 * controllers, inside ONE transaction that is rolled back.
 *
 * ⚠ Deliberately never calls Meta. The WhatsApp send itself is stubbed at the
 * saveOutboundMessage() seam, which is the part this change actually touches.
 */
require 'C:/NF App/nizamifarms/vendor/autoload.php';
$app = require 'C:/NF App/nizamifarms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Services\Payments\OnlineFollowUpService;

$pass = 0; $fail = 0;
function ok($name, $cond, $extra = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name" . ($extra !== null ? "  -> " . json_encode($extra) : '') . "\n"; }
}

// The replica's follow-up data sits on Sep 11-12 and the board holds a 3-day
// window, so pin the clock to a day that actually has rows. Everything below
// still runs against the real services; only "today" is fixed.
\Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-09-14 15:00:00'));

$svc = app(OnlineFollowUpService::class);

// ── Baseline, OUTSIDE the transaction, so we can prove nothing leaked ───────
$before = [
    'orders'   => DB::table('t_crm_prod_order')->whereNotNull('online_message_sent_at')->count(),
    'messages' => DB::table('t_wa_messages')->count(),
    'stampSum' => DB::table('t_crm_prod_order')->selectRaw('COALESCE(SUM(UNIX_TIMESTAMP(online_message_sent_at)),0) s')->value('s'),
];

echo "== 0. board shape ==\n";
$board = $svc->build('all');
$withOthers = array_values(array_filter($board['chase'], fn ($r) => $r['other_bills_count'] > 0));
ok('some chase rows carry other bills', count($withOthers) > 0, count($withOthers));

// Rabia Aamir is the case from the screenshot: NF-19597 + the aged-out NF-19586.
$target = null;
foreach ($board['chase'] as $r) { if ($r['order_number'] === 'NF-19597') { $target = $r; } }
ok('found NF-19597', $target !== null);
if (!$target) { exit(1); }

ok('it names NF-19586 as the other bill', ($target['other_open_bills'][0]['order_number'] ?? null) === 'NF-19586', $target['other_open_bills']);
ok('the other bill is OUTSIDE the board window', $target['other_open_bills'][0]['in_window'] === false);
ok('combined total matches Online Approvals (18,133)', $target['combined_total'] === 18133, $target['combined_total']);
ok('other bill has no proof, so it is chaseable', $target['other_bills_open_count'] === 1);

$primaryId = $target['id'];
$otherId   = $target['other_open_bills'][0]['id'];
$otherNum  = $target['other_open_bills'][0]['order_number'];

// Maham Tayyab has TWO rows on the SAME board — the in_window case.
$maham = null;
foreach ($board['chase'] as $r) { if ($r['order_number'] === 'NF-19601') { $maham = $r; } }
ok('the two-rows-one-customer case is flagged in_window',
    $maham && ($maham['other_open_bills'][0]['in_window'] ?? false) === true,
    $maham['other_open_bills'] ?? null);

// ── Everything that writes happens in here ─────────────────────────────────
DB::beginTransaction();
try {
    echo "\n== 1. stampReminded() marks EVERY order the message named ==\n";
    $res = $svc->stampReminded([$primaryId, $otherId], 1);
    ok('both stamped', count($res['stamped']) === 2, $res);
    ok('nothing skipped', empty($res['skipped']), $res['skipped']);

    $stampedRows = DB::table('t_crm_prod_order')->whereIn('id', [$primaryId, $otherId])
        ->pluck('online_message_sent_at', 'id');
    ok('primary has a timestamp', !empty($stampedRows[$primaryId]));
    ok('the OTHER bill has one too', !empty($stampedRows[$otherId]));

    echo "\n== 2. a bad id in the set does not lose the good ones ==\n";
    $res2 = $svc->stampReminded([$primaryId, 999999999], 1);
    ok('good one still stamped', isset($res2['stamped'][$primaryId]), $res2);
    ok('bad one reported, not fatal', isset($res2['skipped'][999999999]), $res2);

    echo "\n== 3. a CASH order is refused ==\n";
    $cash = DB::table('t_crm_prod_order')
        ->whereIn('order_status', ['delivered', 'completed'])
        ->whereIn('payment_method', ['cash', 'cash_on_delivery', 'cod'])
        ->value('id');
    if ($cash) {
        $res3 = $svc->stampReminded([(int) $cash], 1);
        ok('cash order skipped', isset($res3['skipped'][(int) $cash]), $res3);
        ok('reason names the payment method', str_contains($res3['skipped'][(int) $cash] ?? '', 'online payment'));
    } else {
        ok('no cash order on the replica to test with (skipped)', true);
    }

    echo "\n== 4. the web mark-sent endpoint accepts a SET ==\n";
    DB::table('t_crm_prod_order')->whereIn('id', [$primaryId, $otherId])
        ->update(['online_message_sent_at' => null, 'online_message_sent_by' => null]);

    $controller = app(\App\Http\Controllers\FIN\EmployeeCashController::class);
    $req = Request::create('/x', 'POST', ['order_ids' => [$primaryId, $otherId]]);
    $resp = $controller->markOnlineMessageSentWeb($req, $primaryId);
    $body = json_decode($resp->getContent(), true);
    ok('endpoint succeeded', ($body['success'] ?? false) === true, $body);
    ok('reports both stamped', count($body['stamped'] ?? []) === 2, $body['stamped'] ?? null);
    ok('legacy sent_at still present', !empty($body['sent_at']), $body);

    echo "\n== 5. legacy single call is unchanged ==\n";
    DB::table('t_crm_prod_order')->whereIn('id', [$primaryId, $otherId])
        ->update(['online_message_sent_at' => null, 'online_message_sent_by' => null]);
    $resp5 = $controller->markOnlineMessageSentWeb(Request::create('/x', 'POST', []), $primaryId);
    $body5 = json_decode($resp5->getContent(), true);
    ok('succeeded with no order_ids', ($body5['success'] ?? false) === true, $body5);
    ok('stamped ONLY the route order',
        DB::table('t_crm_prod_order')->where('id', $otherId)->value('online_message_sent_at') === null);

    echo "\n== 6. the multi send records WHICH orders it covered ==\n";
    $conv = DB::table('t_wa_conversations')->value('id');
    ok('a conversation exists to log against', $conv !== null);

    $wa = app(\App\Services\WhatsAppService::class);
    $fakeApi = ['messages' => [['id' => 'wamid.TEST_' . uniqid()]]];
    $msg = $wa->saveOutboundMessage(
        (int) $conv, $fakeApi, 'template',
        'Template: payment_reminder_multiples', 1,
        'payment_reminder_multiples',
        ['Rabia', 'NF-19597, NF-19586', '18,133'],
        false,
        'NF-19597',
        ['NF-19597', 'NF-19586']
    );
    ok('message row written', $msg !== null);
    $stored = DB::table('t_wa_messages')->where('id', $msg->id)->first();
    ok('related_order_number = the primary', $stored->related_order_number === 'NF-19597', $stored->related_order_number);
    $meta = json_decode($stored->metadata ?? 'null', true);
    ok('metadata lists BOTH orders',
        ($meta['related_order_numbers'] ?? null) === ['NF-19597', 'NF-19586'], $stored->metadata);

    echo "\n== 7. a SINGLE-order send does not bloat metadata ==\n";
    $msg2 = $wa->saveOutboundMessage(
        (int) $conv, ['messages' => [['id' => 'wamid.TEST2_' . uniqid()]]], 'template',
        'Template: payment_reminder_single', 1, 'payment_reminder_single',
        ['Rabia', 'NF-19597', '11,893'], false, 'NF-19597', ['NF-19597']
    );
    $stored2 = DB::table('t_wa_messages')->where('id', $msg2->id)->first();
    ok('no metadata written for one order', empty($stored2->metadata), $stored2->metadata);
    ok('related_order_number still set', $stored2->related_order_number === 'NF-19597');

    echo "\n== 8. the board reads that history back for the NON-primary bill ==\n";
    // Put NF-19586 back on the board so its history is visible: it is normally
    // outside the 3-day window, which is the whole reason it needed the modal.
    $hist = (new ReflectionClass(OnlineFollowUpService::class))->getMethod('reminderHistory');
    $hist->setAccessible(true);
    $orders = \App\Models\CRM\OrderModel::whereIn('id', [$primaryId, $otherId])->get();
    $map = $hist->invoke($svc, $orders);
    ok('primary counted (indexed column)', ($map->get('NF-19597')['count'] ?? 0) >= 1, $map->get('NF-19597'));
    ok('the OTHER bill counted from metadata', ($map->get($otherNum)['count'] ?? 0) >= 1, $map->get($otherNum));

    echo "\n== 9. the primary is not double-counted by the metadata pass ==\n";
    $primaryCount = $map->get('NF-19597')['count'] ?? 0;
    $rows = DB::table('t_wa_messages')->where('related_order_number', 'NF-19597')
        ->whereIn('template_name', OnlineFollowUpService::REMINDER_TEMPLATES)
        ->where('direction', 'outbound')->where('status', '!=', 'failed')->count();
    ok('count equals the real send rows, not more', $primaryCount === $rows, [$primaryCount, $rows]);

    echo "\n== 10. a settled bill never reaches the modal ==\n";
    $l2 = DB::table('t_fin_ledger')->where('mode', 'online')
        ->whereIn('transaction_type', ['invoice', 'order_payment'])
        ->where('order_id', $otherId)->first();
    if ($l2) {
        DB::table('t_fin_ledger')->where('id', $l2->id)->update(['approval_status' => 'pending_l2']);
        $b2 = $svc->build('all');
        $t2 = null;
        foreach ($b2['chase'] as $r) { if ($r['order_number'] === 'NF-19597') { $t2 = $r; } }
        ok('L1-approved bill dropped from the list', ($t2['other_bills_count'] ?? -1) === 0, $t2['other_open_bills'] ?? null);
        ok('combined total falls back to this invoice alone', ($t2['combined_total'] ?? 0) === $t2['amount']);
    } else {
        ok('no ledger row to flip (skipped)', true);
    }

    DB::rollBack();
    echo "\n-- transaction rolled back --\n";
} catch (\Throwable $e) {
    DB::rollBack();
    echo "\n!! EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail++;
}

echo "\n== 11. the database is untouched afterwards ==\n";
$after = [
    'orders'   => DB::table('t_crm_prod_order')->whereNotNull('online_message_sent_at')->count(),
    'messages' => DB::table('t_wa_messages')->count(),
    'stampSum' => DB::table('t_crm_prod_order')->selectRaw('COALESCE(SUM(UNIX_TIMESTAMP(online_message_sent_at)),0) s')->value('s'),
];
ok('no orders newly stamped', $before['orders'] === $after['orders'], [$before['orders'], $after['orders']]);
ok('no WhatsApp rows left behind', $before['messages'] === $after['messages'], [$before['messages'], $after['messages']]);
ok('every existing stamp identical', $before['stampSum'] === $after['stampSum']);

echo "\n" . ($fail === 0 ? "ALL $pass CHECKS PASSED" : "$pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
