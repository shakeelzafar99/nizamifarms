-- =============================================================================
-- Reconcile t_ops_rider_profile.company_bike with the vehicle registry
-- Aug-16 2026 · run ONCE, local first then prod · safe to re-run (idempotent)
-- =============================================================================
--
-- WHY
--   From Aug-16 the checkbox is kept in step automatically whenever a machine is
--   assigned, released, or reclassified (VehicleService::syncCompanyBikeFlag).
--   This statement fixes the riders whose checkbox drifted BEFORE that shipped —
--   the assignment path used to leave it entirely alone.
--
-- WHAT IT AFFECTS
--   Fuel treatment, meter demands and the ⛽ attendance tick already asked the
--   registry per date, so those were ALREADY correct and this changes none of
--   them. What it fixes is the two places that still read the raw column and were
--   silently skipping people:
--     • WorkJourneyService::workIssueDays  — "no home start", ride-in ETA and
--       overnight meter continuity are only evaluated for company_bike = 1
--     • the DayChecks population widener
--
-- ⚠ ONLY RUN THIS WHILE VEHICLE_RULES = 'Y'. With the rules switched off the
--   checkbox is the sole authority and is meant to be curated by hand; rewriting
--   it then would enforce assignments the system has promised are "recorded, not
--   yet enforced".
--
-- ⚠ It does NOT touch meter_required — that is a deliberate per-person exemption.
--
-- Basis: the rider's OPEN assignment (a standing arrangement). Per-day attendance
-- overrides are deliberately ignored — they correct a single day, they are not a
-- change of who holds what.

-- ---- 1. PREVIEW — run this first and eyeball it -----------------------------
SELECT  p.user_id,
        u.fullname,
        p.company_bike                       AS checkbox_now,
        COALESCE(h.is_company, 0)            AS should_be,
        COALESCE(h.label, 'nothing')         AS actually_holds
FROM        t_ops_rider_profile p
JOIN        t_sys_user u ON u.id = p.user_id
LEFT JOIN ( SELECT  a.user_id,
                    MAX(v.is_company)                            AS is_company,
                    MAX(COALESCE(v.reg_no, v.nickname))          AS label
            FROM    t_ops_vehicle_assignment a
            JOIN    t_ops_vehicle v ON v.id = a.vehicle_id
            WHERE   a.released_on IS NULL
            GROUP BY a.user_id ) h ON h.user_id = p.user_id
WHERE   p.company_bike <> COALESCE(h.is_company, 0);

-- ---- 2. APPLY ---------------------------------------------------------------
UPDATE      t_ops_rider_profile p
LEFT JOIN ( SELECT  a.user_id, MAX(v.is_company) AS is_company
            FROM    t_ops_vehicle_assignment a
            JOIN    t_ops_vehicle v ON v.id = a.vehicle_id
            WHERE   a.released_on IS NULL
            GROUP BY a.user_id ) h ON h.user_id = p.user_id
SET         p.company_bike = COALESCE(h.is_company, 0),
            p.updated_at   = NOW()
WHERE       p.company_bike <> COALESCE(h.is_company, 0);

-- ---- 3. VERIFY — must return zero rows --------------------------------------
SELECT  COUNT(*) AS still_out_of_step
FROM        t_ops_rider_profile p
LEFT JOIN ( SELECT  a.user_id, MAX(v.is_company) AS is_company
            FROM    t_ops_vehicle_assignment a
            JOIN    t_ops_vehicle v ON v.id = a.vehicle_id
            WHERE   a.released_on IS NULL
            GROUP BY a.user_id ) h ON h.user_id = p.user_id
WHERE   p.company_bike <> COALESCE(h.is_company, 0);
