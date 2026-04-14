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
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_customer_message_at' => 'datetime',
        'unread_count' => 'integer',
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

    /**
     * Check if the 24-hour free messaging window is active
     */
    public function isSessionActive(): bool
    {
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
