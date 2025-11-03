# Mobile Invoice Integration Guide

## Overview
This document describes how to integrate invoice viewing and sharing functionality into the Nizami Farms mobile app.

## Backend Changes

### API Endpoints Enhanced
Two existing mobile API endpoints have been enhanced to include invoice URLs:

#### 1. Rider Order Details API
**Endpoint:** `GET /api/rider/orders/{id}`  
**Authentication:** Required (Sanctum token)

**New Response Fields:**
```json
{
  "success": true,
  "order": {
    "id": 2631,
    "order_number": "NF-2631",
    // ... other order fields ...
    "invoice": {
      "image_url": "https://yourdomain.com/orders/2631/invoice/pdf?download_image=1",
      "pdf_url": "https://yourdomain.com/orders/2631/invoice/pdf?force_pdf=1"
    }
  }
}
```

#### 2. Store Open Orders API
**Endpoint:** `GET /api/rider/store/open-orders`  
**Authentication:** Required (Sanctum token)  
**Permission:** `view_open_orders`

**New Response Fields:**
```json
{
  "success": true,
  "orders": [
    {
      "id": 2631,
      "order_number": "NF-2631",
      // ... other order fields ...
      "invoice": {
        "image_url": "https://yourdomain.com/orders/2631/invoice/pdf?download_image=1",
        "pdf_url": "https://yourdomain.com/orders/2631/invoice/pdf?force_pdf=1"
      }
    }
  ]
}
```

## Mobile App Implementation

### 1. Display Invoice Button

Add a "View Invoice" button in the order details screen and order list items:

**For Rider Order Details:**
- Show button in the order details screen
- Button should be visible for all order statuses
- Icon: Document/Receipt icon

**For Store Open Orders:**
- Show button in each order card/list item
- Or add to the order actions menu
- Icon: Document/Receipt icon

### 2. View Invoice (Image Format)

When user taps "View Invoice":

```javascript
// Example implementation (React Native)
import { Linking } from 'react-native';

const viewInvoice = async (orderId, invoiceUrl) => {
  try {
    // Option 1: Open in browser (simplest)
    await Linking.openURL(invoiceUrl);
    
    // Option 2: Download and display in app
    // const response = await fetch(invoiceUrl, {
    //   headers: {
    //     'Authorization': `Bearer ${authToken}`
    //   }
    // });
    // const blob = await response.blob();
    // // Display the image using Image component or file viewer
  } catch (error) {
    console.error('Failed to open invoice:', error);
    Alert.alert('Error', 'Failed to open invoice');
  }
};
```

### 3. Share Invoice

Add a "Share" button next to the "View Invoice" button:

