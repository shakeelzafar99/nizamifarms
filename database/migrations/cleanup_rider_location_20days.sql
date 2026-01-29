-- =====================================================
-- CLEANUP RIDER LOCATION DATA (Keep 20 Days)
-- Purpose: Delete rider location records older than 20 days
-- Usage: Run manually or schedule via cron
-- =====================================================

-- Show how many records will be deleted
SELECT 
  COUNT(*) as records_to_delete,
  MIN(captured_at) as oldest_record,
  MAX(captured_at) as newest_to_delete
FROM t_ops_rider_location 
WHERE captured_at < DATE_SUB(NOW(), INTERVAL 20 DAY);

-- Delete records older than 20 days
DELETE FROM t_ops_rider_location 
WHERE captured_at < DATE_SUB(NOW(), INTERVAL 20 DAY);

-- Show result
SELECT ROW_COUNT() as records_deleted;

-- Show current data summary
SELECT 
  COUNT(*) as total_records_remaining,
  MIN(captured_at) as oldest_record,
  MAX(captured_at) as newest_record,
  COUNT(DISTINCT user_id) as unique_riders
FROM t_ops_rider_location;

SELECT '✓ Cleanup complete - keeping 20 days of rider location data' as Status;
