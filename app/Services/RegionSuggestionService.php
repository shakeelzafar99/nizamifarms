<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Suggests a delivery region for customers that no coordinate can place, by
 * looking at who their orders went out WITH.
 *
 * Why this works (measured over the last 6 months of non-Qurbani deliveries):
 * a rider's DAY is not one region — average purity 65%, ~2.5 regions per day —
 * so "same rider, same day" is only ~59% accurate and is useless. But a
 * DISPATCH BATCH is: orders stamped out_for_delivery together are ~93% one
 * region, and orders delivered within 15-20 minutes of each other are ~96% one
 * region. Those two windows are what this service votes on.
 *
 * Precision guard: only companions whose own region is trustworthy get a vote —
 * i.e. derived from a human-verified GPS pin, or set by a human. Regions guessed
 * from a Google geocode are excluded, because ~2/3 of those are wrong (Google
 * returns city-centre fallbacks: one coordinate alone accounts for 882
 * customers). Letting them vote would launder that error into new customers.
 *
 * This service only ever reads. Applying a suggestion is a separate, explicit
 * action a human takes in the UI.
 */
class RegionSuggestionService
{
    /** Orders stamped out_for_delivery within this many minutes = same batch. */
    public const BATCH_GAP_MINUTES = 5;

    /** Orders delivered within this many minutes = same leg of the run. */
    public const DELIVERY_GAP_MINUTES = 20;

    /** Only these region sources are trusted enough to vote. */
    public const VOTER_SOURCES = ['polygon_verified', 'manual'];

    /** Below this share of the vote, we show the split but recommend nothing. */
    public const MIN_CONFIDENCE = 0.60;

