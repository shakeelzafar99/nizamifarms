<?php
/**
 * bladecheck2.php <blade file> [...]
 * Compiles each Blade template to PHP and lints the result — catches an unbalanced
 * @if/@endif or a directive typo without booting a request.
 */
require __DIR__ . '/../vendor/autoload.php';

$files = array_slice($argv, 1);
if (!$files) { fwrite(STDERR, "usage: bladecheck2.php <file.blade.php> ...\n"); exit(1); }

$fs       = new Illuminate\Filesystem\Filesystem();
$tmp      = sys_get_temp_dir() . '/nf-blade-check';
if (!is_dir($tmp)) mkdir($tmp, 0777, true);
$compiler = new Illuminate\View\Compilers\BladeCompiler($fs, $tmp);

$bad = 0;
foreach ($files as $f) {
    if (!is_file($f)) { echo "MISSING  $f\n"; $bad++; continue; }
    try {
        $php = $compiler->compileString(file_get_contents($f));
    } catch (\Throwable $e) {
        echo "COMPILE  $f — " . $e->getMessage() . "\n"; $bad++; continue;
    }
    $out = $tmp . '/' . md5($f) . '.php';
    file_put_contents($out, $php);
    exec('php -l ' . escapeshellarg($out) . ' 2>&1', $lines, $code);
    if ($code !== 0) { echo "SYNTAX   $f\n" . implode("\n", $lines) . "\n"; $bad++; }
    else             { echo "ok       $f\n"; }
    $lines = [];
}
exit($bad ? 1 : 0);
