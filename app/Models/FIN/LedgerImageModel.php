<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * One attached bill/receipt image of a ledger row (vendor purchases & payments).
 *
 * ⭐⭐ THE CONTRACT (see vendor_multi_bill_images_aug2026.sql): this table is the
 * truth for a row's images, and `t_fin_ledger.bill_image` stays as a MIRROR of
 * the first image (lowest sort_order) — the old APK, the old ledger page, the
 * Assistant draft flow and the report 📎 all still read that column. Use
 * syncMirror() after any insert/delete here so the two can never disagree.
 */
class LedgerImageModel extends Model
{
    protected $table = 't_fin_ledger_images';

    protected $fillable = [
        'ledger_id',
        'image_path',
        'sort_order',
        'created_by',
    ];

    /** True when the multi-image table exists (SQL applied). Cached per request. */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = Schema::hasTable('t_fin_ledger_images');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    public function ledger()
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_id');
    }

    /** All images of one ledger row, in display order. */
    public static function forLedger(int $ledgerId)
    {
        return static::where('ledger_id', $ledgerId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Re-point the ledger row's legacy `bill_image` column at the FIRST image
     * (or NULL when none remain). Call after every insert/delete. Deliberately
     * a raw update — no model events, no timestamps churn on the ledger row.
     */
    public static function syncMirror(int $ledgerId): void
    {
        $first = static::where('ledger_id', $ledgerId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('image_path');

        \DB::table('t_fin_ledger')->where('id', $ledgerId)->update(['bill_image' => $first]);
    }
}
