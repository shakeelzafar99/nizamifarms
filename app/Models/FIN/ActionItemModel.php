<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use App\Models\CRM\OrderModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionItemModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_action_items';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'item_type',
        'severity',
        'status',
        'title',
        'description',
        'related_entity_type',
        'related_entity_id',
        'order_id',
        'import_log_id',
        'ledger_id',
        'suggested_action',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'resolved_at' => 'datetime'
    ];

    // Type constants
    const TYPE_MISSING_RIDER = 'missing_rider';
    const TYPE_EMPLOYEE_NOT_FOUND = 'employee_not_found';
    const TYPE_POSTING_FAILED = 'posting_failed';
    const TYPE_DATA_ISSUE = 'data_issue';
    const TYPE_IMPORT_SKIPPED = 'import_skipped';

    // Severity constants
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_DISMISSED = 'dismissed';

    /**
     * Relationships
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'resolved_by', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderModel::class, 'order_id', 'id');
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(ImportLogModel::class, 'import_log_id', 'id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_id', 'id');
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('item_type', $type);
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]);
    }

    /**
     * Helper Methods
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function resolve(int $userId, ?string $notes = null): bool
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolved_by = $userId;
        $this->resolved_at = now();
        $this->resolution_notes = $notes;
        
        return $this->save();
    }

    public function dismiss(int $userId, ?string $notes = null): bool
    {
        $this->status = self::STATUS_DISMISSED;
        $this->resolved_by = $userId;
        $this->resolved_at = now();
        $this->resolution_notes = $notes;
        
        return $this->save();
    }

    /**
     * Create action item for missing rider
     */
    public static function createMissingRiderItem(OrderModel $order): self
    {
        return self::create([
            'item_type' => self::TYPE_MISSING_RIDER,
            'severity' => self::SEVERITY_HIGH,
            'title' => "Order #{$order->order_number} delivered without rider",
            'description' => "Order was marked as delivered but no rider was assigned. Cannot post to employee cash account.",
            'related_entity_type' => 'order',
            'related_entity_id' => $order->id,
            'order_id' => $order->id,
            'suggested_action' => "1. Assign a rider to this order\n2. Retry posting to ledger from Action Items page",
            'created_by' => auth()->id() ?? 1
        ]);
    }

    /**
     * Create action item for employee not found during import
     */
    public static function createEmployeeNotFoundItem(string $employeeName, $importLogId = null, array $recordDetails = []): self
    {
        $description = "Employee '{$employeeName}' could not be matched to any user in the system during import.";
        if (!empty($recordDetails)) {
            $description .= "\n\nRecord details: " . json_encode($recordDetails, JSON_PRETTY_PRINT);
        }

        return self::create([
            'item_type' => self::TYPE_EMPLOYEE_NOT_FOUND,
            'severity' => self::SEVERITY_MEDIUM,
            'title' => "Import skipped: Employee '{$employeeName}' not found",
            'description' => $description,
            'related_entity_type' => 'import',
            'import_log_id' => $importLogId,
            'suggested_action' => "1. Create user account for '{$employeeName}'\n2. Or correct the name in your import file\n3. Re-run the import",
            'created_by' => auth()->id() ?? 1
        ]);
    }

    /**
     * Create action item for failed posting
     */
    public static function createPostingFailedItem(string $title, string $error, array $context = []): self
    {
        return self::create([
            'item_type' => self::TYPE_POSTING_FAILED,
            'severity' => self::SEVERITY_HIGH,
            'title' => $title,
            'description' => "Posting failed with error: {$error}\n\nContext: " . json_encode($context, JSON_PRETTY_PRINT),
            'related_entity_type' => $context['entity_type'] ?? null,
            'related_entity_id' => $context['entity_id'] ?? null,
            'order_id' => $context['order_id'] ?? null,
            'suggested_action' => 'Review the error details and correct the underlying issue, then retry posting.',
            'created_by' => auth()->id() ?? 1
        ]);
    }

    /**
     * Get summary statistics
     */
    public static function getSummary(): array
    {
        return [
            'total_pending' => self::where('status', self::STATUS_PENDING)->count(),
            'high_priority' => self::where('status', self::STATUS_PENDING)
                                   ->whereIn('severity', [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL])
                                   ->count(),
            'by_type' => self::where('status', self::STATUS_PENDING)
                             ->select('item_type', \DB::raw('COUNT(*) as count'))
                             ->groupBy('item_type')
                             ->pluck('count', 'item_type')
                             ->toArray()
        ];
    }
}

