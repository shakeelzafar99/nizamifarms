-- Append-only audit log of shift assignment actions (who did what, when, from where).
-- Motivation: t_ops_user_shift_assignment.created_by/updated_by are OVERWRITTEN on same-day
-- in-place edits, so history is lost (see the Danish/Jul-8 incident: two people, one row).
-- This table records EVERY assign / cancel / end action as its own immutable row, so the
-- team can see the full history in the mobile Month view and the web Shift Planner.
--
-- Portable plain DDL. Run once on LOCAL and once on PRODUCTION. No foreign keys (matches the
-- rest of the schema); ids are int(11) to match t_sys_user / t_ops_* .

CREATE TABLE IF NOT EXISTS `t_ops_shift_assignment_log` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`           INT(11)      NOT NULL COMMENT 'the rider whose shift changed',
  `action`            VARCHAR(20)  NOT NULL COMMENT 'assign | cancel | end',
  `mode`              VARCHAR(20)  NULL     COMMENT 'until_changed | one_day | date_range (null for cancel/end)',
  `shift_template_id` INT(11)      NULL,
  `shift_name`        VARCHAR(100) NULL     COMMENT 'snapshot of the template name at the time',
  `effective_from`    DATE         NULL,
  `effective_to`      DATE         NULL     COMMENT 'null = ongoing (until changed)',
  `actor_user_id`     INT(11)      NULL     COMMENT 'who performed the action',
  `actor_name`        VARCHAR(150) NULL     COMMENT 'snapshot of the actor name',
  `source`            VARCHAR(10)  NULL     COMMENT 'web | mobile',
  `note`              VARCHAR(255) NULL,
  `created_at`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_actor` (`actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
