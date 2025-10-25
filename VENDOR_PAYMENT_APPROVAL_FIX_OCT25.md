# Vendor Payment Approval Fix - Oct 25, 2025

## Issue Reported
User configured "Vendor Payment" in Request Settings with **NO approval levels** (no Level 1, no Level 2), expecting all vendor payments to auto-approve.

However:
- ✅ **Cash payments** (from NF Cash) → Auto-approved correctly
- ❌ **Online bank payments** → Still went to approvals

## Root Cause

The vendor payment approval logic in `VendorController.php` was **hardcoded** and **ignored the approval configuration settings**.

### Old Logic (Lines 531-536)
```php
// Determine approval status based on source account
// Online accounts or manager cash accounts require approval
$requiresApproval = in_array($paymentAccount->account_code, ['ONLINE']) || 
                   $paymentAccount->account_category === 'employee_cash';

$approvalStatus = $requiresApproval ? LedgerModel::STATUS_PENDING : LedgerModel::STATUS_APPROVED;
```

**Problem:** This hardcoded logic forced:
1. All `ONLINE` account payments → Pending approval
2. All `employee_cash` account payments → Pending approval
3. **Completely ignored** the Request Settings configuration

## Solution

Modified the approval logic to **check the approval configuration** from the database before determining if approval is required.

### New Logic (Lines 531-554)
```php
// Check approval configuration for vendor payments
$vendorPaymentCategory = \App\Models\Request\RequestCategoryModel::getByCode('vendor_payment');
$requiresApproval = false;

if ($vendorPaymentCategory && $vendorPaymentCategory->approvalConfig) {
    // Check if any approval level is required
    $requiresApproval = $vendorPaymentCategory->approvalConfig->requires_level_1 || 
                       $vendorPaymentCategory->approvalConfig->requires_level_2;
}

$approvalStatus = $requiresApproval ? LedgerModel::STATUS_PENDING : LedgerModel::STATUS_APPROVED;
$mode = ($paymentAccount->account_code === 'ONLINE') ? LedgerModel::MODE_ONLINE : LedgerModel::MODE_CASH;

\Log::info("Vendor payment approval check", [
    'vendor_id' => $vendor->id,
    'vendor_name' => $vendor->vendor_name,
    'amount' => $request->amount,
    'payment_source' => $paymentAccount->account_name,
    'payment_source_code' => $paymentAccount->account_code,
    'requires_approval' => $requiresApproval,
    'approval_status' => $approvalStatus,
    'category_found' => $vendorPaymentCategory ? true : false,
    'config_exists' => $vendorPaymentCategory && $vendorPaymentCategory->approvalConfig ? true : false
]);
```

## How It Works Now

1. **Fetches approval configuration** from `t_req_category_approval_config` table
2. **Checks if Level 1 OR Level 2 is required**
3. **If neither level is required** → Auto-approve
4. **If any level is required** → Send to approvals
5. **Logs the decision** for debugging

## Expected Behavior

### Scenario 1: No Approval Required (Current Settings)
- ✅ Cash payments → Auto-approved
- ✅ Online bank payments → Auto-approved
- ✅ Employee cash payments → Auto-approved

### Scenario 2: Level 1 Required
- ❌ All payments → Pending approval (need Level 1)

### Scenario 3: Level 1 + Level 2 Required
- ❌ All payments → Pending approval (need both levels)

## Files Modified

1. **app/Http/Controllers/FIN/VendorController.php**
   - Lines 531-554: Updated `recordPayment()` method
   - Added approval configuration check
   - Added detailed logging

## Testing Steps

1. Go to **Finance → Vendors → [Any Vendor]**
2. Click "Record Payment"
3. Enter amount and select **Online Bank** as source
4. Submit payment
5. **Expected:** Payment should be auto-approved immediately
6. **Check logs** for approval decision details

## Database Schema

### Request Category
```sql
SELECT * FROM t_req_category WHERE category_code = 'vendor_payment';
```

### Approval Configuration
```sql
SELECT c.category_name, ac.requires_level_1, ac.requires_level_2, ac.auto_approve_threshold
FROM t_req_category c
LEFT JOIN t_req_category_approval_config ac ON c.id = ac.category_id
WHERE c.category_code = 'vendor_payment';
```

## Notes

- The `mode` field still correctly identifies payment type (CASH vs ONLINE)
- This fix ensures consistency with other approval workflows in the system
- The approval configuration is centralized in the Request Settings page
- Logging helps debug approval decisions in production

## Related Files

- `app/Models/Request/RequestCategoryModel.php` - Category model
- `app/Models/Request/RequestCategoryApprovalConfigModel.php` - Approval config model
- `resources/views/admin/requests/settings.blade.php` - Settings UI

