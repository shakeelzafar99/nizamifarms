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

    /**
     * Frozen / "Khaas" business unit. Its freezer contents are NOT scanned packets —
     * they ARE the store inventory (t_crm_prod_product_variant.inventory_quantity),
     * which the transfer-accept and prepare/deduct flows already maintain.
     *
     * ⭐⭐ We DERIVE that figure here and never write an overnight row for a BU-2
     *     product. Two sources for one physical pack would double-count it, and a
     *     scanned row would go stale the moment the pack is sold (overnight touches
     *     no other inventory by design). OvernightStorageController::addItems()
     *     enforces the same rule server-side so it cannot be violated by accident.
     */
    public const FROZEN_BUSINESS_UNIT_ID = 2;

    /** Where a catalog entry's numbers come from. */
    public const SOURCE_SCAN = 'scan';
    public const SOURCE_FROZEN = 'frozen_inventory';

    private ?array $byProduct = null;
    private ?array $catalog = null;
    /** Stored packets whose product row was later deleted — countable, never categorisable. */
    private ?array $orphans = null;

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
        $this->orphans = [];

        try {
            // LEFT JOIN, not INNER: a packet whose product row was later removed
            // still counts toward its product total, it just can't be categorised.
            $rows = DB::table('t_crm_overnight_item as i')
                ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'i.product_id')
                ->where('i.status', 'stored')
                ->whereNotNull('i.product_id')
                ->selectRaw('i.product_id, i.section, i.unit, COUNT(*) as packets, SUM(i.quantity) as qty,
                             MIN(i.entered_at) as oldest_entered_at, p.title as product_title,
                             p.product_type, p.attribute_1, p.attribute_2, p.attribute_3')
                ->groupBy('i.product_id', 'i.section', 'i.unit', 'p.title',
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
                        'name' => $row->product_title,
                        'source' => self::SOURCE_SCAN,
                        'product_type' => $row->product_type,
                        'attribute_1' => $row->attribute_1,
                        'attribute_2' => $row->attribute_2,
                        'attribute_3' => $row->attribute_3,
                        'oldest_entered_at' => null,
                        'totals' => $blank(),
                    ];
                }
                $this->catalog[$pid]['totals'][$section]['packets'] += (int) $row->packets;
                $this->catalog[$pid]['totals'][$section][$isPcs ? 'pcs' : 'kg'] += (float) $row->qty;

                // Oldest packet across ALL of this product's groups — the "use this
                // first" signal. Timestamps are 'Y-m-d H:i:s', so string compare is
                // chronological; a row with no entry time can never win.
                $entered = $row->oldest_entered_at ? (string) $row->oldest_entered_at : null;
                if ($entered !== null) {
                    $current = $this->catalog[$pid]['oldest_entered_at'];
                    if ($current === null || $entered < $current) {
                        $this->catalog[$pid]['oldest_entered_at'] = $entered;
                    }
                }
            }

            $this->loadFrozenInventory($blank);
            $this->loadOrphanPackets();
        } catch (\Throwable $e) {
            Log::warning('Overnight stock unavailable — quantities will render without it', [
                'error' => $e->getMessage(),
            ]);
            $this->byProduct = [];
            $this->catalog = [];
            $this->orphans = [];
        }
    }

    /**
     * Frozen (BU-2) products: their freezer contents ARE the live store inventory.
     *
     * ⭐ DERIVED, never stored. The transfer-accept (+) and prepare/deduct (−) flows
     *    already maintain variant.inventory_quantity, so reading it here means the
     *    freezer figure and the inventory figure are the SAME NUMBER by construction —
     *    they cannot drift, and there is nothing to backfill.
     *
     * ⚠️ Disjoint from the scan source by construction: scanned packets are BU-1 only
     *    (enforced in OvernightStorageController::addItems), this source is BU-2 only.
     *    So no physical item can ever be counted twice.
     *
     * ⚠️ One inventory unit = one retail pack = one "packet". kg/pcs stay 0: the pack
     *    count IS the whole figure, and repeating it as "23 pkt · 23 pcs" would read
     *    like two different measurements of the same thing.
     */
    private function loadFrozenInventory(callable $blank): void
    {
        $rows = DB::table('t_crm_prod_product as p')
            ->join('t_crm_prod_product_variant as v', 'v.product_id', '=', 'p.id')
            ->where('p.business_unit_id', self::FROZEN_BUSINESS_UNIT_ID)
            ->where('p.is_active', 1)
            ->selectRaw('p.id, p.title, p.product_type, p.attribute_1, p.attribute_2, p.attribute_3,
                         SUM(v.inventory_quantity) as qty')
            ->groupBy('p.id', 'p.title', 'p.product_type', 'p.attribute_1', 'p.attribute_2', 'p.attribute_3')
            ->get();

        foreach ($rows as $row) {
            $pid = (int) $row->id;
            $qty = (int) round((float) $row->qty);

            // ⚠️⚠️ A BU-2 product should NEVER have scanned packets — addItems refuses
            //     them. If legacy or hand-inserted rows exist anyway, counting both
            //     sources would double-count the same physical pack, which is the one
            //     failure this whole design exists to prevent. The explicit packet
            //     record wins and the derived figure is skipped, flagged not hidden.
            if (isset($this->catalog[$pid])) {
                $this->catalog[$pid]['warning'] =
                    'Also has scanned overnight packets — frozen stock should be tracked by store '
                    . 'inventory only. Showing the scanned packets; the inventory figure (' . $qty
                    . ') is not added.';
                continue;
            }

            // Negative shop stock is a data fault, not stock. Surface it as a warning
            // rather than as a number — and never let it subtract from a real total.
            $warning = $qty < 0
                ? 'Store inventory reads ' . $qty . ' — needs a physical count.'
                : null;
            $packets = max(0, $qty);

            if ($packets === 0 && $warning === null) {
                continue; // nothing in the freezer for this product
            }

            if ($packets > 0) {
                if (!isset($this->byProduct[$pid])) {
                    $this->byProduct[$pid] = $blank();
                }
                $this->byProduct[$pid]['freezer']['packets'] += $packets;
            }

            $entry = [
                'id' => $pid,
                'name' => $row->title,
                'source' => self::SOURCE_FROZEN,
                'product_type' => $row->product_type,
                'attribute_1' => $row->attribute_1,
                'attribute_2' => $row->attribute_2,
                'attribute_3' => $row->attribute_3,
                'oldest_entered_at' => null, // frozen has no scan time; its history is the store log
                'totals' => $blank(),
            ];
            $entry['totals']['freezer']['packets'] = $packets;
            if ($warning !== null) {
                $entry['warning'] = $warning;
            }

            // A BU-2 product can never already be here — scanned rows are BU-1 only.
            $this->catalog[$pid] = $entry;
        }
    }

    /**
     * Stored packets whose product row was deleted (FK is ON DELETE SET NULL, so the
     * name snapshot survives). They cannot be categorised, so they never reach a node —
     * but they ARE physically in the room, so the grand total must still count them.
     * Without this the Overnight board and the Quantities total would silently disagree.
     */
    private function loadOrphanPackets(): void
    {
        $rows = DB::table('t_crm_overnight_item')
            ->where('status', 'stored')
            ->whereNull('product_id')
            ->selectRaw('product_name, section, unit, COUNT(*) as packets, SUM(quantity) as qty,
                         MIN(entered_at) as oldest_entered_at')
            ->groupBy('product_name', 'section', 'unit')
            ->get();

        foreach ($rows as $row) {
            $section = strtolower((string) $row->section) === 'chiller' ? 'chiller' : 'freezer';
            $isPcs = strtolower((string) $row->unit) === 'pcs';

            $totals = [
                'chiller' => ['packets' => 0, 'kg' => 0.0, 'pcs' => 0.0],
                'freezer' => ['packets' => 0, 'kg' => 0.0, 'pcs' => 0.0],
            ];
            $totals[$section]['packets'] = (int) $row->packets;
            $totals[$section][$isPcs ? 'pcs' : 'kg'] = (float) $row->qty;

            $this->orphans[] = [
                'id' => null,
                'name' => $row->product_name ?: 'Unknown product',
                'source' => self::SOURCE_SCAN,
                'deleted_product' => true,
                'product_type' => null,
                'attribute_1' => null,
                'attribute_2' => null,
                'attribute_3' => null,
                'oldest_entered_at' => $row->oldest_entered_at ? (string) $row->oldest_entered_at : null,
                'totals' => $totals,
            ];
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
        $matchedIds = $this->matchCategoryIds($catalog, $filters);
        if ($matchedIds === null) {
            return null;
        }

        return $this->sumFor($this->map(), $matchedIds);
    }

    /**
     * The product ids behind a category node's storage figure.
     *
     * ⭐⭐ This is the ONE matching implementation. `sumForCategory()` is built on it,
     *     and the breakdown popup is fed by it — so the list a user sees can never
     *     disagree with the number they tapped to open it. Never re-implement this
     *     match client-side; ask the server which ids it used.
     *
     * @return array<int,int>|null null when this node is not a category node at all
     */
    public function matchCategoryIds(array $catalog, array $filters): ?array
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

        return $matchedIds;
    }

    /**
     * Narrow a node's product ids to the ones that actually hold stock.
     *
     * Product/order nodes carry every product they touched, most of which have nothing
     * in the chiller. The popup must list only the ones making up the figure, so this
     * is what gets published as `storage_ids` — not the node's raw product list.
     *
     * @param array<int,mixed> $intProductIds ids as keys OR values — both accepted
     * @return array<int,int>
     */
    public function stockedIds(array $map, array $intProductIds): array
    {
        if (empty($map) || empty($intProductIds)) {
            return [];
        }

        $ids = array_is_list($intProductIds) ? $intProductIds : array_keys($intProductIds);

        $out = [];
        foreach ($ids as $pid) {
            $pid = (int) $pid;
            if (isset($map[$pid]) && !in_array($pid, $out, true)) {
                $out[] = $pid;
            }
        }

        return $out;
    }

    /**
     * Flat, payload-shaped list of everything physically in storage right now — one
     * row per product, both sources, plus deleted-product packets.
     *
     * Sent ONCE per quantities response (it is bounded by "one night's leftovers"),
     * so every popup on the page is a local lookup with no extra request. Clients
     * pair it with each row's `storage_ids`.
     */
    public function catalogRows(): array
    {
        $this->load();

        $rows = [];
        foreach ($this->catalog as $entry) {
            $rows[] = $this->toRow($entry);
        }
        foreach ($this->orphans as $entry) {
            $rows[] = $this->toRow($entry);
        }

        // Biggest first, so the popup opens on what matters.
        usort($rows, function ($a, $b) {
            $ap = $a['chiller']['packets'] + $a['freezer']['packets'];
            $bp = $b['chiller']['packets'] + $b['freezer']['packets'];

            return $bp <=> $ap ?: strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $rows;
    }

    /**
     * Everything in storage, both sections, both sources, orphans included.
     *
     * ⭐ This is the ONLY figure on the quantities screen guaranteed to equal what is
     *    physically in the room: a category with no open orders produces no row on
     *    that screen, so per-row storage can never add up to this on its own.
     */
    public function grandTotal(): array
    {
        $out = [
            'chiller' => ['packets' => 0, 'kg' => 0.0, 'pcs' => 0.0],
            'freezer' => ['packets' => 0, 'kg' => 0.0, 'pcs' => 0.0],
        ];

        foreach ($this->catalogRows() as $row) {
            foreach (self::SECTIONS as $section) {
                $out[$section]['packets'] += $row[$section]['packets'];
                $out[$section]['kg'] += $row[$section]['kg'];
                $out[$section]['pcs'] += $row[$section]['pcs'];
            }
        }

        foreach (self::SECTIONS as $section) {
            $out[$section]['kg'] = round($out[$section]['kg'], 3);
            $out[$section]['pcs'] = round($out[$section]['pcs'], 3);
        }

        return $out;
    }

    /** Catalog entry → payload row. */
    private function toRow(array $entry): array
    {
        $row = [
            'id' => $entry['id'],
            'name' => $entry['name'] ?: 'Unknown product',
            'source' => $entry['source'],
            'product_type' => $entry['product_type'],
            'attribute_1' => $entry['attribute_1'],
            'attribute_2' => $entry['attribute_2'],
            'attribute_3' => $entry['attribute_3'],
            'chiller' => [
                'packets' => $entry['totals']['chiller']['packets'],
                'kg' => round($entry['totals']['chiller']['kg'], 3),
                'pcs' => round($entry['totals']['chiller']['pcs'], 3),
            ],
            'freezer' => [
                'packets' => $entry['totals']['freezer']['packets'],
                'kg' => round($entry['totals']['freezer']['kg'], 3),
                'pcs' => round($entry['totals']['freezer']['pcs'], 3),
            ],
        ];

        if (!empty($entry['oldest_entered_at'])) {
            $row['oldest_entered_at'] = $entry['oldest_entered_at'];
            try {
                $row['age_days'] = (int) abs(
                    \Carbon\Carbon::parse($entry['oldest_entered_at'])->startOfDay()
                        ->diffInDays(\Carbon\Carbon::today())
                );
            } catch (\Throwable $e) {
                // An unparseable timestamp must not cost us the whole row.
            }
        }
        if (!empty($entry['deleted_product'])) {
            $row['deleted_product'] = true;
        }
        if (!empty($entry['warning'])) {
            $row['warning'] = $entry['warning'];
        }

        return $row;
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
