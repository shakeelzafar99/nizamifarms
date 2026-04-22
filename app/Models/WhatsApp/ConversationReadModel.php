<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user read state for WhatsApp conversations.
 *
 * Each row = "user X has read conversation Y up to $last_read_at".
 * Any inbound message whose created_at > last_read_at is considered
 * unread for that user.
 *
 * We ALSO keep the legacy `unread_count` column on `t_wa_conversations`
 * for backward compatibility (push notifications, badge-sum API), but
 * per-user unread is the authoritative source now.
 */
class ConversationReadModel extends Model
{
    protected $table = 't_wa_conversation_reads';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_at',
        'forced_unread_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'user_id' => 'integer',
        'last_read_at' => 'datetime',
        'forced_unread_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(ConversationModel::class, 'conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
