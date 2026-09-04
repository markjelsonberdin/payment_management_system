-- Migration: OCR tracking and Bank Reconciliation
-- Adds scan attempt tracking to OCR results and creates tables for Bank Reconciliation

-- 1. Update ocr_results
ALTER TABLE `ocr_results`
ADD COLUMN `scan_attempt` int(11) DEFAULT 1 AFTER `concern_id`,
ADD COLUMN `scanned_by` int(10) UNSIGNED DEFAULT NULL AFTER `extraction_notes`,
ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp() AFTER `scanned_by`,
ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() AFTER `created_at`;

-- 2. Create bank_statements
CREATE TABLE IF NOT EXISTS `bank_statements` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_bank` varchar(50) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_hash` varchar(64) NOT NULL,
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Processed','Failed') DEFAULT 'Pending',
  `row_count` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_file_hash` (`file_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Create bank_statement_rows
CREATE TABLE IF NOT EXISTS `bank_statement_rows` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `statement_id` int(10) UNSIGNED NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_time` time DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'PHP',
  `transaction_type` varchar(50) DEFAULT NULL,
  `status` enum('Unmatched','Matched','Ignored') DEFAULT 'Unmatched',
  `raw_row_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_row_data`)),
  PRIMARY KEY (`id`),
  KEY `idx_statement_id` (`statement_id`),
  KEY `idx_reference_number` (`reference_number`),
  CONSTRAINT `fk_bank_statement_rows_statement` FOREIGN KEY (`statement_id`) REFERENCES `bank_statements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
