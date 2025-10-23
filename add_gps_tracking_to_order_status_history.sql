-- =====================================================
-- ADD GPS TRACKING TO ORDER STATUS HISTORY
-- =====================================================
-- Purpose: Track GPS location when riders mark orders as delivered via mobile app
-- Table: t_crm_order_status_history
-- New Columns: delivery_latitude, delivery_longitude
-- =====================================================

-- Check current table structure
SELECT 'Current table structure:' as Info;
DESCRIBE t_crm_order_status_history;

-- Add GPS tracking columns
SELECT '' as '';
SELECT '==================================' as Info;
SELECT 'Adding GPS tracking columns...' as Info;
SELECT '==================================' as Info;

ALTER TABLE t_crm_order_status_history
ADD COLUMN delivery_latitude DECIMAL(10, 8) NULL COMMENT 'GPS latitude when status changed (for delivered status)' AFTER notes,
ADD COLUMN delivery_longitude DECIMAL(11, 8) NULL COMMENT 'GPS longitude when status changed (for delivered status)' AFTER delivery_latitude;

-- Verify the changes
SELECT '' as '';
SELECT 'Verification: New columns added' as Info;
DESCRIBE t_crm_order_status_history;

SELECT '' as '';
SELECT '==================================' as Info;
SELECT 'GPS tracking columns added successfully!' as Info;
SELECT '==================================' as Info;
SELECT 'Columns added:' as Info;
SELECT '  - delivery_latitude DECIMAL(10,8) - Stores GPS latitude' as Details;
SELECT '  - delivery_longitude DECIMAL(11,8) - Stores GPS longitude' as Details;
SELECT '' as '';
SELECT 'Usage:' as Info;
SELECT '  - Mobile app will capture GPS when marking orders as delivered' as Details;
SELECT '  - Stored in order status history for audit trail' as Details;
SELECT '  - NULL for status changes that are not delivery-related' as Details;

-- Show sample of existing records (they will have NULL GPS values)
SELECT '' as '';
SELECT 'Sample existing records (GPS will be NULL):' as Info;
SELECT 
    id,
    order_id,
    status_code,
    changed_at,
    delivery_latitude,
    delivery_longitude
FROM t_crm_order_status_history 
WHERE status_code = 'delivered'
ORDER BY changed_at DESC 
LIMIT 3;


