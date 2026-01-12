-- =====================================================
-- Product Change History Table
-- Created: December 26, 2025
-- Purpose: Track all changes to products for audit trail
-- =====================================================

-- Create the product change history table
CREATE TABLE IF NOT EXISTS t_crm_prod_product_change_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_product',
    variant_id BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_product_variant (null if product-level change)',
    user_id INT(11) NULL COMMENT 'FK to t_sys_user table - who made the change',
    change_type VARCHAR(50) NOT NULL COMMENT 'Type: sku_change, price_change, category_change, name_change, etc.',
    field_name VARCHAR(100) NOT NULL COMMENT 'Field that was changed',
    old_value TEXT NULL COMMENT 'Previous value',
    new_value TEXT NULL COMMENT 'New value',
    change_source VARCHAR(50) DEFAULT 'web' COMMENT 'Source: web, mobile, api, system',
    ip_address VARCHAR(45) NULL COMMENT 'IP address of the request',
    user_agent VARCHAR(500) NULL COMMENT 'Browser/app user agent',
    notes TEXT NULL COMMENT 'Additional notes or context',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_product_id (product_id),
    INDEX idx_variant_id (variant_id),
    INDEX idx_user_id (user_id),
    INDEX idx_change_type (change_type),
    INDEX idx_created_at (created_at),
    INDEX idx_product_created (product_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key constraints
ALTER TABLE t_crm_prod_product_change_history
ADD CONSTRAINT fk_product_change_product 
    FOREIGN KEY (product_id) REFERENCES t_crm_prod_product(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_product_change_variant 
    FOREIGN KEY (variant_id) REFERENCES t_crm_prod_product_variant(id) ON DELETE SET NULL,
ADD CONSTRAINT fk_product_change_user 
    FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE SET NULL;

-- =====================================================
-- Verification Query
-- =====================================================
-- SELECT 'Product Change History table created successfully' AS status;
-- DESCRIBE t_crm_prod_product_change_history;

