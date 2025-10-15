# 📋 Invoice Ledger Adjustment Enhancement - Implementation Plan

## 🎯 **Requirement**

When a delivered/completed order's invoice details (price, items, etc.) are modified, the corresponding ledger entry should be updated through an approval process similar to online payment approvals.

---

## 🔍 **Current System Analysis**

### **How Invoices are Currently Posted to Ledger:**

1. **Trigger:** Order status changes to `'delivered'` (in `OrderModel::changeStatus()`)
2. **Service:** `LedgerPostingService::postInvoiceFromOrder()`
3. **Creates:** `LedgerModel` entry with:
   - `transaction_type` = `'invoice'`
   - `from_account_id` = Sales Revenue Account (`REV_SALES`)
   - `to_account_id` = Employee Cash Account or Online Bank
   - `amount` = `order->total_price`
   - `order_id` = `order->id`
   - `approval_status`:
     - `'approved'` for cash payments (auto-approved)
     - `'pending'` for online payments (requires L1→L2 approval)
4. **Links:** `order->ledger_transaction_id` is set to the created ledger entry

### **Current Order Update Flow:**

1. **Controller:** `OrderController::update()`
2. **Updates:** Order fields, line items, discounts
3. **No Ledger Interaction:** Currently does NOT touch the ledger
4. **Issue:** If `total_price` changes after delivery, ledger shows old amount

---

## 🎯 **Proposed Solution**

### **Core Logic:**

1. **Detect Ledger-Posted Orders:**
   - Check if `order->ledger_transaction_id` is NOT null
   - This means the order has already been posted to the ledger

2. **Detect Price Changes:**
   - Compare `old total_price` vs `new total_price`
   - If different, a ledger adjustment is needed

3. **Create Adjustment Request:**
   - **Do NOT directly update the ledger**
   - Create a pending **Ledger Adjustment** entry
   - This adjustment will be reviewed and approved by L1/L2

4. **Approval Flow:**
   - Similar to online payment approvals
   - Appears in the Approvals dashboard
   - L1 approves → L2 approves → Ledger updated

5. **Upon Approval:**
   - Update original ledger entry amount
   - Update account balances to reflect the difference
   - Create audit trail

---

## 📊 **Database Changes Required**

### **1. New Table: `t_fin_ledger_adjustments`**

```sql
CREATE TABLE t_fin_ledger_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ledger_id INT NOT NULL COMMENT 'FK to t_fin_ledger.id (the original invoice entry)',
    order_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_order.id',
    old_amount DECIMAL(15,2) NOT NULL COMMENT 'Original ledger amount',
    new_amount DECIMAL(15,2) NOT NULL COMMENT 'Proposed new amount',
    adjustment_amount DECIMAL(15,2) NOT NULL COMMENT 'Difference (new - old)',
    reason TEXT NULL COMMENT 'Reason for adjustment',
    adjustment_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    
    -- L1 Approval
    requires_level_1 BOOLEAN DEFAULT TRUE,
    level_1_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    level_1_approved_by INT NULL,
    level_1_approved_at TIMESTAMP NULL,
    level_1_comments TEXT NULL,
    
    -- L2 Approval
    requires_level_2 BOOLEAN DEFAULT TRUE,
    level_2_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    level_2_approved_by INT NULL,
    level_2_approved_at TIMESTAMP NULL,
    level_2_comments TEXT NULL,
    
    -- Metadata
    requested_by INT NOT NULL COMMENT 'User who modified the order',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finalized_at TIMESTAMP NULL COMMENT 'When adjustment was approved/rejected',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (ledger_id) REFERENCES t_fin_ledger(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES t_crm_prod_order(id) ON DELETE CASCADE,
    FOREIGN KEY (level_1_approved_by) REFERENCES t_sys_users(id) ON DELETE SET NULL,
    FOREIGN KEY (level_2_approved_by) REFERENCES t_sys_users(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by) REFERENCES t_sys_users(id) ON DELETE CASCADE,
    
    INDEX idx_adjustment_status (adjustment_status),
    INDEX idx_ledger_id (ledger_id),
    INDEX idx_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks ledger adjustments for orders modified after delivery';
```

