<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * THE fuel/maintenance claim rules — one implementation, every creation path.
 *
 * WHY THIS EXISTS
 * There are two ways a petrol or maintenance request can be created:
 *   1. the rider's own app          → API\RiderController::createRequest
 *   2. a manager filing FOR someone → Request\RequestController::store
 *      (the web form AND the mobile Store "create request" screen both post here)
 *
 * Every guard built in Jul-2026 lived only in (1). So a manager-filed company-bike
 * petrol claim skipped the meter requirement, the odometer sanity check and the
 * double-tap block entirely — and `RequestController::store` additionally stored
 * `meter_at_fill` ONLY for Maintenance, silently discarding the reading on Petrol.
 * That is the exact leak this class closes: the rules now live in ONE place and
 * both controllers call it.
 *
 * ⭐ EVERYTHING IS KEYED TO THE **TARGET** RIDER, never the actor. When Shabib
 *    files for Danish, it is Danish's bike, Danish's odometer history and Danish's
 *    company/own status that decide — the manager's own profile is irrelevant.
 *
 * The rules are unchanged in substance; this is a move, not a redesign. The one
 * deliberate widening is that the meter is now required for MAINTENANCE on a
 * company bike too, not just petrol (owner ruling Jul-29) — a service without the
 * odometer can never reset the bike's service clock, so it was always half-useless.
 */
class FuelClaimRules
{
    /** Readings below this are dropped-digit typos — bikes here are 5-figure. */
    const MIN_PLAUSIBLE_METER = 1000;

    /** Tolerance around the floor/ceiling before a reading is called wrong. */
    const METER_SLACK_KM = 20;

    /** With no later reading to bound it, only a wild extra digit is refused. */
    const MAX_FORWARD_JUMP_KM = 2000;

    /** Identical amount inside this many seconds = a double tap, never a 2nd fill. */
    const DOUBLE_TAP_SECS = 600;

    /**
     * Validate a petrol/maintenance claim.
     *
     * @param  int         $forUserId   the rider the claim BELONGS to
     * @param  string|null $category    'Petrol' | 'Maintenance' | anything else
     * @param  array       $input       amount, expense_date, meter_at_fill, attendance_id, service_type
     * @param  int|null    $actorId     who is FILING it. Same as $forUserId = the
     *                                  rider filing for himself; different = a
     *                                  manager filing on his behalf. Null is
     *                                  treated as self-service (the safe default:
     *                                  it applies the stricter rule).
     * @return array{ok: bool, message: ?string, notice: ?string}
     *         ok=false → reject with `message` (422). `notice` is a soft flag to
     *         append to the description so the approver sees it.
     */
    public function check(int $forUserId, ?string $category, array $input, ?int $actorId = null): array
    {
        $category = trim((string) $category);
        if (!in_array($category, ['Petrol', 'Maintenance'], true)) {
            return $this->pass();          // not bike money — nothing to enforce
        }

        $meter     = $this->intOrNull($input['meter_at_fill'] ?? null);
        $claimDate = $this->dateOf($input['expense_date'] ?? null);
        // A claim tied to an attendance row is the SELF-AUDITING metered kind (an
        // own-bike rider's automatic petrol). It carries meter_start/end already,
        // so the flat-cash guards below must not touch it.
        $isMetered = !empty($input['attendance_id']);

        // ── 0. A PER-KM rider does not file his own petrol ─────────────────────
        // His petrol is paid on meter kilometres via the automatic metered claim
        // (the kind with an attendance_id, exempt below) — a hand-typed cash
        // claim on top of that is double payment.
        //
        // ⚠ "Per-km" is judged by USE OF THE METERED FLOW, not by the
        //   company_bike flag alone. `company_bike = 0` also covers people who
        //   were never on the scheme — Taimur (general petrol expenses, ~Rs 195k,
        //   zero metered claims), Farooq (no meters at all, so the manual claim
        //   is his ONLY path), office staff with no rider profile. Blocking on
        //   the flag would have cut all of them off. The double-payment risk
        //   exists precisely for riders who DO get metered claims — so that is
        //   the test.
        //
        // Deliberately narrow, so nothing else breaks:
        //   • only PETROL — own-bike maintenance is a real, separate cost
        //   • only FLAT   — the automatic metered claim must keep working
        //   • only SELF-SERVICE — a manager filing on his behalf is allowed
        //                         (that is the point of the on-behalf path)
        $isSelfService = $actorId === null || (int) $actorId === $forUserId;
        if ($category === 'Petrol' && !$isMetered && $isSelfService
            && $this->isPerKmRider($forUserId)) {
            return $this->fail(
                'Your petrol is paid on the kilometres from your meter readings, so it is '
                . 'raised for you automatically — you do not need to send a petrol request. '
                . 'If something is missing, ask your manager to add it for you.'
            );
        }

        // ── 1. Meter required on a COMPANY bike ────────────────────────────────
        // Only the flat cash kind: a metered claim already has the day's readings.
        if ($meter === null && !$isMetered && $this->ridesCompanyBike($forUserId)) {
            if ($category === 'Petrol' && $this->cfg('FUEL_METER_REQUIRED', 'N') === 'Y') {
                return $this->fail('Please enter the bike\'s meter reading with this fuel request.');
            }
            if ($category === 'Maintenance'
                && in_array($input['service_type'] ?? null, ['oil_change', 'general'], true)) {
                return $this->fail('Please enter the bike\'s meter reading with this service, '
                    . 'otherwise the next service cannot be scheduled from it.');
            }
        }

        // ── 2. Odometer sanity, against the reading's OWN date ─────────────────
        if ($meter !== null) {
            $bad = $this->checkOdometer($forUserId, $meter, $claimDate);
            if ($bad !== null) return $this->fail($bad);
        }

        // ── 3. Flat-cash petrol guards ─────────────────────────────────────────
        if ($category === 'Petrol' && !$isMetered) {
            return $this->checkFlatPetrol($forUserId, (float) ($input['amount'] ?? 0), $claimDate);
        }

        return $this->pass();
    }

