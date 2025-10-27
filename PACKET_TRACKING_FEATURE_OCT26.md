# Packet Tracking Feature - October 26, 2025

## Overview
Added optional packet tracking functionality to orders, allowing managers/admins to specify expected number of packets and riders to enter actual number delivered for verification purposes.

## Purpose
- **Manager/Admin**: Enter expected number of packets when preparing an order
- **Rider**: Enter actual number of packets delivered when marking order as delivered
- **Verification**: System shows if there's a mismatch between expected and actual packets
- **Non-blocking**: This is informational only - does not affect ledger or delivery flow

## Database Changes

### New Columns Added to `t_crm_prod_order`
```sql
expected_packets INT UNSIGNED NULL DEFAULT NULL
  COMMENT 'Number of packets expected (entered by manager/admin)'

actual_packets INT UNSIGNED NULL DEFAULT NULL  
  COMMENT 'Number of packets actually delivered (entered by rider)'
```

**Migration File**: `database/migrations/add_packet_tracking_to_orders_oct26.sql`

## Backend Changes

### 1. OrderModel (`app/Models/CRM/OrderModel.php`)

**Added to `$fillable` array:**
```php
'expected_packets',  // Number of packets expected (entered by manager/admin)
'actual_packets',    // Number of packets delivered (entered by rider)
```

**Added to `$casts` array:**
```php
'expected_packets' => 'integer',
'actual_packets' => 'integer'
```

### 2. Mobile API (`app/Http/Controllers/API/RiderController.php`)

#### `markOrderDelivered()` Method
**Accepts new optional parameter:**
- `actual_packets` (integer, optional)

**Logic:**
```php
// Update actual_packets if provided by rider
if ($actualPackets !== null && is_numeric($actualPackets)) {
    $order->actual_packets = (int)$actualPackets;
    $order->save();
    
    \Log::info('Rider entered packet count', [
        'order_id' => $order->id,
        'expected_packets' => $order->expected_packets,
        'actual_packets' => $actualPackets,
        'match' => $order->expected_packets == $actualPackets
    ]);
}
```

#### `getOrderDetails()` Method
**Added to response:**
```php
'expected_packets' => $order->expected_packets,
'actual_packets' => $order->actual_packets,
```

## Frontend Changes

### 1. Webapp - Edit Order Modal (`resources/views/pages/orders/index.blade.php`)

**Added Packet Tracking Section** (after Order Notes, before Line Items):

```html
<!-- Packet Tracking Section (Optional) -->
<div style="background-color: #fef3c7; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fbbf24;">
    <h4 style="font-weight: 600; color: #92400e; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
        <span>📦</span> Packet Tracking (Optional)
    </h4>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        <div>
            <label>Expected Packets (Manager/Admin)</label>
            <input type="number" name="expected_packets" value="${order.expected_packets || ''}" 
                   min="0" step="1" placeholder="Enter number of packets">
            <p>Number of packets you're sending with this order</p>
        </div>
        <div>
            <label>Actual Packets Delivered (Rider)</label>
            <input type="number" name="actual_packets" value="${order.actual_packets || ''}" 
                   min="0" step="1" readonly>
            <p>
                ${order.actual_packets ? 
                    (order.expected_packets && order.actual_packets != order.expected_packets ? 
                        `⚠️ Mismatch detected!` : 
                        `✅ Verified`) : 
                    'Rider will enter this on delivery'}
            </p>
        </div>
    </div>
</div>
```

**Features:**
- ✅ Expected packets input (editable by manager/admin)
- ✅ Actual packets display (read-only, entered by rider)
- ✅ Visual indicator for match/mismatch
- ✅ Yellow background to indicate optional feature

### 2. Webapp - Invoice View Modal (`resources/views/pages/orders/index.blade.php`)

**Added Packet Tracking Display** (after Order Notes, before Rider Assignment):

```javascript
// Packet Tracking Section (if packet data exists)
if (order.expected_packets || order.actual_packets) {
    html += '<div style="padding: 20px; background-color: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; margin: 20px 0 0 0;">';
    html += '<h3>📦 Packet Tracking</h3>';
    html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">';
    html += '<div>Expected Packets (Manager): ' + (order.expected_packets || '-') + '</div>';
    html += '<div>Actual Packets Delivered (Rider): ' + (order.actual_packets || '-') + '</div>';
    if (order.expected_packets && order.actual_packets) {
        if (order.expected_packets != order.actual_packets) {
            html += '<div>⚠️ Mismatch Detected</div>';
        } else {
            html += '<div>✅ Verified</div>';
        }
    }
    html += '</div>';
    html += '</div>';
}
```

**Features:**
- ✅ Only shows if packet data exists
- ✅ Shows both expected and actual packets
- ✅ Visual indicator for match/mismatch
- ✅ Consistent styling with edit modal

## Mobile App Integration

### Required Changes in Mobile App

#### 1. Order Details Screen
**Display expected_packets from API response:**
```dart
// In order details widget
if (order.expectedPackets != null) {
  Card(
    color: Colors.amber[50],
    child: ListTile(
      leading: Icon(Icons.inventory_2, color: Colors.amber[900]),
      title: Text('Expected Packets'),
      subtitle: Text('Manager specified: ${order.expectedPackets} packet(s)'),
    ),
  )
}
```

