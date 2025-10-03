<?php

namespace App\Models\Request;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Support\Facades\DB;

class RequestModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_req_master';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'request_number',
        'category_id',
        'requester_user_id',
        'title',
        'description',
        'amount',
        'leave_start_date',
        'leave_end_date',
        'leave_type',
        'leave_days',
        'status',
        'priority',
        'requires_level_1',
        'requires_level_2',
        'level_1_status',
        'level_2_status',
        'attachments',
        'remarks',
        'rejection_reason',
        'submitted_at',
        'completed_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'leave_start_date' => 'date',
        'leave_end_date' => 'date',
        'leave_days' => 'integer',
        'requires_level_1' => 'boolean',
        'requires_level_2' => 'boolean',
        'attachments' => 'json',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    const APPROVAL_STATUS_PENDING = 'pending';
    const APPROVAL_STATUS_APPROVED = 'approved';
    const APPROVAL_STATUS_REJECTED = 'rejected';

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(RequestCategoryModel::class, 'category_id', 'id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'requester_user_id', 'id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RequestApprovalModel::class, 'request_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeByRequester($query, int $userId)
    {
        return $query->where('requester_user_id', $userId);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeLeaveRequests($query)
    {
        return $query->whereHas('category', function($q) {
            $q->where('category_code', 'leave');
        });
    }

    // Helper methods
    public static function generateRequestNumber(): string
    {
        $prefix = 'REQ';
        $year = date('Y');
        $month = date('m');
        
        // Get last number for this month
        $lastRequest = static::where('request_number', 'LIKE', "{$prefix}-{$year}{$month}-%")
            ->orderBy('request_number', 'desc')
            ->first();
        
        if ($lastRequest) {
            $lastNumber = (int) substr($lastRequest->request_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return sprintf('%s-%s%s-%04d', $prefix, $year, $month, $newNumber);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function canBeApprovedByLevel(int $level): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        if ($level === 1) {
            return $this->requires_level_1 && $this->level_1_status === self::APPROVAL_STATUS_PENDING;
        }

        if ($level === 2) {
            return $this->requires_level_2 && 
                   $this->level_2_status === self::APPROVAL_STATUS_PENDING &&
                   (!$this->requires_level_1 || $this->level_1_status === self::APPROVAL_STATUS_APPROVED);
        }

        return false;
    }

    public function processApproval(int $level, int $approverId, string $action, ?string $comments = null): bool
    {
        if (!in_array($action, ['approved', 'rejected'])) {
            return false;
        }

        if (!$this->canBeApprovedByLevel($level)) {
            return false;
        }

        DB::beginTransaction();
        try {
            // Create approval record
            RequestApprovalModel::create([
                'request_id' => $this->id,
                'approval_level' => $level,
                'approver_user_id' => $approverId,
                'status' => $action,
                'comments' => $comments,
                'action_date' => now(),
                'created_by' => $approverId
            ]);

            // Update request status
            if ($level === 1) {
                $this->level_1_status = $action;
            } elseif ($level === 2) {
                $this->level_2_status = $action;
            }

            // If rejected at any level, mark entire request as rejected
            if ($action === 'rejected') {
                $this->status = self::STATUS_REJECTED;
                $this->rejection_reason = $comments;
                $this->completed_at = now();
            }
            // Check if all required approvals are complete
            elseif ($this->areAllApprovalsComplete()) {
                $this->status = self::STATUS_APPROVED;
                $this->completed_at = now();
                
                // If it's a leave request, create attendance records
                if ($this->category->category_code === 'leave') {
                    $this->createAttendanceRecordsForLeave();
                }
            }

            $this->updated_by = $approverId;
            $this->save();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Request approval error: ' . $e->getMessage());
            return false;
        }
    }

    protected function areAllApprovalsComplete(): bool
    {
        if ($this->requires_level_1 && $this->level_1_status !== self::APPROVAL_STATUS_APPROVED) {
            return false;
        }

        if ($this->requires_level_2 && $this->level_2_status !== self::APPROVAL_STATUS_APPROVED) {
            return false;
        }

        return true;
    }

    protected function createAttendanceRecordsForLeave(): void
    {
        if (!$this->leave_start_date || !$this->leave_end_date) {
            return;
        }

        $startDate = $this->leave_start_date;
        $endDate = $this->leave_end_date;
        $userId = $this->requester_user_id;

        // Create attendance records for each day of the leave
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            // Check if attendance record already exists
            $exists = DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->where('attendance_date', $currentDate->format('Y-m-d'))
                ->exists();

            if (!$exists) {
                DB::table('t_ops_attendance')->insert([
                    'user_id' => $userId,
                    'attendance_date' => $currentDate->format('Y-m-d'),
                    'login_time' => null,
                    'logout_time' => null,
                    'status' => 'leave',
                    'notes' => "Approved leave: {$this->leave_type}",
                    'leave_request_id' => $this->id,
                    'leave_type' => $this->leave_type,
                    'created_at' => now(),
                    'created_by' => $this->updated_by
                ]);
            }

            $currentDate->addDay();
        }
    }

    public function getApprovalStatusText(): string
    {
        if ($this->status === self::STATUS_APPROVED) {
            return 'Approved';
        }

        if ($this->status === self::STATUS_REJECTED) {
            return 'Rejected';
        }

        if ($this->status === self::STATUS_CANCELLED) {
            return 'Cancelled';
        }

        // Pending - show which level
        if ($this->requires_level_1 && $this->level_1_status === self::APPROVAL_STATUS_PENDING) {
            return 'Pending Level 1 Approval';
        }

        if ($this->requires_level_2 && $this->level_2_status === self::APPROVAL_STATUS_PENDING) {
            return 'Pending Level 2 Approval';
        }

        return 'Pending';
    }

    public function getLevel1Approver()
    {
        return $this->approvals()
            ->where('approval_level', 1)
            ->with('approver')
            ->first();
    }

    public function getLevel2Approver()
    {
        return $this->approvals()
            ->where('approval_level', 2)
            ->with('approver')
            ->first();
    }
}

