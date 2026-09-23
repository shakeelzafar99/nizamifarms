<?php
use Illuminate\Support\Facades\Auth; use Illuminate\Http\Request;
Auth::shouldUse('web'); Auth::loginUsingId(91);
try {
    $html = (new \App\Http\Controllers\KhaasController())->products(Request::create('/khaas/products','GET',[]))->render();
    echo "  OK   Khaas Products (" . number_format(strlen($html)) . " bytes)\n";
    foreach (['No recipe yet' => 'no-recipe chip', 'Made this month' => 'made chip', 'Ingredients, estimated' => 'cost chip'] as $n => $w) {
        echo (str_contains($html, $n) ? "         ↳ contains $w\n" : "         ↳ (no $w on this data)\n");
    }
} catch (\Throwable $e) { echo "  FAIL " . $e->getMessage() . "\n       " . $e->getFile() . ':' . $e->getLine() . "\n"; }
