-- PAYMENT DATABASE ONLY. Apply after add_cashier_school_sales.sql.
-- Tracks the first rendered copy and later reprints of internal cashier receipts.

CREATE TABLE `cashier_receipt_print_events` (
  `receipt_print_event_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `record_type` enum('payment','cash_sale') NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `rendered_by` int unsigned NOT NULL,
  `rendered_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`receipt_print_event_id`),
  KEY `idx_receipt_print_record` (`record_type`, `record_id`),
  KEY `idx_receipt_print_user` (`rendered_by`, `rendered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
