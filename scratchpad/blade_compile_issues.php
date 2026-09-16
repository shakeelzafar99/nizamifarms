<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['pages.riders-map.partials.fleet-issues','pages.riders-map.partials.fleet','pages.fleet.issues-board'] as $v) {
  try {
    $path = view()->getFinder()->find($v);
    $compiled = app('blade.compiler')->compileString(file_get_contents($path));
    $tmp = sys_get_temp_dir().'/bl_'.md5($v).'.php';
    file_put_contents($tmp, $compiled);
    $out = shell_exec('"'.PHP_BINARY.'" -l '.escapeshellarg($tmp).' 2>&1');
    echo str_pad($v, 46).trim($out)."\n";
    @unlink($tmp);
  } catch (\Throwable $e) { echo str_pad($v,46)."ERROR ".$e->getMessage()."\n"; }
}
