-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 29, 2026 at 02:17 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `payment_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `billing`
--

CREATE TABLE `billing` (
  `billing_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `generated_by` int(10) UNSIGNED DEFAULT NULL,
  `billing_type` enum('Enrollment','Assessment','Adjustment') NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_status` enum('Unpaid','Partial','Paid') DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `billing`
--

INSERT INTO `billing` (`billing_id`, `student_id`, `generated_by`, `billing_type`, `academic_year`, `semester`, `total_amount`, `discount_amount`, `remaining_balance`, `billing_status`, `created_at`, `updated_at`) VALUES
(1, 850, 784, 'Enrollment', '2026-2027', '1st', 12885.00, 0.00, 3585.00, 'Partial', '2026-08-20 02:59:18', '2026-08-29 10:36:49'),
(3, 884, 784, 'Enrollment', '2026-2027', '1st', 12635.00, 0.00, 0.00, 'Paid', '2026-08-24 10:35:47', '2026-08-29 04:41:20'),
(4, 909, 784, 'Assessment', '2026-2027', '1st', 3110.00, 0.00, 3110.00, 'Unpaid', '2026-08-28 14:27:20', '2026-08-29 03:57:55');

--
-- Triggers `billing`
--
DELIMITER $$
CREATE TRIGGER `before_billing_insert` BEFORE INSERT ON `billing` FOR EACH ROW BEGIN
    -- Initialize remaining balance based on Gross Assessment - Discount
    SET NEW.remaining_balance = GREATEST(0, NEW.total_amount - NEW.discount_amount);
    
    IF NEW.remaining_balance <= 0 THEN
        SET NEW.billing_status = 'Paid';
    ELSE
        SET NEW.billing_status = 'Unpaid';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `billing_items`
--

CREATE TABLE `billing_items` (
  `billing_item_id` int(10) UNSIGNED NOT NULL,
  `billing_id` int(10) UNSIGNED NOT NULL,
  `fee_id` int(10) UNSIGNED NOT NULL,
  `fee_name` varchar(100) NOT NULL,
  `source_context` varchar(50) NOT NULL,
  `added_by` int(10) UNSIGNED DEFAULT NULL,
  `added_at` datetime DEFAULT current_timestamp(),
  `amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(10,2) NOT NULL,
  `status` enum('Unpaid','Partial','Paid') DEFAULT 'Unpaid'
) ;

--
-- Dumping data for table `billing_items`
--

