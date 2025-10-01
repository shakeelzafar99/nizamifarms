# AppSheet Rider Assignment Webhook Setup Guide

## Overview
This webhook allows your AppSheet delivery tracking app to automatically assign riders to orders and update payment methods in real-time, using the same robust logic as the CSV import feature.

---

## 🔗 Webhook Endpoint

**URL**: `https://your-domain.com/webhook/appsheet/rider-assignment`

**Method**: `POST`

**Content-Type**: `application/json`

**Authentication**: None required (public webhook)

---

## 📋 Required Fields

Your AppSheet webhook must send a JSON payload with these **required** fields:

| Field | Description | Format | Example |
|-------|-------------|--------|---------|
| `order_number` | Order number (non-Shopify) | String | `"9145"` |
| `delivery_rider` | Rider full name | String (will be matched against `t_sys_user.fullname`) | `"Arsalan"` or `"Asim Tahir - Indri"` |

---

## 📝 Optional Fields

| Field | Description | Format | Example |
|-------|-------------|--------|---------|
| `payment_method` | Payment method | String (will be normalized) | `"Cash"`, `"Online"`, `"COD"` |
| `date` | Assignment date | Any date format | `"3/3/2025"`, `"2025-03-03"` |

---

## 🔄 Field Name Flexibility

The webhook accepts multiple field name formats (case-insensitive):

- `order_number` / `Order Number` / `Order_Number` / `order number`
- `delivery_rider` / `Delivery_Rider` / `rider_name` / `Rider Name` / `rider name`
- `payment_method` / `Payment_method` / `Payment Method` / `payment method`
- `date` / `Date` / `assigned_at` / `Assigned At`

---

## 📤 Example Webhook Payload

### Your AppSheet Structure (From Screenshot):

**Minimal (required only)**:
```json
{
  "order_number": "<<[Order Number]>>",
  "delivery_rider": "<<[Delivery_Rider]>>"
}
```

**Complete (all fields)**:
```json
{
  "order_number": "<<[Order Number]>>",
  "delivery_rider": "<<[Delivery_Rider]>>",
  "payment_method": "<<[Payment_method]>>",
  "date": "<<[Date]>>"
}
```

### Concrete Examples:

**Example 1: Basic Assignment**
```json
{
  "order_number": "9145",
  "delivery_rider": "Arsalan"
}
```

**Example 2: With Payment Method**
```json
{
  "order_number": "9159",
  "delivery_rider": "Asim Tahir - Indri",
  "payment_method": "Cash",
  "date": "3/3/2025"
}
```

**Example 3: Payment Method Update**
```json
{
  "order_number": "9141",
  "delivery_rider": "Jazib",
  "payment_method": "Online"
}
```

---

## ✅ Response Formats

### Success (200):
```json
{
  "success": true,
  "message": "Rider assigned successfully",
  "order_id": 12345,
  "order_number": "9145",
  "rider_id": 73,
  "rider_name": "Arsalan",
  "assigned_at": "2025-03-03 10:30:00"
}
```

### Success with Payment Method Updated (200):
```json
{
  "success": true,
  "message": "Rider assigned successfully",
  "order_id": 12345,
  "order_number": "9145",
  "rider_id": 73,
  "rider_name": "Arsalan",
  "assigned_at": "2025-03-03 10:30:00",
  "payment_method_updated": true,
  "payment_method": "cash"
}
```

### Error - Missing Required Fields (422):
```json
{
  "success": false,
  "message": "Missing required fields: order_number and delivery_rider"
}
```

### Error - Order Not Found (404):
```json
{
  "success": false,
  "message": "Order not found or is a Shopify order: 9999"
}
```

### Error - Rider Not Found (404):
```json
{
  "success": false,
  "message": "Rider not found in system: Unknown Rider (cleaned: Unknown Rider)"
}
```

---

## 🔧 Smart Features

