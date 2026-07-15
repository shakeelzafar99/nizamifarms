<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * View-only account guard (Jul-2026).
 *
 * An account whose role carries the `account_read_only` permission may READ any
 * page in the web app but may NOT perform any state-changing request. This is the
 * hard guarantee behind the "an owner can browse everything, but cannot accidentally
 * change operational data" requirement (e.g. the `adnan` login).
 *
 * WHY a global guard and not just hidden buttons: every write in this app goes out
 * as a non-GET request — either an HTML form POST or a JS fetch() to an endpoint on a
 * shared controller (fin.ledger.*, fin.vendors.*, customers update/merge, orders, …),
 * most of which have NO per-action permission check of their own. Hiding a button
 * removes the trigger, not the capability. Blocking every non-GET request from a
 * read-only user at the middleware layer is the only place that actually closes it,
 * including direct-URL and AJAX attempts. The `$canWrite` button-hiding in the blades
 * is UX polish on top of this; THIS is the lock.
 *
 * Read verbs (GET/HEAD/OPTIONS) always pass. Read-only features that legitimately
 * submit via POST are allow-listed in isAllowed() so browsing isn't broken. Pre-auth
 * POSTs (login / forgot-password) pass automatically because there is no user yet.
 */
class ReadOnlyGuard
{
    /** Verbs that never mutate state. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $user = $request->user();
        // isReadOnly() is memoized on the (per-request singleton) user, so this also
        // warms the flag the blades read later in the same request.
        if (!$user || !$user->isReadOnly()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        $message = 'This account is view-only. You can browse everything, but changes are disabled.';

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success'   => false,
                'read_only' => true,
                'message'   => $message,
            ], 403);
        }

        abort(403, $message);
    }

    /**
     * Non-GET requests that a view-only user must still be able to make.
     *
     * Kept deliberately small. Add a path here only after confirming the endpoint
     * is READ-only (a data grid loader, a search, a report/export builder) and
     * merely happens to use POST — never an endpoint that writes business data.
     */
    private function isAllowed(Request $request): bool
    {
        $allowed = [
            'auth/authenticate',   // login (usually pre-auth, listed for safety)
            'auth/logout',
            'logout',
            // Read-only POST endpoints go here as they are found, e.g. 'reports/api/*'.
        ];

        foreach ($allowed as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
