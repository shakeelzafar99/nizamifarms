<?php

namespace App\Services\CRM;

use App\Services\ShiftResolutionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🐔 WHO MAY OPEN THE LA CARNE BOARD — the one authority.
 *
 * Two doors, and they are deliberately different shapes:
 *
 *  1. PERMISSION (`access_lacarne`) — the store team. Any date, full history,
 *     exactly as built. Unaffected by rosters, days off or holidays.
 *
 *  2. ROSTER — a rider whose shift for TODAY is at a La Carne location. This is
 *     the whole point: the man standing at the supplier can see what to buy,
 *     and loses the screen again when the roster moves on. TODAY ONLY.
 *
 * ⭐⭐ The roster test reuses ShiftResolutionService::getUserShift(), which is
 *     already the one authority for "which shift, and where, on this date". Its
 *     resolution order is assignment location → the user's permanent default
 *     location (t_ops_user_location_assignment) → the primary office. The owner
 *     ruled that people permanently based at La Carne SHOULD keep access, so
 *     that fallback is wanted here, not a bug — the final fallback is the
 *     primary office, which is not La Carne, so nobody is granted by accident.
 *     It also means a roster change propagates at once: every write path in
 *     ShiftController already clears that cache.
 *
 * ⭐ "Assigned" is not enough — the date must also be a WORKING day for them.
 *     Templates run [1,3,4,5,6,7] (no Tuesday), and some La Carne assignments
 *     are open-ended, so without this a rider would keep access on days off and
 *     public holidays forever. dayKind() is the system's only valid test for
 *     that (it also covers hire date and manager "not needed" tags).
 */
class LaCarneAccessService
{
    public const VIA_PERMISSION = 'permission';
    public const VIA_ROSTER = 'roster';

    /** Scope of what the caller may look at. */
    public const SCOPE_ALL = 'all';
    public const SCOPE_TODAY = 'today';

    private ?array $locationIds = null;

    /**
     * @return array{
     *   allowed: bool, via: ?string, scope: string, date: string, today: string,
     *   can_change_date: bool, location_name: ?string
     * }
     */
    public function forUser($user, ?string $requestedDate = null): array
    {
        $today = Carbon::today()->format('Y-m-d');
        $deny = [
            'allowed' => false,
            'via' => null,
            'scope' => self::SCOPE_TODAY,
            'date' => $today,
            'today' => $today,
            'can_change_date' => false,
            'location_name' => null,
        ];

        if (!$user) {
            return $deny;
        }

        // ── door 1: the store team's permission ──────────────────────────
        if ($this->hasPermission($user, 'access_lacarne')) {
            return [
                'allowed' => true,
                'via' => self::VIA_PERMISSION,
                'scope' => self::SCOPE_ALL,
                'date' => $this->clampDate($requestedDate, $today),
                'today' => $today,
                'can_change_date' => true,
                'location_name' => null,
            ];
        }

        // ── door 2: rostered at La Carne today ───────────────────────────
        if (!$this->rosterAccessEnabled()) {
            return $deny;
        }

        try {
            $locationIds = $this->locationIds();
            if (empty($locationIds)) {
                return $deny;
            }

            $shifts = app(ShiftResolutionService::class);
            $shift = $shifts->getUserShift((int) $user->id, $today);
            $locationId = $shift['location_id'] ?? null;

            if (!$locationId || !in_array((int) $locationId, $locationIds, true)) {
                return $deny;
            }

            // Rostered there, but is today actually a working day for them?
            if ($shifts->dayKind((int) $user->id, $today) !== 'working') {
                return $deny;
            }

            return [
                'allowed' => true,
                'via' => self::VIA_ROSTER,
                // ⚠ Today only, by owner ruling. A rostered rider is being shown
                //   what to buy right now, not given the history the store team has.
                'scope' => self::SCOPE_TODAY,
                'date' => $today,
                'today' => $today,
                'can_change_date' => false,
                'location_name' => $shift['location_name'] ?? null,
            ];
        } catch (\Throwable $e) {
            // Fail CLOSED: a resolution error must never hand out access.
            Log::warning('La Carne roster access check failed', [
                'user' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return $deny;
        }
    }

    /** Convenience: may this user see the board at all (any door)? */
    public function allowed($user): bool
    {
        return $this->forUser($user)['allowed'];
    }

    // =================================================================

    private function hasPermission($user, string $code): bool
    {
        try {
            if (!$user->relationLoaded('roles')) {
                $user->load(['roles.mobilePermissions']);
            }

            return $user->hasMobilePermission($code);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Which company locations count as "La Carne".
     *
     * ⚠⚠ There is more than one, and it MOVED: id 2 "LaCarne" carried the
     *    assignments up to 2026-08-07 and id 8 "Orchard Lacarne" from
     *    2026-08-08. Hardcoding either id would silently break half the
     *    calendar, so this is configurable — and when the config row is absent
     *    it auto-discovers every active location whose name looks like La Carne,
     *    which finds both today and survives a rename.
     */
    private function locationIds(): array
    {
        if ($this->locationIds !== null) {
            return $this->locationIds;
        }

        $ids = [];

        try {
            $csv = DB::table('t_fin_config')->where('config_key', 'LACARNE_LOCATION_IDS')->value('config_value');
            if (is_string($csv) && trim($csv) !== '') {
                $ids = array_values(array_filter(array_map('intval', explode(',', $csv)), fn ($v) => $v > 0));
            }
        } catch (\Throwable $e) {
            // no config table in this environment — fall through to discovery
        }

        if (empty($ids)) {
            try {
                $ids = DB::table('t_ops_company_locations')
                    ->where('is_active', 1)
                    ->whereRaw("REPLACE(LOWER(location_name), ' ', '') LIKE ?", ['%lacarne%'])
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
            } catch (\Throwable $e) {
                $ids = [];
            }
        }

        return $this->locationIds = $ids;
    }

    /** Kill switch. Default ON, so the SQL is not a prerequisite. */
    private function rosterAccessEnabled(): bool
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', 'LACARNE_ROSTER_ACCESS')->value('config_value');
            if ($v === null || trim((string) $v) === '') {
                return true;
            }

            return in_array(strtoupper(trim((string) $v)), ['Y', '1', 'YES', 'TRUE'], true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function clampDate(?string $raw, string $today): string
    {
        try {
            $c = $raw ? Carbon::parse($raw) : Carbon::today();
        } catch (\Throwable $e) {
            $c = Carbon::today();
        }
        if ($c->gt(Carbon::today())) {
            $c = Carbon::today();
        }

        return $c->format('Y-m-d');
    }
}
