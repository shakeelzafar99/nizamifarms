<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Fleet & Fuel (Jul-2026) — what each rider's bike costs per month.
 *
 * Two different money models sit side by side and must NOT be averaged
 * together, which is the whole point of this screen:
 *
 *   • OWN bike  — paid a per-km rate for SHIFT kilometres only. The claim is
 *                 built from the attendance meter, so it audits itself.
 *   • COMPANY bike — the firm buys the actual fuel, so EVERY kilometre costs
 *                 money, including the ride home and personal use. Claims are
 *                 flat cash amounts with no km attached.
 *
 * So cost-per-km uses a different denominator per bike type (`basis_km`), and
 * the UI always says which. Averaging them would flatter the company bikes.
 *
 * Meter data contains real typos (dropped digits, test rows, one rider once
 * entered ANOTHER rider's odometer). Every reading passes plausibility bounds
 * before it can move a number — see isSaneDay()/isSaneGap().
 */
class FleetFuelService
{
    /** A single day's shift distance above this is a typo, not a ride. */
    const MAX_DAY_KM = 500;

    /** Odometer readings below this are dropped-digit typos (bikes are 5-figure). */
    const MIN_METER = 1000;

    /** Overnight gap above this is a meter change / typo, not a ride home. */
    const MAX_GAP_KM = 500;

    /** Two flat claims closer together than this with the same amount = double-tap. */
    const DOUBLETAP_SECS = 600;

    const CAT_FUEL = 'Petrol';
    const CAT_MAINT = 'Maintenance';

    // =================================================================
    // MONTH SUMMARY
    // =================================================================

    public function monthSummary(string $month): array
    {
        [$from, $to] = $this->bounds($month);

        $profiles = $this->profiles();
        $meters   = $this->meterAggregates($from, $to);
        $claims   = $this->claimAggregates($from, $to);

        // ⭐ The ACTIVE ROSTER is always in the list, activity or not. The ids used
        //    to come only from the month's meters + claims, so a fresh month (or
        //    one where a rider hadn't ridden yet) returned an empty table — and
        //    the "new petrol / maintenance" modal builds its rider list from
        //    these rows, so on the 1st of a month a manager had nobody to file
        //    for, on web and mobile alike. A delivery rider exists regardless of
        //    whether this month has data on him yet.
        $rosterIds = array_keys(array_filter($profiles, fn ($p) => (int) $p->active === 1));
        $ids = array_unique(array_merge($rosterIds, array_keys($meters), array_keys($claims)));
        if (!$ids) {
            return ['month' => $month, 'riders' => [], 'totals' => $this->emptyTotals()];
        }

        $names = DB::table('t_sys_user')->whereIn('id', $ids)->pluck('fullname', 'id')->toArray();
        $service = $this->serviceState(array_keys($profiles));

        $riders = [];
        $offRoster = [];        // spend by people who are not delivery riders
        foreach ($ids as $uid) {
            $p = $profiles[$uid] ?? null;
            $m = $meters[$uid] ?? ['days' => 0, 'work_km' => 0, 'offduty_km' => 0, 'no_meter_days' => 0];
            $c = $claims[$uid] ?? $this->emptyClaims();

            // A rider with fuel claims but no bike flag at all can't be costed
            // honestly — surface it rather than guessing a denominator.
            // NOTE: $isCompany is a bool (or null when the rider has no profile).
            // Compare it as a bool — `=== 1` is always false and silently blanked
            // the off-duty column when this was first written.
            $isCompany = $p ? ((int) $p->company_bike === 1) : null;
            $classified = $p !== null && ($isCompany === true || (int) $p->meter_required === 1);

            // Every kilometre the bike actually covered. Unattributed km ARE part of
            // it — we could not tell whether they were work or commute, but the bike
            // moved them and we bought the petrol. Leaving them out would make the
            // strip fail to add up (work + off-duty + unattributed ≠ total) and would
            // overstate the running cost by dividing real fuel by fewer kilometres.
            $totalKm = $m['work_km'] + $m['offduty_km'] + ($m['unattributed_km'] ?? 0);

            // ⭐ COST PER PRODUCTIVE KILOMETRE — always SHIFT km, both bike types.
            //
            // It is tempting to divide a company bike's cost by every kilometre it
            // moved (work + off-duty). That is wrong and flatters company bikes:
            // off-duty kilometres are a cost you carry, not output you received.
            // Dividing by them credits the bike for the rider's commute.
            //
            // An own-bike rider is paid per shift kilometre, so shift km is his
            // denominator by definition. Using the same denominator for company
            // bikes is the only way the two numbers answer the same question:
            // "what does one delivered kilometre cost me under each model?"
            $basisKm = $m['work_km'];

            // Nothing at all this month (no money, no distance). An OFF-roster
            // user with an empty month is noise and is dropped — but an ACTIVE
            // delivery rider keeps his row with zeros: the roster is who the
            // screen is ABOUT, and the new-claim modal needs him listed even on
            // the 1st of the month before anything has happened.
            $emptyMonth = $basisKm <= 0 && $c['fuel_rs'] <= 0 && $c['maint_rs'] <= 0
                && $c['fuel_pending_rs'] <= 0 && $c['maint_pending_rs'] <= 0;
            if ($emptyMonth && (!$p || (int) $p->active !== 1)) {
                continue;
            }

            // ⭐ ROSTER: this screen lists DELIVERY RIDERS, and the switch for that
            //    already exists — the "Delivery Rider" checkbox in the People &
            //    Rider List, which writes t_ops_rider_profile.active. The same flag
            //    drives the Rider Management page and the mobile assign-rider list,
            //    so one control now governs all three.
            //
            //    Anyone else who bought fuel (a manager buying in bulk, an
            //    ex-rider) keeps their money in the "unattributed" line rather than
            //    getting a bike row — the spend stays visible, it just stops
            //    pretending to be a rider's running cost.
            if (!$p || (int) $p->active !== 1) {
                $offRoster[] = [
                    'name'  => $names[$uid] ?? 'Unknown',
                    'fuel'  => $c['fuel_rs'],
                    'maint' => $c['maint_rs'],
                ];
                continue;
            }

            $riders[] = [
                'user_id'       => (int) $uid,
                'name'          => $names[$uid] ?? 'Unknown',
                'bike'          => $isCompany === null ? 'unknown' : ($isCompany ? 'company' : 'own'),
                'classified'    => $classified,
                'days'          => $m['days'],
                'work_km'       => $m['work_km'],
                'offduty_km'    => $isCompany === true ? $m['offduty_km'] : null,
                'total_km'      => $isCompany === true ? $totalKm : null,
                // Km across stretches containing a worked-but-unmetered day —
                // neither work nor commute, and honest about not knowing.
                'unattributed_km' => $isCompany === true ? ($m['unattributed_km'] ?? 0) : null,
                // Past days he checked in and never checked out. Left as
                // "in progress" by owner ruling — the team must go and close them —
                // but surfaced here so they are visible instead of invisible.
                'open_days'     => $m['open_days'] ?? 0,
                'basis_km'      => $basisKm,
                'basis_label'   => 'shift km',
                // ⭐ FUELLED KM (owner ruling Jul-28): every kilometre whose petrol
                //    THIS COMPANY paid for. On a company bike that is work + the
                //    commute; on an own bike we only fund shift km. This is what
                //    "costed" should always have meant — the old column showed work
                //    km alone while the firm was demonstrably paying for more.
                //    It is a MONEY figure, deliberately NOT the comparison
                //    denominator — see rs_per_fuelled_km below.
                //    Includes unattributed km: not knowing whether a kilometre was
                //    work or commute does not make the petrol free.
                'fuelled_km'    => $isCompany === true ? $totalKm : $m['work_km'],
                // Context only, never a denominator for the comparison: what the
                // fuel worked out to across every kilometre the bike actually
                // moved. Near real pump economics (~7 Rs/km) means the claims are
                // roughly honest; far above it means over-claiming.
                'fuel_per_all_km' => ($isCompany === true && $totalKm > 0)
                    ? round($c['fuel_rs'] / $totalKm, 2) : null,
                'no_meter_days' => $m['no_meter_days'],
                // The last odometer we have from a FILL, so the new-claim form can
                // say "that's N km since his last fill" while the manager types —
                // the same figure the approver later sees on the claim itself.
                'last_fill_meter' => $this->lastFillMeter($uid),
                'incl_ride_home_days' => $m['incl_ride_home_days'] ?? 0,
                'fuel_rs'       => $c['fuel_rs'],
                'fuel_pending_rs' => $c['fuel_pending_rs'],
                'pending_count' => $c['pending_count'],
                'fuel_claims'   => $c['fuel_claims'],
                'metered_rs'    => $c['metered_rs'],
                'flat_rs'       => $c['flat_rs'],
                'flat_claims'   => $c['flat_claims'],
                'litres'        => $c['litres'] > 0 ? round($c['litres'], 1) : null,
                'maint_rs'      => $c['maint_rs'],
                'maint_regular_rs' => $c['maint_regular_rs'],
                'maint_repair_rs'  => $c['maint_repair_rs'],
                'maint_other_rs'   => $c['maint_other_rs'],
                'maint_pending_rs' => $c['maint_pending_rs'],
                'rs_per_km'     => $basisKm > 0 ? round($c['fuel_rs'] / $basisKm, 2) : null,
                'rs_per_km_all' => $basisKm > 0 ? round(($c['fuel_rs'] + $c['maint_rs']) / $basisKm, 2) : null,
                // What a kilometre costs on THIS BIKE — fuel ÷ every km we fuelled.
                // Answers "what is this machine costing me to run", which is a
                // different question from "what does a DELIVERED km cost me"
                // (rs_per_km). Both are shown; only rs_per_km may be compared
                // across bike types, because an own-bike rider's commute is his
                // own expense and never enters his denominator.
                'rs_per_fuelled_km' => ($isCompany === true && $totalKm > 0)
                    ? round($c['fuel_rs'] / $totalKm, 2)
                    : ($basisKm > 0 ? round($c['fuel_rs'] / $basisKm, 2) : null),
                // All-in on the SAME denominator as the fuel rate beside it. Showing
                // fuel ÷ ridden km next to all-in ÷ productive km put two different
                // denominators side by side in one row, which reads as an error.
                'rs_per_fuelled_km_all' => ($isCompany === true && $totalKm > 0)
                    ? round(($c['fuel_rs'] + $c['maint_rs']) / $totalKm, 2)
                    : ($basisKm > 0 ? round(($c['fuel_rs'] + $c['maint_rs']) / $basisKm, 2) : null),
                'km_per_litre'  => $c['litres'] > 0 && $basisKm > 0 ? round($basisKm / $c['litres'], 1) : null,
                'dupe_flags'    => $c['dupe_flags'],
                'early_service_count' => $c['early_service_count'] ?? 0,
                'service'       => $service[$uid] ?? null,
            ];
        }

        usort($riders, fn ($a, $b) => $b['basis_km'] <=> $a['basis_km']);

        return ['month' => $month, 'riders' => $riders, 'totals' => $this->totals($riders, $offRoster)];
    }

    private function emptyClaims(): array
    {
        return ['fuel_rs' => 0.0, 'fuel_pending_rs' => 0.0, 'fuel_claims' => 0, 'metered_rs' => 0.0,
                'flat_rs' => 0.0, 'flat_claims' => 0, 'litres' => 0.0, 'maint_rs' => 0.0,
                'maint_pending_rs' => 0.0, 'dupe_flags' => 0, 'pending_count' => 0,
                'maint_regular_rs' => 0.0, 'maint_repair_rs' => 0.0, 'maint_other_rs' => 0.0,
                'early_service_count' => 0];
    }

    private function emptyTotals(): array
    {
        return ['fuel_rs' => 0, 'maint_rs' => 0, 'company' => null, 'own' => null, 'dupe_flags' => 0];
    }

    /**
     * Fleet totals split by bike type — the comparison the whole screen exists
     * to answer. Never merged into one number.
     */
    private function totals(array $riders, array $offRoster = []): array
    {
        $acc = ['company' => ['km' => 0, 'fuel' => 0.0, 'maint' => 0.0, 'riders' => 0, 'offduty' => 0, 'fuelled' => 0, 'unattrib_km' => 0],
                'own'     => ['km' => 0, 'fuel' => 0.0, 'maint' => 0.0, 'riders' => 0, 'offduty' => 0, 'fuelled' => 0, 'unattrib_km' => 0]];
        $fuel = 0.0; $maint = 0.0; $dupes = 0; $unattributed = 0.0; $unattributedWho = [];

        // People who spent on fuel/maintenance but are not delivery riders.
        // Their money still counts in the HEADLINE totals — it left the business
        // either way, and quietly dropping it would make the month look cheaper
        // than it was. It is only kept out of the per-km buckets, and named in
        // the unattributed line so the gap between the two is explained.
        foreach ($offRoster as $x) {
            $spend = ($x['fuel'] ?? 0) + ($x['maint'] ?? 0);
            if ($spend <= 0) continue;
            $fuel  += $x['fuel'] ?? 0;
            $maint += $x['maint'] ?? 0;
            $unattributed += $spend;
            $unattributedWho[] = $x['name'];
        }

        foreach ($riders as $r) {
            $fuel += $r['fuel_rs'];
            $maint += $r['maint_rs'];
            $dupes += $r['dupe_flags'];

            $k = $r['bike'] === 'company' ? 'company' : ($r['bike'] === 'own' ? 'own' : null);

            // Spend with NO kilometres behind it (no meter readings, or someone
            // who isn't really a rider buying fuel in bulk) must not enter the
            // per-km maths — it would inflate the numerator against a smaller
            // denominator and make the honest riders look expensive. Report it
            // separately instead of hiding it.
            if ($k === null || $r['basis_km'] <= 0) {
                $unattributed += $r['fuel_rs'] + $r['maint_rs'];
                if (($r['fuel_rs'] + $r['maint_rs']) > 0) $unattributedWho[] = $r['name'];
                continue;
            }

            $acc[$k]['km']    += $r['basis_km'];
            $acc[$k]['fuel']  += $r['fuel_rs'];
            $acc[$k]['maint'] += $r['maint_rs'];
            $acc[$k]['offduty'] += (int) ($r['offduty_km'] ?? 0);
            $acc[$k]['fuelled'] += (int) ($r['fuelled_km'] ?? 0);
            $acc[$k]['unattrib_km'] += (int) ($r['unattributed_km'] ?? 0);
            $acc[$k]['riders']++;
        }

        $shape = function (array $a): array {
            return [
                'riders'        => $a['riders'],
                'km'            => $a['km'],
                'offduty_km'    => $a['offduty'],
                // Every km this company bought fuel for (work + commute on a
                // company bike; shift km only on an own bike).
                'fuelled_km'    => $a['fuelled'],
                // Km inside stretches containing a worked-but-unmetered day.
                'unattributed_km' => $a['unattrib_km'],
                'fuel_rs'       => round($a['fuel'], 2),
                'maint_rs'      => round($a['maint'], 2),
                // ⚠ COMPARISON RATE — productive km for BOTH bike types. This is the
                // only pair that may be set against each other: an own-bike rider is
                // paid per shift km and funds his own commute, so crediting a company
                // bike for the commute we pay for would flatter it (Jul-2026: −3% on
                // this basis, −23% on fuelled km — the second is not a saving, it is
                // the commute being counted as output).
                'rs_per_km'     => $a['km'] > 0 ? round($a['fuel'] / $a['km'], 2) : null,
                'rs_per_km_all' => $a['km'] > 0 ? round(($a['fuel'] + $a['maint']) / $a['km'], 2) : null,
                // RUNNING COST of the machines — fuel ÷ every km we fuelled. Real and
                // useful ("what does a km on these bikes cost"), but never a
                // like-for-like against own bikes.
                'rs_per_fuelled_km' => $a['fuelled'] > 0 ? round($a['fuel'] / $a['fuelled'], 2) : null,
                // Everything in — fuel + maintenance over the same kilometres.
                'rs_per_fuelled_km_all' => $a['fuelled'] > 0
                    ? round(($a['fuel'] + $a['maint']) / $a['fuelled'], 2) : null,
            ];
        };

        return [
            'fuel_rs'      => round($fuel, 2),
            'maint_rs'     => round($maint, 2),
            'dupe_flags'   => $dupes,
            'unattributed_rs'  => round($unattributed, 2),
            'unattributed_who' => array_values(array_unique($unattributedWho)),
            'company'      => $shape($acc['company']),
            'own'          => $shape($acc['own']),
        ];
    }

    // =================================================================
    // METERS
    // =================================================================

    /**
     * Why a day has no usable distance. Deliberately mirrors how the attendance
     * screen classifies a day, so the two screens never disagree about the same
     * date:
     *   leave   — an approved leave day. Nothing to measure.
     *   absent  — never checked in. Nothing to measure.
     *   missing — he WORKED but the meter is absent or unusable. This is the only
     *             one worth an alert.
     *   ok      — readings are usable.
     */
    private function dayStatus($r): string
    {
        if (!empty($r->leave_type)) return 'leave';

        // Same first test the attendance screen makes ("didn't work today"):
        // neither a check-in nor a check-out means there was no ride to measure.
        $in  = !empty($r->login_time);
        $out = !empty($r->logout_time);
        if (!$in && !$out) return 'absent';

        if ($this->isSaneDay($r->meter_start ?? null, $r->meter_end ?? null)) return 'ok';

        // ⭐ A reading is only "missing" once it was DUE — start once he checked
        // in, end once he checked out. A rider still on shift has no end meter
        // yet and must not be alerted for it; the attendance screen applies
        // exactly this rule, and the two must agree about the same date.
        if ($in && $r->meter_start === null) return 'missing';
        if ($out && $r->meter_end === null) return 'missing';

        // Readings present but implausible (typo / another bike) — always wrong.
        if ($r->meter_start !== null && $r->meter_end !== null) return 'missing';

        return 'in_progress';        // checked in, no check-out yet — nothing due
    }

    /** Finer-grained reason for the day list — what exactly is absent. */
    private function dayDetail($r): string
    {
        $s = $this->dayStatus($r);
        if ($s !== 'missing') return $s;
        $in  = !empty($r->login_time);
        $out = !empty($r->logout_time);
        if ($in && $out && $r->meter_start === null && $r->meter_end === null) return 'no_reading';
        if ($in && $r->meter_start === null) return 'no_start';
        if ($out && $r->meter_end === null) return 'no_end';
        return 'unusable';           // present but implausible (typo / wrong bike)
    }

    private function isSaneDay($start, $end): bool
    {
        if ($start === null || $end === null) return false;
        $km = (int) $end - (int) $start;
        return (int) $start > self::MIN_METER && $km >= 0 && $km <= self::MAX_DAY_KM;
    }

    private function isSaneGap(int $prevEnd, int $nextStart): bool
    {
        $gap = $nextStart - $prevEnd;
        return $gap >= 0 && $gap <= self::MAX_GAP_KM;
    }

    /** Work km + off-duty km per rider for the month, typos excluded. */
    private function meterAggregates(string $from, string $to): array
    {
        $rows = DB::table('t_ops_attendance')
            ->select('user_id', 'attendance_date', 'meter_start', 'meter_end',
                     'meter_home', 'login_time', 'logout_time', 'leave_type')
            ->whereBetween('attendance_date', [$from, $to])
            ->orderBy('user_id')->orderBy('attendance_date')
            ->get();

        $out = [];
        $prevEnd = [];
        // Set per rider while a gap is "dirty": a day he WORKED but left no usable
        // meter sits inside the stretch, so the km across it are part work and part
        // commute and cannot be told apart. See the unattributed note below.
        $dirtyGap = [];
        $today = Carbon::today()->format('Y-m-d');
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            if (!isset($out[$uid])) {
                $out[$uid] = ['days' => 0, 'work_km' => 0, 'offduty_km' => 0,
                              'no_meter_days' => 0, 'incl_ride_home_days' => 0,
                              'unattributed_km' => 0, 'open_days' => 0];
            }

            if (!$this->isSaneDay($r->meter_start, $r->meter_end)) {
                // ⚠ Only a day he ACTUALLY WORKED can be "missing a meter reading".
                // On leave or absent there is no ride to measure, so counting those
                // produced alerts for days the rider was never on the bike — and it
                // disagreed with the attendance screen, which treats leave/absent as
                // their own states rather than as failures.
                $st = $this->dayStatus($r);
                if ($st === 'missing') {
                    $out[$uid]['no_meter_days']++;
                    // He rode that day, so the next usable gap contains work km.
                    $dirtyGap[$uid] = true;
                }
                // A PAST day still "in progress" = checked in, never checked out.
                // Kept as in_progress on purpose (owner ruling: the team must go and
                // close it, not have it silently reclassified) — but it is counted
                // here so it is visible, and it dirties the gap for the same reason:
                // he worked, and no end reading was ever recorded.
                if ($st === 'in_progress' && $r->attendance_date < $today) {
                    $out[$uid]['open_days']++;
                    $dirtyGap[$uid] = true;
                }
                continue;
            }

            // ⭐ OWNER RULING (Jul-27): for a company bike the ride until he reaches
            //    home and enters the meter IS part of the shift. The meter-out is
            //    taken at home and written to meter_end, so start→end already spans
            //    exactly that — nothing to separate out. The ONLY off-duty stretch
            //    is meter_end → next day's meter_start. Kept as a plain count for
            //    the day list; it is not an exception to flag.
            if ($r->meter_home !== null && (int) $r->meter_home === (int) $r->meter_end) {
                $out[$uid]['incl_ride_home_days']++;
            }

            $s = (int) $r->meter_start; $e = (int) $r->meter_end;
            $out[$uid]['days']++;
            $out[$uid]['work_km'] += $e - $s;

            if (isset($prevEnd[$uid]) && $this->isSaneGap($prevEnd[$uid], $s)) {
                // ⭐ A gap that SPANS a day he worked without a usable meter is NOT
                //    off-duty. Kanan's Jul-1 → Jul-3 stretch is 198 km, but Jul-2 is
                //    inside it and he delivered 12 orders that day — most of those km
                //    are work. Calling the whole stretch "off-duty" both inflates the
                //    commute figure the company-bike argument rests on AND removes
                //    real work km from the Rs/km denominator, making the rider look
                //    dearer than he is. It cannot be split, so it is reported on its
                //    own as unattributed — the same honesty rule already applied to
                //    fuel that can't be tied to any kilometres.
                $gapKm = $s - $prevEnd[$uid];
                if (!empty($dirtyGap[$uid])) {
                    $out[$uid]['unattributed_km'] += $gapKm;
                } else {
                    $out[$uid]['offduty_km'] += $gapKm;
                }
            }
            $dirtyGap[$uid] = false;
            $prevEnd[$uid] = $e;
        }
        return $out;
    }

    // =================================================================
    // CLAIMS
    // =================================================================

    /** Fuel + maintenance money per rider, with duplicate detection. */
    private function claimAggregates(string $from, string $to): array
    {
        $rows = $this->claimRows($from, $to);

        $out = [];
        $byRiderDay = [];      // [uid][date] => list of rows, for duplicate rules
        foreach ($rows as $r) {
            $uid = (int) $r->requester_user_id;
            if (!isset($out[$uid])) $out[$uid] = $this->emptyClaims();

            $approved = $r->status === 'approved';
            $pending  = $r->status === 'pending';
            $amount   = (float) $r->amount;

            if ($r->expense_category === self::CAT_FUEL) {
                if ($approved) {
                    $out[$uid]['fuel_rs'] += $amount;
                    $out[$uid]['fuel_claims']++;
                    if ($r->meter_distance !== null) $out[$uid]['metered_rs'] += $amount;
                    else { $out[$uid]['flat_rs'] += $amount; $out[$uid]['flat_claims']++; }
                    if ($r->litres !== null) $out[$uid]['litres'] += (float) $r->litres;
                } elseif ($pending) {
                    $out[$uid]['fuel_pending_rs'] += $amount;
                    $out[$uid]['pending_count']++;
                }
                if ($approved || $pending) {
                    $byRiderDay[$uid][$r->d][] = $r;
                }
            } else {
                if ($approved) {
                    $out[$uid]['maint_rs'] += $amount;
                    // Split by what was done. Regular service is the scheduled
                    // work that resets the due clock; repair is everything ad-hoc.
                    // Rows filed before service_type existed have none — they are
                    // kept in their own bucket rather than guessed into either.
                    $out[$uid][$this->maintBucket($r->service_type)] += $amount;
                } elseif ($pending) {
                    $out[$uid]['maint_pending_rs'] += $amount;
                    $out[$uid]['pending_count']++;
                }
            }
        }

        foreach ($byRiderDay as $uid => $days) {
            foreach ($days as $date => $list) {
                $out[$uid]['dupe_flags'] += count($this->dupeFlags($list));
            }
        }

        // Regular services done before the bike's schedule was up. Computed per
        // rider from that rider's own rows (kmSinceLastService assumes a single
        // rider's claims, so the grouping has to happen here).
        $byRider = collect($rows)->groupBy('requester_user_id');
        foreach ($byRider as $uid => $riderRows) {
            $uid = (int) $uid;
            if (!isset($out[$uid])) continue;
            $gaps = $this->kmSinceLastService($uid, $riderRows, null);
            $out[$uid]['early_service_count'] = count(array_filter(
                $gaps, fn ($g) => ($g['early_by'] ?? null) !== null
            ));
        }

        return $out;
    }

    /** One month of fuel/maintenance requests, newest last. */
    private function claimRows(string $from, string $to)
    {
        return DB::table('t_req_master')
            ->selectRaw("id, requester_user_id, amount, expense_category, status,
                         meter_distance, petrol_rate, meter_at_fill, litres, service_type,
                         service_due_km,
                         attendance_id, attachments, created_at, title, description,
                         requires_level_1, level_1_status, requires_level_2, level_2_status,
                         -- What the claim was FILED against. The approve strip
                         -- pre-selects this instead of its own first option, which
                         -- used to overwrite the filer's choice with NF Cash unless
                         -- the approver happened to touch the dropdown.
                         payment_source_account_id, receiving_account_id,
                         maintenance_type_id,
                         COALESCE(expense_date, DATE(created_at)) AS d")
            ->whereIn('expense_category', [self::CAT_FUEL, self::CAT_MAINT])
            ->whereRaw("COALESCE(expense_date, DATE(created_at)) BETWEEN ? AND ?", [$from, $to])
            ->orderBy('requester_user_id')->orderBy('created_at')
            ->get();
    }

    /**
     * Duplicate/over-claim detection for one rider on one day.
     *
     * Derived from what actually happened in June: three 500-rupee claims filed
     * within two minutes; a flat cash claim on a day already covered by a
     * metered claim. Returns a flag per SUSPECT row (never the first one).
     */
    private function dupeFlags(array $list): array
    {
        $flags = [];
        $hasMetered = false;
        foreach ($list as $r) {
            if ($r->meter_distance !== null && $r->status === 'approved') $hasMetered = true;
        }

        $seenFlat = 0;
        foreach ($list as $i => $r) {
            if ($r->meter_distance !== null) continue;      // metered rows are self-auditing
            $seenFlat++;

            // (a) identical amount, minutes apart = a double-tap on the button
            foreach ($list as $j => $o) {
                if ($j >= $i || $o->meter_distance !== null) continue;
                if ((float) $o->amount === (float) $r->amount &&
                    abs(strtotime($r->created_at) - strtotime($o->created_at)) <= self::DOUBLETAP_SECS) {
                    $flags[$r->id] = 'double_tap';
                    continue 2;
                }
            }
            // (b) cash claim on a day the meter already paid for
            if ($hasMetered) { $flags[$r->id] = 'flat_on_metered_day'; continue; }
            // (c) simply the 2nd+ cash claim of the day
            if ($seenFlat > 1) $flags[$r->id] = 'second_same_day';
        }
        return $flags;
    }

    // =================================================================
    // SERVICE (oil change) STATE
    // =================================================================

    /** Current odometer per rider — the max plausible reading we know of. */
    private function currentMeters(array $userIds): array
    {
        if (!$userIds) return [];

        // ⚠ PLAUSIBLE rows only. A single typo'd row (one rider has 26,261 → 56,403
        // in a day) would otherwise become "current odometer" forever and make every
        // service chip read wildly overdue. Same bounds as isSaneDay().
        $att = DB::table('t_ops_attendance')
            ->selectRaw('user_id, MAX(GREATEST(COALESCE(meter_end,0), COALESCE(meter_home,0), COALESCE(meter_start,0))) AS m')
            ->whereIn('user_id', $userIds)
            ->whereRaw('meter_start > ' . self::MIN_METER)
            ->whereRaw('(meter_end IS NULL OR (meter_end >= meter_start AND meter_end - meter_start <= ' . self::MAX_DAY_KM . '))')
            ->whereRaw('(meter_home IS NULL OR (meter_home >= meter_start AND meter_home - meter_start <= 700))')
            ->groupBy('user_id')->pluck('m', 'user_id')->toArray();

        $fill = DB::table('t_req_master')
            ->selectRaw('requester_user_id, MAX(meter_at_fill) AS m')
            ->whereIn('requester_user_id', $userIds)
            ->whereNotNull('meter_at_fill')
            ->groupBy('requester_user_id')->pluck('m', 'requester_user_id')->toArray();

        $out = [];
        foreach ($userIds as $uid) {
            $v = max((int) ($att[$uid] ?? 0), (int) ($fill[$uid] ?? 0));
            if ($v > self::MIN_METER) $out[$uid] = $v;
        }
        return $out;
    }

    /** Due / due-soon / overdue per bike. Null when we cannot tell. */
    /**
     * Per rider: the interval of the type of their most recent APPROVED
     * clock-resetting service. Empty for anyone whose last service was untyped
     * (every rider before Aug-2026) — the caller then falls back as before.
     *
     * Only clock-resetting types count: a brake-shoe job is real maintenance but
     * it is not what "next service due" measures, so it must not set the schedule
     * any more than it resets the clock.
     *
     * @return array<int, int>  userId => interval_km
     */
    private function lastServiceTypeIntervals(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_fleet_maintenance_types')) {
                return [];
            }
            // The newest qualifying claim per rider, then that type's interval.
            $rows = DB::table('t_req_master as r')
                ->join('t_fleet_maintenance_types as t', 't.id', '=', 'r.maintenance_type_id')
                ->whereIn('r.requester_user_id', $userIds)
                ->where('r.expense_category', 'Maintenance')
                ->where('r.status', 'approved')
                ->where('t.resets_service_clock', 1)
                ->where('t.interval_km', '>', 0)
                ->orderBy('r.requester_user_id')
                ->orderByDesc('r.meter_at_fill')
                ->get(['r.requester_user_id as uid', 't.interval_km']);

            $out = [];
            foreach ($rows as $row) {
                // Ordered newest-first per rider, so the first one wins.
                if (!isset($out[(int) $row->uid])) {
                    $out[(int) $row->uid] = (int) $row->interval_km;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function serviceState(array $userIds): array
    {
        $profiles = $this->profiles();
        $current  = $this->currentMeters($userIds);
        $default  = (int) $this->cfg('BIKE_SERVICE_INTERVAL_KM', 3000);

        // ⭐ Aug-2026: the schedule follows THE WORK LAST DONE. If the bike had the
        // 2,500 km oil+tuning, it is due in 2,500 — not in whatever the bike's
        // generic override says. Without this the chip kept showing the old single
        // interval while the frozen `service_due_km` on the request used the type's,
        // and the two disagreed on the same screen.
        // Precedence: last clock-resetting service's type → per-bike override →
        // company default. The override still governs every bike with no typed
        // service yet, which today is all of them.
        $lastTypeInterval = $this->lastServiceTypeIntervals($userIds);

        $out = [];
        foreach ($userIds as $uid) {
            $p = $profiles[$uid] ?? null;
            if (!$p) continue;

            $interval = (int) (($lastTypeInterval[$uid] ?? 0) ?: ($p->service_interval_km ?: $default));
            $now      = $current[$uid] ?? null;
            $last     = $p->last_service_meter !== null ? (int) $p->last_service_meter : null;

            if ($interval <= 0 || $now === null || $last === null) {
                $out[$uid] = [
                    'state' => 'unknown', 'interval_km' => $interval,
                    'current_meter' => $now, 'last_service_meter' => $last,
                    'last_service_at' => $p->last_service_at,
                    'since_km' => null, 'due_in_km' => null,
                ];
                continue;
            }

            $since = $now - $last;
            $dueIn = $interval - $since;
            $out[$uid] = [
                'state'         => $dueIn < 0 ? 'overdue' : ($dueIn <= 150 ? 'due_soon' : 'ok'),
                'interval_km'   => $interval,
                'current_meter' => $now,
                'last_service_meter' => $last,
                'last_service_at'    => $p->last_service_at,
                'since_km'      => $since,
                'due_in_km'     => $dueIn,
            ];
        }
        return $out;
    }

    // =================================================================
    // RIDER DETAIL (datewise)
    // =================================================================

    /** One rider's month: a row per day, plus the claims and photos on it. */
    public function riderMonth(int $userId, string $month): array
    {
        [$from, $to] = $this->bounds($month);

        $name = DB::table('t_sys_user')->where('id', $userId)->value('fullname') ?: 'Rider';
        $profiles = $this->profiles();
        $p = $profiles[$userId] ?? null;
        // bool, not int — see the note in monthSummary(); `=== 1` is always false.
        $isCompany = $p ? ((int) $p->company_bike === 1) : null;

        // --- claims, grouped by their expense day ---
        // Approved + pending ONLY. Rejected/cancelled rows are dead money — showing
        // them clutters the review, and worse, a rejected claim sitting on a day
        // would trigger a false "second cash claim" flag on the honest one. The
        // month totals were always approved-only; the datewise list now matches.
        $claimRows = $this->claimRows($from, $to)
            ->where('requester_user_id', $userId)
            ->filter(fn ($r) => in_array($r->status, ['approved', 'pending'], true));
        $byDay = [];
        foreach ($claimRows as $r) $byDay[$r->d][] = $r;

        $flagsById = [];
        foreach ($byDay as $date => $list) {
            $fuelOnly = array_values(array_filter($list, fn ($x) => $x->expense_category === self::CAT_FUEL));
            $flagsById += $this->dupeFlags($fuelOnly);
        }

        // ⭐ Km since the PREVIOUS fill — the number the approver actually wants:
        //    "he last filled at 32,484; this request is at 32,607, so this tank is
        //    for 123 km." Walked in filing order (day, then time). The chain seeds
        //    from the last fill BEFORE this month so the 1st of the month still
        //    gets a delta. Implausible jumps (≤0 or >2000 km — a typo or a swapped
        //    bike) are marked odd rather than shown as a real distance.
        $sinceById = $this->kmSinceLastFill($userId, $claimRows, $from);
        $trail = $this->approvalTrail($claimRows->pluck('id')->all());
        $approvalNotes   = $trail['notes'];
        $approvalActions = $trail['actions'];
        $serviceGap = $this->kmSinceLastService($userId, $claimRows, $isCompany);
        // Where the bike stands against its schedule RIGHT NOW. Used to explain a
        // still-pending service ("the bike is 317 km past due") — after approval
        // the clock resets, so this can only ever describe the unapproved case.
        $svcState = $this->serviceState([$userId])[$userId] ?? null;

        // --- attendance days ---
        $att = DB::table('t_ops_attendance')
            ->select('attendance_date', 'meter_start', 'meter_end', 'meter_home',
                     'login_time', 'logout_time', 'leave_type')
            ->where('user_id', $userId)
            ->whereBetween('attendance_date', [$from, $to])
            ->orderBy('attendance_date')->get();

        $days = [];
        $prevEnd = null;
        $prevDate = null;
        // Mirrors meterAggregates(): a worked-but-unmetered day (or a past day never
        // closed) makes the NEXT gap part-work, so it can't be called off-duty.
        $dirtyGap = false;
        $todayYmd = Carbon::today()->format('Y-m-d');
        foreach ($att as $a) {
            $date = $a->attendance_date;
            $sane = $this->isSaneDay($a->meter_start, $a->meter_end);
            $offduty = null;
            $offdutySince = null;
            $unattributed = null;
            if ($sane && $prevEnd !== null && $this->isSaneGap($prevEnd, (int) $a->meter_start)) {
                $gapKm = (int) $a->meter_start - $prevEnd;
                // The gap is measured from the last USABLE reading, which may be
                // several days back when readings were missed. Say which day it
                // runs from, so "+191 km off-duty" is never read as one evening.
                if ($prevDate !== null &&
                    Carbon::parse($prevDate)->diffInDays(Carbon::parse($date)) > 1) {
                    $offdutySince = $prevDate;
                }
                // Contains a day he rode without a usable meter → part work, part
                // commute, unsplittable. Reported as unattributed rather than
                // silently credited to either side.
                if ($dirtyGap) { $unattributed = $gapKm; }
                else           { $offduty = $gapKm; }
            }
            if (!$sane) {
                $st0 = $this->dayStatus($a);
                if ($st0 === 'missing' || ($st0 === 'in_progress' && $date < $todayYmd)) {
                    $dirtyGap = true;
                }
            } else {
                $dirtyGap = false;
            }
            $days[$date] = [
                'date'        => $date,
                'meter_start' => $a->meter_start !== null ? (int) $a->meter_start : null,
                'meter_end'   => $a->meter_end !== null ? (int) $a->meter_end : null,
                'work_km'     => $sane ? (int) $a->meter_end - (int) $a->meter_start : null,
                'offduty_km'  => $isCompany === true ? $offduty : null,
                'offduty_since' => $isCompany === true ? $offdutySince : null,
                // Km across a stretch that contains a worked-but-unmetered day.
                'unattributed_km' => $isCompany === true ? $unattributed : null,
                'meter_ok'    => $sane,
                // Why this day has no distance — so the list can say "on leave"
                // instead of implying the rider failed to record something.
                'status'      => $this->dayStatus($a),
                'detail'      => $this->dayDetail($a),
                // The meter-out was taken at home, so this day's km include the
                // ride home (the office-close reading is not stored separately).
                'incl_ride_home' => $a->meter_home !== null && (int) $a->meter_home === (int) $a->meter_end,
                'claims'      => [],
            ];
            if ($sane) { $prevEnd = (int) $a->meter_end; $prevDate = $date; }
        }

        // --- attach claims (a claim can land on a day with no attendance row) ---
        // Resolved once for the whole month rather than per claim row.
        $maintTypes = app(\App\Services\Riders\MaintenanceTypeService::class);
        foreach ($byDay as $date => $list) {
            if (!isset($days[$date])) {
                // A claim dated on a day with no attendance row at all (e.g. filed
                // for a day off). Shown, but never counted as a missing meter.
                $days[$date] = ['date' => $date, 'meter_start' => null, 'meter_end' => null,
                                'work_km' => null, 'offduty_km' => null, 'offduty_since' => null,
                                'meter_ok' => false, 'status' => 'no_attendance',
                                'detail' => 'no_attendance', 'incl_ride_home' => false, 'claims' => []];
            }
            foreach ($list as $r) {
                $days[$date]['claims'][] = [
                    'id'        => (int) $r->id,
                    'kind'      => $r->expense_category === self::CAT_FUEL ? 'fuel' : 'maintenance',
                    'amount'    => (float) $r->amount,
                    'status'    => $r->status,
                    'source'    => $r->meter_distance !== null ? 'meter' : 'manual',
                    'meter_distance' => $r->meter_distance !== null ? (float) $r->meter_distance : null,
                    'petrol_rate'    => $r->petrol_rate !== null ? (float) $r->petrol_rate : null,
                    'meter_at_fill'  => $r->meter_at_fill !== null ? (int) $r->meter_at_fill : null,
                    'km_since_fill'  => ($sinceById[$r->id] ?? null) > 0 ? $sinceById[$r->id] : null,
                    'km_since_fill_odd' => ($sinceById[$r->id] ?? null) === -1,
                    'litres'         => $r->litres !== null ? (float) $r->litres : null,
                    'service_type'   => $r->service_type,
                    // The manager's own label for this job ("Brake Shoe"), falling back
                    // to the bucket name on rows filed before types existed.
                    'maintenance_type_id' => $r->maintenance_type_id !== null ? (int) $r->maintenance_type_id : null,
                    'maintenance_type'    => $maintTypes->labelFor($r->maintenance_type_id ?? null, $r->service_type),
                    // Regular services only: how far the bike ran since the last
                    // one, and by how much this beat the schedule.
                    'km_since_service' => $serviceGap[$r->id]['since'] ?? null,
                    'service_early_by' => $serviceGap[$r->id]['early_by'] ?? null,
                    'service_late_by'  => $serviceGap[$r->id]['late_by'] ?? null,
                    'service_interval' => $serviceGap[$r->id]['interval'] ?? null,
                    // A PENDING regular service can't have reset the clock yet, so
                    // the bike is still overdue while it waits. Showing that on the
                    // row being approved answers "why is this bike red?".
                    'overdue_now_km' => ($r->status === 'pending' && $r->expense_category === self::CAT_MAINT
                        && in_array($r->service_type, ['oil_change', 'general'], true)
                        && ($svcState['state'] ?? null) === 'overdue')
                        ? abs((int) $svcState['due_in_km']) : null,
                    // How far off schedule the bike was AT THE MOMENT this was
                    // approved, frozen then. Negative = overdue by that many km.
                    // Without it the evidence disappears the instant approval
                    // resets the clock, and the record can no longer say why.
                    'service_due_km_at_approval' => $r->service_due_km !== null ? (int) $r->service_due_km : null,
                    'filed_at'  => $r->created_at,
                    'note'      => $r->description,
                    // What the approver typed when approving/rejecting — real
                    // context like "Tyre Puncture" that only lives on the
                    // approval row, and was invisible everywhere until now.
                    'approval_notes' => $approvalNotes[$r->id] ?? [],
                    // Who signed this off and from which screen.
                    'approval_actions' => $approvalActions[$r->id] ?? [],
                    'flag'      => $flagsById[$r->id] ?? null,
                    'photo'     => $this->attachmentUrl($r->attachments),
                    // Which approval level this request is WAITING on, so an
                    // approve action from here posts the same level the Daily
                    // Closing screen would. null once nothing is pending.
                    'next_level' => $this->nextApprovalLevel($r),
                    // The account it was FILED against (null on older claims, and
                    // on anything a rider raised — he is never asked). The approver
                    // still decides, but he starts from what was filed rather than
                    // from whatever happens to be first in the list.
                    'filed_source_id' => $r->payment_source_account_id !== null ? (int) $r->payment_source_account_id : null,
                    'filed_bank_id'   => $r->receiving_account_id !== null ? (int) $r->receiving_account_id : null,
                ];
            }
        }

        krsort($days);

        // Every off-duty stretch on its own line: meter-out at home → next
        // morning's meter-in. This is the ONLY distance outside the shift, so
        // it is the one a manager will want to open and read night by night.
        $offNights = [];
        if ($isCompany === true) {
            foreach ($days as $d) {
                if (($d['offduty_km'] ?? 0) > 0) {
                    $offNights[] = [
                        'date'  => $d['date'],
                        'since' => $d['offduty_since'],   // set when it spans >1 day
                        'km'    => $d['offduty_km'],
                        'from'  => null,                   // filled below
                        'to'    => $d['meter_start'],
                    ];
                }
            }
            // `from` is the previous usable close; recover it from the km delta.
            foreach ($offNights as &$n) {
                $n['from'] = $n['to'] !== null ? $n['to'] - $n['km'] : null;
            }
            unset($n);
            usort($offNights, fn ($a, $b) => strcmp($b['date'], $a['date']));
        }

        return [
            'user_id' => $userId,
            'name'    => $name,
            'bike'    => $isCompany === null ? 'unknown' : ($isCompany ? 'company' : 'own'),
            'month'   => $month,
            'days'    => array_values($days),
            'off_nights' => $offNights,
            'service' => ($this->serviceState([$userId])[$userId] ?? null),
            'service_history' => $this->serviceHistory($userId),
            // 🔧 Per-type schedule — the report the manager actually asked for:
            // each job on its own clock (oil 1,200 / brake shoe 10,000 / …) rather
            // than one number for the whole bike.
            'service_schedule' => $this->serviceSchedule($userId),
            // What the month's maintenance money went ON, by type.
            'maint_by_type' => $this->maintByType($userId, $from, $to),
        ];
    }

    /**
     * Every scheduled maintenance type, with when this rider's bike last had it
     * and how far it is from being due again.
     *
     * Derived from approved claims, not stored anywhere — so it self-corrects if a
     * claim is edited or rejected, and needs no backfill. Only types that carry an
     * interval appear: "as conditions" work (Chain Set, Misc) has no due date to
     * count down to and would just be noise on a schedule.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceSchedule(int $userId): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('t_fleet_maintenance_types')) {
                return [];
            }
            $types = DB::table('t_fleet_maintenance_types')
                ->where('is_active', 1)->where('interval_km', '>', 0)
                ->orderBy('sort_order')->orderBy('type_name')
                ->get(['id', 'type_name', 'interval_km', 'bucket']);
            if ($types->isEmpty()) {
                return [];
            }

            // Last job per type for this rider — from APPROVED claims (the normal
            // path, which carries the bill and the photo) AND from manually
            // recorded services (work done outside the system, no bill). Both are
            // real evidence the job happened; whichever is further along the
            // odometer is the one that counts.
            $last = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->where('expense_category', 'Maintenance')
                ->where('status', 'approved')
                ->whereNotNull('maintenance_type_id')
                ->whereNotNull('meter_at_fill')
                ->groupBy('maintenance_type_id')
                ->selectRaw('maintenance_type_id AS tid, MAX(meter_at_fill) AS m,
                             MAX(COALESCE(expense_date, DATE(created_at))) AS d')
                ->get()->keyBy('tid');

            if (\Illuminate\Support\Facades\Schema::hasTable('t_fleet_service_log')) {
                $logged = DB::table('t_fleet_service_log')
                    ->where('user_id', $userId)
                    ->groupBy('maintenance_type_id')
                    ->selectRaw('maintenance_type_id AS tid, MAX(meter) AS m, MAX(service_date) AS d')
                    ->get();
                foreach ($logged as $row) {
                    $existing = $last->get($row->tid);
                    if (!$existing || (int) $row->m > (int) $existing->m) {
                        $last->put($row->tid, $row);
                    }
                }
            }

            $now = $this->currentMeters([$userId])[$userId] ?? null;

            $out = [];
            foreach ($types as $t) {
                $l    = $last->get($t->id);
                $lastM = $l ? (int) $l->m : null;
                // due_in is only meaningful when we know BOTH where the bike is now
                // and when this job was last done. Anything else stays null rather
                // than inventing a countdown from a made-up reference point.
                $dueIn = ($lastM !== null && $now !== null)
                    ? (int) $t->interval_km - ($now - $lastM) : null;

                $out[] = [
                    'id'          => (int) $t->id,
                    'name'        => $t->type_name,
                    'bucket'      => $t->bucket,
                    'interval_km' => (int) $t->interval_km,
                    'last_meter'  => $lastM,
                    'last_at'     => $l->d ?? null,
                    'due_at_km'   => $lastM !== null ? $lastM + (int) $t->interval_km : null,
                    'due_in_km'   => $dueIn,
                    'state'       => $dueIn === null ? 'unknown'
                                    : ($dueIn < 0 ? 'overdue' : ($dueIn <= 150 ? 'due_soon' : 'ok')),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * This month's maintenance spend split by type — "where did the Rs 1,760 go".
     * Approved and pending both count; pending is flagged so the manager can see
     * what is still waiting on him.
     *
     * @return array<int, array<string, mixed>>
     */
    private function maintByType(int $userId, string $from, string $to): array
    {
        try {
            $rows = DB::table('t_req_master as r')
                ->leftJoin('t_fleet_maintenance_types as t', 't.id', '=', 'r.maintenance_type_id')
                ->where('r.requester_user_id', $userId)
                ->where('r.expense_category', 'Maintenance')
                ->whereNotIn('r.status', ['cancelled', 'rejected'])
                ->whereRaw('COALESCE(r.expense_date, DATE(r.created_at)) BETWEEN ? AND ?', [$from, $to])
                ->groupBy('r.maintenance_type_id', 't.type_name', 'r.service_type')
                ->selectRaw("COALESCE(t.type_name,
                                CASE WHEN r.service_type IN ('oil_change','general') THEN 'Regular service'
                                     WHEN r.service_type = 'repair' THEN 'Repair'
                                     ELSE 'Maintenance' END) AS label,
                             SUM(r.amount) AS total, COUNT(*) AS n,
                             SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_n")
                ->get();

            // Untyped rows collapse onto the same bucket label, so merge by label.
            $merged = [];
            foreach ($rows as $r) {
                $k = $r->label;
                $merged[$k] ??= ['label' => $k, 'total' => 0.0, 'n' => 0, 'pending_n' => 0];
                $merged[$k]['total']     += (float) $r->total;
                $merged[$k]['n']         += (int) $r->n;
                $merged[$k]['pending_n'] += (int) $r->pending_n;
            }
            usort($merged, fn ($a, $b) => $b['total'] <=> $a['total']);
            return array_values($merged);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * claim_id => km since the previous fuel fill (or -1 when the two readings
     * don't add up). Fills are chained in filing order; the seed is the last
     * fill with a meter reading BEFORE the month, so month boundaries don't
     * blank the first delta. Fills without a reading break the chain honestly —
     * the next delta would span an unknown distance, so it is not invented.
     */
    private function kmSinceLastFill(int $userId, $claimRows, string $monthStart): array
    {
        $fills = collect($claimRows)
            ->filter(fn ($r) => $r->expense_category === self::CAT_FUEL)
            ->sortBy(fn ($r) => $r->d . '|' . $r->created_at)
            ->values();
        if ($fills->isEmpty()) return [];

        $prev = DB::table('t_req_master')
            ->where('requester_user_id', $userId)
            ->where('expense_category', self::CAT_FUEL)
            ->whereNotNull('meter_at_fill')
            ->whereIn('status', ['approved', 'pending'])
            ->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$monthStart])
            ->orderByRaw('COALESCE(expense_date, DATE(created_at)) DESC')
            ->orderByDesc('created_at')
            ->value('meter_at_fill');
        $prev = $prev !== null ? (int) $prev : null;

        $out = [];
        foreach ($fills as $f) {
            if ($f->meter_at_fill === null) {
                // A fill with no reading breaks the chain: the NEXT delta would
                // silently include this tank's unknown distance.
                $prev = null;
                continue;
            }
            $cur = (int) $f->meter_at_fill;
            if ($prev === null) {
                $prev = $cur;
                continue;
            }
            $delta = $cur - $prev;
            if ($delta >= 1 && $delta <= 2000) {
                $out[$f->id] = $delta;
                $prev = $cur;
            } else {
                // Implausible reading (typo / swapped bike). Flag it, but keep the
                // anchor at the last SANE reading — otherwise one bad entry would
                // poison every delta after it instead of just its own.
                $out[$f->id] = -1;
            }
        }
        return $out;
    }

    /**
     * The approval level a pending request is waiting on.
     *
     * Mirrors RequestModel::canBeApprovedByLevel — L1 first when required and
     * still pending, otherwise L2. Returning the wrong level would make the
     * server reject the approval, so this must stay in step with that model.
     */
    private function nextApprovalLevel($r): ?int
    {
        if (($r->status ?? null) !== 'pending') return null;
        if ((int) ($r->requires_level_1 ?? 0) === 1 && ($r->level_1_status ?? null) === 'pending') return 1;
        if ((int) ($r->requires_level_2 ?? 0) === 1 && ($r->level_2_status ?? null) === 'pending') return 2;
        return null;
    }

    /**
     * For each REGULAR service in the month: how far the bike ran since the
     * previous regular service, and — if that is short of the bike's interval —
     * by how much it beat the schedule.
     *
     * "Early" is worth surfacing because a service at 300 km on a 500 km schedule
     * is either money spent sooner than needed or a bike with a problem; either
     * way the approver should see it before paying. Repairs are never measured
     * this way — they are ad-hoc by definition and never reset the clock.
     *
     * Needs the odometer at the service (`meter_at_fill`), so it stays null for
     * everything filed before that field existed rather than being guessed.
     */
    private function kmSinceLastService(int $userId, $claimRows, ?bool $isCompany): array
    {
        $regular = collect($claimRows)
            ->filter(fn ($r) => $r->expense_category === self::CAT_MAINT
                && in_array($r->service_type, ['oil_change', 'general'], true)
                && $r->meter_at_fill !== null
                && in_array($r->status, ['approved', 'pending'], true))
            ->sortBy(fn ($r) => $r->d . '|' . $r->created_at)
            ->values();
        if ($regular->isEmpty()) return [];

        $profiles = $this->profiles();
        $p = $profiles[$userId] ?? null;
        $interval = (int) (($p->service_interval_km ?? 0) ?: $this->cfg('BIKE_SERVICE_INTERVAL_KM', 3000));
        if ($interval <= 0) return [];

        // Seed from the most recent regular service BEFORE this month, so the
        // first one in the month is still measured. APPROVED ONLY — the anchor
        // every claim is judged against must be settled fact. A pending claim
        // still gets measured (the approver wants the number next to Approve),
        // but until someone approves it, its meter is just a rider's typing and
        // must not shift how the next real claim reads: a mistyped pending meter
        // used to poison the following claim's early/late, and a claim that was
        // later REJECTED had already served as an anchor.
        $prev = DB::table('t_req_master')
            ->where('requester_user_id', $userId)
            ->where('expense_category', self::CAT_MAINT)
            ->whereIn('service_type', ['oil_change', 'general'])
            ->whereNotNull('meter_at_fill')
            ->where('status', 'approved')
            ->whereRaw('COALESCE(expense_date, DATE(created_at)) < ?', [$regular->first()->d])
            ->orderByRaw('COALESCE(expense_date, DATE(created_at)) DESC')
            ->value('meter_at_fill');
        $prev = $prev !== null ? (int) $prev : null;

        $out = [];
        foreach ($regular as $r) {
            $cur = (int) $r->meter_at_fill;
            $isApproved = $r->status === 'approved';
            if ($prev !== null) {
                $since = $cur - $prev;
                // Implausible gap = a typo or a swapped bike; don't call it early.
                if ($since >= 1 && $since <= 50000) {
                    $out[$r->id] = [
                        'since'    => $since,
                        'interval' => $interval,
                        // Both directions matter: early is money spent sooner than
                        // needed, late means the bike ran past its service — the
                        // reason the due chip was red. A small tolerance stops a
                        // service a few km either side reading as a problem.
                        'early_by' => $since < $interval - 25 ? $interval - $since : null,
                        'late_by'  => $since > $interval + 25 ? $since - $interval : null,
                    ];
                    // Anchor advances on APPROVED rows only (see the seed note).
                    if ($isApproved) { $prev = $cur; }
                    continue;
                }
            }
            $out[$r->id] = ['since' => null, 'interval' => $interval, 'early_by' => null, 'late_by' => null];
            if ($isApproved) { $prev = $cur; }
        }
        return $out;
    }

    /**
     * Which money bucket a maintenance row belongs to.
     * `general` rides with `oil_change` because both are scheduled work and both
     * reset the service clock (see BikeServiceClock::onRequestApproved).
     */
    private function maintBucket(?string $serviceType): string
    {
        if (in_array($serviceType, ['oil_change', 'general'], true)) return 'maint_regular_rs';
        if ($serviceType === 'repair') return 'maint_repair_rs';
        return 'maint_other_rs';        // 'other', or filed before the field existed
    }

    /**
     * The approval trail for a set of requests, split into two things that look
     * alike but answer different questions:
     *
     *  'notes'   — what the approver actually TYPED. Managers use this field for
     *              real detail ("Tyre Puncture", "Oil Change") and it lived only
     *              on the approval row, so it never reached any screen.
     *  'actions' — WHO acted, WHEN, and from WHICH screen. Every approve path in
     *              the system stamps its own name into `comments` ("Approved from
     *              daily closing" / "from Fleet" / "from month view"), so that
     *              boilerplate — noise as a note — is precisely the provenance:
     *              it tells you Shabib approved from Daily Closing while Qasim
     *              approved from Bikes.
     *
     * Both come out of one query. A row with a typed note contributes to both.
     *
     * @return array{notes: array<int,array>, actions: array<int,array>}
     */
    private function approvalTrail(array $requestIds): array
    {
        if (!$requestIds) return ['notes' => [], 'actions' => []];
        try {
            $rows = DB::table('t_req_approval as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.approver_user_id')
                ->whereIn('a.request_id', $requestIds)
                ->whereIn('a.status', ['approved', 'rejected'])
                ->orderBy('a.action_date')
                ->get(['a.request_id', 'a.approval_level', 'a.status', 'a.comments', 'a.action_date', 'u.fullname']);

            $notes = [];
            $actions = [];
            foreach ($rows as $r) {
                $id   = (int) $r->request_id;
                $text = trim((string) ($r->comments ?? ''));

                $actions[$id][] = [
                    'level'  => (int) $r->approval_level,
                    'status' => $r->status,
                    'by'     => $r->fullname,
                    'at'     => $r->action_date,
                    'source' => $this->approvalSource($text),
                ];

                if ($text !== '' && !$this->isBoilerplateComment($text)) {
                    $notes[$id][] = [
                        'level'  => (int) $r->approval_level,
                        'status' => $r->status,
                        'by'     => $r->fullname,
                        'at'     => $r->action_date,
                        'text'   => mb_substr($text, 0, 300),
                    ];
                }
            }
            return ['notes' => $notes, 'actions' => $actions];
        } catch (\Throwable $e) {
            return ['notes' => [], 'actions' => []];
        }
    }

    /** Auto-written "Approved from X" — provenance, not a human note. */
    private function isBoilerplateComment(string $text): bool
    {
        return (bool) preg_match('/^(approved|rejected) from (daily closing|fleet|month view)$/i', $text);
    }

    /**
     * Which screen the action came from, read back off the stamp that screen
     * writes. Null when nothing recognisable was written — that means the
     * Requests screen, which posts the approver's own words instead.
     */
    private function approvalSource(string $text): ?string
    {
        if (stripos($text, 'from daily closing') !== false) return 'Daily Closing';
        if (stripos($text, 'from month view') !== false)    return 'Daily Closing — month view';
        if (stripos($text, 'from Fleet') !== false)         return 'Bikes';
        return null;
    }

    /** Past maintenance claims — the bike's service record. */
    private function serviceHistory(int $userId): array
    {
        $rows = DB::table('t_req_master')
            ->selectRaw("id, amount, status, service_type, meter_at_fill, attachments,
                         COALESCE(expense_date, DATE(created_at)) AS d")
            ->where('requester_user_id', $userId)
            ->where('expense_category', self::CAT_MAINT)
            ->whereIn('status', ['approved', 'pending'])
            ->orderByDesc('d')->limit(12)->get();

        return $rows->map(fn ($r) => [
            'date'   => $r->d,
            'amount' => (float) $r->amount,
            'status' => $r->status,
            'type'   => $r->service_type,
            'meter'  => $r->meter_at_fill !== null ? (int) $r->meter_at_fill : null,
            'photo'  => $this->attachmentUrl($r->attachments),
        ])->toArray();
    }

    // =================================================================
    // helpers
    // =================================================================

    /** Rider profiles keyed by user id (bike type + service fields). */
    private function profiles(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = DB::table('t_ops_rider_profile')
            ->select('user_id', 'company_bike', 'meter_required', 'vehicle_type', 'active',
                     'service_interval_km', 'last_service_meter', 'last_service_at')
            ->get()->keyBy('user_id')->toArray();
        return $cache;
    }

    /** First and last day of a YYYY-MM month. */
    private function bounds(string $month): array
    {
        $c = Carbon::parse($month . '-01');
        return [$c->copy()->startOfMonth()->format('Y-m-d'), $c->copy()->endOfMonth()->format('Y-m-d')];
    }

    /** Browser-usable URL for a request's photo, or null. */
    /**
     * Receipt photo for a claim, as an ABSOLUTE url.
     *
     * ⚠ It used to return a bare '/public-storage/…' path. A browser resolves that
     * against the page origin, so the web screens looked fine — but React Native's
     * <Image source={{uri}}> has no origin to resolve against, so every petrol and
     * maintenance photo silently failed to load on the phone, in BOTH store and
     * Khaas/Frozen mode. Same absolute form getMeterPictureUrl() already uses for
     * the attendance meter pictures, which is why those always worked on mobile.
     */
    /**
     * The most recent odometer this rider recorded ON A FUEL FILL, whatever month
     * it was in. Used only to show a live "N km since his last fill" while a claim
     * is being typed — the stored figure on the claim still comes from
     * kmSinceLastFill(), which chains fills properly and refuses to guess across a
     * fill that carried no reading.
     */
    private function lastFillMeter(int $userId): ?int
    {
        try {
            $v = DB::table('t_req_master')
                ->where('requester_user_id', $userId)
                ->where('expense_category', self::CAT_FUEL)
                ->whereNotNull('meter_at_fill')
                ->where('meter_at_fill', '>', self::MIN_METER)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->orderByRaw('COALESCE(expense_date, DATE(created_at)) DESC, id DESC')
                ->value('meter_at_fill');
            return $v !== null ? (int) $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function attachmentUrl($attachments): ?string
    {
        if (empty($attachments)) return null;
        $paths = is_array($attachments) ? $attachments : json_decode($attachments, true);
        if (!is_array($paths) || !isset($paths[0]) || !$paths[0]) return null;

        $path = '/public-storage/' . ltrim((string) $paths[0], '/');

        // Console/queue contexts have no request; fall back to the relative path
        // rather than building a url against a bogus host.
        try {
            $base = request() ? request()->getSchemeAndHttpHost() : null;
        } catch (\Throwable $e) {
            $base = null;
        }
        return $base ? rtrim($base, '/') . $path : $path;
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
