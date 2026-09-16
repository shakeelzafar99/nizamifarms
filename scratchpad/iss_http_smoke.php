<?php
/**
 * Drive the NEW routes through the real HTTP kernel (route → middleware → controller → service
 * → JSON), in process. ⚠ ONE user may be authenticated per process, so the persona is an
 * argument and the caller runs it once per person.
 * Usage: php scratchpad/iss_http_smoke.php <userId>
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
$uid = (int) ($argv[1] ?? 91);
$u = \App\Models\User::find($uid);
if (!$u) { echo "no user $uid\n"; exit(1); }
\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($uid);
echo "AS: {$u->fullname} (#{$uid})\n";

foreach ([['GET','/orders/riders-map/fleet/issues'],
          ['GET','/orders/riders-map/fleet/issues?mode=history'],
          ['GET','/orders/riders-map/fleet/issues-board']] as [$m,$url]) {
    $req = Request::create($url, $m);
    $req->headers->set('Accept', str_contains($url,'issues-board') ? 'text/html' : 'application/json');
    $req->setLaravelSession(app('session.store'));
    try {
        $res = $kernel->handle($req);
        $code = $res->getStatusCode();
        $body = $res->getContent();
        $note = '';
        if (str_contains($url,'issues-board')) {
            $note = $code === 302 ? ('redirect → '.$res->headers->get('Location'))
                  : ('html '.strlen($body).'B, has board div: '.(str_contains($body,'flIssWrap')?'YES':'NO'));
        } else {
            $j = json_decode($body, true);
            $note = 'success='.var_export($j['success'] ?? null, true)
                  . ' can_manage='.var_export($j['can_manage'] ?? null, true)
                  . ' read_only='.var_export($j['read_only'] ?? null, true)
                  . ' threads='.var_export($j['threads'] ?? null, true)
                  . ' cards='.count($j['vehicles'] ?? [])
                  . ' quiet='.count($j['quiet'] ?? [])
                  . ' history='.count($j['history'] ?? []);
        }
        echo sprintf("  %-46s %s  %s\n", $url, $code, $note);
    } catch (\Throwable $e) {
        echo sprintf("  %-46s EXCEPTION %s\n", $url, $e->getMessage());
    }
}