### **2. New Permission: `approve_invoice_ledger_adjustments`**

```sql
INSERT INTO t_sys_permissions (permission_name, permission_description, created_at, updated_at)
VALUES 
    ('approve_invoice_ledger_adjustments', 'Can approve invoice ledger adjustments (L1/L2)', NOW(), NOW());
```

---

## 🔧 **Code Changes Required**

### **1. New Model: `LedgerAdjustmentModel`**

**File:** `app/Models/FIN/LedgerAdjustmentModel.php`

```php
<?php

namespace App\Models\FIN;

use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerAdjustmentModel extends BaseModel
{
    protected $table = 't_fin_ledger_adjustments';
    protected $primaryKey = 'id';
    
    protected $fillable = [
        'ledger_id', 'order_id', 'old_amount', 'new_amount', 'adjustment_amount',
        'reason', 'adjustment_status',
        'requires_level_1', 'level_1_status', 'level_1_approved_by', 'level_1_approved_at', 'level_1_comments',
        'requires_level_2', 'level_2_status', 'level_2_approved_by', 'level_2_approved_at', 'level_2_comments',
        'requested_by', 'requested_at', 'finalized_at'
    ];
    
    protected $casts = [
        'old_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'requires_level_1' => 'boolean',
        'requires_level_2' => 'boolean',
        'level_1_approved_at' => 'datetime',
        'level_2_approved_at' => 'datetime',
        'requested_at' => 'datetime',
        'finalized_at' => 'datetime'
    ];
    
    // Relationships
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(LedgerModel::class, 'ledger_id');
    }
    
    public function order(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CRM\OrderModel::class, 'order_id');
    }
    
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SysAdmin\UserModel::class, 'requested_by');
    }
    
    // Status checks
    public function isPending(): bool
    {
        return $this->adjustment_status === 'pending';
    }
    
    public function isApproved(): bool
    {
        return $this->adjustment_status === 'approved';
    }
    
    public function isRejected(): bool
    {
        return $this->adjustment_status === 'rejected';
    }
    
    // Approval logic (similar to RequestModel)
    public function canBeApprovedByLevel(int $level): bool
    {
        if (!$this->isPending()) {
            return false;
        }
        
        if ($level === 1) {
            return $this->requires_level_1 && $this->level_1_status === 'pending';
        }
        
        if ($level === 2) {
            return $this->requires_level_2 && 
                   $this->level_2_status === 'pending' &&
                   (!$this->requires_level_1 || $this->level_1_status === 'approved');
        }
        
        return false;
    }
    
    public function processApproval(int $level, int $approverId, string $action, ?string $comments = null): bool
    {
        if (!in_array($action, ['approved', 'rejected'])) {
            return false;
        }
        
        if (!$this->canBeApprovedByLevel($level)) {
            return false;
        }
        
        DB::beginTransaction();
        try {
            // Update approval fields
            if ($level === 1) {
                $this->level_1_status = $action;
                $this->level_1_approved_by = $approverId;
                $this->level_1_approved_at = now();
                $this->level_1_comments = $comments;
            } elseif ($level === 2) {
                $this->level_2_status = $action;
                $this->level_2_approved_by = $approverId;
                $this->level_2_approved_at = now();
                $this->level_2_comments = $comments;
            }
            
            // Check if fully approved or rejected
            if ($action === 'rejected') {
                $this->adjustment_status = 'rejected';
                $this->finalized_at = now();
            } elseif ($this->isFullyApproved()) {
                $this->adjustment_status = 'approved';
                $this->finalized_at = now();
                
                // Apply the ledger adjustment
                $this->applyAdjustment();
            }
            
            $this->save();
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Failed to process ledger adjustment approval", [
                'adjustment_id' => $this->id,
                'level' => $level,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    private function isFullyApproved(): bool
    {
        $l1Approved = !$this->requires_level_1 || $this->level_1_status === 'approved';
        $l2Approved = !$this->requires_level_2 || $this->level_2_status === 'approved';
        
        return $l1Approved && $l2Approved;
    }
    
    private function applyAdjustment(): void
    {
        // Update the ledger entry
        $ledger = $this->ledger;
        $oldAmount = $ledger->amount;
        $newAmount = $this->new_amount;
        $difference = $newAmount - $oldAmount;
        
        $ledger->amount = $newAmount;
        $ledger->save();
        
        // Update account balances
        $fromAccount = $ledger->fromAccount;
        $toAccount = $ledger->toAccount;
        
        // Adjust balances by the difference
        if ($fromAccount) {
            $fromAccount->current_balance -= $difference; // Revenue decreases by difference
            $fromAccount->save();
        }
        
        if ($toAccount) {
            $toAccount->current_balance += $difference; // Asset increases by difference
            $toAccount->save();
        }
        
        \Log::info("Ledger adjustment applied", [
            'adjustment_id' => $this->id,
            'ledger_id' => $ledger->id,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'difference' => $difference
        ]);
    }
}
```

