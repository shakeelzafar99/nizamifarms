-- ═══════════════════════════════════════════════════════════════════════════════
-- MAINTENANCE SCHEDULES BY VEHICLE CLASS + PER-VEHICLE PER-JOB OVERRIDES
-- 10-Sep-2026                                        (owner ruling, 9/10-Sep)
-- ═══════════════════════════════════════════════════════════════════════════════
-- Idempotent: safe to run twice. Additive only — no column is dropped, no row is
-- deleted, nothing is backfilled destructively.
--
-- ⚠⚠ RUN THIS **BEFORE** UPLOADING THE WEB FILES IS **NOT** REQUIRED, but running
--    it first is still preferred. Every reader is guarded on Schema::hasColumn /
--    hasTable, so web files uploaded ahead of this SQL degrade to exactly today's
--    behaviour (one bike list for every machine, no per-vehicle overrides) rather
--    than 500-ing. Verified by test_service_intervals.php §7f.
--
-- ── WHAT THIS IS FOR ──────────────────────────────────────────────────────────
--
-- Until now `t_fleet_maintenance_types` held ONE interval per job for the whole
-- fleet, so the van (CAD-2958, vtype='van', 75,031 km) was judged against bike
-- numbers — "Oil Change every 1,000 km" on a van. There was no way to say "vans
-- do this every 5,000 km", and the per-vehicle control was a single scalar
-- (`t_ops_vehicle.service_interval_km`) that never named a job, so since the
-- Aug-27 resolver landed it changed nothing on any screen.
--
-- TWO LEVELS, PER JOB (owner, 10-Sep):
--   1. the CLASS STANDARD — every job carries its own number for BIKES and for
--      VANS. This is what "company default" has always meant, now said per job.
--   2. the VEHICLE OVERRIDE — one row per (vehicle, job) for the exceptions.
--      Blank = follow the class standard.
--
-- ⭐ ONE IDENTITY PER JOB. A van's Oil Change is the SAME type row as a bike's,
--   with a different interval — never a second "Oil Change (van)" type. Claims,
--   service logs and workshop visits all store `maintenance_type_id`, so a second
--   row would orphan the van's existing Oil Change evidence and split every
--   report by id. Only the NUMBER differs by class.
-- ═══════════════════════════════════════════════════════════════════════════════


-- ── 1 ─ THE TYPE LEARNS ABOUT VEHICLE CLASS AND TIME ──────────────────────────
--
-- `interval_km` (existing column) keeps its meaning and becomes explicitly THE
-- BIKE NUMBER. Nothing is migrated: every existing type keeps the number it has
-- and defaults to `applies_to = 'bike'`, which is exactly today's fleet — the
-- five regular types were all written for bikes.
--
-- ⚠⚠ CONSEQUENCE ON DAY ONE, SAY IT OUT LOUD: the van has NO scheduled jobs
--    until management adds them (Bikes → ⚙️ Types → 🚚 Vans, on web or on the
--    phone). Its panel reads "No van schedule set yet" and it raises NO service
--    alerts. That is deliberate and it is the honest state — better than today,
--    where it silently nags on bike intervals nobody chose for it.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_maintenance_types'
             AND COLUMN_NAME = 'applies_to');
SET @s := IF(@c = 0,
    'ALTER TABLE t_fleet_maintenance_types
       ADD COLUMN applies_to VARCHAR(8) NOT NULL DEFAULT ''bike'' AFTER bucket',
    'SELECT "applies_to already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- km | time. Asked once when the type is created; default km (owner, 10-Sep).
-- A time-based job counts down from the last service DATE, not the odometer —
-- for work that ages rather than wears ("oil every 6 months even if it is parked").
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_maintenance_types'
             AND COLUMN_NAME = 'basis');
SET @s := IF(@c = 0,
    'ALTER TABLE t_fleet_maintenance_types
       ADD COLUMN basis VARCHAR(8) NOT NULL DEFAULT ''km'' AFTER applies_to',
    'SELECT "basis already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The VAN's kilometre standard for this job. NULL = this job has no van number
-- (either it does not apply to vans, or management has not set one yet).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_maintenance_types'
             AND COLUMN_NAME = 'interval_km_van');
SET @s := IF(@c = 0,
    'ALTER TABLE t_fleet_maintenance_types
       ADD COLUMN interval_km_van INT NULL AFTER interval_km',
    'SELECT "interval_km_van already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The TIME standards, in DAYS (the editor offers days or months; months are
-- stored as N*30 and rendered back as months when the figure divides evenly).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_maintenance_types'
             AND COLUMN_NAME = 'interval_days');
SET @s := IF(@c = 0,
    'ALTER TABLE t_fleet_maintenance_types
       ADD COLUMN interval_days INT NULL AFTER interval_km_van',
    'SELECT "interval_days already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_maintenance_types'
             AND COLUMN_NAME = 'interval_days_van');
