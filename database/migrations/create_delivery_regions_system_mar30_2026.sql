-- ============================================================================
-- MIGRATION: Delivery Regions System
-- Date: March 30, 2026
-- Purpose: Region-based delivery management with auto-detection and rider assignment
--
-- Tables Created:
--   1. t_ops_delivery_region      - Core delivery regions (DHA2, Bahria8, Islamabad, Rawalpindi)
--   2. t_ops_delivery_area        - Named areas mapped to regions (for address keyword matching)
--   3. t_ops_rider_region         - Rider-to-region assignments (many-to-many)
--
-- Columns Added:
--   4. t_crm_prod_customer.delivery_region_id
--   5. t_crm_prod_order.delivery_region_id
--
-- NOTE: Run this AFTER backing up your database.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- TABLE 1: t_ops_delivery_region
-- Master table for the 4 core delivery regions
-- ============================================================================
DROP TABLE IF EXISTS `t_ops_rider_region`;
DROP TABLE IF EXISTS `t_ops_delivery_area`;
DROP TABLE IF EXISTS `t_ops_delivery_region`;

CREATE TABLE `t_ops_delivery_region` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Display name: DHA Phase 2, Bahria Phase 8, etc.',
    `code` VARCHAR(30) NOT NULL UNIQUE COMMENT 'Short code: dha2, bahria8, isb, rwp',
    `color` VARCHAR(7) DEFAULT '#3B82F6' COMMENT 'Hex color for map display',
    `polygon_coordinates` JSON NULL COMMENT 'Array of [lat,lng] pairs forming the boundary polygon',
    `center_lat` DECIMAL(10, 7) NULL COMMENT 'Center latitude for map display',
    `center_lng` DECIMAL(11, 7) NULL COMMENT 'Center longitude for map display',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Core delivery regions for order routing and rider assignment';

-- ============================================================================
-- TABLE 2: t_ops_delivery_area
-- Named areas/sectors mapped to regions (for address text matching)
-- ============================================================================
CREATE TABLE `t_ops_delivery_area` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `region_id` INT UNSIGNED NOT NULL COMMENT 'FK to t_ops_delivery_region',
    `area_name` VARCHAR(200) NOT NULL COMMENT 'Primary name: F-6, DHA Phase 1, PWD Colony, etc.',
    `keywords` JSON NULL COMMENT 'Alternative names/spellings for matching: ["f6","f-6","f 6","sector f6"]',
    `center_lat` DECIMAL(10, 7) NULL COMMENT 'Optional center for map marker',
    `center_lng` DECIMAL(11, 7) NULL COMMENT 'Optional center for map marker',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_area_region` FOREIGN KEY (`region_id`)
        REFERENCES `t_ops_delivery_region`(`id`) ON DELETE CASCADE,

    INDEX `idx_area_region` (`region_id`),
    INDEX `idx_area_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Named areas mapped to delivery regions for address-based detection';