---

### **2. Update `OrderController::update()`**

**File:** `app/Http/Controllers/CRM/OrderController.php`

**Add after line 449 (after `$order->update($validated)`)**:

```php
// Check if this order has a ledger entry and if the total_price changed
if ($order->ledger_transaction_id && $order->wasChanged('total_price')) {
    $ledger = LedgerModel::find($order->ledger_transaction_id);
    
    if ($ledger) {
        $oldAmount = $ledger->amount;
        $newAmount = $order->total_price;
        
        if (abs($oldAmount - $newAmount) > 0.01) { // Significant change
            // Create a ledger adjustment request
            $adjustment = \App\Models\FIN\LedgerAdjustmentModel::create([
                'ledger_id' => $ledger->id,
                'order_id' => $order->id,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'adjustment_amount' => $newAmount - $oldAmount,
                'reason' => "Order #{$order->order_number} invoice amount changed from Rs. {$oldAmount} to Rs. {$newAmount}",
                'adjustment_status' => 'pending',
                'requires_level_1' => true,
                'requires_level_2' => true,
                'level_1_status' => 'pending',
                'level_2_status' => 'pending',
                'requested_by' => auth()->id(),
                'requested_at' => now()
            ]);
            
            \Log::info("Ledger adjustment created for order update", [
                'order_id' => $order->id,
                'adjustment_id' => $adjustment->id,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount
            ]);
            
            // Add a message to the response
            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully. Ledger adjustment created and pending approval.',
                'requires_approval' => true,
                'adjustment_id' => $adjustment->id,
                'order' => $order->load(['customer', 'lineItems', 'discounts'])
            ]);
        }
    }
}
```

---

### **3. Add Frontend Confirmation**

**File:** `resources/views/pages/orders/index.blade.php`

**In `saveOrderChanges()` function, add confirmation before submitting:**

```javascript
// Check if order is delivered and has ledger entry
if (order.order_status === 'delivered' && order.ledger_transaction_id) {
    const oldTotal = parseFloat(order.total_price) || 0;
    const newTotal = calculateOrderTotal(); // Your existing function
    
    if (Math.abs(oldTotal - newTotal) > 0.01) {
        const confirmed = confirm(
            `⚠️ LEDGER ADJUSTMENT REQUIRED\n\n` +
            `This order has already been posted to the ledger.\n\n` +
            `Old Amount: Rs. ${oldTotal.toFixed(2)}\n` +
            `New Amount: Rs. ${newTotal.toFixed(2)}\n` +
            `Difference: Rs. ${(newTotal - oldTotal).toFixed(2)}\n\n` +
            `The ledger adjustment will be sent for L1→L2 approval.\n` +
            `Do you want to proceed?`
        );
        
        if (!confirmed) {
            return; // Cancel the save
        }
    }
}

// Proceed with save
fetch(`/api/orders/${orderId}`, {
    method: 'PUT',
    ...
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        if (data.requires_approval) {
            showSuccessMessage(`${data.message}\n\nAdjustment ID: ${data.adjustment_id}`);
        } else {
            showSuccessMessage(data.message);
        }
        closeOrderModal();
        loadOrders(); // Refresh table
    }
});
```

---

### **4. Approval Controller**

**File:** `app/Http/Controllers/FIN/LedgerAdjustmentController.php` (NEW)

