<?php
/**
 * REAL-HTTP smoke for the day-review endpoints (Sep-6 2026).
 *
 * Unit tests call the service; this calls the ROUTES over HTTP the way the browser and the
 * phone do — which is what catches a wrong permission gate, a validator that rejects a real
 * payload, an array printing as "Array", or a 500 hiding behind a caught exception.
 *
 * Needs the dev server running:  .claude/launch.json → nizamifarms-web (port 8931)
 *   php smoke_day_review_http.php [base-url]
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$base = rtrim($argv[1] ?? 'http://localhost:8931', '/');
$pass = 0; $fail = 0;
function check(string $what, $got, $want): void {
    global $pass, $fail;
    $ok = json_encode($got) === json_encode($want);
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $what
        . ($ok ? "\n" : "  got=".json_encode($got)."  want=".json_encode($want)."\n");
}

/** A real web session for one user, written straight into the session store. */
function sessionCookieFor(int $uid): array {
    $store = app('session.store');
    $store->setId(Str::random(40));
    $store->start();
    $store->put('login_web_'.sha1(Illuminate\Auth\SessionGuard::class), $uid);
    $store->regenerateToken();
    $store->save();
    $name = config('session.cookie');
    $enc = app('encrypter');
    $val = $enc->encrypt(
        Illuminate\Cookie\CookieValuePrefix::create($name, $enc->getKey()).$store->getId(), false);
    return [$name.'='.rawurlencode($val), $store->token()];
}

function http(string $method, string $url, array $headers = [], ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            ['Accept: application/json', 'Content-Type: application/json'], $headers));
    }
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, json_decode((string) $out, true), $err, (string) $out];
}

// Is the server up?
[$c, , $err] = http('GET', $base . '/up');
if ($c === 0) {
    echo "dev server not reachable at $base ($err)\nStart it, then re-run.\n";
    exit(1);
}
echo "server: $base\n";

// A manager (manage_payroll) and someone without it, to prove the gate.
$manager = 68;
$rider = (int) DB::table('t_ops_attendance')->whereNotNull('login_time')
    ->orderByDesc('attendance_date')->value('user_id');
echo "manager=$manager  rider=$rider\n\n";

[$cookie, $token] = sessionCookieFor($manager);
$H = ['Cookie: ' . $cookie, 'X-CSRF-TOKEN: ' . $token, 'X-Requested-With: XMLHttpRequest'];

echo "1 - the bulb's count\n";
[$code, $j] = http('GET', $base . '/hr/day-reviews/pending-count', $H);
check('http 200', $code, 200);
check('success', $j['success'] ?? null, true);
check('enabled', $j['enabled'] ?? null, true);
check('count is a number', is_int($j['count'] ?? null), true);
echo "       count = " . ($j['count'] ?? '?') . "\n";

echo "\n2 - the queue itself\n";
[$code, $j] = http('GET', $base . '/hr/day-reviews/pending?limit=5', $H);
check('http 200', $code, 200);
check('success', $j['success'] ?? null, true);
$items = $j['items'] ?? [];
check('items is a list', is_array($items), true);
$first = $items[0] ?? null;
if ($first) {
    foreach (['user_id','fullname','date','kind','minutes','status','label','meta'] as $k) {
        check("item carries $k", array_key_exists($k, $first), true);
    }
    // ⚠ The exact class of bug a unit test misses: a field the phone prints that is
    // secretly an array or an object shows up as "Array"/"[object Object]" on screen.
    foreach (['fullname','date','kind','label','status'] as $k) {
        check("$k is printable (not an array)", is_scalar($first[$k]) || $first[$k] === null, true);
    }
    echo "       first = {$first['fullname']} {$first['date']} {$first['kind']} — {$first['label']}\n";
} else {
    echo "  -- no pending items on this database; shape checks skipped\n";
}

echo "\n3 - one month for one employee\n";
if ($first) {
    $m = substr($first['date'], 0, 7);
    [$code, $j] = http('GET', $base . '/hr/day-reviews/month?user_id=' . $first['user_id'] . '&month=' . $m, $H);
    check('http 200', $code, 200);
    check('success', $j['success'] ?? null, true);
    check('has a summary', isset($j['summary']['overtime']['days']), true);
}

