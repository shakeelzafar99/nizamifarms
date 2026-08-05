<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;
use App\Models\CRM\CustomerModel;

class ConversationModel extends Model
{
    protected $table = 't_wa_conversations';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'customer_id',
        'wa_phone',
        'wa_contact_name',
        'status',
        'last_message_at',
        'last_customer_message_at',
        'unread_count',
        'assigned_to',
        'is_qurbani',
        'qurbani_flagged_at',
        'qurbani_flag_reason',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_customer_message_at' => 'datetime',
        'unread_count' => 'integer',
        'is_qurbani' => 'boolean',
        'qurbani_flagged_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(CustomerModel::class, 'customer_id');
    }

    public function messages()
    {
        return $this->hasMany(MessageModel::class, 'conversation_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    public function reads()
    {
        return $this->hasMany(ConversationReadModel::class, 'conversation_id');
    }

    /**
     * DEV-ONLY escape hatch for the 24-hour window.
     *
     * Meta's webhook points at production, so a message the developer sends to
     * the business number never reaches a local install — the window can never
     * open on dev and the composer is permanently stuck on "Send template",
     * making the free-form/voice UI untestable locally.
     *
     * Numbers listed in `whatsapp.dev_open_numbers` are treated as always in
     * session. HARD-GATED on APP_ENV !== production, so shipping this file to
     * prod cannot open a real customer's window even if the env var came along.
     * Meta still enforces its own rule server-side — a send may still be
     * rejected; this only unblocks OUR gate so the UI can be exercised.
     */
    public static function devSessionOverride(?string $waPhone): bool
    {
        if (app()->environment('production') || !$waPhone) {
            return false;
        }
        $list = trim((string) config('whatsapp.dev_open_numbers', ''));
        if ($list === '') {
            return false;
        }
        // Compare on trailing digits so +92…, 0345…, 92… all match one entry.
        $tail = fn($n) => substr(preg_replace('/\D+/', '', (string) $n), -10);
        $mine = $tail($waPhone);
        if ($mine === '') {
            return false;
        }
        foreach (explode(',', $list) as $entry) {
            if ($tail($entry) !== '' && $tail($entry) === $mine) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the 24-hour free messaging window is active
     */
    public function isSessionActive(): bool
    {
        if (self::devSessionOverride($this->wa_phone)) {
            return true;
        }

        if (!$this->last_customer_message_at) {
            return false;
        }

        return $this->last_customer_message_at->diffInHours(now()) < 24;
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->customer) {
            $name = trim($this->customer->first_name . ' ' . $this->customer->last_name);
            if ($name) return $name;
        }

        return $this->wa_contact_name ?: $this->wa_phone;
    }

    public function scopeWithUnread($query)
    {
        return $query->where('unread_count', '>', 0);
    }
}
