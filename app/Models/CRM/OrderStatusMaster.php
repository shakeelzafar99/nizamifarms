<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Shared\BaseModel;

class OrderStatusMaster extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_crm_order_status_master';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'status_code',
        'status_name',
        'description',
        'sequence_order',
        'is_active',
        'is_final',
        'color_class',
        'icon',
        'show_in_mobile',          // Whether this status appears in mobile app
        'visible_to_roles',         // JSON array of role IDs (null = all roles)
        // Order Status Hub (Jul-2026) behaviour columns
        'counts_in_quantities',     // Feeds the Open-Order Quantities "to prepare" list
        'auto_prepares',            // "Out the door": items treated as prepared, hidden from both qty views
        'lane',                     // journey | offtrack | legacy (Hub organisation)
        'send_to_customer_app',     // Whether a status change is pushed to the customer app
        'customer_app_alias',       // What the customer app should show instead of the raw code
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_final' => 'boolean',
        'show_in_mobile' => 'boolean',
        'visible_to_roles' => 'array',  // Auto JSON encode/decode
        'sequence_order' => 'integer',
        'counts_in_quantities' => 'boolean',
        'auto_prepares' => 'boolean',
        'send_to_customer_app' => 'boolean'
    ];

    // Relationships
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'status_id');
    }

    public function transitionsFrom(): HasMany
    {
        return $this->hasMany(OrderStatusTransition::class, 'from_status_id');
    }

    public function transitionsTo(): HasMany
    {
        return $this->hasMany(OrderStatusTransition::class, 'to_status_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence_order');
    }

    public function scopeFinal($query)
    {
        return $query->where('is_final', true);
    }

    public function scopeNotFinal($query)
    {
        return $query->where('is_final', false);
    }

    // Helper methods
    public function canTransitionTo(OrderStatusMaster $toStatus): bool
    {
        return $this->transitionsFrom()
            ->where('to_status_id', $toStatus->id)
            ->where('is_allowed', true)
            ->exists();
    }

    public function getAvailableTransitions()
    {
        return static::whereIn('id', 
            $this->transitionsFrom()
                ->where('is_allowed', true)
                ->pluck('to_status_id')
        )->active()->ordered()->get();
    }

    public static function getByCode(string $code): ?self
    {
        return static::where('status_code', $code)->active()->first();
    }

    public static function getActiveStatuses()
    {
        return static::active()->ordered()->get();
    }

    public static function getNextSequenceOrder(): int
    {
        return (static::max('sequence_order') ?? 0) + 1;
    }
    
    /**
     * Check if this status is visible to a specific user (based on their roles)
     */
    public function isVisibleToUser($user): bool
    {
        // If no role restriction, visible to all
        if (empty($this->visible_to_roles)) {
            return true;
        }
        
        // Get user's role IDs
        $userRoleIds = $user->roles()->pluck('id')->toArray();
        
        // Check if any of user's roles are in the visible_to_roles array
        return !empty(array_intersect($userRoleIds, $this->visible_to_roles));
    }
    
    /**
     * Get statuses visible to a specific user in mobile app
     */
    public static function getStatusesForMobile($user)
    {
        $query = static::active()->where('show_in_mobile', true);

        // Status Hub: never offer a "legacy" (retiring) status in the mobile picker.
        // Guarded so this is safe even before the Hub migration has run.
        if (\Schema::hasColumn('t_crm_order_status_master', 'lane')) {
            $query->where('lane', '!=', 'legacy');
        }

        $statuses = $query->ordered()->get();
        
        // Filter by role visibility
        return $statuses->filter(function($status) use ($user) {
            return $status->isVisibleToUser($user);
        })->values();
    }
}
