-- =============================================================================
-- PENDING PROD -- FILE B of 2:  RUN **AFTER** THE WEB UPLOAD      (2026-08-11)
--
-- ⚠⚠ STOP. DO NOT RUN THIS UNTIL ALL THREE ARE TRUE:
--        1. FILE A has been run on production.
--        2. The web files are uploaded -- in particular
--           app/Services/Payments/Signals/CustomerBankAliasService.php,
--           PaymentSignalMatcher.php and the three NEW service files
--           (PayerNameResolver, PayerNameArbiter, HeldCreditResweeper).
--        3. /api/public/xclean has been hit so the old code is really gone.
--
-- WHY THE ORDER IS THE OTHER WAY ROUND HERE
--   This file deletes payer-name aliases that were learned from unverified
--   guesses, and releases credits that were stacked on the wrong invoice. The
--   OLD code re-creates exactly those rows: CustomerBankAliasService::
--   learnFromApprovedOrder used to learn from ANY signal on an approved order,
--   including a pure amount guess. Run this cleanup while the old code is still
--   live and one approval in the gap quietly puts the bad rows back, and the
--   resweep re-stacks the released credits by amount. The new code only learns
--   from evidence, so the cleanup sticks.
--
-- ⭐ REVISION 2026-08-11: SECTION 2 IS NOW AGE-BOUNDED TO 7 DAYS.
--   The first version of this file released EVERY stacked guess regardless of
--   age. That is wrong, and the reason is worth understanding before you run it.
--
--   A released credit is detached from its order and handed back to the money
--   inbox on the expectation that the RESWEEP will immediately reconsider it and
--   put it somewhere better. But the resweep only looks at credits younger than
--   payment_signals.resweep_days, which is 7. Release something older and
--   nothing ever picks it up again: the order silently loses its proof chip AND
--   the credit becomes a permanent open item for a human to sort by hand.
--
--   Measured when the unbounded version was run on the Aug-11 dev replica: 10
--   credits released, 4 of them 8 to 19 days old and now un-re-placeable
--   (Rs 12,143 / Rs 5,200 / Rs 15,325 / Rs 22,180). That is four new pieces of
--   manual work created to correct history nobody was going to revisit.
--
--   So section 2 now only touches credits the system can immediately re-place by
--   itself. Older stacks stay exactly as they are: imperfect, but stable, on
--   orders that are long since approved, and above all NOT NOISE. If you ever
--   want to revisit them, do it deliberately from the money inbox rather than by
--   dumping them all into it at once.
--
--   Keep the INTERVAL below equal to or less than resweep_days. If you raise
--   resweep_days later, you may raise this to match.
--
-- WHAT IS AND IS NOT AT RISK
--   No money moves in this file. No ledger row, no balance, no order total and
--   no approval status is touched. Everything it releases becomes VISIBLE again
--   in the money inbox rather than being deleted, and everything it deletes is
--   re-learnable -- the next payment from that person is re-matched and, once
--   confirmed, the alias comes back, this time backed by evidence.
--
-- IDEMPOTENT
--   Yes. The deletes are written as CRITERIA rather than id lists, so a second
--   run simply matches nothing new. Section 2 releases only signals that are
--   still stacked, and a released signal carries match_reason
--   'guess_released_stacked' which the criteria no longer select.
--
-- NOTE ON SEMICOLONS: this file contains no semicolon anywhere except as a
-- statement terminator -- not inside comments, not inside string literals -- so
-- it splits correctly in any client.
-- =============================================================================


