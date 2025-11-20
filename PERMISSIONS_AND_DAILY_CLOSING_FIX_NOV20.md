# Permissions & Daily Closing Fix - November 20, 2025

## Issues Fixed

### 1. Incorrect Permissions SQL
**Problem**: The SQL query was using incorrect table and column names.

**Incorrect**:
- Table: `t_sys_mobile_permissions` (wrong - has plural 's')
- Column: `permission_key` (wrong)
- Mapping column: `mobile_permission_id` (wrong)

**Correct** (from table structure):
- Table: `t_sys_mobile_permission` (singular, no 's')
- Column: `permission_code` (not permission_key)
- Column: `permission_group` (required field)
- Column: `display_order` (for ordering in UI)
- Mapping column: `permission_id` (not mobile_permission_id)

### 2. New Correct SQL File
**File**: `database/migrations/add_mobile_permissions_overall_ledger_daily_closing_nov20_2025.sql`

This file now correctly:
- Uses `t_sys_mobile_permission` (singular)
- Uses `permission_code` instead of `permission_key`
- Includes `permission_group` ('finance')
- Includes `display_order` for proper ordering
- Uses `permission_id` in mapping table
- Adds both Overall Ledger AND Daily Closing permissions
- Uses `ON DUPLICATE KEY UPDATE` to prevent errors if run multiple times
- Assigns to both Super Admin (role_id = 1) and Admin (role_id = 2)

## Daily Closing Implementation Plan

### Overview
I've created a comprehensive implementation plan document: `DAILY_CLOSING_MOBILE_IMPLEMENTATION_PLAN.md`

### Key Business Rules Identified

#### Invoice States
- **OPEN**: No settlement yet (`settled_amount = 0`)
- **PARTIAL**: Partially settled (`settled_amount > 0` but not full)
- **PENDING**: Settlement deposits awaiting approval
- **SETTLED**: Fully settled invoices

#### KPI Cards (6 cards total)

**Row 1 - Invoice Status:**
1. 🔴 **OPEN** - Count and amount of unsettled invoices
2. 🟡 **PARTIAL** - Count and amount of partially settled invoices
3. ⏳ **PENDING** - Count and amount of settlements awaiting approval
4. 📊 **TOTAL** - Combined OPEN + PARTIAL

**Row 2 - Expense Management:**
5. ⏳ **PENDING (Approvals)** - Expense requests awaiting approval
6. 💸 **SHORT CASH** - Approved expenses from rider balance not yet settled

#### Data Grouping
- Invoices grouped by rider
- Each rider shows:
  - Account name and code
  - Total outstanding amount
  - Invoice count
  - List of invoices with details

#### Filtering
- By Rider (dropdown)
- By Status (open/partial/settled/all)
- By Date Range (from/to)

#### Approvals
- Approve/Reject buttons for pending settlements
- Uses existing `LedgerController::approveTransaction()` and `rejectTransaction()`
- Approvals update invoice settlement status
- Account balances are updated automatically

### Technical Approach

#### Backend API
**Endpoint**: `GET /api/rider/daily-closing`

**Parameters**:
- `status`: 'all', 'open', 'partial', 'settled'
- `rider`: account_id or 'all'
- `date_from`: Y-m-d format
- `date_to`: Y-m-d format

**Reuses Logic From**:
- `EmployeeCashController::allOutstandingInvoices()` - main calculation logic
- `LedgerController::approveTransaction()` - for approvals
- `LedgerController::rejectTransaction()` - for rejections

#### Mobile App
**Screen**: `src/screens/DailyClosingScreen.js`

**Features**:
- Header with filters (rider dropdown, date range)
- 2 rows of KPI cards (horizontal scroll)
- Rider-grouped invoice sections (vertical scroll)
- Pending settlements section (collapsible)
- Pull-to-refresh
- Approve/reject actions for pending settlements

## Next Steps

### 1. Run the SQL Migration ✅
Execute: `database/migrations/add_mobile_permissions_overall_ledger_daily_closing_nov20_2025.sql`

This will:
- Add `view_overall_ledger` permission
- Add `view_daily_closing` permission
- Assign both to Super Admin and Admin roles

### 2. Implement Daily Closing API (Backend)
- Create `RiderController::getDailyClosing()` method
- Create `RiderController::approveDailyClosingSettlement()` method  
- Create `RiderController::rejectDailyClosingSettlement()` method
- Add routes to `routes/api.php`

### 3. Implement Daily Closing Screen (Mobile)
- Create `src/screens/DailyClosingScreen.js`
- Add to navigation stack
- Add menu item in sidebar
- Implement KPI cards
- Implement invoice listing
- Implement pending settlements
- Implement approve/reject actions
- Add pull-to-refresh

### 4. Testing
- Test all filters (rider, status, date)
- Test approvals and rejections
- Test data accuracy vs web app
- Test permission checks
- Test pull-to-refresh

## Files Created/Modified

### Created
1. `database/migrations/add_mobile_permissions_overall_ledger_daily_closing_nov20_2025.sql` ✅
2. `DAILY_CLOSING_MOBILE_IMPLEMENTATION_PLAN.md` ✅
3. `PERMISSIONS_AND_DAILY_CLOSING_FIX_NOV20.md` ✅ (this file)

### Modified
1. `database/migrations/add_overall_ledger_mobile_permission_nov20_2025.sql` ✅ (marked as deprecated)

### To Be Created (Daily Closing Implementation)
1. `DailyClosingScreen.js` (mobile app)
2. API methods in `RiderController.php`
3. Routes in `routes/api.php`
4. Navigation updates in mobile app

## Summary

The permissions SQL has been corrected with proper table and column names. A comprehensive implementation plan for Daily Closing has been created, which mirrors the web app's implementation exactly. The plan includes all business rules, technical requirements, API structure, and mobile UI specifications.

The Daily Closing feature will provide mobile users with the same powerful invoice settlement and approval workflow that exists in the web app.