### 1. **Rider Name Matching**
The webhook uses 4 matching strategies (same as CSV import):
- **Exact match**: `"Arsalan"` matches `"Arsalan"`
- **Case-insensitive**: `"arsalan"` matches `"Arsalan"`
- **Starts with**: `"Ars"` matches `"Arsalan"`
- **Contains**: `"Tahir"` matches `"Asim Tahir"`

### 2. **Name Cleaning**
Automatically removes common suffixes:
- `"Asim Tahir - Indri"` → `"Asim Tahir"`
- `"Arsalan - indrive"` → `"Arsalan"`
- Extra spaces are trimmed

### 3. **Payment Method Normalization**
Uses the **same logic as WooCommerce** to normalize payment methods:

| AppSheet Value | Normalized To | Database Value |
|----------------|---------------|----------------|
| `Cash` | `cash` | `cash` |
| `COD` | `cash_on_delivery` | `cash_on_delivery` |
| `Online` | `online` | `online` |
| `Card` | `card` | `card` |
| `Bank Transfer` | `bank_transfer` | `bank_transfer` |
| `PayPal` | `online` | `online` |
| `Stripe` | `online` | `online` |

**Smart partial matching**:
- Contains "cash" or "cod" → `cash`
- Contains "bank" or "transfer" → `bank_transfer`
- Contains "card" or "visa" → `card`
- Contains "online" or "paypal" → `online`

### 4. **Payment Method Update Logic**
- If `payment_method` is provided in webhook
- System normalizes it (e.g., `"Cash"` → `"cash"`)
- Compares with current order payment method
- **Only updates if different** (no unnecessary database writes)
- Logs both old and new values

**Example Flow**:
```
Order 9145 current payment method: "online"
Webhook receives: "Cash"
Normalized to: "cash"
Comparison: "online" != "cash"
Action: Update database
Log: "Payment method updated from 'online' to 'cash'"
```

### 5. **Rider Assignment History**
- Creates history record in `t_ops_order_rider_history`
- Sets `is_current = 1` for new assignment
- Sets `is_current = 0` for previous assignments
- Preserves full timeline for audit trail
- Updates denormalized `assigned_rider_user_id` on order

### 6. **Safe Re-assignments**
- Can assign rider multiple times (corrects mistakes)
- Previous assignments preserved in history
- No data loss

---

## 🛠️ AppSheet Configuration Steps

### Step 1: Create a Bot/Automation
1. In AppSheet, go to **Automation** → **Bots**
2. Click **New Bot**
3. Name it: `Delivery Rider Assignment`

### Step 2: Configure the Trigger
Choose your trigger event:
- **Option A**: When a rider is assigned (column changes)
- **Option B**: When order status changes to specific value
- **Option C**: On button click

Example trigger condition:
```
[Delivery_Rider] IS NOT BLANK
```

### Step 3: Add a Webhook Task
1. Add a new task to your bot
2. Select **Call a webhook**
3. Configure:
   - **URL**: `https://your-domain.com/webhook/appsheet/rider-assignment`
   - **Method**: `POST`
   - **Headers**: 
     ```
     Content-Type: application/json
     ```
   - **Body**: (Map your AppSheet columns)
     ```json
     {
       "order_number": <<[Order Number]>>,
       "delivery_rider": <<[Delivery_Rider]>>,
       "payment_method": <<[Payment_method]>>,
       "date": <<[Date]>>
     }
     ```

### Step 4: Test the Webhook
1. In AppSheet, use the **Test** button to simulate the webhook
2. Check your Laravel logs: `storage/logs/laravel.log`
3. Look for: `AppSheet rider-assignment webhook received`

---

## 🐛 Debugging & Troubleshooting

### Check Laravel Logs
All webhook activity is logged:
```bash
tail -f storage/logs/laravel.log | grep "rider-assignment"
```

### Common Issues

#### Issue 1: Order Not Found
**Error**: `"Order not found or is a Shopify order: 9145"`

