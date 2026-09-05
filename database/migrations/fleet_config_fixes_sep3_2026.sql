-- ═══════════════════════════════════════════════════════════════════════════════
-- FLEET CONFIG FIXES — 3-Sep-2026            (owner rulings, data only, no schema)
-- ═══════════════════════════════════════════════════════════════════════════════
-- Idempotent: safe to run twice. No DDL, so nothing here can fail half-way.
--
-- ── 1 ─ Oil Change must RESET the service clock ───────────────────────────────
--
-- ⚠⚠ WHAT WAS BROKEN. `resets_service_clock` decides which jobs may speak for
--    "when is this bike next due?" — the single headline the rider's phone shows.
--    On 22-Aug Oil Change was set to 1,000 km and UN-ticked, leaving Oil + Tuning
--    (2,000 km) as the only clock-resetting type. Most machines have Oil Change
--    evidence and no Oil + Tuning evidence, so the headline had nothing to stand
--    on and computed `unknown`.
--
--    Live proof on the replica (vehicle 4, the van, meter 74,969): the per-type
--    panel correctly read "Oil Change — due in 1,000 km, ok", while the rider's
--    own chip on the same screen read "Service schedule not set". Verified before
--    and after inside a rolled-back transaction: with the flag on, the chip
--    becomes "Oil Change due in 1000 km" (source=schedule).
--
--    This is also what caused service log #8 to be misfiled — see the note in
--    FleetFuelController::…, where an untyped record was guessed as "the shortest
--    clock-resetting type" and that stopped meaning Oil Change on 22-Aug.
--
-- ⭐ Matched on `type_name` ONLY because this is a one-off data repair; nothing in
--    the application may ever branch on the name (see MaintenanceTypeModel).
UPDATE t_fleet_maintenance_types
   SET resets_service_clock = 1,
       updated_at           = NOW()
 WHERE type_name = 'Oil Change'
   AND bucket    = 'regular'
   AND resets_service_clock = 0;

-- Expect: Oil Change = 1, Oil + Tuning = 1, Brake Shoe = 0, Chain Set = 0.
-- Brake Shoe and Chain Set stay OFF on purpose: a 10,000 km brake job must never
-- make an overdue oil change look done.
-- SELECT id, type_name, interval_km, resets_service_clock
--   FROM t_fleet_maintenance_types WHERE bucket = 'regular' ORDER BY id;

-- ⚠ AFTER RUNNING THIS: the headline is cached for 300s per (vehicle, meter,
--   keeper) and its key does NOT change when a maintenance type is edited, so
--   phones keep the old chip for up to 5 minutes. Nothing to do — just don't
--   report it as "didn't work" inside that window. `/api/public/xclean` clears it.

-- ── 2 ─ Taimur receives service alerts (owner ruling, 3-Sep) ──────────────────
--
-- Service-due pushes go to whoever holds the MOBILE key `receive_service_alerts`,
-- resolved by ROLE through t_sys_role_mobile_permission. Qasim (role 17, khaas)
-- and Shabib (role 10, Management) already hold it; Taimur (role 14) did not.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 14, mp.id
  FROM t_sys_mobile_permission mp
 WHERE mp.permission_code = 'receive_service_alerts'
   AND NOT EXISTS (
       SELECT 1 FROM t_sys_role_mobile_permission x
        WHERE x.role_id = 14 AND x.mobile_permission_id = mp.id);

-- ⚠⚠ MOBILE PERMISSIONS ARE A LOGIN-TIME SNAPSHOT. Taimur must sign out and back
--    in (or restart the app) before this reaches his phone. Granting it changes
--    nothing on a session that is already open.

-- ── NOT CHANGED, deliberately ────────────────────────────────────────────────
-- Qasim holds no `assign_vehicles` and that is CORRECT (owner, 3-Sep): he is
-- responsible for maintenance; Shabib assigns bikes. Left exactly as it is.
