# Store Mode Implementation Plan

**Date:** October 30, 2025  
**Status:** 📋 PLANNING

---

## 📋 **Overview**

Implement a dual-mode mobile app:
1. **Rider Mode** (current) - For delivery riders
2. **Store Mode** (new) - For store managers/staff

**Key Features:**
- Permission-based mode access (controlled from webapp)
- Open Orders management (compact view, rider assignment, status changes)
- Open Order Quantities (category hierarchy drill-down)

---

## 🎯 **Requirements**

### **1. Mobile Permissions System (Webapp)**
- Create a permissions management page similar to existing roles/permissions
- Allow admins to control:
  - Who can access Store Mode
  - Which Store Mode features each role can access
  - Granular permissions for future features (expenses, etc.)

### **2. Store Mode Toggle (Mobile)**
- Mode switcher in mobile app header/menu
- Check user permissions from API
- Show/hide modes based on permissions
- Different bottom navigation for each mode

### **3. Open Orders Management (Mobile - Store Mode)**
**Tab 1: Open Orders**
- Compact card view (customer name, order number, item count)
- Group by status
- Actions:
  - Assign rider (same logic as webapp)
  - Change status (same rules as webapp)
  - Enter packet information (initial entry, not delivery confirmation)

### **4. Open Order Quantities (Mobile - Store Mode)**
**Tab 2: Open Order Quantities**
- Start with Category Level 1 summary cards
- Drill down through hierarchy (Level 1 → Level 2 → Level 3 → Products)
- Show quantities at each level
- Mobile-optimized UI (touch-friendly, good UX)

---

## 🗄️ **Database Changes**

### **New Table: `t_sys_mobile_permission`**
```sql
CREATE TABLE t_sys_mobile_permission (
    id INT PRIMARY KEY AUTO_INCREMENT,
    permission_code VARCHAR(100) UNIQUE NOT NULL,
    permission_name VARCHAR(255) NOT NULL,
    permission_group VARCHAR(100) NOT NULL, -- 'store_mode', 'rider_mode', etc.
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### **New Table: `t_sys_role_mobile_permission`**
```sql
CREATE TABLE t_sys_role_mobile_permission (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role_id INT NOT NULL,
    mobile_permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES t_sys_role(id) ON DELETE CASCADE,
    FOREIGN KEY (mobile_permission_id) REFERENCES t_sys_mobile_permission(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role_id, mobile_permission_id)
);
```

### **Initial Mobile Permissions:**
```sql
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description) VALUES
-- Store Mode Access
('access_store_mode', 'Access Store Mode', 'store_mode', 'Can switch to and use Store Mode'),

-- Open Orders
('view_open_orders', 'View Open Orders', 'store_mode', 'Can view open orders list'),
('assign_riders', 'Assign Riders', 'store_mode', 'Can assign riders to orders'),
('change_order_status', 'Change Order Status', 'store_mode', 'Can change order status'),
('enter_packet_info', 'Enter Packet Information', 'store_mode', 'Can enter packet information for orders'),

-- Open Order Quantities
('view_open_quantities', 'View Open Order Quantities', 'store_mode', 'Can view open order quantities'),

-- Future Features
('manage_store_expenses', 'Manage Store Expenses', 'store_mode', 'Can manage expenses in store mode'),
('view_store_reports', 'View Store Reports', 'store_mode', 'Can view store reports');
```

---

## 📂 **File Structure**

### **Backend (Laravel):**
```
app/
├── Http/Controllers/
│   ├── Admin/
│   │   └── MobilePermissionController.php (NEW)
│   └── API/
│       ├── StoreController.php (NEW)
│       └── RiderController.php (existing)
├── Models/
│   ├── SysAdmin/
│   │   ├── MobilePermissionModel.php (NEW)
│   │   └── RoleMobilePermissionModel.php (NEW)
│   └── ...
database/migrations/
└── create_mobile_permissions_tables.sql (NEW)

resources/views/pages/
├── admin/
│   └── mobile-permissions.blade.php (NEW)
└── ...

routes/
├── web.php (add mobile permissions routes)
└── api.php (add store mode routes)
```

### **Mobile App (React Native):**
```
src/
├── screens/
│   ├── StoreMode/
│   │   ├── OpenOrdersScreen.js (NEW)
│   │   ├── OpenQuantitiesScreen.js (NEW)
│   │   ├── OrderDetailsStoreScreen.js (NEW)
│   │   └── QuantityDrillDownScreen.js (NEW)
│   └── ...
├── components/
│   ├── ModeToggle.js (NEW)
│   ├── CompactOrderCard.js (NEW)
│   ├── CategoryCard.js (NEW)
│   └── ...
├── services/
│   ├── storeService.js (NEW)
│   └── permissionsService.js (NEW)
├── navigation/
│   ├── index.js (update for dual modes)
│   ├── RiderTabs.js (NEW - extract current tabs)
│   └── StoreTabs.js (NEW)
└── contexts/
    └── ModeContext.js (NEW - manage current mode)
