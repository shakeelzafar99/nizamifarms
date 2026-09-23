<?php
// Self-bootstrapping so it runs standalone: `php scratchpad/route_order_check.php`.
require __DIR__ . '/../vendor/autoload.php';
$__app = require __DIR__ . '/../bootstrap/app.php';
$__app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
chdir(dirname(__DIR__));
// ⚠ Prove the literal receipt routes win over the /{id} catch-all, on BOTH surfaces.
// A route that merely EXISTS is not enough — Laravel matches in registration order and
// "receipt-drafts" is a perfectly good-looking vendor id.
use Illuminate\Http\Request;
$checks = [
    ['GET',  'api/vendors/receipt-drafts',        'Khaas\ReceiptController@drafts'],
    ['GET',  'api/vendors/receipt-drafts/7',      'Khaas\ReceiptController@show'],
    ['GET',  'api/vendors/21',                    'FIN\VendorController@show'],
    ['POST', 'api/vendors/21/receipt/extract',    'Khaas\ReceiptController@extract'],
    ['GET',  'finance/vendors/receipt-drafts',    'Khaas\ReceiptController@drafts'],
    ['GET',  'finance/vendors/21',                'FIN\VendorController@show'],
];
$bad = 0;
foreach ($checks as [$verb, $uri, $want]) {
    try {
        $route = app('router')->getRoutes()->match(Request::create('/' . $uri, $verb));
        $got = $route->getActionName();
        $ok = str_contains($got, $want);
        echo ($ok ? '  OK   ' : '  FAIL ') . str_pad("$verb /$uri", 42) . ' -> ' . class_basename($got) . "\n";
        if (!$ok) { echo "         wanted $want\n"; $bad++; }
    } catch (\Throwable $e) {
        echo "  FAIL $verb /$uri  (" . $e->getMessage() . ")\n"; $bad++;
    }
}
echo $bad === 0 ? "\nROUTE ORDER CORRECT\n" : "\n$bad ROUTE(S) RESOLVE TO THE WRONG PLACE\n";