```php
<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\LedgerAdjustmentModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;

class LedgerAdjustmentController extends Controller
{
    public function index()
    {
        $adjustments = LedgerAdjustmentModel::where('adjustment_status', 'pending')
            ->with(['ledger', 'order', 'requestedBy'])
            ->orderBy('requested_at', 'asc')
            ->get();
        
        return view('fin.adjustments.index', compact('adjustments'));
    }
    
    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'level' => 'required|integer|in:1,2',
            'comments' => 'nullable|string'
        ]);
        
        $adjustment = LedgerAdjustmentModel::findOrFail($id);
        $user = auth()->user();
        $level = $validated['level'];
        
        // Check if user has approval rights for this level
        if (!RoleApprovalLevelModel::userHasApprovalLevel($user->id, $level)) {
            return response()->json([
                'success' => false,
                'message' => "You don't have Level {$level} approval rights"
            ], 403);
        }
        
        $success = $adjustment->processApproval(
            $level,
            $user->id,
            'approved',
            $validated['comments'] ?? null
        );
        
        if ($success) {
            return response()->json([
                'success' => true,
                'message' => 'Ledger adjustment approved successfully'
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to approve adjustment'
        ], 500);
    }
    
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'level' => 'required|integer|in:1,2',
            'comments' => 'required|string'
        ]);
        
        $adjustment = LedgerAdjustmentModel::findOrFail($id);
        $user = auth()->user();
        $level = $validated['level'];
        
        if (!RoleApprovalLevelModel::userHasApprovalLevel($user->id, $level)) {
            return response()->json([
                'success' => false,
                'message' => "You don't have Level {$level} approval rights"
            ], 403);
        }
        
        $success = $adjustment->processApproval(
            $level,
            $user->id,
            'rejected',
            $validated['comments'] ?? null
        );
        
        if ($success) {
            return response()->json([
                'success' => true,
                'message' => 'Ledger adjustment rejected'
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to reject adjustment'
        ], 500);
    }
}
```

---

### **5. Update Approvals Dashboard**

**File:** `app/Http/Controllers/ApprovalController.php`

**Add to `index()` method:**

```php
// ========== LEDGER ADJUSTMENTS (L1/L2 Workflow) ==========
$pendingAdjustments = collect();

if ($hasLevel1Rights || $hasLevel2Rights) {
    $allPendingAdjustments = \App\Models\FIN\LedgerAdjustmentModel::where('adjustment_status', 'pending')
        ->with(['ledger', 'order', 'requestedBy'])
        ->orderBy('requested_at', 'asc')
        ->get();
    
    // Filter adjustments that this user can approve
    $pendingAdjustments = $allPendingAdjustments->filter(function($adj) use ($user, $hasLevel1Rights, $hasLevel2Rights) {
        if ($hasLevel1Rights && 
            $adj->requires_level_1 && 
            $adj->level_1_status === 'pending') {
            return true;
        }
        
        if ($hasLevel2Rights && 
            $adj->requires_level_2 && 
            $adj->level_1_status === 'approved' && 
            $adj->level_2_status === 'pending') {
            return true;
        }
        
        return false;
    });
}

// Pass to view
return view('approvals.index', compact(
    'pendingRequests',
    'pendingLedger',
    'pendingAdjustments', // NEW
    ...
));
```

---

## 🎨 **UI Changes**

### **Approvals Dashboard - New Section:**

