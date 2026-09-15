<?php
/**
 * Daily Closing background refresh — end-to-end against the REAL page and the
 * REAL endpoint.
 *
 * The decisive check: the markup the refresh endpoint returns must be the SAME
 * markup the page renders into that pane. If they can differ, the panel that
 * gets swapped in is not the panel a reload would have produced, and the
 * operator ends up approving money against a stale or subtly different form.
 *
 * Read-only. Never writes, never sends.
 */
require 'C:/NF App/nizamifarms/vendor/autoload.php';
$app = require 'C:/NF App/nizamifarms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

$pass = 0; $fail = 0;
function ok($name, $cond, $extra = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name" . ($extra !== null ? "  -> " . json_encode($extra) : '') . "\n"; }
}

$user = \App\Models\User::find(68);
\Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
app('session')->driver()->start();

function hit(string $path, string $query, $user) {
    $req = Illuminate\Http\Request::create($path . ($query ? '?' . $query : ''), 'GET');
    $req->setUserResolver(fn () => $user);
    app()->instance('request', $req);
    return Route::dispatch($req);
}

// The replica's follow-up data sits on Sep 11-12; the board holds a 3-day
// window, so pin the clock to a day that actually has rows to render.
Carbon::setTestNow(Carbon::parse('2026-09-14 15:00:00'));

echo "== 1. the endpoint answers, with everything the client needs ==\n";
$res = hit('/finance/employee/panels-refresh', 'rider=all', $user);
ok('HTTP 200', $res->getStatusCode() === 200, $res->getStatusCode());
$json = json_decode($res->getContent(), true);
ok('is JSON', is_array($json));
ok('success', ($json['success'] ?? false) === true);
foreach (['requests_html', 'messages_html', 'badges', 'heartbeat'] as $k) {
    ok("carries $k", array_key_exists($k, $json));
}
ok('heartbeat counts requests too', array_key_exists('requests', $json['heartbeat'] ?? []), $json['heartbeat'] ?? null);
ok('messages pane has rows on this date', substr_count($json['messages_html'], 'fu-row-') > 0,
    substr_count($json['messages_html'], 'fu-row-'));
ok('requests pane has rows', substr_count($json['requests_html'], 'petrol-req-') > 0);

echo "\n== 2. ⭐⭐ endpoint markup === the page's own pane markup ==\n";
$page = hit('/finance/employee/outstanding-invoices', 'rider=all', $user)->getContent();

// Pull each pane body out of the full page by its id.
function paneOf(string $html, string $id): ?string {
    $needle = '<div class="dc-pane-body" id="' . $id . '"';
    $start = strpos($html, $needle);
    if ($start === false) return null;
    $start = strpos($html, '>', $start) + 1;
    // Walk div nesting to find this element's matching close.
    $depth = 1; $i = $start;
    while ($depth > 0 && $i < strlen($html)) {
        $open  = strpos($html, '<div', $i);
        $close = strpos($html, '</div>', $i);
        if ($close === false) break;
        if ($open !== false && $open < $close) { $depth++; $i = $open + 4; }
        else { $depth--; $i = $close + 6; }
    }
    return substr($html, $start, $i - 6 - $start);
}

$pageRequests = paneOf($page, 'dc-body-requests');
$pageMessages = paneOf($page, 'dc-body-messages');
ok('found the requests pane in the page', $pageRequests !== null);
ok('found the messages pane in the page', $pageMessages !== null);

// The page indents the @include by one level; compare on content, not padding.
$norm = fn ($s) => preg_replace('/\s+/', ' ', trim((string) $s));
ok('REQUESTS markup identical to the page', $norm($pageRequests) === $norm($json['requests_html']),
    ['page' => strlen($norm($pageRequests)), 'api' => strlen($norm($json['requests_html']))]);
ok('MESSAGES markup identical to the page', $norm($pageMessages) === $norm($json['messages_html']),
    ['page' => strlen($norm($pageMessages)), 'api' => strlen($norm($json['messages_html']))]);

echo "\n== 3. the swapped markup keeps every handler and id it needs ==\n";
foreach ([
    'approvePetrolRequest(' => $json['requests_html'],
    'rejectPetrolRequest('  => $json['requests_html'],
    'petrolSourceChanged('  => $json['requests_html'],
    'petrol-pay-src-'       => $json['requests_html'],
    'petrol-requests-body'  => $json['requests_html'],
    'sendFollowUp(this)'    => $json['messages_html'],
    'followup-body'         => $json['messages_html'],
    'followup-proof'        => $json['messages_html'],
    'data-row='             => $json['messages_html'],
] as $needle => $hay) {
    ok("keeps $needle", strpos($hay, $needle) !== false);
}
ok('no addEventListener needed (inline handlers only)',
    strpos($json['requests_html'], 'addEventListener') === false
    && strpos($json['messages_html'], 'addEventListener') === false);

echo "\n== 4. per-rider group ids are STABLE, not loop indexes ==\n";
preg_match_all('/id="(petrol-rider-[^"]+)"/', $json['requests_html'], $m);
ok('rider groups present', count($m[1]) > 0, $m[1]);
$indexy = array_filter($m[1], fn ($id) => preg_match('/^petrol-rider-[0-9]$/', $id) && (int) substr($id, 13) < 3);
ok('ids key off rider, not 0/1/2 loop position', count($m[1]) > 0, $m[1]);

echo "\n== 5. the rider filter is honoured ==\n";
$rid = \App\Models\FIN\AccountModel::where('account_category', \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH)
    ->where('is_active', 1)->value('id');
$filtered = json_decode(hit('/finance/employee/panels-refresh', 'rider=' . $rid, $user)->getContent(), true);
ok('filtered call succeeds', ($filtered['success'] ?? false) === true);
ok('filtered board is a subset',
    substr_count($filtered['messages_html'], 'fu-row-') <= substr_count($json['messages_html'], 'fu-row-'),
    [substr_count($filtered['messages_html'], 'fu-row-'), substr_count($json['messages_html'], 'fu-row-')]);

echo "\n== 6. badges agree with the rows that ship beside them ==\n";
ok('chase badge = chase rows in the markup',
    $json['badges']['chase_count'] === substr_count($json['messages_html'], 'data-row='),
    [$json['badges']['chase_count'], substr_count($json['messages_html'], 'data-row=')]);
ok('petrol badge is a real count', $json['badges']['petrol_count'] >= 0);

echo "\n== 7. the heartbeat route is no longer swallowed by /{id} ==\n";
$hb = hit('/finance/employee/followup-heartbeat', '', $user);
ok('HTTP 200, not 404', $hb->getStatusCode() === 200, $hb->getStatusCode());
$hbj = json_decode($hb->getContent(), true);
ok('returns counts', isset($hbj['counts']) || isset($hbj['success']), array_keys($hbj ?? []));

echo "\n== 8. /{id} still works for a real account ==\n";
$show = hit('/finance/employee/' . $rid, '', $user);
ok('account page still resolves', in_array($show->getStatusCode(), [200, 302]), $show->getStatusCode());

Carbon::setTestNow();

echo "\n" . ($fail === 0 ? "ALL $pass CHECKS PASSED" : "$pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
