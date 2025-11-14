# Approval Routing System - Implementation Plan

## Overview
This document outlines the implementation of a flexible approval routing system that:
1. Routes online invoices through request workflow before ledger posting
2. Assigns approvals to specific users while maintaining role-based backup
3. Supports routing based on payment source, mode, and amount
4. Maintains backward compatibility with existing approval flows

---

## Architecture Changes

### Database Schema

#### New Tables
1. **`t_req_approval_rules`** - Routing rules for approvals
   - Filters: area_type, area_identifier, payment_source, payment_mode, amount range
   - Assignment strategy: single_primary, round_robin, all_can_act
   - Priority for rule matching

2. **`t_req_approval_rule_assignees`** - Users assigned to rules
   - Links rules to specific users
   - Supports primary/backup designations
   - Sequence for round-robin

#### Modified Tables
1. **`t_req_master`** - Added:
   - `level_1_assigned_to` - User assigned at L1
   - `level_2_assigned_to` - User assigned at L2
   - `order_id` - Link to order for invoice approvals

2. **`t_crm_prod_order`** - Added:
   - `invoice_request_id` - Link to approval request

3. **`t_fin_ledger_adjustments`** - Added:
   - `level_1_assigned_to` - User assigned at L1
   - `level_2_assigned_to` - User assigned at L2

---

## Online Invoice Workflow Changes

### Current Flow (Cash Invoices)
```
Order Delivered → LedgerPostingService::postInvoiceFromOrder()
                → Ledger entry created (STATUS_APPROVED)
                → Account balances updated
```

### Current Flow (Online Invoices)
```
Order Delivered → LedgerPostingService::postInvoiceFromOrder()
                → Ledger entry created (STATUS_PENDING)
                → Manager approves in Ledger
                → Account balances updated
```

### NEW Flow (Online Invoices)
```
Order Delivered → Create Request (invoice_approval category)
                → Status: PENDING, requires_level_1 = true
                → Assigned to specific L1 user (based on rules)
                
L1 Approval     → Manager reviews/approves request
                → Can modify amount, change payment method
                → If changed to cash: Auto-post to ledger (APPROVED), close request
                → If stays online: Post to ledger (STATUS_PENDING)
                
L2 Approval     → Finance reviews ledger entry
                → Approves/rejects ledger transaction
                → Account balances updated on approval
```

---

## Implementation Steps

### Phase 1: Database Migration ✅
**File**: `database/migrations/approval_routing_system_migration.sql`

**Actions**:
1. Run SQL migration to create tables and add columns
2. Verify invoice_approval category exists
3. Check all indexes and foreign keys created

**Verification**:
```sql
-- Check tables
SELECT * FROM t_req_approval_rules;
SELECT * FROM t_req_approval_rule_assignees;

-- Check new columns
DESCRIBE t_req_master;
DESCRIBE t_crm_prod_order;
```

---

### Phase 2: Service Layer Changes

#### 2.1 Modify `LedgerPostingService::postInvoiceFromOrder()`

**File**: `app/Services/FIN/LedgerPostingService.php`