**Solutions**:
1. Check if order exists in Orders page
2. Verify it's a **non-Shopify** order
3. Check exact order number (no spaces, commas, etc.)
4. Shopify orders are intentionally excluded

#### Issue 2: Rider Not Found
**Error**: `"Rider not found in system: Unknown Rider"`

**Solutions**:
1. Check if rider exists in Users table
2. Verify spelling matches exactly
3. Name cleaning removes "- indrive", "- Indri" automatically
4. Try partial match (webhook tries 4 strategies)
5. Add rider to Users first if missing

#### Issue 3: Payment Method Not Updating
**Check logs for**:
```
AppSheet rider-assignment: payment method unchanged
```

**This means**:
- Payment method was provided
- It was normalized correctly
- But it matches current value (no update needed)

**To force update**:
- Check current payment method in database
- Ensure AppSheet is sending different value

#### Issue 4: Webhook Not Firing
**Solutions**:
1. Check AppSheet Bot logs
2. Verify the URL is correct
3. Test with the `/test` endpoint first: `POST /webhook/appsheet/test`
4. Check Laravel routes: `php artisan route:list | grep rider`
5. Ensure trigger condition is met

---

## 🔍 What Happens Behind the Scenes

For this webhook payload:
```json
{
  "order_number": "9159",
  "delivery_rider": "Asim Tahir - Indri",
  "payment_method": "Cash",
  "date": "3/3/2025"
}
```

**Processing Steps**:
1. ✅ **Extract fields** with case-insensitive fallbacks
2. ✅ **Validate** order_number and delivery_rider present
3. ✅ **Find order** in `t_crm_prod_order` (non-Shopify only)
4. ✅ **Clean rider name**: `"Asim Tahir - Indri"` → `"Asim Tahir"`
5. ✅ **Match rider** using 4 strategies in `t_sys_user`
6. ✅ **Normalize payment method**: `"Cash"` → `"cash"`
7. ✅ **Compare payment methods**: If different, update order
8. ✅ **Call** `OrderModel::assignRider()`
9. ✅ **Update history** in `t_ops_order_rider_history`
10. ✅ **Update order** table with `assigned_rider_user_id`
11. ✅ **Return success** with rider and order details

---

## 📊 Monitoring

### Check Recent Assignments
```sql
SELECT 
    o.order_number,
    u.fullname AS rider_name,
    o.payment_method,
    h.assigned_at,
    h.notes,
    h.source
FROM t_crm_prod_order o
LEFT JOIN t_sys_user u ON u.id = o.assigned_rider_user_id
LEFT JOIN t_ops_order_rider_history h ON h.order_id = o.id AND h.is_current = 1
WHERE h.source = 'api'
AND h.notes = 'AppSheet webhook'
ORDER BY h.assigned_at DESC
LIMIT 20;
```

### Count Today's Webhook Assignments
```sql
SELECT COUNT(*) AS webhook_assignments_today
FROM t_ops_order_rider_history
WHERE source = 'api'
AND notes = 'AppSheet webhook'
AND DATE(assigned_at) = CURDATE();
```

### Laravel Log Search
```bash
# Success logs
grep "AppSheet rider-assignment: assignment successful" storage/logs/laravel.log

# Error logs
grep "AppSheet rider-assignment error" storage/logs/laravel.log

# Payment method updates
grep "payment method updated" storage/logs/laravel.log

# Rider not found
grep "rider not found" storage/logs/laravel.log
```

---

## 🧪 Testing the Webhook

### Using cURL:
```bash
curl -X POST https://your-domain.com/webhook/appsheet/rider-assignment \
  -H "Content-Type: application/json" \
  -d '{
    "order_number": "9145",
    "delivery_rider": "Arsalan",
    "payment_method": "Cash",
    "date": "3/3/2025"
  }'
```

