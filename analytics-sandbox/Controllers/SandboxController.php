<?php

namespace AnalyticsSandbox\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Shared base for every sandbox dashboard controller.
 *
 * Why this exists:
 *  - Gives subclasses a one-call helper for loading raw SQL files from
 *    `analytics-sandbox/queries/` so individual controllers stay readable.
 *  - Centralises the "session user + permissions" preamble so every sandbox
 *    view extends `layouts.app` with the same auth context the real app has.
 *  - Forces every query through `DB::select()` (read-only) — the sandbox is
 *    not a place for writes. A misbehaving AI cannot UPDATE the prod DB
 *    through this base class.
 */
abstract class SandboxController extends Controller
{
    /**
     * Load and run a raw SQL file from `analytics-sandbox/queries/`.
     *
     * @param string $filename  e.g. 'qurbani-cohorts.example.sql'
     * @param array  $bindings  PDO-style positional or named bindings
     * @return array<int, \stdClass>
     */
    protected function runQueryFile(string $filename, array $bindings = []): array
    {
        $path = base_path('analytics-sandbox/queries/' . $filename);

        if (!is_file($path)) {
            abort(500, "Sandbox query file not found: {$filename}");
        }

        $sql = file_get_contents($path);

        // Defensive: even though the DB user should be SELECT-only, refuse
        // to execute a query file that contains anything destructive. This
        // catches a developer who pastes an UPDATE into a sandbox SQL file
        // by mistake before the DB user can.
        if (preg_match('/\b(insert|update|delete|alter|drop|truncate|create|replace|grant|revoke)\b/i', $sql)) {
            abort(500, "Sandbox query files must be read-only (SELECT only). Offending file: {$filename}");
        }

        return DB::select($sql, $bindings);
    }

    /**
     * Common variables that every sandbox view should receive so it can
     * extend `layouts.app` cleanly.
     */
    protected function viewContext(array $extra = []): array
    {
        return array_merge([
            'user'            => Auth::user(),
            'sandboxEnabled'  => filter_var(env('ANALYTICS_SANDBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'currentYear'     => (int) now('Asia/Karachi')->format('Y'),
        ], $extra);
    }
}
