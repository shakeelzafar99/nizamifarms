<?php

namespace App\Services;

/**
 * QurbaniBundleService — "smart box" calculation for Qurbani line items.
 *
 * Concept (see QURBANI_SMART_BOX_PLAN_MAY2026.md for the full design):
 *   A "bundle" is the set of physical packets one rider carries to one
 *   customer on one trip. Items share a bundle iff they have the same
 *   (order_id, qurbani_day, qurbani_slot, qurbani_delivery_type).
 *
 *   Bundle size N = SUM(quantity) over all items sharing the bundle key.
 *   Each line item gets a (start, end) position pair within its bundle so
 *   the mobile row card can show "3/5" (end position over total) and
 *   printed packet stickers can read "Packet 2 of 5".
 *
 * Stable ordering (so positions don't shuffle on reload):
 *   1. category_priority (Hissa < Bakra < Dumba < Other)
 *   2. product_id
 *   3. line_item_id
 *
 * Returns: indexed by line_item_id with
 *   {
 *     bundle_key: 'order_id|day|slot|delivery_type',
 *     bundle_size: int,
 *     bundle_position_start: int,
 *     bundle_position_end: int,
 *     bundle_item_count: int,   // distinct LIs in this bundle
 *   }
 *
 * No DB writes — pure function. Callers should pass an iterable of
 * line-item arrays/objects; the helper is tolerant of either.
 */
class QurbaniBundleService
{
    /**
     * Category priority for sorting within a bundle. Lower = earlier
     * position. Anything not matched falls back to 99 (last). Comparison
     * is case-insensitive substring on category_level_2.
     */
    private const CATEGORY_PRIORITY = [
        'hissa' => 1,
        'bakra' => 2,
        'dumba' => 3,
    ];

    /**
     * Compute bundles across an iterable of line items. Each line item
     * must expose: id, order_id, quantity, qurbani_day, qurbani_slot,
     * qurbani_delivery_type. Optional: category_level_2, product_id.
     *
     * Accepts arrays, stdClass, Eloquent models — anything with array or
     * property access.
     *
     * @param  iterable  $lineItems
     * @return array<int, array>  keyed by line item id
     */
    public function computeBundles(iterable $lineItems): array
    {
        // Normalise + group by bundle key in one pass so we don't have to
        // re-walk the collection.
        $grouped = [];
        foreach ($lineItems as $li) {
            $row = $this->normalize($li);
            if ($row === null) continue;
            $grouped[$row['bundle_key']][] = $row;
        }

        $byLineItem = [];
        foreach ($grouped as $bundleKey => $items) {
            usort($items, [$this, 'sortWithinBundle']);

            // SUM(quantity) over the whole bundle. Cast to int because
            // packet labels are integers; fractional qty would be a data
            // error (you can't deliver half a goat).
            $bundleSize = 0;
            foreach ($items as $row) {
                $bundleSize += max(1, (int) $row['quantity']);
            }

            $cursor = 1;
            foreach ($items as $row) {
                $qty = max(1, (int) $row['quantity']);
                $start = $cursor;
                $end = $cursor + $qty - 1;
                $cursor = $end + 1;

                $byLineItem[$row['id']] = [
                    'bundle_key'             => $bundleKey,
                    'bundle_size'            => $bundleSize,
                    'bundle_position_start'  => $start,
                    'bundle_position_end'    => $end,
                    'bundle_item_count'      => count($items),
                ];
            }
        }

        return $byLineItem;
    }

    /**
     * Build a single line-item's normalized record. Returns null if the
     * row can't be processed (e.g. missing id) so the caller is robust to
     * partial data.
     */
    private function normalize($li): ?array
    {
        $id          = $this->get($li, 'id');
        $orderId     = $this->get($li, 'order_id');
        $quantity    = $this->get($li, 'quantity');
        $day         = $this->get($li, 'qurbani_day');
        $slot        = $this->get($li, 'qurbani_slot');
        $deliveryT   = $this->get($li, 'qurbani_delivery_type');
        $category    = $this->get($li, 'category_level_2');
        $productId   = $this->get($li, 'product_id');

        if ($id === null || $orderId === null) return null;

        // Bundle key uses literal '__' for unset fields so a NULL day
        // doesn't collide with a future literal value. Items missing
        // day/slot still group together (so the rider sees them as a
        // pending-metadata bundle in the soft-warn).
        $bundleKey = sprintf(
            '%s|%s|%s|%s',
            (string) $orderId,
            $day !== null && $day !== '' ? (string) $day : '__',
            $slot !== null && $slot !== '' ? (string) $slot : '__',
            $deliveryT !== null && $deliveryT !== '' ? (string) $deliveryT : '__'
        );

        return [
            'id'                => (int) $id,
            'order_id'          => (int) $orderId,
            'quantity'          => $quantity,
            'category_priority' => $this->categoryPriority($category),
            'product_id'        => $productId !== null ? (int) $productId : 0,
            'bundle_key'        => $bundleKey,
        ];
    }

    private function get($li, string $key)
    {
        if (is_array($li)) {
            return $li[$key] ?? null;
        }
        if (is_object($li)) {
            // Eloquent models support both property and array access via
            // ArrayAccess; this covers stdClass + models cleanly.
            try {
                return $li->{$key} ?? null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    private function categoryPriority(?string $category): int
    {
        if ($category === null || $category === '') return 99;
        $lower = strtolower($category);
        foreach (self::CATEGORY_PRIORITY as $needle => $rank) {
            if (str_contains($lower, $needle)) return $rank;
        }
        return 99;
    }

    /**
     * usort comparator. Stable ordering rule:
     *   1. category_priority ASC
     *   2. product_id ASC
     *   3. line_item_id ASC
     */
    private function sortWithinBundle(array $a, array $b): int
    {
        if ($a['category_priority'] !== $b['category_priority']) {
            return $a['category_priority'] <=> $b['category_priority'];
        }
        if ($a['product_id'] !== $b['product_id']) {
            return $a['product_id'] <=> $b['product_id'];
        }
        return $a['id'] <=> $b['id'];
    }
}
