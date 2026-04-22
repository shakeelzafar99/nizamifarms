<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

/**
 * WhatsApp shared label library (Phase 1 of conversation labels).
 *
 * Labels are owned by the workspace, not by individual conversations — they
 * live in `t_wa_labels` and are applied to conversations through the
 * `t_wa_conversation_labels` pivot. Two logical kinds:
 *
 *   • Generic (user_id NULL)   → topic/action tags ("Order Request", "VIP").
 *   • User-mention (user_id)   → one per staff user. Applying one is treated
 *                                as an @mention in Phase 2 (push + in-app
 *                                highlight for that user).
 *
 * `is_system=1` marks seeded labels so the admin UI can warn before delete.
 */
class LabelModel extends Model
{
    protected $table = 't_wa_labels';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'name',
        'color',
        'user_id',
        'is_system',
        'created_by',
    ];

    protected $casts = [
        'user_id'   => 'integer',
        'is_system' => 'boolean',
    ];

    public function conversations()
    {
        return $this->belongsToMany(
            ConversationModel::class,
            't_wa_conversation_labels',
            'label_id',
            'conversation_id'
        )->withPivot('applied_by', 'applied_at');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
