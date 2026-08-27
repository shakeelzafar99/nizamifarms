<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

class MessageModel extends Model
{
    protected $table = 't_wa_messages';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'wa_message_id',
        'direction',
        'type',
        'content',
        'template_name',
        'template_params',
        'media_url',
        'media_mime_type',
        'status',
        'status_updated_at',
        'sent_by',
        'error_code',
        'error_message',
        'metadata',
        'created_at',
        // Apr-2026 auto-reply: stamps the outbound row with the rule
        // that generated it. MUST be in $fillable — the cooldown lookup
        // in WhatsAppService::maybeSendAutoReply() does
        //   ->whereNotNull('auto_reply_rule_id')
        // so if Eloquent silently drops this field on persist (which it
        // will if it's not fillable), every auto-reply row ends up with
        // auto_reply_rule_id = NULL, the cooldown query returns nothing,
        // and the rule fires on every inbound forever.
        'auto_reply_rule_id',
        // Aug-2026: the order this message is ABOUT (order_number, not id).
        // Same fillable trap as auto_reply_rule_id above — saveOutboundMessage()
        // persists via MessageModel::create(), so without this line Eloquent
        // silently drops the column and every template send logs a NULL. That is
        // exactly why it was empty on all 852 delivery_confirmation_online rows
        // while invoice sends (which assign the attribute directly, bypassing
        // mass assignment) had it populated. The Daily Closing follow-up panel
        // counts reminders off this column.
        'related_order_number',
    ];

    protected $casts = [
        'status_updated_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(ConversationModel::class, 'conversation_id');
    }

    public function sender()
    {
        return $this->belongsTo(\App\Models\User::class, 'sent_by');
    }

    /**
     * Get a public URL for the media file.
     *
     * On shared hosting the /storage symlink is often unavailable, so we route
     * through the FileController::publicStorage endpoint (registered at
     * /public-storage/{path}) which streams the file straight from
     * storage/app/public. This is the same fallback the attendance meter
     * pictures use.
     */
    public function getMediaPublicUrlAttribute(): ?string
    {
        if (!$this->media_url) {
            return null;
        }

        return url('public-storage/' . ltrim($this->media_url, '/'));
    }

    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }
}
