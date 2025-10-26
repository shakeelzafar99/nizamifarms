# Payment Method Change After Delivery - Analysis & Solution

## Current System Flow

### 1. When Order is Marked as Delivered

**File:** `app/Models/CRM/OrderModel.php` (line 941)
**Service:** `app/Services/FIN/LedgerPostingService.php`

```php
// When status changes to 'delivered'
$ledgerService = new \App\Services\FIN\LedgerPostingService();
$result = $ledgerService->postInvoiceFromOrder($this);
```

**Logic in `postInvoiceFromOrder()`:**

```php
// Determine destination account based on payment method
$onlinePaymentMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];

if (in_array($order->payment_method, $onlinePaymentMethods)) {
    // ONLINE PAYMENT
    $toAccount = ConfigModel::getOnlineBankAccount(); // Online Bank account
    $mode = LedgerModel::MODE_ONLINE;
    $approvalStatus = LedgerModel::STATUS_PENDING; // Requires approval
} else {
    // CASH PAYMENT
    $toAccount = AccountModel::createEmployeeCashAccount($rider->id, $rider->fullname);
    $mode = LedgerModel::MODE_CASH;
    $approvalStatus = LedgerModel::STATUS_APPROVED; // Auto-approved
}

// Create ledger entry
$ledger = LedgerModel::create([
    'transaction_type' => LedgerModel::TYPE_INVOICE,
    'from_account_id' => $salesAccount->id,  // Revenue
    'to_account_id' => $toAccount->id,       // Rider cash OR Online bank
    'amount' => $order->total_price,
    'mode' => $mode,
    'approval_status' => $approvalStatus,
    'order_id' => $order->id
]);

// Update balances if approved (cash only)
if ($approvalStatus === LedgerModel::STATUS_APPROVED) {
    $salesAccount->current_balance -= $order->total_price;
    $toAccount->current_balance += $order->total_price;  // Rider balance increases
}
```

### 2. When Order is Updated After Delivery

**File:** `app/Http/Controllers/CRM/OrderController.php` (lines 449-517)

**Current Logic:**
- ✅ Detects if `total_price` changed
- ✅ Creates ledger adjustment request (requires approval)
- ❌ **Does NOT check if `payment_method` changed**

### 3. Impact on Different Parts of System

#### A. Employee Cash Account (Rider Balance)
- **Cash orders:** Invoice amount goes to rider's cash account
- **Shows in:** Employee Cash page, Daily Closing
- **Settlement:** Rider must deposit this cash back to NF Cash

#### B. Online Bank Account
- **Online orders:** Invoice amount goes to Online Bank (pending approval)
- **Shows in:** Online Bank ledger (not in rider balance)
- **No settlement needed:** Money already in bank

#### C. Daily Closing
- **Calculates:** Total cash that should be with riders
- **Based on:** Approved ledger entries with `to_account_id` = rider accounts
- **Issue:** If payment method changes from cash to online AFTER delivery, the ledger entry still points to rider account

## The Problem

**Scenario:**
1. Order #2610 delivered as **Cash on Delivery**
   - Ledger entry created: Revenue → Rider Cash (Rs. 1,000)
   - Rider balance: +Rs. 1,000
   - Daily closing expects: Rs. 1,000 from rider

2. User changes payment method to **Online Payment** (after delivery)
   - Order updated: `payment_method` = 'Online Payment'
   - Ledger entry: **UNCHANGED** (still points to rider account!)
   - Rider balance: **Still shows Rs. 1,000** (wrong!)
   - Daily closing: **Still expects Rs. 1,000** (wrong!)

**Result:**
- ❌ Rider shows Rs. 1,000 cash they don't actually have
- ❌ Daily closing shows shortage
- ❌ Online bank doesn't show the Rs. 1,000 payment
- ❌ Settlement flow broken

## Solution Design

### Option 1: Reverse & Repost (Recommended)

When payment method changes after delivery:
1. **Reverse the old ledger entry** (mark as cancelled/reversed)
2. **Create new ledger entry** with correct account
3. **Update balances** accordingly
4. **Link new ledger to order**

**Pros:**
- ✅ Complete audit trail
- ✅ Shows what happened (cash → online change)
- ✅ Balances always correct
- ✅ Daily closing accurate

**Cons:**
- More complex implementation
- Two ledger entries per order (if changed)

### Option 2: Update Existing Ledger Entry

When payment method changes:
1. **Reverse old balances**
2. **Update ledger entry** (change `to_account_id`, `mode`, `approval_status`)
3. **Apply new balances**

**Pros:**
- Simpler implementation
- One ledger entry per order

**Cons:**
- ❌ Loses history of what changed
- ❌ Harder to audit
- ❌ May confuse if partially settled

### Recommended: Option 1 (Reverse & Repost)

## Implementation Plan

### Step 1: Detect Payment Method Change

