<?php
/**
 * devsession.php <userId>
 *
 * Forge a LOCAL web-guard session and print the encrypted cookie value, so the browser pane
 * can drive the real pages as a real persona against the local replica.
 *
 * ⚠⚠ LOCAL ONLY, and it refuses to run otherwise: it asserts APP_ENV=local and a localhost
 *    database before it writes anything. Never point this at production.
 * ⭐ Owner authorised this on 6-Sep-2026 — see the memory note
 *   `local-login-allowed-for-verification`.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

if (config('app.env') !== 'local') { fwrite(STDERR, "refusing: APP_ENV is not local\n"); exit(1); }
$dbHost = config('database.connections.' . config('database.default') . '.host');
if (!in_array($dbHost, ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "refusing: database host is '$dbHost', not localhost\n"); exit(1);
}

$uid = (int) ($argv[1] ?? 0);
if (!$uid) { fwrite(STDERR, "usage: devsession.php <userId>\n"); exit(1); }

$user = App\Models\User::find($uid);
if (!$user) { fwrite(STDERR, "no such user: $uid\n"); exit(1); }

$id      = Str::random(40);
$token   = Str::random(40);
$payload = [
    'login_web_' . sha1(SessionGuard::class) => $uid,
    '_token'                                 => $token,
];

$path = storage_path('framework/sessions/' . $id);
file_put_contents($path, serialize($payload));

$cookieName = config('session.cookie');
$value      = Crypt::encrypt(CookieValuePrefix::create($cookieName, Crypt::getKey()) . $id, false);

echo json_encode([
    'user_id' => $uid,
    'name'    => $user->fullname,
    'cookie'  => $cookieName,
    'value'   => $value,
    'csrf'    => $token,
], JSON_UNESCAPED_SLASHES), "\n";
