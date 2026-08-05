<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Which machine was this on?" — the ONE answer every rule will read (Phase C).
 *
 * WHY IT IS A SEPARATE CLASS FROM VehicleService
 * VehicleService is the admin side: creating machines, handing them over, photos.
 * This is the read side, called on hot paths (per claim, per attendance day, per
 * alert) and eventually from inside the meter rules themselves. Keeping it small,
 * read-only and cached means the rules can lean on it without dragging the whole
 * admin surface in behind them.
 *
 * ⭐ RESOLUTION ORDER — and why it does NOT stamp anything
 *
 *   1. t_ops_attendance.vehicle_id      → a manager said so for that ONE day
 *   2. the assignment covering that date → who held what, from history
 *   3. t_ops_rider_profile.default_vehicle_id → his usual machine
 *   4. NULL                              → behave exactly as today
 *
 * The plan originally had every meter event stamp `attendance.vehicle_id` as it
 * happened. That is not needed and not safe: the assignment table ALREADY carries
 * the history (step 2 reads it by date, so a later reassignment cannot rewrite the
 * past), while stamping would mean editing check-in, checkout, the home-meter
 * submission and the photo upload — the most sensitive paths in the system — for
 * information that is already derivable. So `attendance.vehicle_id` is written in
 * exactly ONE place: the manager's explicit day override. Nothing here writes.
 *
 * ⚠ TRANSFER-DAY AMBIGUITY IS REAL AND IS RESOLVED DELIBERATELY. On the day a bike
 *   changes hands, the outgoing rider's row (released_on = that date) and the
 *   incoming rider's row (assigned_on = that date) BOTH cover it. For the incoming
 *   rider two of his own rows can match at once — the bike he gave up that morning
 *   and the one he took that afternoon. The open row wins, then the latest
 *   assigned_on. When that guess is wrong, the manager's day override (step 1) is
 *   the answer — which is exactly what it exists for.
 */
class VehicleResolver
{
    /** Per-process memo. These are asked the same question repeatedly in one render. */
    private static array $dayCache = [];
    private static array $vehicleCache = [];

    public function available(): bool
    {
        return (new VehicleService())->available();
    }

    /** Is the Phase-C behaviour switch on? Rules consult this before changing anything. */
    public function rulesEnabled(): bool
    {
        return (new VehicleService())->rulesEnabled();
    }

