# Mobile App - Packet Tracking & Delivery Location Implementation Guide
**Date:** October 26, 2025

## Overview
This guide explains how to implement packet tracking in the mobile app when riders mark orders as delivered. The implementation is **non-blocking** - riders can still deliver even if they enter wrong packet counts or skip the prompt.

---

## 🎯 Requirements

### 1. Packet Tracking Flow
- **IF** order has `expected_packets` → Show prompt to enter actual packets
- **IF** no `expected_packets` → Skip prompt, deliver normally
- **IF** mismatch → Show warning but still allow delivery
- **IF** match → Show success message and deliver
- **Always** allow delivery regardless of packet count

### 2. Existing Flow Must Work
- ✅ GPS location capture (already working)
- ✅ Ledger posting (must not be affected)
- ✅ Status change to "delivered" (must not be affected)
- ✅ All existing functionality preserved

---

## 📱 Mobile App Implementation

### Step 1: Update Order Details Screen

**File:** `lib/screens/order_details_screen.dart` (or equivalent)

#### Display Expected Packets
```dart
// In your order details widget, after order notes section:

if (order.expectedPackets != null && order.expectedPackets! > 0) {
  Card(
    color: Colors.amber[50],
    margin: EdgeInsets.symmetric(vertical: 8),
    child: Padding(
      padding: EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.inventory_2, color: Colors.amber[900], size: 20),
              SizedBox(width: 8),
              Text(
                'Packet Information',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: Colors.amber[900],
                ),
              ),
            ],
          ),
          SizedBox(height: 12),
          Container(
            padding: EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Expected Packets:',
                  style: TextStyle(
                    fontSize: 14,
                    color: Colors.grey[600],
                  ),
                ),
                Text(
                  '${order.expectedPackets} packet(s)',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
              ],
            ),
          ),
          SizedBox(height: 8),
          Text(
            '💡 You will be asked to verify this count when marking as delivered',
            style: TextStyle(
              fontSize: 12,
              color: Colors.grey[600],
              fontStyle: FontStyle.italic,
            ),
          ),
        ],
      ),
    ),
  )
}
```

---

### Step 2: Update Mark as Delivered Flow

**File:** `lib/services/order_service.dart` (or equivalent)

#### Modified Delivery Function
```dart
Future<void> markAsDelivered({
  required int orderId,
  required Order order,
  Position? position,
  String? notes,
}) async {
  try {
    // Step 1: Check if order has expected packets
    int? actualPackets;
    
    if (order.expectedPackets != null && order.expectedPackets! > 0) {
      // Show packet count dialog
      actualPackets = await _showPacketCountDialog(
        context: context,
        expectedPackets: order.expectedPackets!,
      );
      
      // If user cancelled the dialog, don't proceed
      if (actualPackets == null) {
        return; // User cancelled
      }
    }
    
    // Step 2: Prepare API request
    final requestBody = {
      'latitude': position?.latitude,
      'longitude': position?.longitude,
      'notes': notes,
      'actual_packets': actualPackets, // Can be null if no expected packets
    };
    
    // Step 3: Call API (existing flow)
    final response = await http.post(
      Uri.parse('$baseUrl/api/rider/orders/$orderId/delivered'),
      headers: {
        'Authorization': 'Bearer $token',
        'Content-Type': 'application/json',
      },
      body: jsonEncode(requestBody),
    );
    
    // Step 4: Handle response
    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      if (data['success']) {
        // Show success message
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('✅ Order marked as delivered successfully!'),
            backgroundColor: Colors.green,
          ),
        );
        
        // Navigate back or refresh
        Navigator.pop(context);
      }
    } else {
      throw Exception('Failed to mark as delivered');
    }
    
  } catch (e) {
    // Show error message
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('❌ Error: ${e.toString()}'),
        backgroundColor: Colors.red,
      ),
    );
  }
}
```

---

### Step 3: Create Packet Count Dialog

**File:** `lib/widgets/packet_count_dialog.dart` (new file)

