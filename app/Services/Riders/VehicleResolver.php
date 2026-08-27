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
    /** [vehicleId => [date => true]] — the days that machine changed hands. */
    private static array $transferCache = [];

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
     * ⭐⭐ PHASE D — "WHAT IS HE HOLDING **RIGHT NOW**?"
     *
     * ⚠⚠ THIS IS NOT `vehicleForDay($u, today)` AND THE DIFFERENCE IS THE WHOLE
     *    POINT. A row released TODAY still *covers* today by date, because the
     *    handover happened partway through it. That is right for history (the
     *    morning's kilometres really were his) and wrong for a live gate: at
     *    5 pm Waseem would still be asked to close the meter of a bike that left
     *    at 2 pm. So the live gates ask THIS, and only history asks by date.
     *
     * Order: the manager's override for today (he said so explicitly) → the OPEN
     * assignment → nothing.
     *
     * ⚠ `t_ops_rider_profile.default_vehicle_id` is DELIBERATELY NOT consulted.
     *   It is a convenience mirror of the open assignment and can go stale
     *   (a live example: one rider's mirror points at a colleague's bike). The
     *   assignment table is the truth; the mirror is not.
     */
    public function currentVehicleFor(int $userId, ?string $date = null): ?int
    {
        $date = $date ? substr($date, 0, 10) : date('Y-m-d');
        try {
            if (!$this->available()) return null;

            $override = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', $date)
                ->whereNotNull('vehicle_id')
                ->value('vehicle_id');
            if ($override) return (int) $override;

            $open = DB::table(VehicleService::T_ASSIGN)
                ->where('user_id', $userId)
                ->whereNull('released_on')
                ->where('assigned_on', '<=', $date)
                ->orderByDesc('assigned_on')->orderByDesc('id')
                ->value('vehicle_id');

            return $open ? (int) $open : null;
        } catch (\Throwable $e) {
            Log::warning('currentVehicleFor failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Has the registry ever known this person to hold a machine? (Any assignment
     * row, open or closed.)
     *
     * ⭐ WHY IT EXISTS. "He holds nothing" and "we have never recorded anything
     *    about him" look identical from the assignment table, but they must not be
     *    treated identically anywhere that REMOVES A CAPABILITY. Farooq files flat
     *    petrol claims and has no registered machine — that is his only path, and
     *    it has to keep working. Waseem handed a bike back last week; his fuel door
     *    should close. This is the only thing that tells them apart.
     *
     * ⚠ Rules that merely stop DEMANDING something (the meter gates) do not use
     *   this — see `hasRiderProfile`. Not asking a man for a reading can't hurt
     *   him; taking away the only way he claims his petrol can.
     */
    public function trackedByRegistry(int $userId): bool
    {
        static $memo = [];
        if (isset($memo[$userId])) return $memo[$userId];
        try {
            if (!$this->available()) return false;   // not memoized — tables may appear
            return $memo[$userId] = DB::table(VehicleService::T_ASSIGN)->where('user_id', $userId)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Is this person a rider at all? Store staff and managers have no profile row.
     *  Memoized — the month sheets ask per row. */
    public function hasRiderProfile(int $userId): bool
    {
        static $memo = [];
        if (isset($memo[$userId])) return $memo[$userId];
        try {
            return $memo[$userId] = DB::table('t_ops_rider_profile')->where('user_id', $userId)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * ⭐⭐ THE ONE ANSWER EVERY METER GATE ASKS (owner ruling R2, Aug-6):
     *    **no machine in the registry = no meter.**
     *
     *    true  → he holds a machine, so the reading is compulsory
     *    false → he holds nothing, so nothing is demanded of him
     *    null  → the registry has no opinion; the caller uses the old profile
     *            checkbox exactly as before
     *
     * Null is returned when the switch is off, the tables are missing, or the
     * person is not a rider at all — the three cases where changing behaviour
     * would be guessing. That is what keeps this inert until `VEHICLE_RULES`
     * flips, and harmless for store staff forever.
     *
     * ⚠ EVERY gate must route through this ONE method — the app's
     *   `meter_required` flag, the check-in gate and the checkout gate. If the
     *   flag and the gate ever disagree, the rider is shown a door he cannot
     *   open, which is the precise shape of a lockout.
     */
    public function meterRequiredNow(int $userId, ?string $date = null): ?bool
    {
        try {
            if (!$this->available() || !$this->rulesEnabled()) return null;
            if (!$this->hasRiderProfile($userId))               return null;
            return $this->currentVehicleFor($userId, $date) !== null;
        } catch (\Throwable $e) {
            Log::warning('meterRequiredNow failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return null;                      // no opinion = the old behaviour
        }
    }

    /**
     * ⭐⭐ "IS HE ON A COMPANY MACHINE RIGHT NOW?" — the HOLDS-NOW question, in ONE place.
     *
     * true  → he holds a company machine this minute (the home-meter flow is his)
     * false → he holds his own bike, or nothing at all
     * null  → the registry has no opinion (rules off, tables missing, not a rider)
     *
     * ⚠⚠ CALLERS MUST TEST `=== false`, NEVER falsy. `null` means "cannot say" and has to fall
     *    through to the pre-registry behaviour — treating it as "no" would silence the home-meter
     *    flow for every rider the moment the registry went quiet.
     *
     * ⚠ This lived as a private helper on RiderController, which is why the escalation sweep in
     *   HomeJourneyService could not reach it and shipped without the guard its two siblings had
     *   (see openEscalations). It belongs here, next to `currentVehicleFor` and `meterRequiredNow`,
     *   so every surface asks the identical question. RiderController now delegates to this.
     */
    public function holdsCompanyMachineNow(int $userId): ?bool
    {
        try {
            if (!$this->available() || !$this->rulesEnabled()) return null;
            if (!$this->hasRiderProfile($userId))               return null;
            $vid = $this->currentVehicleFor($userId);
            if ($vid === null) return false;
            $v = $this->vehicle($vid);
            return $v ? ((int) $v->is_company === 1) : false;
        } catch (\Throwable $e) {
            Log::warning('holdsCompanyMachineNow failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return null;                       // no opinion = never block the old flow
        }
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
     * ⭐⭐ AUG-2026 — `vehicleForDay` FOR A WHOLE MONTH AT ONCE, as
     *    ['userId|Y-m-d' => vehicleId].
     *
     * The attribution engine has to answer "which machine was this rider on?" for
     * every rider on every day of a month. Asking `vehicleForDay` per day is ~250
     * round trips for one page; this loads the assignment table (a few dozen rows)
     * and the month's overrides once and resolves in memory.
     *
     * ⚠⚠ IT MUST AGREE WITH `vehicleForDay` ROW FOR ROW. Both now sort candidate
     *   assignments through the SAME comparator (`assignmentRank`) — do not
     *   re-implement the precedence here or the machine's chain and the rider's
     *   chip will disagree on exactly the days that matter (handovers).
     *   `test_attribution.php` asserts bulk == per-day across the live data.
     */
    public function vehiclesForDays(array $userIds, string $from, string $to): array
    {
        $out = [];
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) return $out;

        try {
            if (!$this->available()) return $out;

            // Every assignment that can touch the range, grouped per rider.
            $byUser = [];
            foreach (DB::table(VehicleService::T_ASSIGN)
                        ->whereIn('user_id', $userIds)
                        ->where('assigned_on', '<=', $to)
                        ->where(function ($q) use ($from) {
                            $q->whereNull('released_on')->orWhere('released_on', '>=', $from);
                        })
                        ->get(['id', 'user_id', 'vehicle_id', 'assigned_on', 'released_on']) as $a) {
                $byUser[(int) $a->user_id][] = [
                    'id'         => (int) $a->id,
                    'vehicle_id' => (int) $a->vehicle_id,
                    'from'       => substr((string) $a->assigned_on, 0, 10),
                    'to'         => $a->released_on ? substr((string) $a->released_on, 0, 10) : null,
                ];
            }

            // His usual machine — step 3, only when no assignment covers the day.
            $defaults = [];
            try {
                $defaults = DB::table('t_ops_rider_profile')
                    ->whereIn('user_id', $userIds)
                    ->whereNotNull('default_vehicle_id')
                    ->pluck('default_vehicle_id', 'user_id')->toArray();
            } catch (\Throwable $e) {
                $defaults = [];
            }

            $cursor = \Carbon\Carbon::parse($from)->startOfDay();
            $last   = \Carbon\Carbon::parse($to)->startOfDay();
            $dates  = [];
            while ($cursor->lte($last)) { $dates[] = $cursor->format('Y-m-d'); $cursor->addDay(); }

            foreach ($userIds as $uid) {
                foreach ($dates as $d) {
                    $cands = [];
                    foreach ($byUser[$uid] ?? [] as $a) {
                        if ($a['from'] <= $d && ($a['to'] === null || $a['to'] >= $d)) $cands[] = $a;
                    }
                    if ($cands) {
                        usort($cands, fn ($x, $y) => self::assignmentRank($y) <=> self::assignmentRank($x));
                        $out[$uid . '|' . $d] = $cands[0]['vehicle_id'];
                    } elseif (!empty($defaults[$uid])) {
                        $out[$uid . '|' . $d] = (int) $defaults[$uid];
                    }
                }
            }

            // Step 1 outranks everything: the manager's explicit day override.
            foreach (DB::table('t_ops_attendance')
                        ->whereIn('user_id', $userIds)
                        ->whereBetween('attendance_date', [$from, $to])
                        ->whereNotNull('vehicle_id')
                        ->get(['user_id', 'attendance_date', 'vehicle_id']) as $o) {
                $out[(int) $o->user_id . '|' . substr((string) $o->attendance_date, 0, 10)]
                    = (int) $o->vehicle_id;
            }
        } catch (\Throwable $e) {
            Log::warning('vehiclesForDays failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * The ONE precedence rule for "which of these assignments wins this date":
     * an OPEN row first, then the latest `assigned_on`, then the highest id —
     * the SQL ordering `vehicleForDay` uses, expressed as a sortable key so the
     * bulk resolver cannot drift from it.
     */
    private static function assignmentRank(array $a): array
    {
        return [$a['to'] === null ? 1 : 0, $a['from'], $a['id']];
    }

    /**
     * ⭐ PHASE D — everyone holding ANY machine on that DATE (windows + overrides),
     *    as [user_id => vehicle_id]. The set form of `vehicleForDay`, for the
     *    sheets and reports that judge a whole roster per day: the attendance
     *    page's ⛽ tick, the mobile sheet, the meter-miss month column.
     *
     * ⚠ DATE-scoped, not now-scoped — reports judge history, so a row released
     *   last week still covers the days it spanned. The live gates use
     *   `currentVehicleFor`, which is the opposite choice, deliberately.
     *
     * Memoized per process: a month grid asks about the same date once per rider.
     */
    public function machineHoldersOn(string $date): array
    {
        $date = substr($date, 0, 10);
        static $memo = [];
        if (isset($memo[$date])) return $memo[$date];

        $held = [];
        try {
            if (!$this->available()) return $memo[$date] = [];

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
        } catch (\Throwable $e) {
            Log::warning('machineHoldersOn failed', ['date' => $date, 'error' => $e->getMessage()]);
            $held = [];
        }
        return $memo[$date] = $held;
    }

    /**
     * Should a REPORT excuse this rider's meter on this date? True only when the
     * registry is authoritative (rules on, tables there), he is a rider, and he
     * held nothing that day. Anything else → "no opinion", judge as before.
     *
     * This is the report-side twin of `meterRequiredNow`: same blanket rule,
     * date-scoped instead of now-scoped. Keeping the pair adjacent-but-separate
     * is deliberate — a report that judged history by today's holding would
     * retroactively excuse (or accuse) whole months on every handover.
     */
    public function meterExcusedOn(int $userId, string $date): bool
    {
        try {
            if (!$this->available() || !$this->rulesEnabled()) return false;
            if (!$this->hasRiderProfile($userId))               return false;
            return !isset($this->machineHoldersOn($date)[$userId]);
        } catch (\Throwable $e) {
            return false;                     // failure = judge as before
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

            // ⭐ Memoized per VEHICLE, not per (vehicle, date). Callers ask about a
            //   whole month of days for one machine — a per-date cache would still
            //   be ~30 queries. Assignment rows per vehicle are a handful.
            // ⚠ Held on the CLASS, not in a function static, so `flush()` clears it
            //   after an assign/release — a stale answer here would mean the sheet
            //   excusing (or accusing) the wrong day.
            if (!array_key_exists($vehicleId, self::$transferCache)) {
                $days = [];
                foreach (DB::table(VehicleService::T_ASSIGN)
                            ->where('vehicle_id', $vehicleId)
                            ->get(['assigned_on', 'released_on']) as $a) {
                    if ($a->assigned_on) $days[substr((string) $a->assigned_on, 0, 10)] = true;
                    if ($a->released_on) $days[substr((string) $a->released_on, 0, 10)] = true;
                }
                self::$transferCache[$vehicleId] = $days;
            }
            return isset(self::$transferCache[$vehicleId][$date]);
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
        if ($v) return (int) $v->is_company === 1;

        // ⭐ PHASE D — HOLDING NOTHING IS AN ANSWER, NOT A GAP (owner ruling R2).
        //   This used to fall back to the checkbox, which is frozen at whatever it
        //   was the day the bike was taken away: a rider who handed his machine
        //   back stayed on the company fuel protocol, kept his overnight/home
        //   checks armed and stayed in every company cohort. For a RIDER the
        //   registry is now the whole answer — no machine that day, no company day.
        //
        // ⚠ Scoped to people who actually have a rider profile. Managers and store
        //   staff never had a machine and must keep behaving exactly as before;
        //   the checkbox still speaks for them.
        return $this->hasRiderProfile($userId) ? false : $riderFlag;
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

            // ⭐ PHASE D — and drop every RIDER holding nothing at all. Must mirror
            //   isCompanyDay()'s "holding nothing is an answer" exactly: this is the
            //   set form of the same rule, and a cohort that disagreed with the
            //   per-rider answer would flag a man in a report for a duty the live
            //   rules had already excused him from. Non-riders keep the checkbox.
            $riderIds = DB::table('t_ops_rider_profile')->pluck('user_id')
                ->map(fn ($v) => (int) $v)->flip()->toArray();
            foreach (array_keys($out) as $uid) {
                if (isset($riderIds[$uid]) && !isset($held[$uid])) unset($out[$uid]);
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
    /**
     * ⭐⭐ A METERED (PER-KM) CLAIM NAMES ITS OWN EVIDENCE — use it (Aug-22 2026).
     *
     * The per-km flow sends `attendance_id`: the exact row whose `meter_distance` produced the
     * amount. That reading knows which machine it was of (step C stamps), so the MONEY can follow
     * the very same machine as the KILOMETRES it is paying for. No inference, no date guessing.
     *
     * ⚠⚠ WHY THE DATE ANSWER IS NOT ENOUGH. These claims are filed the NEXT DAY for YESTERDAY's
     *    distance — REQ-0262 (Rs 1,007 for 106 km on 20 Aug) was created on the 21st, REQ-0280
     *    (Rs 323 for 34 km on 21 Aug) on the 22nd. So neither `currentVehicleFor` (wrong day) nor
     *    `vehicleForDay` (returns whatever he ENDED that day on — the van) can get them right.
     *    Both were own-bike money and both landed on the van. Only the attendance row can say.
     *
     * ⚠ Returns null on a SPLIT day (the two ends stamped to different machines): `end - start`
     *   then spans two odometers and the distance itself is meaningless, so there is nothing
     *   honest to attribute. The caller falls back, and the claim is flagged unstamped on the card.
     */
    private function vehicleFromReadingStamps(?int $attendanceId): ?int
    {
        if (!$attendanceId) return null;
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'meter_start_vehicle_id')) {
                return null;
            }
            $r = DB::table('t_ops_attendance')->where('id', $attendanceId)
                ->first(['meter_start_vehicle_id', 'meter_end_vehicle_id']);
            if (!$r) return null;

            $s = $r->meter_start_vehicle_id ? (int) $r->meter_start_vehicle_id : null;
            $e = $r->meter_end_vehicle_id ? (int) $r->meter_end_vehicle_id : null;

            if ($s && $e) return $s === $e ? $s : null;   // split day — see the note above
            return $s ?: $e;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ⭐⭐ `$explicitVehicleId` — SOMEBODY SAID SO, AND THAT OUTRANKS EVERY GUESS (Aug-27 2026).
     *
     * Two callers now know the machine for certain and could never tell us: the manager's
     * petrol modal, where he picks the vehicle off the rider's own day, and the per-km
     * claim, where the legs builder has already decided which machine's kilometres are
     * being paid for. Before this, both fell through to the date guess below — which is
     * exactly how own-bike money landed on the van (see the note above).
     *
     * ⚠ It is first in the order of authority but still cannot overwrite an existing
     *   value: the `whereNull('vehicle_id')` guard stays, because a correction someone
     *   made deliberately must survive a re-file.
     */
    public function stampClaim(?int $requestId, int $forUserId, ?string $category,
                               ?string $expenseDate, ?int $attendanceId = null,
                               ?int $explicitVehicleId = null): void
    {
        try {
            if (!$requestId) return;
            if (!in_array((string) $category, ['Petrol', 'Maintenance'], true)) return;
            if (!$this->available()) return;
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_req_master', 'vehicle_id')) return;

            $date = $expenseDate ? substr((string) $expenseDate, 0, 10) : date('Y-m-d');

            // ⭐⭐ FILING FOR TODAY? ASK WHAT HE IS HOLDING **RIGHT NOW** (Aug-22 2026).
            //
            // ⚠⚠ THE PROD INCIDENT. `vehicleForDay` ranks the OPEN assignment first, so it
            //    answers with whatever he ends the DAY on — and a claim filed at 12:37 on his own
            //    bike was being stamped with a van handed to him at 12:57, twenty minutes LATER.
            //    Own-bike per-km money then appears on a company machine's card, which is the one
            //    thing that must never happen: the two schemes are different money.
            //
            // ⭐ Same principle as the meter stamp (`meterStampFields`) and for the same reason —
            //   a claim is a permanent record of ONE machine at ONE moment, so it is frozen from
            //   the "right now" answer, not a day-level one.
            //
            // ⚠ Only for TODAY. A claim filed for an earlier date is history, and
            //   `currentVehicleFor` would then be the wrong question entirely — that is precisely
            //   the mistake this guard exists to avoid making in the other direction.
            // 0. Somebody named the machine outright — nothing beats being told.
            $vid = $explicitVehicleId ?: null;

            // 1. The claim's OWN evidence, when it has any — the strongest answer by far.
            if (!$vid) $vid = $this->vehicleFromReadingStamps($attendanceId);

            // 2. Otherwise: filing for today = what he holds right now; an earlier date = the
            //    day-level answer, which is all history can offer.
            if (!$vid) {
                $vid = ($date === date('Y-m-d'))
                    ? ($this->currentVehicleFor($forUserId) ?: $this->vehicleForDay($forUserId, $date))
                    : $this->vehicleForDay($forUserId, $date);
            }
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
        self::$transferCache = [];
    }
}
