<?php
// Quick path checker
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "<h2>Laravel Path Configuration</h2>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Function</th><th>Path</th></tr>";
echo "<tr><td>base_path()</td><td>" . base_path() . "</td></tr>";
echo "<tr><td>storage_path()</td><td>" . storage_path() . "</td></tr>";
echo "<tr><td>storage_path('app/public')</td><td>" . storage_path('app/public') . "</td></tr>";
echo "<tr><td>public_path()</td><td>" . public_path() . "</td></tr>";
echo "<tr><td>public_path('storage')</td><td>" . public_path('storage') . "</td></tr>";
echo "<tr><td>__DIR__</td><td>" . __DIR__ . "</td></tr>";
echo "</table>";

echo "<h3>Test File Path</h3>";
$testFile = 'attendance/meters/2025/10/user_73_20251031_152124_checkin.jpg';
$fullPath = storage_path('app/public/' . $testFile);
echo "<p><strong>Full path:</strong> $fullPath</p>";
echo "<p><strong>File exists:</strong> " . (file_exists($fullPath) ? 'YES' : 'NO') . "</p>";

if (file_exists($fullPath)) {
    echo "<p><strong>File size:</strong> " . filesize($fullPath) . " bytes</p>";
    
    // Calculate relative path from public directory
    $publicPath = public_path();
    $relativePath = str_replace($publicPath, '', $fullPath);
    echo "<p><strong>Relative to public:</strong> $relativePath</p>";
    
    // Try different URL constructions
    echo "<h3>Possible URLs:</h3>";
    echo "<ol>";
    echo "<li><a href='/app/storage/app/public/$testFile' target='_blank'>/app/storage/app/public/$testFile</a></li>";
    echo "<li><a href='/../storage/app/public/$testFile' target='_blank'>/../storage/app/public/$testFile</a></li>";
    echo "<li><a href='/storage/$testFile' target='_blank'>/storage/$testFile</a></li>";
    echo "</ol>";
}

echo "<h3>Symlink Check</h3>";
$symlinkPath = public_path('storage');
if (is_link($symlinkPath)) {
    echo "<p style='color:green'>✓ Symlink exists</p>";
    echo "<p>Target: " . readlink($symlinkPath) . "</p>";
} else if (is_dir($symlinkPath)) {
    echo "<p style='color:orange'>⚠ 'storage' is a directory (not symlink)</p>";
} else {
    echo "<p style='color:red'>✗ No symlink found</p>";
}
?>

