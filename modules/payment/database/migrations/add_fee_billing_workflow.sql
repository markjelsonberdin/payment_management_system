-- PAYMENT DATABASE ONLY. NOT AUTO-APPLIED. Back up and compare live schema first.
-- Additive workflow tables; no existing balances, payments, or allocations change.
CREATE TABLE `fee_billing_campaigns` (
  `campaign_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fee_id` int(10) unsigned NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `fee_name_snapshot` varchar(100) NOT NULL,
  `amount_snapshot` decimal(10,2) NOT NULL,
  `status` enum('Submitted','Returned','Running','Completed') NOT NULL DEFAULT 'Submitted',
  `version` int unsigned NOT NULL DEFAULT 1,
  `submitted_by` int unsigned NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `runner_token` char(32) DEFAULT NULL,
  `lease_expires_at` timestamp NULL DEFAULT NULL,
  `cursor_student_id` int unsigned NOT NULL DEFAULT 0,
  `added_count` int unsigned NOT NULL DEFAULT 0,
  `existing_count` int unsigned NOT NULL DEFAULT 0,
  `skipped_count` int unsigned NOT NULL DEFAULT 0,
  `failed_count` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`campaign_id`),
  UNIQUE KEY `uq_fee_term` (`fee_id`,`academic_year`,`semester`),
  KEY `idx_campaign_status` (`status`),
  CONSTRAINT `fk_campaign_fee` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`fee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `fee_billing_assignments` (
  `assignment_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `billing_item_id` int(10) unsigned DEFAULT NULL,
  `outcome` enum('Added','Existing','Skipped','Failed') NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uq_campaign_student` (`campaign_id`,`student_id`),
  KEY `idx_assignment_student` (`student_id`),
  CONSTRAINT `fk_assignment_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `fee_billing_campaigns` (`campaign_id`),
  CONSTRAINT `fk_assignment_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  CONSTRAINT `fk_assignment_item` FOREIGN KEY (`billing_item_id`) REFERENCES `billing_items` (`billing_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payment_notifications` (
  `notification_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` int unsigned NOT NULL,
  `event_key` varchar(120) NOT NULL,
  `title` varchar(150) NOT NULL,
  `body` varchar(500) NOT NULL,
  `target_url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`notification_id`),
  UNIQUE KEY `uq_payment_notification_event` (`recipient_user_id`,`event_key`),
  KEY `idx_payment_notification_recipient` (`recipient_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Do not add UNIQUE(billing_id, fee_id) without inspecting existing duplicates.
-- Rollback before use: DROP these three new tables in reverse dependency order.
-- After financial use: disable code and retain data for Accounting review.
