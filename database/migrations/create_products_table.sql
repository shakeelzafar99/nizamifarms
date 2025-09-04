-- Products table for Shopify integration
CREATE TABLE IF NOT EXISTS nizamifarms_db.t_crm_prod_product (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  -- Shopify Identity
  shopify_product_id    BIGINT NULL,                                       -- Shopify product ID
  shopify_handle        VARCHAR(255) NULL,                                 -- URL handle from Shopify
  
  -- Basic Product Info
  title                 VARCHAR(500) NOT NULL,                             -- Product name
  description           TEXT NULL,                                         -- Product description
  vendor                VARCHAR(150) NULL,                                 -- Brand/manufacturer
  product_type          VARCHAR(150) NULL,                                 -- Category/type
  
  -- Status & Visibility
  status                ENUM('active','archived','draft') DEFAULT 'active',
  published_at          DATETIME NULL,                                     -- When published on Shopify
  
  -- Pricing (from default variant or price range)
  price_min             DECIMAL(10,2) NULL,                                -- Minimum price across variants
  price_max             DECIMAL(10,2) NULL,                                -- Maximum price across variants
  compare_at_price      DECIMAL(10,2) NULL,                                -- Original price (for discounts)
  
  -- Inventory
  total_inventory       INT DEFAULT 0,                                     -- Total stock across variants
  track_inventory       TINYINT(1) DEFAULT 1,                             -- Whether to track inventory
  
  -- SEO & Media
  seo_title             VARCHAR(255) NULL,
  seo_description       TEXT NULL,
  featured_image        TEXT NULL,                                         -- Main product image URL
  images                JSON NULL,                                         -- Array of all image URLs
  
  -- Organization
  tags                  JSON NULL,                                         -- Product tags array
  options               JSON NULL,                                         -- Product options (Size, Color, etc.)
  
  -- Shopify Metadata
  shopify_created_at    DATETIME NULL,                                     -- Original creation date in Shopify
  shopify_updated_at    DATETIME NULL,                                     -- Last update in Shopify
  
  -- Internal Tracking
  sync_status           ENUM('synced','pending','error') DEFAULT 'pending',
  last_synced_at        DATETIME NULL,                                     -- Last successful sync
  sync_error            TEXT NULL,                                         -- Error message if sync failed
  
  -- Audit
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  created_by            BIGINT NULL,
  updated_by            BIGINT NULL,
  created_at            DATETIME NULL,
  updated_at            DATETIME NULL,
  
  -- Indexes
  UNIQUE KEY uq_shopify_product_id (shopify_product_id),
  INDEX idx_product_status (status),
  INDEX idx_product_vendor (vendor),
  INDEX idx_product_type (product_type),
  INDEX idx_product_handle (shopify_handle),
  INDEX idx_product_sync_status (sync_status),
  INDEX idx_product_published (published_at),
  FULLTEXT KEY ft_product_search (title, description, vendor, product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product Variants table (since Shopify products can have multiple variants)
CREATE TABLE IF NOT EXISTS nizamifarms_db.t_crm_prod_product_variant (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id            BIGINT UNSIGNED NOT NULL,
  
  -- Shopify Identity
  shopify_variant_id    BIGINT NULL,                                       -- Shopify variant ID
  
  -- Variant Details
  title                 VARCHAR(255) NULL,                                 -- Variant title (e.g., "Large / Red")
  sku                   VARCHAR(100) NULL,                                 -- Stock keeping unit
  barcode               VARCHAR(100) NULL,                                 -- Product barcode
  
  -- Pricing
  price                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,               -- Variant price
  compare_at_price      DECIMAL(10,2) NULL,                                -- Original price
  cost_price            DECIMAL(10,2) NULL,                                -- Cost price (if available)
  
  -- Inventory
  inventory_quantity    INT DEFAULT 0,                                     -- Current stock level
  inventory_policy      ENUM('deny','continue') DEFAULT 'deny',           -- Allow overselling?
  
  -- Physical Properties
  weight                DECIMAL(8,2) NULL,                                 -- Weight in grams
  weight_unit           VARCHAR(10) DEFAULT 'g',                          -- Weight unit
  
  -- Variant Options (Size, Color, etc.)
  option1               VARCHAR(255) NULL,                                 -- First option value
  option2               VARCHAR(255) NULL,                                 -- Second option value  
  option3               VARCHAR(255) NULL,                                 -- Third option value
  
  -- Status
  available             TINYINT(1) DEFAULT 1,                             -- Is variant available for sale
  
  -- Shopify Metadata
  position              INT DEFAULT 1,                                     -- Display order
  shopify_created_at    DATETIME NULL,
  shopify_updated_at    DATETIME NULL,
  
  -- Audit
  created_by            BIGINT NULL,
  updated_by            BIGINT NULL,
  created_at            DATETIME NULL,
  updated_at            DATETIME NULL,
  
  -- Foreign Keys & Indexes
  CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES t_crm_prod_product(id) ON DELETE CASCADE,
  UNIQUE KEY uq_shopify_variant_id (shopify_variant_id),
  INDEX idx_variant_product (product_id),
  INDEX idx_variant_sku (sku),
  INDEX idx_variant_barcode (barcode),
  INDEX idx_variant_available (available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
