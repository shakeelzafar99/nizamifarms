<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\Log;

/**
 * ⚠ "WHICH BIKES ARE ASKING FOR SOMETHING?" — one answer, for every fleet list.
 *
 * ⭐⭐ WHY THIS EXISTS (owner review, Sep-2026): *"someone opening the vehicles page — will it be
 *    clear which bikes are asking for an action?"* It was not. The card showed plate, keeper,
 *    meter and a service line, in registry order — a STATUS list, not a TRIAGE list. A bike with
 *    an urgent ticket sat below one with nothing wrong, and the reader had to check every card.
 *
 * ⭐ So this composes the four things that can want a manager, per machine:
 *      1. a ticket marked "not rideable"   — a rider is stranded RIGHT NOW;
 *      2. a workshop visit MISSED          — an instruction nobody carried out;
 *      3. service overdue                  — the machine is past its schedule;
 *      4. a rider waiting on a handover    — somebody cannot start;
 *    …then the softer ones (a visit today/tomorrow, unconfirmed, open tickets, service due soon).
 *
 * ⭐ ONE ENGINE, TWO RENDERERS. The web grid and the mobile list both read this map and both sort
 *   by `rank`, so the two screens cannot disagree about which bike is worst — the failure mode
 *   this whole round has been removing.
 *
 * ⚠ Every lookup is schema-guarded and batched: this decorates a LIST, so it must never add a
 *   query per row, and it must degrade to "nothing needs attention" rather than take the fleet
 *   screen down when a table is missing.
 */
class FleetAttentionService
{
    /** Lower rank = more urgent. Used as the sort key by both renderers. */
    public const RANK_STRANDED   = 0;   // ticket says the bike cannot be ridden
    public const RANK_MISSED     = 1;   // a workshop visit was not carried out
    public const RANK_OVERDUE    = 2;   // service is past due
    public const RANK_WAITING    = 3;   // a rider is waiting on a handover
    public const RANK_TODAY      = 4;   // workshop today
    public const RANK_TOMORROW   = 5;   // workshop tomorrow
    public const RANK_UNCONFIRMED = 6;  // workshop set, rider has not confirmed
    public const RANK_TICKETS    = 7;   // open tickets, none urgent
    public const RANK_DUE_SOON   = 8;   // service due soon
    public const RANK_NONE       = 99;  // nothing to do

