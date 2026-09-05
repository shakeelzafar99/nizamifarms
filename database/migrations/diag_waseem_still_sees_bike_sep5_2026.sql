-- ═══════════════════════════════════════════════════════════════════════════════
-- DIAGNOSTIC — "the vehicles page says handed back, but the rider still sees the bike"
-- Waseem / DCR-799, 5-Sep-2026.   ⚠ READ-ONLY. Every statement is a SELECT.
-- ═══════════════════════════════════════════════════════════════════════════════
-- Why this exists: on the dev replica the flow is CORRECT — after a real release(),
-- currentVehicleFor() returns NULL, the ticket bike-picker empties, company_bike
-- flips to 0 and the profile mirror clears. So the bug is NOT in the resolver logic;
-- it is either prod DATA that the resolver is faithfully reporting, or prod running
-- an older build. These five questions tell us which, and Q2 is the likely culprit.
-- ═══════════════════════════════════════════════════════════════════════════════

-- Q0 · who is Waseem, and which vehicle is DCR-799
SELECT id, fullname FROM `t_sys_user` WHERE fullname LIKE '%Waseem%' AND is_active = '1';
SELECT id, reg_no, nickname, is_company FROM `t_ops_vehicle`
 WHERE reg_no LIKE '%DCR%799%' OR nickname LIKE '%DCR%799%';

-- Q1 · is the assignment ACTUALLY closed?  (the vehicles page says yes — confirm)
--      Expect: released_on filled in, and NO row with released_on IS NULL.
SELECT a.id, a.user_id, u.fullname, a.vehicle_id, a.assigned_on, a.released_on
  FROM `t_ops_vehicle_assignment` a
  JOIN `t_sys_user` u ON u.id = a.user_id
 WHERE u.fullname LIKE '%Waseem%'
 ORDER BY a.id DESC
 LIMIT 8;

-- ⭐⭐ Q2 · THE LIKELY CULPRIT — a per-day vehicle OVERRIDE on attendance.
--      `currentVehicleFor()` checks TODAY'S attendance row FIRST and returns that
--      vehicle before it ever looks at the assignment registry. So one override row
--      makes the rider's app keep showing the bike no matter what the registry says,
--      and the vehicles page (which reads the registry) still says "handed back" —
--      exactly the split being reported.
--      Expect for a clean handover: NO rows, or vehicle_id NULL.
SELECT att.attendance_date, att.user_id, u.fullname, att.vehicle_id, att.vehicle_source
  FROM `t_ops_attendance` att
  JOIN `t_sys_user` u ON u.id = att.user_id
 WHERE u.fullname LIKE '%Waseem%'
   AND att.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)
   AND att.vehicle_id IS NOT NULL
 ORDER BY att.attendance_date DESC;

-- Q3 · the profile mirrors. `company_bike` only auto-clears on release if the
--      Aug-16 sync is deployed; `default_vehicle_id` only if clearMirrorIfPointsAt is.
--      `vehicle_plate` is NEVER cleared by any handover — it is a hand-typed field
--      (nothing on mobile reads it, so it is cosmetic, but it will look wrong).
--      Expect after a handover: company_bike = 0, default_vehicle_id = NULL.
SELECT p.user_id, u.fullname, p.company_bike, p.default_vehicle_id,
       p.vehicle_plate, p.meter_required, p.updated_at
  FROM `t_ops_rider_profile` p
  JOIN `t_sys_user` u ON u.id = p.user_id
 WHERE u.fullname LIKE '%Waseem%';

-- Q4 · things he raised HIMSELF that still name the bike — these are CORRECT and
--      are expected to stay on his phone: he reported it, so he can follow it.
--      If this is all he is seeing, there is no bug to fix.
SELECT t.id, t.title, t.status, t.vehicle_id, t.opened_by, t.opened_for_user_id, t.opened_at
  FROM `t_ops_vehicle_ticket` t
  JOIN `t_sys_user` u ON u.id = t.opened_by
 WHERE u.fullname LIKE '%Waseem%'
   AND t.status <> 'closed'
 ORDER BY t.id DESC
 LIMIT 10;

-- Q5 · is the registry switch even on? If VEHICLE_RULES is not 'Y', the registry is
--      "recorded, not enforced" and the old profile checkbox is the sole authority —
--      which would explain the rider side ignoring the handover entirely.
SELECT config_key, config_value FROM `t_fin_config`
 WHERE config_key IN ('VEHICLE_RULES', 'MACHINE_ATTRIBUTION');
