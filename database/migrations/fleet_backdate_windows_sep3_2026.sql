-- ═══════════════════════════════════════════════════════════════════════════════
-- BACKDATE WINDOW for the two managers who record maintenance   (owner ruling)
-- ═══════════════════════════════════════════════════════════════════════════════
-- Owner, 4-Sep: "for backdate lets allow 2 days, and in case I want to change later
-- there is an option to change from the profile."
--
-- ⭐ WHERE TO CHANGE IT LATER — it is NOT on the user's profile.
--    Sidebar → Roles  (/roles) → edit the role → field "📅 Expense Backdate Days".
--    It is a ROLE setting, not a person's, and the form caps it at 30 days.
--    So a one-off can be granted by raising the role for a day and putting it back.
--
-- ⚠⚠ IT IS PER ROLE, SO IT MOVES EVERYONE ON THAT ROLE:
--      role 10 Management -> Shabib AND the "Nizami Farms" account
--      role 17 khaas      -> Qasim  AND Sabir
--
-- ⚠⚠ READ THIS: the cap has NEVER ACTUALLY FIRED until now. On Carbon 3 `diffInDays`
--    returns a SIGNED value, so the comparison was `-20 > 2` = false and EVERY backdate
--    was accepted, for everyone, however old (measured: a 400-day-old expense passed).
--    That is fixed in the same batch, so this number starts being enforced the moment
--    the batch is uploaded. Expect it to refuse things it used to wave through.
--
-- ⚠ ONE HONEST EXCEPTION REMAINS: a bill ATTACHED to a recorded service inherits that
--   SERVICE's date, so a 10-day-old service can still be billed today. That date is
--   manager-recorded truth about when the work happened, so it is deliberate — but it
--   is a way round this window and you should know it exists.
--
-- Net effect: role 17 stays where it was (2); role 10 moves 0 -> 2.
-- Idempotent. No DDL — safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════════

-- Before: expect Management = 0 (or 14 if the earlier draft of this file was run), khaas = 2.
SELECT id, urole_name, expense_backdate_days FROM `t_sys_role` WHERE id IN (10, 17);

UPDATE `t_sys_role`
   SET `expense_backdate_days` = 2,
       `updated_at` = NOW()
 WHERE `id` IN (10, 17);

-- After: expect both = 2.
SELECT id, urole_name, expense_backdate_days FROM `t_sys_role` WHERE id IN (10, 17);
