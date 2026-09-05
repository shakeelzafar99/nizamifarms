<?php

namespace App\Services\HR;

use Illuminate\Support\Facades\DB;

/**
 * ⚠ PERF: the HR services read the same few `t_fin_config` keys (shift hours, late buffer,
 * leave quota, cycle dates) inside per-employee and per-day loops. On a payroll month that
 * was ~150 identical SELECTs. Settings change when someone edits them on the config screen,
 * which is a different request, so one read per key per request is enough.
 *
 * Deliberately NOT the Laravel cache: this app's cache driver is the database, so caching
 * a config read there would just trade one query for another.
 */
class ConfigMemo
{
    private static array $memo = [];

    /** The raw config value, or null. Never throws — a missing table means "not set". */
    public static function get(string $key): ?string
    {
        if (!array_key_exists($key, self::$memo)) {
            try {
                $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
                self::$memo[$key] = $v === null ? null : (string) $v;
            } catch (\Throwable $e) {
                self::$memo[$key] = null;
            }
        }
        return self::$memo[$key];
    }

    /** Drop the memo — for a request that edits configuration and then reads it back. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}