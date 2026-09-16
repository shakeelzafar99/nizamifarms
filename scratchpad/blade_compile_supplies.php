<?php
// Compile the Storage blade through the real Blade compiler and lint the PHP it produces.
$src = file_get_contents('resources/views/pages/supplies/index.blade.php');
$php = app('blade.compiler')->compileString($src);
$tmp = sys_get_temp_dir() . '/sup_compiled.php';
file_put_contents($tmp, $php);
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
echo implode("\n", $out), "\n", $rc === 0 ? "BLADE COMPILES CLEAN\n" : "BLADE COMPILE FAILED\n";