-- =============================================================================
-- 0. PRE-FLIGHT -- read-only. Run this FIRST and read the row.
--
-- EXPECT, measured against the Aug-11 local replica. Production has had more
-- trading days since that replica was taken, so treat these as shapes rather
-- than exact numbers:
--     b0_file_a_ran          = 1    ai_name_checked_at exists. If 0, STOP and
--                                   run FILE A first.
--     b1_aliases_total       = 672-ish
--     b2_junk_names          = 135-ish   removed by section 1a
--     b3_unsupported         = 15-ish    removed by section 1b
--     b4_stacked_in_window   = small, single digits. Released by section 2.
--                              These are the ones the resweep can re-place.
--     b5_stacked_too_old     = LEFT ALONE ON PURPOSE. See the revision note.
--                              A non-zero number here is expected and fine.
--     b6_already_released    = 0         non-zero means this file already ran
--
-- After the run, aliases_total should land near 522 -- that is 672 minus 135
-- minus 15. The two delete criteria do not overlap.
-- =============================================================================
SELECT
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 't_ai_bank_sms'
        AND column_name = 'ai_name_checked_at')                    AS b0_file_a_ran,

    (SELECT COUNT(*) FROM t_crm_customer_bank_alias)               AS b1_aliases_total,

    (SELECT COUNT(*) FROM t_crm_customer_bank_alias
      WHERE LOWER(bank_account_name) LIKE '%above address%'
         OR LOWER(TRIM(bank_account_name)) IN
              ('ca payroll account','saadiq saver plus lcy','ibft','credit')
         OR CHAR_LENGTH(TRIM(bank_account_name)) < 3)              AS b2_junk_names,

    (SELECT COUNT(*) FROM t_crm_customer_bank_alias a
      WHERE NOT EXISTS (
            SELECT 1 FROM t_fin_payment_signal p
             WHERE LOWER(TRIM(p.extracted_sender_name)) = LOWER(TRIM(a.bank_account_name))
               AND p.matched_customer_id = a.customer_id
               AND (p.paired_signal_id IS NOT NULL
                    OR p.match_reason IN ('email_corroborates_whatsapp',
                                          'bank_sms_corroborates_whatsapp',
                                          'whatsapp_corroborates_email')
                    OR (p.source IN ('whatsapp','email') AND p.status = 'matched'
                        AND (p.match_reason IS NULL
                             OR p.match_reason <> 'amount_unique_sms')))))  AS b3_unsupported,

    (SELECT COUNT(*) FROM t_fin_payment_signal p
       JOIN (SELECT matched_order_id FROM t_fin_payment_signal
              WHERE status IN ('matched','amount_mismatch')
                AND matched_order_id IS NOT NULL
              GROUP BY matched_order_id HAVING COUNT(*) > 1) dup
         ON dup.matched_order_id = p.matched_order_id
       LEFT JOIN t_ai_bank_sms s ON s.linked_signal_id = p.id
      WHERE p.match_reason IN ('amount_unique_sms','name_amount_sms','name_ai_sms')
        AND p.paired_signal_id IS NULL
        AND COALESCE(s.sms_at, p.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                                                                   AS b4_stacked_in_window,

    (SELECT COUNT(*) FROM t_fin_payment_signal p
       JOIN (SELECT matched_order_id FROM t_fin_payment_signal
              WHERE status IN ('matched','amount_mismatch')
                AND matched_order_id IS NOT NULL
              GROUP BY matched_order_id HAVING COUNT(*) > 1) dup
         ON dup.matched_order_id = p.matched_order_id
       LEFT JOIN t_ai_bank_sms s ON s.linked_signal_id = p.id
      WHERE p.match_reason IN ('amount_unique_sms','name_amount_sms','name_ai_sms')
        AND p.paired_signal_id IS NULL
        AND COALESCE(s.sms_at, p.created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY))
                                                                   AS b5_stacked_too_old,

    (SELECT COUNT(*) FROM t_fin_payment_signal
      WHERE match_reason = 'guess_released_stacked')               AS b6_already_released;


-- =============================================================================
-- 1. REMOVE PAYER-NAME ALIASES THAT NO REAL EVIDENCE SUPPORTS
--
-- t_crm_customer_bank_alias is the system's memory of "this bank account name
-- belongs to this customer". Until now it was written on EVERY approval, from
-- EVERY signal on the order, including matches the system had merely guessed by
-- amount. A fast approval of a wrong guess therefore taught a wrong payer
-- permanently. The new matcher TRUSTS this table, so a bad row would be
-- repeated confidently rather than sitting there harmlessly. That is why the
-- cleanup has to happen at the same time as the new code, not later.
--
-- UNCHANGED from the original file -- these two deletes were already correct.
-- =============================================================================

-- 1a. Bank-statement noise that was never a person's name. "at the above
--     address" is email-parser debris that had been recorded as the payer for
--     125 DIFFERENT customers. The rest are bank product and label strings.
DELETE FROM t_crm_customer_bank_alias
 WHERE LOWER(bank_account_name) LIKE '%above address%'
    OR LOWER(TRIM(bank_account_name)) IN
         ('ca payroll account','saadiq saver plus lcy','ibft','credit')
    OR CHAR_LENGTH(TRIM(bank_account_name)) < 3;

-- 1b. Aliases with no supporting evidence anywhere in the signal history.
--     KEEPS a row when at least one signal with that payer name and customer
--     was paired with the opposite source, corroborated across sources, or was
--     a WhatsApp or email match that was not a bare amount guess.
--     DELETES a row whose only support was 'amount_unique_sms' -- the system's
--     own guess, which an approval then quietly promoted to a fact.
DELETE FROM t_crm_customer_bank_alias
 WHERE NOT EXISTS (
       SELECT 1 FROM t_fin_payment_signal p
        WHERE LOWER(TRIM(p.extracted_sender_name)) = LOWER(TRIM(t_crm_customer_bank_alias.bank_account_name))
          AND p.matched_customer_id = t_crm_customer_bank_alias.customer_id
          AND (p.paired_signal_id IS NOT NULL
               OR p.match_reason IN ('email_corroborates_whatsapp',
                                     'bank_sms_corroborates_whatsapp',
                                     'whatsapp_corroborates_email')
               OR (p.source IN ('whatsapp','email') AND p.status = 'matched'
                   AND (p.match_reason IS NULL
                        OR p.match_reason <> 'amount_unique_sms'))));


-- =============================================================================
-- 2. RELEASE RECENT CREDITS STACKED ON THE SAME INVOICE
--
-- One invoice cannot have been paid by two different strangers, but the old
-- "attach by amount" rule had no way to see that a credit was already sitting
-- on an order, so they piled up. On the Aug-09 replica, SH-20443 (Sameeha
-- Farooq, Rs 7,533) was wearing BOTH a Rs 7,500 and a Rs 7,600 credit -- and
-- the Rs 7,600 one was really Nouman Siddique's, whose own Rs 7,400 order sat
-- untouched a few rows away.
--
-- The new code prevents this happening again. This releases the RECENT ones
-- already there. Released credits are NOT deleted and NOT decided -- they go
-- back to the money inbox unattached, and the resweep re-runs the full ladder
-- over them within minutes, which is how Nouman's credit finds his own order.
--
-- ⭐ Only INFERRED, unpaired matches are touched. A verified pair or a
--    customer's own screenshot is never released.
-- ⭐ Only credits inside the 7-day resweep window are touched. See the
--    revision note at the top of this file for why that matters.
-- =============================================================================

-- 2a. PREVIEW -- read-only, and worth actually reading. Exactly what section 2b
--     will release, with the age of each so you can see they are all inside the
--     window the resweep will revisit.
SELECT p.id AS signal_id,
       p.extracted_amount,
       p.extracted_sender_name,
       o.order_number,
       (o.total_price - COALESCE(o.total_paid, 0)) AS invoice_balance,
       COALESCE(s.sms_at, p.created_at) AS received_at,
       DATEDIFF(NOW(), COALESCE(s.sms_at, p.created_at)) AS age_days
  FROM t_fin_payment_signal p
  JOIN t_crm_prod_order o ON o.id = p.matched_order_id
  JOIN (SELECT matched_order_id FROM t_fin_payment_signal
         WHERE status IN ('matched','amount_mismatch')
           AND matched_order_id IS NOT NULL
         GROUP BY matched_order_id HAVING COUNT(*) > 1) dup
    ON dup.matched_order_id = p.matched_order_id
  LEFT JOIN t_ai_bank_sms s ON s.linked_signal_id = p.id
 WHERE p.match_reason IN ('amount_unique_sms','name_amount_sms','name_ai_sms')
   AND p.paired_signal_id IS NULL
   AND COALESCE(s.sms_at, p.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
 ORDER BY age_days;

-- 2b. Detach them. The LEFT JOIN to t_ai_bank_sms exists solely to reach the
--     credit's arrival time for the age bound.
UPDATE t_fin_payment_signal p
  JOIN (SELECT matched_order_id FROM t_fin_payment_signal
         WHERE status IN ('matched','amount_mismatch')
           AND matched_order_id IS NOT NULL
         GROUP BY matched_order_id HAVING COUNT(*) > 1) dup
    ON dup.matched_order_id = p.matched_order_id
  LEFT JOIN t_ai_bank_sms s ON s.linked_signal_id = p.id
   SET p.matched_order_id    = NULL,
       p.matched_customer_id = NULL,
       p.status              = 'unmatched',
       p.match_reason        = 'guess_released_stacked',
       p.match_confidence    = NULL,
       p.updated_at          = NOW()
 WHERE p.match_reason IN ('amount_unique_sms','name_amount_sms','name_ai_sms')
   AND p.paired_signal_id IS NULL
   AND COALESCE(s.sms_at, p.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- 2c. Drop any combined-invoice links those released signals were holding.
DELETE FROM t_fin_payment_signal_order
 WHERE signal_id IN (SELECT id FROM (
        SELECT id FROM t_fin_payment_signal
         WHERE match_reason = 'guess_released_stacked'
       ) x);

-- 2d. Re-open their inbox rows so the resweep, and Taimur, can see them again.
--
--     ⭐ THIS IS NOT WHAT CREATES INBOX NOISE, and skipping it is worse than
--     running it. The amount-unique attach already leaves the inbox row open by
--     design -- "confirm the payer" IS the chip -- so most of these rows are
--     'new' already and this statement changes nothing for them. On the Aug-11
--     dev run it flipped ZERO rows.
--
--     What it protects against is the one genuinely bad state: a credit
--     detached from its order by 2b but still marked handled here, which would
--     make it invisible in BOTH places -- money neither attached nor asked
--     about. The age bound in 2b is the control for noise. This is the control
--     for silence.
UPDATE t_ai_bank_sms s
  JOIN t_fin_payment_signal p ON p.id = s.linked_signal_id
   SET s.status      = 'new',
       s.auto_reason = NULL,
       s.updated_at  = NOW()
 WHERE p.match_reason = 'guess_released_stacked'
   AND s.status IN ('matched','recorded');


-- =============================================================================
-- VERIFY
--
-- EXPECT:
--   v1_aliases_remaining     roughly 520-530, all evidence-backed
--   v2_junk_left             = 0
--   v3_unsupported_left      = 0
--   v4_stacked_in_window     = 0    everything re-placeable was released
--   v5_stacked_too_old       = same as b5. LEFT ALONE ON PURPOSE -- this is not
--                                   a failure, it is the age bound working.
--   v6_released              = the b4 number from the pre-flight
-- =============================================================================
SELECT
    (SELECT COUNT(*) FROM t_crm_customer_bank_alias)               AS v1_aliases_remaining,

    (SELECT COUNT(*) FROM t_crm_customer_bank_alias
      WHERE LOWER(bank_account_name) LIKE '%above address%'
         OR LOWER(TRIM(bank_account_name)) IN
              ('ca payroll account','saadiq saver plus lcy','ibft','credit')
         OR CHAR_LENGTH(TRIM(bank_account_name)) < 3)              AS v2_junk_left,

    (SELECT COUNT(*) FROM t_crm_customer_bank_alias a
      WHERE NOT EXISTS (
            SELECT 1 FROM t_fin_payment_signal p
             WHERE LOWER(TRIM(p.extracted_sender_name)) = LOWER(TRIM(a.bank_account_name))
               AND p.matched_customer_id = a.customer_id
               AND (p.paired_signal_id IS NOT NULL
                    OR p.match_reason IN ('email_corroborates_whatsapp',
                                          'bank_sms_corroborates_whatsapp',
                                          'whatsapp_corroborates_email')
                    OR (p.source IN ('whatsapp','email') AND p.status = 'matched'
                        AND (p.match_reason IS NULL
                             OR p.match_reason <> 'amount_unique_sms')))))  AS v3_unsupported_left,

    (SELECT COUNT(*) FROM t_fin_payment_signal p
       JOIN (SELECT matched_order_id FROM t_fin_payment_signal
              WHERE status IN ('matched','amount_mismatch')
                AND matched_order_id IS NOT NULL
              GROUP BY matched_order_id HAVING COUNT(*) > 1) dup
         ON dup.matched_order_id = p.matched_order_id
       LEFT JOIN t_ai_bank_sms s ON s.linked_signal_id = p.id
      WHERE p.match_reason IN ('amount_unique_sms','name_amount_sms','name_ai_sms')
        AND p.paired_signal_id IS NULL
        AND COALESCE(s.sms_at, p.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                                                                   AS v4_stacked_in_window,

    (SELECT COUNT(*) FROM t_fin_payment_signal p
       JOIN (SELECT matched_order_id FROM t_fin_payment_signal
              WHERE status IN ('matched','amount_mismatch')
                AND matched_order_id IS NOT NULL
              GROUP BY matched_order_id HAVING COUNT(*) > 1) dup
         ON dup.matched_order_id = p.matched_order_id
       LEFT JOIN t_ai_bank_sms s ON s.linked_signal_id = p.id
      WHERE p.match_reason IN ('amount_unique_sms','name_amount_sms','name_ai_sms')
        AND p.paired_signal_id IS NULL
        AND COALESCE(s.sms_at, p.created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY))
                                                                   AS v5_stacked_too_old,

    (SELECT COUNT(*) FROM t_fin_payment_signal
      WHERE match_reason = 'guess_released_stacked')               AS v6_released;


-- =============================================================================
-- AFTER THIS FILE
--   The released credits sit unattached in the money inbox. The resweep picks
--   them up on the next load of Online Approvals or the NF Assistant money
--   inbox -- it runs after the response, throttled to once every 5 minutes site
--   wide -- and re-matches them through the new ladder. Expect most to land on
--   the right invoice within minutes. Whatever is left is a genuine human
--   decision and is now visible instead of silently wrong.
--
--   If nothing seems to move, the sweep simply has not been triggered yet:
--   open /approvals/online or the assistant money inbox once and give it a
--   moment. There is no cron on production and none is needed.
--
--   Then build and ship the APK.
-- =============================================================================


-- =============================================================================
-- ROLLBACK
--   Section 1 has no automatic undo -- the deleted aliases were, by definition,
--   the rows no evidence supported. They re-learn themselves from the next
--   confirmed payment, which is the intended repair path.
--
--   Section 2, if you must put the credits back exactly where they were, is
--   also not automatically reversible: the old matched_order_id was overwritten
--   with NULL. Restore from a database backup taken before the run if this ever
--   becomes necessary. In practice the resweep re-deciding them is the fix, not
--   a problem to undo. The age bound keeps the blast radius to a handful of
--   days of recent credits.
-- =============================================================================
