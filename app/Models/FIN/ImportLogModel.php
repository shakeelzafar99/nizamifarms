<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLogModel extends BaseModel
{
    use HasFactory;

    protected $table = 't_fin_import_log';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'import_source',
        'import_date',
        'file_name',
        'rows_processed',
        'rows_inserted',
        'rows_skipped',
        'rows_failed',
        'status',
        'summary',
        'error_details',
        'imported_by'
    ];

    protected $casts = [
        'import_date' => 'datetime',
        'rows_processed' => 'integer',
        'rows_inserted' => 'integer',
        'rows_skipped' => 'integer',
        'rows_failed' => 'integer',
        'summary' => 'json',
        'error_details' => 'json'
    ];

    // Status constants
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_PARTIAL = 'partial';

    /**
     * Relationships
     */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'imported_by', 'id');
    }

    /**
     * Scopes
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeBySource($query, $source)
    {
        return $query->where('import_source', $source);
    }

    /**
     * Helper Methods
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->rows_processed == 0) {
            return 0;
        }
        
        return ($this->rows_inserted / $this->rows_processed) * 100;
    }

    public function getFormattedSuccessRateAttribute(): string
    {
        return number_format($this->success_rate, 2) . '%';
    }

    /**
     * Create new import log
     */
    public static function startImport($source, $fileName, $userId = null)
    {
        return static::create([
            'import_source' => $source,
            'import_date' => now(),
            'file_name' => $fileName,
            'rows_processed' => 0,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'status' => self::STATUS_IN_PROGRESS,
            'imported_by' => $userId ?? auth()->id() ?? 1
        ]);
    }

    /**
     * Update import progress
     */
    public function updateProgress($inserted = 0, $skipped = 0, $failed = 0)
    {
        $this->rows_processed += ($inserted + $skipped + $failed);
        $this->rows_inserted += $inserted;
        $this->rows_skipped += $skipped;
        $this->rows_failed += $failed;
        $this->save();
    }

    /**
     * Complete import
     */
    public function complete($summary = [])
    {
        $this->status = $this->rows_failed > 0 ? self::STATUS_PARTIAL : self::STATUS_COMPLETED;
        $this->summary = $summary;
        $this->save();
    }

    /**
     * Mark as failed
     */
    public function markFailed($errorDetails = [])
    {
        $this->status = self::STATUS_FAILED;
        $this->error_details = $errorDetails;
        $this->save();
    }
}

