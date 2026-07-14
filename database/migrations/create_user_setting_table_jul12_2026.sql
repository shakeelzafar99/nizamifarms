-- ============================================================================
-- Phase 5 — per-user UI preferences ("My Sidebar" / customize menu)
-- Nizami Farms · Sidebar+Permissions UX revamp · 2026-07-12
--
-- A generic per-user key/value store. First use: setting_key = 'sidebar_hidden'
-- holds a JSON array of sidebar item slugs the user chose to hide from their own
-- menu (a personal, subtractive display preference — it can never GRANT access).
--
-- SAFE / DEPLOY:
--   * Purely additive — no existing table or column is touched.
--   * The app guards on Schema::hasTable('t_sys_user_setting'): until this table
--     exists the "Customize menu" feature is simply invisible, so it does not
--     matter whether you upload the code before or after running this script.
--   * Idempotent (CREATE TABLE IF NOT EXISTS). Run on LOCAL first, then PROD.
-- ============================================================================

CREATE TABLE IF NOT EXISTS t_sys_user_setting (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    setting_key    VARCHAR(100)    NOT NULL,
    setting_value  TEXT            NULL,
    created_at     TIMESTAMP       NULL DEFAULT NULL,
    updated_at     TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_setting (user_id, setting_key),
    KEY idx_user_setting_user (user_id)
);
