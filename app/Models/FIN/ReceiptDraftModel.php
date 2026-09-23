<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

/**
 * A photographed receipt read by the vision model, waiting for a human to confirm it.
 *
 * ⚠⚠ EXTENDS Model, NOT BaseModel. BaseModel declares `protected string $status`,
 *    which shadows this table's `status` column — the trap that cost hours on the
 *    Supplies round. Any model with a `status` column must extend Model directly.
 *
 * ⭐ The row and the photo are written BEFORE the model is called, so a model failure
 * leaves a draft with a picture and an empty card rather than a lost receipt.
 *
 * ⭐ `client_uuid` is minted when the card OPENS, not when Submit is pressed, so the
 * "could not reach the server" retry cannot book the same purchase twice — the exact
 * bug found and fixed in Supplies round 2.
 *
 * Nothing here moves money. The draft becomes a purchase only by being replayed through
 * VendorController::recordWeightedPurchase, which stays the single writer.
 */
class ReceiptDraftModel extends Model
{
    protected $table = 't_fin_receipt_draft';
    protected $primaryKey = 'id';
    public $timestamps = true;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_DISCARDED = 'discarded';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'client_uuid',
        'vendor_id',
        'image_path',
        'model',
        'raw_json',
        'parsed_json',
        'status',
        'ledger_id',
        'tokens_in',
        'tokens_out',
        'extract_count',
        'error',
        'created_by',
    ];

    protected $casts = [
        'vendor_id'     => 'integer',
        'ledger_id'     => 'integer',
        'tokens_in'     => 'integer',
        'tokens_out'    => 'integer',
        'extract_count' => 'integer',
        'created_by'    => 'integer',
    ];

    public function parsed(): array
    {
        $decoded = json_decode((string) $this->parsed_json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_FAILED], true);
    }
}
