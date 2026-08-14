<?php

namespace App\Services\CRM;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ONE authority for "what is physically in the chiller / freezer right now",
 * as shown next to open-order quantities.
 *
 * ⭐⭐ This is product STATE, not order flow. The same product can appear on ten
 *     open orders and still have ONE physical stock figure, so callers must sum
 *     it over a node's DISTINCT products — never accumulate it per line-item row.
 *
 * ⚠️ Keyed by the INTERNAL product id (t_crm_prod_product.id). Never look stock
 *    up with t_crm_prod_order_line_item.product_id: on synced orders that column
 *    holds an EXTERNAL id that can collide with an unrelated internal id.
 *
 * ⚠️ kg and pcs are never added together (overnight `unit` is a kg|pcs enum).
 */
class OvernightStockService
{
    public const SECTIONS = ['chiller', 'freezer'];

    /** Hierarchy levels that group by a product attribute rather than a product. */
    public const CATEGORY_FIELDS = ['product_type', 'attribute_1', 'attribute_2', 'attribute_3'];

    private ?array $byProduct = null;
    private ?array $catalog = null;

    /**
     * One query builds both views of the same stored packets.
     *
     * Counts every STORED packet regardless of manager verification — the
     * question being answered is "how much is physically there".
     *
     * Never throws: the overnight tables may not exist in a given environment
     * yet, and the quantities screens must keep working without them.
     */
    private function load(): void
    {
        if ($this->byProduct !== null) {
            return;
        }

        $this->byProduct = [];
        $this->catalog = [];

        try {
            // LEFT JOIN, not INNER: a packet whose product row was later removed
            // still counts toward its product total, it just can't be categorised.
            $rows = DB::table('t_crm_overnight_item as i')
                ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'i.product_id')
                ->where('i.status', 'stored')
                ->whereNotNull('i.product_id')
                ->selectRaw('i.product_id, i.section, i.unit, COUNT(*) as packets, SUM(i.quantity) as qty,
                             p.product_type, p.attribute_1, p.attribute_2, p.attribute_3')
                ->groupBy('i.product_id', 'i.section', 'i.unit',
                          'p.product_type', 'p.attribute_1', 'p.attribute_2', 'p.attribute_3')
                ->get();

            $blank = fn () => [
                'chiller' => ['packets' => 0, 'kg' => 0.0, 'pcs' => 0.0],
                'freezer' => ['packets' => 0, 'kg' => 0.0, 'pcs' => 0.0],
            ];

            foreach ($rows as $row) {
                $pid = (int) $row->product_id;
                $section = strtolower((string) $row->section) === 'chiller' ? 'chiller' : 'freezer';
                $isPcs = strtolower((string) $row->unit) === 'pcs';

                if (!isset($this->byProduct[$pid])) {
                    $this->byProduct[$pid] = $blank();
                }
                $this->byProduct[$pid][$section]['packets'] += (int) $row->packets;
                $this->byProduct[$pid][$section][$isPcs ? 'pcs' : 'kg'] += (float) $row->qty;

                if (!isset($this->catalog[$pid])) {
                    $this->catalog[$pid] = [
                        'id' => $pid,
                        'product_type' => $row->product_type,
                        'attribute_1' => $row->attribute_1,
                        'attribute_2' => $row->attribute_2,
                        'attribute_3' => $row->attribute_3,
                        'totals' => $blank(),
                    ];
                }
                $this->catalog[$pid]['totals'][$section]['packets'] += (int) $row->packets;
                $this->catalog[$pid]['totals'][$section][$isPcs ? 'pcs' : 'kg'] += (float) $row->qty;
            }
        } catch (\Throwable $e) {
            Log::warning('Overnight stock unavailable — quantities will render without it', [
                'error' => $e->getMessage(),
            ]);
            $this->byProduct = [];
            $this->catalog = [];
        }
    }

    /** product_id => ['chiller' => [packets, kg, pcs], 'freezer' => [...]] */
    public function map(): array
    {
        $this->load();

        return $this->byProduct;
    }

    /** Stocked products with their categorisation, for category-level totals. */
    public function catalog(): array
    {
        $this->load();

        return $this->catalog;
    }

    /**
     * Total stock for a CATEGORY node — every stocked product whose attributes
     * match the node's filters, whether or not it currently has open orders.
     *
     * ⚠️ Matches the node's FULL ancestor path, not just its own field: two
     *    different level-1 categories can share a level-2 label, and keying on
     *    the label alone would pull in the wrong products.
     */
    public function sumForCategory(array $catalog, array $filters): ?array
    {
        if (empty($catalog)) {
            return null;
        }

        $active = array_intersect_key($filters, array_flip(self::CATEGORY_FIELDS));
        if (empty($active)) {
            return null;
        }

        $matchedIds = [];
        foreach ($catalog as $entry) {
            $ok = true;
            foreach ($active as $field => $wanted) {
                $have = $entry[$field] ?? null;
                $isBlank = ($have === null || trim((string) $have) === '');
                // 'Uncategorized' is how the tree labels a NULL/empty attribute.
                if ((string) $wanted === 'Uncategorized') {
                    if (!$isBlank) { $ok = false; break; }
                } elseif ($isBlank || (string) $have !== (string) $wanted) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $matchedIds[] = $entry['id'];
            }
        }

        return $this->sumFor($this->map(), $matchedIds);
    }

    /**
     * Total stock across a set of DISTINCT internal product ids.
     * Returns null when there is nothing stored, so callers can omit the key
     * entirely and keep the tree payload lean.
     *
     * @param array<int,mixed> $intProductIds ids as keys OR values — both accepted
     */
    public function sumFor(array $map, array $intProductIds): ?array
    {
        if (empty($map) || empty($intProductIds)) {
            return null;
        }

        // Accept either [id => true] sets or plain [id, id] lists.
        $ids = array_is_list($intProductIds) ? $intProductIds : array_keys($intProductIds);

        $out = [
            'chiller' => ['packets' => 0, 'kg' => 0.0, 'pcs' => 0.0],
            'freezer' => ['packets' => 0, 'kg' => 0.0, 'pcs' => 0.0],
        ];
        $any = false;

        foreach ($ids as $pid) {
            $pid = (int) $pid;
            if (!isset($map[$pid])) {
                continue;
            }
            foreach (self::SECTIONS as $section) {
                $src = $map[$pid][$section];
                if ($src['packets'] > 0) {
                    $any = true;
                }
                $out[$section]['packets'] += $src['packets'];
                $out[$section]['kg'] += $src['kg'];
                $out[$section]['pcs'] += $src['pcs'];
            }
        }

        if (!$any) {
            return null;
        }

        foreach (self::SECTIONS as $section) {
            $out[$section]['kg'] = round($out[$section]['kg'], 3);
            $out[$section]['pcs'] = round($out[$section]['pcs'], 3);
        }

        return $out;
    }

    /** Parse a GROUP_CONCAT(DISTINCT p.id) string into a clean id list. */
    public function idsFromConcat(?string $concat): array
    {
        if ($concat === null || $concat === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', $concat)),
            fn ($id) => $id > 0
        )));
    }
}
