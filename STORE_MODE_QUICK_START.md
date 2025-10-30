# Store Mode - Quick Start Guide

**Date:** October 30, 2025

---

## 🚀 **Quick Start (3 Steps)**

### **Step 1: Execute SQL** ⚡
```bash
# Run this SQL file in your database:
database/migrations/create_mobile_permissions_tables_oct30.sql
```

This creates:
- `t_sys_mobile_permission` table
- `t_sys_role_mobile_permission` table
- Initial permissions
- Auto-grants permissions to Admin role (ID=1)

---

### **Step 2: Grant Permissions (Webapp)** 🌐

1. Login to webapp as Admin
2. Go to **Admin → Roles**
3. Click the **📱** button next to a role (e.g., "Manager")
4. Check these permissions:
   - ✅ Access Store Mode
   - ✅ View Open Orders
   - ✅ Assign Riders to Orders
   - ✅ Change Order Status
   - ✅ Enter/Edit Packet Information
5. Click **💾 Save Mobile Permissions**

---

### **Step 3: Test Mobile App** 📱

1. **Rebuild Mobile App** (for context provider):
   ```bash
   cd NizamiFarmsMobile
   npm start
   # In another terminal:
   npm run android  # or npm run ios
   ```

2. **Login** with a user who has Store Mode permission

3. **Switch Mode:**
   - Tap the mode toggle button (top right)
   - Shows 🚴 "Rider" or 🏪 "Store"
   - Select "Store Mode"

4. **You'll see:**
   - **2 tabs:** "Open Orders" and "Quantities"
   - **Purple theme** (vs green for Rider Mode)
   - Mode toggle button in header

5. **Switch back anytime:**
   - Tap mode toggle
   - Select "Rider Mode"
   - Back to 4 tabs (Orders, Payment, Requests, Attendance)

---

## 📋 **What's Available Now**

### **Webapp:**
✅ Mobile Permissions management page  
✅ Role-based permission assignment  
✅ Beautiful UI matching existing permissions  

### **Mobile App:**
✅ Mode toggle (Rider ↔ Store)  
✅ Permission checking  
✅ Different tabs per mode  
✅ Persisted mode selection  

### **APIs (Ready to Use):**
✅ `GET /api/rider/permissions` - Get user permissions  
✅ `GET /api/rider/store/open-orders` - Fetch open orders  
✅ `GET /api/rider/store/riders` - Fetch active riders  
✅ `POST /api/rider/store/assign-rider` - Assign rider  
✅ `POST /api/rider/store/update-status` - Change status  
✅ `POST /api/rider/store/update-packets` - Update packets  

---

## 🎯 **What's Next (Phase 2)**

The APIs are ready, but the mobile UI screens need to be built:

1. **Open Orders Screen:**
   - Compact cards showing order info
   - Rider assignment dropdown
   - Status change dropdown
   - Packet info input
   - Grouping by status

2. **Open Order Quantities Screen:**
   - Category hierarchy drill-down
   - Product quantities
   - Same logic as webapp

---

## ❓ **Troubleshooting**

### **Mode toggle not showing?**
- User needs "Access Store Mode" permission
- Check webapp: Admin → Roles → 📱 Mobile Permissions

### **APIs returning 403?**
- User needs specific permissions (view_open_orders, assign_riders, etc.)
- Grant permissions in webapp

### **Mode not persisting?**
- Clear app data and try again
- Check AsyncStorage permissions

---

## 📞 **Support**

For issues or questions:
1. Check `STORE_MODE_PHASE1_COMPLETE.md` for detailed implementation
2. Check `STORE_MODE_IMPLEMENTATION_SUMMARY.md` for overview
3. Review API logs in Laravel log files

---

**Status:** Phase 1 Complete ✅ | Ready for Phase 2