-- ============================================================================
-- TABLE 3: t_ops_rider_region
-- Many-to-many: riders assigned to delivery regions
-- ============================================================================
CREATE TABLE `t_ops_rider_region` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `rider_user_id` INT NOT NULL COMMENT 'FK to t_sys_user (rider)',
    `region_id` INT UNSIGNED NOT NULL COMMENT 'FK to t_ops_delivery_region',
    `is_primary` TINYINT(1) DEFAULT 0 COMMENT 'Primary region for this rider',
    `is_active` TINYINT(1) DEFAULT 1,
    `assigned_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_rider_region_region` FOREIGN KEY (`region_id`)
        REFERENCES `t_ops_delivery_region`(`id`) ON DELETE CASCADE,

    UNIQUE KEY `uk_rider_region` (`rider_user_id`, `region_id`),
    INDEX `idx_rr_rider` (`rider_user_id`),
    INDEX `idx_rr_region` (`region_id`),
    INDEX `idx_rr_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rider-to-region assignments for delivery routing';

-- ============================================================================
-- COLUMN ADDITIONS: delivery_region_id on customer and order tables
-- ============================================================================

ALTER TABLE `t_crm_prod_customer`
    ADD COLUMN `delivery_region_id` INT UNSIGNED NULL
        COMMENT 'Auto-detected delivery region for this customer'
        AFTER `geocoded_at`,
    ADD COLUMN `delivery_region_source` VARCHAR(30) NULL
        COMMENT 'How region was detected: polygon_verified, polygon_geocoded, keyword, city, manual'
        AFTER `delivery_region_id`,
    ADD INDEX `idx_customer_region` (`delivery_region_id`);

ALTER TABLE `t_crm_prod_order`
    ADD COLUMN `delivery_region_id` INT UNSIGNED NULL
        COMMENT 'Delivery region for this order (copied from customer or detected from address)'
        AFTER `assigned_rider_user_id`,
    ADD INDEX `idx_order_region` (`delivery_region_id`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED: Insert the 4 core delivery regions
-- ============================================================================
INSERT INTO `t_ops_delivery_region` (`name`, `code`, `color`, `center_lat`, `center_lng`, `sort_order`, `is_active`) VALUES
('DHA Phase 2',     'dha2',    '#EF4444', 33.5200, 73.1000, 1, 1),
('Bahria Phase 8',  'bahria8', '#F59E0B', 33.5150, 73.0600, 2, 1),
('Islamabad',       'isb',     '#3B82F6', 33.6938, 73.0551, 3, 1),
('Rawalpindi',      'rwp',     '#10B981', 33.5651, 73.0169, 4, 1);

-- ============================================================================
-- SEED: Known areas of Islamabad/Rawalpindi mapped to regions
-- ============================================================================

-- DHA Phase 2 region areas
INSERT INTO `t_ops_delivery_area` (`region_id`, `area_name`, `keywords`) VALUES
((SELECT id FROM t_ops_delivery_region WHERE code='dha2'), 'DHA Phase 1', '["dha phase 1","dha 1","dha-1","defence phase 1","defense phase 1"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='dha2'), 'DHA Phase 2', '["dha phase 2","dha 2","dha-2","defence phase 2","defense phase 2"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='dha2'), 'DHA Phase 3', '["dha phase 3","dha 3","dha-3","defence phase 3","defense phase 3"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='dha2'), 'DHA Phase 5', '["dha phase 5","dha 5","dha-5","defence phase 5","defense phase 5"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='dha2'), 'Askari 14', '["askari 14","askari-14","askari xiv"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='dha2'), 'Media Town', '["media town","mediatown"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='dha2'), 'Civic Centre DHA', '["civic centre dha","civic center dha","dha civic"]');

-- Bahria Phase 8 region areas
INSERT INTO `t_ops_delivery_area` (`region_id`, `area_name`, `keywords`) VALUES
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Town Phase 1', '["bahria phase 1","bahria town phase 1","bahria-1","bahria 1"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Town Phase 2', '["bahria phase 2","bahria town phase 2","bahria-2","bahria 2"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Town Phase 3', '["bahria phase 3","bahria town phase 3","bahria-3","bahria 3"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Town Phase 4', '["bahria phase 4","bahria town phase 4","bahria-4","bahria 4"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Town Phase 5', '["bahria phase 5","bahria town phase 5","bahria-5","bahria 5"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Town Phase 6', '["bahria phase 6","bahria town phase 6","bahria-6","bahria 6"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Town Phase 7', '["bahria phase 7","bahria town phase 7","bahria-7","bahria 7"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Town Phase 8', '["bahria phase 8","bahria town phase 8","bahria-8","bahria 8"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Enclave', '["bahria enclave","bahria town enclave"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Springs', '["bahria springs"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='bahria8'), 'Bahria Orchard', '["bahria orchard"]');

-- Islamabad region areas (sectors, markaz, major areas)
INSERT INTO `t_ops_delivery_area` (`region_id`, `area_name`, `keywords`) VALUES
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'F-5', '["f-5","f5","f 5","sector f-5","sector f5"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'F-6', '["f-6","f6","f 6","sector f-6","sector f6","f-6 markaz","f6 markaz"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'F-7', '["f-7","f7","f 7","sector f-7","sector f7","f-7 markaz","f7 markaz","jinnah super"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'F-8', '["f-8","f8","f 8","sector f-8","sector f8","f-8 markaz","f8 markaz"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'F-9', '["f-9","f9","f 9","sector f-9","sector f9"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'F-10', '["f-10","f10","f 10","sector f-10","sector f10","f-10 markaz","f10 markaz"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'F-11', '["f-11","f11","f 11","sector f-11","sector f11","f-11 markaz","f11 markaz"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-5', '["g-5","g5","g 5","sector g-5","sector g5"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-6', '["g-6","g6","g 6","sector g-6","sector g6","blue area"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-7', '["g-7","g7","g 7","sector g-7","sector g7"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-8', '["g-8","g8","g 8","sector g-8","sector g8","g-8 markaz","g8 markaz"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-9', '["g-9","g9","g 9","sector g-9","sector g9","g-9 markaz","g9 markaz","karachi company"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-10', '["g-10","g10","g 10","sector g-10","sector g10","g-10 markaz","g10 markaz"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-11', '["g-11","g11","g 11","sector g-11","sector g11","g-11 markaz","g11 markaz"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-12', '["g-12","g12","g 12","sector g-12"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-13', '["g-13","g13","g 13","sector g-13"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-14', '["g-14","g14","g 14","sector g-14"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'G-15', '["g-15","g15","g 15","sector g-15"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'H-8', '["h-8","h8","h 8","sector h-8"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'H-9', '["h-9","h9","h 9","sector h-9"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'H-10', '["h-10","h10","h 10","sector h-10"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'H-11', '["h-11","h11","h 11","sector h-11"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'H-12', '["h-12","h12","h 12","sector h-12"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'H-13', '["h-13","h13","h 13","sector h-13"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'I-8', '["i-8","i8","i 8","sector i-8"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'I-9', '["i-9","i9","i 9","sector i-9","i-9 industrial","industrial area i-9"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'I-10', '["i-10","i10","i 10","sector i-10","i-10 markaz","i10 markaz"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'I-11', '["i-11","i11","i 11","sector i-11"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'I-14', '["i-14","i14","i 14","sector i-14"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'I-16', '["i-16","i16","i 16","sector i-16"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'E-7', '["e-7","e7","e 7","sector e-7"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'E-11', '["e-11","e11","e 11","sector e-11"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'D-12', '["d-12","d12","d 12","sector d-12"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'D-17', '["d-17","d17","d 17","sector d-17"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Blue Area', '["blue area","jinnah avenue","blue area islamabad"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Diplomatic Enclave', '["diplomatic enclave","embassy road"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Bani Gala', '["bani gala","banigala"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Soan Garden', '["soan garden","soan gardens"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'PWD Colony', '["pwd","pwd colony","pwd housing","pwd society"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Pakistan Town', '["pakistan town"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'CBR Town', '["cbr town","cbr"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Naval Anchorage', '["naval anchorage"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Margalla Hills', '["margalla","margalla hills","margalla town"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Chak Shahzad', '["chak shahzad"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='isb'), 'Golra Sharif', '["golra","golra sharif"]');

-- Rawalpindi region areas
INSERT INTO `t_ops_delivery_area` (`region_id`, `area_name`, `keywords`) VALUES
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Satellite Town', '["satellite town","satellite town rawalpindi"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Saddar', '["saddar","saddar rawalpindi","sadar"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Chaklala Scheme 3', '["chaklala scheme 3","chaklala scheme III","chaklala-3","chaklala 3"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Chaklala Scheme 1', '["chaklala scheme 1","chaklala scheme I","chaklala-1","chaklala 1"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Westridge', '["westridge","west ridge"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Askari 1', '["askari 1","askari-1","askari i"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Askari 2', '["askari 2","askari-2","askari ii"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Askari 3', '["askari 3","askari-3","askari iii"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Askari 4', '["askari 4","askari-4","askari iv"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Askari 5', '["askari 5","askari-5","askari v"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Askari 10', '["askari 10","askari-10","askari x"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Askari 11', '["askari 11","askari-11","askari xi"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Gulraiz', '["gulraiz","gulraiz housing","gulraiz rawalpindi"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Shamsabad', '["shamsabad"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Adiala Road', '["adiala road","adiala"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Airport Area', '["airport","airport housing","new islamabad airport","airport society"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Rawat', '["rawat"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Kalma Chowk', '["kalma chowk"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Commercial Market', '["commercial market rawalpindi"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Murree Road', '["murree road"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), '6th Road', '["6th road","sixth road"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Tench Bhatta', '["tench bhatta"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Liaquat Bagh', '["liaquat bagh"]'),
((SELECT id FROM t_ops_delivery_region WHERE code='rwp'), 'Dhoke Kashmirian', '["dhoke kashmirian"]');

-- ============================================================================
-- SUCCESS MESSAGE
-- ============================================================================
SELECT '✅ Delivery Regions System created successfully!' AS status;
SELECT 'Tables: t_ops_delivery_region, t_ops_delivery_area, t_ops_rider_region' AS tables;
SELECT 'Columns added: delivery_region_id on t_crm_prod_customer and t_crm_prod_order' AS columns;
SELECT CONCAT('Regions seeded: ', (SELECT COUNT(*) FROM t_ops_delivery_region), ' regions, ', (SELECT COUNT(*) FROM t_ops_delivery_area), ' areas') AS seed_data;