```javascript
// Example implementation (React Native)
import Share from 'react-native-share';
import RNFS from 'react-native-fs';

const shareInvoice = async (orderId, invoiceUrl, authToken) => {
  try {
    // Download the invoice image
    const localFile = `${RNFS.CachesDirectoryPath}/invoice-${orderId}.png`;
    
    const downloadResult = await RNFS.downloadFile({
      fromUrl: invoiceUrl,
      toFile: localFile,
      headers: {
        'Authorization': `Bearer ${authToken}`
      }
    }).promise;
    
    if (downloadResult.statusCode === 200) {
      // Share the downloaded file
      await Share.open({
        url: `file://${localFile}`,
        type: 'image/png',
        title: `Invoice ${orderId}`,
        message: `Invoice for Order #${orderId}`,
        subject: `Invoice ${orderId}`, // For email
        // WhatsApp specific
        social: Share.Social.WHATSAPP, // Optional: force WhatsApp
      });
    }
  } catch (error) {
    console.error('Failed to share invoice:', error);
    Alert.alert('Error', 'Failed to share invoice');
  }
};
```

### 4. Authentication

Both invoice URLs require authentication. Ensure your HTTP requests include the Sanctum token:

```javascript
// Add to your API client
const headers = {
  'Authorization': `Bearer ${authToken}`,
  'Accept': 'application/json',
};
```

### 5. UI/UX Recommendations

#### Button Placement
- **Order Details Screen:** Add buttons in a row below order information
  - [View Invoice] [Share Invoice]
  
- **Order List:** Add icon buttons in the order card
  - [...] (three dots menu) → View Invoice, Share Invoice

#### Loading States
- Show loading spinner while downloading invoice
- Display progress indicator for large files

#### Error Handling
- Network errors: "Failed to load invoice. Please check your connection."
- Authentication errors: "Session expired. Please login again."
- Permission errors: "You don't have permission to view this invoice."

#### Success Feedback
- After sharing: "Invoice shared successfully"
- After viewing: No feedback needed (invoice opens)

### 6. Offline Support (Optional)

Consider caching invoices for offline viewing:

```javascript
// Cache invoice after first download
const cacheInvoice = async (orderId, invoiceUrl, authToken) => {
  const cacheKey = `invoice_${orderId}`;
  const localFile = `${RNFS.DocumentDirectoryPath}/invoices/${orderId}.png`;
  
  // Check if already cached
  const exists = await RNFS.exists(localFile);
  if (exists) {
    return localFile;
  }
  
  // Download and cache
  await RNFS.downloadFile({
    fromUrl: invoiceUrl,
    toFile: localFile,
    headers: { 'Authorization': `Bearer ${authToken}` }
  }).promise;
  
  return localFile;
};
```

## Testing

### Test Scenarios

1. **View Invoice**
   - ✅ Tap "View Invoice" button
   - ✅ Invoice image loads correctly
   - ✅ All order details are visible
   - ✅ Logo and branding are correct
   - ✅ Works for different order statuses

2. **Share Invoice**
   - ✅ Tap "Share" button
   - ✅ Share sheet opens with invoice
   - ✅ Can share via WhatsApp
   - ✅ Can share via Email
   - ✅ Can share via other apps
   - ✅ Shared image is high quality

3. **Authentication**
   - ✅ Works with valid token
   - ✅ Fails gracefully with invalid token
   - ✅ Prompts login if session expired

4. **Permissions**
   - ✅ Riders can view their assigned orders' invoices
   - ✅ Store users can view all open orders' invoices
   - ✅ Cannot view invoices for unauthorized orders

5. **Edge Cases**
   - ✅ Works with slow network
   - ✅ Handles network errors
   - ✅ Works with large invoices (many items)
   - ✅ Works with special characters in customer names

## Invoice Format

The invoice image includes:
- **Header:** Nizami Farms logo and company details
- **Invoice Title:** "INVOICE"
- **Customer Details:** Name, address, phone
- **Order Details:** Order number, order date
- **Items Table:** Product name, SKU, quantity, unit price, total
- **Totals:** Subtotal, discount, shipping, tip, total
- **Footer:** Thank you message and contact information

The invoice is professionally designed with:
- Modern fonts (Poppins)
- Clean layout
- Proper spacing and alignment
- High-resolution logo
- Print-ready format

## API Response Examples

### Full Rider Order Details Response
```json
{
  "success": true,
  "order": {
    "id": 2631,
    "order_number": "NF-2631",
    "order_status": "ready_for_delivery",
    "status_display": "Ready For Delivery",
    "order_date": "2024-11-02",
    "delivery_date": "2024-11-03",
    "payment_method": "cash",
    "payment_type": "cash",
    "payment_label": "Cash",
    "customer": {
      "id": 123,
      "name": "John Doe",
      "phone": "0333-1234567",
      "email": "john@example.com",
      "address": "F-10 Markaz, Islamabad",
      "city": "Islamabad"
    },
    "amounts": {
      "subtotal": 5000,
      "discount": 500,
      "shipping": 200,
      "total": 4700,
      "subtotal_formatted": "Rs. 5,000",
      "discount_formatted": "Rs. 500",
      "shipping_formatted": "Rs. 200",
      "total_formatted": "Rs. 4,700"
    },
    "invoice": {
      "image_url": "https://yourdomain.com/orders/2631/invoice/pdf?download_image=1",
      "pdf_url": "https://yourdomain.com/orders/2631/invoice/pdf?force_pdf=1"
    },
    "line_items": [...],
    "status_history": [...]
  }
}
```

### Full Store Open Orders Response
```json
{
  "success": true,
  "orders": [
    {
      "id": 2631,
      "order_number": "NF-2631",
      "order_date": "2024-11-02",
      "order_status": "pending",
      "total_price": 4700,
      "payment_method": "cash",
      "expected_packets": 3,
      "customer_name": "John Doe",
      "customer_phone": "0333-1234567",
      "customer_address": "F-10 Markaz, Islamabad",
      "customer_id": 123,
      "has_verified_location": true,
      "assigned_rider": {
        "id": 45,
        "name": "Ali Khan"
      },
      "items_count": 5,
      "items_summary": "Chicken Breast (x2), Beef Mince (x1), ...",
      "invoice": {
        "image_url": "https://yourdomain.com/orders/2631/invoice/pdf?download_image=1",
        "pdf_url": "https://yourdomain.com/orders/2631/invoice/pdf?force_pdf=1"
      }
    }
  ],
  "total_count": 1
}
```

## Backend Files Modified

1. **app/Http/Controllers/API/RiderController.php**
   - `getOrderDetails()` method: Added invoice URLs to response
   - `getStoreOpenOrders()` method: Added invoice URLs to each order

## Notes

- Invoice URLs use the existing web routes (`/orders/{id}/invoice/pdf`)
- Authentication is handled via Laravel Sanctum tokens
- Image format (`download_image=1`) generates a PNG file
- PDF format (`force_pdf=1`) generates a PDF file
- Both formats use the same beautiful invoice design
- No new routes or APIs were created (reusing existing functionality)
- Invoice generation happens on-demand (not cached)

## Support

For issues or questions:
- Backend: Check Laravel logs at `storage/logs/laravel.log`
- Mobile: Check console logs for API response errors
- Contact: support@nizamifarms.com

