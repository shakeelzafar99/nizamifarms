-- =====================================================================
-- 🔁 RIDER-INITIATED VEHICLE HANDOVER REQUESTS (Sep-2026)
--
-- The case this exists for, in the owner's words: Rajab checks in on his own
-- bike in the morning, then takes the VAN over when it is handed to him — and
-- gives it back in the evening. Today a manager has to be at a desk to record
-- both moves, so the registry lags reality by hours: the van's kilometres,
-- the fuel he may claim, the meters he is asked for and the ride-home timer
-- all follow whoever the registry still thinks holds what.
--
-- ⭐⭐ A REQUEST MOVES NOTHING. It is a signal, exactly like the verified-pin
--    unlock request. The machine changes hands only when Shabib or Taimur
--    APPROVES, and approval runs the same VehicleService::assign()/release()
--    the web screen has always run. There is deliberately no second handover
--    engine — this table only records who asked, for what, and what was
--    decided.
--
-- Same shape as the khaas transfer request ("a request moves NO inventory;
-- only /accept does") and the pin-unlock banner (a live query re-derives the
-- open set every poll, so a dead banner is unconstructable).
--
-- ⚠ RUN THIS BEFORE UPLOADING THE PHP. The service is Schema-guarded and
--   degrades to "no requests" without the table, but the rider's button and
--   the approval banners simply will not work until it exists.
--
-- Idempotent-ish: CREATE TABLE IF NOT EXISTS. Re-running is safe.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `t_ops_vehicle_handover_request` (
  `id` INT NOT NULL AUTO_INCREMENT,

  -- WHO is asking. Always the rider himself — a manager does not need this
  -- table, he has the fleet screen.
  `user_id` INT NOT NULL
      COMMENT 'Rider raising the request (t_sys_user.id)',

  -- WHICH WAY. 'take'   = give me this machine (morning: the van)
  --            'return' = take it back and give me mine (evening)
  -- Stored as a string, not an enum: adding a third direction later must not
  -- need a schema change on a live table.
  `direction` VARCHAR(10) NOT NULL DEFAULT 'take'
      COMMENT 'take = he wants this machine | return = he is giving it back',

  -- The machine changing hands. On a 'take' it is what he wants; on a
  -- 'return' it is what he is handing back.
  `vehicle_id` INT NOT NULL
      COMMENT 'The machine this request is about (t_ops_vehicle.id)',

  -- ⭐ WHAT HE GETS BACK on a 'return'. Snapshotted when he asks so the app can
  --   tell him "you get back: your bike DCR-xxx" BEFORE he sends, and the
  --   approver sees the same sentence. NULL = nothing / not applicable.
  -- ⚠ The APPROVER MAY CHANGE THIS (owner ruling) — the decided value is what
  --   is executed, and both are visible in the audit.
  `give_back_vehicle_id` INT NULL DEFAULT NULL
      COMMENT 'Return only: which machine he is to receive back. Editable at approval',

  -- The odometer as the keys change hands — the reading a manager types today.
  -- Optional and soft-validated everywhere, exactly like
  -- t_ops_vehicle_assignment.handover_meter, which is where an approved
  -- request writes it. A handover is a thing that already happened; refusing
  -- to record it over a questionable digit would leave the register lying.
  `meter_claimed` INT NULL DEFAULT NULL
      COMMENT 'Odometer the rider read at the handover. Optional, never blocking',

  -- ⭐ Photo of that reading. OPTIONAL TODAY, and the owner has said it may
  --   become mandatory later — which is a t_fin_config flip
  --   (HANDOVER_METER_PHOTO_REQUIRED = 'Y'), not another migration, because
  --   the column already exists from day one.
  `photo_path` VARCHAR(255) NULL DEFAULT NULL
      COMMENT 'Stored relative path of the meter photo, if one was sent',

  `note` VARCHAR(255) NULL DEFAULT NULL
      COMMENT 'Anything the rider typed',

  -- pending | approved | rejected | cancelled | expired
  -- VARCHAR not ENUM for the same reason as `direction`; every reader tests
  -- for 'pending' explicitly rather than "not something".
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending',

  `requested_at` DATETIME NOT NULL
      COMMENT 'When he asked. Drives the 12h TTL that kills an ignored request',

  `decided_by` INT NULL DEFAULT NULL
      COMMENT 'Manager who approved/rejected (t_sys_user.id)',
  `decided_at` DATETIME NULL DEFAULT NULL,
  `decision_note` VARCHAR(255) NULL DEFAULT NULL
      COMMENT 'Why it was rejected, or what the approver changed',

  -- ⭐ THE PROOF THE REQUEST ACTUALLY MOVED THE MACHINE. Links to the
  --   assignment row approval created, so "he asked / it was approved / this
  --   is the row it produced" is one join, not an inference from timestamps.
  `applied_assignment_id` INT NULL DEFAULT NULL
      COMMENT 't_ops_vehicle_assignment.id created by approving this',

  `created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
  `updated_at` TIMESTAMP NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),

  -- The banners poll for open requests every 30s from several surfaces; this
  -- keeps that a narrow index scan. Almost every row is decided, so the
  -- pending set stays tiny.
  KEY `idx_vhr_status_requested` (`status`, `requested_at`),

  -- "Has this rider already got one open?" — the one-open-request-per-rider
  -- rule, and the rider's own card state.
  KEY `idx_vhr_user_status` (`user_id`, `status`),

  -- "Is there an open request for this machine?" — shown on the vehicle card.
  KEY `idx_vhr_vehicle_status` (`vehicle_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- The photo-required switch, off by default (owner: "optional for now, we
-- might make it mandatory later"). Present from the start so turning it on
-- is one UPDATE and no upload.
-- ---------------------------------------------------------------------
INSERT INTO `t_fin_config` (`config_key`, `config_value`)
SELECT 'HANDOVER_METER_PHOTO_REQUIRED', 'N'
WHERE NOT EXISTS (
  SELECT 1 FROM `t_fin_config` WHERE `config_key` = 'HANDOVER_METER_PHOTO_REQUIRED'
);

-- ---------------------------------------------------------------------
-- Verify:
--   SHOW CREATE TABLE t_ops_vehicle_handover_request;
--   SELECT * FROM t_fin_config WHERE config_key = 'HANDOVER_METER_PHOTO_REQUIRED';
--
-- NOTE ON PERMISSIONS: approving deliberately needs NO new key. It is gated on
-- the existing `assign_vehicles` right (Shabib + Taimur today, Farooq addable
-- by ticking his role) — the same key that governs the web fleet screen and
-- the new mobile handover, so one grant moves all three together and nothing
-- can be permitted on one surface but refused on another.
-- ---------------------------------------------------------------------
