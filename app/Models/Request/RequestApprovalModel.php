<?php

namespace App\Models\Request;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;

class RequestApprovalModel extends BaseModel
{
    use HasFactory;
    
    protected $table = 't_req_approval';
    protected $primaryKey = 'id';
    public $timestamps = false; // Only has created_at

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'approval_level',
        'approver_user_id',
        'status',
        'comments',
        'action_date',
        'created_by'
    ];

    protected $casts = [
        'approval_level' => 'integer',
        'action_date' => 'datetime'
    ];

    // Relationships
    public function request(): BelongsTo
    {
        return $this->belongsTo(RequestModel::class, 'request_id', 'id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'approver_user_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'id');
    }

    // Scopes
    public function scopeLevel($query, int $level)
    {
        return $query->where('approval_level', $level);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeByApprover($query, int $userId)
    {
        return $query->where('approver_user_id', $userId);
    }

    // Helper methods
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}

