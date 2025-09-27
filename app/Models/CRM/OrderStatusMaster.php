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
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_final' => 'boolean',
        'sequence_order' => 'integer'
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
}
