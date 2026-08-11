-- =====================================================================
-- IMAP mailbox migration: Bluehost -> StackCP (support@nizamifarms.com)
-- Date: 2026-08-10
--
-- RUN ORDER (critical — reverse of the usual habit):
--   1. Cloudflare MX/SPF switched to mx.stackmail.com / mx2.stackmail.com
--   2. Prod .env IMAP_HOST/IMAP_PASSWORD updated + /xclean
--   3. THEN this file, on the production database.
-- Running this while prod still polls the OLD Bluehost mailbox would let
-- the reconciler re-ingest old bank emails as duplicates.
--
-- Why this file exists at all: the poller's cursor says "read up to email
-- UID 34316" (Bluehost numbering). The new StackCP mailbox numbers from 1
-- (verified: UIDNEXT 1), so without a reset the poller would treat every
-- new bank email as "already read" — silently, forever. The UPDATE also
-- moves the ~396 historical Bluehost signal rows out of the (folder, uid)
-- dedupe namespace so a future StackCP email whose UID happens to reach
-- the 33k range is never mistaken for an already-processed duplicate.
-- =====================================================================

-- 1. Retire the Bluehost-era email signals from the INBOX dedupe namespace.
--    (Rows keep their data; only the folder tag changes. Unique key
--    uq_email (email_folder, email_uid) stays satisfied — same uids,
--    uniformly renamed folder.)
UPDATE t_fin_payment_signal
   SET email_folder = 'BLUEHOST'
 WHERE source = 'email'
   AND email_folder = 'INBOX';

-- 2. Drop the stale cursor. The next poll re-seeds it "start-from-now"
--    against the StackCP mailbox (currently empty, UIDs from 1).
DELETE FROM t_fin_imap_cursor
 WHERE mailbox = 'support@nizamifarms.com';

-- =====================================================================
-- Verification (run after):
--   SELECT COUNT(*) FROM t_fin_payment_signal WHERE email_folder = 'INBOX';
--     -- expect 0 (all historical rows now 'BLUEHOST')
--   SELECT COUNT(*) FROM t_fin_payment_signal WHERE email_folder = 'BLUEHOST';
--     -- expect ~396 (local replica count; prod may be slightly higher)
--   SELECT * FROM t_fin_imap_cursor;
--     -- expect 0 rows immediately; ONE fresh row with a small
--     -- last_seen_uid appears after the next poll runs.
-- =====================================================================
