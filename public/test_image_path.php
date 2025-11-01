<?php
// Test script to find the correct URL path for meter pictures
// Upload this to: /public_html/test_image_path.php

echo "<h2>Testing Meter Picture Paths</h2>";

// The file we're trying to access
$testFile = 'attendance/meters/2025/10/user_73_20251031_152124_checkin.jpg';

echo "<h3>File Storage Location:</h3>";
echo "<p><strong>Storage disk 'public' stores files at:</strong><br>";
echo realpath(__DIR__ . '/../storage/app/public') . "</p>";

echo "<h3>Test These URLs:</h3>";
echo "<ol>";

// Test URL 1: Original symlink approach
$url1 = '/storage/' . $testFile;
echo "<li><strong>Symlink approach:</strong><br>";
echo "<a href='$url1' target='_blank'>$url1</a><br>";
echo "<small>File should exist at: " . realpath(__DIR__ . '/storage/' . $testFile) . "</small></li>";

// Test URL 2: Direct path v1
$url2 = '/app/storage/app/public/' . $testFile;
echo "<li><strong>Direct path v1:</strong><br>";
echo "<a href='$url2' target='_blank'>$url2</a><br>";
echo "<small>File should exist at: " . realpath(__DIR__ . '/../storage/app/public/' . $testFile) . "</small></li>";

// Test URL 3: Direct path v2
$url3 = '/app/public/storage/' . $testFile;
echo "<li><strong>Direct path v2:</strong><br>";
echo "<a href='$url3' target='_blank'>$url3</a><br>";
echo "<small>Would map to: /public_html/app/public/storage/</small></li>";

echo "</ol>";

echo "<h3>File System Check:</h3>";
$fullPath = __DIR__ . '/../storage/app/public/' . $testFile;
if (file_exists($fullPath)) {
    echo "<p style='color: green;'>✓ File EXISTS at: $fullPath</p>";
    echo "<p>File size: " . filesize($fullPath) . " bytes</p>";
} else {
    echo "<p style='color: red;'>✗ File NOT FOUND at: $fullPath</p>";
}

echo "<h3>Symlink Check:</h3>";
$symlinkPath = __DIR__ . '/storage';
if (is_link($symlinkPath)) {
    echo "<p style='color: green;'>✓ Symlink EXISTS at: $symlinkPath</p>";
    echo "<p>Points to: " . readlink($symlinkPath) . "</p>";
} else if (is_dir($symlinkPath)) {
    echo "<p style='color: orange;'>⚠ 'storage' is a DIRECTORY (not a symlink)</p>";
} else {
    echo "<p style='color: red;'>✗ Symlink NOT FOUND at: $symlinkPath</p>";
}
?>

