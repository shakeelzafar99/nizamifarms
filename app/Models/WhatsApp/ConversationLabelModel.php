<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

/**
 * Pivot row mapping a WhatsApp conversation to a label, with audit columns so
 * we know WHO labelled what and WHEN (useful for Phase 2 mention analytics).
 *
 * One unique (conversation_id, label_id) pair — enforced at DB level; callers
 * use insertOrIgnore/updateOrCreate to idempotently "apply" a label.
 */
class ConversationLabelModel extends Model
{
    protected $table = 't_wa_conversation_labels';
    protected $primaryKey = 'id';

    // We manage applied_at ourselves via the column default + explicit writes
    // and never need updated_at, so disable Eloquent's auto-timestamping.
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'label_id',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'label_id'        => 'integer',
        'applied_by'      => 'integer',
        'applied_at'      => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(ConversationModel::class, 'conversation_id');
    }

    public function label()
    {
        return $this->belongsTo(LabelModel::class, 'label_id');
    }
}