**Changes**:
```php
public function postInvoiceFromOrder(OrderModel $order)
{
    // ... existing checks ...
    
    // NEW: Check if payment method is online
    $onlinePaymentMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];
    
    if (in_array($order->payment_method, $onlinePaymentMethods)) {
        // Create request instead of ledger entry
        return $this->createInvoiceApprovalRequest($order);
    }
    
    // EXISTING: Cash payments continue as before
    // ... rest of existing code ...
}

/**
 * Create invoice approval request for online payments
 */
private function createInvoiceApprovalRequest(OrderModel $order)
{
    try {
        DB::beginTransaction();
        
        // Get invoice_approval category
        $category = RequestCategoryModel::getByCode('invoice_approval');
        if (!$category) {
            throw new \Exception("Invoice approval category not found");
        }
        
        // Get approval config
        $requiresL1 = $category->requiresLevel1();
        $requiresL2 = $category->requiresLevel2();
        
        // Determine assignee using routing rules
        $assignedToL1 = $this->getAssigneeForApproval(
            'request_category',
            'invoice_approval',
            1,
            [
                'payment_mode' => 'online',
                'amount' => $order->total_price
            ]
        );
        
        // Build customer name for description
        $customerName = $this->getCustomerName($order);
        
        // Create request
        $request = RequestModel::create([
            'request_number' => RequestModel::generateRequestNumber(),
            'category_id' => $category->id,
            'order_id' => $order->id,
            'requester_user_id' => $order->created_by ?? 1,
            'title' => "Online Invoice Approval - Order #{$order->order_number}",
            'description' => "Online payment invoice for {$customerName}\nOrder: {$order->order_number}\nPayment Method: {$order->payment_method}",
            'amount' => $order->total_price,
            'status' => RequestModel::STATUS_PENDING,
            'requires_level_1' => $requiresL1,
            'requires_level_2' => $requiresL2,
            'level_1_status' => $requiresL1 ? RequestModel::APPROVAL_STATUS_PENDING : null,
            'level_1_assigned_to' => $assignedToL1,
            'level_2_status' => $requiresL2 ? RequestModel::APPROVAL_STATUS_PENDING : null,
            'submitted_at' => now(),
            'created_by' => $order->created_by ?? 1
        ]);
        
        // Link request to order
        $order->invoice_request_id = $request->id;
        $order->save();
        
        DB::commit();
        
        Log::info("Invoice approval request created", [
            'order_id' => $order->id,
            'request_id' => $request->id,
            'amount' => $order->total_price,
            'assigned_to' => $assignedToL1
        ]);
        
        return [
            'success' => true,
            'message' => 'Invoice approval request created',
            'request_id' => $request->id
        ];
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("Failed to create invoice approval request", [
            'order_id' => $order->id,
            'error' => $e->getMessage()
        ]);
        
        return [
            'success' => false,
            'message' => 'Failed to create approval request: ' . $e->getMessage()
        ];
    }
}

/**
 * Get assignee based on routing rules
 */
private function getAssigneeForApproval(
    string $areaType,
    string $areaIdentifier,
    int $level,
    array $context = []
): ?int
{
    // Query rules matching the criteria
    $query = DB::table('t_req_approval_rules')
        ->where('area_type', $areaType)
        ->where('area_identifier', $areaIdentifier)
        ->where('approval_level', $level)
        ->where('is_active', 1);
    
    // Apply contextual filters
    if (isset($context['payment_source_account_id'])) {
        $query->where(function($q) use ($context) {
            $q->where('payment_source_account_id', $context['payment_source_account_id'])
              ->orWhereNull('payment_source_account_id');
        });
    }
    
    if (isset($context['payment_mode'])) {
        $query->where(function($q) use ($context) {
            $q->where('payment_mode', $context['payment_mode'])
              ->orWhereNull('payment_mode');
        });
    }
    
    if (isset($context['amount'])) {
        $amount = $context['amount'];
        $query->where(function($q) use ($amount) {
            $q->where(function($subQ) use ($amount) {
                $subQ->whereNull('min_amount')
                     ->orWhere('min_amount', '<=', $amount);
            })
            ->where(function($subQ) use ($amount) {
                $subQ->whereNull('max_amount')
                     ->orWhere('max_amount', '>=', $amount);
            });
        });
    }
    
    // Get highest priority rule
    $rule = $query->orderBy('priority', 'asc')->first();
    
    if (!$rule) {
        return null; // No rule found, use default behavior
    }
    
    // Get primary assignee for this rule
    $assignee = DB::table('t_req_approval_rule_assignees')
        ->where('rule_id', $rule->id)
        ->where('is_primary', 1)
        ->orderBy('sequence_order', 'asc')
        ->first();
    
    return $assignee ? $assignee->user_id : null;
}
```

#### 2.2 Modify `RequestModel::processApproval()`

**File**: `app/Models/Request/RequestModel.php`

