-- ═══════════════════════════════════════════════════════════════════════════════
-- SERVICE RECORDS FOLLOW THE VEHICLE                               10-Sep-2026
-- ═══════════════════════════════════════════════════════════════════════════════
-- Idempotent, additive only. ONE nullable column and one index on one table.
-- Nothing is written, nothing deleted, no index dropped. Safe to run twice.
--
-- ⚠ Every reader is guarded on Schema::hasColumn and falls back to the old
--   derivation, so uploading the PHP before this SQL behaves exactly as today.
--
-- ── WHAT THIS IS FOR ──────────────────────────────────────────────────────────
--
-- `t_fleet_service_log` records WHO had the work done and WHEN, but never WHICH
-- MACHINE. Every reader re-derives the bike at read time from
-- `VehicleResolver::vehicleForDay(rider, service_date)` — "what was this man on
-- that day". That is right for a rider who stays on one bike, and silently wrong
-- the moment the bike he serviced is not the bike the registry hands him:
--
--   • the machine goes IN to the workshop and the manager puts him on a spare
--     (an assignment, or a day override) — the derivation answers "the spare",
--     so the oil change is credited to a bike that never had one;
--   • Shabib releases the bike overnight and the rider falls back to his OWN
--     bike — the record lands on a personal machine, which the company schedule
--     ignores entirely, so it vanishes;
--   • a manager records the work days later, after a handover.
--
-- In every case the visit reads "done" with a `service_log_id`, while the real
-- machine's countdown keeps running and nothing on any screen says why.
--
-- ⭐ THE CODEBASE ALREADY SOLVED THIS SHAPE FOR MONEY. `VehicleResolver::stampClaim()`:
--   *"a permanent financial record… must be frozen at filing time. A reassignment
--   months later must not silently re-attribute old money."* A service record is
--   the same kind of fact — one machine, one odometer, one instant — so it gets
--   the same treatment: stamped when it is filed, and never re-derived afterwards.
--
-- ⚠ NULL keeps its meaning: "filed before this column existed". Those rows are
--   still attributed by `vehicleForDay`, exactly as they are today, so no history
--   moves and no countdown shifts on the day this runs.
-- ═══════════════════════════════════════════════════════════════════════════════


-- ── 1 ─ THE MACHINE THE WORK WAS DONE ON ──────────────────────────────────────

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_service_log'
             AND COLUMN_NAME = 'vehicle_id');
SET @s := IF(@c = 0,
    'ALTER TABLE t_fleet_service_log
       ADD COLUMN vehicle_id INT NULL DEFAULT NULL
       COMMENT ''The machine serviced, frozen at filing time. NULL = pre-Sep-10 row, attributed by vehicleForDay()''
       AFTER user_id',
    'SELECT "t_fleet_service_log.vehicle_id already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 2 ─ THE INDEX ─────────────────────────────────────────────────────────────
--
-- Every per-machine reader (the countdown evidence, the history list, the meter
-- contributors, the claim plausibility floor) now filters on this column.

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_service_log'
             AND INDEX_NAME = 'idx_fsl_vehicle');
SET @s := IF(@i = 0,
    'ALTER TABLE t_fleet_service_log ADD INDEX idx_fsl_vehicle (vehicle_id, service_date)',
    'SELECT "idx_fsl_vehicle already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 3 ─ VERIFY ────────────────────────────────────────────────────────────────
--
-- Expect: the column present and every existing row NULL (nothing is back-filled;
-- see the note above — old rows keep their current derivation).
--
--   SHOW COLUMNS FROM t_fleet_service_log LIKE 'vehicle_id';
--   SELECT COUNT(*) AS total, COUNT(vehicle_id) AS stamped FROM t_fleet_service_log;
--
-- ⚠ DO NOT back-fill with vehicleForDay(). It would freeze today's derivation —
--   including the wrong answers this column exists to prevent — into a column that
--   is meant to hold a recorded fact. Leaving them NULL keeps them honest: they are
--   derived, and they say so.
