<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\BaseModel;
use Carbon\Carbon;

class OrderStatusHistory extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_crm_order_status_history';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'order_id',
        'status_id',
        'status_code',
        'is_current',
        'changed_at',
        'changed_by',
        'notes'
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'changed_at' => 'datetime'
    ];

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatusMaster::class, 'status_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'changed_by');
    }

    // Scopes
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeForOrder($query, int $orderId)
    {
        return $query->where('order_id', $orderId);
    }

    public function scopeByStatus($query, string $statusCode)
    {
        return $query->where('status_code', $statusCode);
    }

    public function scopeOrderedByDate($query, string $direction = 'desc')
    {
        return $query->orderBy('changed_at', $direction);
    }

    // Helper methods
    public function getFormattedChangedAtAttribute(): string
    {
        return $this->changed_at ? $this->changed_at->format('M j, Y g:i A') : '';
    }

    public function getChangedByNameAttribute(): string
    {
        return $this->changedBy ? $this->changedBy->name : 'System';
    }

    public function getStatusDisplayAttribute(): string
    {
        return $this->status ? $this->status->status_name : ucfirst(str_replace('_', ' ', $this->status_code));
    }

    public static function getCurrentStatusForOrder(int $orderId): ?self
    {
        return static::forOrder($orderId)->current()->first();
    }

    public static function getHistoryForOrder(int $orderId)
    {
        return static::forOrder($orderId)
            ->with(['status', 'changedBy'])
            ->orderedByDate()
            ->get();
    }

    public static function createStatusChange(int $orderId, string $statusCode, ?int $changedBy = null, ?string $notes = null): self
    {
        $status = OrderStatusMaster::getByCode($statusCode);
        
        if (!$status) {
            throw new \InvalidArgumentException("Invalid status code: {$statusCode}");
        }

        return static::create([
            'order_id' => $orderId,
            'status_id' => $status->id,
            'status_code' => $statusCode,
            'changed_by' => $changedBy ?? auth()->id(),
            'notes' => $notes,
            'changed_at' => now()
        ]);
    }
}
