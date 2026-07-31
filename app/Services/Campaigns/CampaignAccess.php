<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\DB;

/**
 * Who may look at campaigns, and who may actually send them.
 *
 * One implementation shared by the web controller, the mobile API and
 * ReadOnlyGuard — the same reason CampaignSendService and CampaignStatsService
 * are shared. If each surface answered "can this person send?" on its own they
 * would drift, and the surface with the looser answer becomes the way around the
 * rule.
 *
 * Two levels:
 *   view_campaigns   — open the page / screen and read results
 *   manage_campaigns — create, add customers, send, pause, refresh dedup, skip, end
 *
 * The split exists so a VIEW-ONLY account can be granted campaign-running rights
 * without being handed write access to the rest of the operational system
 * (requested Jul-2026 for the analyst login). ReadOnlyGuard lifts its blanket
 * non-GET block only for /campaigns paths and only for a user holding
 * manage_campaigns.
 */
class CampaignAccess
{
    public const PERM_VIEW   = 'view_campaigns';
    public const PERM_MANAGE = 'manage_campaigns';

    /** May open campaigns and read results. */
    public function canView($user): bool
    {
        if (!$user) return false;
        return $this->isTaimurRole($user) || $this->hasPerm($user, self::PERM_VIEW);
    }

    /**
     * May change a campaign (including sending).
     *
     * Deliberately backwards-compatible: until `manage_campaigns` is seeded in
     * this database, anyone who can view can still send — which is exactly the
     * behaviour before this split existed. Without that fallback, uploading the
     * code before running the SQL would silently stop ALL campaign sending, and
     * the deploy here is two manual steps.
     */
    public function canManage($user): bool
    {
        if (!$this->canView($user)) return false;
        if ($this->isTaimurRole($user)) return true;
        if (!$this->managePermissionSeeded()) return true;   // pre-SQL fallback
        return $this->hasPerm($user, self::PERM_MANAGE);
    }

    /**
     * The Taimur role predates the permission system for this feature and is
     * treated as a full campaign operator on both surfaces (it is also how the
     * sidebar decides to show the Campaigns link).
     */
    public function isTaimurRole($user): bool
    {
        if (!$user || empty($user->id)) return false;

        static $cache = [];
        if (array_key_exists($user->id, $cache)) return $cache[$user->id];

        try {
            return $cache[$user->id] = DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $user->id)
                ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
                ->exists();
        } catch (\Throwable $e) {
            return $cache[$user->id] = false;
        }
    }

    protected function hasPerm($user, string $code): bool
    {
        return method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission($code);
    }

    /** Has manage_campaigns been created in this database yet? */
    public function managePermissionSeeded(): bool
    {
        static $seeded = null;
        if ($seeded !== null) return $seeded;

        try {
            $seeded = DB::table('t_sys_mobile_permission')
                ->where('permission_code', self::PERM_MANAGE)
                ->where('is_active', 1)
                ->exists();
        } catch (\Throwable $e) {
            $seeded = false;
        }
        return $seeded;
    }
}
