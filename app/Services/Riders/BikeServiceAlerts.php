<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🛢 SERVICE ALERTS — "this bike is due for its oil change" (owner ask, Aug-12).
 *
 * ⚠⚠ THESE ARE **NOT** BIKE METER ALERTS. `receive_bike_meter_alerts` is about a
 *    RIDER (he came home late / never recorded his closing meter). This is about a
 *    MACHINE (a scheduled job is due on it). Different audience, different key
 *    (`receive_service_alerts`), different wording — the owner asked for them to be
 *    kept apart, and conflating them would mean one checkbox silencing two
 *    unrelated duties.
 *
 * ⭐⭐ THE ALERT IS DERIVED, NEVER STORED — and that is the whole answer to "make
 *    sure he stops getting it once the service is done". There is no "open alert"
 *    row to close: the alert IS `serviceScheduleFor()` reporting overdue/due-soon.
 *    Record the service (an approved Maintenance claim with a meter and a type, or
 *    a manual service-log entry) and `last_meter` jumps forward, `due_in_km` goes
 *    positive, the state returns to `ok` — and the alert simply stops existing, on
 *    every surface at once. Nothing to clean up, nothing that can be left stale.
 *
 * ⭐ ONE SOURCE. It reads the SAME `VehicleService::serviceScheduleFor()` the web
 *    profile and both phone screens render, so the banner can never claim a
 *    service is due while the profile says it is fine.
 *
 * WHO IS TOLD (owner ruling Aug-12):
 *   • managers holding the mobile key `receive_service_alerts` (Shabib, Qasim) —
 *     they see EVERY machine;
 *   • the rider actually HOLDING the machine — no permission involved, because
 *     "you are riding it" is the qualification. He sees only his own bike.
 *
 * ALL TYPES. Every active maintenance type with an interval that the team has set
 * up is checked — oil change, oil + tuning, brake shoe, whatever is added later.
 * Nothing here names a type.
 */
class BikeServiceAlerts
{
    /** The mobile permission that makes a manager an audience for these. */
    public const PERMISSION = 'receive_service_alerts';

    /** Cache key for the push throttle (prod has no cron — see fireFromRequest). */
    private const PUSH_LOCK = 'bike_service_alerts_push_lock';

    /**
     * Every service currently due across the active fleet, newest problem first.
     *
     * @return array<int, array<string, mixed>> each: vehicle_id, vehicle_name,
     *         type_id, type_name, state ('overdue'|'due_soon'), due_in_km,
     *         keeper_user_id, keeper_name, alert_key, message
     */
    public function due(): array
    {
        $out = [];
        try {
            $svc = new VehicleService();
            if (!$svc->available()) return [];

            foreach ($svc->all(false) as $v) {
                $meter = isset($v['current_meter']) && $v['current_meter'] !== null
                    ? (int) $v['current_meter'] : null;
                if ($meter === null) continue;      // no reading = nothing measurable

                // ⭐⭐ AN OWN BIKE'S ALERTS BELONG TO ITS OWNER (Aug-27 2026).
                //
                // ⚠⚠ THE GAP THIS CLOSES. `keeper_user_id` is the OPEN assignment —
                //    and an own bike loses its open assignment the moment its rider
                //    is handed a company machine (one open row per rider, enforced).
                //    So exactly the rider this round is about — own bike at home, van
                //    in custody — had NOBODY pushed when his own bike fell due: no
                //    keeper row, and the banner filter only knew the machine he was
                //    HOLDING. Managers still heard; the one man who has to actually
                //    take the bike to the mechanic did not.
                //
                // Ownership is answered by the same rule the day-legs engine uses
                // (`ownerOf` / `ownMachineIdsFor` — one definition, two directions),
                // so "whose alert is this" can never disagree with "whose km are
                // these". A company machine with no keeper stays managers-only:
                // a parked spare is the fleet's problem, not a rider's.
                $keeperId   = $v['keeper_user_id'] ?? null;
                $keeperName = $v['keeper_name'] ?? null;
                if (!$keeperId) {
                    $owner = (new RiderDayLegs())->ownerOf((int) $v['id']);
                    if ($owner) {
                        $keeperId = $owner;
                        try {
                            $keeperName = DB::table('t_sys_user')->where('id', $owner)->value('fullname');
                        } catch (\Throwable $e) { $keeperName = null; }
                    }
                }

                foreach ($svc->serviceScheduleFor((int) $v['id'], $meter) as $t) {
                    // 'unknown' = never recorded, so there is no clock to be late
                    // against. Nagging about a job that has no baseline would be
                    // noise nobody can act on.
                    if (!in_array($t['state'], ['overdue', 'due_soon'], true)) continue;

                    $out[] = [
                        'vehicle_id'     => (int) $v['id'],
                        'vehicle_name'   => $v['name'],
                        'type_id'        => (int) $t['id'],
                        'type_name'      => $t['name'],
                        'state'          => $t['state'],
                        'due_in_km'      => $t['due_in_km'],
                        'interval_km'    => $t['interval_km'],
                        'last_meter'     => $t['last_meter'],
                        'current_meter'  => $meter,
                        'keeper_user_id' => $keeperId,
                        'keeper_name'    => $keeperName,
                        'alert_key'      => $this->keyFor((int) $v['id'], (int) $t['id'],
                                                          $t['last_meter'], $t['state']),
                        'message'        => $this->message($v['name'], $t),
                    ];
                }
            }

            // Overdue before due-soon, then the worst overrun first.
            usort($out, function ($a, $b) {
                if ($a['state'] !== $b['state']) return $a['state'] === 'overdue' ? -1 : 1;
                return ($a['due_in_km'] ?? 0) <=> ($b['due_in_km'] ?? 0);
            });
        } catch (\Throwable $e) {
            Log::warning('BikeServiceAlerts::due failed', ['error' => $e->getMessage()]);
            return [];
        }
        return $out;
    }

