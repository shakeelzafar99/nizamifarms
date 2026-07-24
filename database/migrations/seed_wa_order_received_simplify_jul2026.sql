-- =============================================================================
-- SIMPLIFY the order-received schedule to the 2-layer model (Jul 2026)
--
-- WHY: the accept-buttons (deliver_today/tomorrow/wednesday/thursday) now own the
-- delivery COMMITMENT, made by staff when they accept the order. So the automatic
-- order-received message must only ACKNOWLEDGE ("we got it, team will contact
-- you") and must NOT promise a delivery day or classify chicken vs red meat.
--
-- This collapses the old composition-based schedule (chicken -> Wednesday, red
-- meat -> Thursday, mixed -> choose, Wed-evening -> Thursday, all with buttons)
-- down to just TWO reused, already-approved templates:
--   * order_received_workinghours       — Wed-Mon 10:00-20:00 (Asia/Karachi)
--   * order_received_offhours_existing   — everything else, incl. ALL of Tuesday
--     (this template already names Tuesday as the off day, so it doubles as the
--      day-off acknowledgment — no new template needed).
--
-- Days convention is 0=Sun .. 6=Sat (Carbon dayOfWeek; see BaseOrderReceivedHandler).
-- Working days Wed,Thu,Fri,Sat,Sun,Mon = [3,4,5,6,0,1]; Tuesday (2) is omitted so
-- it falls through to default_template.
--
-- The old templates (order_received_mon_chicken / _mon_mixed / _red_meat / _wed)
-- are simply no longer routed to — they are RETIRED, not deleted, and keep their
-- history.
--
-- ⚠️ OVERWRITES config_json for BOTH order-received lanes. Safe if you have NOT
-- hand-edited the schedule in Messages -> 🤖 -> Order actions -> Edit schedule.
-- If you HAVE, skip this and instead simplify it in that UI (delete the meat rows,
-- leave one "Any items" working-hours row + the off-hours default).
--
-- AFTER RUNNING: clear cache (DEV: php artisan cache:clear / PROD: /api/public/xclean)
-- and refresh. Run on LOCAL + PROD. No money/ledger impact.
-- =============================================================================

-- >>> CHECK FIRST — is your current schedule the standard default, or hand-edited?
--     SELECT rule_key, config_json FROM t_wa_automations
--       WHERE rule_key IN ('order_received_new','order_received_existing');
--     Standard default = multiple chicken/red-meat/Wednesday rows (never customized -> safe).
--     If it's clearly something YOU set up, keep it / edit in the UI instead of running this.

UPDATE t_wa_automations
SET config_json = '{"rows":[{"days":[3,4,5,6,0,1],"start":"10:00","end":"20:00","composition":"any","template":"order_received_workinghours"}],"default_template":"order_received_offhours_existing"}',
    updated_at = NOW()
WHERE rule_key IN ('order_received_new', 'order_received_existing');

-- Verify:
--   SELECT rule_key, JSON_PRETTY(config_json) FROM t_wa_automations
--     WHERE rule_key IN ('order_received_new','order_received_existing');
-- =============================================================================