```

---

## 🔄 **API Endpoints**

### **Mobile Permissions:**
```
GET  /api/rider/permissions          - Get current user's mobile permissions
```

### **Store Mode - Open Orders:**
```
GET  /api/store/open-orders           - Get open orders grouped by status
POST /api/store/orders/{id}/assign-rider  - Assign rider to order
POST /api/store/orders/{id}/change-status - Change order status
POST /api/store/orders/{id}/set-packets   - Set packet information
```

### **Store Mode - Open Quantities:**
```
GET  /api/store/open-quantities       - Get category level 1 summary
GET  /api/store/open-quantities/drill-down - Drill down to next level
```

---

## 🎨 **Mobile UI Design**

### **Mode Toggle:**
```
┌─────────────────────────────────────┐
│  [Rider Mode ▼]                     │  ← Dropdown in header
│  • Rider Mode                       │
│  • Store Mode ✓ (if has permission) │
└─────────────────────────────────────┘
```

### **Store Mode - Bottom Navigation:**
```
┌─────────────────────────────────────┐
│  [Open Orders] [Open Quantities]    │
└─────────────────────────────────────┘
```

### **Open Orders - Compact Card:**
```
┌─────────────────────────────────────┐
│ 📦 NF-14567  |  👤 Ahmad Khan       │
│ 5 items      |  🚚 Assign Rider     │
│ Status: Ready for Delivery          │
└─────────────────────────────────────┘
```

### **Open Quantities - Category Card:**
```
┌─────────────────────────────────────┐
│ 🥬 Fresh Vegetables                 │
│ Total Qty: 150 kg                   │
│ 12 products  |  Tap to drill down → │
└─────────────────────────────────────┘
```

---

## 🔒 **Permission Checks**

### **Backend:**
```php
// Check if user has mobile permission
if (!$user->hasMobilePermission('access_store_mode')) {
    return response()->json(['error' => 'Access denied'], 403);
}
```

### **Mobile:**
```javascript
// Check permission before showing UI
if (userPermissions.includes('assign_riders')) {
    // Show "Assign Rider" button
}
```

---

## 📊 **Implementation Phases**

### **Phase 1: Foundation (Week 1)**
- ✅ Create database tables
- ✅ Create mobile permissions management page (webapp)
- ✅ Create API endpoint for user permissions
- ✅ Implement mode toggle in mobile app

### **Phase 2: Open Orders (Week 2)**
- ✅ Create Open Orders API endpoints
- ✅ Implement Open Orders screen (mobile)
- ✅ Add rider assignment functionality
- ✅ Add status change functionality
- ✅ Add packet information entry

### **Phase 3: Open Quantities (Week 3)**
- ✅ Create Open Quantities API endpoints
- ✅ Implement category hierarchy drill-down
- ✅ Create mobile UI for quantities
- ✅ Test drill-down functionality

### **Phase 4: Testing & Refinement (Week 4)**
- ✅ End-to-end testing
- ✅ Permission testing
- ✅ UX refinements
- ✅ Documentation

---

## ⚠️ **Important Considerations**

### **1. Reuse Webapp Logic:**
- ✅ Rider assignment: Use existing `OrderController` logic
- ✅ Status changes: Follow existing status transition rules
- ✅ Packet information: Use existing validation rules
- ✅ Open quantities: Reuse existing calculation logic

### **2. Performance:**
- ✅ Paginate open orders list
- ✅ Cache category hierarchy
- ✅ Optimize API responses (only send needed data)

### **3. Security:**
- ✅ Always check permissions on backend
- ✅ Never trust frontend permission checks alone
- ✅ Log all rider assignments and status changes

### **4. UX:**
- ✅ Clear visual distinction between modes
- ✅ Confirm actions (rider assignment, status change)
- ✅ Show loading states
- ✅ Handle errors gracefully

---

## 🧪 **Testing Checklist**

### **Webapp - Mobile Permissions:**
- [ ] Can create/edit mobile permissions
- [ ] Can assign permissions to roles
- [ ] Permissions save correctly
- [ ] UI matches existing permissions page

### **Mobile - Mode Toggle:**
- [ ] Users with permission see Store Mode option
- [ ] Users without permission don't see Store Mode
- [ ] Mode switch works smoothly
- [ ] Correct tabs show for each mode

### **Mobile - Open Orders:**
- [ ] Orders display correctly
- [ ] Can assign rider
- [ ] Can change status
- [ ] Can enter packet info
- [ ] Actions follow webapp rules

### **Mobile - Open Quantities:**
- [ ] Category Level 1 displays correctly
- [ ] Can drill down to Level 2
- [ ] Can drill down to Level 3
- [ ] Can drill down to products
- [ ] Quantities are accurate

---

## 📝 **Next Steps**

1. **Review this plan** - Confirm approach and requirements
2. **Start Phase 1** - Database and permissions system
3. **Iterative development** - Build and test each phase
4. **User feedback** - Refine based on actual usage

---

**Estimated Timeline:** 3-4 weeks  
**Complexity:** High  
**Priority:** High

---

## ❓ **Questions to Confirm**

1. Should Store Mode completely replace the bottom navigation, or should users be able to switch back to Rider Mode easily?
2. For Open Orders, which statuses should be shown? (e.g., pending, ready_for_delivery, out_for_delivery)
3. For rider assignment, should we show all riders or only active/available riders?
4. Should packet information be editable after initial entry?
5. For Open Quantities, should we show all categories or only those with open orders?

---

**Ready to proceed?** Please review and confirm, then I'll start with Phase 1!

