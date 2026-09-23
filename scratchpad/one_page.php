<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
use Illuminate\Auth\SessionGuard; use Illuminate\Http\Request; use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt; use Illuminate\Cookie\CookieValuePrefix;
$sid = Str::random(40); $token = Str::random(40);
file_put_contents(storage_path('framework/sessions/'.$sid), serialize([
  'login_web_'.sha1(SessionGuard::class) => (int)($argv[1] ?? 68), '_token' => $token]));
$cookie = config('session.cookie');
$enc = Crypt::encrypt(CookieValuePrefix::create($cookie, Crypt::getKey()).$sid, false);
$req = Request::create($argv[2] ?? '/khaas/inventory', 'GET', ['tab' => $argv[3] ?? 'ingredients']);
$req->cookies->set($cookie, $enc);
$res = $kernel->handle($req);
echo "status: " . $res->getStatusCode() . "\n";
$body = $res->getContent();
if ($res->getStatusCode() !== 200) {
    if (preg_match('/<title>(.*?)<\/title>/s', $body, $m)) { echo "title: " . trim($m[1]) . "\n"; }
    if (preg_match('/exception-message[^>]*>(.*?)</s', $body, $m)) { echo "message: " . trim(strip_tags($m[1])) . "\n"; }
    // Ignition/Whoops plain text fallback
    foreach (['/class="exception__message">(.*?)</s', '/<span class="exception_title">(.*?)<\/span>/s'] as $p) {
        if (preg_match($p, $body, $m)) { echo "msg: " . trim(strip_tags($m[1])) . "\n"; }
    }
    echo substr(strip_tags($body), 0, 700) . "\n";
}