INSERT INTO `billing_items` (`billing_item_id`, `billing_id`, `fee_id`, `fee_name`, `source_context`, `added_by`, `added_at`, `amount`, `paid_amount`, `remaining_amount`, `status`) VALUES
(1, 1, 31, 'Registration', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 400.00, 400.00, 0.00, 'Paid'),
(2, 1, 33, 'Library', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 650.00, 650.00, 0.00, 'Paid'),
(3, 1, 34, 'Athletics & Sports Dev. Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 500.00, 500.00, 0.00, 'Paid'),
(4, 1, 35, 'Cultural Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 400.00, 400.00, 0.00, 'Paid'),
(5, 1, 36, 'Guidance & Counseling', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 400.00, 400.00, 0.00, 'Paid'),
(6, 1, 37, 'Energy Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 1000.00, 1000.00, 0.00, 'Paid'),
(7, 1, 38, 'Laboratory Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 600.00, 150.00, 450.00, 'Partial'),
(8, 1, 39, 'Community & Student Dev. Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 600.00, 0.00, 600.00, 'Unpaid'),
(9, 1, 40, 'Insurance', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 25.00, 0.00, 25.00, 'Unpaid'),
(10, 1, 41, 'Medical and Dental', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 400.00, 0.00, 400.00, 'Unpaid'),
(11, 1, 42, 'Student Handbook', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 250.00, 250.00, 0.00, 'Paid'),
(12, 1, 43, 'RFID', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 500.00, 500.00, 0.00, 'Paid'),
(13, 1, 45, 'Research Forum 2026', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 200.00, 200.00, 0.00, 'Paid'),
(14, 3, 31, 'Registration', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 400.00, 400.00, 0.00, 'Paid'),
(15, 3, 33, 'Library', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 650.00, 650.00, 0.00, 'Paid'),
(16, 3, 34, 'Athletics & Sports Dev. Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 500.00, 500.00, 0.00, 'Paid'),
(17, 3, 35, 'Cultural Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 400.00, 400.00, 0.00, 'Paid'),
(18, 3, 36, 'Guidance & Counseling', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 400.00, 400.00, 0.00, 'Paid'),
(19, 3, 37, 'Energy Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 1000.00, 1000.00, 0.00, 'Paid'),
(20, 3, 38, 'Laboratory Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 600.00, 600.00, 0.00, 'Paid'),
(21, 3, 39, 'Community & Student Dev. Fee', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 600.00, 600.00, 0.00, 'Paid'),
(22, 3, 40, 'Insurance', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 25.00, 25.00, 0.00, 'Paid'),
(23, 3, 41, 'Medical and Dental', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 400.00, 400.00, 0.00, 'Paid'),
(24, 3, 42, 'Student Handbook', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 250.00, 250.00, 0.00, 'Paid'),
(25, 3, 43, 'RFID', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 500.00, 500.00, 0.00, 'Paid'),
(26, 3, 45, 'Research Forum 2026', 'Enrollment Assessment', NULL, '2026-08-24 20:59:40', 200.00, 200.00, 0.00, 'Paid'),
(27, 1, 46, 'Pre Oral Defense', 'Assessment', 784, '2026-08-24 21:04:59', 2100.00, 2100.00, 0.00, 'Paid'),
(28, 1, 47, 'Final Defense', 'Assessment', 784, '2026-08-24 21:04:59', 2100.00, 0.00, 2100.00, 'Unpaid'),
(29, 3, 46, 'Pre Oral Defense', 'Assessment', 784, '2026-08-26 14:42:41', 2100.00, 2100.00, 0.00, 'Paid'),
(30, 3, 47, 'Final Defense', 'Assessment', 784, '2026-08-26 14:42:41', 2100.00, 2100.00, 0.00, 'Paid'),
(31, 1, 42, 'Student Handbook', 'Assessment', 784, '2026-08-26 20:08:19', 250.00, 250.00, 0.00, 'Paid'),
(32, 1, 48, 'Basketball Share', 'Assessment', 784, '2026-08-26 20:48:04', 2500.00, 2500.00, 0.00, 'Paid'),
(33, 3, 48, 'Basketball Share', 'Enrollment', 784, '2026-08-26 21:08:13', 2500.00, 2500.00, 0.00, 'Paid'),
(34, 4, 46, 'Pre Oral Defense', 'Assessment', 784, '2026-08-28 22:27:20', 2100.00, 0.00, 2100.00, 'Unpaid'),
(35, 4, 49, 'adadadadada', 'Assessment', 784, '2026-08-29 11:57:55', 1000.00, 0.00, 1000.00, 'Unpaid'),
(36, 4, 50, 'test', 'Assessment', 784, '2026-08-29 11:57:55', 10.00, 0.00, 10.00, 'Unpaid'),
(37, 1, 51, 'test', 'Enrollment', 784, '2026-08-29 12:38:52', 10.00, 0.00, 10.00, 'Unpaid'),
(38, 3, 51, 'test', 'Assessment', 784, '2026-08-29 12:40:13', 10.00, 10.00, 0.00, 'Paid');

--
-- Triggers `billing_items`
--
DELIMITER $$
CREATE TRIGGER `before_billing_items_insert` BEFORE INSERT ON `billing_items` FOR EACH ROW BEGIN
    -- Prevent over-payment at DB level
    IF NEW.paid_amount > NEW.amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid amount cannot exceed billing item amount.';
    END IF;

    -- Recalculate remaining_amount
    SET NEW.remaining_amount = NEW.amount - NEW.paid_amount;

    -- Set Status
    IF NEW.remaining_amount <= 0 THEN
        SET NEW.status = 'Paid';
    ELSEIF NEW.paid_amount > 0 THEN
        SET NEW.status = 'Partial';
    ELSE
        SET NEW.status = 'Unpaid';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `before_billing_items_update` BEFORE UPDATE ON `billing_items` FOR EACH ROW BEGIN
    -- Prevent over-payment at DB level
    IF NEW.paid_amount > NEW.amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid amount cannot exceed billing item amount.';
    END IF;

    -- Recalculate remaining_amount
    SET NEW.remaining_amount = NEW.amount - NEW.paid_amount;

    -- Set Status
    IF NEW.remaining_amount <= 0 THEN
        SET NEW.status = 'Paid';
    ELSEIF NEW.paid_amount > 0 THEN
        SET NEW.status = 'Partial';
    ELSE
        SET NEW.status = 'Unpaid';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `fees`
--

CREATE TABLE `fees` (
  `fee_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `fee_name` varchar(100) NOT NULL,
  `default_amount` decimal(10,2) NOT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fees`
--

INSERT INTO `fees` (`fee_id`, `category_id`, `fee_name`, `default_amount`, `is_required`, `status`, `description`, `created_at`, `updated_at`) VALUES
(31, 2, 'Registration', 400.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(33, 2, 'Library', 650.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(34, 2, 'Athletics & Sports Dev. Fee', 500.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(35, 2, 'Cultural Fee', 400.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(36, 2, 'Guidance & Counseling', 400.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(37, 2, 'Energy Fee', 1000.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(38, 2, 'Laboratory Fee', 600.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(39, 2, 'Community & Student Dev. Fee', 600.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(40, 2, 'Insurance', 25.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(41, 2, 'Medical and Dental', 400.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(42, 5, 'Student Handbook', 250.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(43, 5, 'RFID', 500.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(45, 5, 'Research Forum 2026', 200.00, 1, 'Active', NULL, '2026-08-12 12:49:45', '2026-08-12 12:49:45'),
(46, 5, 'Pre Oral Defense', 2100.00, 1, 'Active', NULL, '2026-08-24 11:48:22', '2026-08-24 11:48:22'),
(47, 5, 'Final Defense', 2100.00, 1, 'Active', NULL, '2026-08-24 11:48:49', '2026-08-24 11:48:49'),
(48, 6, 'Basketball Share', 2500.00, 1, 'Active', NULL, '2026-08-26 12:46:59', '2026-08-26 12:46:59'),
(49, 2, 'adadadadada', 1000.00, 1, 'Inactive', NULL, '2026-08-29 03:41:58', '2026-08-29 04:37:27'),
(50, 2, 'test', 10.00, 1, 'Inactive', NULL, '2026-08-29 03:51:58', '2026-08-29 04:37:24'),
(51, 2, 'test', 10.00, 1, 'Inactive', NULL, '2026-08-29 04:37:59', '2026-08-29 09:53:25');

-- --------------------------------------------------------

--
-- Table structure for table `fee_categories`
--

CREATE TABLE `fee_categories` (
  `category_id` int(10) UNSIGNED NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `priority_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_categories`
--

INSERT INTO `fee_categories` (`category_id`, `category_name`, `priority_order`, `status`) VALUES
(1, 'Tuition', 6, 'Active'),
(2, 'Miscellaneous', 1, 'Active'),
(3, 'Laboratory & Computer', 3, 'Active'),
(4, 'Student Council & Organization', 4, 'Active'),
(5, 'Supplementary Fees', 2, 'Active'),
(6, 'Other', 5, 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `ocr_results`
--

CREATE TABLE `ocr_results` (
  `ocr_result_id` int(10) UNSIGNED NOT NULL,
  `concern_id` int(10) UNSIGNED NOT NULL,
  `scan_attempt` int(11) DEFAULT 1,
  `extracted_amount` decimal(10,2) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `confidence_score` decimal(5,2) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `transaction_time` time DEFAULT NULL,
  `raw_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_json`)),
  `extraction_status` varchar(50) DEFAULT 'PROCESSING',
  `extraction_notes` text DEFAULT NULL,
  `scanned_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `ocr_results`
--

INSERT INTO `ocr_results` (`ocr_result_id`, `concern_id`, `scan_attempt`, `extracted_amount`, `bank_name`, `confidence_score`, `reference_number`, `transaction_date`, `transaction_time`, `raw_json`, `extraction_status`, `extraction_notes`, `scanned_by`, `created_at`, `updated_at`) VALUES
(3, 2, 1, 1500.00, 'GCash', NULL, '1029384756', '2026-08-26', '14:30:00', '{\"text\":\"MOCK OCR SOURCE\\nGCash\\nAmount Paid: PHP 1,500.00\\nRef No. 1029384756\\nDate: 08\\/26\\/2026 14:30\"}', 'COMPLETE', '', NULL, current_timestamp(), NULL);

-- --------------------------------------------------------

--
-- Table structure for table `bank_statements`
--

CREATE TABLE `bank_statements` (
  `id` int(10) UNSIGNED NOT NULL,
  `source_bank` varchar(50) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_hash` varchar(64) NOT NULL,
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Processed','Failed') DEFAULT 'Pending',
  `row_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_statement_rows`
--

CREATE TABLE `bank_statement_rows` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `statement_id` int(10) UNSIGNED NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_time` time DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'PHP',
  `transaction_type` varchar(50) DEFAULT NULL,
  `status` enum('Unmatched','Matched','Ignored') DEFAULT 'Unmatched',
  `raw_row_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_row_data`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `billing_id` int(10) UNSIGNED NOT NULL,
  `verified_by` int(10) UNSIGNED DEFAULT NULL,
  `transaction_type` enum('Walk-in','Online','Payment Concern') NOT NULL,
  `payment_method` enum('Walk-in','Online','Bank Transfer') NOT NULL,
  `amount` decimal(10,2) NOT NULL COMMENT 'Amount applied to the student balance',
  `processing_fee` decimal(10,2) DEFAULT NULL COMMENT 'Gateway fee',
  `checkout_total` decimal(10,2) DEFAULT NULL COMMENT 'Amount actually charged by PayMongo',
  `category_id` int(11) DEFAULT NULL COMMENT 'ID of the designated fee category',
  `allocation_context` enum('ENROLLMENT_PRIORITY','SPECIFIC_ITEM') NOT NULL DEFAULT 'ENROLLMENT_PRIORITY',
  `billing_item_id` int(10) UNSIGNED DEFAULT NULL,
  `checkout_session_id` varchar(255) DEFAULT NULL COMMENT 'PayMongo session ID',
  `payment_intent_id` varchar(255) DEFAULT NULL,
  `cash_received` decimal(10,2) DEFAULT NULL,
  `change_amount` decimal(10,2) DEFAULT NULL,
  `payment_channel` enum('Cash','GCash','Maya','Visa','Mastercard','Bank','PayMongo','QRPh') NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_status` enum('Pending','Verified','Rejected','Failed') DEFAULT 'Pending',
  `payment_date` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Used for QR payment 30-min expiry',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `student_id`, `billing_id`, `verified_by`, `transaction_type`, `payment_method`, `amount`, `processing_fee`, `checkout_total`, `category_id`, `allocation_context`, `billing_item_id`, `checkout_session_id`, `payment_intent_id`, `cash_received`, `change_amount`, `payment_channel`, `reference_number`, `payment_status`, `payment_date`, `remarks`, `receipt_number`, `verified_at`, `created_at`) VALUES
(13, 850, 1, NULL, 'Online', 'Online', 1500.00, 34.21, 1534.21, 2, 'ENROLLMENT_PRIORITY', NULL, 'cs_a8801b7a87c3b46b20d304a6', NULL, NULL, NULL, 'GCash', 'PM-1787380122-7217', 'Verified', '2026-08-22', NULL, NULL, '2026-08-22 06:29:14', '2026-08-22 06:28:43'),
(14, 850, 1, NULL, 'Online', 'Online', 1000.00, 22.81, 1022.81, 2, 'ENROLLMENT_PRIORITY', NULL, 'cs_6862bbdfb3a3a850dfc354b1', NULL, NULL, NULL, 'GCash', 'PM-1787382635-5135', 'Verified', '2026-08-22', NULL, NULL, '2026-08-22 07:11:04', '2026-08-22 07:10:36'),
(17, 884, 3, 784, 'Walk-in', 'Walk-in', 2000.00, NULL, NULL, NULL, 'ENROLLMENT_PRIORITY', NULL, NULL, NULL, 2000.00, 0.00, 'Cash', 'OR-20260824-2431', 'Verified', '2026-08-24', '', 'OR-20260824-2431', '2026-08-24 10:54:53', '2026-08-24 10:54:53'),
(18, 884, 3, NULL, 'Online', 'Online', 2100.00, 47.90, 2147.90, 5, 'ENROLLMENT_PRIORITY', NULL, 'cs_d5b79111b8663322c85e2043', NULL, NULL, NULL, 'GCash', 'PM-1787742797-8197', 'Verified', '2026-08-26', NULL, NULL, '2026-08-26 11:13:31', '2026-08-26 11:13:18'),
(19, 884, 3, 784, 'Walk-in', 'Walk-in', 2100.00, NULL, NULL, NULL, 'ENROLLMENT_PRIORITY', NULL, NULL, NULL, 2200.00, 100.00, 'Cash', 'OR-20260826-5049', 'Verified', '2026-08-26', '', 'OR-20260826-5049', '2026-08-26 11:31:44', '2026-08-26 11:31:44'),
(20, 850, 1, NULL, 'Online', 'Online', 950.00, 21.67, 971.67, 5, 'ENROLLMENT_PRIORITY', NULL, 'cs_b34bfe73958fbe721d275021', NULL, NULL, NULL, 'GCash', 'PM-1787746147-4817', 'Verified', '2026-08-26', NULL, NULL, '2026-08-26 12:09:34', '2026-08-26 12:09:08'),
(21, 884, 3, NULL, 'Online', 'Online', 450.00, 10.26, 460.26, NULL, 'SPECIFIC_ITEM', 30, 'cs_e8013d3c29e88c529ee9f34a', NULL, NULL, NULL, 'GCash', 'PM-1787748358-9461', 'Verified', '2026-08-26', NULL, NULL, '2026-08-26 12:46:08', '2026-08-26 12:45:58'),
(22, 884, 3, NULL, 'Online', 'Online', 2500.00, 57.02, 2557.02, NULL, 'SPECIFIC_ITEM', 33, 'cs_c3bdb61dbe4845d381b42683', NULL, NULL, NULL, 'GCash', 'PM-1787750224-3017', 'Verified', '2026-08-26', NULL, NULL, '2026-08-26 13:17:23', '2026-08-26 13:17:05'),
(23, 884, 3, NULL, 'Online', 'Online', 2000.00, 45.62, 2045.62, NULL, 'ENROLLMENT_PRIORITY', NULL, 'cs_0a7a7e785cbb77de36b12132', NULL, NULL, NULL, 'GCash', 'PM-1787750274-5598', 'Verified', '2026-08-26', NULL, NULL, '2026-08-26 13:18:05', '2026-08-26 13:17:54'),
(24, 850, 1, NULL, 'Online', 'Online', 1000.00, 0.00, 1000.00, NULL, 'ENROLLMENT_PRIORITY', NULL, NULL, 'pi_test_1787753749', NULL, NULL, 'QRPh', 'PM-TEST-1787753749', 'Verified', '2026-08-26', NULL, NULL, '2026-08-26 14:15:49', '2026-08-26 14:15:49'),
(26, 850, 1, NULL, 'Online', 'Online', 2500.00, 57.02, 2557.02, NULL, 'SPECIFIC_ITEM', 32, 'cs_e23c0b655152d53f478c530d', NULL, NULL, NULL, 'GCash', 'PM-1787846235-4380', 'Verified', '2026-08-27', NULL, NULL, '2026-08-27 15:59:57', '2026-08-27 15:57:16'),
(31, 884, 3, NULL, 'Online', 'Online', 1475.00, 33.64, 1508.64, NULL, 'ENROLLMENT_PRIORITY', NULL, 'cs_90db85d2948fe34dba458faa', NULL, NULL, NULL, 'GCash', 'PM-1787917934-2146', 'Verified', '2026-08-28', NULL, NULL, '2026-08-28 11:56:15', '2026-08-28 11:52:15'),
(36, 884, 3, NULL, 'Online', 'Online', 10.00, 0.23, 10.23, NULL, 'SPECIFIC_ITEM', 38, 'cs_cde4b36d54b435032dddc139', NULL, NULL, NULL, 'GCash', 'PM-1787978458-8882', 'Verified', '2026-08-29', NULL, NULL, '2026-08-29 04:41:20', '2026-08-29 04:40:58'),
(37, 850, 1, NULL, 'Online', 'Online', 2100.00, 47.90, 2147.90, NULL, 'SPECIFIC_ITEM', 27, 'cs_d8759420d532491738ebcb91', NULL, NULL, NULL, 'GCash', 'PM-1787998130-7781', 'Verified', '2026-08-29', NULL, NULL, '2026-08-29 10:09:00', '2026-08-29 10:08:51'),
(42, 850, 1, NULL, 'Online', 'Online', 250.00, 5.70, 255.70, NULL, 'SPECIFIC_ITEM', 31, 'cs_d416d18ec2f0ed4dd58dcda9', NULL, NULL, NULL, 'GCash', 'PM-1787999799-1618', 'Verified', '2026-08-29', NULL, NULL, '2026-08-29 10:36:49', '2026-08-29 10:36:40');

-- --------------------------------------------------------

--
-- Table structure for table `payment_allocations`
--

CREATE TABLE `payment_allocations` (
  `allocation_id` int(10) UNSIGNED NOT NULL,
  `payment_id` int(10) UNSIGNED NOT NULL,
  `billing_item_id` int(10) UNSIGNED NOT NULL,
  `allocated_amount` decimal(10,2) NOT NULL,
  `allocated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `payment_allocations`
--

INSERT INTO `payment_allocations` (`allocation_id`, `payment_id`, `billing_item_id`, `allocated_amount`, `allocated_at`) VALUES
(1, 13, 1, 400.00, '2026-08-22 06:29:14'),
(2, 13, 2, 650.00, '2026-08-22 06:29:14'),
(3, 13, 3, 450.00, '2026-08-22 06:29:14'),
(4, 14, 3, 50.00, '2026-08-22 07:11:04'),
(5, 14, 4, 400.00, '2026-08-22 07:11:04'),
(6, 14, 5, 400.00, '2026-08-22 07:11:04'),
(7, 14, 6, 150.00, '2026-08-22 07:11:04'),
(16, 17, 25, 500.00, '2026-08-24 10:54:53'),
(17, 17, 14, 400.00, '2026-08-24 10:54:53'),
(18, 17, 15, 650.00, '2026-08-24 10:54:53'),
(19, 17, 16, 450.00, '2026-08-24 10:54:53'),
(20, 18, 24, 250.00, '2026-08-26 11:13:31'),
(21, 18, 26, 200.00, '2026-08-26 11:13:31'),
(22, 18, 29, 1650.00, '2026-08-26 11:13:31'),
(23, 19, 29, 450.00, '2026-08-26 11:31:44'),
(24, 19, 30, 1650.00, '2026-08-26 11:31:44'),
(25, 20, 11, 250.00, '2026-08-26 12:09:34'),
(26, 20, 12, 500.00, '2026-08-26 12:09:34'),
(27, 20, 13, 200.00, '2026-08-26 12:09:34'),
(28, 21, 30, 450.00, '2026-08-26 12:46:08'),
(29, 22, 33, 2500.00, '2026-08-26 13:17:23'),
(30, 23, 16, 50.00, '2026-08-26 13:18:05'),
(31, 23, 17, 400.00, '2026-08-26 13:18:05'),
(32, 23, 18, 400.00, '2026-08-26 13:18:05'),
(33, 23, 19, 1000.00, '2026-08-26 13:18:05'),
(34, 23, 20, 150.00, '2026-08-26 13:18:05'),
(35, 24, 6, 850.00, '2026-08-26 14:15:49'),
(36, 24, 7, 150.00, '2026-08-26 14:15:49'),
(37, 26, 32, 2500.00, '2026-08-27 15:59:57'),
(38, 31, 20, 450.00, '2026-08-28 11:56:15'),
(39, 31, 21, 600.00, '2026-08-28 11:56:15'),
(40, 31, 22, 25.00, '2026-08-28 11:56:15'),
(41, 31, 23, 400.00, '2026-08-28 11:56:15'),
(42, 36, 38, 10.00, '2026-08-29 04:41:20'),
(43, 37, 27, 2100.00, '2026-08-29 10:09:00'),
(48, 42, 31, 250.00, '2026-08-29 10:36:49');

--
-- Triggers `payment_allocations`
--
DELIMITER $$
CREATE TRIGGER `after_payment_allocations_insert` AFTER INSERT ON `payment_allocations` FOR EACH ROW BEGIN
    -- Push the allocation amount up to the billing item
    -- (This will fire the `before_billing_items_update` trigger)
    UPDATE `billing_items`
    SET `paid_amount` = `paid_amount` + NEW.allocated_amount
    WHERE `billing_item_id` = NEW.billing_item_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `payment_concerns`
--

CREATE TABLE `payment_concerns` (
  `concern_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `payment_id` int(10) UNSIGNED DEFAULT NULL,
  `receipt_path` varchar(255) NOT NULL,
  `verification_status` enum('Pending','Verified','Rejected') DEFAULT 'Pending',
  `ocr_status` enum('Processing','Completed','Failed') DEFAULT 'Processing',
  `remarks` text DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_concerns`
--

INSERT INTO `payment_concerns` (`concern_id`, `student_id`, `payment_id`, `receipt_path`, `verification_status`, `ocr_status`, `remarks`, `reviewed_by`, `submitted_at`, `reviewed_at`) VALUES
(1, 884, NULL, 'uploads/receipts/7eefbe98fd3676412fb1d57c6dc63bc9.jpg', 'Pending', 'Processing', '[wrong_amount] ', NULL, '2026-08-28 13:18:39', NULL),
(2, 909, NULL, 'uploads/receipts/f1db252b83638ed55a0145cfe2c1077d.jpg', 'Pending', 'Completed', '[wrong_amount] Pa-verify ako niyan ya', NULL, '2026-08-28 14:28:25', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment_gateway_settings`
--

CREATE TABLE `payment_gateway_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_gateway_settings`
--

INSERT INTO `payment_gateway_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES
('fee_policy', 'pass_to_student', 'pass_to_student or absorb_by_school', '2026-08-12 12:49:45'),
('gateway_mode', 'test', 'Set to live or test mode', '2026-08-29 10:25:55'),
('live_channel_card', '0', '1 to Enable, 0 to Disable Credit/Debit Cards (Live)', '2026-08-21 03:51:32'),
('live_channel_gcash', '0', '1 to Enable, 0 to Disable GCash (Live)', '2026-08-21 03:51:32'),
('live_channel_maya', '0', '1 to Enable, 0 to Disable Maya (Live)', '2026-08-21 03:51:32'),
('live_channel_qrph', '1', '1 to Enable, 0 to Disable QR Ph (Live)', '2026-08-21 03:57:32'),
('paymongo_public_key', '', 'PayMongo Public Key (Stored in .env)', '2026-08-12 12:49:45'),
('paymongo_secret_key', '', 'PayMongo Secret Key (Stored in .env)', '2026-08-12 12:49:45'),
('paymongo_webhook_secret', '', 'Webhook Secret (Stored in .env)', '2026-08-12 12:49:45'),
('test_channel_card', '1', '1 to Enable, 0 to Disable Credit/Debit Cards', '2026-08-22 04:39:18'),
('test_channel_gcash', '1', '1 to Enable, 0 to Disable GCash', '2026-08-22 04:39:18'),
('test_channel_maya', '1', '1 to Enable, 0 to Disable Maya', '2026-08-21 03:51:32'),
('test_channel_qrph', '1', '1 to Enable, 0 to Disable QR Ph (Test)', '2026-08-26 14:16:34'),
('webhook_secret_live', '', NULL, '2026-08-21 06:05:13'),
('webhook_secret_test', 'whsk_o5adgJEYQ8HTZBYQftSVYay9', NULL, '2026-08-22 06:06:17');

-- --------------------------------------------------------

--
-- Table structure for table `payment_settings_audit`
--

CREATE TABLE `payment_settings_audit` (
  `audit_id` int(11) NOT NULL,
  `setting_key` varchar(50) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paymongo_transactions`
--

CREATE TABLE `paymongo_transactions` (
  `paymongo_transaction_id` int(10) UNSIGNED NOT NULL,
  `payment_id` int(10) UNSIGNED DEFAULT NULL,
  `checkout_session_id` varchar(150) DEFAULT NULL,
  `payment_intent_id` varchar(150) DEFAULT NULL,
  `paymongo_payment_id` varchar(150) DEFAULT NULL,
  `webhook_event_id` varchar(150) DEFAULT NULL,
  `event_type` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `convenience_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_charged` decimal(10,2) NOT NULL DEFAULT 0.00,
  `signature_verified` tinyint(1) NOT NULL DEFAULT 0,
  `processing_status` enum('Received','Processing','Processed','Failed','Ignored') NOT NULL DEFAULT 'Received',
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `scholarships`
--

CREATE TABLE `scholarships` (
  `scholarship_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `billing_id` int(10) UNSIGNED DEFAULT NULL,
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `scholarship_name` varchar(100) NOT NULL,
  `discount_type` enum('Percentage','Fixed Amount') NOT NULL,
  `discount_percentage` decimal(5,2) DEFAULT NULL,
  `status` enum('Active','Revoked','Expired') DEFAULT 'Active',
  `approved_at` timestamp NULL DEFAULT NULL
) ;

--
-- Dumping data for table `scholarships`
--

INSERT INTO `scholarships` (`scholarship_id`, `student_id`, `billing_id`, `approved_by`, `discount_amount`, `scholarship_name`, `discount_type`, `discount_percentage`, `status`, `approved_at`) VALUES
(1, 9, 1, 4, 2962.50, 'Academic Excellence', 'Percentage', 50.00, 'Active', '2026-08-16 05:25:57');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `student_number` varchar(50) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `course` varchar(100) NOT NULL,
  `year_level` enum('1','2','3','4') NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `status` enum('Enrolled','Not Enrolled','Graduated','Dropped') DEFAULT 'Not Enrolled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_sync_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `user_id`, `student_number`, `full_name`, `course`, `year_level`, `section`, `contact_number`, `status`, `created_at`, `last_sync_at`) VALUES
(9, 9, 'S230000001', 'Student User', 'Unknown', '1', NULL, NULL, '', '2026-08-18 09:57:26', '2026-08-18 09:57:26'),
(850, 850, 'S230115569', 'Lebron James', 'Unknown', '1', NULL, NULL, 'Enrolled', '2026-08-20 02:59:11', '2026-08-29 10:34:00'),
(884, 884, 's230115570', 'Kevin Durant', 'Unknown', '1', NULL, NULL, 'Enrolled', '2026-08-24 10:35:37', '2026-08-29 10:01:35'),
(909, 909, 'S230115571', 'Justine Bonifacio', 'Unknown', '1', NULL, NULL, 'Enrolled', '2026-08-28 14:26:41', '2026-08-29 04:02:07');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `billing`
--
ALTER TABLE `billing`
  ADD PRIMARY KEY (`billing_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `generated_by` (`generated_by`),
  ADD KEY `idx_billing_status` (`billing_status`);

--
-- Indexes for table `billing_items`
--
ALTER TABLE `billing_items`
  ADD PRIMARY KEY (`billing_item_id`),
  ADD KEY `billing_id` (`billing_id`),
  ADD KEY `fee_id` (`fee_id`);

--
-- Indexes for table `fees`
--
ALTER TABLE `fees`
  ADD PRIMARY KEY (`fee_id`),
  ADD KEY `fk_fees_category` (`category_id`);

--
-- Indexes for table `fee_categories`
--
ALTER TABLE `fee_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `ocr_results`
--
ALTER TABLE `ocr_results`
  ADD PRIMARY KEY (`ocr_result_id`),
  ADD UNIQUE KEY `idx_concern_unique` (`concern_id`),
  ADD KEY `concern_id` (`concern_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD UNIQUE KEY `checkout_session_id` (`checkout_session_id`),
  ADD UNIQUE KEY `payment_intent_id` (`payment_intent_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `billing_id` (`billing_id`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `fk_payments_billing_item` (`billing_item_id`);

--
-- Indexes for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD UNIQUE KEY `uq_payment_billing_item` (`payment_id`,`billing_item_id`),
  ADD KEY `idx_allocations_payment` (`payment_id`),
  ADD KEY `idx_allocations_billing_item` (`billing_item_id`);

--
-- Indexes for table `payment_concerns`
--
ALTER TABLE `payment_concerns`
  ADD PRIMARY KEY (`concern_id`),
  ADD KEY `payment_id` (`payment_id`),
  ADD KEY `idx_payment_concerns_student` (`student_id`);

--
-- Indexes for table `payment_gateway_settings`
--
ALTER TABLE `payment_gateway_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `payment_settings_audit`
--
ALTER TABLE `payment_settings_audit`
  ADD PRIMARY KEY (`audit_id`);

--
-- Indexes for table `paymongo_transactions`
--
ALTER TABLE `paymongo_transactions`
  ADD PRIMARY KEY (`paymongo_transaction_id`),
  ADD UNIQUE KEY `checkout_session_id` (`checkout_session_id`),
  ADD UNIQUE KEY `payment_intent_id` (`payment_intent_id`),
  ADD UNIQUE KEY `paymongo_payment_id` (`paymongo_payment_id`),
  ADD UNIQUE KEY `webhook_event_id` (`webhook_event_id`),
  ADD KEY `idx_paymongo_payment` (`payment_id`),
  ADD KEY `idx_paymongo_status` (`processing_status`);

--
-- Indexes for table `scholarships`
--
ALTER TABLE `scholarships`
  ADD PRIMARY KEY (`scholarship_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `billing_id` (`billing_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `billing`
--
ALTER TABLE `billing`
  MODIFY `billing_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `billing_items`
--
ALTER TABLE `billing_items`
  MODIFY `billing_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fees`
--
ALTER TABLE `fees`
  MODIFY `fee_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `fee_categories`
--
ALTER TABLE `fee_categories`
  MODIFY `category_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `ocr_results`
--
ALTER TABLE `ocr_results`
  MODIFY `ocr_result_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  MODIFY `allocation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_concerns`
--
ALTER TABLE `payment_concerns`
  MODIFY `concern_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payment_settings_audit`
--
ALTER TABLE `payment_settings_audit`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paymongo_transactions`
--
ALTER TABLE `paymongo_transactions`
  MODIFY `paymongo_transaction_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scholarships`
--
ALTER TABLE `scholarships`
  MODIFY `scholarship_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=910;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `billing`
--
ALTER TABLE `billing`
  ADD CONSTRAINT `billing_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `billing_items`
--
ALTER TABLE `billing_items`
  ADD CONSTRAINT `billing_items_ibfk_1` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `billing_items_ibfk_2` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`fee_id`);

--
-- Constraints for table `fees`
--
ALTER TABLE `fees`
  ADD CONSTRAINT `fk_fees_category` FOREIGN KEY (`category_id`) REFERENCES `fee_categories` (`category_id`) ON DELETE SET NULL;

--
-- Constraints for table `ocr_results`
--
ALTER TABLE `ocr_results`
  ADD CONSTRAINT `ocr_results_ibfk_1` FOREIGN KEY (`concern_id`) REFERENCES `payment_concerns` (`concern_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_billing_item` FOREIGN KEY (`billing_item_id`) REFERENCES `billing_items` (`billing_item_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`);

--
-- Constraints for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  ADD CONSTRAINT `fk_allocations_billing_item` FOREIGN KEY (`billing_item_id`) REFERENCES `billing_items` (`billing_item_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_allocations_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON UPDATE CASCADE;

--
-- Constraints for table `payment_concerns`
--
ALTER TABLE `payment_concerns`
  ADD CONSTRAINT `fk_payment_concerns_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `payment_concerns_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL;

--
-- Constraints for table `paymongo_transactions`
--
ALTER TABLE `paymongo_transactions`
  ADD CONSTRAINT `fk_paymongo_transaction_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `scholarships`
--
ALTER TABLE `scholarships`
  ADD CONSTRAINT `fk_scholarships_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `scholarships_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