    /**
     * ⭐⭐ THE SELF-CLEARING KEY. It embeds the meter the job was LAST DONE at, so:
     *
     *   • dismissed while due-soon → stays dismissed for that cycle;
     *   • it worsens to overdue    → the state changes, so the key changes, and it
     *                                comes back ONCE — an escalation, not a nag;
     *   • the service is recorded  → `last_meter` moves, so every old key is dead;
     *                                the state also returns to `ok`, so nothing is
     *                                raised at all until the NEXT cycle is due,
     *                                which then gets a fresh key of its own.
     *
     * ⚠ Must fit `t_ops_alert_dismissal.alert_key` (varchar 64). The longest real
     *   form is ~40 chars ("svc:8:3:47013:due_soon"), but truncate defensively so a
     *   silently-cut key can never collide with a different alert.
     */
    public function keyFor(int $vehicleId, int $typeId, $lastMeter, string $state): string
    {
        return substr('svc:' . $vehicleId . ':' . $typeId . ':'
            . ($lastMeter === null ? 'none' : (int) $lastMeter) . ':' . $state, 0, 64);
    }

    /** One sentence a manager or rider can act on without opening anything. */
    private function message(string $vehicleName, array $t): string
    {
        return $t['state'] === 'overdue'
            ? $vehicleName . ' — ' . $t['name'] . ' is ' . number_format(abs((int) $t['due_in_km']))
                . ' km overdue.'
            : $vehicleName . ' — ' . $t['name'] . ' due in ' . number_format((int) $t['due_in_km']) . ' km.';
    }

