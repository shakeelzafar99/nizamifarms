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
        // Exclude AppSheet webhook routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'webhook/appsheet/*'
        ]);

        // Server-to-server auth for the customer-app pull endpoints (Phase 2).
        $middleware->alias([
            'customer.app' => \App\Http\Middleware\EnsureCustomerAppAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
