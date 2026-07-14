<?php

namespace App\Http\Controllers\SysAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user UI preferences (Phase 5). Currently backs the "Customize menu"
 * feature: each user can HIDE sidebar items from their own menu. This is a
 * personal, subtractive display preference — it can never grant access, and the
 * server still renders exactly what the user's role allows; hiding is applied on
 * top of that. Everything is scoped to auth()->id(); a user can only read/write
 * their own row.
 */
class UserSettingController extends Controller
{
    private const KEY = 'sidebar_hidden';

    /**
     * Slugs that may never be hidden. Dashboard and HQ ARE hideable by choice
     * (owner ruling, Jul-2026) — a user can always re-show them from the
     * "Customize menu" gear, which lives in the footer with an href="#" and so
     * is itself never collectable/hideable. Logout (also href="#") is likewise
     * never collected; it stays here purely as belt-and-suspenders against a
     * crafted request. Net effect: the menu can be emptied of navigation items,
     * but the always-present gear + Logout keep it recoverable.
     */
    private const NON_HIDEABLE = ['logout'];

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('t_sys_user_setting');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** GET /me/settings/sidebar — the current user's hidden-item slugs. */
    public function getSidebar()
    {
        $hidden = [];
        if ($this->tableReady() && auth()->check()) {
            $raw = DB::table('t_sys_user_setting')
                ->where('user_id', auth()->id())
                ->where('setting_key', self::KEY)
                ->value('setting_value');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $hidden = array_values(array_filter($decoded, 'is_string'));
                }
            }
        }
        return response()->json(['success' => true, 'hidden' => $hidden]);
    }

    /** POST /me/settings/sidebar — replace the current user's hidden-item list. */
    public function saveSidebar(Request $request)
    {
        if (!$this->tableReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Menu preferences are not set up on this server yet.',
            ]);
        }

        $input = $request->input('hidden', []);
        if (!is_array($input)) {
            $input = [];
        }

        // Sanitise: strings only, trimmed, capped, de-duplicated, and with the
        // never-hideable slugs stripped so a crafted request cannot empty the menu.
        $clean = [];
        foreach ($input as $slug) {
            if (!is_string($slug)) {
                continue;
            }
            $slug = substr(trim($slug), 0, 120);
            if ($slug === '' || in_array($slug, self::NON_HIDEABLE, true)) {
                continue;
            }
            $clean[$slug] = true;
            if (count($clean) >= 200) {
                break;
            }
        }
        $clean = array_keys($clean);

        DB::table('t_sys_user_setting')->updateOrInsert(
            ['user_id' => auth()->id(), 'setting_key' => self::KEY],
            ['setting_value' => json_encode($clean), 'updated_at' => now()]
        );

        return response()->json(['success' => true, 'hidden' => $clean]);
    }
}