    /**
     * What THIS user should be shown, minus anything he has dismissed.
     *
     * A manager sees the whole fleet; a rider sees only the machine he is holding.
     * Someone who is neither gets an empty list, so the banner simply never
     * appears rather than being hidden client-side.
     */
    public function forUser($user): array
    {
        if (!$user) return [];
        try {
            $isManager = method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission(self::PERMISSION);

            // "The rider holding it" — the OPEN assignment, not a date window: the
            // man who has the bike right now is the one who must get it serviced.
            // ⭐⭐ PLUS HIS OWN MACHINES (Aug-27 2026). Holding the company van does
            //    not make his own bike someone else's problem — but it DOES release
            //    the bike's open assignment (one open row per rider), which used to
            //    drop it from this filter entirely. His day-legs, his claims and his
            //    alerts now all answer ownership with the same rule.
            $his = [];
            $held = (new VehicleResolver())->currentVehicleFor((int) $user->id);
            if ($held) $his[$held] = true;
            foreach ((new RiderDayLegs())->ownMachineIdsFor((int) $user->id) as $own) {
                $his[(int) $own] = true;
            }

            if (!$isManager && !$his) return [];

            $alerts = array_values(array_filter($this->due(), function ($a) use ($isManager, $his) {
                return $isManager || isset($his[$a['vehicle_id']]);
            }));
            if (!$alerts) return [];

            // Riders are told about their own machine; they do not need to know it
            // is "with Kanan" — they are Kanan.
            if (!$isManager) {
                $alerts = array_map(function ($a) {
                    unset($a['keeper_user_id'], $a['keeper_name']);
                    // 🗣 The rider must act on this — he is the one who takes the bike in —
                    // so his copy is Roman Urdu (owner ruling). The manager list above keeps
                    // the English `message` built by message(); this is the only fork.
                    $km = number_format(abs((int) ($a['due_in_km'] ?? 0)));
                    $job = $a['type_name'] ?? 'service';
                    $a['message'] = ($a['state'] ?? '') === 'overdue'
                        ? 'Aap ki bike ka ' . $job . ' ' . $km . ' km late ho chuka hai.'
                        : 'Aap ki bike ka ' . $job . ' ' . $km . ' km baad hai.';
                    return $a;
                }, $alerts);
            }

            return $this->minusDismissed($alerts, (int) $user->id);
        } catch (\Throwable $e) {
            Log::warning('BikeServiceAlerts::forUser failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** Drop the ones this user has already waved away (per user, per cycle). */
    private function minusDismissed(array $alerts, int $userId): array
    {
        try {
            if (!Schema::hasTable('t_ops_alert_dismissal')) return $alerts;
            $keys = array_column($alerts, 'alert_key');
            $gone = array_flip(DB::table('t_ops_alert_dismissal')
                ->where('user_id', $userId)->whereIn('alert_key', $keys)
                ->pluck('alert_key')->all());
            return array_values(array_filter($alerts, fn ($a) => !isset($gone[$a['alert_key']])));
        } catch (\Throwable $e) {
            return $alerts;
        }
    }

    /** This user waves one away. Idempotent. */
    public function dismiss(int $userId, string $alertKey): bool
    {
        try {
            if (!Schema::hasTable('t_ops_alert_dismissal')) return false;
            DB::table('t_ops_alert_dismissal')->updateOrInsert(
                ['user_id' => $userId, 'alert_key' => substr($alertKey, 0, 64)],
                ['dismissed_at' => now()]
            );
            return true;
        } catch (\Throwable $e) {
            Log::warning('BikeServiceAlerts::dismiss failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * ⭐ PUSH, ONCE PER CYCLE — piggybacked on ordinary traffic.
     *
     * ⚠⚠ PROD HAS NO SCHEDULER. `schedule:run` has never executed there, so a
     *    nightly job would be silently dead. This follows the pattern the home-meter
     *    escalation already uses: called from a request, deferred to
     *    `app()->terminating()` by the caller so nothing is slowed, and throttled by
     *    a cache lock so N riders heartbeating does not mean N sweeps.
     *
     * Dedupe is per ALERT KEY in `t_ops_service_alert_push`, so a bike 800 km
     * overdue buzzes once, not every half hour — and because the key carries the
     * last-service meter, the NEXT cycle is free to buzz again on its own merits.
     */
    public function pushDue(): int
    {
        $sent = 0;
        try {
            if (!Schema::hasTable('t_ops_service_alert_push')) return 0;   // SQL not run yet
            $alerts = $this->due();
            if (!$alerts) return 0;

            $fresh = array_values(array_filter($alerts, fn ($a) => !$this->alreadyPushed($a['alert_key'])));
            if (!$fresh) return 0;

            $fb = new \App\Services\FirebaseService();
            foreach ($fresh as $a) {
                try {
                    $fb->notifyServiceDue($a);
                    DB::table('t_ops_service_alert_push')->updateOrInsert(
                        ['alert_key' => $a['alert_key']], ['pushed_at' => now()]
                    );
                    $sent++;
                } catch (\Throwable $e) {
                    // One bad push must not stop the rest; it simply is not marked,
                    // so the next sweep retries it.
                    Log::warning('service alert push failed', [
                        'key' => $a['alert_key'], 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('BikeServiceAlerts::pushDue failed', ['error' => $e->getMessage()]);
        }
        return $sent;
    }

    private function alreadyPushed(string $key): bool
    {
        try {
            return DB::table('t_ops_service_alert_push')->where('alert_key', $key)->exists();
        } catch (\Throwable $e) {
            return true;      // can't tell → stay quiet rather than spam
        }
    }

    /**
     * Call this from a request path. Throttled to roughly once every 30 minutes
     * across the whole app, and deferred so the caller's response is never slowed.
     */
    public function fireFromRequest(): void
    {
        try {
            if (!\Cache::add(self::PUSH_LOCK, 1, 1800)) return;    // ~once per 30 min
            app()->terminating(function () {
                try {
                    $this->pushDue();
                } catch (\Throwable $e) {
                    Log::warning('service alert sweep failed', ['error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
            Log::debug('service alert hook skipped', ['error' => $e->getMessage()]);
        }
    }
}
