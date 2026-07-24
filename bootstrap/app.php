<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // (AppSheet webhook CSRF exclusion removed Jul 2026 — AppSheet retired, routes deleted.)

        // Server-to-server auth for the customer-app pull endpoints (Phase 2).
        // block.rider — server-side gate for the Operations / bulk-import area
        // (mirrors the sidebar's non-rider visibility rule; blocks rider-only accounts).
        $middleware->alias([
            'customer.app' => \App\Http\Middleware\EnsureCustomerAppAuth::class,
            'block.rider' => \App\Http\Middleware\BlockRiderAccess::class,
        ]);

        // View-only accounts (permission `account_read_only`, e.g. the `adnan`
        // owner login): may browse every web page but cannot make any change.
        // Appended to the web group so it covers ALL web routes — the writes it
        // blocks live on shared finance/orders/customer controllers that have no
        // per-action gate of their own. Read verbs pass; see ReadOnlyGuard.
        $middleware->web(append: [
            \App\Http\Middleware\ReadOnlyGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
