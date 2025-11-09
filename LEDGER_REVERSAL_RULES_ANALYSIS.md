# 📊 Ledger Reversal & Integration Rules - Current Implementation Analysis

**Date:** November 9, 2025  
**Purpose:** Comprehensive analysis of ledger posting, reversal rules, and integration points for order management

---

## 🎯 **EXECUTIVE SUMMARY**

### Current State:
- ✅ **Ledger posting** works when order status → `delivered`
- ✅ **Amount changes** trigger ledger adjustment requests (L1→L2 approval)
- ✅ **Payment method changes** reverse old ledger + create new one
- ⚠️ **Rider changes** do NOT trigger ledger updates (MISSING)
- ⚠️ **Order cancellation** does NOT reverse ledger (MISSING)
- ⚠️ **Quick edit buttons** work on ALL orders (needs restriction for delivered)

---

## 📋 **CURRENT IMPLEMENTATION - DETAILED BREAKDOWN**

### **1. AUTOMATIC LEDGER POSTING (When Order → Delivered)**

**Trigger Point:**  
`app/Models/CRM/OrderModel.php` → `changeStatus()` method (lines 986-1014)

**Logic:**
```php
if ($statusCode === 'delivered') {
    $ledgerService = new \App\Services\FIN\LedgerPostingService();
    $result = $ledgerService->postInvoiceFromOrder($this);
}
```

**Posting Rules** (`app/Services/FIN/LedgerPostingService.php`):

| Payment Method | Destination Account | Mode | Approval Status | Balance Update |
|----------------|---------------------|------|-----------------|----------------|
| `online`, `Online`, `bank_transfer`, `card`, `online_payment` | Online Bank Account (from config) | `online` | `pending` | ❌ Not updated until approved |
| `cash`, `COD`, or any other | Rider's Cash Account (`t_fin_account` - employee cash) | `cash` | `approved` | ✅ Updated immediately |

**Key Points:**
- ✅ Creates `t_fin_ledger` entry
- ✅ Sets `order.ledger_transaction_id`
- ✅ For **cash**: Updates rider's cash account balance immediately
- ✅ For **online**: Requires L1/L2 approval before balance update
- ⚠️ **Rider MUST be assigned** for cash orders (creates action item if missing)

---

### **2. AMOUNT CHANGE AFTER DELIVERY**

**Trigger Point:**  
`app/Http/Controllers/CRM/OrderController.php` → `update()` method (lines 533-580)

**Conditions:**
```php
if ($order->ledger_transaction_id && !$isWebhookUpdate) {
    $oldAmount = $ledger->amount;
    $newAmount = $validated['total_price'];
    
    if (abs($oldAmount - $newAmount) > 0.01) {
        // Create ledger adjustment request
    }
}
```

**What Happens:**
1. ✅ Detects amount change (> 1 cent difference)
2. ✅ Creates `RequestModel` with category `invoice_adjustment`
3. ✅ Requires L1→L2 approval (configurable)
4. ✅ Order updates immediately
5. ✅ Ledger updates ONLY after approval
6. ✅ Frontend shows confirmation dialog with old/new amounts

**Frontend Confirmation** (`resources/views/pages/orders/index.blade.php` lines 4252-4279):
```javascript
if (window.currentOrder && window.currentOrder.ledger_transaction_id) {
    const difference = newTotal - oldTotal;
    const confirmed = confirm(
        `⚠️ LEDGER ADJUSTMENT REQUIRED\n\n` +
        `Old Amount: Rs. ${oldTotal.toFixed(2)}\n` +
        `New Amount: Rs. ${newTotal.toFixed(2)}\n` +
        `Difference: ${difference >= 0 ? '+' : ''}Rs. ${difference.toFixed(2)}\n\n` +
        `Do you want to proceed?`
    );
}
```

---

### **3. PAYMENT METHOD CHANGE AFTER DELIVERY**

**Trigger Point:**  
`app/Http/Controllers/CRM/OrderController.php` → `update()` method (lines 613-680)

**Conditions:**
```php
if ($order->ledger_transaction_id && !$isWebhookUpdate) {
    if ($oldPaymentMethod !== $newPaymentMethod) {
        // Handle payment method change
    }
}
```

