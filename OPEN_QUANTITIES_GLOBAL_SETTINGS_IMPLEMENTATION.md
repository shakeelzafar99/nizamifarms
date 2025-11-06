# Open Order Quantities - Global Settings Implementation Guide
## November 5, 2025

## Overview
This implementation changes the Open Order Quantities settings from per-user (localStorage) to global settings that apply to all users, with editing restricted to the Taimur role only.

## Database Changes

### New Table: `t_crm_open_quantities_settings`
```sql
CREATE TABLE `t_crm_open_quantities_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `setting_type` ENUM('hierarchy', 'status_filter', 'other') DEFAULT 'other',
    `updated_by_user_id` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
)
```

**Default Settings:**
- `hierarchy_levels`: `["product_type", "product_name", "orders"]`
- `excluded_statuses`: `["delivered", "completed", "cancelled", "refunded"]`

### Migration File
`database/migrations/create_open_quantities_settings_table_nov05_2025.sql`

**To Run:**
```bash
# Run the SQL file manually in your database
mysql -u your_user -p nizamifarms_db < database/migrations/create_open_quantities_settings_table_nov05_2025.sql
```

## Backend API Changes

### New Endpoints

#### 1. Get Settings
**Endpoint:** `GET /orders/open-quantities/settings`
**Response:**
```json
{
  "success": true,
  "settings": {
    "hierarchy_levels": ["product_type", "product_name", "orders"],
    "excluded_statuses": ["delivered", "completed", "cancelled", "refunded"]
  },
  "can_edit": true,  // true only for Taimur role (id=12)
  "updated_at": "2025-11-05 10:30:00",
  "updated_by": 12
}
```

#### 2. Save Settings
**Endpoint:** `POST /orders/open-quantities/settings`
**Permissions:** Only Taimur role (id=12) can save
**Request:**
```json
{
  "hierarchy_levels": ["product_type", "attribute_1", "product_name", "orders"],
  "excluded_statuses": ["delivered", "completed", "cancelled"]
}
```

### Updated Methods

#### `OrderController::openQuantitiesData()`
- Now reads `hierarchy_levels` from database instead of request parameter
- Now reads `excluded_statuses` from database instead of request parameter
- Settings are global - same for all users

## Frontend Changes Needed

### 1. Remove localStorage Usage
**Replace:**
```javascript
localStorage.getItem('openQtyHierarchy')
localStorage.setItem('openQtyHierarchy', ...)
localStorage.getItem('openQtyExcludedStatuses')
localStorage.setItem('openQtyExcludedStatuses', ...)
```

**With:**
API calls to `/orders/open-quantities/settings`

### 2. Load Settings on Page Load
```javascript
async function loadGlobalSettings() {
    const response = await fetch('/orders/open-quantities/settings');
    const data = await response.json();
    
    if (data.success) {
        window.openQtyState.hierarchy = data.settings.hierarchy_levels;
        window.openQtyState.excludedStatuses = data.settings.excluded_statuses;
        window.canEditSettings = data.can_edit;
        
        // Disable/enable UI based on permissions
        updateUIPermissions();
    }
}
```

### 3. Save Settings (Taimur Role Only)
```javascript
async function saveGlobalSettings() {
    if (!window.canEditSettings) {
        alert('Only Taimur role can modify these settings.');
        return;
    }
    
    const response = await fetch('/orders/open-quantities/settings', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            hierarchy_levels: window.openQtyState.hierarchy,
            excluded_statuses: window.openQtyState.excludedStatuses
        })
    });
    
    const data = await response.json();
    if (data.success) {
        alert('Settings saved successfully for all users!');
    }
}
```

### 4. Disable Settings UI for Non-Taimur Users
```javascript
function updateUIPermissions() {
    const settingsButton = document.getElementById('settings-button');
    const addLevelButton = document.querySelector('.add-level-btn');
    const hierarchyPills = document.querySelectorAll('.hierarchy-pill');
    
    if (!window.canEditSettings) {
        // Disable all editing capabilities
        if (settingsButton) settingsButton.disabled = true;
        if (addLevelButton) addLevelButton.style.display = 'none';
        hierarchyPills.forEach(pill => {
            pill.style.cursor = 'not-allowed';
            pill.draggable = false;
            const removeBtn = pill.querySelector('.pill-remove');
            if (removeBtn) removeBtn.style.display = 'none';
        });
    }
}
```

### 5. Update Column Display - Show Lean/Non-Lean Within Columns

**OLD:**
```javascript
<th>Quantity</th>
<th>Lean / Non-Lean</th>
<th>Processing</th>
<th>Preparing</th>
```

**NEW:**
```javascript
<th>Quantity (L/NL)</th>
<th>Processing (L/NL)</th>
<th>Prepared (L/NL)</th>
```

**Display Format:**
```javascript
// In each cell, show: Total (Lean/Non-Lean)
const totalQty = item.total_quantity;
const leanQty = item.lean_quantity || 0;
const nonLeanQty = item.non_lean_quantity || 0;

