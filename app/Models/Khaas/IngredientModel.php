<?php

namespace App\Models\Khaas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing that goes into a frozen product: chicken thigh, canola oil, salt, cheese.
 *
 * ⭐ THE UNIT RULE. `base_unit` (g | ml | pcs) is the ONLY unit any quantity is ever
 * stored in — recipe lines, purchase lines and consumption rows all speak it. A form
 * converts on the way in and `display()` converts on the way out, so a number is never
 * converted twice and a kg never silently becomes a litre.
 *
 * `storage_product_id` is set for MEAT only. Meat is bought and consumed through the
 * real storage ledger (t_crm_khaas_storage_log) which is already exact, so for those
 * ingredients the recipe line is a STANDARD to compare against, never a second
 * deduction. Nothing in this feature writes to storage.
 *
 * ⚠ t_crm_khaas_storage_inventory carries MORE THAN ONE row per source_product_id (one
 *   per variant), so every reader of storage must SUM across them.
 */
class IngredientModel extends Model
{
    protected $table = 't_crm_khaas_ingredient';
    protected $primaryKey = 'id';
    public $timestamps = true;

    public const UNIT_G   = 'g';
    public const UNIT_ML  = 'ml';
    public const UNIT_PCS = 'pcs';

    public const KIND_MEAT      = 'meat';
    public const KIND_VEGETABLE = 'vegetable';
    public const KIND_DAIRY     = 'dairy';
    public const KIND_DRY       = 'dry';
    public const KIND_PACKAGING = 'packaging';
    public const KIND_OTHER     = 'other';

    public const BASE_UNITS = [self::UNIT_G, self::UNIT_ML, self::UNIT_PCS];

    public const KINDS = [
        self::KIND_MEAT, self::KIND_VEGETABLE, self::KIND_DAIRY,
        self::KIND_DRY, self::KIND_PACKAGING, self::KIND_OTHER,
    ];

    /** Human labels, used on both surfaces so the wording cannot drift. */
    public const KIND_LABELS = [
        self::KIND_MEAT      => 'Meat',
        self::KIND_VEGETABLE => 'Vegetables',
        self::KIND_DAIRY     => 'Dairy',
        self::KIND_DRY       => 'Dry goods',
        self::KIND_PACKAGING => 'Packaging',
        self::KIND_OTHER     => 'Other',
    ];

    /** Which display units a base unit may legally be shown in. */
    public const DISPLAY_UNITS = [
        self::UNIT_G   => ['g', 'kg'],
        self::UNIT_ML  => ['ml', 'L'],
        self::UNIT_PCS => ['pcs'],
    ];

    /** How many base units one display unit is worth. */
    public const DISPLAY_FACTOR = [
        'g' => 1.0, 'kg' => 1000.0,
        'ml' => 1.0, 'L' => 1000.0,
        'pcs' => 1.0,
    ];

    protected $fillable = [
        'business_unit_id',
        'name',
        'name_urdu',
        'base_unit',
        'display_unit',
        'kind',
        'storage_product_id',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'business_unit_id'   => 'integer',
        'storage_product_id' => 'integer',
        'is_active'          => 'boolean',
        'created_by'         => 'integer',
    ];

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLineModel::class, 'ingredient_id');
    }

    public function isMeat(): bool
    {
        return $this->storage_product_id !== null;
    }

    /**
     * Turn a base-unit quantity into what a person should read.
     *
     * Grams and millilitres step up to kg / L at 1000, because "1200 g of onions" is
     * how a spreadsheet talks and "1.2 kg" is how Qasim talks. The step is on the
     * VALUE, not on display_unit, so a 90 g salt line stays in grams on the same
     * screen where a 9 kg chicken line is in kilos.
     *
     * @return array{qty: float, unit: string, text: string}
     */
    public function display(float $qtyBase): array
    {
        $unit = $this->base_unit;
        $qty  = $qtyBase;

        if ($unit === self::UNIT_G && abs($qtyBase) >= 1000) {
            $qty  = $qtyBase / 1000;
            $unit = 'kg';
        } elseif ($unit === self::UNIT_ML && abs($qtyBase) >= 1000) {
            $qty  = $qtyBase / 1000;
            $unit = 'L';
        }

        // Three decimals is the storage precision; trailing zeros just add noise.
        $rounded = round($qty, 3);
        $text    = rtrim(rtrim(number_format($rounded, 3, '.', ','), '0'), '.') . ' ' . $unit;

        return ['qty' => $rounded, 'unit' => $unit, 'text' => $text];
    }

    /** Shorthand for the text half of display(). */
    public function phrase(float $qtyBase): string
    {
        return $this->display($qtyBase)['text'];
    }

    /** Convert a number typed in $unit into base units. Unknown unit = already base. */
    public static function toBase(float $qty, string $unit): float
    {
        return round($qty * (self::DISPLAY_FACTOR[$unit] ?? 1.0), 3);
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? 'Other';
    }

    /**
     * The shape both surfaces read. One place, so web and phone cannot disagree.
     */
    public function shape(): array
    {
        return [
            'id'                 => (int) $this->id,
            'name'               => $this->name,
            'name_urdu'          => $this->name_urdu,
            'base_unit'          => $this->base_unit,
            'display_unit'       => $this->display_unit,
            'display_units'      => self::DISPLAY_UNITS[$this->base_unit] ?? [$this->base_unit],
            'kind'               => $this->kind,
            'kind_label'         => $this->kindLabel(),
            'is_meat'            => $this->isMeat(),
            'storage_product_id' => $this->storage_product_id ? (int) $this->storage_product_id : null,
            'is_active'          => (bool) $this->is_active,
        ];
    }
}