**Changes**:
```php
public function processApproval(int $level, int $approverId, string $action, ?string $comments = null): bool
{
    // ... existing validation ...
    
    DB::beginTransaction();
    try {
        // ... existing approval record creation ...
        
        // Update request status
        if ($level === 1) {
            $this->level_1_status = $action;
        } elseif ($level === 2) {
            $this->level_2_status = $action;
        }
        
        // If rejected at any level, mark entire request as rejected
        if ($action === 'rejected') {
            $this->setAttribute('status', self::STATUS_REJECTED);
            $this->rejection_reason = $comments;
            $this->completed_at = now();
        }
        // Check if all required approvals are complete
        elseif ($this->areAllApprovalsComplete()) {
            $this->setAttribute('status', self::STATUS_APPROVED);
            $this->completed_at = now();
            
            // NEW: Handle invoice approval requests
            if ($this->category->category_code === 'invoice_approval' && $this->order_id) {
                $this->postInvoiceToLedgerAfterApproval();
            }
            // EXISTING: Handle leave requests
            elseif ($this->category->category_code === 'leave') {
                $this->createAttendanceRecordsForLeave();
            }
            // EXISTING: Handle expense requests
            elseif ($this->category->category_code === 'expense' && $this->amount > 0) {
                // ... existing expense posting ...
            }
            // EXISTING: Handle salary advance
            elseif ($this->category->category_code === 'salary_advance' && $this->amount > 0) {
                // ... existing salary advance posting ...
            }
        }
        
        $this->updated_by = $approverId;
        $this->save();
        
        DB::commit();
        return true;
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Request approval error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Post invoice to ledger after request approval (for online invoices)
 */
protected function postInvoiceToLedgerAfterApproval(): void
{
    try {
        $order = $this->order;
        if (!$order) {
            throw new \Exception("Order not found for invoice request");
        }
        
        // Check if order payment method is still online
        $onlinePaymentMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];
        
        if (!in_array($order->payment_method, $onlinePaymentMethods)) {
            // Payment method changed to cash - post as approved
            \Log::info("Payment method changed to cash, posting as approved", [
                'request_id' => $this->id,
                'order_id' => $order->id,
                'payment_method' => $order->payment_method
            ]);
            
            $ledgerService = new \App\Services\FIN\LedgerPostingService();
            $result = $ledgerService->postInvoiceFromOrder($order);
            
            if ($result['success']) {
                \Log::info("Invoice posted to ledger (cash)", [
                    'request_id' => $this->id,
                    'ledger_id' => $result['ledger_id'] ?? null
                ]);
            }
            
            return;
        }
        
        // Still online - post as PENDING for L2 approval
        \Log::info("Posting online invoice to ledger as pending", [
            'request_id' => $this->id,
            'order_id' => $order->id
        ]);
        
        DB::beginTransaction();
        
        $salesAccount = \App\Models\FIN\ConfigModel::getSalesRevenueAccount();
        $onlineAccount = \App\Models\FIN\ConfigModel::getOnlineBankAccount();
        
        if (!$salesAccount || !$onlineAccount) {
            throw new \Exception("Required accounts not found");
        }
        
        // Build description
        $customerName = 'Unknown Customer';
        if (!empty($order->name)) {
            $customerName = trim($order->name);
        } elseif ($order->customer) {
            $customerName = $order->customer->full_name ?? $order->customer->name ?? 'Unknown';
        }
        
        $description = "Invoice #{$order->order_number} - Delivered ({$customerName})";
        
        // Create ledger entry as PENDING
        $ledger = \App\Models\FIN\LedgerModel::create([
            'transaction_date' => now(),
            'transaction_type' => \App\Models\FIN\LedgerModel::TYPE_INVOICE,
            'description' => $description,
            'from_account_id' => $salesAccount->id,
            'to_account_id' => $onlineAccount->id,
            'amount' => $order->total_price,
            'mode' => \App\Models\FIN\LedgerModel::MODE_ONLINE,
            'approval_status' => \App\Models\FIN\LedgerModel::STATUS_PENDING, // L2 approval needed
            'settlement_status' => 'open',
            'settled_amount' => 0.00,
            'order_id' => $order->id,
            'request_id' => $this->id,
            'created_by' => $this->updated_by,
            'comments' => "Approved via request #{$this->request_number}"
        ]);
        
        // Link ledger to order
        $order->ledger_transaction_id = $ledger->id;
        $order->save();
        
        // Link ledger to request
        $this->ledger_transaction_id = $ledger->id;
        $this->save();
        
        DB::commit();
        
        \Log::info("Online invoice posted to ledger as pending", [
            'request_id' => $this->id,
            'order_id' => $order->id,
            'ledger_id' => $ledger->id,
            'amount' => $order->total_price
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error("Failed to post invoice to ledger after approval", [
            'request_id' => $this->id,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}
```

---

### Phase 3: Ledger Audit Exclusion

#### 3.1 Modify `LedgerAuditController`

**File**: `app/Http/Controllers/FIN/LedgerAuditController.php`

**Changes**:
```php
// In the audit check for missing ledger entries
$deliveredOrders = OrderModel::where('order_status', 'delivered')
    ->whereNull('ledger_transaction_id')
    ->whereDoesntHave('invoiceRequest', function($q) {
        // Exclude orders that have pending invoice approval requests
        $q->where('status', 'pending');
    })
    ->with('customer')
    ->get();
```

