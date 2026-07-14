-- =============================================================================
-- Backfill: link historical "invoice sent" WhatsApp messages to their order
-- (Jul 2026) so the orders page's "invoice sent" tick works for PAST sends too,
-- not just new ones.
--
-- Going forward, sendInvoice() stamps `related_order_number` at send time. This
-- one-time script fills it in for existing invoice messages by parsing the order
-- number out of their content ("Invoice #<order_number> (optional params)").
--
-- Example content: "Invoice #SH-21122 (Ali, SH-21122, Rs 4088)" → SH-21122
--   LOCATE('#')      → start after the '#'
--   SUBSTRING_INDEX ' ' → cut at the first space  → "SH-21122"
--   SUBSTRING_INDEX '(' → belt-and-suspenders if no space before "("
--
-- SAFE: only touches outbound template messages whose content starts with
-- "Invoice #" and that don't already have a related_order_number. Re-runnable.
-- Run on LOCAL (dev) and PROD. No money/ledger impact.
-- =============================================================================

UPDATE `t_wa_messages`
SET `related_order_number` = TRIM(
        SUBSTRING_INDEX(
            SUBSTRING_INDEX(
                SUBSTRING(`content`, LOCATE('#', `content`) + 1),
            ' ', 1),
        '(', 1)
    )
WHERE `content` LIKE 'Invoice #%'
  AND `direction` = 'outbound'
  AND `type` = 'template'
  AND (`related_order_number` IS NULL OR `related_order_number` = '')
  AND LOCATE('#', `content`) > 0;

-- Verify (should list order numbers like SH-xxxxx / NF-xxxxx):
-- SELECT related_order_number, COUNT(*) FROM t_wa_messages
--   WHERE content LIKE 'Invoice #%' AND related_order_number IS NOT NULL
--   GROUP BY related_order_number ORDER BY related_order_number LIMIT 20;
-- =============================================================================
