-- ============================================================================
-- Backfill: mirror the home-meter photo into picture_end (portable, hand-run local + prod).
-- The going-home flow saved a bike rider's day-closing meter PHOTO to picture_home only,
-- while the READING was mirrored to meter_end. Every End-meter display surface (web row
-- camera icon, rider-app preview/history, mobile store view) keys off picture_end, so the
-- photo showed as "missing" everywhere except the web meter-details modal (which reads
-- picture_home ?? picture_end). The code now mirrors picture_end on save; this fills the
-- rows that were recorded BEFORE that fix (e.g. Kanan/Arslan on 2026-07-24).
--
-- SAFE: only touches rows that HAVE a home meter + a home photo AND an empty picture_end.
-- It never overwrites a real checkout photo. Idempotent (re-run is a no-op).
-- ============================================================================

-- Preview what will change (run first to eyeball it):
SELECT id, user_id, attendance_date, meter_home, picture_home, picture_end
FROM t_ops_attendance
WHERE meter_home IS NOT NULL
  AND picture_home IS NOT NULL AND picture_home <> ''
  AND (picture_end IS NULL OR picture_end = '');

-- Apply:
UPDATE t_ops_attendance
SET picture_end = picture_home,
    updated_at  = NOW()
WHERE meter_home IS NOT NULL
  AND picture_home IS NOT NULL AND picture_home <> ''
  AND (picture_end IS NULL OR picture_end = '');

-- Verify (expect 0 rows remaining with a home photo but no picture_end):
SELECT COUNT(*) AS still_missing
FROM t_ops_attendance
WHERE meter_home IS NOT NULL
  AND picture_home IS NOT NULL AND picture_home <> ''
  AND (picture_end IS NULL OR picture_end = '');
