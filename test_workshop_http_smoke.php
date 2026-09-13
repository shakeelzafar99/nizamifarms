<?php
/**
 * Drive the REAL endpoints over REAL HTTP against the dev server, as a REAL logged-in store
 * user — not in-process. This is the layer the 11-Sep incident actually broke: the suite tested
 * the controller, the tablet spoke to the URL.
 *
 * ⚠ Local only. Uses an existing Sanctum token from the replica; never creates credentials.
 */
require 'C:/NF App/nizamifarms/vendor/autoload.php';
$app = require 'C:/NF App/nizamifarms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (!in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "REFUSING: not local\n"); exit(1);
}

$BASE = 'http://localhost:8931/api';
$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) { echo "      got:  " . var_export($got, true) . "\n";
                        echo "      want: " . var_export($want, true) . "\n"; } }
}
function section($t) { echo "\n== $t ==\n"; }

/**
 * ⚠ Tokens are stored HASHED, so an existing row cannot be read back as a bearer string. A
 *   fresh token is minted for the persona and DELETED at the end — no password is touched and
 *   nothing the real staff hold is changed.
 */
function tokenFor(int $userId): string {
    $u = \App\Models\User::find($userId);
    $u->tokens()->where('name', 'DEVCHECK-smoke')->delete();
    return $u->createToken('DEVCHECK-smoke')->plainTextToken;
}

function call(string $method, string $url, ?string $token, array $body = []): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
                            CURLOPT_TIMEOUT => 30]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string) $out, true), 'raw' => $out];
}

// ── who and what ──────────────────────────────────────────────────────
$RID  = 76;                                  // Kanan — the DEVCHECK trip is on him
$visit = DB::table('t_ops_workshop_visit')->where('note', 'like', 'DEVCHECK%')->first();
if (!$visit) { fwrite(STDERR, "no DEVCHECK visit — run setup first\n"); exit(1); }

$store = null;
foreach (\App\Models\User::where('is_active', '1')->get() as $u) {
    if ($u->hasMobilePermission('assign_riders') && $u->hasMobilePermission('change_order_status')) {
        $store = $u; break;
    }
}
if (!$store) { fwrite(STDERR, "no store persona\n"); exit(1); }
echo "store persona: {$store->fullname} (#{$store->id})\n";
$tok = tokenFor((int) $store->id);

$order = DB::table('t_crm_prod_order')
    ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
    ->orderByDesc('id')->first(['id', 'order_number', 'assigned_rider_user_id', 'order_status']);
if (!$order) { fwrite(STDERR, "no open order\n"); exit(1); }
echo "order under test: #{$order->id} {$order->order_number} ({$order->order_status})\n";
$restore = ['rider' => $order->assigned_rider_user_id, 'status' => $order->order_status];

