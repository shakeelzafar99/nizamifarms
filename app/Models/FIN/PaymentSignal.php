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
    // A credit alert SMS read from a manager's phone by the NF Messages app.
    // Counts as a BANK-side confirmation, exactly like a bank email: it can
    // pair with a WhatsApp screenshot to make a proof "verified".
    public const SOURCE_BANK_SMS = 'bank_sms';

    /** The sources that are the bank's own word (vs the customer's claim). */
    public const BANK_SIDE_SOURCES = [self::SOURCE_EMAIL, self::SOURCE_BANK_SMS];

    public const STATUS_NEW             = 'new';
    public const STATUS_MATCHED         = 'matched';
    public const STATUS_UNMATCHED       = 'unmatched';
    public const STATUS_AMOUNT_MISMATCH = 'amount_mismatch';
    public const STATUS_DUPLICATE       = 'duplicate';
    public const STATUS_IRRELEVANT      = 'irrelevant';

    /**
     * ⭐ THE GUESS REASONS — a match the system INFERRED about an unidentified
     * payer, never evidence the payer themselves supplied. Every one of these:
     *   • shows blue "Bank confirmed" / amber, NEVER green "Verified";
     *   • may be overruled by any stronger evidence (a screenshot pairing, the
     *     approver re-tagging, an Ignore, or displacement by a real proof);
     *   • never teaches a permanent alias on its own.
     *
     * ⚠⚠ ADDING A REASON HERE IS NOT OPTIONAL PLUMBING — the retract/override
     * sites all key off this list (PaymentSignalMatcher::retractAmountGuess +
     * displaceGuessesOn, AssistantSmsController::match/ignore,
     * AssistantWorkspaceController::proofBackedMatch). A guess reason missing
     * from this list is a guess that survives being disproven.
     */
    public const REASON_AMOUNT_UNIQUE = 'amount_unique_sms';  // lone queue invoice at this amount
    public const REASON_NAME_AMOUNT   = 'name_amount_sms';    // payer name resolved -> their order
    public const REASON_NAME_AI       = 'name_ai_sms';        // Gemini picked from a shortlist

    public const GUESS_REASONS = [
        self::REASON_AMOUNT_UNIQUE,
        self::REASON_NAME_AMOUNT,
        self::REASON_NAME_AI,
    ];

    /** A human said "this is the one" — outranks every guess, never auto-retracted. */
    public const REASON_MANUAL_CONFIRMED = 'manual_confirmed';

    /** A human rejected an INFERRED match. */
    public const REASON_GUESS_DISMISSED = 'guess_dismissed';

    /**
     * A human detached a match backed by real evidence — including a
     * screenshot⇄bank VERIFIED pair. The pair itself is not in doubt (both
     * sources describe one true transfer); what was wrong is the ORDER it was
     * attached to. Being verified proves the payment HAPPENED, never whose
     * invoice it settles, so this must remain removable.
     */
    public const REASON_PROOF_DETACHED = 'proof_detached';

    /**
     * ⚠⚠ A HUMAN HAS RULED — automation must never re-attach these. Every
     * re-matching entry point (HeldCreditResweeper, PaymentSignalMatcher::
     * rematch, PaymentSignalReconciler by way of rematch) checks this list.
     * Miss one and the correction is silently undone on the next page load,
     * which reads to the user as the software arguing with them.
     */
    public const TERMINAL_REASONS = [
        self::REASON_GUESS_DISMISSED,
        self::REASON_PROOF_DETACHED,
        'combined_dismissed',
    ];

    /** Is this signal's match an inferred guess rather than payer-supplied evidence? */
    public function isGuess(): bool
    {
        return in_array((string) $this->match_reason, self::GUESS_REASONS, true);
    }

    /**
     * ⭐ THE MOVEMENT LOG (owner ask, Aug-2026): "so I know what payments
     * changed hands." Self-healing is only trustworthy when it is visible —
     * every attach, re-point and release of a signal's order/customer is
     * recorded in t_fin_payment_signal_moves, with the reason before and after.
     *
     * A model hook rather than edits at every call site ON PURPOSE: the moves
     * happen in a dozen places (pairing retract, displacement, resweep, G1
     * re-validation, manual re-points, ignore, unmark…) and every future one is
     * covered automatically, so the log can never silently fall out of date.
     *
     * ⚠⚠ MUST NO-OP UNTIL THE TABLE EXISTS. Code reaches prod before the owner
     * runs the SQL (deploys are manual), and a throwing hook here would take
     * down EVERY signal save — matching, approvals, the assistant. Existence is
     * checked once per request and any failure is swallowed: the log is a
     * nicety, the save is money.
     */
    protected static function booted(): void
    {
        static::updated(function (PaymentSignal $s) {
            try {
                if (!$s->wasChanged('matched_order_id') && !$s->wasChanged('matched_customer_id')) {
                    return;
                }
                static $tableOk = null;
                if ($tableOk === null) {
                    $tableOk = \Illuminate\Support\Facades\Schema::hasTable('t_fin_payment_signal_moves');
                }
                if (!$tableOk) {
                    return;
                }
                \Illuminate\Support\Facades\DB::table('t_fin_payment_signal_moves')->insert([
                    'signal_id'        => $s->id,
                    'source'           => $s->source,
                    'amount'           => $s->extracted_amount,
                    'payer_name'       => $s->extracted_sender_name,
                    'from_customer_id' => $s->getOriginal('matched_customer_id'),
                    'from_order_id'    => $s->getOriginal('matched_order_id'),
                    'to_customer_id'   => $s->matched_customer_id,
                    'to_order_id'      => $s->matched_order_id,
                    'from_reason'      => $s->getOriginal('match_reason'),
                    'to_reason'        => $s->match_reason,
                    'moved_by'         => auth()->id(),
                    'created_at'       => now(),
                ]);
            } catch (\Throwable $e) {
                // Never let bookkeeping break a money-path save.
            }
        });
    }

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
        'extracted_to_account_last4',
        'extracted_txn_datetime',
        'extraction_confidence',
        'extraction_attempts',
        'extraction_raw_text',
        'extractor_version',
        // ⚠ Must stay in $fillable: a manual entry sets this and mass-assignment
        // would otherwise DROP it silently, leaving the row with no author —
        // which is the whole point of the column.
        'created_by',
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