    /**
     * @param array $vehicles rows from VehicleService::all()
     * @param array $pending  the handover map from VehicleController::pendingByVehicle()
     * @return array<string, array> keyed by vehicle id (STRING keys — a JSON object, so the
     *         client can look one up without scanning; the same shape `pending_requests` uses)
     */
    public function forVehicles(array $vehicles, array $pending = []): array
    {
        if (!$vehicles) return [];
        $ids = array_values(array_filter(array_map(fn ($v) => (int) ($v['id'] ?? 0), $vehicles)));
        if (!$ids) return [];

        $tickets  = $this->ticketsByVehicle($ids);
        $visits   = $this->visitsByVehicle($ids);

        $out = [];
        foreach ($vehicles as $v) {
            $id   = (int) ($v['id'] ?? 0);
            if (!$id) continue;
            $t    = $tickets[$id] ?? ['open' => 0, 'urgent' => 0];
            $w    = $visits[$id]  ?? null;
            $svc  = $v['service'] ?? null;
            $due  = $svc['due_in_km'] ?? null;
            $state = $svc['state'] ?? null;
            $wait = $pending[(string) $id] ?? null;

            $flags = [];
            $rank  = self::RANK_NONE;
            $keep  = function (int $r) use (&$rank) { if ($r < $rank) $rank = $r; };

            if (!empty($t['urgent'])) {
                $flags[] = ['k' => 'stranded', 'text' => '🔴 Not rideable'];
                $keep(self::RANK_STRANDED);
            }
            if ($w && !empty($w['is_missed'])) {
                $flags[] = ['k' => 'missed', 'text' => '🔧 Workshop missed ' . $w['visit_date']];
                $keep(self::RANK_MISSED);
            }
            // ⚠ `due_in_km` is negative when overdue — the same convention the chips use.
            if ($state === 'overdue' || (is_numeric($due) && $due < 0)) {
                $flags[] = ['k' => 'overdue', 'text' => '🛢 Service overdue'];
                $keep(self::RANK_OVERDUE);
            }
            if ($wait) {
                $flags[] = ['k' => 'waiting', 'text' => '⏳ ' . ($wait['rider_name'] ?? 'A rider') . ' waiting'];
                $keep(self::RANK_WAITING);
            }
            if ($w && empty($w['is_missed'])) {
                if (!empty($w['is_today'])) {
                    $flags[] = ['k' => 'today', 'text' => '🔧 Workshop today'
                        . ($w['visit_time'] ? ' ' . $w['visit_time'] : '')];
                    $keep(self::RANK_TODAY);
                } elseif (!empty($w['is_tomorrow'])) {
                    $flags[] = ['k' => 'tomorrow', 'text' => '🔧 Workshop tomorrow'];
                    $keep(self::RANK_TOMORROW);
                } else {
                    $flags[] = ['k' => 'booked', 'text' => '🔧 Workshop ' . $w['visit_date']];
                }
                if (empty($w['accepted'])) {
                    $flags[] = ['k' => 'unconfirmed', 'text' => '⏳ not confirmed'];
                    $keep(self::RANK_UNCONFIRMED);
                }
            }
            if (!empty($t['open'])) {
                $flags[] = ['k' => 'tickets', 'text' => '🛠 ' . $t['open'] . ' open'];
                $keep(self::RANK_TICKETS);
            }
            if ($state === 'due_soon') {
                $flags[] = ['k' => 'due_soon', 'text' => '🛢 Service due soon'];
                $keep(self::RANK_DUE_SOON);
            }

            $out[(string) $id] = [
                'rank'    => $rank,
                'needs'   => $rank < self::RANK_NONE,
                'flags'   => $flags,
                'tickets_open'   => (int) $t['open'],
                'tickets_urgent' => (int) $t['urgent'],
                'workshop'       => $w,
            ];
        }
        return $out;
    }

    /** How many tickets are open per machine, and whether any says "not rideable". */
    private function ticketsByVehicle(array $vehicleIds): array
    {
        try {
            $svc = app(VehicleTicketService::class);
            if (!$svc->available()) return [];
            $rows = \DB::table(VehicleTicketService::T_TICKET)
                ->whereIn('vehicle_id', $vehicleIds)
                ->whereIn('status', VehicleTicketService::OPEN_STATUSES)
                ->get(['vehicle_id', 'urgent']);
            $out = [];
            foreach ($rows as $r) {
                $k = (int) $r->vehicle_id;
                $out[$k] ??= ['open' => 0, 'urgent' => 0];
                $out[$k]['open']++;
                if ($r->urgent) $out[$k]['urgent']++;
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('FleetAttention: tickets lookup failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** The soonest live visit per machine, already shaped (is_today / is_tomorrow / is_missed). */
    private function visitsByVehicle(array $vehicleIds): array
    {
        try {
            $svc = app(WorkshopVisitService::class);
            if (!$svc->available()) return [];
            $out = [];
            // ⚠ ONE call for the whole list — listVisits is already ordered by date, so the first
            //   row per machine is its soonest. A per-vehicle call would be a query per card.
            foreach ($svc->listVisits(['from' => \Carbon\Carbon::today()->subDays(30)->format('Y-m-d'),
                                       'limit' => 400]) as $v) {
                $k = (int) $v['vehicle_id'];
                if (!in_array($k, $vehicleIds, true) || isset($out[$k])) continue;
                $out[$k] = [
                    'id' => $v['id'], 'visit_date' => $v['visit_date'], 'visit_time' => $v['visit_time'],
                    'rider_name' => $v['rider_name'], 'accepted' => $v['accepted'],
                    'accepted_on_behalf' => $v['accepted_on_behalf'],
                    'is_today' => $v['is_today'], 'is_tomorrow' => $v['is_tomorrow'],
                    'is_missed' => $v['is_missed'], 'workshop' => $v['workshop'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('FleetAttention: visits lookup failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
