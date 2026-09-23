<?php

namespace App\Models\FIN;

use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical count of a till by the person who holds it (Sep-2026).
 *
 * Origin: on 20-Sep a Rs 150,000 vendor payment was posted against NF Cash from a phone
 * by mistake. It was a legitimate action by a permitted user — nothing to block — but the
 * balance moved and nobody could say when it had last been RIGHT. A count is that anchor.
 *
 * ⭐⭐ THE SEAL. `last_ledger_id` is MAX(t_fin_ledger.id) at the instant of the count,
 * taken inside the same transaction under a row lock on the account. Any row with a
 * higher id arrived after the keeper counted; if it is ALSO dated on or before the count
 * day, it rewrote history behind him, and the ledger page says so on the count's own line.
 * That is the whole point — "later even if a backdated amount comes we will know where
 * the balance went wrong".
 *
 * ⚠ This is a RECORD, not money. No ledger row, no approval item, no balance moves —
 * the same charter as RiderController::confirmCash, which this mirrors for company tills.
 * A mismatch is shown, never auto-corrected: what to do about it is the owner's call.
 */
class CashCountModel extends BaseModel
{
    protected $table = 't_fin_cash_count';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /** Where the count was taken. */
    public const SOURCE_CHECKOUT = 'checkout';
    public const SOURCE_HUB      = 'hub';

    protected $fillable = [
        'account_id',
        'user_id',
        'counted_amount',
        'system_balance',
        'difference',
        'last_ledger_id',
        'counted_at',
        'source',
        'attendance_id',
        'note',
    ];

    protected $casts = [
        'counted_amount' => 'decimal:2',
        'system_balance' => 'decimal:2',
        'difference'     => 'decimal:2',
        'last_ledger_id' => 'integer',
        // ⚠ DATETIME in the schema, matching t_ops_attendance.cash_confirmed_at. Kept a
        // datetime cast (not a TIMESTAMP column) so it reads back as the literal Pakistan
        // wall clock it was written in — see the replica note in the deploy SQL.
        'counted_at'     => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountModel::class, 'account_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }

    /** Did the keeper's figure agree with the books? Tolerance matches the ledger's own. */
    public function matches(): bool
    {
        return abs((float) $this->difference) < 0.005;
    }

    /** '+' = he holds MORE than the books say, '−' = he is short. */
    public function shortBy(): float
    {
        return -(float) $this->difference;
    }
}
