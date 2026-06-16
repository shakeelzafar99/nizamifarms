<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Server-to-server auth for the customer-app pull endpoints (Phase 2).
 *
 * The customer-app (Vercel) backend calls our live-tracking endpoint with:
 *
 *     Authorization: Bearer <CUSTOMER_APP_INBOUND_TOKEN>
 *
 * This is a service-principal token (NOT a user session and NOT the webhook
 * signing secret). We keep it separate from the webhook secret so the signing
 * secret is never transmitted in a header.
 *
 * Customer ownership of the order is enforced on THEIR side (they resolve the
 * customer from the mobile JWT before proxying to us); our job here is only to
 * authenticate that the caller is the trusted backend.
 */
class EnsureCustomerAppAuth
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('customer_app.inbound_token', '');

        // Fail closed: if no token is configured, the endpoint is unusable
        // rather than open.
        if ($expected === '') {
            Log::warning('Customer-app inbound token not configured — rejecting tracking request');
            return response()->json([
                'success' => false,
                'message' => 'Customer-app integration not configured.',
            ], 503);
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '' || !hash_equals($expected, $provided)) {
            // Log enough to diagnose a token mismatch during testing, but
            // never the token values themselves.
            Log::warning('[customer-app] tracking request rejected (bad/missing bearer token)', [
                'path'         => $request->path(),
                'ip'           => $request->ip(),
                'token_given'  => $provided !== '',
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