```blade
<!-- Ledger Adjustments Section -->
@if($pendingAdjustments->count() > 0)
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-bold text-purple-800 mb-4">
        📝 Ledger Adjustments Pending Approval
        <span class="ml-2 bg-purple-100 text-purple-800 text-sm font-semibold px-2.5 py-0.5 rounded">
            {{ $pendingAdjustments->count() }}
        </span>
    </h2>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Old Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">New Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Difference</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requested By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($pendingAdjustments as $adj)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <a href="/orders/{{ $adj->order_id }}" class="text-indigo-600 hover:text-indigo-900 font-medium">
                            {{ $adj->order->order_number }}
                        </a>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-gray-900">
                        Rs. {{ number_format($adj->old_amount, 2) }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-gray-900">
                        Rs. {{ number_format($adj->new_amount, 2) }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="{{ $adj->adjustment_amount >= 0 ? 'text-green-700' : 'text-red-700' }} font-semibold">
                            {{ $adj->adjustment_amount >= 0 ? '+' : '' }}Rs. {{ number_format($adj->adjustment_amount, 2) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-gray-600">
                        {{ $adj->requestedBy->name }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-gray-600">
                        {{ $adj->requested_at->format('M j, Y g:i A') }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($adj->level_1_status === 'pending')
                            <span class="px-2 py-1 text-xs font-bold bg-yellow-100 text-yellow-800 rounded-full">
                                ⏳ Pending L1
                            </span>
                        @elseif($adj->level_2_status === 'pending')
                            <span class="px-2 py-1 text-xs font-bold bg-blue-100 text-blue-800 rounded-full">
                                ⏳ Pending L2
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-right">
                        @if($adj->canBeApprovedByLevel(1) && $hasLevel1Rights)
                            <button onclick="approveAdjustment({{ $adj->id }}, 1)" class="text-green-600 hover:text-green-900 mr-2">
                                ✅ Approve (L1)
                            </button>
                            <button onclick="rejectAdjustment({{ $adj->id }}, 1)" class="text-red-600 hover:text-red-900">
                                ❌ Reject
                            </button>
                        @elseif($adj->canBeApprovedByLevel(2) && $hasLevel2Rights)
                            <button onclick="approveAdjustment({{ $adj->id }}, 2)" class="text-green-600 hover:text-green-900 mr-2">
                                ✅ Approve (L2)
                            </button>
                            <button onclick="rejectAdjustment({{ $adj->id }}, 2)" class="text-red-600 hover:text-red-900">
                                ❌ Reject
                            </button>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
```

---

## 🧪 **Testing Checklist**

### **Scenario 1: Cash Invoice Adjustment**
1. ✅ Create cash order, mark as delivered
2. ✅ Verify ledger entry created (approved, balance updated)
3. ✅ Edit order, change total from Rs. 1000 → Rs. 1200
4. ✅ Confirm adjustment prompt appears
5. ✅ Verify adjustment created (pending)
6. ✅ L1 approves → status changes to "Pending L2"
7. ✅ L2 approves → ledger updated, balances adjusted
8. ✅ Verify final ledger amount is Rs. 1200

### **Scenario 2: Online Invoice Adjustment**
1. ✅ Create online order, mark as delivered
2. ✅ Verify ledger entry created (pending approval)
3. ✅ L1→L2 approve original invoice
4. ✅ Edit order, change total
5. ✅ Verify adjustment created
6. ✅ L1→L2 approve adjustment
7. ✅ Verify ledger updated

### **Scenario 3: Downward Adjustment**
1. ✅ Order total Rs. 1000 → Rs. 800
2. ✅ Verify adjustment_amount = -200
3. ✅ Verify balances decrease correctly

### **Scenario 4: Rejection**
1. ✅ Create adjustment
2. ✅ L1 rejects → adjustment marked as rejected
3. ✅ Verify ledger unchanged
4. ✅ Verify order still updated (only ledger not synced)

---

## 🚨 **Safety Considerations**

1. **No Direct Ledger Updates:** All changes go through approval
2. **Audit Trail:** Every adjustment is logged
3. **Balance Integrity:** Balances only updated after full approval
4. **Reversibility:** Rejected adjustments don't affect ledger
5. **Permissions:** Only L1/L2 can approve
6. **Confirmation:** User must confirm before creating adjustment

---

## 🔒 **Permissions Required**

Add to roles that should approve ledger adjustments:
- `approve_invoice_ledger_adjustments` (or use existing L1/L2 approval rights)

---

## 📊 **Migration Order**

1. Run `add_ledger_adjustments_table.sql`
2. Deploy `LedgerAdjustmentModel`
3. Update `OrderController`
4. Update `ApprovalController`
5. Update frontend confirmation
6. Add permission to roles
7. Test thoroughly

---

**Date:** 2025-10-15  
**Status:** ⏳ Awaiting Approval to Proceed  
**Estimated Time:** 3-4 hours for full implementation

