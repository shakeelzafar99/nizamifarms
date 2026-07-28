-- ============================================================================
-- Location confidence tiers (portable, hand-run local + prod).
--
-- WHY: a coordinate was stored with no record of how good it was, so dispatch
-- could not tell a rooftop pin from the middle of Islamabad. Two new columns
-- describe the machine-geocoded pin; the human "verified" columns
-- (latitude/longitude) are NOT touched here and are never written by a machine.
--
-- RUN THIS BEFORE UPLOADING THE WEB FILES: the customer selects in
-- calculateDeliveryEtas read geocode_precision unconditionally, so the web will
-- 500 on dispatch if the column is missing (same rule as the morning-start batch).
--
-- Rollback = drop the two columns; every consumer falls back to today's
-- behaviour (latitude ?: geocoded_latitude) with no code change.
-- ============================================================================

-- 1) Describe the machine-geocoded pin.
ALTER TABLE `t_crm_prod_customer`
  ADD COLUMN IF NOT EXISTS `geocode_precision` VARCHAR(16) NULL
    COMMENT 'How good the geocoded pin is: exact|street|area. NULL = legacy/unknown (treated as area).'
    AFTER `geocoded_longitude`,
  ADD COLUMN IF NOT EXISTS `geocode_provider` VARCHAR(16) NULL
    COMMENT 'Which engine produced it: google. NULL = legacy Nominatim row.'
    AFTER `geocode_precision`;

-- 2) Config rows.
--    DISPATCH_NO_PIN_LEG_MINUTES  - flat travel estimate used for a stop with no
--                                   usable pin, so it stays in the route and gets
--                                   a real (approximate) delivery time instead of
--                                   being silently dropped.
--    DISPATCH_REQUIRE_PIN         - 0 = warn and let the dispatch through
--                                   (default; blocking would not stop the
--                                   delivery, only the timing of it).
--                                   1 = refuse the dispatch until every stop has
--                                   a pin. Flip in the DB, no code change/APK.
--    GEOCODE_MAX_KM_FROM_OFFICE   - reject any geocode result further than this
--                                   from the office (killed a customer geocoded
--                                   to Canada and a 5,815-minute ETA).
--    GEOCODE_CENTER_LAT/LNG       - office centre used for that guard and to bias
--                                   Google's search. Defaults = primary office.
INSERT INTO t_fin_config (config_key, config_value)
SELECT 'DISPATCH_NO_PIN_LEG_MINUTES', '15' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'DISPATCH_NO_PIN_LEG_MINUTES');

INSERT INTO t_fin_config (config_key, config_value)
SELECT 'DISPATCH_REQUIRE_PIN', '0' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'DISPATCH_REQUIRE_PIN');

INSERT INTO t_fin_config (config_key, config_value)
SELECT 'GEOCODE_MAX_KM_FROM_OFFICE', '60' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'GEOCODE_MAX_KM_FROM_OFFICE');

INSERT INTO t_fin_config (config_key, config_value)
SELECT 'GEOCODE_CENTER_LAT', '33.70811597' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'GEOCODE_CENTER_LAT');

INSERT INTO t_fin_config (config_key, config_value)
SELECT 'GEOCODE_CENTER_LNG', '73.08868750' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'GEOCODE_CENTER_LNG');

-- 3) VERIFY — expect 2 columns and 5 config rows.
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_NAME = 't_crm_prod_customer'
  AND COLUMN_NAME IN ('geocode_precision', 'geocode_provider')
ORDER BY COLUMN_NAME;

SELECT config_key, config_value FROM t_fin_config
WHERE config_key IN ('DISPATCH_NO_PIN_LEG_MINUTES', 'DISPATCH_REQUIRE_PIN',
                     'GEOCODE_MAX_KM_FROM_OFFICE', 'GEOCODE_CENTER_LAT', 'GEOCODE_CENTER_LNG')
ORDER BY config_key;
