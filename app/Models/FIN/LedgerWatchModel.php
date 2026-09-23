<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's "I have seen up to here" watermark for the cash pill (Sep-2026).
 *
 * Deliberately a watermark and not a per-row read flag: the pill answers one question —
 * "has anything happened on my tills since I last looked?" — and a single id answers it
 * in one indexed comparison, however many rows arrive.
 *
 * ⚠ No timestamps pair: the table has `last_seen_at` only (there is no created_at), so
 * Eloquent's automatic timestamps are off.
 */
class LedgerWatchModel extends Model
{
    protected $table = 't_fin_ledger_watch';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'last_seen_ledger_id',
        'last_seen_at',
    ];

    protected $casts = [
        'user_id'             => 'integer',
        'last_seen_ledger_id' => 'integer',
        'last_seen_at'        => 'datetime',
    ];
}
