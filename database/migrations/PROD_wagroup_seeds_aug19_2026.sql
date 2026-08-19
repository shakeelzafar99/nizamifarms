-- ---------------------------------------------------------------------------
-- Aug-19-2026 - Seed the two known WhatsApp purchase-log groups as TAUGHT
-- (owner ruling: "the pictures i shared, those group names should already be
-- learned"). Idempotent, NOT EXISTS guarded, safe to run any time in any order.
--
-- Without these the two groups still resolve by NAME MATCH and become taught on
-- the first confirmed card. Seeding just removes the "matched by name" caveat
-- from day one. Keys are PurchaseLogService::groupKey() output: title
-- uppercased, punctuation to spaces, collapsed, prefixed WAGROUP.
--
-- Each insert also guards that the vendor row exists and is active, so a
-- re-numbered vendor table can never seed a rule pointing at nobody.
--
-- NOTE: no semicolons and no non-ASCII inside these comments, deliberately.
-- A semicolon in a comment splits the file in naive SQL clients.
-- ---------------------------------------------------------------------------

-- "Taimoor Ali (Jilani Meat)" -> vendor 1, Jilani Meat (Taimoor Ali)
INSERT INTO t_ai_counterparty_map
    (account_key, name_key, entity_type, entity_id, entity_label, is_active, created_by, created_at, updated_at)
SELECT 'WAGROUP:TAIMOOR ALI JILANI MEAT', NULL, 'vendor', 1, 'Jilani Meat (Taimoor Ali)', 1, NULL, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_ai_counterparty_map WHERE account_key = 'WAGROUP:TAIMOOR ALI JILANI MEAT')
  AND EXISTS (SELECT 1 FROM t_fin_vendors WHERE id = 1 AND is_active = 1
              AND vendor_name LIKE '%Jilani%');

-- "Wahab Mutton" -> vendor 4, Ghousia Mutton (Wahab Qureshi)
INSERT INTO t_ai_counterparty_map
    (account_key, name_key, entity_type, entity_id, entity_label, is_active, created_by, created_at, updated_at)
SELECT 'WAGROUP:WAHAB MUTTON', NULL, 'vendor', 4, 'Ghousia Mutton (Wahab Qureshi)', 1, NULL, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_ai_counterparty_map WHERE account_key = 'WAGROUP:WAHAB MUTTON')
  AND EXISTS (SELECT 1 FROM t_fin_vendors WHERE id = 4 AND is_active = 1
              AND vendor_name LIKE '%Wahab%');

-- Verify, expect 2 rows:
-- SELECT account_key, entity_type, entity_id, entity_label
--   FROM t_ai_counterparty_map WHERE account_key LIKE 'WAGROUP:%'
