<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

class TemplateModel extends Model
{
    protected $table = 't_wa_templates';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'display_name',
        'category',
        'language',
        'status',
        'header_text',
        'body_text',
        'footer_text',
        'variable_count',
        'has_buttons',
        'button_labels',
        'show_in',
        'is_default',
        'is_active',
        'is_qurbani_only',
        'is_regular_only',
    ];

    protected $casts = [
        'variable_count' => 'integer',
        'has_buttons' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'is_qurbani_only' => 'boolean',
        'is_regular_only' => 'boolean',
    ];
}