**Validation Rules:**
- ❌ **BLOCKED** if ledger is `settled`
- ❌ **BLOCKED** if ledger has `partial` settlement or `settled_amount > 0`
- ✅ **ALLOWED** if ledger is `open` (not settled)

**What Happens** (via `handlePaymentMethodChange()` lines 2140-2191):
1. ✅ Reverses old ledger entry (marks as `reversed`)
2. ✅ Reverses account balances (if old entry was approved)
3. ✅ Clears `order.ledger_transaction_id`
4. ✅ Creates NEW ledger entry with new payment method
5. ✅ Updates `order.ledger_transaction_id` to new entry
6. ✅ All in DB transaction (rollback on failure)

**Reversal Logic** (`reverseLedgerEntry()` lines 2200-2247):
```php
// Mark as reversed
$ledger->approval_status = LedgerModel::STATUS_REVERSED;

// Reverse balances ONLY if approved
if ($wasApproved) {
    $fromAccount->current_balance += $ledger->amount; // Add back
    $toAccount->current_balance -= $ledger->amount;   // Subtract back
}
```

---

### **4. RIDER ASSIGNMENT**

**Trigger Point:**  
`app/Http/Controllers/CRM/OrderRiderController.php` → `assign()` method (lines 11-32)

**What Happens:**
1. ✅ Calls `OrderModel::assignRider()`
2. ✅ Creates entry in `t_ops_order_rider_history`
3. ✅ Updates `order.assigned_rider_user_id`
4. ✅ Demotes previous rider assignment (sets `is_current = 0`, `unassigned_at`)
5. ❌ **DOES NOT** check if order has ledger entry
6. ❌ **DOES NOT** update ledger destination account

**Current Limitation:**
- If order is delivered with Rider A → ledger posts to Rider A's cash account
- If rider changes to Rider B → ledger STILL points to Rider A's account
- **Result:** Rider B delivers but Rider A's account gets credited

---

### **5. ORDER CANCELLATION**

**Current State:**
- ✅ Status can be changed to `cancelled` via status change
- ❌ **NO automatic ledger reversal**
- ❌ **NO validation** to check if order has ledger entry
- ❌ **NO prompt** to user about ledger implications

**What SHOULD Happen:**
- If order is `delivered` (has ledger entry) and not settled
- Changing to `cancelled` should reverse the ledger entry
- Should show confirmation to user

---

### **6. QUICK EDIT FUNCTIONALITY**

**Location:**  
`resources/views/pages/orders/index.blade.php`

**Current Buttons:**
- ✏️ **Edit Order** (line 6823) → Opens full edit modal → Calls `editOrderDetails()`
- 🔄 **Quick Status Change** (inline) → Calls `quickChangeStatus()`

**Current Behavior:**
- ✅ Both work on ALL orders (no restrictions)
- ⚠️ Full edit modal has ledger adjustment detection
- ⚠️ Quick status change does NOT have ledger reversal logic

**Issue:**
- Quick edit buttons should be **disabled** or **hidden** for delivered orders
- OR should follow same ledger rules as full edit

---

## 🔍 **MOBILE APP INTEGRATION POINTS**

### **API Endpoints Used by Mobile:**

| Endpoint | Controller | Method | Ledger Impact |
|----------|-----------|--------|---------------|
| `POST /api/rider/store/assign-rider` | `RiderController` | `assignRiderToOrder()` | ❌ None (uses `OrderModel::assignRider()`) |
| `POST /api/rider/store/update-status` | `RiderController` | `updateOrderStatus()` | ✅ Yes (uses `OrderModel::changeStatus()`) |
| `POST /api/rider/store/update-packets` | `RiderController` | `updatePacketInfo()` | ❌ None (only updates packet fields) |

**Mobile Safety:**
- ✅ Mobile uses `OrderModel::changeStatus()` → Ledger posting works
- ✅ Mobile does NOT call `OrderController::update()` → No risk of breaking ledger adjustment
- ⚠️ Mobile rider assignment does NOT update ledger

---