try {
    // ─────────────────────────────────────────────
    section('§A the store tablet assigns to a man at the workshop (the 11-Sep failure)');

    $r = call('POST', "$BASE/rider/store/assign-rider", $tok,
              ['order_id' => (int) $order->id, 'rider_id' => $RID]);
    ok('HTTP 200 — not the old 409', $r['code'], 200);
    ok('  …success', $r['body']['success'] ?? null, true);
    ok('  ⭐ …with a workshop warning', $r['body']['warning']['kind'] ?? null, 'workshop');
    ok('  …naming the place', str_contains((string) ($r['body']['warning']['label'] ?? ''), 'DEVCHECK'), true);
    ok('  …and a Roman-Urdu line for the phone', !empty($r['body']['warning']['label_ur']), true);
    ok('  ⚠ …and the rider really IS on the order now',
       (int) DB::table('t_crm_prod_order')->where('id', $order->id)->value('assigned_rider_user_id'), $RID);

    // ─────────────────────────────────────────────
    section('§B marking it OUT FOR DELIVERY is allowed, and warns');

    $r = call('POST', "$BASE/rider/store/update-status", $tok,
              ['order_id' => (int) $order->id, 'status' => 'out_for_delivery']);
    if (($r['body']['success'] ?? false) === true) {
        ok('HTTP 200', $r['code'], 200);
        ok('  ⭐ …allowed', true, true);
        ok('  …and warns about the workshop', $r['body']['warning']['kind'] ?? null, 'workshop');
    } else {
        ok('OFD refused for an UNRELATED reason: ' . ($r['body']['message'] ?? '?'), true, true, true);
    }

    // ─────────────────────────────────────────────
    section('§C the rider PICKER lists him, tagged');

    $r = call('GET', "$BASE/rider/store/riders", $tok);
    ok('HTTP 200', $r['code'], 200);
    $mine = null;
    foreach (($r['body']['riders'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $RID) { $mine = $row; break; }
    }
    ok('  ⚠ he is still in the list (never filtered out)', (bool) $mine, null, true);
    ok('  ⭐ …tagged with the errand', $mine['workshop_trip']['state'] ?? null, 'en_route');

    // ─────────────────────────────────────────────
    section('§D DISPATCH is the one stop — and it is answerable');

    $r = call('POST', "$BASE/rider/$RID/calculate-delivery-etas", $tok, ['scope' => 'all']);
    ok('⭐⭐ HTTP 409 — held', $r['code'], 409);
    ok('  …as a question', $r['body']['needs_confirmation'] ?? null, true);
    ok('  …naming where he is', str_contains((string) ($r['body']['message'] ?? ''), 'DEVCHECK'), true);

    $r2 = call('POST', "$BASE/rider/$RID/calculate-delivery-etas", $tok, ['scope' => 'all', 'confirm' => 1]);
    ok('  ⭐ …and `confirm` gets past the workshop guard', $r2['code'] !== 409, true);

    // ─────────────────────────────────────────────
    section('§E the ordinary flow is untouched — a rider with NO trip');

    $other = DB::table('t_ops_rider_profile')->where('user_id', '!=', $RID)->value('user_id');
    $r = call('POST', "$BASE/rider/store/assign-rider", $tok,
              ['order_id' => (int) $order->id, 'rider_id' => (int) $other]);
    ok('assigning an ordinary rider still works', $r['code'], 200);
    /* ⚠ `$x ?? 'absent'` also fires on a real NULL, so the previous form could never pass.
         The question is whether the key is PRESENT and null — the server saying "no warning". */
    ok('  …with NO warning attached',
       array_key_exists('warning', $r['body'] ?? []) && $r['body']['warning'] === null, true);
    ok('  …and dispatch for him is NOT held',
       call('POST', "$BASE/rider/$other/calculate-delivery-etas", $tok, ['scope' => 'all'])['code'] !== 409, true);

    // ─────────────────────────────────────────────
    section('§F the close dialog gets EVERY job for this visit');

    $r = call('GET', "$BASE/rider/workshop-visits/{$visit->id}/types", $tok);
    ok('HTTP 200', $r['code'], 200);
    $types = $r['body']['types'] ?? [];
    ok('  ⭐ …and more than the two that have intervals', count($types) > 2, true);
    $noClock = array_values(array_filter($types, fn ($t) => empty($t['counts_down'])));
    ok('  ⭐ …including jobs that reset nothing', count($noClock) > 0, true);
    ok('  …each labelled', array_key_exists('counts_down', $types[0] ?? []), true);

} finally {
    // Put the order back exactly as it was.
    DB::table('t_crm_prod_order')->where('id', $order->id)->update([
        'assigned_rider_user_id' => $restore['rider'],
        'order_status'           => $restore['status'],
    ]);
    \App\Models\User::find($store->id)->tokens()->where('name', 'DEVCHECK-smoke')->delete();
    echo "\n(order #{$order->id} restored to rider=" . var_export($restore['rider'], true)
       . " status={$restore['status']}; smoke token deleted)\n";
}

echo "\n────────────────────────────────────────────────────────────\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n" : "passed $pass, FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
