-- Apply once, after confirming the deployed schema matches payment_db.sql.
-- Back up the database before applying. This migration changes no payment data.

ALTER TABLE `payment_concerns`
  MODIFY `verification_status` enum('Pending','On Hold','Verified','Rejected') DEFAULT 'Pending',
  ADD COLUMN `hold_reason` text DEFAULT NULL AFTER `remarks`,
  ADD COLUMN `held_by` int(10) UNSIGNED DEFAULT NULL AFTER `hold_reason`,
  ADD COLUMN `held_at` timestamp NULL DEFAULT NULL AFTER `held_by`;

ALTER TABLE `bank_statement_rows`
  ADD COLUMN `matched_concern_id` int(10) UNSIGNED DEFAULT NULL AFTER `status`,
  ADD COLUMN `matched_payment_id` int(10) UNSIGNED DEFAULT NULL AFTER `matched_concern_id`,
  ADD COLUMN `matched_by` int(10) UNSIGNED DEFAULT NULL AFTER `matched_payment_id`,
  ADD COLUMN `matched_at` timestamp NULL DEFAULT NULL AFTER `matched_by`,
  ADD UNIQUE KEY `uq_bank_row_concern` (`matched_concern_id`),
  ADD UNIQUE KEY `uq_bank_row_payment` (`matched_payment_id`),
  ADD CONSTRAINT `fk_bank_row_concern` FOREIGN KEY (`matched_concern_id`) REFERENCES `payment_concerns` (`concern_id`),
  ADD CONSTRAINT `fk_bank_row_payment` FOREIGN KEY (`matched_payment_id`) REFERENCES `payments` (`payment_id`);
