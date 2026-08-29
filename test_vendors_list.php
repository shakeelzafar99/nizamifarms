<?php
/**
 * VENDORS LIST — the phone must receive every vendor (Aug-28 2026).
 *
 * The bug: `VendorController::index` paginated at 20 for BOTH surfaces, and the app
 * reads only `vendors` — it never looks at `pagination`, and its list has no infinite
 * scroll. Sorted by name, every vendor past the 20th was invisible on the phone
 * (20 of 37 with "All" business units selected), and the app's own search could not
 * find them because it filters the rows it already has.
 *
 * What these prove:
 *   §1 the API returns the COMPLETE set, for each business-unit selection;
 *   §2 the web page still gets its paginator, unchanged;
 *   §3 the `status` param is honoured (its Inactive pill used to be a no-op);
 *   §4 search WORKS AT ALL (it referenced columns that do not exist and 500'd) and
 *      cannot escape the active / business-unit filters;
 *   §5 the report picker offers every vendor, not just the page on screen.
 *
 * Run:  php test_vendors_list.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\FIN\VendorController;
use App\Models\FIN\VendorModel;
use Illuminate\Http\Request;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

$ctrl = new VendorController();

/** Call index() as the MOBILE app does (an /api/* request). */
function api(VendorController $ctrl, array $params): array {
    $req = Request::create('/api/vendors', 'GET', $params);
    $req->headers->set('Accept', 'application/json');
    return json_decode($ctrl->index($req)->getContent(), true);
}

head('§1 the API returns every vendor');
foreach ([['1', 'BU 1 (the app\'s store-mode default)'],
          ['2', 'BU 2 (Khaas)'],
          ['all', 'All business units']] as [$bu, $label]) {
    $expected = VendorModel::where('is_active', 1)
        ->when($bu !== 'all', fn ($q) => $q->where('business_unit_id', $bu))
        ->count();
    $res = api($ctrl, ['status' => 'active', 'business_unit_id' => $bu]);
    ok($label . ": all {$expected} returned", count($res['vendors'] ?? []), $expected);
}

// The specific vendors Taimur could not see, by name.
$all = api($ctrl, ['status' => 'active', 'business_unit_id' => 'all']);
$names = array_column($all['vendors'] ?? [], 'vendor_name');
foreach (['Raju (Beef)', 'Vegetable Supplies', 'Warehouse Maintenance', 'Shrink Wrap (Zeeshan)'] as $n) {
    ok("  …including '{$n}' (was past the 20-row cut)", in_array($n, $names, true), true);
}
ok('the list is still sorted by name', $names, (function () use ($names) {
    $s = $names; sort($s, SORT_NATURAL | SORT_FLAG_CASE); return $s;
})());
ok('pagination now says there is nothing more to fetch',
   [$all['pagination']['current_page'], $all['pagination']['last_page']], [1, 1]);
ok('  …and its total matches the rows sent',
   $all['pagination']['total'], count($all['vendors']));
ok('total_balance is still returned', array_key_exists('total_balance', $all), true);

// A client that explicitly wants pages can still have them.
$paged = api($ctrl, ['status' => 'active', 'business_unit_id' => 'all', 'per_page' => 5]);
ok('an explicit per_page still paginates', count($paged['vendors'] ?? []), 5);
ok('  …and reports more pages', $paged['pagination']['last_page'] > 1, true);

head('§2 the web page is untouched');
$webReq = Request::create('/finance/vendors', 'GET', ['business_unit_id' => '1']);
$view = $ctrl->index($webReq);
ok('still returns a view, not JSON', $view instanceof \Illuminate\View\View, true);
$data = $view->getData();
ok('$vendors is still a paginator (the Blade calls ->links())',
   $data['vendors'] instanceof \Illuminate\Pagination\LengthAwarePaginator, true);
ok('  …still 20 per page', $data['vendors']->perPage(), 20);
ok('  …counting the full result set', $data['vendors']->total(),
   VendorModel::where('is_active', 1)->where('business_unit_id', 1)->count());
foreach (['totalBalance', 'businessUnits', 'accessibleCompanyAccounts', 'userDefaultBuId'] as $k) {
    ok("  …and still passes \${$k}", array_key_exists($k, $data), true);
}

head('§3 the status pill actually filters');
$activeCount   = VendorModel::where('is_active', 1)->count();
$inactiveCount = VendorModel::where('is_active', 0)->count();
$r = api($ctrl, ['status' => 'active', 'business_unit_id' => 'all']);
ok('active → only active', count($r['vendors']), $activeCount);
$r = api($ctrl, ['status' => 'inactive', 'business_unit_id' => 'all']);
ok('inactive → only inactive (the pill was a no-op)', count($r['vendors']), $inactiveCount);
ok('  …and they really are inactive',
   count(array_filter($r['vendors'], fn ($v) => (int) $v['is_active'] === 0)), $inactiveCount);
$r = api($ctrl, ['status' => 'all', 'business_unit_id' => 'all']);
ok('all → both', count($r['vendors']), $activeCount + $inactiveCount);
$r = api($ctrl, ['business_unit_id' => 'all']);
ok('no status at all → active, as the web has always assumed', count($r['vendors']), $activeCount);
$r = api($ctrl, ['status' => 'nonsense', 'business_unit_id' => 'all']);
ok('an unknown status falls back to active, never to everything', count($r['vendors']), $activeCount);

head('§4 search — it used to 500');
$r = api($ctrl, ['search' => 'raju', 'business_unit_id' => 'all']);
ok('searching by name works (was "Unknown column vendor_contact")',
   count($r['vendors']) >= 1, true);
ok('  …and finds the right vendor',
   in_array('Raju (Beef)', array_column($r['vendors'], 'vendor_name'), true), true);

// ⚠ The old ungrouped orWhere let search escape the filters. Prove it cannot.
$inactiveName = VendorModel::where('is_active', 0)->value('vendor_name');
$r = api($ctrl, ['search' => $inactiveName, 'status' => 'active', 'business_unit_id' => 'all']);
ok("an INACTIVE vendor ('{$inactiveName}') cannot leak into an active search",
   count($r['vendors']), 0);
$bu2Name = VendorModel::where('is_active', 1)->where('business_unit_id', 2)->value('vendor_name');
$r = api($ctrl, ['search' => $bu2Name, 'business_unit_id' => '1']);
ok("a BU-2 vendor ('{$bu2Name}') cannot leak into a BU-1 search", count($r['vendors']), 0);
$r = api($ctrl, ['search' => $bu2Name, 'business_unit_id' => '2']);
ok('  …but is found in its own business unit', count($r['vendors']) >= 1, true);

head('§5 the report picker lists every vendor');
$picker = $view->getData()['pickerVendors'] ?? null;
ok('the page passes a dedicated picker list', $picker !== null, true, true);
ok('  …containing every active vendor, not just the page on screen',
   $picker ? $picker->count() : 0, $activeCount);
ok('  …which is more than the table shows', ($picker ? $picker->count() : 0) > $data['vendors']->count(), true);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "ALL GREEN" : "FAILURES") . " — passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
