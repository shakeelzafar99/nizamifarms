<?php
use Illuminate\Support\Facades\Auth; use Illuminate\Http\Request;
Auth::shouldUse('web'); Auth::loginUsingId(68); // Taimur
foreach ([21 => 'Vegetable Supplies (by_total)', 39 => 'B.B.Q (by_weight)'] as $id => $label) {
    try {
        $html = (new \App\Http\Controllers\FIN\VendorController())->show(Request::create("/finance/vendors/$id", 'GET'), $id)->render();
        echo "  OK   $label (" . number_format(strlen($html)) . " bytes)\n";
        echo (str_contains($html, 'Scan a Bill') ? "         ↳ scan button present\n" : "         ↳ no scan button (by_total vendor — correct)\n");
        echo (str_contains($html, 'rcRecord') ? "         ↳ card script present\n" : "");
        if (preg_match('/PRODUCTS = (\[.{0,60})/s', $html, $m)) { echo "         ↳ products payload: " . trim($m[1]) . "…\n"; }
    } catch (\Throwable $e) {
        echo "  FAIL $label: " . $e->getMessage() . "\n       " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
