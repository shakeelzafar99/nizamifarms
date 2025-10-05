<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class ShiftTemplateModel extends Model
{
    protected $table = 't_ops_shift_template';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'shift_name',
        'shift_code',
        'shift_start',
        'shift_end',
        'working_days',
        'is_default',
        'description',
        'active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'working_days' => 'array',  // Auto JSON encode/decode
        'is_default' => 'boolean',
        'active' => 'boolean'
    ];

    /**
     * Relationships
     */
    public function userAssignments()
    {
        return $this->hasMany(UserShiftAssignmentModel::class, 'shift_template_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * Helper methods
     */
    public function getWorkingDaysArray(): array
    {
        return is_array($this->working_days) ? $this->working_days : [];
    }

    public function isWorkingDay(int $dayOfWeek): bool
    {
        // $dayOfWeek: 1=Mon, 7=Sun (ISO-8601)
        return in_array($dayOfWeek, $this->getWorkingDaysArray());
    }

    public function getWorkingDaysCount(): int
    {
        return count($this->getWorkingDaysArray());
    }

    public function getOffDaysString(): string
    {
        $allDays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $workingDays = $this->getWorkingDaysArray();
        $offDays = array_diff(array_keys($allDays), $workingDays);
        
        if (empty($offDays)) {
            return 'No off days';
        }
        
        return implode(', ', array_map(fn($day) => $allDays[$day], $offDays));
    }

    /**
     * Get the default shift template
     */
    public static function getDefaultShift()
    {
        return self::where('is_default', 1)
            ->where('active', 1)
            ->first();
    }

    /**
     * Get all active shift templates
     */
    public static function getActiveShifts()
    {
        return self::where('active', 1)
            ->orderBy('shift_name')
            ->get();
    }

    /**
     * Scope queries
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', 1);
    }
}



