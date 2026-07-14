-- ============================================================================
-- Rename leave taxonomy to  planned / emergency
-- Nizami Farms · 2026-07-14
--
-- MODEL: ONE pool of leaves; each leave is either PLANNED (applied in advance) or
-- EMERGENCY (same-day, capped at 4/cycle). Historically the advance type was stored
-- as 'casual' and the form-default / manager path as 'annual'. This renames every
-- non-emergency leave type to 'planned'.
--
-- The same-day cap (LeavePolicyService::samedayUsed) keys ONLY on leave_type='emergency',
-- so those rows are LEFT UNTOUCHED here and the cap count is completely unaffected.
--
-- SAFETY
--   * Plain UPDATEs — run on StackCP / phpMyAdmin (no PREPARE/EXECUTE).
--   * Idempotent: re-running is a no-op (no annual/casual leaves remain).
--   * (1) is SCOPED to the LEAVE category, so leave_type on non-leave requests
--     (expenses etc., which never use the field) is left alone.
--   * NEVER touches leave_type='emergency'.
--   * Run on LOCAL first, verify with (3), then PROD.
-- ============================================================================

-- 1) Leave REQUESTS: annual/casual -> planned  (leave category only)
UPDATE t_req_master r
JOIN t_req_category c ON c.id = r.category_id
SET r.leave_type = 'planned'
WHERE c.category_code = 'leave'
  AND r.leave_type IN ('annual', 'casual');

-- 2) Attendance snapshot copy (t_ops_attendance.leave_type is only ever set for leave days)
UPDATE t_ops_attendance
SET leave_type = 'planned'
WHERE leave_type IN ('annual', 'casual');

-- 3) VERIFY — expect only 'planned' + 'emergency' (zero annual/casual)
SELECT 'req_master' AS tbl, r.leave_type, COUNT(*) AS n
FROM t_req_master r
JOIN t_req_category c ON c.id = r.category_id
WHERE c.category_code = 'leave'
GROUP BY r.leave_type
UNION ALL
SELECT 'attendance' AS tbl, leave_type, COUNT(*)
FROM t_ops_attendance
WHERE leave_type IS NOT NULL AND leave_type <> ''
GROUP BY leave_type
ORDER BY tbl, leave_type;
-- ============================================================================
