-- Add payment source account selection for expense requests
-- This allows approvers to choose which account to pay from

ALTER TABLE `t_req_master`
ADD COLUMN `payment_source_account_id` INT NULL COMMENT 'FK to t_fin_accounts - which account to debit (Expense Fund, Online, Manager cash, etc.)' AFTER `expense_category`,
ADD INDEX `idx_payment_source` (`payment_source_account_id`);

-- Add foreign key constraint
ALTER TABLE `t_req_master`
ADD CONSTRAINT `fk_req_payment_source`
FOREIGN KEY (`payment_source_account_id`) REFERENCES `t_fin_accounts`(`id`)
ON DELETE SET NULL
ON UPDATE CASCADE;

SELECT '✓ Added payment_source_account_id to t_req_master' as Status;

