<?php
// Render the two touched Khaas pages as a real logged-in user and report the outcome.
// A blade that COMPILES can still be a fatal at render (a missing view variable, a
// null relation). This drives the real controllers.
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

Auth::shouldUse('web');
Auth::loginUsingId(91); // Qasim — khaas role

$c = new \App\Http\Controllers\KhaasController();

foreach ([
    ['Planning · Ingredients tab', fn () => $c->inventory(Request::create('/khaas/inventory', 'GET', ['tab' => 'ingredients']))],
    ['Planning · Recipes tab (untouched)', fn () => $c->inventory(Request::create('/khaas/inventory', 'GET', ['tab' => 'recipes']))],
    ['Planning · Stock tab (untouched)', fn () => $c->inventory(Request::create('/khaas/inventory', 'GET', ['tab' => 'stock']))],
    ['Month Review', fn () => $c->monthReview(Request::create('/khaas/month-review', 'GET', []))],
] as [$label, $fn]) {
    try {
        $view = $fn();
        $html = $view->render();
        $len  = strlen($html);
        echo "  OK   $label  (" . number_format($len) . " bytes)\n";
        foreach ([
            'Ingredients & Quantities' => 'ingredients tab link',
            'How these packs came in'   => 'plan-vs-direct alert',
            'bought, used and left'     => 'ingredient panel',
        ] as $needle => $what) {
            if (str_contains($html, $needle)) { echo "         ↳ contains $what\n"; }
        }
    } catch (\Throwable $e) {
        echo "  FAIL $label\n       " . $e->getMessage() . "\n       " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