    /**
     * The vehicle id this rider was on for that date, or null when unknown.
     * Null is the honest "we cannot tell" answer and callers must treat it as
     * "behave as before", never as "no vehicle".
     */
    public function vehicleForDay(int $userId, string $date): ?int
    {
        $date = substr($date, 0, 10);
        $key  = $userId . '|' . $date;
        if (array_key_exists($key, self::$dayCache)) return self::$dayCache[$key];

        $out = null;
        try {
            if ($this->available()) {
                // 1. the manager's explicit override for that day
                $override = DB::table('t_ops_attendance')
                    ->where('user_id', $userId)
                    ->where('attendance_date', $date)
                    ->whereNotNull('vehicle_id')
                    ->value('vehicle_id');

                if ($override) {
                    $out = (int) $override;
                } else {
                    // 2. what he held on that date. Open row first, then the most
                    //    recently started — see the transfer-day note above.
                    $row = DB::table(VehicleService::T_ASSIGN)
                        ->where('user_id', $userId)
                        ->where('assigned_on', '<=', $date)
                        ->where(function ($q) use ($date) {
                            $q->whereNull('released_on')->orWhere('released_on', '>=', $date);
                        })
                        ->orderByRaw('released_on IS NULL DESC')
                        ->orderByDesc('assigned_on')->orderByDesc('id')
                        ->value('vehicle_id');

                    // 3. his usual machine
                    $out = $row
                        ? (int) $row
                        : ((new VehicleService())->defaultVehicleFor($userId));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('vehicleForDay failed', ['user' => $userId, 'date' => $date, 'error' => $e->getMessage()]);
            $out = null;
        }

        return self::$dayCache[$key] = $out;
    }

    /**
     * Who had this vehicle on that date — the reverse question, and the one that
     * makes an alert about a MACHINE reachable: a morning flag on AY-4771 has to
     * go to whoever has it today, not whoever had it last month.
     */
    public function riderForVehicleDay(int $vehicleId, string $date): ?int
    {
        $date = substr($date, 0, 10);
        try {
            if (!$this->available()) return null;

            // An explicit override outranks the assignment, same as the forward direction.
            $byOverride = DB::table('t_ops_attendance')
                ->where('vehicle_id', $vehicleId)
                ->where('attendance_date', $date)
                ->value('user_id');
            if ($byOverride) return (int) $byOverride;

            $row = DB::table(VehicleService::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)
                ->where('assigned_on', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('released_on')->orWhere('released_on', '>=', $date);
                })
                ->orderByRaw('released_on IS NULL DESC')
                ->orderByDesc('assigned_on')->orderByDesc('id')
                ->value('user_id');

            return $row ? (int) $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Is that date a TRANSFER day for the vehicle — i.e. did an assignment start or
     * end on it? The handover ride is legitimate travel, so Phase C allows
     * VEHICLE_TRANSFER_GRACE_KM on top of the continuity threshold and suppresses
     * "no home start" for it. Nothing consults this yet; it is here so the rule and
     * the definition live together.
     */
    public function isTransferDay(int $vehicleId, string $date): bool
    {
        $date = substr($date, 0, 10);
        try {
            if (!$this->available()) return false;
            return DB::table(VehicleService::T_ASSIGN)
                ->where('vehicle_id', $vehicleId)
                ->where(function ($q) use ($date) {
                    $q->whereDate('assigned_on', $date)->orWhereDate('released_on', $date);
                })->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A vehicle row (cached), or null. */
    public function vehicle(?int $vehicleId)
    {
        if (!$vehicleId) return null;
        if (array_key_exists($vehicleId, self::$vehicleCache)) return self::$vehicleCache[$vehicleId];
        try {
            $v = DB::table(VehicleService::T_VEHICLE)->where('id', $vehicleId)->first();
        } catch (\Throwable $e) {
            $v = null;
        }
        return self::$vehicleCache[$vehicleId] = $v;
    }

    /** Short label for messages and chips: the plate, else the nickname. */
    public function labelFor(?int $vehicleId): ?string
    {
        $v = $this->vehicle($vehicleId);
        if (!$v) return null;
        $reg  = trim((string) ($v->reg_no ?? ''));
        $nick = trim((string) ($v->nickname ?? ''));
        return $reg !== '' ? $reg : ($nick !== '' ? $nick : ('Vehicle #' . $v->id));
    }

    /**
     * Does the company buy this day's fuel? Vehicle-first, falling back to the
     * rider flag when there is no vehicle to ask.
     *
     * ⚠ GATED ON `VEHICLE_RULES`. While the switch is off this returns the rider
     *   flag unchanged — the whole point of Phase A/B being inert. Phase C turns it
     *   on, and only then does an assignment start deciding fuel treatment.
     */
    public function isCompanyDay(int $userId, string $date): bool
    {
        // ⚠⚠ THE RAW FLAG, never FuelClaimRules::ridesCompanyBike() — since Phase C
        //    that method asks THIS one, so calling it here would recurse forever.
        $riderFlag = (new FuelClaimRules())->profileCompanyBikeFlag($userId);
        if (!$this->rulesEnabled()) return $riderFlag;

        $v = $this->vehicle($this->vehicleForDay($userId, $date));
        return $v ? ((int) $v->is_company === 1) : $riderFlag;
    }

    /**
     * ⭐ EVERY rider on a company machine on that date — the SET form of
     *    `isCompanyDay`, for the half-dozen places that need a cohort rather
     *    than one answer (month flags, fleet reports, the checkout classifier,
     *    the GPS bypass sheet).
     *
     * ⚠ WHY A SET AND NOT A LOOP. Those callers already run one query for the
     *   whole cohort; asking the resolver per rider would turn a page render
     *   into N+1. This stays a handful of queries no matter how many riders.
     *
     * Composition, in the same order of authority as `vehicleForDay`:
     *   • start from the profile checkbox (the fallback population);
     *   • anyone HOLDING a company vehicle that date is added;
     *   • anyone whose machine that date is NOT a company one is removed —
     *     that is the case the checkbox alone gets wrong once bikes move.
     *
     * Returns user ids. While VEHICLE_RULES is off it is exactly the old
     * `where('company_bike', 1)` population.
     */
    public function companyRiderIdsFor(string $date): array
    {
        $date = substr($date, 0, 10);
        try {
            $flagged = DB::table('t_ops_rider_profile')
                ->where('company_bike', 1)->pluck('user_id')
                ->map(fn ($v) => (int) $v)->all();

            if (!$this->rulesEnabled() || !$this->available()) return $flagged;

            // vehicle id => is_company
            $isCompany = DB::table(VehicleService::T_VEHICLE)
                ->pluck('is_company', 'id')
                ->map(fn ($v) => (int) $v === 1)->toArray();

            // Who held what that day: assignments, then overrides on top.
            $held = [];
            foreach (DB::table(VehicleService::T_ASSIGN)
                        ->where('assigned_on', '<=', $date)
                        ->where(function ($q) use ($date) {
                            $q->whereNull('released_on')->orWhere('released_on', '>=', $date);
                        })
                        ->orderBy('assigned_on')->orderBy('id')
                        ->get(['user_id', 'vehicle_id']) as $a) {
                $held[(int) $a->user_id] = (int) $a->vehicle_id;   // later row wins
            }
            foreach (DB::table('t_ops_attendance')
                        ->where('attendance_date', $date)
                        ->whereNotNull('vehicle_id')
                        ->get(['user_id', 'vehicle_id']) as $o) {
                $held[(int) $o->user_id] = (int) $o->vehicle_id;   // override outranks
            }

            $out = array_flip($flagged);
            foreach ($held as $uid => $vid) {
                if (!empty($isCompany[$vid])) $out[$uid] = true;   // on a company machine
                else unset($out[$uid]);                            // on his own → not
            }
            return array_map('intval', array_keys($out));
        } catch (\Throwable $e) {
            Log::warning('companyRiderIdsFor failed', ['date' => $date, 'error' => $e->getMessage()]);
            // Fail back to the legacy population rather than emptying a report.
            try {
                return DB::table('t_ops_rider_profile')->where('company_bike', 1)
                    ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    /**
     * Where this rider's machine sleeps on that date: the VEHICLE's fixed base when
     * it has one (the van's parking), otherwise the rider's own home pin.
     *
     * A home pin is a fact about the PERSON — if Danish takes Arslan's bike home the
     * fence must be Danish's house, not Arslan's. A base is a fact about the MACHINE
     * — the van's yard does not move when the driver changes. Hence base first, then
     * the person.
     *
     * ⚠ Also gated on `VEHICLE_RULES`: until Phase C this returns exactly what
     *   HomeJourneyService::riderHomePin() has always returned.
     */
    public function overnightPinFor(int $userId, string $date): ?array
    {
        $riderPin = (new HomeJourneyService())->riderHomePin($userId);
        if (!$this->rulesEnabled()) return $riderPin;

        $v = $this->vehicle($this->vehicleForDay($userId, $date));
        if ($v && $v->base_latitude !== null && $v->base_longitude !== null) {
            return [
                'lat'      => (float) $v->base_latitude,
                'lng'      => (float) $v->base_longitude,
                'radius_m' => ($v->base_radius_m && $v->base_radius_m > 0)
                    ? (int) $v->base_radius_m
                    : (int) (new HomeJourneyService())->config('HOME_RADIUS_M', 300),
                'source'   => 'vehicle_base',
                'label'    => 'parking',
            ];
        }
        return $riderPin;
    }

    /**
     * Record WHICH machine a fuel/service claim was for, after the row exists.
     *
     * ⭐ WHY IT IS STAMPED RATHER THAN DERIVED. Everything else here is resolved on
     *    read, because the assignment table is the history. A claim is different: it
     *    is a permanent financial record, and "which bike was this Rs 3,000 service
     *    for" must be frozen at filing time — the same reasoning that freezes
     *    `service_due_km` before an approval resets the clock. A reassignment months
     *    later must not silently re-attribute old money.
     *
     * ⭐ WHY IT RUNS AFTER `create()` INSTEAD OF INSIDE IT. Adding the key to the
     *    create array would go through mass assignment (silently dropped if the
     *    model's $fillable is not updated) and would hard-fail on a server where
     *    batch 13 has not been run yet. A guarded update afterwards can do neither.
     *
     * Non-fatal by design: a claim that saved must never be reported as failed
     * because its vehicle could not be worked out.
     */
    public function stampClaim(?int $requestId, int $forUserId, ?string $category, ?string $expenseDate): void
    {
        try {
            if (!$requestId) return;
            if (!in_array((string) $category, ['Petrol', 'Maintenance'], true)) return;
            if (!$this->available()) return;
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_req_master', 'vehicle_id')) return;

            $date = $expenseDate ? substr((string) $expenseDate, 0, 10) : date('Y-m-d');
            $vid  = $this->vehicleForDay($forUserId, $date);
            if (!$vid) return;

            DB::table('t_req_master')->where('id', $requestId)
                ->whereNull('vehicle_id')          // never overwrite a correction
                ->update(['vehicle_id' => $vid]);
        } catch (\Throwable $e) {
            Log::warning('stampClaim skipped', ['request_id' => $requestId, 'error' => $e->getMessage()]);
        }
    }

    /** Tests and long-running processes need to drop the memo. */
    public static function flush(): void
    {
        self::$dayCache = [];
        self::$vehicleCache = [];
    }
}
