<?php

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-rule operator settings for an event-triggered WhatsApp automation.
 *
 * The rule CATALOG (what each rule does, its trigger, variables, which knobs
 * are editable) lives in code — App\Services\WhatsApp\Automation\
 * AutomationRegistry. This model only stores the operator's choices for a
 * single rule: whether it's on, which template it sends, and a JSON blob of
 * the editable params that rule type permits.
 *
 * IMPORTANT: absence of a row == disabled. A rule is only ever active when a
 * row exists AND enabled = 1 AND the master switch (wa_automations_enabled)
 * is on. See create_wa_automations_jun2026.sql.
 */
class AutomationModel extends Model
{
    protected $table = 't_wa_automations';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'rule_key',
        'enabled',
        'template_name',
        'config_json',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * The editable params for this rule, decoded. Always an array (never null)
     * so callers can read keys without guarding. Invalid JSON => empty array.
     */
    public function config(): array
    {
        if (empty($this->config_json)) {
            return [];
        }
        $decoded = json_decode($this->config_json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