## 🚨 **GAPS & MISSING FEATURES**

### **Gap 1: Rider Change After Delivery**

**Problem:**
- Order delivered with Rider A → Ledger posts to Rider A's cash account
- Manager changes rider to Rider B → Ledger still points to Rider A
- Rider B delivers but doesn't get credited

**Solution Needed:**
1. Detect rider change on delivered order with ledger entry
2. Check if ledger is settled
   - If settled → Block change (or require special approval)
   - If not settled → Reverse old ledger + create new one
3. Show confirmation to user:
   ```
   ⚠️ LEDGER WILL BE UPDATED
   
   Old Rider: Waseem (Cash Account)
   New Rider: Ali (Cash Account)
   
   The ledger entry will be reversed and reposted to the new rider's account.
   
   Proceed?
   ```

---

### **Gap 2: Order Cancellation After Delivery**

**Problem:**
- Order delivered → Ledger posted
- Manager cancels order → Ledger NOT reversed
- Result: Revenue/rider balance incorrect

**Solution Needed:**
1. Detect cancellation of delivered order
2. Check if ledger exists and not settled
   - If settled → Block cancellation (or require special approval)
   - If not settled → Reverse ledger entry
3. Show confirmation to user:
   ```
   ⚠️ LEDGER REVERSAL REQUIRED
   
   This order has been posted to the ledger.
   Cancelling will reverse the ledger entry.
   
   Order Amount: Rs. 5,000
   Posted to: Waseem (Cash Account)
   
   Proceed with cancellation?
   ```

---

### **Gap 3: Quick Edit on Delivered Orders**

**Problem:**
- Quick edit buttons work on all orders
- No ledger validation for delivered orders

**Solution Needed:**
1. **Option A:** Disable quick edit for delivered orders
   ```javascript
   if (order.ledger_transaction_id) {
       // Hide or disable quick edit buttons
       // Force user to use full edit flow
   }
   ```

2. **Option B:** Add ledger validation to quick edit
   - Detect changes that affect ledger
   - Show same confirmations as full edit

**Recommendation:** Option A (simpler, safer)

---

## 📊 **DATABASE TABLES INVOLVED**

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `t_crm_prod_order` | Main order | `ledger_transaction_id`, `assigned_rider_user_id`, `order_status`, `total_price`, `payment_method` |
| `t_fin_ledger` | Ledger entries | `order_id`, `from_account_id`, `to_account_id`, `amount`, `approval_status`, `settlement_status` |
| `t_fin_account` | Accounts | `account_type`, `employee_user_id`, `current_balance` |
| `t_crm_order_status_history` | Status changes | `order_id`, `status_code`, `changed_by`, `changed_at` |
| `t_ops_order_rider_history` | Rider assignments | `order_id`, `rider_user_id`, `is_current`, `assigned_at`, `unassigned_at` |
| `t_request_request` | Approval requests | `category_code`, `related_order_id`, `approval_status` |

---

## 🎯 **RECOMMENDED IMPLEMENTATION PLAN**

### **Phase 1: Rider Change Detection (High Priority)**

**Files to Modify:**
1. `app/Http/Controllers/CRM/OrderRiderController.php` → `assign()` method
2. Add ledger detection and reversal logic
3. Return confirmation message to frontend

**Logic:**
```php
public function assign(Request $request, int $orderId) {
    $order = OrderModel::find($orderId);
    
    // Check if order has ledger entry
    if ($order->ledger_transaction_id) {
        $ledger = LedgerModel::find($order->ledger_transaction_id);
        
        // Only for cash orders (online doesn't care about rider)
        if ($ledger && $ledger->mode === 'cash') {
            // Check if settled
            if ($ledger->settlement_status === 'settled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change rider: Invoice has been settled.',
                    'requires_confirmation' => false
                ], 422);
            }
            
            // Require confirmation
            if (!$request->has('confirmed') || !$request->confirmed) {
                return response()->json([
                    'success' => false,
                    'requires_confirmation' => true,
                    'message' => 'Rider change will update ledger',
                    'old_rider' => $order->assignedRider->fullname,
                    'new_rider' => User::find($request->rider_user_id)->fullname,
                    'amount' => $order->total_price
                ], 200);
            }
            
            // Reverse and repost ledger
            $this->handleRiderChangeLedgerUpdate($order, $ledger, $request->rider_user_id);
        }
    }
    
    // Proceed with assignment
    $order->assignRider($request->rider_user_id, $request->notes);
    
    return response()->json(['success' => true]);
}
```

