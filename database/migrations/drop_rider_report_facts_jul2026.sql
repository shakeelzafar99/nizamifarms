-- =====================================================================
-- Rider Reports — remove the Phase-1 fact tables (Jul-2026)
-- The reports are now fully REAL-TIME (computed on open, no job, no storage),
-- so these snapshot tables are no longer used. Safe cleanup.
--
-- Only run this if you already created them (the earlier Phase-1 build). If you
-- never ran the create script on this environment, you can skip this file.
-- =====================================================================

DROP TABLE IF EXISTS t_ops_order_journey_facts;
DROP TABLE IF EXISTS t_ops_rider_day_facts;