Add relationship to OrderModel:
```php
// In app/Models/CRM/OrderModel.php
public function invoiceRequest()
{
    return $this->hasOne(RequestModel::class, 'order_id')
                ->where('category_id', function($q) {
                    $q->select('id')
                      ->from('t_req_category')
                      ->where('category_code', 'invoice_approval')
                      ->limit(1);
                });
}
```

---

### Phase 4: UI Changes

#### 4.1 Request Settings - Add Routing Configuration

**File**: `resources/views/pages/requests/settings.blade.php`

**Add new section**:
```blade
<!-- Approval Routing Rules -->
<div class="kt-card mt-6">
    <div class="kt-card-header">
        <h3 class="kt-card-title">Approval Routing Rules</h3>
        <button onclick="showAddRuleModal()" class="kt-btn kt-btn-sm kt-btn-primary">
            <i class="ki-filled ki-plus"></i> Add Rule
        </button>
    </div>
    
    <div class="kt-card-body">
        <table class="kt-table kt-table-border">
            <thead class="bg-gray-100">
                <tr>
                    <th>Rule Name</th>
                    <th>Area</th>
                    <th>Level</th>
                    <th>Filters</th>
                    <th>Assigned To</th>
                    <th>Priority</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="routing-rules-list">
                <!-- Populated via AJAX -->
            </tbody>
        </table>
    </div>
</div>
```

#### 4.2 Approvals Dashboard - Show Assigned Items

**File**: `resources/views/approvals/index.blade.php`

**Modify to show "My Assignments" tab**:
```blade
<!-- Add new tab -->
<li class="mr-2" role="presentation">
    <button class="inline-flex items-center gap-2 px-4 py-3 border-b-2 border-transparent"
            id="my-assignments-tab"
            onclick="switchTab('my-assignments')">
        👤 My Assignments
        <span class="px-2 py-0.5 bg-gray-100 text-gray-700 text-xs font-bold rounded-full">
            {{ $myAssignments->count() }}
        </span>
    </button>
</li>

<!-- Tab content -->
<div id="my-assignments" class="hidden">
    <!-- Show items assigned to current user -->
    @foreach($myAssignments as $item)
        <!-- Display assigned requests/adjustments -->
    @endforeach
    
    <!-- Option to view all items user can approve -->
    <button onclick="showAllApprovals()" class="kt-btn kt-btn-light mt-4">
        View All Items I Can Approve
    </button>
</div>
```

---

### Phase 5: Controller Updates

#### 5.1 Add Routing Management Controller

**File**: `app/Http/Controllers/Request/ApprovalRoutingController.php`

