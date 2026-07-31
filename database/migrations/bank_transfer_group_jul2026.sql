-- ============================================================================
-- Bank-to-bank transfers (Ledger Hub → Banks → ⇄ Move between banks)
-- Jul 2026 — run AFTER bank_balance_adjustments_jul2026.sql
-- ============================================================================
-- Moving money between OUR OWN banks does not change how much online money the
-- company holds — only which bank holds it. So it is deliberately NOT a ledger
-- transaction (that would move the ONLINE account and the KPIs): it is a PAIR
-- of attribution rows, −X on the source bank and +X on the destination, which
-- sum to zero and therefore leave the pool and the reconciliation untouched.
--
-- This column ties the two halves together. It matters because the pair is only
-- safe while it stays a pair: deleting one leg on its own would move the tracked
-- total away from the pool and open an unexplained gap. With the group set, the
-- delete action removes both halves together, and each half can be shown as a
-- transfer rather than as a manual correction.
--
-- Run ONCE on local, then on prod. Portable plain SQL.
-- ============================================================================

ALTER TABLE `t_fin_bank_balance_adjustment`
    ADD COLUMN `transfer_group` VARCHAR(36) NULL
        COMMENT 'Set on BOTH halves of a bank-to-bank transfer (shared uuid). NULL = ordinary manual fix.'
        AFTER `note`;

CREATE INDEX `idx_bba_transfer_group`
    ON `t_fin_bank_balance_adjustment` (`transfer_group`);

-- Post-migration sanity:
-- SELECT transfer_group, COUNT(*) c, SUM(amount) net
--   FROM t_fin_bank_balance_adjustment
--  WHERE transfer_group IS NOT NULL
--  GROUP BY transfer_group;      -- every group must have c = 2 and net = 0