SET @s := IF(@c = 0,
    'ALTER TABLE t_fleet_maintenance_types
       ADD COLUMN interval_days_van INT NULL AFTER interval_days',
    'SELECT "interval_days_van already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Belt and braces: anything already in the table predates this file and is a
-- BIKE, kilometre-based job. The column defaults say so; this makes it true for
-- rows written by a connection that ignores defaults.
UPDATE t_fleet_maintenance_types
   SET applies_to = 'bike'
 WHERE applies_to IS NULL OR applies_to = '';
UPDATE t_fleet_maintenance_types
   SET basis = 'km'
 WHERE basis IS NULL OR basis = '';


-- ── 2 ─ THE EXCEPTION: ONE ROW PER (VEHICLE, JOB) ─────────────────────────────
--
-- ⭐⭐ THIS IS WHAT "⚙️ This bike's schedule" HAS ALWAYS IMPLIED AND COULD NEVER
--    DELIVER. The old control wrote ONE number per vehicle that named no job, so
--    the engine had to guess which countdown it meant — it guessed "the shortest
--    clock-resetting type", and that guess MOVED on 22-Aug when an unrelated
--    checkbox changed (see MAINTENANCE-ONE-ENGINE-PLAN-AUG27-2026.md §1). The
--    Aug-27 resolver stopped honouring the guess, which is why typing a number
--    into that prompt has changed nothing on any screen since.
--
--    A row per (vehicle, job) is the shape the question actually has:
--    "AY-4771 does its oil every 800 km, everything else standard."
--    ServiceIntervalResolver already reserved this as step 0, so no consumer
--    changes again after this.
--
-- ⚠ NO FOREIGN KEYS — matches the rest of t_ops_*, and a retired type must keep
--   resolving for history. Orphan rows are ignored by the resolver (it only ever
--   reads a row while rendering a type that still exists), and the editor deletes
--   them when a type is retired is NOT done deliberately: retiring a type means
--   "stop offering it", never "forget what was configured", so the row survives a
--   re-activation.
--
-- ⚠ interval_km and interval_days are BOTH nullable and exactly one is used —
--   whichever the TYPE's basis says. Storing both lets a job change basis without
--   losing the override that was set under the old one.

CREATE TABLE IF NOT EXISTS t_ops_vehicle_service_schedule (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id          INT NOT NULL,
    maintenance_type_id INT NOT NULL,
    interval_km         INT NULL COMMENT 'used when the type basis is km',
    interval_days       INT NULL COMMENT 'used when the type basis is time',
    created_by          INT NULL,
    updated_by          INT NULL,
    created_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_type (vehicle_id, maintenance_type_id),
    KEY idx_vsched_vehicle (vehicle_id),
    KEY idx_vsched_type (maintenance_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Per-vehicle override of a maintenance job''s schedule. Absent = follow the class standard.';


-- ── 3 ─ NOTHING IS DELETED (owner ruling, 10-Sep) ─────────────────────────────
--
-- The old per-vehicle scalar `t_ops_vehicle.service_interval_km` and the legacy
-- per-rider `t_ops_rider_profile.service_interval_km` are KEPT and stay readable
-- as the last fallback for a job that has no schedule of its own. Their three
-- WRITERS are retired in code (the rider-drawer prompt, the mobile ⚙️ sheet and
-- the vehicle edit form) — the columns are not touched here.
--
-- For the record, what they hold today on prod:
--   SELECT id, reg_no, service_interval_km FROM t_ops_vehicle
--    WHERE service_interval_km IS NOT NULL;
--   SELECT user_id, service_interval_km FROM t_ops_rider_profile
--    WHERE service_interval_km IS NOT NULL;
-- On the replica that is BCN-5755 = 1,000 and rider #77 = 1,000 — both equal Oil
-- Change's own standard, so they are already no-ops.
--
-- The seven duplicate manual service records (t_fleet_service_log ids 8-14, all
-- rider #77 at 36,521 km, from the pre-Sep-2 untyped-fallback era) are likewise
-- LEFT ALONE. They are visible under "Past services" and each has a Remove
-- button if they are ever to go.


-- ── 4 ─ POST-FLIGHT ───────────────────────────────────────────────────────────
--
-- SELECT id, type_name, bucket, applies_to, basis,
--        interval_km, interval_km_van, interval_days, interval_days_van,
--        resets_service_clock, is_active
--   FROM t_fleet_maintenance_types ORDER BY id;
--
-- Expect: all six existing types applies_to='bike', basis='km', van columns NULL,
-- interval_km unchanged (Oil Change 1000, Oil + Tuning 2000, Brake Shoe 10000,
-- Chain Set 20000, Misc NULL, General Repair NULL).
--
-- SELECT COUNT(*) FROM t_ops_vehicle_service_schedule;   -- expect 0
--
-- ⚠ AFTER RUNNING THIS: the per-vehicle schedule and the headline are cached for
--   300s per (vehicle, meter, keeper). Editing a type bumps the global config
--   version and clears them; the SQL itself does not, so if you run this by hand
--   and want it visible immediately, hit /api/public/xclean.
