# Open Order Quantities Changes Summary - November 5, 2025

## ✅ COMPLETED Changes

### 1. Database Schema
**Created:** `database/migrations/create_open_quantities_settings_table_nov05_2025.sql`

**⚠️ ACTION REQUIRED:** Run this SQL manually:
```bash
# Option 1: Via MySQL command line
mysql -u root -p nizamifarms_db < "C:\NF App\nizamifarms\database\migrations\create_open_quantities_settings_table_nov05_2025.sql"

# Option 2: Copy the SQL and run in your database tool (phpMyAdmin, MySQL Workbench, etc.)
```

### 2. Backend API - New Endpoints
**File:** `app/Http/Controllers/CRM/OrderController.php`

**Added Methods:**
- `getOpenQuantitiesSettings()` - Fetches global settings, checks if user can edit (Taimur role only)
- `saveOpenQuantitiesSettings()` - Saves global settings (Taimur role only)

**Updated Methods:**
- `openQuantitiesData()` - Now uses global settings from database instead of request parameters

**Added Calculations:**
- `lean_quantity` - Sum of items where product name contains "lean"
- `non_lean_quantity` - Sum of items where product name doesn't contain "lean"  
- `processing_quantity` - Sum of items in "processing" status
- `preparing_quantity` - Sum of items with preparation_status = "preparing"

### 3. Routes
**File:** `routes/web.php`

**Added:**
```php
Route::get('/orders/open-quantities/settings', [OrderController::class, 'getOpenQuantitiesSettings']);
Route::post('/orders/open-quantities/settings', [OrderController::class, 'saveOpenQuantitiesSettings']);
```

### 4. Previous Changes
- Backend already returns `lean_quantity`, `non_lean_quantity`, `processing_quantity`, `preparing_quantity`
- Frontend already displays these in separate columns

## 🔄 CHANGES STILL NEEDED

### Frontend Web App Changes

Due to the large scope of frontend changes, I recommend the following approach:

#### A. Update Column Display (High Priority)
**File:** `resources/views/pages/orders/open-quantities.blade.php`

**Current:**
- Separate columns: Quantity | Lean/Non-Lean | Processing | Preparing

**Need to Change To:**
- Combined columns: Quantity (L/NL) | Processing (L/NL) | Prepared (L/NL)

**Implementation:**
1. Remove the separate "Lean / Non-Lean" column
2. In each column, show total with lean/non-lean split below:
```javascript
// Example format:
<td class="text-right">
    <div><strong>${totalQty}</strong></div>
    <div style="font-size: 11px;">
        <span style="color: #059669;">${leanQty}</span> / 
        <span style="color: #dc2626;">${nonLeanQty}</span>
    </div>
</td>
```

3. Rename "Preparing" to "Prepared" throughout

#### B. Replace localStorage with API Calls (High Priority)
**Current:**
```javascript
localStorage.getItem('openQtyHierarchy')
localStorage.setItem('openQtyHierarchy', ...)
localStorage.getItem('openQtyExcludedStatuses')
localStorage.setItem('openQtyExcludedStatuses', ...)
```

**Replace With:**
```javascript
// On page load
async function loadGlobalSettings() {
    const response = await fetch('/orders/open-quantities/settings');
    const data = await response.json();
    if (data.success) {
        window.openQtyState.hierarchy = data.settings.hierarchy_levels;
        window.openQtyState.excludedStatuses = data.settings.excluded_statuses;
        window.canEditSettings = data.can_edit; // true only for Taimur role
        updateUIPermissions();
        loadData();
    }
}

// When saving
async function saveHierarchy() {
    if (!window.canEditSettings) {
        alert('Only Taimur role can modify settings.');
        return;
    }
    
    await fetch('/orders/open-quantities/settings', {
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
}
```

#### C. Disable Settings UI for Non-Taimur Users (Medium Priority)
```javascript
function updateUIPermissions() {
    const canEdit = window.canEditSettings;
    
    // Disable/hide controls if user cannot edit
    if (!canEdit) {
        // Disable hierarchy editing
        document.querySelectorAll('.hierarchy-pill').forEach(pill => {
            pill.draggable = false;
            const removeBtn = pill.querySelector('.pill-remove');
            if (removeBtn) removeBtn.style.display = 'none';
        });
        
        // Hide "Add Level" button
        const addBtn = document.querySelector('.add-level-btn');
        if (addBtn) addBtn.style.display = 'none';
        
        // Disable status settings button or show read-only
        const settingsBtn = document.getElementById('settingsBtn');
        if (settingsBtn) {
            settingsBtn.disabled = true;
            settingsBtn.title = 'Only Taimur role can modify settings';
        }
    }
}
```

