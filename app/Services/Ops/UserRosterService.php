<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 👥 THE USERS LIST — one roster, one reader, one writer.
 *
 * Owner ruling (9-Sep-2026): the Shift Planner shows the ATTENDANCE list, and the planner
 * page carries the list itself so a missing person can be added on the spot — *"if someone
 * is missing the team can select and make him available in both shift and attendance"*.
 *
 * The list lives in `t_ops_attendance_visibility`, where ⭐ NO ROW MEANS ON THE LIST (only
 * an explicit `is_visible = 0` takes someone off). Reading it is `User::shiftPlannerRoster()`
 * for the planner and AttendanceController's own join for attendance; this service is the
 * shared **membership** view + the single **writer** that web and mobile both go through.
 *
 * ⚠ Who may write is NOT decided here — callers ask
 *   `ShiftAuthorityService::canManageRoster()` first (Shabib + Taimur).
 */
class UserRosterService
{
    public const T = 't_ops_attendance_visibility';

    /**
     * Every active account, with whether it is on the list.
     *
     * ⚠ Deliberately ALL active accounts, not just the ones on the list — the whole point
     *   is to find the person who is missing and tick them on. System/company accounts show
     *   up too; they are simply unticked, and that is the honest picture.
     */
    public function list(): array
    {
        $riderCol = false;
        try { $riderCol = Schema::hasTable('t_ops_rider_profile'); } catch (\Throwable $e) {}

        $q = DB::table('t_sys_user as u')
            ->leftJoin(self::T . ' as av', 'av.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->select(
                'u.id',
                'u.fullname',
                DB::raw('COALESCE(av.is_visible, 1) as on_list'),
                // MAX() so a user with several roles still yields ONE row.
                DB::raw('(SELECT MAX(r.urole_name) FROM t_sys_user_role ur
                            JOIN t_sys_role r ON r.id = ur.role_id
                           WHERE ur.user_id = u.id) as role_name')
            );

        if ($riderCol) {
            $q->addSelect(DB::raw('(SELECT MAX(CASE WHEN p.active = 1 THEN 1 ELSE 0 END)
                                      FROM t_ops_rider_profile p
                                     WHERE p.user_id = u.id) as is_rider'));
        }

        return $q->orderBy('u.fullname')->get()->map(fn ($r) => [
            'user_id'   => (int) $r->id,
            'name'      => $r->fullname,
            'role_name' => $r->role_name,
            'on_list'   => (int) $r->on_list === 1,
            'is_rider'  => (int) ($r->is_rider ?? 0) === 1,
        ])->all();
    }

    /**
     * Put someone on the list, or take them off. THE single writer.
     *
     * ⚠ Writes `is_visible = 1` rather than deleting the row, so an explicit "yes" is
     *   recorded and `hidden_by` / `hidden_at` are cleared. Deleting would also read as
     *   "on the list" but would lose who put them back.
     */
    public function set(int $userId, bool $onList, ?int $actorId = null, ?string $notes = null): void
    {
        $row = [
            'is_visible' => $onList ? 1 : 0,
            'notes'      => $notes,
            'hidden_by'  => $onList ? null : $actorId,
            'hidden_at'  => $onList ? null : now(),
            'updated_at' => now(),
        ];

        if (DB::table(self::T)->where('user_id', $userId)->exists()) {
            DB::table(self::T)->where('user_id', $userId)->update($row);
        } else {
            DB::table(self::T)->insert($row + ['user_id' => $userId, 'created_at' => now()]);
        }
    }

    /**
     * ⚠ Guard for the one genuinely destructive edit: taking somebody OFF the list while
     * they still have a shift change nobody has settled. The planner keeps such a person
     * visible via the safety net in `User::shiftPlannerRoster()` and tags them
     * "not in attendance", so nothing is stranded — but the caller should say so.
     *
     * Returns a human sentence, or null when removing them is clean.
     */
    public function removalWarning(int $userId): ?string
    {
        $today = now()->format('Y-m-d');
        try {
            $live = DB::table('t_ops_user_shift_assignment')
                ->where('user_id', $userId)
                ->where(function ($q) use ($today) {
                    $q->where(function ($w) use ($today) {
                        $w->whereNotNull('effective_to')->whereDate('effective_to', '>=', $today);
                    })->orWhere(function ($w) use ($today) {
                        $w->whereNotNull('effective_from')->whereDate('effective_from', '>', $today);
                    });
                })->exists();
            if ($live) {
                return 'They still have a shift change that has not finished. '
                     . 'They will stay on the planner, marked "not in attendance", until it ends.';
            }
        } catch (\Throwable $e) { /* table missing → nothing to warn about */ }

        return null;
    }
}
