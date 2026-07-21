-- ============================================================================
-- Verified-location LOCK — the rider-unlock grant columns (portable, hand-run on
-- local + prod). The lock feature's code shipped but this schema step was never
-- produced as a file; the unlock button 500s ("Failed to unlock location") without it.
-- Additive + nullable. "Duplicate column" on re-run = already applied (ignore).
--   verified_pin_unlocked_until = when the 6-hour rider-unlock window ends
--   verified_pin_unlocked_by    = the staff/manager user who opened it (audit)
-- (verified_location_saved_by / _at already exist; the lock state derives from them.)
-- ============================================================================
ALTER TABLE t_crm_prod_customer
  ADD COLUMN verified_pin_unlocked_until DATETIME NULL,
  ADD COLUMN verified_pin_unlocked_by    INT(11) NULL;
