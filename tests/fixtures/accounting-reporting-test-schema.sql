-- TEST DATABASE ONLY
-- DO NOT RUN AGAINST PRODUCTION
-- TARGET DATABASE NAME MUST END IN _test
--
-- Purpose: minimal transactional schema for
-- tests/payment-accounting-reporting-regression.php.
-- This file contains schema only. It creates no database, users, or seed rows.
-- The operator must explicitly select an empty, approved local test database.

-- Fail closed before the first schema mutation. On an unsafe database this
-- deliberately selects from a nonexistent table and terminates the script.
SET @accounting_reporting_safe_database :=
    DATABASE() IS NOT NULL
    AND LOWER(DATABASE()) <> 'payment_db'
    AND LOWER(DATABASE()) LIKE '%\_test';
SET @accounting_reporting_guard_sql := IF(
    @accounting_reporting_safe_database,
    'SELECT DATABASE() AS approved_test_database',
    'SELECT * FROM __ABORT_ACCOUNTING_REPORTING_SCHEMA_REQUIRES_TEST_DATABASE__'
);
PREPARE accounting_reporting_guard FROM @accounting_reporting_guard_sql;
EXECUTE accounting_reporting_guard;
DEALLOCATE PREPARE accounting_reporting_guard;

CREATE TABLE `students` (
  `student_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `student_number` varchar(50) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `course` varchar(100) NOT NULL,
  `year_level` enum('1','2','3','4') NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `status` enum('Enrolled','Not Enrolled','Graduated','Dropped') DEFAULT 'Not Enrolled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_sync_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `student_number` (`student_number`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fee_categories` (
  `category_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `priority_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fees` (
  `fee_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fee_code` varchar(60) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `fee_name` varchar(100) NOT NULL,
  `default_amount` decimal(10,2) NOT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `identity_status` enum('Active','Archived') DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fee_id`),
  UNIQUE KEY `uq_fees_fee_code` (`fee_code`),
  KEY `fk_fees_category` (`category_id`),
  CONSTRAINT `fk_fees_category` FOREIGN KEY (`category_id`)
    REFERENCES `fee_categories` (`category_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `billing` (
  `billing_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `generated_by` int(10) unsigned DEFAULT NULL,
  `billing_type` enum('Enrollment','Assessment','Adjustment') NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_status` enum('Unpaid','Partial','Paid') DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`billing_id`),
  KEY `student_id` (`student_id`),
  KEY `idx_billing_status` (`billing_status`),
  CONSTRAINT `billing_ibfk_1` FOREIGN KEY (`student_id`)
    REFERENCES `students` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `billing_items` (
  `billing_item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `billing_id` int(10) unsigned NOT NULL,
  `fee_id` int(10) unsigned NOT NULL,
  `fee_name` varchar(100) NOT NULL,
  `source_context` varchar(50) NOT NULL,
  `added_by` int(10) unsigned DEFAULT NULL,
  `added_at` datetime DEFAULT current_timestamp(),
  `amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(10,2) NOT NULL,
  `status` enum('Unpaid','Partial','Paid') DEFAULT 'Unpaid',
  PRIMARY KEY (`billing_item_id`),
  KEY `billing_id` (`billing_id`),
  KEY `fee_id` (`fee_id`),
  CONSTRAINT `billing_items_ibfk_1` FOREIGN KEY (`billing_id`)
    REFERENCES `billing` (`billing_id`) ON DELETE CASCADE,
  CONSTRAINT `billing_items_ibfk_2` FOREIGN KEY (`fee_id`)
    REFERENCES `fees` (`fee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payments` (
  `payment_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `billing_id` int(10) unsigned NOT NULL,
  `verified_by` int(10) unsigned DEFAULT NULL,
  `transaction_type` enum('Walk-in','Online','Payment Concern') NOT NULL,
  `payment_method` enum('Walk-in','Online','Bank Transfer') NOT NULL,
  `amount` decimal(10,2) NOT NULL COMMENT 'Amount applied to the student balance',
  `processing_fee` decimal(10,2) DEFAULT NULL COMMENT 'Gateway fee',
  `checkout_total` decimal(10,2) DEFAULT NULL COMMENT 'Amount actually charged by PayMongo',
  `category_id` int(11) DEFAULT NULL,
  `allocation_context` enum('ENROLLMENT_PRIORITY','SPECIFIC_ITEM') NOT NULL DEFAULT 'ENROLLMENT_PRIORITY',
  `billing_item_id` int(10) unsigned DEFAULT NULL,
  `checkout_session_id` varchar(255) DEFAULT NULL,
  `payment_intent_id` varchar(255) DEFAULT NULL,
  `payment_method_id` varchar(255) DEFAULT NULL,
  `cash_received` decimal(10,2) DEFAULT NULL,
  `change_amount` decimal(10,2) DEFAULT NULL,
  `payment_channel` enum('Cash','GCash','Maya','Visa','Mastercard','Bank','PayMongo','QRPh') NOT NULL,
  `gateway_environment` enum('test','live') DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_status` enum('Pending','Verified','Rejected','Failed','Cancelled','Expired') NOT NULL DEFAULT 'Pending',
  `payment_date` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `reference_number` (`reference_number`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  UNIQUE KEY `checkout_session_id` (`checkout_session_id`),
  UNIQUE KEY `payment_intent_id` (`payment_intent_id`),
  KEY `student_id` (`student_id`),
  KEY `billing_id` (`billing_id`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `fk_payments_billing_item` (`billing_item_id`),
  KEY `idx_qr_pending_expiry` (`student_id`,`billing_id`,`payment_channel`,`payment_status`,`expires_at`),
  KEY `idx_payment_environment` (`gateway_environment`,`payment_status`),
  CONSTRAINT `fk_payments_billing_item` FOREIGN KEY (`billing_item_id`)
    REFERENCES `billing_items` (`billing_item_id`) ON DELETE SET NULL,
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`student_id`)
    REFERENCES `students` (`student_id`),
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`billing_id`)
    REFERENCES `billing` (`billing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payment_allocations` (
  `allocation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` int(10) unsigned NOT NULL,
  `billing_item_id` int(10) unsigned NOT NULL,
  `allocated_amount` decimal(10,2) NOT NULL,
  `allocated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`allocation_id`),
  UNIQUE KEY `uq_payment_billing_item` (`payment_id`,`billing_item_id`),
  KEY `idx_allocations_payment` (`payment_id`),
  KEY `idx_allocations_billing_item` (`billing_item_id`),
  CONSTRAINT `fk_allocations_billing_item` FOREIGN KEY (`billing_item_id`)
    REFERENCES `billing_items` (`billing_item_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_allocations_payment` FOREIGN KEY (`payment_id`)
    REFERENCES `payments` (`payment_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Exact rollback for a dedicated schema created from this artifact:
-- DROP TABLE IF EXISTS `payment_allocations`;
-- DROP TABLE IF EXISTS `payments`;
-- DROP TABLE IF EXISTS `billing_items`;
-- DROP TABLE IF EXISTS `billing`;
-- DROP TABLE IF EXISTS `fees`;
-- DROP TABLE IF EXISTS `fee_categories`;
-- DROP TABLE IF EXISTS `students`;
