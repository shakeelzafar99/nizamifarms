# Final Fix: Payment Method & Petrol Category
**Date:** October 27, 2025

## ✅ Issues Fixed

### 1. **Payment Method Change - Missing Import** ✅
**Error**: `Class "App\Http\Controllers\API\OrderModel" not found`

**Root Cause**: The `RiderController` was using `OrderModel` but didn't have the `use` statement to import it.

**Solution**: Added the missing import statement.

**File Modified**: `app/Http/Controllers/API/RiderController.php`

```php
use App\Models\CRM\OrderModel;
```

---

### 2. **Petrol Category Display** ✅
**Issue**: Category picker was showing "Expense" or "Expense Reimbursement" instead of "Petrol" directly.

**Solution**: Updated the mobile app to display "Petrol" in the category picker for expense requests, matching the rider's mental model.

**File Modified**: `src/screens/RequestsScreen.js`

```javascript
{categories.map(c => {
  // Show "Petrol" instead of "Expense" or "Expense Reimbursement"
  const displayName = c.category_code === 'expense' ? 'Petrol' : c.category_name;
  return <Picker.Item label={displayName} value={c.id} key={c.id} />;
})}
```

---

## 📋 How It Works Now

### Request Categories (Mobile App)

The mobile app now shows **3 categories** in the picker:

1. **Petrol** (instead of "Expense" or "Expense Reimbursement")
   - Backend: `category_code: 'expense'`, `expense_category: 'Petrol'`
   - Goes to `exp_fund` account
   
2. **Salary Advance**
   - Backend: `category_code: 'salary_advance'`
   - Deducted from future salary
   
3. **Leave**
   - Backend: `category_code: 'leave'`
   - No financial impact

### Webapp vs Mobile Display

| Component | Webapp | Mobile App |
|-----------|--------|------------|
| **Category** | "Expense Reimbursement" | "Petrol" |
| **Expense Type** | Dropdown: Petrol, Fuel, etc. | Fixed to "Petrol" |
| **Reason** | Flexible for different expense types | Simplified for riders (only petrol) |

**Why Different?**
- Webapp: Used by managers/admins who handle various expense types
- Mobile: Used by riders who primarily claim petrol expenses
- Simpler UX for riders = faster request creation

---

## 🔄 Complete Flow

### Creating a Petrol Request (Mobile)

1. **Rider Opens App**
   - Taps "Requests" tab
   - Taps "New" button

2. **Fills Form**
   - Category: "Petrol" (automatically maps to expense reimbursement)
   - Amount: 500
   - Notes: "Deliveries for Oct 27"

3. **Submits**
   ```javascript
   const payload = {
     category_id: selectedCategory?.id, // expense category
     title: 'Expense',
     description: notes,
     amount: 500,
     expense_category: 'Petrol' // Fixed value for riders
   };
   ```

4. **Backend Processing**
   ```php
   $newRequest = RequestModel::create([
       'category_id' => $category->id, // expense category
       'requester_user_id' => $user->id,
       'title' => 'Expense',
       'description' => $notes,
       'amount' => 500,
       'expense_category' => 'Petrol',
       'status' => 'pending',
   ]);
   ```

5. **Approval Flow**
   - Goes to Level 1 approver (based on category config)
   - If approved, funds disbursed from `exp_fund`
   - Ledger entry created linking to request

6. **Display in List**
   - Shows as "Petrol" (not "Expense")
   - Shows amount: "Rs. 500"
   - Shows status: "Pending"

---

## 🧪 Testing Instructions

### Test 1: Payment Method Change
1. Open mobile app
2. Navigate to a **non-delivered** order
3. Tap the payment badge (Cash/Online)
4. Confirm change
5. ✅ Should work without "OrderModel not found" error
6. ✅ Payment method should update

### Test 2: Create Petrol Request
1. Open "Requests" tab
2. Tap "New"
3. ✅ Category dropdown should show:
   - **Petrol** (not "Expense" or "Expense Reimbursement")
   - Salary Advance
   - Leave
4. Select "Petrol"
5. Enter amount: 500
6. Enter notes: "Deliveries"
7. Tap "Save"
8. ✅ Request created successfully

### Test 3: Verify Backend
```sql
-- Check the created request
SELECT 
    id,
    request_number,
    category_id,
    title,
    amount,
    expense_category,
    status
FROM t_req_master 
WHERE requester_user_id = [RIDER_USER_ID]
ORDER BY id DESC 
LIMIT 1;
```

Expected:
- `category_id`: Points to 'expense' category
- `expense_category`: 'Petrol'
- `status`: 'pending'

---

## 📂 Files Modified

### Backend
**`app/Http/Controllers/API/RiderController.php`**
- Added missing import: `use App\Models\CRM\OrderModel;`

### Mobile
**`src/screens/RequestsScreen.js`**
- Updated category picker to show "Petrol" instead of "Expense"
- Display logic already correct (shows "Petrol" in list)

---

## ✅ Summary

Both issues are now fixed:

1. **Payment Method Change**:
   - ✅ Missing `OrderModel` import added
   - ✅ API endpoint now works correctly
   - ✅ Riders can change payment method before delivery

2. **Petrol Category**:
   - ✅ Shows "Petrol" in category picker (not "Expense")
   - ✅ Backend correctly stores `expense_category: 'Petrol'`
   - ✅ Goes to `exp_fund` with proper approval flow
   - ✅ Matches webapp functionality (just simpler UX)

**Just reload Metro** (press `r`) to test! 🎉

No rebuild needed - JavaScript changes only.