```dart
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

Future<int?> showPacketCountDialog({
  required BuildContext context,
  required int expectedPackets,
}) async {
  final TextEditingController controller = TextEditingController();
  bool showMismatchWarning = false;
  
  return await showDialog<int>(
    context: context,
    barrierDismissible: false, // User must tap button to close
    builder: (BuildContext context) {
      return StatefulBuilder(
        builder: (context, setState) {
          return AlertDialog(
            title: Row(
              children: [
                Icon(Icons.inventory_2, color: Colors.amber[700]),
                SizedBox(width: 8),
                Text('Verify Packet Count'),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Expected packets info
                  Container(
                    padding: EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.amber[50],
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: Colors.amber[200]!),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Expected:',
                          style: TextStyle(
                            fontSize: 14,
                            color: Colors.grey[700],
                          ),
                        ),
                        Text(
                          '$expectedPackets packet(s)',
                          style: TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            color: Colors.amber[900],
                          ),
                        ),
                      ],
                    ),
                  ),
                  
                  SizedBox(height: 16),
                  
                  // Input field
                  Text(
                    'Enter actual packet count:',
                    style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                  SizedBox(height: 8),
                  TextField(
                    controller: controller,
                    keyboardType: TextInputType.number,
                    inputFormatters: [
                      FilteringTextInputFormatter.digitsOnly,
                    ],
                    decoration: InputDecoration(
                      hintText: 'Enter number',
                      border: OutlineInputBorder(),
                      prefixIcon: Icon(Icons.numbers),
                    ),
                    onChanged: (value) {
                      if (value.isNotEmpty) {
                        final entered = int.tryParse(value);
                        setState(() {
                          showMismatchWarning = entered != null && entered != expectedPackets;
                        });
                      } else {
                        setState(() {
                          showMismatchWarning = false;
                        });
                      }
                    },
                    autofocus: true,
                  ),
                  
                  // Mismatch warning
                  if (showMismatchWarning) ...[
                    SizedBox(height: 12),
                    Container(
                      padding: EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.orange[50],
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: Colors.orange[300]!),
                      ),
                      child: Row(
                        children: [
                          Icon(Icons.warning_amber, color: Colors.orange[700], size: 20),
                          SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'Count doesn\'t match! Expected: $expectedPackets',
                              style: TextStyle(
                                fontSize: 13,
                                color: Colors.orange[900],
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                  
                  SizedBox(height: 12),
                  
                  // Info message
                  Container(
                    padding: EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: Colors.blue[50],
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Row(
                      children: [
                        Icon(Icons.info_outline, size: 16, color: Colors.blue[700]),
                        SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            'You can still deliver even if counts don\'t match',
                            style: TextStyle(
                              fontSize: 11,
                              color: Colors.blue[900],
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            actions: [
              // Cancel button
              TextButton(
                onPressed: () {
                  Navigator.of(context).pop(null); // Return null = cancelled
                },
                child: Text('Cancel'),
              ),
              
              // Confirm button
              ElevatedButton(
                onPressed: () {
                  final value = controller.text.trim();
                  if (value.isEmpty) {
                    // Show error
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text('Please enter packet count'),
                        backgroundColor: Colors.red,
                      ),
                    );
                    return;
                  }
                  
                  final packets = int.tryParse(value);
                  if (packets == null || packets < 0) {
                    // Show error
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text('Please enter a valid number'),
                        backgroundColor: Colors.red,
                      ),
                    );
                    return;
                  }
                  
                  Navigator.of(context).pop(packets); // Return the count
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.green,
                ),
                child: Text('Confirm & Deliver'),
              ),
            ],
          );
        },
      );
    },
  );
}
```

---

### Step 4: Update Order Model

**File:** `lib/models/order.dart` (or equivalent)

```dart
class Order {
  final int id;
  final String orderNumber;
  final String orderStatus;
  final double totalPrice;
  final int? expectedPackets;  // NEW: Add this field
  final int? actualPackets;    // NEW: Add this field
  // ... other fields
  
  Order({
    required this.id,
    required this.orderNumber,
    required this.orderStatus,
    required this.totalPrice,
    this.expectedPackets,  // NEW
    this.actualPackets,    // NEW
    // ... other fields
  });
  
  factory Order.fromJson(Map<String, dynamic> json) {
    return Order(
      id: json['id'],
      orderNumber: json['order_number'],
      orderStatus: json['order_status'],
      totalPrice: double.parse(json['amounts']['total'].toString()),
      expectedPackets: json['expected_packets'],  // NEW
      actualPackets: json['actual_packets'],      // NEW
      // ... other fields
    );
  }
}
```

---

## 🔄 Complete Flow Diagram

