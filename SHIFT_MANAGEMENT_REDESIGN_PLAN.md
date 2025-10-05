# Shift Management System Redesign - Implementation Plan

## Current State Analysis

### 1. **Current Implementation:**
- **Storage:** `t_ops_rider_profile` table has `shift_start` and `shift_end` columns (TIME type)
- **Management:** Individual shift times per user (managed one-by-one in "Manage Shifts" modal)
- **Working Days:** Hardcoded Tuesday as off-day in `AttendanceController.php` (line 438)
- **Reports:** `attendance/reports` page has localStorage-based working days config (checkboxes for each day + holiday calendar)
- **Usage:**
  - Attendance queries use `COALESCE(rp.shift_start, "09:00")` as fallback
  - Late/overtime calculations compare against shift times
  - Working days calculation excludes Tuesday

### 2. **Problems with Current Approach:**
- ❌ Managing shifts individually for each user is tedious
- ❌ Working days hardcoded, not user-specific
- ❌ Reports working days config stored in localStorage (not persistent across devices/users)
- ❌ No relationship between roles and shifts
- ❌ No centralized shift template system
- ❌ Holiday management only in reports, not integrated

---

## Proposed Solution: Shift Templates System

### **Architecture Overview:**

```
┌─────────────────────────┐
│   Shift Templates       │  ← Define reusable shift configurations
│  (t_ops_shift_template) │     (e.g., "Rider Shift 1", "Office Shift")
└──────────┬──────────────┘
           │
           ├─► Working Hours (shift_start, shift_end)
           ├─► Working Days (JSON: [1,2,3,4,5,6] = Mon-Sat)
           └─► Is Default?
           
┌─────────────────────────┐
│  User ↔ Shift Mapping   │  ← Assign shifts to individual users
│ (t_ops_user_shift_assignment) │
└─────────────────────────┘

┌─────────────────────────┐
│    Public Holidays      │  ← Centralized holiday calendar
│  (t_ops_public_holidays)│     (applies to all users)
└─────────────────────────┘
```

---

## Database Schema

### 1. **New Table: `t_ops_shift_template`**
Stores reusable shift configurations.

