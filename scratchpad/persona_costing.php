<?php
/**
 * ⚠ ONE PERSONA PER PROCESS. The default guard caches the first user it resolves, so a
 *   second login in the same process silently answers as the first — the trap that once
 *   made a rider read as Qasim and return 200.
 *
 * Asserts the owner's ruling of 21-Sep: the ingredient QUANTITIES are open to anyone in
 * Frozen mode; the RUPEES need view_khaas_costing.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

$uid   = (int) $argv[1];
$label = $argv[2];
$wantsCost = $argv[3] === 'yes';

$sid = Str::random(40);
file_put_contents(storage_path('framework/sessions/' . $sid), serialize([
    'login_web_' . sha1(SessionGuard::class) => $uid, '_token' => Str::random(40),
]));
$cookie = config('session.cookie');
$enc = Crypt::encrypt(CookieValuePrefix::create($cookie, Crypt::getKey()) . $sid, false);

$req = Request::create('/khaas/ingredients/month', 'GET', ['business_unit_id' => 2], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
]);
$req->cookies->set($cookie, $enc);
$res  = $kernel->handle($req);
$body = json_decode($res->getContent(), true);

$pass = 0; $fail = 0;
function ok(string $w, bool $c, string $x = '') { global $pass,$fail;
    if ($c) { $pass++; echo "  ✓ $w\n"; } else { $fail++; echo "  ✗ $w" . ($x ? " ($x)" : '') . "\n"; } }

echo "\n--- $label (user $uid) ---\n";
ok('the panel answers 200', $res->getStatusCode() === 200, 'got ' . $res->getStatusCode());
ok('and reports the cost flag as ' . ($wantsCost ? 'allowed' : 'withheld'),
    (bool) ($body['can_see_cost'] ?? false) === $wantsCost);

$rows = $body['ingredients']['rows'] ?? [];
ok('ingredients are listed either way', count($rows) > 0, count($rows) . ' rows');

if ($rows) {
    $r = $rows[0];
    ok('the bought QUANTITY is always there', array_key_exists('bought_text', $r) && $r['bought_text'] !== null);
    ok('the used QUANTITY is always there',   array_key_exists('used_text', $r) && $r['used_text'] !== null);
    if ($wantsCost) {
        ok('and the rupees are present',  array_key_exists('used_value', $r) && $r['used_value'] !== null);
    } else {
        ok('but the rupees are stripped', array_key_exists('used_value', $r) && $r['used_value'] === null);
        ok('and the rate too',            $r['rate_text'] === null);
        ok('the KEY still exists, so a client never has to guess', array_key_exists('rate_text', $r));
    }
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
