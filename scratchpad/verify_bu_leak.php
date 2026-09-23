<?php
/**
 * ❄🚫 PROOF — Frozen ingredients must not appear at a NON-Frozen vendor.
 *
 * The owner's rule, 22-Sep: "i dont want them seeing product suggestions for oil and
 * cheese etc". On this database that means the 11 by-weight MEAT suppliers of BU 1 —
 * Jilani Meat, Ghousia Beef and the rest — must show no trace of the ingredient list.
 *
 * ⚠ Part A is the half that matters just as much: the Frozen vendor must KEEP it all.
 *   A gate that silences everything is not a fix.
 *
 * ⚠ Two traps this file hit while being written: `$row->col ?? 'fallback'` swallows a
 *   genuine NULL, which is the very value under test; and a Blade COMMENT's wording is
 *   not the label the page renders — assert on the rendered label.
 *
 * Runs through the REAL HTTP kernel, because the leak was in what the PAGE renders.
 * Run:  php scratchpad/verify_bu_leak.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const TAIMUR = 68;   // holds the vendor screens AND Frozen access — the worst case

$pass = 0; $fail = 0;
function check(string $w, $got, $want) { global $pass,$fail;
  if ($got === $want) { $pass++; echo "  ✓ $w\n"; }
  else { $fail++; echo "  ✗ $w\n      got:  ".var_export($got,true)."\n      want: ".var_export($want,true)."\n"; } }
function truthy(string $w, $g){ check($w, (bool)$g, true); }
function falsy(string $w, $g){ check($w, (bool)$g, false); }

$sid = Str::random(40);
file_put_contents(storage_path('framework/sessions/' . $sid), serialize([
    'login_web_' . sha1(SessionGuard::class) => TAIMUR, '_token' => Str::random(40),
]));
$cookieName = config('session.cookie');
$enc = Crypt::encrypt(CookieValuePrefix::create($cookieName, Crypt::getKey()) . $sid, false);

$get = function (string $uri, array $headers = []) use ($kernel, $cookieName, $enc) {
    $req = Request::create($uri, 'GET', [], [], [], $headers);
    $req->cookies->set($cookieName, $enc);
    $res = $kernel->handle($req);
    return [$res->getStatusCode(), $res->getContent()];
};

$frozen = DB::table('t_fin_vendors')->where('business_unit_id',2)->where('default_purchase_method','by_weight')->first();
$meat   = DB::table('t_fin_vendors')->where('business_unit_id',1)->where('default_purchase_method','by_weight')->first();

echo "\n=== A · The Frozen vendor still gets everything ===\n";
echo "  vendor: {$frozen->vendor_name} (BU {$frozen->business_unit_id})\n";
[$st,$html] = $get('/finance/vendors/'.$frozen->id.'/products');
check('page responds 200', $st, 200);
truthy('the suggestion datalist is there', str_contains($html, 'id="ingNames"'));
truthy('and it really carries Cooking oil', str_contains($html, 'Cooking oil'));
falsy('the INGREDIENTS payload is NOT empty', str_contains($html, 'const INGREDIENTS = [];'));
truthy('the Frozen tag field is offered', str_contains($html, '>Frozen ingredient<'));

echo "\n=== B · ⭐⭐ The MEAT vendor gets NONE of it ===\n";
echo "  vendor: {$meat->vendor_name} (BU {$meat->business_unit_id})\n";
[$st2,$html2] = $get('/finance/vendors/'.$meat->id.'/products');
check('page still responds 200', $st2, 200);
truthy('and it really is the catalogue page', str_contains($html2, 'id="product_name"'));
falsy('⭐⭐ no suggestion datalist', str_contains($html2, 'id="ingNames"'));
falsy('⭐⭐ "Cooking oil" appears nowhere', str_contains($html2, 'Cooking oil'));
falsy('nor "Cheese"', str_contains($html2, 'Cheese'));
falsy('nor the Frozen tag field', str_contains($html2, '>Frozen ingredient<'));
truthy('and the INGREDIENTS payload is empty', str_contains($html2, 'const INGREDIENTS = [];'));

echo "\n=== B2 · ⭐ Not-yet-added first, already-added grouped (never dropped) ===\n";
// A vendor with a product for some ingredients shows the rest loose and those few under
// a heading — and every ingredient stays reachable either way.
$added = DB::table('t_fin_vendor_products')->where('vendor_id', $frozen->id)
    ->whereNotNull('ingredient_id')->where('is_active', 1)->distinct()->count('ingredient_id');
$all = DB::table('t_crm_khaas_ingredient')->where('business_unit_id', 2)
    ->where('is_active', 1)->whereNull('storage_product_id')->count();
echo "  this vendor has a product for $added of $all ingredients\n";
if ($added > 0) {
    truthy('⭐ the already-added ones sit under their own heading', str_contains($html, '<optgroup label='));
    truthy('and each says which product already stands for it', str_contains($html, '— already “'));
} else {
    falsy('nothing added yet, so no heading is drawn', str_contains($html, '<optgroup'));
}
// ⚠⚠ THE INVARIANT: every ingredient is still selectable, grouped or not.
preg_match_all('/<option value="(\\d+)" data-unit=/', $html, $m);
check('⚠⚠ every ingredient is still offered somewhere', count(array_unique($m[1])), $all);
preg_match('/<datalist id="ingNames">(.*?)<\\/datalist>/s', $html, $dl);
check('the name list still carries every spelling', substr_count($dl[1] ?? '', '<option value='), $all);
if ($added > 0) {
    truthy('with the already-added ones labelled, not removed', str_contains($dl[1] ?? '', 'label="already added"'));
}

echo "\n=== C · The API flag the phone reads ===\n";
$json = ['HTTP_ACCEPT'=>'application/json','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest'];
[$s3,$j3] = $get('/finance/vendors/'.$frozen->id.'/products/list', $json);
[$s4,$j4] = $get('/finance/vendors/'.$meat->id.'/products/list', $json);
$f = json_decode($j3, true); $m = json_decode($j4, true);
check('frozen vendor: supports_ingredients true', $f['supports_ingredients'] ?? null, true);
check('⭐⭐ meat vendor: supports_ingredients FALSE', $m['supports_ingredients'] ?? null, false);
truthy('both still return their products', ($f['success'] ?? false) && ($m['success'] ?? false));

echo "\n=== D · ⚠⚠ The WRITE door refuses a cross-unit tag ===\n";
DB::beginTransaction();
try {
    $oil = DB::table('t_crm_khaas_ingredient')->where('name','Cooking oil')->first();
    $ctl = app(App\Http\Controllers\FIN\VendorProductController::class);
    Illuminate\Support\Facades\Auth::guard('web')->loginUsingId(TAIMUR);

    $payload = fn() => Request::create('/x','POST',[
      'product_name'=>'ZZ leak probe','unit'=>'litre','rate_per_unit'=>500,
      'ingredient_id'=>$oil->id, 'pack_qty_base'=>1000,
    ],[],[],['HTTP_ACCEPT'=>'application/json']);

    $ctl->store($payload(), $meat->id);
    $made = DB::table('t_fin_vendor_products')->where('vendor_id',$meat->id)->where('product_name','ZZ leak probe')->first();
    truthy('the product is still created (only the TAG is refused)', (bool)$made);
    check('⭐⭐ but it carries NO ingredient', $made->ingredient_id, null);
    check('and no pack size', $made->pack_qty_base, null);

    $ctl->store($payload(), $frozen->id);
    $made2 = DB::table('t_fin_vendor_products')->where('vendor_id',$frozen->id)->where('product_name','ZZ leak probe')->first();
    check('while the SAME call at the Frozen vendor DOES tag', (int)($made2->ingredient_id ?? 0), (int)$oil->id);
    check('with the litre conversion applied', (float)($made2->pack_qty_base ?? 0), 1000.0);
} catch (\Throwable $e) {
    $fail++; echo "  ✗✗ EXCEPTION: ".$e->getMessage()." @".$e->getLine()."\n";
}
DB::rollBack();

@unlink(storage_path('framework/sessions/'.$sid));
echo "\n────────────────────────────────────────\n";
echo ($fail===0 ? "✅  " : "❌  ")."$pass passed, $fail failed\n";
echo "Replica untouched — part D was rolled back.\n";
