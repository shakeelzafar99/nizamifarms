<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;

/**
 * Reusable per-line-item "quick note" presets (chips shown next to the
 * free-text "Add note" box on the web Edit Order screen and the mobile
 * order-edit screens). The note a user picks is still saved into the
 * existing t_crm_prod_order_line_item.instructions field — this table only
 * holds the shared, team-wide list of preset strings that can be pinned.
 *
 * Table created manually (see handoff SQL): t_crm_line_item_quick_note.
 */
class LineItemQuickNoteModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_crm_line_item_quick_note';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'note_text',
        'sort_order',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];
}
