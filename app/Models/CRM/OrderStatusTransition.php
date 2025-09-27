<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\BaseModel;

class OrderStatusTransition extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_crm_order_status_transitions';
    protected $primaryKey = 'id';
    public $timestamps = false; // Only has created_at

    protected $fillable = [
        'from_status_id',
        'to_status_id',
        'is_allowed',
        'created_by'
    ];

    protected $casts = [
        'is_allowed' => 'boolean'
    ];

    // Relationships
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatusMaster::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatusMaster::class, 'to_status_id');
    }

    // Scopes
    public function scopeAllowed($query)
    {
        return $query->where('is_allowed', true);
    }

    public function scopeFromStatus($query, int $statusId)
    {
        return $query->where('from_status_id', $statusId);
    }

    public function scopeToStatus($query, int $statusId)
    {
        return $query->where('to_status_id', $statusId);
    }

    // Helper methods
    public static function isTransitionAllowed(int $fromStatusId, int $toStatusId): bool
    {
        return static::fromStatus($fromStatusId)
            ->toStatus($toStatusId)
            ->allowed()
            ->exists();
    }

    public static function createTransition(int $fromStatusId, int $toStatusId, bool $isAllowed = true): self
    {
        return static::updateOrCreate(
            [
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatusId
            ],
            [
                'is_allowed' => $isAllowed,
                'created_by' => auth()->id()
            ]
        );
    }

    public static function getAllowedTransitions(int $fromStatusId)
    {
        return static::fromStatus($fromStatusId)
            ->allowed()
            ->with('toStatus')
            ->get()
            ->pluck('toStatus');
    }
}