---

### **Phase 2: Cancellation Detection (High Priority)**

**Files to Modify:**
1. `app/Models/CRM/OrderModel.php` → `changeStatus()` method
2. Add ledger reversal for cancellation

**Logic:**
```php
public function changeStatus(string $statusCode, ?string $notes = null, ?int $changedBy = null): bool {
    // ... existing code ...
    
    // Check if changing to cancelled and has ledger entry
    if ($statusCode === 'cancelled' && $this->ledger_transaction_id) {
        $ledger = LedgerModel::find($this->ledger_transaction_id);
        
        if ($ledger && $ledger->approval_status !== LedgerModel::STATUS_REVERSED) {
            // Check if settled
            if ($ledger->settlement_status === 'settled') {
                throw new \Exception('Cannot cancel: Invoice has been settled.');
            }
            
            // Reverse the ledger entry
            $this->reverseLedgerEntry($ledger, "Order cancelled");
        }
    }
    
    // ... rest of status change logic ...
}
```

---

### **Phase 3: Quick Edit Restrictions (Medium Priority)**

**Files to Modify:**
1. `resources/views/pages/orders/index.blade.php`
2. Disable quick edit buttons for delivered orders

**Logic:**
```javascript
// In the order row rendering
const hasLedger = order.ledger_transaction_id;
const isDelivered = order.order_status === 'delivered';

if (hasLedger && isDelivered) {
    // Show view-only button or disabled edit button
    html += `<button disabled class="..." title="Use full edit for delivered orders">
        <i class="fas fa-lock"></i>
    </button>`;
} else {
    // Show normal edit button
    html += `<button onclick="editOrderDetails(${order.id})" class="...">
        <i class="fas fa-edit"></i>
    </button>`;
}
```

---

## ✅ **TESTING CHECKLIST**

### **Scenario 1: Amount Change**
- [ ] Edit delivered order amount → Shows confirmation
- [ ] Confirm → Creates adjustment request
- [ ] Adjustment approved → Ledger updated
- [ ] Webhook update → No adjustment created

### **Scenario 2: Payment Method Change**
- [ ] Change online→cash → Reverses + reposts
- [ ] Change cash→online → Reverses + reposts
- [ ] Try on settled invoice → Blocked
- [ ] Try on partial settlement → Blocked

### **Scenario 3: Rider Change (After Implementation)**
- [ ] Change rider on delivered cash order → Shows confirmation
- [ ] Confirm → Reverses old ledger + creates new
- [ ] Try on settled invoice → Blocked
- [ ] Try on online order → No ledger change (correct)

### **Scenario 4: Cancellation (After Implementation)**
- [ ] Cancel delivered order → Shows confirmation
- [ ] Confirm → Reverses ledger
- [ ] Try on settled invoice → Blocked

### **Scenario 5: Mobile App**
- [ ] Mobile status change to delivered → Ledger posts
- [ ] Mobile rider assignment → No ledger impact (correct for now)
- [ ] Mobile packet update → No ledger impact (correct)

---

## 🚀 **NEXT STEPS**

1. **Review this analysis** with stakeholders
2. **Prioritize** which gaps to address first
3. **Implement Phase 1** (Rider change detection)
4. **Test thoroughly** on staging
5. **Deploy** to production
6. **Monitor** ledger consistency

---

## 📝 **NOTES**

- All ledger operations use **DB transactions** for safety
- Webhook updates are **explicitly excluded** from ledger adjustments
- Mobile app is **safe** as it doesn't call update endpoint
- Quick edit is **currently unrestricted** (needs fixing)
- Rider change is **biggest gap** (affects cash order accuracy)

---

**Document prepared by:** AI Assistant  
**Last updated:** November 9, 2025  
**Status:** Ready for Review & Implementation

