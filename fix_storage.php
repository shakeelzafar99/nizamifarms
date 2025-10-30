<?php
$target = '/home/nizamifarms/public_html/app/storage/app/public';
$link = '/home/nizamifarms/public_html/app/public/storage';

if (file_exists($link)) {
    if (is_dir($link) && !is_link($link)) {
        rmdir($link);
    } elseif (is_link($link)) {
        unlink($link);
    }
}

if (symlink($target, $link)) {
    echo "✅ FIXED! Meter pictures should work now.";
} else {
    echo "❌ Failed. Contact support: enable symlink() function";
}
?>