### Using Postman:
1. Method: `POST`
2. URL: `https://your-domain.com/webhook/appsheet/rider-assignment`
3. Headers: `Content-Type: application/json`
4. Body (raw JSON):
   ```json
   {
     "order_number": "9145",
     "delivery_rider": "Arsalan",
     "payment_method": "Cash",
     "date": "3/3/2025"
   }
   ```

### Test Cases:

**Test 1: Basic Assignment**
```json
{
  "order_number": "9145",
  "delivery_rider": "Arsalan"
}
```
Expected: Rider assigned, payment method unchanged

**Test 2: With Payment Method**
```json
{
  "order_number": "9145",
  "delivery_rider": "Arsalan",
  "payment_method": "Online"
}
```
Expected: Rider assigned, payment method updated if different

**Test 3: Name with Suffix**
```json
{
  "order_number": "9159",
  "delivery_rider": "Asim Tahir - Indri"
}
```
Expected: Suffix removed, rider matched, assigned

**Test 4: Case Insensitive**
```json
{
  "order_number": "9141",
  "delivery_rider": "JAZIB"
}
```
Expected: Matched case-insensitively, assigned

**Test 5: Missing Rider**
```json
{
  "order_number": "9145",
  "delivery_rider": "Nonexistent Person"
}
```
Expected: 404 error "Rider not found"

**Test 6: Missing Order**
```json
{
  "order_number": "999999",
  "delivery_rider": "Arsalan"
}
```
Expected: 404 error "Order not found"

**Test 7: Shopify Order**
```json
{
  "order_number": "5001",
  "delivery_rider": "Arsalan"
}
```
Expected: 404 error "is a Shopify order" (if 5001 is Shopify)

---

## 📈 Best Practices

1. **Test with One Order First**
   - Send one webhook call
   - Verify assignment in Orders page
   - Check payment method if provided
   - Then enable for all orders

2. **Handle Rider Names Consistently**
   - Use exact names from Users table
   - Or let system clean them (removes suffixes)
   - Partial matching works but exact is faster

3. **Payment Method Updates**
   - Only send if you want to update
   - System won't update if value is same
   - Normalization is automatic

4. **Monitor Logs**
   - Check for rider/order not found errors
   - Review payment method normalization
   - Track successful assignments

5. **Handle Failures Gracefully**
   - Bot can retry on failure
   - Check response status code
   - Log errors in AppSheet for investigation

---

## 🔒 Security Considerations

### Current State:
- Webhook is **public** (no authentication required)
- Same as existing AppSheet webhooks (status, attendance)
- Logs all activity for audit trail

### Data Validation:
- ✅ Non-Shopify orders only (Shopify excluded)
- ✅ Rider must exist in Users table
- ✅ Order must exist in database
- ✅ All assignments logged with source: 'api', notes: 'AppSheet webhook'

---

## ✨ Comparison with CSV Import

| Feature | CSV Import | AppSheet Webhook |
|---------|-----------|------------------|
| **Real-time** | ❌ Manual | ✅ Automatic |
| **Batch Processing** | ✅ Multiple records | ❌ One at a time |
| **Payment Method Update** | ❌ No | ✅ Yes |
| **Rider Matching** | ✅ 4 strategies | ✅ 4 strategies |
| **Name Cleaning** | ✅ Yes | ✅ Yes |
| **Error Summary** | ✅ Full report | ❌ Per-record only |
| **History Tracking** | ✅ Yes | ✅ Yes |
| **Non-Shopify Only** | ✅ Yes | ✅ Yes |

**Recommendation**: Use **both**!
- **Webhook** for real-time daily assignments
- **CSV Import** for bulk historical data or corrections

---

## 📞 Support

If you encounter issues:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Test with minimal payload (order + rider only)
3. Verify rider exists in Users table
4. Verify order exists and is non-Shopify
5. Check payment method normalization in logs
6. Review AppSheet bot execution logs

---

**Created**: October 2025  
**Last Updated**: October 2025  
**Version**: 1.0

