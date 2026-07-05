-- ============================================================================
-- "Left without dispatch" event log (2026-07-04)
-- Portable, plain DDL. Run on LOCAL and PROD (owner's manual workflow).
--
-- One row per rider PER DAY, written by App\Http\Controllers\API\RiderController
-- ::logMissedDispatch() the FIRST time the live detector sees that rider has left
-- the office without pressing dispatch. NO cron — it is logged by whichever
-- surface runs the detector (Riders-Live poll, store orders feed, rider
-- dashboard). The writer is fail-safe: if this table does not exist yet, logging
-- silently no-ops and detection/alerts still work — so it is SAFE to upload the
-- PHP before running this script (nothing is logged until it exists).
--
-- The RESOLUTION ("who pressed dispatch and when") is NOT stored here — it is
-- derivable from the orders themselves: for each id in `undispatched_order_ids`,
-- once dispatched the order carries eta_calculated_at (when) + eta_calculated_by
-- (who). The issues report joins on that.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_ops_dispatch_missed` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rider_id`               INT             NOT NULL,
  `issue_date`             DATE            NOT NULL,                 -- for reporting + idempotency
  `left_at`                DATETIME        NULL,                    -- estimated actual office-departure (from GPS trail)
  `detected_at`            DATETIME        NOT NULL,                -- when the live detector first logged it
  `undispatched_count`     INT             NOT NULL DEFAULT 0,
  `undispatched_order_ids` TEXT            NULL,                    -- JSON array of prod order ids at detection
  `distance_meters`        INT             NULL,                    -- how far from office when detected
  `context`                VARCHAR(20)     NULL,                    -- 'first_run' | 'returned_then_left'
  `created_at`             TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rider_day` (`rider_id`, `issue_date`),           -- one departure event per rider/day (idempotent)
  KEY `idx_dm_date` (`issue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