```php
<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalRoutingController extends Controller
{
    /**
     * Get all routing rules
     */
    public function index()
    {
        $rules = DB::table('t_req_approval_rules as r')
            ->leftJoin('t_fin_accounts as a', 'r.payment_source_account_id', '=', 'a.id')
            ->select(
                'r.*',
                'a.account_name as payment_source_name'
            )
            ->where('r.is_active', 1)
            ->orderBy('r.priority', 'asc')
            ->get();
        
        // Get assignees for each rule
        foreach ($rules as $rule) {
            $rule->assignees = DB::table('t_req_approval_rule_assignees as ra')
                ->join('t_sys_user as u', 'ra.user_id', '=', 'u.id')
                ->where('ra.rule_id', $rule->id)
                ->select('u.id', 'u.fullname', 'ra.is_primary', 'ra.sequence_order')
                ->orderBy('ra.is_primary', 'desc')
                ->orderBy('ra.sequence_order', 'asc')
                ->get();
        }
        
        return response()->json([
            'success' => true,
            'data' => $rules
        ]);
    }
    
    /**
     * Create new routing rule
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'rule_name' => 'required|string|max:255',
            'area_type' => 'required|in:request_category,ledger_transaction,ledger_adjustment',
            'area_identifier' => 'required|string|max:100',
            'approval_level' => 'required|integer|in:1,2',
            'payment_source_account_id' => 'nullable|exists:t_fin_accounts,id',
            'payment_mode' => 'nullable|in:cash,online',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'assignment_strategy' => 'required|in:single_primary,round_robin,all_can_act',
            'priority' => 'required|integer|min:1',
            'assignees' => 'required|array|min:1',
            'assignees.*.user_id' => 'required|exists:t_sys_user,id',
            'assignees.*.is_primary' => 'required|boolean'
        ]);
        
        try {
            DB::beginTransaction();
            
            // Create rule
            $ruleId = DB::table('t_req_approval_rules')->insertGetId([
                'rule_name' => $validated['rule_name'],
                'area_type' => $validated['area_type'],
                'area_identifier' => $validated['area_identifier'],
                'approval_level' => $validated['approval_level'],
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                'payment_mode' => $validated['payment_mode'] ?? null,
                'min_amount' => $validated['min_amount'] ?? null,
                'max_amount' => $validated['max_amount'] ?? null,
                'assignment_strategy' => $validated['assignment_strategy'],
                'priority' => $validated['priority'],
                'is_active' => 1,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            // Create assignees
            foreach ($validated['assignees'] as $index => $assignee) {
                DB::table('t_req_approval_rule_assignees')->insert([
                    'rule_id' => $ruleId,
                    'user_id' => $assignee['user_id'],
                    'is_primary' => $assignee['is_primary'],
                    'sequence_order' => $index,
                    'created_at' => now()
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Routing rule created successfully',
                'rule_id' => $ruleId
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create rule: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update routing rule
     */
    public function update(Request $request, $id)
    {
        // Similar to store, but updates existing rule
    }
    
    /**
     * Delete routing rule
     */
    public function destroy($id)
    {
        try {
            DB::table('t_req_approval_rules')
                ->where('id', $id)
                ->update([
                    'is_active' => 0,
                    'updated_by' => auth()->id(),
                    'updated_at' => now()
                ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Rule deactivated successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate rule: ' . $e->getMessage()
            ], 500);
        }
    }
}
```

---

## Testing Checklist

### 1. Online Invoice Flow
- [ ] Create order with online payment method
- [ ] Mark order as delivered
- [ ] Verify request created (not ledger entry)
- [ ] Check request assigned to correct L1 user
- [ ] L1 user approves request
- [ ] Verify ledger entry created as PENDING
- [ ] L2 user approves ledger
- [ ] Verify balances updated correctly

### 2. Payment Method Change
- [ ] Create online invoice request
- [ ] Change order payment method to cash before L1 approval
- [ ] L1 approves request
- [ ] Verify ledger posted as APPROVED (not pending)
- [ ] Verify request closed

### 3. Request Modification
- [ ] Create online invoice request
- [ ] L1 user modifies amount before approval
- [ ] Approve request
- [ ] Verify ledger has modified amount

### 4. Routing Rules
- [ ] Create rule for expense from EXP_FUND → User A
- [ ] Create expense request from EXP_FUND
- [ ] Verify assigned to User A
- [ ] Verify User B (also L1) can still approve

### 5. Backward Compatibility
- [ ] Create cash invoice
- [ ] Verify posted directly to ledger (approved)
- [ ] Create expense request (non-invoice)
- [ ] Verify existing flow unchanged

### 6. Audit Exclusion
- [ ] Create online invoice (in request stage)
- [ ] Run ledger audit
- [ ] Verify order NOT flagged as missing ledger

---

## Rollback Plan

If issues arise:

1. **Disable online invoice routing**:
```sql
UPDATE t_req_category 
SET is_active = 0 
WHERE category_code = 'invoice_approval';
```

2. **Revert to direct ledger posting**:
   - Comment out new code in `postInvoiceFromOrder()`
   - Restore original behavior

3. **Clean up orphaned data**:
```sql
-- Find invoice requests without ledger entries
SELECT * FROM t_req_master 
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'invoice_approval')
AND status = 'approved'
AND ledger_transaction_id IS NULL;
```

---

## Next Steps

1. **Run SQL migration** (provided separately)
2. **Update service layer** (LedgerPostingService)
3. **Update request model** (processApproval method)
4. **Update audit controller** (exclude online invoices in request stage)
5. **Test thoroughly** using checklist above
6. **Configure initial routing rules** in Request Settings
7. **Train users** on new workflow

---

## Notes

- Assignment is for **display purposes only** - any L1/L2 role member can still approve
- Rules are evaluated in priority order (lower number = higher priority)
- If no rule matches, falls back to current "anyone with role" behavior
- Online invoices now have two approval stages: request (L1) + ledger (L2)
- Payment method changes during approval automatically adjust posting behavior

