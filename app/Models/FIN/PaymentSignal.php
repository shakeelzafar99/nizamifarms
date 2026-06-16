<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;
use App\Models\CRM\OrderModel;
use App\Models\WhatsApp\MessageModel;

/**
 * A read-only "payment signal" — one fact extracted from either a customer
 * WhatsApp bank screenshot (via Gemini Vision) or a bank confirmation email
 * (via IMAP + regex). The matcher later ties it to an order.
 *
 * This model NEVER touches money. It is a decoration/cache layer only.
 *
 * @see database/migrations/create_payment_signal_and_alias_jun2026.sql
 */
class PaymentSignal extends Model
{
    protected $table = 't_fin_payment_signal';
    protected $primaryKey = 'id';
    public $timestamps = true;

    public const SOURCE_WHATSAPP = 'whatsapp';
    public const SOURCE_EMAIL    = 'email';

    public const STATUS_NEW             = 'new';
    public const STATUS_MATCHED         = 'matched';
    public const STATUS_UNMATCHED       = 'unmatched';
    public const STATUS_AMOUNT_MISMATCH = 'amount_mismatch';
    public const STATUS_DUPLICATE       = 'duplicate';
    public const STATUS_IRRELEVANT      = 'irrelevant';

    protected $fillable = [
        'source',
        'wa_message_id',
        'wa_conversation_id',
        'image_path',
        'email_uid',
        'email_folder',
        'email_from',
        'email_subject',
        'email_received_at',
        'extracted_amount',
        'extracted_ref',
        'extracted_sender_name',
        'extracted_sender_account_masked',
        'extracted_sender_bank',
        'extracted_to_account_short',
        'extracted_txn_datetime',
        'extraction_confidence',
        'extraction_raw_text',
        'extractor_version',
        'status',
        'matched_order_id',
        'matched_customer_id',
        'match_confidence',
        'match_reason',
        'paired_signal_id',
    ];

    protected $casts = [
        'email_received_at'      => 'datetime',
        'extracted_amount'       => 'decimal:2',
        'extracted_txn_datetime' => 'datetime',
        'extraction_confidence'  => 'decimal:2',
        'match_confidence'       => 'decimal:2',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(OrderModel::class, 'matched_order_id');
    }

    public function waMessage()
    {
        return $this->belongsTo(MessageModel::class, 'wa_message_id');
    }

    public function pairedSignal()
    {
        return $this->belongsTo(self::class, 'paired_signal_id');
    }

    public function scopeForOrder($query, $orderId)
    {
        return $query->where('matched_order_id', $orderId);
    }

    public function scopeWhatsapp($query)
    {
        return $query->where('source', self::SOURCE_WHATSAPP);
    }

    public function scopeEmail($query)
    {
        return $query->where('source', self::SOURCE_EMAIL);
    }

    /** A public URL for the WhatsApp image, reusing the app's public-storage route. */
    public function getImagePublicUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }
        return url('public-storage/' . ltrim($this->image_path, '/'));
    }

    /** Whether this signal points at a real order (matched or amount-mismatch). */
    public function isLinked(): bool
    {
        return $this->matched_order_id
            && in_array($this->status, [self::STATUS_MATCHED, self::STATUS_AMOUNT_MISMATCH], true);
    }
}
