-- =============================================================================
-- EDN-198 phantom odometer (12,390) — remove the mistaken one-day assignment
-- Aug-16 2026 · run local first, then prod · idempotent
-- =============================================================================
--
-- WHAT HAPPENED
--   Farooq's Oil Change claim (659 km) was refused: "lower than this bike's
--   12,390 km". EDN-198 is a new bike whose real readings are 342 → 659 km.
--
--   The 12,390 is ASIM TAHIR'S OWN BIKE's odometer (meter_end, 2026-08-09).
--   On Aug-9 EDN-198 was briefly assigned to Asim and reversed the same day:
--       Farooq  2026-08-08 -> 2026-08-09
--       Asim    2026-08-09 -> 2026-08-09      <-- the mistake, kept as history
--       Farooq  2026-08-09 -> OPEN
--   The machine-odometer reconstruction takes every keeper window and pulls that
--   person's readings inside it — so Asim's own-bike 12,390 was inhaled into
--   EDN-198, became its "current meter", and now floors every claim on it.
--
--   The day resolver itself already knows better: for Aug-9 it attributes Asim's
--   readings to HIS OWN bike (open-assignment precedence). Deleting this row
--   therefore loses nothing — his Aug-9 kilometres remain fully counted on
--   bike #6 where they belong.
--
-- VERIFIED ON THE REPLICA: with the row removed, Farooq's 659 km claim is
-- ACCEPTED and EDN-198's odometer drops back to its own readings.
--
-- ⚠ AFTERWARDS the bike's meter reads as "unknown" rather than 12,390, because
--   its genuine odometer (~659 km) sits below the 1,000 km plausibility floor
--   the reconstruction applies. That is conservative and harmless: claims
--   validate fail-open (659 passes), and the service countdown simply stays
--   silent until the bike crosses 1,000 km. No false overdue can result.

-- ---- 1. PREVIEW — must show exactly ONE row (Asim / 2026-08-09 -> 2026-08-09)
SELECT  a.id, u.fullname, a.assigned_on, a.released_on
FROM    t_ops_vehicle_assignment a
JOIN    t_sys_user u ON u.id = a.user_id
JOIN    t_ops_vehicle v ON v.id = a.vehicle_id
WHERE   v.reg_no = 'EDN-198'
  AND   u.fullname LIKE '%Asim%'
  AND   DATE(a.assigned_on)  = '2026-08-09'
  AND   DATE(a.released_on)  = '2026-08-09';

-- ---- 2. APPLY --------------------------------------------------------------
DELETE a
FROM    t_ops_vehicle_assignment a
JOIN    t_sys_user u ON u.id = a.user_id
JOIN    t_ops_vehicle v ON v.id = a.vehicle_id
WHERE   v.reg_no = 'EDN-198'
  AND   u.fullname LIKE '%Asim%'
  AND   DATE(a.assigned_on)  = '2026-08-09'
  AND   DATE(a.released_on)  = '2026-08-09';

-- ---- 3. VERIFY — preview must now return zero rows; then have Farooq retry
-- his claim (needs no re-login; the odometer window is computed per request).
