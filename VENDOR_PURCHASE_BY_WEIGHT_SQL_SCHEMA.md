# Vendor Purchase by Weight - SQL Schema

## Overview
This document describes the database tables and columns needed for the "Purchase by Weight" feature, which allows recording vendor purchases with multiple line items based on weighted products.

---

## Tables to Create

### 1. **t_fin_vendor_products** (Vendor Products Catalog)

```sql
CREATE TABLE `t_fin_vendor_products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `vendor_id` INT NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `unit` VARCHAR(50) NOT NULL DEFAULT 'kg' COMMENT 'kg, liter, piece, etc',
  `rate_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Price per unit',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`vendor_id`) REFERENCES `t_fin_vendors`(`id`) ON DELETE CASCADE,
  INDEX `idx_vendor_active` (`vendor_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose**: Stores vendor-specific products with their rates. These are separate from customer products and only used for purchasing.

**Fields**:
- `id`: Primary key
- `vendor_id`: Links to the vendor
- `product_name`: Name of the product (e.g., "Chicken Breast", "Mutton Leg", "Beef Ribs")
- `unit`: Unit of measurement (kg, liter, piece, dozen, etc.)
- `rate_per_unit`: Price per unit in PKR
- `is_active`: Whether the product is currently available
- Timestamps for tracking

---

### 2. **t_fin_vendor_purchase_items** (Purchase Line Items)

```sql
CREATE TABLE `t_fin_vendor_purchase_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ledger_id` INT NOT NULL COMMENT 'Links to t_fin_ledger',
  `vendor_product_id` INT NULL COMMENT 'Links to t_fin_vendor_products if applicable',
  `product_name` VARCHAR(255) NOT NULL COMMENT 'Snapshot of product name at time of purchase',
  `quantity` DECIMAL(10,3) NOT NULL COMMENT 'Quantity purchased',
  `unit` VARCHAR(50) NOT NULL COMMENT 'Snapshot of unit',
  `rate_per_unit` DECIMAL(10,2) NOT NULL COMMENT 'Snapshot of rate at time of purchase',
  `line_total` DECIMAL(10,2) NOT NULL COMMENT 'quantity * rate_per_unit',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`ledger_id`) REFERENCES `t_fin_ledger`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_product_id`) REFERENCES `t_fin_vendor_products`(`id`) ON DELETE SET NULL,
  INDEX `idx_ledger` (`ledger_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose**: Stores individual line items for each weighted purchase transaction.

**Fields**:
- `id`: Primary key
- `ledger_id`: Links to the main ledger transaction
- `vendor_product_id`: Reference to vendor product (nullable if product deleted later)
- `product_name`: Snapshot for historical record
- `quantity`: Amount purchased (e.g., 25.5 kg)
- `unit`: Unit of measurement (snapshot)
- `rate_per_unit`: Rate at time of purchase (snapshot)
- `line_total`: Calculated total for this line
- Timestamps

**Why snapshots?**: If vendor product rates change later, historical purchases remain accurate.

---

## Existing Table Modifications

### **t_fin_ledger** (No changes needed)
The existing `t_fin_ledger` table already has all fields needed:
- `transaction_type` will be `'vendor_purchase'` (same as flat purchase)
- `amount` will be the total of all line items
- `description` will indicate "Weighted Purchase with X items"
- `comments` can store summary of items

---

## Sample SQL Script to Run

```sql
-- =============================================
-- Vendor Purchase by Weight Feature
-- =============================================
-- Run this script to create the required tables
-- =============================================

USE nizamifarms_db;

-- Create vendor products table
CREATE TABLE IF NOT EXISTS `t_fin_vendor_products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `vendor_id` INT NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `unit` VARCHAR(50) NOT NULL DEFAULT 'kg' COMMENT 'kg, liter, piece, etc',
  `rate_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Price per unit',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`vendor_id`) REFERENCES `t_fin_vendors`(`id`) ON DELETE CASCADE,
  INDEX `idx_vendor_active` (`vendor_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create purchase line items table
CREATE TABLE IF NOT EXISTS `t_fin_vendor_purchase_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ledger_id` INT NOT NULL COMMENT 'Links to t_fin_ledger',
  `vendor_product_id` INT NULL COMMENT 'Links to t_fin_vendor_products if applicable',
  `product_name` VARCHAR(255) NOT NULL COMMENT 'Snapshot of product name at time of purchase',
  `quantity` DECIMAL(10,3) NOT NULL COMMENT 'Quantity purchased',
  `unit` VARCHAR(50) NOT NULL COMMENT 'Snapshot of unit',
  `rate_per_unit` DECIMAL(10,2) NOT NULL COMMENT 'Snapshot of rate at time of purchase',
  `line_total` DECIMAL(10,2) NOT NULL COMMENT 'quantity * rate_per_unit',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`ledger_id`) REFERENCES `t_fin_ledger`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`vendor_product_id`) REFERENCES `t_fin_vendor_products`(`id`) ON DELETE SET NULL,
  INDEX `idx_ledger` (`ledger_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification queries
SELECT 'Vendor Products Table Created' as Status, COUNT(*) as RowCount FROM t_fin_vendor_products;
SELECT 'Purchase Items Table Created' as Status, COUNT(*) as RowCount FROM t_fin_vendor_purchase_items;

-- Show table structures
SHOW CREATE TABLE t_fin_vendor_products;
SHOW CREATE TABLE t_fin_vendor_purchase_items;
```

---

## Data Flow

### Recording a Weighted Purchase:
1. User selects "Purchase by Weight" button
2. Modal opens showing vendor's product list
3. User adds multiple line items with quantities
4. System calculates: `line_total = quantity × rate_per_unit`
5. System calculates: `grand_total = SUM(all line_totals)`
6. On submit:
   - Create ONE ledger entry with `amount = grand_total`
   - Create MULTIPLE `t_fin_vendor_purchase_items` records
   - Update vendor and purchase account balances (same as flat purchase)

### Viewing Purchase Details:
- In transaction history, weighted purchases show "📦 Weighted Purchase"
- Clicking shows breakdown of all line items
- Shows: Product | Quantity | Unit | Rate | Total

---

## Benefits of This Design

1. **Separation of Concerns**: Vendor products separate from customer products
2. **Historical Accuracy**: Snapshots preserve purchase details even if rates change
3. **Flexible**: Can add as many line items as needed
4. **Consistent**: Uses existing ledger system, no changes to accounting logic
5. **Scalable**: Can easily add features like product categories, images, etc. later

---

## Next Steps (After Running SQL)

1. ✅ Tables created
2. Add Eloquent models (`VendorProductModel`, `VendorPurchaseItemModel`)
3. Add routes for vendor product management
4. Create UI for managing vendor products
5. Create "Purchase by Weight" modal with line items
6. Add controller method to process weighted purchases