    /**
     * The reading must sit between what the bike had already covered BEFORE that
     * day and what it read AFTER it. Compared to the claim's own date, not to
     * "latest known" — a service filed for yesterday is legitimately lower than
     * today's odometer, and comparing to the latest rejected exactly those.
     */
    private function checkOdometer(int $userId, int $meter, string $date): ?string
    {
        $win = $this->odometerWindow($userId, $date);

        if ($win['floor'] !== null && $meter < $win['floor'] - self::METER_SLACK_KM) {
            return 'That reading (' . number_format($meter) . ' km) is lower than this bike\'s '
                . number_format($win['floor']) . ' km recorded before ' . $date . '. Please check the number.';
        }
        if ($win['ceil'] !== null && $meter > $win['ceil'] + self::METER_SLACK_KM) {
            return 'That reading (' . number_format($meter) . ' km) is higher than this bike\'s '
                . number_format($win['ceil']) . ' km recorded after ' . $date . '. Please check the number.';
        }
        if ($win['ceil'] === null && $win['floor'] !== null
            && $meter > $win['floor'] + self::MAX_FORWARD_JUMP_KM) {
            return 'That reading (' . number_format($meter) . ' km) is far above this bike\'s last '
                . number_format($win['floor']) . ' km. Please check the number.';
        }
        return null;
    }

    /**
     * (a) HARD BLOCK the same amount minutes apart — always a double tap.
     * (b) SOFT FLAG a cash claim on a day the meter already paid for, or simply
     *     the 2nd cash claim of the day. Allowed; the approver is told.
     */
    private function checkFlatPetrol(int $userId, float $amount, string $claimDate): array
    {
        try {
            $sameDay = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->where('expense_category', 'Petrol')
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) = ?', [$claimDate])
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->get(['id', 'amount', 'attendance_id', 'created_at']);
        } catch (\Throwable $e) {
            return $this->pass();          // never block a claim on a read failure
        }

        foreach ($sameDay as $prev) {
            if ($prev->attendance_id !== null) continue;
            if (abs((float) $prev->amount - $amount) < 0.01
                && abs(time() - strtotime($prev->created_at)) <= self::DOUBLE_TAP_SECS) {
                return $this->fail('You already submitted Rs ' . number_format($amount)
                    . ' for petrol a moment ago. Check "My Requests" before sending it again.');
            }
        }