// For Quantity column:
<td>
    <strong>${totalQty}</strong>
    <br><span style="font-size: 11px; color: #059669;">${leanQty}</span> / 
    <span style="font-size: 11px; color: #dc2626;">${nonLeanQty}</span>
</td>

// For Processing column:
const procQty = item.processing_quantity || 0;
const procLean = item.processing_lean_quantity || 0;
const procNonLean = item.processing_non_lean_quantity || 0;

<td>
    <strong>${procQty}</strong>
    <br><span style="font-size: 11px; color: #059669;">${procLean}</span> / 
    <span style="font-size: 11px; color: #dc2626;">${procNonLean}</span>
</td>

// Similar for Prepared column
```

## Mobile App Changes

### API Endpoint Updates

#### Mobile API Controller: `RiderController::getOpenOrderQuantities()`
**File:** `app/Http/Controllers/API/RiderController.php`

**Changes Needed:**
1. Fetch global settings from database
2. Add lean/non-lean calculations
3. Return settings to mobile app

```php
public function getOpenOrderQuantities(Request $request) {
    // Get global settings
    $hierarchySetting = \DB::table('t_crm_open_quantities_settings')
        ->where('setting_key', 'hierarchy_levels')
        ->first();
    $hierarchy = $hierarchySetting ? json_decode($hierarchySetting->setting_value, true) : ['product_type', 'product_name'];
    
    $statusSetting = \DB::table('t_crm_open_quantities_settings')
        ->where('setting_key', 'excluded_statuses')
        ->first();
    $excludedStatuses = $statusSetting ? json_decode($statusSetting->setting_value, true) : ['delivered', 'completed', 'cancelled', 'refunded'];
    
    // Add lean/non-lean calculations in SELECT
    \DB::raw('SUM(CASE WHEN LOWER(li.name) LIKE "%lean%" THEN li.quantity ELSE 0 END) as lean_quantity'),
    \DB::raw('SUM(CASE WHEN LOWER(li.name) NOT LIKE "%lean%" THEN li.quantity ELSE 0 END) as non_lean_quantity'),
    \DB::raw('SUM(CASE WHEN o.order_status = "processing" THEN li.quantity ELSE 0 END) as processing_quantity'),
    \DB::raw('SUM(CASE WHEN li.preparation_status = "preparing" THEN li.quantity ELSE 0 END) as prepared_quantity'),
    
    // Return settings along with data
    return response()->json([
        'success' => true,
        'items' => $results,
        'settings' => [
            'hierarchy_levels' => $hierarchy,
            'excluded_statuses' => $excludedStatuses
        ]
    ]);
}
```

### Mobile App UI Updates

#### File: `src/screens/StoreOpenQuantitiesScreen.js`

**Changes:**
1. Fetch and respect global settings
2. Display lean/non-lean split in each column
3. Rename "Preparing" to "Prepared"

```javascript
// Fetch settings and data together
const response = await api.get('/rider/store/open-quantities', {
  params: {
    level,
    filters: JSON.stringify(filters),
  },
});

if (response.data.success) {
  setItems(response.data.items || []);
  // Update hierarchy from server (global settings)
  if (response.data.settings) {
    setGlobalHierarchy(response.data.settings.hierarchy_levels);
  }
}

// Display format (example):
<View style={styles.row}>
  <Text style={styles.quantityTotal}>{item.total_quantity}</Text>
  <Text style={styles.leanNonLean}>
    <Text style={styles.lean}>{item.lean_quantity}</Text> / 
    <Text style={styles.nonLean}>{item.non_lean_quantity}</Text>
  </Text>
</View>
```

## Testing Checklist

### Database
- [ ] Run migration script
- [ ] Verify table created
- [ ] Verify default settings inserted

### Backend API
- [ ] Test GET /orders/open-quantities/settings (as Taimur role)
- [ ] Test GET /orders/open-quantities/settings (as non-Taimur role) - should show can_edit: false
- [ ] Test POST /orders/open-quantities/settings (as Taimur role) - should save
- [ ] Test POST /orders/open-quantities/settings (as non-Taimur role) - should fail with 403
- [ ] Test openQuantitiesData uses global settings

### Web Frontend
- [ ] Settings load from API on page load
- [ ] Non-Taimur users cannot edit settings
- [ ] Taimur role can edit and save settings
- [ ] Settings persist across users
- [ ] Lean/Non-Lean shown within each column
- [ ] "Preparing" renamed to "Prepared"
- [ ] All hierarchy levels work correctly

### Mobile App
- [ ] Mobile app respects global hierarchy settings
- [ ] Mobile app respects global status filter settings
- [ ] Lean/Non-Lean data displayed correctly
- [ ] "Prepared" column shows correct data

## Rollback Plan

If issues occur:
1. Drop the settings table: `DROP TABLE t_crm_open_quantities_settings;`
2. Revert code changes to use localStorage
3. Remove new API endpoints from routes

## Notes

- **Taimur Role ID**: 12
- **Permission Check**: `$user->roles()->where('id', 12)->exists()`
- **Global Settings**: Same for all users across web and mobile
- **Lean Detection**: Case-insensitive, checks if product name contains "lean"