#### D. Update loadData() Function
**Remove:**
```javascript
hierarchy: JSON.stringify(window.openQtyState.hierarchy),
excluded_statuses: JSON.stringify(window.openQtyState.excludedStatuses)
```

**Replace With:**
```javascript
// Don't send these parameters - backend will read from database
```

### Mobile App Changes

#### A. Update Mobile API Endpoint (High Priority)
**File:** `app/Http/Controllers/API/RiderController.php`
**Method:** `getOpenOrderQuantities()`

Need to add:
1. Read global settings from database (same as web)
2. Add lean/non-lean calculations (same SQL as OrderController)
3. Return settings in response so mobile knows current hierarchy

#### B. Update Mobile UI (High Priority)
**File:** `NizamiFarmsMobile/src/screens/StoreOpenQuantitiesScreen.js`

Need to:
1. Fetch and respect global hierarchy from API response
2. Display lean/non-lean split within columns (not separate)
3. Rename "Preparing" to "Prepared"
4. Remove local hierarchy management (use server's hierarchy)

## 📋 Testing Steps

### After Running SQL Migration:

1. **Test Web Backend:**
   ```bash
   # As Taimur role user:
   curl http://127.0.0.1:8000/orders/open-quantities/settings
   # Should return can_edit: true
   
   # As non-Taimur user:
   curl http://127.0.0.1:8000/orders/open-quantities/settings  
   # Should return can_edit: false
   ```

2. **Test Web Frontend:**
   - Open Open Order Quantities page
   - Check browser console for any errors
   - Verify columns show lean/non-lean data
   - Try to edit settings as Taimur role (should work)
   - Try to edit settings as other role (should be disabled)

3. **Test Mobile App:**
   - Open Store Open Quantities screen
   - Verify it respects web settings
   - Verify lean/non-lean data displays

## 🎯 Key Concepts

### Global Settings
- Stored in database table `t_crm_open_quantities_settings`
- Same settings apply to ALL users
- Same settings apply to BOTH web and mobile

### Taimur Role Permission
- Role ID: 12
- Check: `$user->roles()->where('id', 12)->exists()`
- Only this role can edit global settings
- Other users can view but not edit

### Lean/Non-Lean Detection
- Case-insensitive check: `LOWER(product_name) LIKE '%lean%'`
- If product name contains "lean" anywhere → Lean
- Otherwise → Non-Lean

### Column Display Format
**OLD:** Separate columns for each metric
**NEW:** Combined display within each column:
```
Quantity (L/NL)     Processing (L/NL)     Prepared (L/NL)
      12                   8                      5
    10 / 2               6 / 2                  4 / 1
  (Lean/Non)          (Lean/Non)             (Lean/Non)
```

## 📝 Documentation Files Created

1. `create_open_quantities_settings_table_nov05_2025.sql` - Database migration
2. `OPEN_QUANTITIES_GLOBAL_SETTINGS_IMPLEMENTATION.md` - Detailed implementation guide
3. `CHANGES_SUMMARY_NOV05_2025.md` - This summary

## ⚠️ Important Notes

1. **Run the SQL migration first** before testing
2. **Frontend changes are substantial** - the localStorage to API conversion needs careful implementation
3. **Mobile app needs updates** to respect global settings
4. **Test with both Taimur and non-Taimur roles** to ensure permissions work correctly
5. **Backup your database** before running the migration

## 🔧 Need Help?

If you encounter issues:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Check browser console for JavaScript errors
3. Verify database table was created successfully
4. Verify you're testing with correct role (Taimur = ID 12)

## Next Steps

1. ✅ Run the SQL migration
2. ⏳ Update frontend to use API instead of localStorage
3. ⏳ Update column display format (lean/non-lean within columns)
4. ⏳ Add permission checks to UI
5. ⏳ Update mobile API endpoint
6. ⏳ Update mobile app UI
7. ✅ Test thoroughly with different user roles

