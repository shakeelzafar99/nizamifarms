<?php

namespace App\Services\CRM;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ONE authority for Open-Order-Quantities display ordering.
 *
 * Preferences are PER USER (t_crm_open_quantities_user_sort, one row per user,
 * JSON keyed by hierarchy FIELD name — never by level index, so a hierarchy
 * change can't misapply an old preference).
 *
 *   {"attribute_1": {"mode":"custom","sequence":["Chicken","Beef"]},
 *    "attribute_2": {"mode":"alpha"}}
 *
 * Modes: qty_desc (default, never stored) | alpha | custom.
 *
 * Two consumers, one rule set:
 *   - MOBILE reads/writes the prefs via RiderController and sorts on the phone
 *     (src/utils/quantitiesSort.js) so the cached tree needs no server round-trip.
 *   - WEB sorts here in PHP inside OrderController::openQuantitiesData.
 * ⚠️ compare() below MUST stay behaviourally identical to quantitiesSort.js —
 *    same normalisation, same Uncategorized-last rule, same tie-breaks — or the
 *    same user sees two different orders on the two screens.
 */
class QuantitiesSortService
{
    /** Hierarchy fields that may carry a preference. 'orders' is intentionally absent. */
    public const FIELDS = ['product_type', 'attribute_1', 'attribute_2', 'attribute_3', 'product_name'];

    public const MODES = ['qty_desc', 'alpha', 'custom'];

    private const UNCATEGORIZED = 'uncategorized';

    /** Per-request memo so the 5s web poller doesn't re-query for one page render. */
    private array $memo = [];

    /**
     * Read a user's preferences. NEVER throws: if the table is missing (SQL not
     * run on prod yet) or anything else fails, callers fall back to the default
     * order rather than 500-ing the quantities screen.
     */
    public function getPrefs(?int $userId): array
    {
        if (!$userId) {
            return [];
        }
        if (array_key_exists($userId, $this->memo)) {
            return $this->memo[$userId];
        }

        $prefs = [];
        try {
            $raw = DB::table('t_crm_open_quantities_user_sort')
                ->where('user_id', $userId)
                ->value('prefs');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $prefs = $decoded;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Quantities sort prefs unavailable, using default order', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $prefs = [];
        }

        return $this->memo[$userId] = $prefs;
    }

    /**
     * Sanitize an incoming preferences payload: unknown fields and modes are
     * dropped, sequences trimmed/de-duplicated/capped, and a field left on the
     * qty_desc default is simply not stored.
     */
    public function sanitize(array $raw): array
    {
        $clean = [];

        foreach (self::FIELDS as $field) {
            $entry = $raw[$field] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $mode = in_array($entry['mode'] ?? '', self::MODES, true) ? $entry['mode'] : 'qty_desc';
            if ($mode === 'qty_desc') {
                continue; // default — nothing to store
            }

            $out = ['mode' => $mode];

            if ($mode === 'custom') {
                $seq = is_array($entry['sequence'] ?? null) ? $entry['sequence'] : [];
                $seq = array_values(array_unique(array_filter(
                    array_map(
                        fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 150) : '',
                        $seq
                    ),
                    fn ($v) => $v !== ''
                )));
                if (count($seq) > 300) {
                    $seq = array_slice($seq, 0, 300);
                }
                if (empty($seq)) {
                    continue; // custom with no sequence is meaningless
                }
                $out['sequence'] = $seq;
            }

            $clean[$field] = $out;
        }

        return $clean;
    }

    /** Persist sanitized preferences for a user and return what was stored. */
    public function savePrefs(int $userId, array $raw): array
    {
        $clean = $this->sanitize($raw);

        DB::table('t_crm_open_quantities_user_sort')->updateOrInsert(
            ['user_id' => $userId],
            ['prefs' => json_encode($clean), 'updated_at' => now()]
        );

        $this->memo[$userId] = $clean;

        return $clean;
    }

    /** Does this user have a non-default order for this level's field? */
    public function modeFor(array $prefs, ?string $field): ?string
    {
        if (!$field || $field === 'orders') {
            return null;
        }

        return $prefs[$field]['mode'] ?? null;
    }

    /**
     * Sort rows for one level. Accessors keep this usable from any shape of row
     * (web returns stdClass with group_name/total_quantity).
     *
     * Returns a NEW array — the input is never reordered in place.
     */
    public function sortRows(array $rows, ?string $field, array $prefs, callable $nameOf, callable $qtyOf): array
    {
        if (count($rows) <= 1 || !$field || $field === 'orders') {
            return $rows;
        }

        $mode = $prefs[$field]['mode'] ?? 'qty_desc';
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'qty_desc';
        }

        // A 'custom' preference with no usable sequence degrades to qty_desc —
        // NOT to "leave the input order". The sanitizer never stores that state,
        // but a hand-edited row could produce it, and the mobile comparator
        // degrades the same way; parity here is what keeps the two screens equal.
        $seqIndex = null;
        if ($mode === 'custom') {
            $sequence = $prefs[$field]['sequence'] ?? null;
            if (is_array($sequence) && !empty($sequence)) {
                $built = [];
                foreach (array_values($sequence) as $i => $name) {
                    $key = $this->normalizeName((string) $name);
                    if ($key !== '' && !array_key_exists($key, $built)) {
                        $built[$key] = $i;
                    }
                }
                $seqIndex = empty($built) ? null : $built;
            }
        }

        // Precompute keys once (avoids re-normalising inside every comparison).
        $decorated = [];
        foreach ($rows as $row) {
            $key = $this->normalizeName((string) $nameOf($row));
            $decorated[] = [
                'row' => $row,
                'key' => $key,
                'qty' => (float) $qtyOf($row),
                'uncat' => $key === self::UNCATEGORIZED,
                'seq' => $seqIndex === null ? 0 : ($seqIndex[$key] ?? PHP_INT_MAX),
            ];
        }

        usort($decorated, function ($a, $b) use ($mode, $seqIndex) {
            // Uncategorized always sinks, whatever the mode or quantity.
            if ($a['uncat'] !== $b['uncat']) {
                return $a['uncat'] ? 1 : -1;
            }

            if ($seqIndex !== null && $a['seq'] !== $b['seq']) {
                return $a['seq'] <=> $b['seq'];
            }

            if ($seqIndex === null && $mode === 'alpha') {
                $cmp = strcmp($a['key'], $b['key']);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            // qty desc, then name asc — a deterministic tie-break so equal rows
            // never shuffle between refreshes.
            if ($a['qty'] !== $b['qty']) {
                return $b['qty'] <=> $a['qty'];
            }

            return strcmp($a['key'], $b['key']);
        });

        return array_column($decorated, 'row');
    }

    /**
     * Normalisation shared with the mobile comparator: decode the same small set
     * of HTML entities (Shopify titles store "&amp;"), trim, lowercase.
     * Deliberately NOT html_entity_decode() — that decodes far more than the JS
     * does, which would make the two screens order differently.
     */
    public function normalizeName(string $value): string
    {
        $decoded = str_ireplace(
            ['&amp;', '&quot;', '&#39;', '&lt;', '&gt;'],
            ['&', '"', "'", '<', '>'],
            $value
        );

        return mb_strtolower(trim($decoded));
    }
}
