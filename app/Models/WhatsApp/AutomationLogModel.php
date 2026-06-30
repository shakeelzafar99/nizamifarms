<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per automation send ATTEMPT (sent / failed / skipped).
 *
 * Two jobs:
 *   1. Send-once dedup — before sending, the dispatcher checks for an existing
 *      `sent` row with the same (rule_key, dedup_key). The dedup_key is a
 *      stable per-entity string (e.g. "order:SH-123"), never a raw auto-id, so
 *      it survives the Shopify-staging vs prod id collision.
 *   2. Activity tracker — powers the "how many sent / failed" view the operator
 *      sees, and the per-rule history.
 *
 * created_at is stamped explicitly by the writer; there is no updated_at.
 */
class AutomationLogModel extends Model
{
    protected $table = 't_wa_automation_log';
    protected $primaryKey = 'id';
    public $timestamps = false;

    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'rule_key',
        'trigger_event',
        'dedup_key',
        'order_id',
        'conversation_id',
        'template_name',
        'status',
        'skip_reason',
        'wa_message_id',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
