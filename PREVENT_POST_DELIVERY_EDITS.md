# Prevent Post-Delivery Order Edits

**Issue**: Staff are editing orders after delivery, creating ledger adjustments that require approval.

**Example**: Order 16492 was delivered at 17:15 with Rs. 9,820, then edited at 20:24 to Rs. 7,741.50, creating adjustment ADJ-25.

## Solution: Lock Delivered Orders

### Implementation Plan

Add a check in the order edit form to prevent editing delivered orders unless the user has a special permission.

### Files to Modify

1. **Frontend**: `resources/views/pages/orders/index.blade.php`
   - Add check before allowing edit modal to open
   - Show warning message if order is delivered

2. **Backend**: `app/Http/Controllers/CRM/OrderController.php`
   - Add validation in `update()` method
   - Return error if order is delivered and user lacks permission

### Code Changes

#### 1. Add Permission Check in Controller

```php
// In OrderController@update(), add this before line 449:

// Check if order is delivered and user has permission to edit
if ($order->ledger_transaction_id && !auth()->user()->hasPermission('edit_delivered_orders')) {
    return response()->json([
        'success' => false,
        'message' => 'This order has been delivered and cannot be edited. Please contact your manager if changes are needed.',
        'requires_special_permission' => true
    ], 403);
}
```

#### 2. Add Frontend Warning

```javascript
// In resources/views/pages/orders/index.blade.php
// Add this check before opening edit modal:

function openEditModal(orderId) {
    // Fetch order details first
    fetch(`/api/orders/${orderId}`)
        .then(response => response.json())
        .then(data => {
            if (data.order.ledger_transaction_id) {
                // Order is delivered
                if (!confirm('⚠️ WARNING: This order has been delivered.\n\nEditing it will create a ledger adjustment that requires L1→L2 approval.\n\nAre you sure you want to continue?')) {
                    return;
                }
            }
            // Continue with edit...
            loadEditForm(orderId);
        });
}
```

### SQL to Add Permission

```sql
-- Add new permission for editing delivered orders
INSERT INTO t_sys_permissions (permission_code, permission_name, permission_description, category, created_at)
VALUES 
('edit_delivered_orders', 'Edit Delivered Orders', 'Allow editing orders that have been marked as delivered and have ledger entries', 'orders', NOW());

-- Grant to admin role (adjust role_id as needed)
INSERT INTO t_sys_role_permissions (role_id, permission_id)
SELECT 1, id FROM t_sys_permissions WHERE permission_code = 'edit_delivered_orders';
```

## Alternative: Auto-Approve Small Adjustments

If you want to allow minor corrections without approval:

```php
// In OrderController@update(), modify the adjustment creation:

if (abs($oldAmount - $newAmount) > 0.01) {
    // Auto-approve if difference is less than Rs. 500
    $isSmallAdjustment = abs($newAmount - $oldAmount) < 500;
    
    if ($isSmallAdjustment) {
        // Auto-approve - no L1/L2 needed
        $requiresL1 = false;
        $requiresL2 = false;
        $adjustment->adjustment_status = 'approved';
        $adjustment->level_1_status = 'approved';
        $adjustment->level_2_status = 'approved';
        $adjustment->approved_at = now();
        $adjustment->approved_by = auth()->id();
        
        \Log::info("Small adjustment auto-approved", [
            'order_id' => $order->id,
            'amount' => abs($newAmount - $oldAmount)
        ]);
    }
}
```

## Workflow Training

### For Staff:
1. **Check prices BEFORE marking as delivered**
2. **If you need to edit after delivery, understand it requires approval**
3. **For urgent corrections, contact a manager**

### For Managers:
1. **Review adjustment requests promptly**
2. **Investigate why corrections are needed after delivery**
3. **Implement better pre-delivery checks**

## Monitoring

Watch for these patterns:
- Multiple adjustments for the same order
- Large time gaps between delivery and edit
- Same staff member creating many adjustments
- Specific products that frequently need corrections

## Implementation Steps

1. ✅ Add the permission to database
2. ✅ Modify controller to check permission
3. ✅ Add frontend warning
4. ✅ Train staff on new workflow
5. ✅ Monitor adjustment patterns for 1 week
6. ✅ Adjust thresholds if needed


