<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class PublicHolidayModel extends Model
{
    protected $table = 't_ops_public_holidays';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'holiday_date',
        'holiday_name',
        'description',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_active' => 'boolean'
    ];

    /**
     * Relationships
     */
    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * Get all active holidays in a date range
     * 
     * @param string $startDate Y-m-d format
     * @param string $endDate Y-m-d format
     * @return array Array of date strings
     */
    public static function getHolidaysInRange(string $startDate, string $endDate): array
    {
        return self::where('is_active', 1)
            ->whereBetween('holiday_date', [$startDate, $endDate])
            ->pluck('holiday_date')
            ->map(fn($date) => $date->format('Y-m-d'))
            ->toArray();
    }

    /**
     * Check if a specific date is a holiday
     * 
     * @param string $date Y-m-d format
     * @return bool
     */
    public static function isHoliday(string $date): bool
    {
        return self::where('is_active', 1)
            ->where('holiday_date', $date)
            ->exists();
    }

    /**
     * Get all active holidays for the current year
     */
    public static function getCurrentYearHolidays()
    {
        $year = date('Y');
        return self::where('is_active', 1)
            ->whereYear('holiday_date', $year)
            ->orderBy('holiday_date')
            ->get();
    }

    /**
     * Get upcoming holidays (next 90 days)
     */
    public static function getUpcomingHolidays(int $days = 90)
    {
        $startDate = now()->format('Y-m-d');
        $endDate = now()->addDays($days)->format('Y-m-d');
        
        return self::where('is_active', 1)
            ->whereBetween('holiday_date', [$startDate, $endDate])
            ->orderBy('holiday_date')
            ->get();
    }

    /**
     * Scope queries
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    public function scopeUpcoming($query, int $days = 90)
    {
        $startDate = now()->format('Y-m-d');
        $endDate = now()->addDays($days)->format('Y-m-d');
        
        return $query->whereBetween('holiday_date', [$startDate, $endDate]);
    }

    public function scopeCurrentYear($query)
    {
        return $query->whereYear('holiday_date', date('Y'));
    }
}



