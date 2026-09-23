<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
use Illuminate\Auth\SessionGuard; use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request; use Illuminate\Support\Facades\Crypt; use Illuminate\Support\Str;
$sid = Str::random(40);
file_put_contents(storage_path('framework/sessions/'.$sid), serialize([
  'login_web_'.sha1(SessionGuard::class) => 68, '_token' => Str::random(40)]));
$cookie = config('session.cookie');
$enc = Crypt::encrypt(CookieValuePrefix::create($cookie, Crypt::getKey()).$sid, false);
$pass=0;$fail=0;
function ok($w,$c,$x=''){global $pass,$fail; if($c){$pass++;echo "  ✓ $w\n";}else{$fail++;echo "  ✗ $w".($x?" ($x)":'')."\n";}}
foreach ([[39,'B.B.Q (by_weight)',true],[21,'Vegetable Supplies (by_total)',false]] as [$id,$label,$wantScan]) {
    $req = Request::create("/finance/vendors/$id", 'GET');
    $req->cookies->set($cookie, $enc);
    $res = $kernel->handle($req);
    $b = $res->getContent();
    echo "\n--- $label ---\n";
    ok('page responds 200', $res->getStatusCode() === 200, 'got '.$res->getStatusCode());
    ok($wantScan ? 'the Scan a Bill button is offered' : 'no Scan button on a lump-sum vendor',
        str_contains($b, '🧾 Scan a Bill') === $wantScan);
    ok('the card markup is present', str_contains($b, 'id="rcModal"'));
    ok('and it points at the receipt endpoint', str_contains($b, "/receipt/extract"));
    ok('the save still goes to the ordinary weighted-purchase route',
        str_contains($b, "/finance/vendors/$id/weighted-purchase"));
}
echo "\n$pass passed, $fail failed\n";
exit($fail===0?0:1);
