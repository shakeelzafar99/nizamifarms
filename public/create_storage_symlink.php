<?php
// Create storage symlink
// Run this once to fix the storage link

$link = __DIR__ . '/storage';
$target = __DIR__ . '/../storage/app/public';

echo "<h2>Create Storage Symlink</h2>";

// Check if target exists
if (!file_exists($target)) {
    echo "<p style='color:red'>❌ Target does not exist: $target</p>";
    exit;
}

echo "<p style='color:green'>✓ Target exists: $target</p>";

// Remove existing storage if it's a directory
if (file_exists($link) && !is_link($link)) {
    echo "<p>Removing existing storage directory...</p>";
    
    // Try to remove it (will only work if empty or if we have permissions)
    if (is_dir($link)) {
        // Try to delete
        if (@rmdir($link)) {
            echo "<p style='color:green'>✓ Removed old storage directory</p>";
        } else {
            echo "<p style='color:orange'>⚠ Could not remove old storage directory (may not be empty)</p>";
            echo "<p>You need to manually delete: /public_html/app/public/storage/</p>";
            echo "<p>Then run this script again.</p>";
            exit;
        }
    }
}

// Create symlink
if (file_exists($link) && is_link($link)) {
    echo "<p style='color:green'>✓ Symlink already exists</p>";
    echo "<p>Points to: " . readlink($link) . "</p>";
} else {
    // Try to create symlink
    if (@symlink($target, $link)) {
        echo "<p style='color:green'>✓ Successfully created symlink!</p>";
        echo "<p><strong>From:</strong> $link</p>";
        echo "<p><strong>To:</strong> $target</p>";
        
        // Test it
        $testFile = 'attendance/meters/2025/10/user_73_20251031_152124_checkin.jpg';
        $testPath = $link . '/' . $testFile;
        if (file_exists($testPath)) {
            echo "<p style='color:green'>✓ Test successful! File is accessible via symlink</p>";
            echo "<p><a href='/storage/$testFile' target='_blank'>Click here to test image</a></p>";
        }
    } else {
        echo "<p style='color:red'>❌ Failed to create symlink</p>";
        echo "<p>This usually means:</p>";
        echo "<ul>";
        echo "<li>PHP doesn't have permission to create symlinks</li>";
        echo "<li>The hosting provider has disabled symlink() function</li>";
        echo "<li>You need to use SSH or contact hosting support</li>";
        echo "</ul>";
        
        echo "<h3>Alternative Solution: Use Direct Path</h3>";
        echo "<p>Since symlink creation failed, we'll need to use a direct path approach.</p>";
        echo "<p>The code has been updated to handle this.</p>";
    }
}
?>

