-- =============================================================================
-- Van: clear the Jan-28 SEED odometer (15,723) so its real reading can be entered
-- Aug-17 2026 · local first, then prod · idempotent · affects ONE row
-- =============================================================================
--
-- WHY
--   The Van's "odometer" rested entirely on ONE row: Mashood, 2026-01-28,
--   meter_start = meter_end = 15,723 — i.e. ZERO kilometres driven, dated exactly
--   the day his Van assignment window opened. The same 15,723 appears that day on
--   ARSLAN's row (15,723 → 15,803, 80 km), which is his GENUINE BCN-5755 reading:
--   BCN's series runs 10,761 on Jan-1 → 35,094 today, so 15,7xx in late January
--   fits it exactly. The registry seed copied that number onto Mashood/the Van.
--
--   Consequence: the Van reported 15,723 km, and the odometer guard refused the
--   real reading — "That reading (73,342 km) is far above this bike's last
--   15,723 km" (the 2,000 km forward-jump rule).
--
-- ⚠⚠ ARSLAN'S ROW MUST NOT BE TOUCHED. It is real BCN-5755 history. This
--    statement is scoped to Mashood's user id and that single date. Verified on
--    the replica: Van → no reading, BCN-5755 stays 35,094.
--
-- AFTERWARDS
--   The Van has no odometer at all (correct — nothing trustworthy was ever
--   recorded). Assign it to Taimur, then file the fuel at the true 73,342 km:
--   the claim is accepted, stamped to the Van, and becomes its first real
--   reading. A follow-up fill at 73,800 then validates normally. Verified.

-- ---- 1. PREVIEW — expect ONE row: Mashood, 2026-01-28, 15723 → 15723 ---------
SELECT  a.user_id, u.fullname, a.attendance_date, a.meter_start, a.meter_end,
        (a.meter_end - a.meter_start) AS km_that_day
FROM    t_ops_attendance a
JOIN    t_sys_user u ON u.id = a.user_id
WHERE   u.fullname LIKE '%Mashood%'
  AND   a.attendance_date = '2026-01-28'
  AND   a.meter_start = 15723;

-- ---- 1b. CONTROL — Arslan's row must exist and must NOT be in the change set --
SELECT  u.fullname, a.attendance_date, a.meter_start, a.meter_end
FROM    t_ops_attendance a
JOIN    t_sys_user u ON u.id = a.user_id
WHERE   u.fullname LIKE '%Arslan%'
  AND   a.attendance_date = '2026-01-28';

-- ---- 2. APPLY ----------------------------------------------------------------
UPDATE  t_ops_attendance a
JOIN    t_sys_user u ON u.id = a.user_id
SET     a.meter_start = NULL,
        a.meter_end   = NULL,
        a.updated_at  = NOW()
WHERE   u.fullname LIKE '%Mashood%'
  AND   a.attendance_date = '2026-01-28'
  AND   a.meter_start = 15723;

-- ---- 3. VERIFY --------------------------------------------------------------
-- (a) the preview above must now return zero rows
-- (b) Arslan's control row must still read 15723 → 15803
-- (c) then: assign the Van to Taimur and file the fuel at 73,342 km.
