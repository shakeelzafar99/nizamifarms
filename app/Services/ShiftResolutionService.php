<?php

namespace App\Services;

use App\Models\Ops\ShiftTemplateModel;
use App\Models\Ops\UserShiftAssignmentModel;
use App\Models\Ops\PublicHolidayModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShiftResolutionService
{
    /**
     * Get the effective shift for a user on a specific date
     * 
     * Resolution order:
     * 1. Check user_shift_assignment (explicit user assignment)
     * 2. Fall back to old rider_profile.shift_start/end if not migrated
     * 3. Fall back to default shift template
     * 4. Fall back to hardcoded values
     * 
     * @param int $userId
     * @param string|null $date (Y-m-d format)
     * @return array ['shift_start' => '09:00', 'shift_end' => '17:00', 'working_days' => [1,2,3,4,5,6], 'shift_name' => '...', 'shift_id' => 1, 'source' => '...']
     */
    public function getUserShift(int $userId, ?string $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');
        
        // Try to get from cache first (cache for 1 hour)
        $cacheKey = "user_shift_{$userId}_{$date}";
        
        return Cache::remember($cacheKey, 3600, function() use ($userId, $date) {
            // 1. Check user shift assignment
            $assignment = UserShiftAssignmentModel::with('shiftTemplate')
                ->where('user_id', $userId)
                ->effective($date)
                ->first();

            if ($assignment && $assignment->shiftTemplate && $assignment->shiftTemplate->active) {
                $shift = $assignment->shiftTemplate;
                return [
                    'shift_start' => substr($shift->shift_start, 0, 5), // HH:MM format
                    'shift_end' => substr($shift->shift_end, 0, 5),
                    'working_days' => $shift->getWorkingDaysArray(),
                    'shift_name' => $shift->shift_name,
                    'shift_id' => $shift->id,
                    'source' => 'user_assignment'
                ];
            }

            // 2. Fall back to old rider_profile system
            $riderProfile = DB::table('t_ops_rider_profile')
                ->where('user_id', $userId)
                ->where('migrated_to_shift_system', 0)
                ->first();

            if ($riderProfile && $riderProfile->shift_start && $riderProfile->shift_end) {
                return [
                    'shift_start' => substr($riderProfile->shift_start, 0, 5),
                    'shift_end' => substr($riderProfile->shift_end, 0, 5),
                    'working_days' => [1,3,4,5,6,7], // Hardcoded: exclude Tuesday (legacy default)
                    'shift_name' => 'Legacy Shift',
                    'shift_id' => null,
                    'source' => 'legacy_rider_profile'
                ];
            }

            // 3. Fall back to default shift
            $defaultShift = ShiftTemplateModel::getDefaultShift();
            if ($defaultShift) {
                return [
                    'shift_start' => substr($defaultShift->shift_start, 0, 5),
                    'shift_end' => substr($defaultShift->shift_end, 0, 5),
                    'working_days' => $defaultShift->getWorkingDaysArray(),
                    'shift_name' => $defaultShift->shift_name,
                    'shift_id' => $defaultShift->id,
                    'source' => 'default_shift'
                ];
            }

            // 4. Ultimate fallback (hardcoded)
            return [
                'shift_start' => '09:00',
                'shift_end' => '17:00',
                'working_days' => [1,2,3,4,5,6], // Mon-Sat
                'shift_name' => 'System Default',
                'shift_id' => null,
                'source' => 'hardcoded_fallback'
            ];
        });
    }

    /**
     * Calculate working days in a date range for a specific user
     * Excludes user's off days AND public holidays
     * 
     * @param int $userId
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return int
     */
    public function calculateWorkingDays(int $userId, string $startDate, string $endDate): int
    {
        // Get user's shift using today's date (current shift assignment)
        // This ensures shifts apply retroactively to all past dates
        $lookupDate = date('Y-m-d');
        $shift = $this->getUserShift($userId, $lookupDate);
        $workingDaysOfWeek = $shift['working_days'];
        
        Log::info('calculateWorkingDays - shift data', [
            'user_id' => $userId,
            'shift_name' => $shift['shift_name'],
            'working_days_array' => $workingDaysOfWeek,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        // Get public holidays in this range
        $holidays = PublicHolidayModel::getHolidaysInRange($startDate, $endDate);
        
        // Iterate through date range
        $workingDays = 0;
        $currentDate = new \DateTime($startDate);
        $endDateObj = new \DateTime($endDate);
        
        while ($currentDate <= $endDateObj) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayOfWeek = (int)$currentDate->format('N'); // 1=Mon, 7=Sun
            
            // Check if it's a working day AND not a holiday
            if (in_array($dayOfWeek, $workingDaysOfWeek) && !in_array($dateStr, $holidays)) {
                $workingDays++;
            }
            
            $currentDate->modify('+1 day');
        }
        
        Log::info('calculateWorkingDays - result', [
            'user_id' => $userId,
            'working_days_count' => $workingDays
        ]);
        
        return $workingDays;
    }

    /**
     * Get shift info for multiple users at once (bulk operation)
     * Returns array keyed by user_id
     * 
     * @param array $userIds
     * @param string|null $date
     * @return array
     */
    public function getUserShiftsBulk(array $userIds, ?string $date = null): array
    {
        $shifts = [];
        foreach ($userIds as $userId) {
            $shifts[$userId] = $this->getUserShift($userId, $date);
        }
        return $shifts;
    }

    /**
     * Check if a specific date is a working day for a user
     * 
     * @param int $userId
     * @param string $date (Y-m-d)
     * @return bool
     */
    public function isWorkingDay(int $userId, string $date): bool
    {
        // Get user's shift
        $shift = $this->getUserShift($userId, $date);
        
        // Check day of week
        $dayOfWeek = (int)(new \DateTime($date))->format('N');
        if (!in_array($dayOfWeek, $shift['working_days'])) {
            return false;
        }
        
        // Check if it's a public holiday
        if (PublicHolidayModel::isHoliday($date)) {
            return false;
        }
        
        return true;
    }

    /**
     * Clear cache for a user's shift (call after updating shifts or assignments)
     * 
     * @param int $userId
     */
    public function clearUserShiftCache(int $userId): void
    {
        // Clear all possible cached entries for this user
        // In a production app, you'd use cache tags for more efficient clearing
        $today = now()->format('Y-m-d');
        for ($i = -30; $i <= 30; $i++) {
            $date = now()->addDays($i)->format('Y-m-d');
            Cache::forget("user_shift_{$userId}_{$date}");
        }
        
        Log::info("Cleared shift cache for user {$userId}");
    }

    /**
     * Clear all shift caches (call after creating/updating shift templates)
     */
    public function clearAllShiftCaches(): void
    {
        // For now, just flush all cache
        // In production, you'd use cache tags for more granular control
        Cache::flush();
        
        Log::info("Cleared all shift caches");
    }

    /**
     * Get a summary of shift distribution across users
     * Useful for reports and dashboards
     * 
     * @return array
     */
    public function getShiftDistributionSummary(): array
    {
        $summary = [];
        
        // Get all shift templates
        $shifts = ShiftTemplateModel::active()->get();
        
        foreach ($shifts as $shift) {
            $summary[] = [
                'shift_id' => $shift->id,
                'shift_name' => $shift->shift_name,
                'assigned_users' => $shift->userAssignments()->count(),
                'working_days_count' => $shift->getWorkingDaysCount(),
                'hours' => substr($shift->shift_start, 0, 5) . ' - ' . substr($shift->shift_end, 0, 5)
            ];
        }
        
        // Add legacy users count
        $legacyCount = DB::table('t_ops_rider_profile')
            ->where('migrated_to_shift_system', 0)
            ->whereNotNull('shift_start')
            ->count();
        
        if ($legacyCount > 0) {
            $summary[] = [
                'shift_id' => null,
                'shift_name' => 'Legacy (Not Migrated)',
                'assigned_users' => $legacyCount,
                'working_days_count' => 6,
                'hours' => 'Various'
            ];
        }
        
        return $summary;
    }
}



