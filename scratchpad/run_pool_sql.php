<?php
// Apply the round-3 migration to the replica, statement by statement (Laravel's raw
// runner will not take PREPARE/EXECUTE in one blob).
use Illuminate\Support\Facades\DB;
$sql = file_get_contents('database/migrations/supplies_pool_sep17_2026.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    try {
        $rows = DB::select($stmt);
        if ($rows) { foreach ($rows as $r) { echo '  ' . json_encode($r) . "\n"; } }
    } catch (\Throwable $e) {
        echo "  !! " . mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 70) . "\n     " . $e->getMessage() . "\n";
    }
}
