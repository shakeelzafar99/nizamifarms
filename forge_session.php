<?php
/**
 * Forge a LOCAL web session cookie so a page can be driven as a real user.
 * Owner-approved for local verification only ([[local-login-allowed-for-verification]]).
 *
 * ⚠⚠ LOCAL ONLY. Never point this at production.
 *
 * Usage:  php forge_session.php 68        → prints the Cookie header value
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

$userId = (int) ($argv[1] ?? 0);
if (!$userId) { fwrite(STDERR, "usage: php forge_session.php <user_id>\n"); exit(1); }

$store = app('session');
$id = $store->getId() ?: Str::random(40);
$store->setId($id);
$store->start();
$store->put('login_web_' . sha1(SessionGuard::class), $userId);
$store->put('_token', Str::random(40));
$store->save();

$name  = config('session.cookie');
$value = CookieValuePrefix::create($name, Crypt::getKey()) . $id;
echo $name . '=' . rawurlencode(Crypt::encryptString($value)) . "\n";
