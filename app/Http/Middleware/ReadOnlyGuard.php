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

        // Narrow, per-user carve-out: a view-only account granted
        // `manage_campaigns` may run campaigns (create / send / end) while every
        // other write in the app stays blocked. Requested Jul-2026 so the
        // analyst login can run marketing without being handed the operational
        // system. Scoped to campaign paths AND to that one permission, so it can
        // never widen into ledger, orders, customers, etc.
        if ($this->isPermittedCampaignWrite($request, $user)) {
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
     * Is this a campaign write by a view-only user who is explicitly allowed to
     * run campaigns?
     *
     * Both halves must hold: the path must be under /campaigns, and the user must
     * carry `manage_campaigns`. The path check is what keeps this from becoming a
     * general write unlock — granting the permission opens campaigns and nothing
     * else. The campaign controller enforces the same permission again, so this
     * middleware is only lifting the blanket read-only block, never granting
     * access on its own.
     */
    private function isPermittedCampaignWrite(Request $request, $user): bool
    {
        if (!$request->is('campaigns') && !$request->is('campaigns/*')) {
            return false;
        }

        // Same rule the campaign controller enforces (CampaignAccess), so the
        // middleware and the controller can never disagree about who may send.
        // Note this asks for the PERMISSION specifically, not canManage() — the
        // latter falls back to "anyone who can view" before the SQL is seeded,
        // which is right for normal users but must NOT quietly unlock writes for
        // a view-only account.
        return method_exists($user, 'hasMobilePermission')
            && $user->hasMobilePermission(\App\Services\Campaigns\CampaignAccess::PERM_MANAGE);
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
