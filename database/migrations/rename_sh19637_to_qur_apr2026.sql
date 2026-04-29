-- ============================================================
-- Rename SH-19637 to next available QUR26-XXX order number
-- Safe to run: all table relationships use order_id (int PK),
-- not order_number (varchar). This only updates display fields.
-- ============================================================

-- Step 1: Find the current max QUR26-XXX sequence across both tables
SET @max_prod = (
    SELECT COALESCE(MAX(CAST(SUBSTRING(order_number, 7) AS UNSIGNED)), 0)
    FROM t_crm_prod_order
    WHERE order_number LIKE 'QUR26-%'
);
SET @max_shopify = (
    SELECT COALESCE(MAX(CAST(SUBSTRING(order_number, 7) AS UNSIGNED)), 0)
    FROM t_crm_shopify_order
    WHERE order_number LIKE 'QUR26-%'
);
SET @next_seq = GREATEST(@max_prod, @max_shopify) + 1;
SET @new_order_number = CONCAT('QUR26-', LPAD(@next_seq, 3, '0'));
SET @old_order_number = 'SH-19637';

-- Step 2: Preview what will change
SELECT 'PREVIEW — order to rename' AS info;
SELECT id, order_number, customer_id, total_price, order_status, order_date
FROM t_crm_prod_order
WHERE order_number = @old_order_number COLLATE utf8mb4_unicode_ci;

SELECT CONCAT('Will rename ', @old_order_number, ' -> ', @new_order_number) AS planned_rename;

-- Step 3: Verify no duplicate
SELECT 'CHECK — new number must not exist' AS info;
SELECT COUNT(*) AS collision_count
FROM t_crm_prod_order
WHERE order_number = @new_order_number COLLATE utf8mb4_unicode_ci;

-- ============================================================
-- UNCOMMENT THE BLOCK BELOW TO EXECUTE THE RENAME
-- ============================================================

-- START TRANSACTION;
--
-- -- 3a. Rename the order
-- UPDATE t_crm_prod_order
--    SET order_number = @new_order_number
--  WHERE order_number = @old_order_number COLLATE utf8mb4_unicode_ci;
--
-- -- 3b. Update ledger description text (cosmetic, FK is via order_id)
-- UPDATE t_fin_ledger_transaction
--    SET description = REPLACE(description, @old_order_number, @new_order_number)
--  WHERE description LIKE CONCAT('%', @old_order_number, '%');
--
-- -- 3c. Update request title/description text (cosmetic)
-- UPDATE t_sys_request
--    SET title = REPLACE(title, @old_order_number, @new_order_number),
--        description = REPLACE(description, @old_order_number, @new_order_number)
--  WHERE title LIKE CONCAT('%', @old_order_number, '%')
--     OR description LIKE CONCAT('%', @old_order_number, '%');
--
-- -- 3d. Update order status history notes if any reference the old number
-- UPDATE t_crm_order_status_history
--    SET notes = REPLACE(notes, @old_order_number, @new_order_number)
--  WHERE notes LIKE CONCAT('%', @old_order_number, '%')
--    AND order_id = (SELECT id FROM t_crm_prod_order WHERE order_number = @new_order_number COLLATE utf8mb4_unicode_ci LIMIT 1);
--
-- -- Verify the result
-- SELECT id, order_number, customer_id, total_price, order_status
-- FROM t_crm_prod_order
-- WHERE order_number = @new_order_number COLLATE utf8mb4_unicode_ci;
--
-- COMMIT;
