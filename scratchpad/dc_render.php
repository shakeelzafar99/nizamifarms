<?php
/**
 * Render the REAL Daily Closing page as Taimur and dump its HTML.
 * Used to prove the partial extraction changes nothing: render before, render
 * after, diff. Read-only.
 */
require 'C:/NF App/nizamifarms/vendor/autoload.php';
$app = require 'C:/NF App/nizamifarms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out   = $argv[1] ?? 'page.html';
$path  = $argv[2] ?? '/finance/employee/outstanding-invoices';
$query = $argv[3] ?? '';

$user = \App\Models\User::find(68);
if (!$user) { fwrite(STDERR, "user 68 missing\n"); exit(1); }
// setUser, not login: there is no real session in a console context.
\Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
app('session')->driver()->start();

$request = Illuminate\Http\Request::create($path . ($query ? '?' . $query : ''), 'GET');
$request->setUserResolver(fn () => $user);
$app->instance('request', $request);

$response = \Illuminate\Support\Facades\Route::dispatch($request);

$html = $response->getContent();
file_put_contents($out, $html);
printf("status %d, %d bytes -> %s\n", $response->getStatusCode(), strlen($html), $out);