echo "\n4 - a bad payload is refused, not 500\n";
[$code, $j] = http('POST', $base . '/hr/day-reviews/record', $H,
    ['user_id' => 1, 'date' => 'not-a-date', 'kind' => 'overtime', 'verdict' => 'verified']);
check('http 422', $code, 422);

echo "\n5 - an unknown verdict is refused\n";
[$code, $j] = http('POST', $base . '/hr/day-reviews/record', $H,
    ['user_id' => 1, 'date' => date('Y-m-d'), 'kind' => 'overtime', 'verdict' => 'whatever']);
check('http 422', $code, 422);

echo "\n6 - a real verdict round-trips, then is rolled back\n";
if ($first && $first['status'] === 'pending' && empty($first['not_ready'])) {
    $before = DB::table('t_hr_day_review')->count();
    [$code, $j] = http('POST', $base . '/hr/day-reviews/record', $H, [
        'user_id' => $first['user_id'], 'date' => $first['date'],
        'kind' => $first['kind'], 'verdict' => 'verified',
    ]);
    check('http 200', $code, 200);
    check('success', $j['success'] ?? null, true);
    check('a row was written', DB::table('t_hr_day_review')->count(), $before + 1);
    // A "verified" verdict must leave every figure exactly as it was.
    [$c2, $j2] = http('GET', $base . '/hr/day-reviews/month?user_id=' . $first['user_id']
        . '&month=' . substr($first['date'], 0, 7), $H);
    $row = null;
    foreach (($j2['items'] ?? []) as $it) {
        if ($it['date'] === $first['date'] && $it['kind'] === $first['kind']) { $row = $it; }
    }
    check('it reads back as verified', $row['status'] ?? null, 'verified');
    check('and the minutes did not move', $row['effective'] ?? null, $first['minutes']);
    // Clean up — this is the live dev database, not a transaction.
    DB::table('t_hr_day_review')
        ->where('user_id', $first['user_id'])->where('review_date', $first['date'])
        ->where('kind', $first['kind'])->delete();
    check('cleaned up', DB::table('t_hr_day_review')->count(), $before);
} else {
    echo "  -- no reviewable pending item; skipped\n";
}

echo "\n7 - the gate: a user without manage_payroll gets nothing\n";
// Ask the MODEL rather than reconstructing the permission joins — the schema for those
// has more than one shape in this database and a wrong guess would silently pick a user
// who does hold the permission, turning this check into a pass that proves nothing.
$noPerm = null;
foreach (DB::table('t_sys_user')->where('is_active', 1)->limit(60)->pluck('id') as $uid) {
    try {
        $u = \App\Models\User::find($uid);
        if ($u && !$u->hasPermission('manage_payroll')) { $noPerm = (int) $uid; break; }
    } catch (\Throwable $e) { /* skip */ }
}
if ($noPerm) {
    [$cookie2, $token2] = sessionCookieFor((int) $noPerm);
    $H2 = ['Cookie: ' . $cookie2, 'X-CSRF-TOKEN: ' . $token2, 'X-Requested-With: XMLHttpRequest'];
    [$code, $j] = http('GET', $base . '/hr/day-reviews/pending-count', $H2);
    check('count endpoint answers 0 rather than erroring', ($j['count'] ?? null), 0);
    [$code, $j] = http('GET', $base . '/hr/day-reviews/pending', $H2);
    check('the list is refused', $code, 403);
    [$code, $j] = http('POST', $base . '/hr/day-reviews/record', $H2,
        ['user_id' => 1, 'date' => date('Y-m-d'), 'kind' => 'overtime', 'verdict' => 'verified']);
    check('recording is refused', $code, 403);
} else {
    echo "  -- every active user holds manage_payroll on this database; skipped\n";
}

echo "\n" . ($fail ? "FAILED" : "PASSED") . "  $pass passed, $fail failed\n";
echo "day-review rows left behind: " . DB::table('t_hr_day_review')->count() . "\n";
exit($fail ? 1 : 0);
