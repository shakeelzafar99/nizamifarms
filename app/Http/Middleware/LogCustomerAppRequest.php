<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer-app traffic measurement (Jul-2026).
 *
 * Writes ONE structured line per /api/customer-app request to the dedicated
 * `customer_app` daily log channel (config/logging.php). The "Customer App
 * Stats" admin page aggregates these lines (requests/day, peak per minute,
 * per-endpoint counts, error rate) so the owner can size a real rate limit
 * from observed traffic instead of guessing.
 *
 * Design notes:
 *  - Registered FIRST in the route-group middleware stack so rejected requests
 *    (401 bad token, 429 throttled) are logged too — those are exactly the
 *    events worth seeing.
 *  - Logging happens in terminate(), i.e. AFTER the response is sent — zero
 *    added latency for the customer app.
 *  - duration_ms is measured from LARAVEL_START, so it includes framework
 *    boot: that is the true per-request cost on the shared host, which is the
 *    number that matters for capacity planning.
 *  - Fully fail-safe: a logging problem can never break the request.
 */
class LogCustomerAppRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $durationMs = defined('LARAVEL_START')
                ? (int) round((microtime(true) - LARAVEL_START) * 1000)
                : null;

            $route = $request->route();

            Log::channel('customer_app')->info('req', [
                'm' => $request->method(),
                // Route PATTERN (e.g. "api/customer-app/orders/{orderNumber}/tracking")
                // so the stats page can group without parsing raw paths.
                'r' => $route ? $route->uri() : $request->path(),
                // Identifier actually requested (order number / mobile) — lets the
                // stats page count unique customers and spot hot orders.
                'id' => $route
                    ? ($route->parameter('orderNumber') ?? $route->parameter('mobile'))
                    : null,
                's' => $response->getStatusCode(),
                'd' => $durationMs,
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            // Never let measurement break the request path. Plain error log —
            // if the dedicated channel itself is broken we still want a trace.
            try {
                Log::error('LogCustomerAppRequest failed', ['error' => $e->getMessage()]);
            } catch (\Throwable $ignored) {
                // give up silently
            }
        }
    }
}
