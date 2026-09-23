<?php
// Self-bootstrapping so it runs standalone: `php scratchpad/blade_compile_recipes.php`.
require __DIR__ . '/../vendor/autoload.php';
$__app = require __DIR__ . '/../bootstrap/app.php';
$__app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
chdir(dirname(__DIR__));
// Compile every blade this round touches through the REAL Blade compiler and lint the
// PHP it produces. A Blade that parses in an editor can still be a fatal at render.
$files = [
    'resources/views/khaas/month-review.blade.php',
    'resources/views/khaas/inventory.blade.php',
    'resources/views/khaas/products.blade.php',
    'resources/views/fin/vendor/show.blade.php',
    'resources/views/fin/vendor/products.blade.php',
];
$bad = 0;
foreach ($files as $f) {
    if (!file_exists($f)) { echo "MISSING $f\n"; $bad++; continue; }
    $php = app('blade.compiler')->compileString(file_get_contents($f));
    $tmp = sys_get_temp_dir() . '/rc_' . md5($f) . '.php';
    file_put_contents($tmp, $php);
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    echo ($rc === 0 ? "  OK   " : "  FAIL ") . $f . "\n";
    if ($rc !== 0) { echo '       ' . implode("\n       ", $out) . "\n"; $bad++; }
}
echo $bad === 0 ? "\nALL BLADES COMPILE CLEAN\n" : "\n$bad BLADE(S) FAILED\n";
exit($bad === 0 ? 0 : 1);
