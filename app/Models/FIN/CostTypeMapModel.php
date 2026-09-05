<?php

namespace App\Models\FIN;

use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a money SOURCE to one of three cost types, per business unit.
 *
 * Money reaches the Frozen unit in four shapes (vendor purchase, expense,
 * salary, asset purchase) so the map is keyed by (source_kind, source_key)
 * rather than living as a column on t_fin_vendors — a vendor column could
 * only ever classify one of the four.
 *
 * ⭐ Resolution happens at READ TIME and is never stamped on a ledger row,
 * so re-tagging a vendor re-files its entire history instantly. Same
 * principle as the Category Report's Level-1 tag.
 *
 * ⚠ Anything with no row resolves to TYPE_UNCLASSIFIED, which the screen
 * SHOWS in its own bucket. A money report must never silently shed rows.
 */
class CostTypeMapModel extends BaseModel
{
    protected $table = 't_fin_cost_type_map';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /** Goes into a pack: meat, ingredients, packaging, cooking fuel. */
    public const TYPE_PRODUCT = 'product';
    /** Runs the place every month whether or not a pack is made. */
    public const TYPE_FIXED = 'fixed';
    /** Builds or buys something that lasts: construction, equipment. */
    public const TYPE_ONE_TIME = 'one_time';
    /** No row in the map. Never hidden — shown so the columns add up. */
    public const TYPE_UNCLASSIFIED = 'unclassified';

    public const KIND_VENDOR = 'vendor';
    public const KIND_EXPENSE_CATEGORY = 'expense_category';
    public const KIND_SALARY = 'salary';
    public const KIND_ASSET_PURCHASE = 'asset_purchase';

    /** The catch-all key for a whole kind (e.g. every salary). */
    public const KEY_ALL = '*';

    protected $fillable = [
        'business_unit_id',
        'source_kind',
        'source_key',
        'cost_type',
        'updated_by',
    ];

    public static function types(): array
    {
        return [self::TYPE_PRODUCT, self::TYPE_FIXED, self::TYPE_ONE_TIME];
    }

    public static function kinds(): array
    {
        return [self::KIND_VENDOR, self::KIND_EXPENSE_CATEGORY, self::KIND_SALARY, self::KIND_ASSET_PURCHASE];
    }

    public static function label(string $type): string
    {
        return [
            self::TYPE_PRODUCT      => 'Product cost',
            self::TYPE_FIXED        => 'Fixed cost',
            self::TYPE_ONE_TIME     => 'One-time cost',
            self::TYPE_UNCLASSIFIED => 'Not classified yet',
        ][$type] ?? $type;
    }

    /**
     * The table ships behind a migration that is applied by hand, so every
     * read is guarded — the screen degrades to "everything unclassified"
     * rather than 500ing if the file has not been run yet.
     */
    public static function available(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Schema::hasTable('t_fin_cost_type_map');
            } catch (\Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /**
     * Whole map for a unit as [source_kind => [source_key => cost_type]].
     * Small table (tens of rows) — loaded once per request, never joined
     * into the money queries, which keeps those queries untouched.
     */
    public static function mapFor(int $businessUnitId): array
    {
        if (!self::available()) {
            return [];
        }

        $out = [];
        foreach (self::where('business_unit_id', $businessUnitId)->get() as $row) {
            $out[$row->source_kind][(string) $row->source_key] = $row->cost_type;
        }
        return $out;
    }

    /**
     * Resolve one source. Precedence: exact key, then the kind's '*'
     * catch-all, then unclassified.
     */
    public static function resolve(array $map, string $kind, $key): string
    {
        $key = (string) $key;

        if ($key !== '' && isset($map[$kind][$key])) {
            return $map[$kind][$key];
        }
        if (isset($map[$kind][self::KEY_ALL])) {
            return $map[$kind][self::KEY_ALL];
        }
        return self::TYPE_UNCLASSIFIED;
    }
}
