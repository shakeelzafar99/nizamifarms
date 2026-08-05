<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Riders\FleetFuelService;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * "My vehicle" — the rider's own machine, in his own app (Aug-2026).
 *
 * Owner's ask: the rider should see what is assigned to him, and on tapping it get
 * the history of his fuel and maintenance requests, the condition photos with their
 * dates, and the same fuel average the Bikes screen shows.
 *
 * ⭐ SELF-SCOPED, SO IT NEEDS NO PERMISSION. It resolves the CALLER's own
 *    assignment and returns only that machine. A rider seeing his own bike's
 *    odometer and his own claims is not new access — he filed those claims. The
 *    fleet-wide roster and everybody else's costs stay behind `view_bike_costs`,
 *    which this endpoint never consults and never exposes.
 *
 * ⭐ THE NUMBERS COME FROM FleetFuelService, NOT FROM A PARALLEL CALCULATION.
 *    `rs_per_fuelled_km` here is literally the row the Bikes tab renders for him,
 *    read from the same cache key. If a rider and a manager ever quote different
 *    figures for the same bike, one of them is reading a copy — so there is no copy.
 */
class MyVehicleController extends Controller
{
    public function show(Request $request, VehicleResolver $res, VehicleService $veh, FleetFuelService $fleet)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 401);
        }

        try {
            // Before batch 13 this is simply "no vehicle" — the app hides the card
            // and nothing else changes. Never an error.
            if (!$veh->available()) {
                return response()->json([
                    'success' => true, 'has_vehicle' => false, 'vehicle' => null,
                    'reason' => 'not_set_up',
                ]);
            }

            $month = $this->safeMonth($request->query('month'));
            $today = Carbon::today()->format('Y-m-d');
            $uid   = (int) $user->id;

            $vehicleId = $res->vehicleForDay($uid, $today);
            if (!$vehicleId) {
                return response()->json([
                    'success' => true, 'has_vehicle' => false, 'vehicle' => null,
                    'reason' => 'none_assigned', 'month' => $month,
                ]);
            }

            $v = $veh->find($vehicleId);
            if (!$v) {
                return response()->json(['success' => true, 'has_vehicle' => false, 'vehicle' => null,
                                         'reason' => 'none_assigned', 'month' => $month]);
            }

            // Only the parts of the admin payload a rider should see. His own
            // machine's condition and service state — not who else has had it,
            // which is a management question about other people.
            unset($v['history']);
            // The "needs a home pin" nag is a MANAGER's job (it points at the
            // Riders page, which no rider can open). It stays on the web fleet
            // card; showing a rider an instruction he cannot act on is noise.
            unset($v['needs_home_pin']);

            // ⭐ BRIEF MODE — what the one-line card on the Attendance screen needs,
            //   and nothing else.
            //
            //   `fetchAttendanceData()` fires from THIRTEEN places (check-in,
            //   checkout, every meter upload, pull-to-refresh, screen focus…). The
            //   full payload runs FleetFuelService::monthSummary(), which computes
            //   the whole company's month — an expensive call to render a plate and
            //   an odometer. Brief mode skips the month summary and the claim list
            //   entirely, so the card costs a couple of cheap reads no matter how
            //   often the attendance screen refreshes. The full screen asks for
            //   everything.
            if ($request->boolean('brief')) {
                unset($v['photos']);
                return response()->json([
                    'success'     => true,
                    'has_vehicle' => true,
                    'brief'       => true,
                    'vehicle'     => $v,
                ]);
            }

            return response()->json([
                'success'     => true,
                'has_vehicle' => true,
                'month'       => $month,
                'vehicle'     => $v,
                'assignment'  => $this->assignmentFor($vehicleId, $uid),
                // HIS running cost per km — the manager's Bikes figure, unchanged.
                'summary'     => $this->summaryFor($uid, $month, $fleet),
                // ⭐ THE MACHINE'S claims, not the man's (Phase C4). See below.
                'claims'      => $claims = $this->claimsFor($vehicleId, $uid, $month, $veh),
                // ⭐ …and the month totals TOTALLED FROM THOSE SAME ROWS, so the
                //    headline figures can never disagree with the list under them.
                //    (This is a sum of what is displayed, not a second engine.)
                'vehicle_month' => $this->totalsOf($claims),
                // ⭐ THE MACHINE'S running cost: selected month vs the pooled
                //    previous three (owner ruling Aug-4 — lifetime is impossible,
                //    and the old rider-month figure dashed out on day one of a
                //    handover even though the bike had a month of real data).
                'averages'    => $veh->fuelAverages($vehicleId, $month),
                // ⭐ HIS stint on this machine — km + Rs/km since HE took it,
                //    the number that, against last-3, says whether the rider or
                //    the machine is the variable (owner ruling Aug-4).
                'my_stint'    => $veh->keeperStintStats($vehicleId),
                'months'      => $this->recentMonths(),
            ]);
        } catch (\Throwable $e) {
            Log::error('MyVehicle failed', ['user' => $request->user()->id ?? null, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load your vehicle'], 500);
        }
    }

    /** Since when he has had it, and any note the manager left. */
    private function assignmentFor(int $vehicleId, int $userId): ?array
    {
        try {
            $a = DB::table(VehicleService::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)->where('user_id', $userId)
                ->whereNull('released_on')->orderByDesc('id')->first();
            if (!$a) return null;
            return [
                'since' => substr((string) $a->assigned_on, 0, 10),
                'note'  => $a->note,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * His month, as the Bikes tab computes it.
     *
     * ⚠ Reads the SAME cache key the web/mobile Bikes screens use, so a rider and
     *   his manager cannot be shown different figures for the same month. The
     *   monthly summary covers every rider; only this rider's row is returned.
     */
    private function summaryFor(int $userId, string $month, FleetFuelService $fleet): array
    {
        $empty = [
            'fuel_rs' => 0, 'maint_rs' => 0, 'rs_per_fuelled_km' => null,
            'fuelled_km' => 0, 'work_km' => 0, 'offduty_km' => 0,
        ];
        try {
            $data = Cache::remember("fleet_fuel_month_{$month}", 120,
                fn () => $fleet->monthSummary($month));

            $row = collect($data['riders'] ?? [])->firstWhere('user_id', $userId);
            if (!$row) return $empty;

            return [
                'fuel_rs'           => $row['fuel_rs'] ?? 0,
                'maint_rs'          => $row['maint_rs'] ?? 0,
                // The machine's running cost per kilometre ridden — the same field,
                // with the same label, that the Bikes table shows.
                'rs_per_fuelled_km' => $row['rs_per_fuelled_km'] ?? null,
                'fuelled_km'        => $row['fuelled_km'] ?? 0,
                'work_km'           => $row['work_km'] ?? 0,
                'offduty_km'        => $row['offduty_km'] ?? 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('MyVehicle summary failed', ['error' => $e->getMessage()]);
            return $empty;
        }
    }

    /**
     * ⭐ THIS MACHINE'S fuel and maintenance for the month — READ ONLY.
     *
     * ⚠ IT USED TO BE THE MAN'S, AND THAT WAS WRONG (owner, Aug-4): the screen is
     *   titled with a plate, so a rider newly given DCR-799 was shown his OWN old
     *   personal-bike fills underneath it. The list now belongs to the bike —
     *   including a predecessor's claims, each labelled with who filed it, which
     *   is exactly how a keeper learns what has already been done to the machine
     *   he has just been handed.
     *
     * One query, in VehicleService, shared with the web profile — a rider and his
     * manager must never be looking at two different reconstructions of the same
     * bike's history.
     *
     * `is_mine` lets the app tint his own rows without a second call.
     */
    private function claimsFor(int $vehicleId, int $userId, string $month, VehicleService $veh): array
    {
        try {
            $from = Carbon::parse($month . '-01')->startOfMonth()->format('Y-m-d');
            $to   = Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d');

            return array_map(function (array $c) use ($userId) {
                $c['is_mine'] = ((int) ($c['by_user_id'] ?? 0)) === $userId;
                return $c;
            }, $veh->claimsForVehicle($vehicleId, $from, $to));
        } catch (\Throwable $e) {
            Log::warning('MyVehicle claims failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** Month totals for the MACHINE, summed from the rows the screen displays. */
    private function totalsOf(array $claims): array
    {
        $fuel = 0.0; $maint = 0.0; $pending = 0;
        foreach ($claims as $c) {
            if (($c['category'] ?? '') === 'Petrol') $fuel += (float) ($c['amount'] ?? 0);
            else $maint += (float) ($c['amount'] ?? 0);
            if (!empty($c['is_pending'])) $pending++;
        }
        return [
            'fuel_rs'  => round($fuel, 2),
            'maint_rs' => round($maint, 2),
            'count'    => count($claims),
            'pending'  => $pending,
        ];
    }

    /** The last 6 months, for the month switcher. */
    private function recentMonths(): array
    {
        $out = [];
        $c = Carbon::today()->startOfMonth();
        for ($i = 0; $i < 6; $i++) {
            $out[] = ['value' => $c->format('Y-m'), 'label' => $c->format('M Y')];
            $c->subMonthNoOverflow();
        }
        return $out;
    }

    private function safeMonth($raw): string
    {
        try {
            $c = $raw ? Carbon::parse($raw . '-01') : Carbon::today()->startOfMonth();
        } catch (\Throwable $e) {
            $c = Carbon::today()->startOfMonth();
        }
        if ($c->gt(Carbon::today()->startOfMonth())) $c = Carbon::today()->startOfMonth();
        return $c->format('Y-m');
    }
}
