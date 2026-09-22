-- PAYMENT DATABASE ONLY.
-- Cashier direct school-goods sales are intentionally separate from academic
-- billing/payments so they never change a student's academic balance or
-- collection-efficiency calculations.

CREATE TABLE `official_receipt_sequences` (
  `sequence_key` varchar(32) NOT NULL,
  `next_number` bigint unsigned NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sequence_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `official_receipt_sequences` (`sequence_key`, `next_number`)
SELECT CONCAT('OR-', YEAR(CURDATE())), 1;

CREATE TABLE `school_sale_categories` (
  `sale_category_id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sale_category_id`),
  UNIQUE KEY `uq_school_sale_category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `school_sale_items` (
  `sale_item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `sale_category_id` int unsigned NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`sale_item_id`),
  KEY `idx_sale_item_category_status` (`sale_category_id`, `status`),
  CONSTRAINT `fk_sale_item_category` FOREIGN KEY (`sale_category_id`) REFERENCES `school_sale_categories` (`sale_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cash_sales` (
  `cash_sale_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int unsigned NOT NULL,
  `cashier_id` int unsigned NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `academic_year_snapshot` varchar(20) DEFAULT NULL,
  `year_level_snapshot` varchar(50) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `cash_received` decimal(10,2) NOT NULL,
  `change_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sale_status` enum('Completed','Voided') NOT NULL DEFAULT 'Completed',
  `remarks` text DEFAULT NULL,
  `sold_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`cash_sale_id`),
  UNIQUE KEY `uq_cash_sale_receipt` (`receipt_number`),
  KEY `idx_cash_sale_cashier_date` (`cashier_id`, `sold_at`),
  KEY `idx_cash_sale_student_date` (`student_id`, `sold_at`),
  CONSTRAINT `fk_cash_sale_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cash_sale_items` (
  `cash_sale_line_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cash_sale_id` bigint unsigned NOT NULL,
  `sale_item_id` int unsigned DEFAULT NULL,
  `sale_category_id` int unsigned DEFAULT NULL,
  `category_name_snapshot` varchar(100) NOT NULL,
  `item_name_snapshot` varchar(150) NOT NULL,
  `unit_price_snapshot` decimal(10,2) NOT NULL,
  `quantity` int unsigned NOT NULL,
  `line_total` decimal(10,2) NOT NULL,
  PRIMARY KEY (`cash_sale_line_id`),
  KEY `idx_cash_sale_line_sale` (`cash_sale_id`),
  CONSTRAINT `fk_cash_sale_line_sale` FOREIGN KEY (`cash_sale_id`) REFERENCES `cash_sales` (`cash_sale_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `school_sale_categories` (`category_name`, `sort_order`) VALUES
  ('Uniform', 10), ('Books', 20), ('Graduation', 30), ('Miscellaneous', 40);
