<?php
// Fix storage directory structure
// This creates the proper folder structure in public/storage

$publicStoragePath = __DIR__ . '/storage';
$actualStoragePath = __DIR__ . '/../storage/app/public';

echo "<h2>Storage Directory Fix</h2>";

// Check if public/storage exists
if (!file_exists($publicStoragePath)) {
    echo "<p style='color:red'>❌ public/storage does not exist</p>";
    exit;
}

echo "<p style='color:green'>✓ public/storage exists</p>";

// Check if it's a symlink or directory
if (is_link($publicStoragePath)) {
    echo "<p>It's a symlink pointing to: " . readlink($publicStoragePath) . "</p>";
} else if (is_dir($publicStoragePath)) {
    echo "<p style='color:orange'>⚠ It's a directory (not a symlink)</p>";
    
    // Try to create the attendance/meters folder structure
    $metersPath = $publicStoragePath . '/attendance/meters';
    
    if (!file_exists($metersPath)) {
        if (mkdir($metersPath, 0755, true)) {
            echo "<p style='color:green'>✓ Created: $metersPath</p>";
        } else {
            echo "<p style='color:red'>❌ Failed to create: $metersPath</p>";
        }
    } else {
        echo "<p style='color:green'>✓ Already exists: $metersPath</p>";
    }
    
    // Now try to copy/link the actual files
    echo "<h3>Option 1: Create Symlink (Recommended)</h3>";
    echo "<p>To create a proper symlink, you need SSH access and run:</p>";
    echo "<pre>cd /home/sites/29a/8/8556230fc3/public_html/app/public\n";
    echo "rm -rf storage\n";
    echo "ln -s ../../storage/app/public storage</pre>";
    
    echo "<h3>Option 2: Copy Files (Not Recommended)</h3>";
    echo "<p>Alternatively, you can manually copy files from:</p>";
    echo "<p><strong>From:</strong> /home/sites/29a/8/8556230fc3/public_html/app/storage/app/public/</p>";
    echo "<p><strong>To:</strong> /home/sites/29a/8/8556230fc3/public_html/app/public/storage/</p>";
    
    echo "<h3>Option 3: Use Direct Path (Easiest)</h3>";
    echo "<p>Modify .htaccess to allow access to ../storage/app/public/</p>";
}

// Test if we can access the actual storage
echo "<h3>Actual Storage Path</h3>";
echo "<p><strong>Path:</strong> $actualStoragePath</p>";
if (file_exists($actualStoragePath)) {
    echo "<p style='color:green'>✓ Actual storage exists</p>";
    
    // List some files
    $testFile = $actualStoragePath . '/attendance/meters/2025/10/user_73_20251031_152124_checkin.jpg';
    if (file_exists($testFile)) {
        echo "<p style='color:green'>✓ Test file exists: " . basename($testFile) . "</p>";
        echo "<p>File size: " . filesize($testFile) . " bytes</p>";
    }
} else {
    echo "<p style='color:red'>❌ Actual storage does not exist</p>";
}
?>

