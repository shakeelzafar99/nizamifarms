-- ═══════════════════════════════════════════════════════════════════════════════
-- WORKSHOP TRIP — "going to the workshop" / "at the workshop"      10-Sep-2026
-- ═══════════════════════════════════════════════════════════════════════════════
-- Idempotent, additive only. Five nullable columns on one table. No data written,
-- nothing deleted, no index dropped. Safe to run twice.
--
-- ⚠ Readers are guarded on Schema::hasColumn, so uploading the PHP before this SQL
--   degrades to exactly today's behaviour (a visit with no trip) rather than 500-ing.
--
-- ── WHAT THIS IS FOR ──────────────────────────────────────────────────────────
--
-- The workshop flow could book a day and record its outcome, but it had no notion
-- of the ERRAND ITSELF. There was no "he has set off" and no "he is there", so:
--   • the live rider card on the orders page derived his status from dispatch
--     counts and distance to the office — a rider riding to the workshop with no
--     orders read as "Away (GPS stale)", or tripped the red "⚠ Left without
--     dispatch" warning, which is a false alarm about a man doing what he was told;
--   • nobody on the store side could see who was away, so orders kept being
--     assigned to him;
--   • a same-day breakdown ("bike is playing up, take it in now") had no flow at
--     all — the only day-of mechanism was the attendance pin, which is meaningless
--     for a rider who checked in hours ago.
--
-- ⭐ The trip is a PHASE OF THE VISIT, not a second record. One row still describes
--   the whole errand, so there is nothing to keep in sync.
-- ═══════════════════════════════════════════════════════════════════════════════


-- ── 1 ─ WHERE HE CHECKS IN THAT DAY ───────────────────────────────────────────
--
-- Asked of the approver (or the planner booking directly) instead of inferred:
--   'workshop' → his one-day shift location is pinned to the workshop, so he clocks
--                in THERE and no lateness or remote flag lands on him;
--   'regular'  → he clocks in at his usual place and rides over during the day.
--
-- ⚠⚠ NULL is not a third option — it means "written before this column existed",
--    and the resolver treats it exactly as the old code did (pin if, and only if, a
--    registered workshop was chosen). Old rows therefore keep their meaning.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit'
             AND COLUMN_NAME = 'attendance_at');
SET @s := IF(@c = 0,
    'ALTER TABLE t_ops_workshop_visit
       ADD COLUMN attendance_at VARCHAR(10) NULL DEFAULT NULL
       COMMENT ''regular|workshop - where he checks in that day. NULL = pre-Sep-10 row''
       AFTER location_id',
    'SELECT "attendance_at already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 2 ─ THE TRIP ──────────────────────────────────────────────────────────────
--
-- `departed_at` is stamped when the rider presses "Workshop jaa raha hoon" (or a
-- manager presses it for him, which is why `departed_by` exists and is not assumed
-- to be the rider).
--
-- `arrived_at` is stamped by the GEOFENCE — the owner's ruling: there is no arrival
-- button, so his own heartbeat proves he is there. Same rule the going-home journey
-- already uses. A workshop with no coordinates simply never stamps it, and the trip
-- honestly reads "on the way" until he answers the outcome.
--
-- ⚠ No `ended_at`. The trip ends when the OUTCOME is recorded (`done_at`, which
--   already exists) or when he checks out — both facts already live elsewhere, and a
--   third copy of "it is over" is how two screens start disagreeing.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit'
             AND COLUMN_NAME = 'departed_at');
SET @s := IF(@c = 0,
    'ALTER TABLE t_ops_workshop_visit
       ADD COLUMN departed_at DATETIME NULL DEFAULT NULL
           COMMENT ''He set off for the workshop'',
       ADD COLUMN departed_by INT NULL DEFAULT NULL
           COMMENT ''Who pressed it - the rider himself, or a manager standing in'',
       ADD COLUMN arrived_at DATETIME NULL DEFAULT NULL
           COMMENT ''Geofence: first fix inside the workshop radius. No button exists.''',
    'SELECT "trip columns already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 3 ─ THE MACHINE IS THERE AND NOBODY IS RESPONSIBLE ────────────────────────
--
-- Owner ruling (10-Sep): when the bike has to stay overnight, Shabib simply moves it
-- off the rider with "change rider" / release. The VISIT must not die with that
-- handover — the work still has to be answered for — so releasing a machine with an
-- open trip stamps this instead. The managers' queue then reads "AY-4771 is at Ali
-- Motors, nobody holds it, record the outcome when it comes back".
--
-- ⚠⚠ It is NOT an auto-completion. Nothing in this feature may ever make "he went"
--    and "he never went" indistinguishable (the Sep-2 ruling); this only records that
--    the machine has no keeper while the question is still open.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit'
             AND COLUMN_NAME = 'no_keeper_since');
SET @s := IF(@c = 0,
    'ALTER TABLE t_ops_workshop_visit
       ADD COLUMN no_keeper_since DATETIME NULL DEFAULT NULL
       COMMENT ''Machine left at the workshop with no rider holding it''',
    'SELECT "no_keeper_since already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 4 ─ "HE NEVER WENT, AND HE HAS GONE HOME" ─────────────────────────────────
--
-- Owner ruling (10-Sep): a same-day booking has no approval cut-off any more. The one
-- thing that must not happen quietly is the rider checking out having never set off —
-- so the managers are told ONCE, at checkout, and the matter ends there for the day.
--
-- Stamped so the notice cannot repeat (prod has no scheduler; this fires from the
-- checkout request itself).

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit'
             AND COLUMN_NAME = 'not_gone_alert_at');
SET @s := IF(@c = 0,
    'ALTER TABLE t_ops_workshop_visit
       ADD COLUMN not_gone_alert_at DATETIME NULL DEFAULT NULL
       COMMENT ''Managers told he checked out without going. Once only.''',
    'SELECT "not_gone_alert_at already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 5 ─ INDEX ─────────────────────────────────────────────────────────────────
-- The live boards ask "who is on a workshop errand today" on every poll, per rider.
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit'
             AND INDEX_NAME = 'idx_ws_trip_day');
SET @s := IF(@i = 0,
    'CREATE INDEX idx_ws_trip_day ON t_ops_workshop_visit (visit_date, status, user_id)',
    'SELECT "idx_ws_trip_day already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── POST-FLIGHT ───────────────────────────────────────────────────────────────
-- SHOW COLUMNS FROM t_ops_workshop_visit LIKE '%_at';
--   expect: visit_time … accepted_at, reminded_at, done_at, approved_at, declined_at,
--           departed_at, arrived_at, no_keeper_since, not_gone_alert_at
-- SELECT COUNT(*) FROM t_ops_workshop_visit WHERE departed_at IS NOT NULL;  -- expect 0
--
-- ⚠ Nothing to backfill: every existing visit is a past or future day with no trip,
--   which is exactly what NULL means here.