```
┌─────────────────────────────────────────┐
│  Rider clicks "Mark as Delivered"      │
└──────────────┬──────────────────────────┘
               │
               ▼
       ┌───────────────────┐
       │ Has expected_     │
       │ packets?          │
       └───┬───────────┬───┘
           │ YES       │ NO
           ▼           ▼
    ┌──────────┐   ┌──────────────────┐
    │ Show     │   │ Skip prompt,     │
    │ Packet   │   │ deliver normally │
    │ Dialog   │   │ (existing flow)  │
    └────┬─────┘   └──────────────────┘
         │
         ▼
    ┌──────────────┐
    │ User enters  │
    │ actual count │
    └────┬─────────┘
         │
         ▼
    ┌──────────────────┐
    │ Count matches?   │
    └───┬──────────┬───┘
        │ YES      │ NO
        ▼          ▼
    ┌────────┐  ┌──────────────────┐
    │ Show   │  │ Show warning but │
    │ ✅     │  │ allow delivery   │
    └────┬───┘  └────┬─────────────┘
         │           │
         └───────┬───┘
                 ▼
         ┌───────────────────┐
         │ Call API with:    │
         │ - GPS location    │
         │ - actual_packets  │
         │ - notes           │
         └────────┬──────────┘
                  ▼
         ┌───────────────────┐
         │ Backend:          │
         │ - Saves packets   │
         │ - Saves location  │
         │ - Posts to ledger │
         │ - Changes status  │
         └────────┬──────────┘
                  ▼
         ┌───────────────────┐
         │ Success!          │
         │ Order delivered   │
         └───────────────────┘
```

---

## ✅ Testing Checklist

### Webapp Testing
- [ ] View delivered order → See delivery location section
- [ ] Click "View on Google Maps" → Opens correct location
- [ ] View order with packet mismatch → Shows warning badge
- [ ] View order with packet match → Shows verified badge

### Mobile App Testing
- [ ] Order with expected_packets → Shows packet info on details screen
- [ ] Order without expected_packets → No packet info shown
- [ ] Mark delivered with packets → Prompt appears
- [ ] Enter matching count → Shows success, delivers
- [ ] Enter mismatching count → Shows warning, still delivers
- [ ] Cancel packet dialog → Delivery cancelled
- [ ] Skip packet prompt (no expected) → Delivers normally
- [ ] GPS location captured → Stored correctly
- [ ] Ledger posting → Works as before
- [ ] Order status → Changes to delivered

---

## 🔒 Safety Guarantees

### What This DOES NOT Affect:
- ✅ Ledger posting logic (unchanged)
- ✅ GPS location capture (enhanced, not changed)
- ✅ Status change flow (unchanged)
- ✅ Existing delivery process (only adds optional prompt)
- ✅ Orders without packets (work exactly as before)

### What This ADDS:
- ✅ Optional packet verification
- ✅ Visual feedback for mismatches
- ✅ Delivery location display in webapp
- ✅ Non-blocking user experience

---

## 📝 API Reference

### GET `/api/rider/orders/{id}`
**Response includes:**
```json
{
  "success": true,
  "order": {
    "id": 2613,
    "order_number": "NF-14558",
    "expected_packets": 4,
    "actual_packets": null,
    ...
  }
}
```

### POST `/api/rider/orders/{id}/delivered`
**Request body:**
```json
{
  "latitude": 33.6844,
  "longitude": 73.0479,
  "actual_packets": 4,
  "notes": "Delivered successfully"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Order marked as delivered successfully",
  "order": {
    "id": 2613,
    "order_number": "NF-14558",
    "order_status": "delivered"
  }
}
```

---

## 📄 Files to Modify

### Mobile App
1. `lib/models/order.dart` - Add packet fields
2. `lib/screens/order_details_screen.dart` - Display expected packets
3. `lib/services/order_service.dart` - Update delivery function
4. `lib/widgets/packet_count_dialog.dart` - NEW: Create dialog

### Backend (Already Done ✅)
1. ✅ `app/Http/Controllers/API/RiderController.php` - Accepts actual_packets
2. ✅ `app/Http/Controllers/CRM/OrderController.php` - Returns delivery_location
3. ✅ `app/Models/CRM/OrderModel.php` - Has packet fields

### Webapp (Already Done ✅)
4. ✅ `resources/views/pages/orders/index.blade.php` - Shows packets & location

---

**Status:** Backend & Webapp Complete ✅ | Mobile App Implementation Pending 📱  
**Priority:** Medium (Enhancement, not critical)  
**Risk:** Very Low (non-blocking, optional feature)

