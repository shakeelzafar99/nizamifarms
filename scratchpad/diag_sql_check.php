<?php
// Parse DIAG-SUPPLIES-SEP14-2026.sql and run every statement against the replica,
// proving each is syntactically valid AND that none of them writes.
use Illuminate\Support\Facades\DB;
$sql = file_get_contents('C:/NF App/DIAG-SUPPLIES-SEP14-2026.sql');
// strip line comments, then split on ';'
$clean = preg_replace('/^\s*--.*$/m', '', $sql);
$stmts = array_values(array_filter(array_map('trim', explode(';', $clean)), fn($s) => $s !== ''));
echo count($stmts) . " statements found\n\n";
$bad = 0;
foreach ($stmts as $i => $s) {
    $first = strtoupper(strtok(ltrim($s), " \n\t"));
    if ($first !== 'SELECT') { echo "  FAIL #" . ($i+1) . " is not a SELECT ($first)\n"; $bad++; continue; }
    try {
        $rows = DB::select($s);
        printf("  OK   #%-2d %-8s rows=%d   %s\n", $i+1, $first, count($rows),
            trim(preg_replace('/\s+/', ' ', mb_substr($s, 0, 60))));
    } catch (\Throwable $e) {
        $bad++;
        printf("  FAIL #%-2d %s\n       %s\n", $i+1, trim(preg_replace('/\s+/',' ', mb_substr($s,0,60))), $e->getMessage());
    }
}
echo "\n" . ($bad ? "$bad FAILED" : "all " . count($stmts) . " statements are valid SELECTs and ran clean") . "\n";
