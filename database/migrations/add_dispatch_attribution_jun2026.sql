-- =============================================
-- Dispatch Attribution (Rider-Mode Dispatch feature)
-- Created: 2026-06-17
-- Purpose: Track WHO last changed a delivery route / dispatched it, so that
--          Store Mode and Rider Mode show the same picture ("rider wins, both
--          stay aware"). Riders can now reorder + Auto Route + Dispatch their
--          own orders; managers need to see when a rider changed the sequence
--          or dispatched.
--
-- NOTE: No new "dispatched" status is introduced. eta_calculated_at remains
--       the dispatch marker (set by calculate-delivery-etas). These columns
--       only add attribution; existing logic is untouched.
-- =============================================

-- Who/when last set the delivery sequence (priority) for the order.
ALTER TABLE t_crm_prod_order
ADD COLUMN delivery_priority_updated_by INT NULL
COMMENT 't_sys_user.id who last set delivery_priority (rider or store manager).';

ALTER TABLE t_crm_prod_order
ADD COLUMN delivery_priority_updated_at DATETIME NULL
COMMENT 'When delivery_priority was last changed.';

-- Who dispatched (ran calculate-delivery-etas). Pairs with existing eta_calculated_at.
ALTER TABLE t_crm_prod_order
ADD COLUMN eta_calculated_by INT NULL
COMMENT 't_sys_user.id who last calculated ETAs / dispatched (rider or store manager).';

-- Verify:
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 't_crm_prod_order'
AND COLUMN_NAME IN ('delivery_priority_updated_by', 'delivery_priority_updated_at', 'eta_calculated_by');

SELECT 'Dispatch attribution columns added successfully!' as status;
