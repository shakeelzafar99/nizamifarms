<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
use Illuminate\Auth\SessionGuard; use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request; use Illuminate\Support\Facades\Crypt; use Illuminate\Support\Str;
$sid = Str::random(40);
file_put_contents(storage_path('framework/sessions/'.$sid), serialize(['login_web_'.sha1(SessionGuard::class) => 68, '_token' => Str::random(40)]));
$cookie = config('session.cookie');
$enc = Crypt::encrypt(CookieValuePrefix::create($cookie, Crypt::getKey()).$sid, false);
$pass=0;$fail=0;
function ok($w,$c,$x=''){global $pass,$fail; if($c){$pass++;echo "  ✓ $w\n";}else{$fail++;echo "  ✗ $w".($x?" ($x)":'')."\n";}}
$req = Request::create('/finance/vendors/21/products', 'GET'); $req->cookies->set($cookie, $enc);
$res = $kernel->handle($req); $b = $res->getContent();
echo "\n--- Manage Products (Vegetable Supplies) ---\n";
ok('page responds 200', $res->getStatusCode() === 200, 'got '.$res->getStatusCode());
ok('the ingredient names are offered as you type', str_contains($b, '<datalist id="ingNames">'));
// A name the vendor ALREADY has a product for still appears, carrying a label rather
// than being dropped — a second pack size must keep the same spelling.
ok('and Cabbage is one of them', str_contains($b, '<option value="Cabbage (Band Gobi)"'));
ok('the Frozen ingredient field is on the add form', str_contains($b, 'id="ingredient_id"'));
ok('and on the edit form', str_contains($b, 'id="edit_ingredient_id"'));
ok('the pack-size box exists, hidden until needed', str_contains($b, 'id="pack_qty_wrap" class="hidden"'));
ok('the table shows which product is which ingredient', substr_count($b, 'title="one kg = 1,000 base units"') >= 11);
ok('the name-matching JS is present', str_contains($b, 'function matchNameToIngredient'));
$req2 = Request::create('/finance/vendors/21/products/list', 'GET', [], [], [], ['HTTP_ACCEPT'=>'application/json']); $req2->cookies->set($cookie, $enc);
$j = json_decode($kernel->handle($req2)->getContent(), true);
echo "\n--- products/list (what the phone reads) ---\n";
ok('answers', $j['success'] ?? false);
$onion = collect($j['products'] ?? [])->firstWhere('product_name', 'Onions (Piyaaz)');
ok('a tagged product carries its ingredient NAME', ($onion['ingredient_name'] ?? null) === 'Onions (Piyaaz)');
ok('and its pack size', (float)($onion['pack_qty_base'] ?? 0) === 1000.0);
echo "\n$pass passed, $fail failed\n"; exit($fail?1:0);
