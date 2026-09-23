<?php
/**
 * Drive the touched pages + endpoints through the REAL HTTP kernel — every middleware,
 * the auth guard, the session, CSRF. A controller call proves the controller; this
 * proves the request.
 *
 * ⚠ ONE PERSONA PER PROCESS is the rule for permission tests (the guard caches the
 *   first user it resolves). This file runs ONE persona; pass a user id to change it.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
// ⚠ The HTTP kernel does not bootstrap on construction. config() is unavailable until
//   it does, and the first call dies with "Target class [config] does not exist".
$kernel->bootstrap();

use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

$uid = (int) ($argv[1] ?? 68);
$label = $argv[2] ?? "user $uid";

// Forge a file session for this persona.
$sid = Str::random(40);
$token = Str::random(40);
file_put_contents(storage_path('framework/sessions/' . $sid), serialize([
    'login_web_' . sha1(SessionGuard::class) => $uid,
    '_token' => $token,
]));
$cookie = config('session.cookie');

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what" . ($extra ? "  ($extra)" : '') . "\n"; }
}

function hit(string $method, string $uri, array $params, string $sid, string $cookie, string $token) {
    global $kernel;
    // ⚠ EncryptCookies decrypts every cookie before the session reads it, so the value
    //   must be the ENCRYPTED, prefixed id — a raw session id lands as a 401. Same
    //   shape scratchpad/devsession.php prints for the browser.
    $encrypted = \Illuminate\Support\Facades\Crypt::encrypt(
        \Illuminate\Cookie\CookieValuePrefix::create($cookie, \Illuminate\Support\Facades\Crypt::getKey()) . $sid,
        false
    );

    $req = Request::create($uri, $method, $params, [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CSRF_TOKEN' => $token,
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ]);
    $req->cookies->set($cookie, $encrypted);
    return $kernel->handle($req);
}

echo "\n=== Driving the real HTTP stack as $label ===\n";

foreach ([
    ['GET', '/khaas/inventory', ['tab' => 'ingredients'], 'Planning · Ingredients tab', ['Ingredients &amp; Quantities', 'One batch like this makes how many packs']],
    ['GET', '/khaas/month-review', [], 'Month Review', ['How these packs came in', 'bought, used and left']],
    ['GET', '/khaas/products', [], 'Frozen Products', ['No recipe yet']],
    ['GET', '/khaas/ingredients', ['business_unit_id' => 2], 'GET ingredients (JSON)', ['"success":true', '"base_unit"']],
    ['GET', '/khaas/recipe/coverage', ['business_unit_id' => 2], 'GET recipe coverage (JSON)', ['"success":true', '"has_recipe"']],
    ['GET', '/khaas/product-costs', ['business_unit_id' => 2], 'GET product costs (JSON)', ['"success":true', '"coverage"']],
    ['GET', '/khaas/ingredients/month', ['business_unit_id' => 2], 'GET ingredient month (JSON)', ['"success":true', '"rows"']],
] as [$m, $uri, $p, $label2, $needles]) {
    $res = hit($m, $uri, $p, $sid, $cookie, $token);
    $body = $res->getContent();
    $code = $res->getStatusCode();
    ok("$label2 responds 200", $code === 200, "got $code");
    foreach ($needles as $n) {
        ok("   ↳ carries " . substr($n, 0, 46), str_contains($body, $n));
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
