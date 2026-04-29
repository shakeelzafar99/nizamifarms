<?php

/**
 * Sandbox routes.
 *
 * Loaded conditionally from `routes/web.php` only when:
 *   - the request is authenticated, AND
 *   - `ANALYTICS_SANDBOX_ENABLED=true` is set in the .env.
 *
 * Every route here is gated by the `auth` middleware so `Auth::user()` is
 * available inside controllers and views, exactly as it would be in the
 * real app — including all sidebar / header context from `layouts.app`.
 */

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use AnalyticsSandbox\Controllers\QurbaniSandboxController;

// Tell Blade where to find sandbox views (namespaced as `analytics-sandbox::`).
View::addNamespace('analytics-sandbox', base_path('analytics-sandbox/views'));

Route::middleware(['auth'])->prefix('sandbox')->group(function () {

    Route::get('/', function () {
        return view('analytics-sandbox::index', [
            'user' => auth()->user(),
        ]);
    })->name('sandbox.index');

    Route::get('/qurbani', [QurbaniSandboxController::class, 'index'])
        ->name('sandbox.qurbani');

    // ── Add new sandbox dashboards below this line ────────────────────────
    // Route::get('/customers', [CustomersSandboxController::class, 'index']);
    // Route::get('/finance',   [FinanceSandboxController::class,   'index']);

});