    /**
     * Build suggestions for a set of customers.
     *
     * @param  int[] $customerIds
     * @return array<int, array> keyed by customer_id; customers with no usable
     *                           evidence are simply absent from the result.
     */
    public static function suggestForCustomers(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_map('intval', array_filter($customerIds))));
        if (empty($customerIds)) {
            return [];
        }

        // 1. The target customers' own delivery events (which rider, when).
        $targetEvents = self::loadEvents(['o.customer_id' => $customerIds]);
        if (empty($targetEvents)) {
            return [];
        }

        // 2. Everything those same riders did on those same days — the pool the
        //    companions come from. One query, not one per customer.
        $riderDays = [];
        foreach ($targetEvents as $e) {
            $riderDays[$e['rider'] . '|' . $e['day']] = ['rider' => $e['rider'], 'day' => $e['day']];
        }
        $poolEvents = self::loadEventsForRiderDays(array_values($riderDays));

        // Index the pool by rider|day so companion lookup is a hash hit.
        $pool = [];
        foreach ($poolEvents as $e) {
            $pool[$e['rider'] . '|' . $e['day']][] = $e;
        }

        // 3. Vote.
        $byCustomer = [];
        foreach ($targetEvents as $e) {
            $byCustomer[$e['customer_id']][] = $e;
        }

        $regions = DB::table('t_ops_delivery_region')
            ->get(['id', 'name', 'code', 'color'])
            ->keyBy('id');

        $out = [];
        foreach ($byCustomer as $customerId => $events) {
            $suggestion = self::voteForCustomer((int) $customerId, $events, $pool, $regions);
            if ($suggestion) {
                $out[(int) $customerId] = $suggestion;
            }
        }

        return $out;
    }

    public static function suggestForCustomer(int $customerId): ?array
    {
        return self::suggestForCustomers([$customerId])[$customerId] ?? null;
    }

    /**
     * Tally companion regions for one customer across all of that customer's
     * delivery events.
     */
    private static function voteForCustomer(int $customerId, array $events, array $pool, $regions): ?array
    {
        $batchGap    = self::BATCH_GAP_MINUTES * 60;
        $deliveryGap = self::DELIVERY_GAP_MINUTES * 60;

        // Keyed by companion ORDER id so a single companion order can never vote
        // twice, even when the target customer had two orders on the same run.
        $companions = [];

        foreach ($events as $e) {
            $key = $e['rider'] . '|' . $e['day'];
            foreach ($pool[$key] ?? [] as $c) {
                if ((int) $c['customer_id'] === $customerId) {
                    continue;
                }
                if (!$c['region_id'] || !in_array($c['region_source'], self::VOTER_SOURCES, true)) {
                    continue;
                }

                $sameBatch = $e['ofd_ts'] && $c['ofd_ts']
                    && abs($c['ofd_ts'] - $e['ofd_ts']) <= $batchGap;
                $sameLeg = $e['delivered_ts'] && $c['delivered_ts']
                    && abs($c['delivered_ts'] - $e['delivered_ts']) <= $deliveryGap;

                if (!$sameBatch && !$sameLeg) {
                    continue;
                }

                $companions[$c['order_id']] = [
                    'region_id'   => (int) $c['region_id'],
                    'customer_id' => (int) $c['customer_id'],
                    'day'         => $c['day'],
                    'basis'       => $sameBatch ? 'batch' : 'leg',
                ];
            }
        }

        if (empty($companions)) {
            return null;
        }

        // votes  = co-delivered orders (what the accuracy figures were measured on)
        // trips  = distinct days that region showed up on
        // people = distinct companion customers
        $tally = [];
        foreach ($companions as $c) {
            $rid = $c['region_id'];
            $tally[$rid]['votes'] = ($tally[$rid]['votes'] ?? 0) + 1;
            $tally[$rid]['days'][$c['day']] = true;
            $tally[$rid]['people'][$c['customer_id']] = true;
        }

        $breakdown = [];
        foreach ($tally as $rid => $t) {
            $region = $regions[$rid] ?? null;
            $breakdown[] = [
                'region_id'   => $rid,
                'region_name' => $region->name ?? ('Region ' . $rid),
                'region_code' => $region->code ?? null,
                'color'       => $region->color ?? '#6b7280',
                'votes'       => $t['votes'],
                'trips'       => count($t['days']),
                'customers'   => count($t['people']),
            ];
        }

        // Most co-deliveries wins; distinct trips breaks a tie, because evidence
        // spread over three separate runs beats the same run counted three times.
        usort($breakdown, function ($a, $b) {
            return [$b['votes'], $b['trips']] <=> [$a['votes'], $a['trips']];
        });

        $totalVotes = array_sum(array_column($breakdown, 'votes'));
        $top        = $breakdown[0];
        $confidence = $totalVotes > 0 ? $top['votes'] / $totalVotes : 0.0;

        return [
            'customer_id'      => $customerId,
            'suggested'        => $confidence >= self::MIN_CONFIDENCE ? $top : null,
            'confidence'       => round($confidence, 4),
            'confidence_label' => self::label($confidence, $top['votes'], $totalVotes),
            'total_votes'      => $totalVotes,
            'total_trips'      => count(array_unique(array_column($companions, 'day'))),
            'breakdown'        => $breakdown,
            'is_unanimous'     => count($breakdown) === 1,
        ];
    }

    /**
     * Number of agreeing co-deliveries below which "they all said the same
     * thing" stops meaning much. Measured on customers whose true region is
     * known from their own verified pin: unanimous on 1 co-delivery is 79.2%
     * right, on 2 it is 91.0%, on 3 it is 93.6%, on 5+ it is 98.3%. Three is
     * where a unanimous vote becomes safe to apply in bulk (96.7% overall).
     */
    public const SOLID_VOTES = 3;

    /**
     * Plain-English strength. Measured accuracy of this service, hold-one-out,
     * against customers whose true region is known from their own verified pin:
     * unanimous (3+ votes) 96.7% · unanimous (1-2 votes) 83.6% · strong 93.9% ·
     * moderate 87.8% · conflicted 46.6% (a coin flip — never recommended).
     */
    private static function label(float $confidence, int $topVotes, int $totalVotes): string
    {
        if ($totalVotes === 0) {
            return 'none';
        }
        if ($topVotes === $totalVotes) {
            return $totalVotes >= self::SOLID_VOTES ? 'unanimous' : 'unanimous_thin';
        }
        if ($confidence >= 0.75) {
            return 'strong';
        }
        if ($confidence >= self::MIN_CONFIDENCE) {
            return 'moderate';
        }
        return 'conflicted';
    }

    /**
     * Delivery events (rider + dispatch time + delivery time) for orders matching
     * a where clause. Qurbani orders are excluded — their delivery ran on a
     * separate day/slot model, so co-delivery says nothing about normal routing.
     */
    private static function loadEvents(array $whereIn): array
    {
        $query = self::baseEventQuery();
        foreach ($whereIn as $column => $values) {
            $query->whereIn($column, $values);
        }
        return self::normalise($query->get());
    }

    private static function loadEventsForRiderDays(array $riderDays): array
    {
        if (empty($riderDays)) {
            return [];
        }

        $query = self::baseEventQuery();
        $query->where(function ($q) use ($riderDays) {
            foreach ($riderDays as $rd) {
                $q->orWhere(function ($inner) use ($rd) {
                    $inner->where('o.assigned_rider_user_id', $rd['rider'])
                          ->whereRaw('DATE(COALESCE(ofd.ts, del.ts)) = ?', [$rd['day']]);
                });
            }
        });

        return self::normalise($query->get());
    }

    private static function baseEventQuery()
    {
        return DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin(DB::raw('(SELECT order_id, MIN(changed_at) AS ts FROM t_crm_order_status_history
                                 WHERE status_code = \'out_for_delivery\' GROUP BY order_id) as ofd'),
                'ofd.order_id', '=', 'o.id')
            ->leftJoin(DB::raw('(SELECT order_id, MIN(changed_at) AS ts FROM t_crm_order_status_history
                                 WHERE status_code = \'delivered\' GROUP BY order_id) as del'),
                'del.order_id', '=', 'o.id')
            ->whereNotNull('o.assigned_rider_user_id')
            ->whereNotNull('o.customer_id')
            ->whereNotIn('o.order_status', ['cancelled', 'refunded'])
            ->whereNotNull(DB::raw('COALESCE(ofd.ts, del.ts)'))
            // Qurbani exclusion — same definition the region auto-assign uses.
            ->whereNull('o.qurbani_day')
            ->whereNull('o.qurbani_slot')
            ->whereNull('o.qurbani_region')
            ->whereNull('o.qurbani_delivery_type')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('t_crm_prod_order_line_item as li_q')
                    ->join('t_crm_prod_product as p_q', 'p_q.id', '=', 'li_q.product_id')
                    ->whereColumn('li_q.order_id', 'o.id')
                    ->whereRaw("LOWER(COALESCE(p_q.attribute_1, '')) = 'qurbani'");
            })
            ->select([
                'o.id as order_id',
                'o.customer_id',
                'o.assigned_rider_user_id as rider',
                'c.delivery_region_id as region_id',
                'c.delivery_region_source as region_source',
                DB::raw('ofd.ts as ofd_ts'),
                DB::raw('del.ts as delivered_ts'),
            ]);
    }

    private static function normalise($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ofd = $r->ofd_ts ? strtotime($r->ofd_ts) : null;
            $del = $r->delivered_ts ? strtotime($r->delivered_ts) : null;
            if (!$ofd && !$del) {
                continue;
            }
            $out[] = [
                'order_id'      => (int) $r->order_id,
                'customer_id'   => (int) $r->customer_id,
                'rider'         => (int) $r->rider,
                'region_id'     => $r->region_id ? (int) $r->region_id : null,
                'region_source' => $r->region_source,
                'ofd_ts'        => $ofd,
                'delivered_ts'  => $del,
                'day'           => date('Y-m-d', $ofd ?: $del),
            ];
        }
        return $out;
    }
}
