<?php

namespace App\Services\Riders;

use App\Models\Request\RequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fleet & Fuel (Jul-2026) — the ONE place an approved bike service moves the
 * service-due clock.
 *
 * Lives here (not in RequestApprovalController) because a request can become
 * APPROVED on more than one path — the normal L1/L2 approve endpoint, the web
 * form's auto-approve-at-creation branch (a category with no approval levels),
 * and the short-cash-settlement auto-approves that call
 * RequestModel::processApproval() directly from LedgerController / the mobile
 * deposit flow. The hook is wired into processApproval() itself plus the
 * create-time auto-approve, so every current AND future path is covered without
 * each caller having to remember it.
 *
 * Deliberately silent and non-fatal — a failure here must never turn a
 * successful approval into an error for the approver. Idempotent — the stamp
 * is written once (service_due_km guard) and the clock never moves backwards,
 * so being invoked twice for the same request is a no-op.
 */
class BikeServiceClock
{
    /**
     * When a MAINTENANCE request that was an oil change / general service is
     * fully approved and carries the meter reading taken at the time, record it
     * as the bike's last service. That is what makes the Fleet tab's
     * "due in X km" chip self-maintaining.
     */
    public static function onRequestApproved(RequestModel $req): void
    {
        try {
            if ($req->status !== RequestModel::STATUS_APPROVED) return;
            if ($req->expense_category !== 'Maintenance') return;

            // ⭐ Aug-2026: which REGULAR services actually reset the clock is now a
            // property of the chosen type, not of the bucket. The manager's list has
            // several regular types on different schedules (oil 1,200 km, brake shoe
            // 10,000 km); before this, any of them would have reset the one clock and
            // a bike with fresh brake shoes would have looked oil-serviced while its
            // oil was overdue. Untyped rows — every row before this ships — keep the
            // old answer exactly: any oil_change/general resets it.
            if (!app(\App\Services\Riders\MaintenanceTypeService::class)
                    ->resetsClock($req->maintenance_type_id ?? null, $req->service_type)) {
                return;
            }

            $meter = (int) ($req->meter_at_fill ?? 0);
            if ($meter <= 1000) return;      // missing or a dropped-digit typo

            $profile = DB::table('t_ops_rider_profile')
                ->where('user_id', $req->requester_user_id)->first();
            if (!$profile) return;

            // Freeze how far off schedule the bike was, BEFORE the clock moves.
            // Negative = overdue by that many km. Once the update below lands,
            // this can never be recomputed — the old reference point is gone —
            // so a record of a service done 317 km late would be lost forever.
            self::stampServiceDueKm($req, $profile, $meter);

            // Never move the clock backwards — an older service approved late
            // must not undo a newer one already recorded.
            if ($profile->last_service_meter !== null && (int) $profile->last_service_meter >= $meter) return;

            DB::table('t_ops_rider_profile')
                ->where('user_id', $req->requester_user_id)
                ->update([
                    'last_service_meter' => $meter,
                    'last_service_at'    => $req->expense_date
                        ? \Carbon\Carbon::parse($req->expense_date)->format('Y-m-d')
                        : now()->format('Y-m-d'),
                    'updated_at'         => now(),
                ]);

            Log::info('Service clock reset from approved maintenance request', [
                'request_id' => $req->id, 'user_id' => $req->requester_user_id, 'meter' => $meter,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Service clock reset skipped: ' . $e->getMessage(), ['request_id' => $req->id ?? null]);
        }
    }

    /**
     * Write `service_due_km` on the request: km still to run when the service
     * was done (negative = overdue). Read against the bike's own interval when
     * it has one, otherwise the company default.
     *
     * Skipped when the bike has no previous service on record — there is no
     * reference point, and inventing one would put a made-up number on a
     * permanent record. Also non-fatal by design.
     */
    private static function stampServiceDueKm(RequestModel $req, $profile, int $meter): void
    {
        try {
            if ($req->service_due_km !== null) return;          // already frozen
            $last = $profile->last_service_meter !== null ? (int) $profile->last_service_meter : null;
            if ($last === null || $meter <= $last) return;

            // Interval priority: the TYPE's own schedule (Oil Change = 1,200 km)
            // beats the per-bike override, which beats the company default. The
            // type knows best — "how far since the last one" is only meaningful
            // against the schedule for THAT service.
            $interval = 0;
            $type = app(\App\Services\Riders\MaintenanceTypeService::class)
                ->find($req->maintenance_type_id ?? null);
            if ($type && (int) $type->interval_km > 0) {
                $interval = (int) $type->interval_km;
            }
            if ($interval <= 0) {
                $interval = (int) ($profile->service_interval_km ?: 0);
            }
            if ($interval <= 0) {
                $interval = (int) (DB::table('t_fin_config')
                    ->where('config_key', 'BIKE_SERVICE_INTERVAL_KM')->value('config_value') ?: 0);
            }
            if ($interval <= 0) return;

            DB::table('t_req_master')->where('id', $req->id)
                ->update(['service_due_km' => $interval - ($meter - $last)]);
        } catch (\Throwable $e) {
            Log::warning('service_due_km stamp skipped: ' . $e->getMessage(), ['request_id' => $req->id ?? null]);
        }
    }
}