#### 2. Delivery Confirmation Dialog
**Prompt for actual_packets when marking as delivered:**
```dart
// Before calling markAsDelivered API
if (order.expectedPackets != null) {
  // Show dialog to enter actual packets
  final actualPackets = await showDialog<int>(
    context: context,
    builder: (context) => PacketCountDialog(
      expectedPackets: order.expectedPackets,
    ),
  );
  
  // Include in API call
  await markAsDelivered(
    orderId: order.id,
    actualPackets: actualPackets,
    latitude: position.latitude,
    longitude: position.longitude,
  );
} else {
  // No packet tracking - proceed as normal
  await markAsDelivered(orderId: order.id);
}
```

#### 3. API Call Update
**Add `actual_packets` parameter to markAsDelivered:**
```dart
Future<void> markAsDelivered({
  required int orderId,
  int? actualPackets,  // NEW: Optional packet count
  double? latitude,
  double? longitude,
  String? notes,
}) async {
  final response = await http.post(
    Uri.parse('$baseUrl/api/rider/orders/$orderId/delivered'),
    headers: {'Authorization': 'Bearer $token'},
    body: jsonEncode({
      'actual_packets': actualPackets,  // NEW
      'latitude': latitude,
      'longitude': longitude,
      'notes': notes,
    }),
  );
}
```

## User Flow

### Manager/Admin Flow (Webapp)
1. Create or edit an order
2. (Optional) Enter expected number of packets in "Packet Tracking" section
3. Save order
4. Expected packets stored in database

### Rider Flow (Mobile App)
1. View assigned order
2. See expected packets (if manager entered it)
3. Deliver order
4. When marking as delivered:
   - If expected_packets exists → App prompts for actual count
   - If no expected_packets → Skip packet prompt
5. Enter actual packet count
6. Submit delivery confirmation
7. Actual packets stored in database

### Verification Flow (Webapp)
1. View order/invoice details
2. See "Packet Tracking" section (if data exists)
3. Compare expected vs actual:
   - ✅ **Match**: Green "Verified" badge
   - ⚠️ **Mismatch**: Red "Mismatch Detected" badge
4. Take action if needed (informational only)

## Technical Details

### Data Validation
- **Both fields are optional** (NULL allowed)
- **Type**: Unsigned Integer (0 or positive numbers only)
- **No maximum limit** set in database
- **Frontend validation**: `min="0"` `step="1"`

### API Endpoints

#### GET `/api/rider/orders/{id}`
**Response includes:**
```json
{
  "success": true,
  "order": {
    "id": 123,
    "order_number": "NF-14558",
    "expected_packets": 3,
    "actual_packets": null,
    ...
  }
}
```

#### POST `/api/rider/orders/{id}/delivered`
**Request body (optional):**
```json
{
  "actual_packets": 3,
  "latitude": 33.6844,
  "longitude": 73.0479,
  "notes": "Delivered successfully"
}
```

### Logging
All packet tracking actions are logged:
```php
\Log::info('Rider entered packet count', [
    'order_id' => $order->id,
    'expected_packets' => $order->expected_packets,
    'actual_packets' => $actualPackets,
    'match' => $order->expected_packets == $actualPackets
]);
```

## Important Notes

### ✅ What This Feature DOES:
- Allows optional packet count tracking
- Shows visual indicators for mismatches
- Logs packet data for audit trail
- Provides verification information

### ❌ What This Feature DOES NOT Do:
- **Does NOT block order delivery** if packets mismatch
- **Does NOT affect ledger posting** or financial calculations
- **Does NOT require packet count** to mark as delivered
- **Does NOT send notifications** for mismatches
- **Does NOT prevent order updates** if packets are wrong

### Why Non-Blocking?
- Packet tracking is **informational only**
- Allows flexibility in operations
- Rider can still deliver even if they forget to count
- Manager can review mismatches later
- No impact on existing delivery workflow

## Testing Checklist

- [x] Database migration runs successfully
- [x] OrderModel includes new fields in $fillable and $casts
- [x] Webapp edit modal shows packet tracking section
- [x] Webapp invoice view shows packet tracking (if data exists)
- [x] Mobile API accepts actual_packets parameter
- [x] Mobile API returns expected_packets in order details
- [x] Marking order as delivered works with/without packet count
- [x] Ledger posting still works correctly
- [x] No breaking changes to existing delivery flow
- [ ] Mobile app displays expected packets
- [ ] Mobile app prompts for actual packets on delivery
- [ ] Mobile app sends actual_packets to API

## Files Modified

### Backend
1. `database/migrations/add_packet_tracking_to_orders_oct26.sql` - NEW
2. `app/Models/CRM/OrderModel.php` - Modified ($fillable, $casts)
3. `app/Http/Controllers/API/RiderController.php` - Modified (markOrderDelivered, getOrderDetails)

### Frontend
4. `resources/views/pages/orders/index.blade.php` - Modified (edit modal, view modal)

### Documentation
5. `PACKET_TRACKING_FEATURE_OCT26.md` - NEW (this file)

## Future Enhancements (Optional)

If needed, could add:
1. **Notifications**: Alert manager when mismatch detected
2. **Reports**: Dashboard showing packet mismatch statistics
3. **Mandatory Mode**: Make packet count required for specific customers
4. **Photo Upload**: Allow rider to upload photo of packets
5. **Barcode Scanning**: Scan packet barcodes instead of manual count
6. **Historical Trends**: Track packet accuracy per rider over time

---
**Status**: ✅ Backend Complete, Frontend Complete, Mobile App Integration Pending  
**Risk Level**: Very Low (optional feature, no impact on existing flows)  
**Rollback**: Easy (columns can be dropped, feature can be hidden)

