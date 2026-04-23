-- ============================================================================
-- Migration: Qurbani Target Quantities (soft limits)
-- Date: April 2026
-- Purpose:
--   Lets admins set a target (soft booking cap) for every (delivery_type,
--   day, category) cell on the Qurbani Booked Summary, and for the finer
--   (delivery_type, day, category, slot, region) cells shown inside the
--   detailed breakdown view. The cap is soft — bookings are NEVER blocked,
--   the UI just shows "booked / target" with color hints.
--
-- Design notes:
--   * Two "levels" share the same table:
--       - SUMMARY rows:   slot = '', region = ''   (top-level cap)
--       - BREAKDOWN rows: slot, region BOTH set    (per-slot-per-region)
--     Empty-string (instead of NULL) is used for unique-key compatibility
--     — MySQL allows multiple NULLs in a unique key, which would allow
--     duplicate summary rows.
--   * `target_qty` is a plain INT and can be 0 (means "no target set";
--     the UI will render just the booked count in that case).
--   * Nothing here is destructive — pre-existing installs will simply have
--     no target rows and the summary keeps rendering exactly as before.
--   * If a step errors "Duplicate key name" / "Table already exists" it's
--     safe to ignore — it just means this part already ran.
--
-- Run each statement block one at a time on MySQL Workbench.
-- ============================================================================

-- STEP 1: Create the targets table.
CREATE TABLE IF NOT EXISTS `t_crm_qurbani_targets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `delivery_type` VARCHAR(100) NOT NULL
        COMMENT 'e.g. "Delivery" or "Self Collection". Matches the summary card header.',
    `day` VARCHAR(100) NOT NULL
        COMMENT 'e.g. "Day 1" — the qurbani_day option value.',
    `category` VARCHAR(100) NOT NULL
        COMMENT 'Product category_level_2, e.g. "Goat (Bakra)".',
    `slot` VARCHAR(150) NOT NULL DEFAULT ''
        COMMENT 'Empty string for summary-level target. Slot value (e.g. "Afternoon 11 AM to 3 PM") for breakdown-level target.',
    `region` VARCHAR(100) NOT NULL DEFAULT ''
        COMMENT 'Empty string for summary-level target. Region value (e.g. "DHA Phase 2") for breakdown-level target.',
    `target_qty` INT NOT NULL DEFAULT 0
        COMMENT 'Soft cap. 0 means no target set.',
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_qurbani_target_cell` (`delivery_type`, `day`, `category`, `slot`, `region`),
    KEY `idx_qurbani_target_dt_day` (`delivery_type`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STEP 2: (Optional) seed no rows. Targets are fully admin-managed, so this
-- migration intentionally does NOT insert any defaults — the table stays empty
-- until someone hits "Set Targets" in the UI.