        $meteredToday = $sameDay->first(fn ($x) => $x->attendance_id !== null);
        if ($meteredToday) {
            return $this->pass('Cash claim on a day already paid by meter reading (request #' . $meteredToday->id . ').');
        }
        if ($sameDay->count() > 0) {
            return $this->pass('This is petrol cash claim #' . ($sameDay->count() + 1) . ' for ' . $claimDate . '.');
        }
        return $this->pass();
    }

    /**
     * floor = highest plausible reading known BEFORE $date
     * ceil  = lowest  plausible reading known AFTER  $date
     *
     * ⚠ Only PLAUSIBLE rows. The data contains typo'd meters (one row jumps
     * 26,261 → 56,403 in a day). A raw MAX lets one such row become the floor
     * forever, after which the rider can never file a correct reading again.
     */
    public function odometerWindow(int $userId, string $date): array
    {
        try {
            $sane = 'meter_start > ' . self::MIN_PLAUSIBLE_METER . '
                     AND (meter_end IS NULL OR (meter_end >= meter_start AND meter_end - meter_start <= 500))
                     AND (meter_home IS NULL OR (meter_home >= meter_start AND meter_home - meter_start <= 700))';

            $attBefore = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', '<', $date)
                ->whereRaw($sane)
                ->selectRaw('MAX(GREATEST(COALESCE(meter_end,0), COALESCE(meter_home,0), COALESCE(meter_start,0))) AS m')
                ->value('m');

            $fillBefore = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->whereNotNull('meter_at_fill')
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$date])
                ->max('meter_at_fill');

            $floor = max((int) $attBefore, (int) $fillBefore);
            $floor = $floor > self::MIN_PLAUSIBLE_METER ? $floor : null;

            // Sub-1000 junk is excluded INSIDE the query — taking MIN() first would
            // let one junk row become the ceiling and silently disable the check.
            $attAfter = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', '>', $date)
                ->whereRaw($sane)
                ->selectRaw('MIN(COALESCE(NULLIF(meter_start,0), NULLIF(meter_end,0), NULLIF(meter_home,0))) AS m')
                ->value('m');

            $fillAfter = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->whereNotNull('meter_at_fill')
                ->where('meter_at_fill', '>', self::MIN_PLAUSIBLE_METER)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) > ?', [$date])
                ->min('meter_at_fill');

            $candidates = array_filter([
                (int) $attAfter  > self::MIN_PLAUSIBLE_METER ? (int) $attAfter  : null,
                (int) $fillAfter > self::MIN_PLAUSIBLE_METER ? (int) $fillAfter : null,
            ], fn ($v) => $v !== null);

            return ['floor' => $floor, 'ceil' => $candidates ? min($candidates) : null];
        } catch (\Throwable $e) {
            return ['floor' => null, 'ceil' => null];
        }
    }

    /** Metered petrol inside this window = the rider is living on the per-km scheme. */
    const PER_KM_LOOKBACK_DAYS = 60;

    /**
     * On the per-km scheme: NOT a company bike, and has had at least one metered
     * petrol claim recently. Judged by behaviour rather than a profile flag —
     * see the note in check(). Self-correcting in both directions: a rider who
     * stops using the metered flow ages out; one who starts is covered at once.
     */
    public function isPerKmRider(int $userId): bool
    {
        try {
            if ($this->ridesCompanyBike($userId)) return false;
            return DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->where('expense_category', 'Petrol')
                ->whereNotNull('attendance_id')
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(expense_date, DATE(created_at)) >= DATE_SUB(CURDATE(), INTERVAL '
                    . self::PER_KM_LOOKBACK_DAYS . ' DAY)')
                ->exists();
        } catch (\Throwable $e) {
            return false;              // never block anyone on a read failure
        }
    }

    /** For the app: may this user file a petrol claim for himself? */
    public function canSelfFilePetrol(int $userId): bool
    {
        return !$this->isPerKmRider($userId);
    }

    /** Is this rider on a company-owned bike (the company buys the actual fuel)? */
    public function ridesCompanyBike(int $userId): bool
    {
        try {
            return (int) DB::table('t_ops_rider_profile')
                ->where('user_id', $userId)->value('company_bike') === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function pass(?string $notice = null): array
    {
        return ['ok' => true, 'message' => null, 'notice' => $notice];
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'notice' => null];
    }

    private function intOrNull($v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    private function dateOf($v): string
    {
        try {
            return $v ? Carbon::parse($v)->format('Y-m-d') : Carbon::today()->format('Y-m-d');
        } catch (\Throwable $e) {
            return Carbon::today()->format('Y-m-d');
        }
    }

    private function cfg(string $key, $default)
    {
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return ($v === null || $v === '') ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