```sql
CREATE TABLE t_ops_shift_template (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_name VARCHAR(100) NOT NULL,                  -- e.g., "Rider Shift 1", "Office 9-5"
    shift_code VARCHAR(50) NOT NULL UNIQUE,            -- e.g., "rider_shift_1", "office_9_5"
    shift_start TIME NOT NULL DEFAULT '09:00:00',      -- e.g., 09:00
    shift_end TIME NOT NULL DEFAULT '17:00:00',        -- e.g., 17:00
    working_days JSON NOT NULL,                         -- [1,2,3,4,5,6] = Mon-Sat (1=Mon, 7=Sun)
    is_default TINYINT(1) DEFAULT 0,                   -- 1 = default shift for users without assignment
    description TEXT NULL,                              -- Optional notes
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    INDEX idx_active (active),
    INDEX idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Example Data:**
```sql
INSERT INTO t_ops_shift_template (shift_name, shift_code, shift_start, shift_end, working_days, is_default, description) VALUES
('Rider Shift 1', 'rider_shift_1', '11:00:00', '17:00:00', '[1,3,4,5,6,7]', 0, 'Morning riders - Off on Tuesdays'),
('Rider Shift 2', 'rider_shift_2', '09:00:00', '16:00:00', '[1,2,3,4,5,6]', 0, 'Day riders - Off on Sundays'),
('Office Hours', 'office_hours', '09:00:00', '17:00:00', '[1,2,3,4,5]', 0, 'Standard office hours Mon-Fri'),
('Default Shift', 'default_shift', '09:00:00', '17:00:00', '[1,2,3,4,5,6]', 1, 'Fallback shift for unassigned users');
```

---

### 2. **New Table: `t_ops_user_shift_assignment`**
Maps users to shift templates (overrides role-based assignments).

```sql
CREATE TABLE t_ops_user_shift_assignment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    shift_template_id INT NOT NULL,
    effective_from DATE NULL,                          -- Optional: When this shift starts
    effective_to DATE NULL,                            -- Optional: When this shift ends (for temp assignments)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_template_id) REFERENCES t_ops_shift_template(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_shift (user_id, shift_template_id),
    INDEX idx_user (user_id),
    INDEX idx_shift (shift_template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3. **New Table: `t_ops_public_holidays`**
Centralized holiday calendar (applies to all shifts).

```sql
CREATE TABLE t_ops_public_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    holiday_name VARCHAR(200) NOT NULL,                -- e.g., "Independence Day"
    description TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    INDEX idx_date (holiday_date),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Example Data:**
```sql
INSERT INTO t_ops_public_holidays (holiday_date, holiday_name, description) VALUES
('2025-01-01', 'New Year\'s Day', 'Public Holiday'),
('2025-03-23', 'Pakistan Day', 'National Holiday'),
('2025-08-14', 'Independence Day', 'National Holiday');
```

---

### 4. **Migration Strategy (NO Breaking Changes)**

We will **NOT drop** `shift_start` and `shift_end` from `t_ops_rider_profile`. Instead:
- Keep existing columns for backward compatibility
- Gradually migrate data to new system
- Update code to check new system first, fall back to old columns

```sql
-- Step 1: Create new tables (above)
-- Step 2: Add migration helper column to track migrated users
ALTER TABLE t_ops_rider_profile 
ADD COLUMN migrated_to_shift_system TINYINT(1) DEFAULT 0 AFTER shift_end;
```

---

## Backend Implementation

### 1. **New Models**

#### `app/Models/Ops/ShiftTemplateModel.php`
```php
<?php
namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class ShiftTemplateModel extends Model
{
    protected $table = 't_ops_shift_template';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'shift_name', 'shift_code', 'shift_start', 'shift_end',
        'working_days', 'is_default', 'description', 'active',
        'created_by', 'updated_by'
    ];

    protected $casts = [
        'working_days' => 'array',  // Auto JSON encode/decode
        'is_default' => 'boolean',
        'active' => 'boolean'
    ];

    // Relationships
    public function userAssignments()
    {
        return $this->hasMany(UserShiftAssignmentModel::class, 'shift_template_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    // Helper methods
    public function getWorkingDaysArray(): array
    {
        return is_array($this->working_days) ? $this->working_days : [];
    }

    public function isWorkingDay(int $dayOfWeek): bool
    {
        // $dayOfWeek: 1=Mon, 7=Sun (ISO-8601)
        return in_array($dayOfWeek, $this->getWorkingDaysArray());
    }

    public static function getDefaultShift()
    {
        return self::where('is_default', 1)->where('active', 1)->first();
    }
}
```

#### `app/Models/Ops/UserShiftAssignmentModel.php`
```php
<?php
namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class UserShiftAssignmentModel extends Model
{
    protected $table = 't_ops_user_shift_assignment';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'user_id', 'shift_template_id', 'effective_from', 'effective_to',
        'created_by', 'updated_by'
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date'
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function shiftTemplate()
    {
        return $this->belongsTo(ShiftTemplateModel::class, 'shift_template_id');
    }

    // Check if assignment is currently effective
    public function isEffective(?string $date = null): bool
    {
        $checkDate = $date ? new \DateTime($date) : new \DateTime();
        
        $from = $this->effective_from ? new \DateTime($this->effective_from) : null;
        $to = $this->effective_to ? new \DateTime($this->effective_to) : null;

        if ($from && $checkDate < $from) return false;
        if ($to && $checkDate > $to) return false;

        return true;
    }
}
```

#### `app/Models/Ops/PublicHolidayModel.php`
```php
<?php
namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class PublicHolidayModel extends Model
{
    protected $table = 't_ops_public_holidays';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'holiday_date', 'holiday_name', 'description', 'is_active',
        'created_by', 'updated_by'
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_active' => 'boolean'
    ];

    // Get all active holidays in a date range
    public static function getHolidaysInRange(string $startDate, string $endDate): array
    {
        return self::where('is_active', 1)
            ->whereBetween('holiday_date', [$startDate, $endDate])
            ->pluck('holiday_date')
            ->map(fn($date) => $date->format('Y-m-d'))
            ->toArray();
    }

    // Check if a specific date is a holiday
    public static function isHoliday(string $date): bool
    {
        return self::where('is_active', 1)
            ->where('holiday_date', $date)
            ->exists();
    }
}
```

---

### 2. **Shift Resolution Service**

Create a helper service to resolve user shifts with proper fallback logic.

#### `app/Services/ShiftResolutionService.php`
```php
<?php
namespace App\Services;

use App\Models\Ops\ShiftTemplateModel;
use App\Models\Ops\UserShiftAssignmentModel;
use App\Models\Ops\PublicHolidayModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ShiftResolutionService
{
    /**
     * Get the effective shift for a user on a specific date
     * 
     * Resolution order:
     * 1. Check user_shift_assignment (explicit user assignment)
     * 2. Fall back to old rider_profile.shift_start/end if not migrated
     * 3. Fall back to default shift template
     * 
     * @param int $userId
     * @param string|null $date (Y-m-d format)
     * @return array ['shift_start' => '09:00', 'shift_end' => '17:00', 'working_days' => [1,2,3,4,5,6], 'shift_name' => '...']
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
                ->get()
                ->first(function($assignment) use ($date) {
                    return $assignment->isEffective($date);
                });

            if ($assignment && $assignment->shiftTemplate && $assignment->shiftTemplate->active) {
                $shift = $assignment->shiftTemplate;
                return [
                    'shift_start' => $shift->shift_start,
                    'shift_end' => $shift->shift_end,
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
                    'shift_start' => $riderProfile->shift_start,
                    'shift_end' => $riderProfile->shift_end,
                    'working_days' => [1,3,4,5,6,7], // Hardcoded: exclude Tuesday (legacy)
                    'shift_name' => 'Legacy Shift',
                    'shift_id' => null,
                    'source' => 'legacy_rider_profile'
                ];
            }

            // 3. Fall back to default shift
            $defaultShift = ShiftTemplateModel::getDefaultShift();
            if ($defaultShift) {
                return [
                    'shift_start' => $defaultShift->shift_start,
                    'shift_end' => $defaultShift->shift_end,
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
        // Get user's shift (we can use any date in range for shift lookup)
        $shift = $this->getUserShift($userId, $startDate);
        $workingDaysOfWeek = $shift['working_days'];
        
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
        
        return $workingDays;
    }

    /**
     * Clear cache for a user's shift (call after updating shifts)
     */
    public function clearUserShiftCache(int $userId): void
    {
        // Clear all cached shifts for this user (for different dates)
        // In production, you might want to use cache tags
        Cache::flush(); // For now, flush all (or implement more granular caching)
    }
}
```

---

## Frontend Implementation

### 1. **New Page: Shift Templates Management**

**Route:** `/shifts` → `resources/views/pages/shifts/index.blade.php`

**Features:**
- List all shift templates
- Create new shift template
- Edit existing shift template
- Set default shift
- Define working days (checkboxes for Mon-Sun)
- Delete shift template (if not in use)

### 2. **Enhanced: Manage Employee Shifts** (existing modal)

**Update:** `/attendance` → Manage Shifts button

**New Features:**
- Instead of time inputs, show **dropdown** to select shift template
- Option to "Use Legacy Times" for users not yet migrated
- Bulk assign: Select multiple users + assign shift template
- Show which shift is assigned to each user

### 3. **New Page: Public Holidays Management**

**Route:** `/holidays` → `resources/views/pages/holidays/index.blade.php`

**Features:**
- List all holidays
- Add new holiday (date picker + name)
- Import holidays from CSV
- Delete holiday

### 4. **Updated: Attendance Reports**

**Changes:**
- Remove localStorage working days config
- Use backend shift templates + holidays
- Show which shift was used for calculation

---

## Migration Path (Phased Rollout)

### **Phase 1: Database Setup** (Zero Impact)
1. Run SQL to create 3 new tables
2. Insert default shift templates
3. No code changes yet - new tables exist but unused

### **Phase 2: Backend Service** (Zero Impact)
1. Create new models
2. Create `ShiftResolutionService`
3. Service uses fallback logic - no breaking changes

### **Phase 3: Update Controllers** (Gradual Migration)
1. Update `AttendanceController::employeeDetails` to use `ShiftResolutionService`
2. Update `AttendanceController::data` to use new service
3. All queries use service with fallback - existing data still works

### **Phase 4: Frontend - New Management Pages** (Additive)
1. Create `/shifts` page for shift template management
2. Create `/holidays` page for holiday management
3. Add to sidebar menu
4. Old "Manage Shifts" modal still works (will be enhanced in Phase 5)

### **Phase 5: Frontend - Enhanced Shift Assignment** (Enhancement)
1. Update "Manage Shifts" modal to show shift template dropdown
2. Add bulk assign feature
3. Show migration status per user
4. Provide "Migrate All" button to convert old rider_profile shifts to templates

### **Phase 6: Reports Update** (Enhancement)
1. Update reports to use backend shift data
2. Remove localStorage logic
3. Show shift-based calculations

---

## Testing Checklist

### **Before Migration:**
- [ ] All users with `shift_start/shift_end` in `rider_profile` are displayed correctly
- [ ] Attendance calculations work correctly
- [ ] Late/overtime detection works

### **After Phase 1-2:**
- [ ] New tables created successfully
- [ ] Models can CRUD shift templates
- [ ] Service resolves to fallback shifts correctly

### **After Phase 3:**
- [ ] Attendance page shows correct shift times
- [ ] Working days calculation uses new logic
- [ ] Users without assignments fall back correctly

### **After Phase 4-5:**
- [ ] Shift templates can be created/edited/deleted
- [ ] Holidays can be managed
- [ ] Users can be assigned to shifts
- [ ] Bulk assignment works

### **After Phase 6:**
- [ ] Reports use new shift system
- [ ] All calculations match expected results

---

## SQL Scripts Needed

1. `01_create_shift_management_tables.sql` - Create 3 new tables
2. `02_seed_default_shift_templates.sql` - Insert default shifts
3. `03_migrate_rider_profiles_to_shifts.sql` - Data migration script (optional, can be done via UI)

---

## Backwards Compatibility

✅ **Old system continues to work** until explicitly migrated
✅ **No data loss** - old columns preserved
✅ **Gradual migration** - can migrate users one-by-one or in bulk
✅ **Fallback logic** ensures system always has valid shift data

---

## Benefits of New System

1. ✅ **Centralized Management** - Define shifts once, assign to many users
2. ✅ **Role-Based Flexibility** - Can assign same shift to all riders
3. ✅ **Holiday Integration** - Public holidays apply to everyone automatically
4. ✅ **User-Specific Overrides** - Still can assign custom shift to individual user
5. ✅ **Historical Tracking** - `effective_from/to` allows tracking shift changes over time
6. ✅ **Reports Accuracy** - Working days calculation becomes accurate and consistent
7. ✅ **Scalability** - Easy to add new shift patterns as business grows

---

## Next Steps - Your Decision

**Questions for you:**

1. **Approval?** Does this approach make sense for your needs?
2. **Priorities?** Should I start with Phase 1 (database) immediately?
3. **Default Shifts?** What shift templates should I seed by default?
   - Rider Shift 1: 11:00-17:00, Off: Tuesday
   - Rider Shift 2: ?
   - Office Shift: 09:00-17:00, Off: Sunday
4. **Holidays?** Should I pre-populate any Pakistani public holidays for 2025?
5. **Working Days?** Confirm: Most shifts are 6 days/week, right? Which day is typically off?

Once you confirm, I'll proceed with implementation! 🚀