In `OrderController::update()`, add detection:

```php
// After existing ledger adjustment detection
if ($order->ledger_transaction_id && !$isWebhookUpdate) {
    $ledger = \App\Models\FIN\LedgerModel::find($order->ledger_transaction_id);
    
    if ($ledger) {
        // Check if payment method changed
        $oldPaymentMethod = $order->payment_method;
        $newPaymentMethod = $validated['payment_method'] ?? $oldPaymentMethod;
        
        if ($oldPaymentMethod !== $newPaymentMethod) {
            // Payment method changed - need to reverse and repost
            $this->handlePaymentMethodChange($order, $ledger, $newPaymentMethod);
        }
    }
}
```

### Step 2: Create Payment Method Change Handler

```php
private function handlePaymentMethodChange($order, $oldLedger, $newPaymentMethod)
{
    DB::beginTransaction();
    
    try {
        // 1. Reverse old ledger entry
        $this->reverseLedgerEntry($oldLedger);
        
        // 2. Create new ledger entry with correct payment method
        $ledgerService = new \App\Services\FIN\LedgerPostingService();
        
        // Temporarily update order payment method for posting
        $originalPaymentMethod = $order->payment_method;
        $order->payment_method = $newPaymentMethod;
        
        $result = $ledgerService->postInvoiceFromOrder($order);
        
        if (!$result['success']) {
            throw new \Exception("Failed to repost invoice: " . $result['message']);
        }
        
        // 3. Add note to new ledger entry
        $newLedger = LedgerModel::find($result['ledger_id']);
        $newLedger->comments = "Payment method changed from '{$originalPaymentMethod}' to '{$newPaymentMethod}'. Original ledger #{$oldLedger->id} reversed.";
        $newLedger->save();
        
        DB::commit();
        
        \Log::info("Payment method changed for delivered order", [
            'order_id' => $order->id,
            'old_method' => $originalPaymentMethod,
            'new_method' => $newPaymentMethod,
            'old_ledger_id' => $oldLedger->id,
            'new_ledger_id' => $newLedger->id
        ]);
        
        return [
            'success' => true,
            'old_ledger_id' => $oldLedger->id,
            'new_ledger_id' => $newLedger->id
        ];
        
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error("Failed to handle payment method change", [
            'order_id' => $order->id,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

private function reverseLedgerEntry($ledger)
{
    // Mark as reversed
    $ledger->approval_status = 'reversed';
    $ledger->comments = ($ledger->comments ?? '') . "\n\nREVERSED: Payment method changed on order.";
    $ledger->save();
    
    // Reverse balances if they were applied
    if ($ledger->approval_status === LedgerModel::STATUS_APPROVED) {
        $fromAccount = $ledger->fromAccount;
        $toAccount = $ledger->toAccount;
        
        if ($fromAccount) {
            $fromAccount->current_balance += $ledger->amount; // Reverse debit
            $fromAccount->save();
        }
        
        if ($toAccount) {
            $toAccount->current_balance -= $ledger->amount; // Reverse credit
            $toAccount->save();
        }
    }
}
```

### Step 3: Update LedgerModel Status Constants

Add new status for reversed entries:

```php
// In app/Models/FIN/LedgerModel.php
const STATUS_REVERSED = 'reversed';
```

### Step 4: Update Views to Show Reversed Entries

In employee cash and daily closing views, filter out reversed entries:

```php
// Only show non-reversed entries
$ledgerQuery->where('approval_status', '!=', 'reversed');
```

## Testing Checklist

1. **Cash → Online Change:**
   - [ ] Rider balance decreases correctly
   - [ ] Online bank shows pending invoice
   - [ ] Daily closing updated
   - [ ] Old ledger marked as reversed
   - [ ] New ledger created with correct account

2. **Online → Cash Change:**
   - [ ] Online bank invoice removed/reversed
   - [ ] Rider balance increases correctly
   - [ ] Daily closing updated
   - [ ] Settlement flow works

3. **Edge Cases:**
   - [ ] Order already settled (should prevent change?)
   - [ ] Order with ledger adjustment pending
   - [ ] Multiple payment method changes
   - [ ] Webhook updates (should skip this logic)

## Database Migration Needed

```sql
-- Add 'reversed' status to approval_status enum
ALTER TABLE t_fin_ledger 
MODIFY COLUMN approval_status ENUM('pending', 'approved', 'rejected', 'reversed') 
DEFAULT 'pending';
```

## Impact Analysis

### Affected Components:
1. ✅ Employee Cash Balance - Will be corrected
2. ✅ Daily Closing - Will show accurate cash expected
3. ✅ Online Bank - Will show correct pending invoices
4. ✅ Settlement Flow - Will work correctly
5. ✅ Ledger Adjustments - Independent (amount-based, not payment method)

### Not Affected:
- Order history
- Customer records
- Product inventory
- Shipping/delivery status

