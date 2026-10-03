/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.8-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: hf_db_bfim0j6t
-- ------------------------------------------------------
-- Server version	11.8.8-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `bank_statement_rows`
--

DROP TABLE IF EXISTS `bank_statement_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_statement_rows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `statement_id` int(10) unsigned NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_time` time DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'PHP',
  `transaction_type` varchar(50) DEFAULT NULL,
  `status` enum('Unmatched','Matched','Ignored') DEFAULT 'Unmatched',
  `matched_concern_id` int(10) unsigned DEFAULT NULL,
  `matched_payment_id` int(10) unsigned DEFAULT NULL,
  `matched_by` int(10) unsigned DEFAULT NULL,
  `matched_at` timestamp NULL DEFAULT NULL,
  `raw_row_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_row_data`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_row_concern` (`matched_concern_id`),
  UNIQUE KEY `uq_bank_row_payment` (`matched_payment_id`),
  KEY `idx_statement_id` (`statement_id`),
  KEY `idx_reference_number` (`reference_number`),
  CONSTRAINT `fk_bank_row_concern` FOREIGN KEY (`matched_concern_id`) REFERENCES `payment_concerns` (`concern_id`),
  CONSTRAINT `fk_bank_row_payment` FOREIGN KEY (`matched_payment_id`) REFERENCES `payments` (`payment_id`),
  CONSTRAINT `fk_bank_statement_rows_statement` FOREIGN KEY (`statement_id`) REFERENCES `bank_statements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_statement_rows`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `bank_statement_rows` WRITE;
/*!40000 ALTER TABLE `bank_statement_rows` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_statement_rows` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `bank_statements`
--

DROP TABLE IF EXISTS `bank_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_statements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source_bank` varchar(50) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_hash` varchar(64) NOT NULL,
  `uploaded_by` int(10) unsigned NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Processed','Failed') DEFAULT 'Pending',
  `row_count` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_file_hash` (`file_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_statements`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `bank_statements` WRITE;
/*!40000 ALTER TABLE `bank_statements` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_statements` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `billing`
--

DROP TABLE IF EXISTS `billing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  KEY `generated_by` (`generated_by`),
  KEY `idx_billing_status` (`billing_status`),
  CONSTRAINT `billing_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing` WRITE;
/*!40000 ALTER TABLE `billing` DISABLE KEYS */;
INSERT INTO `billing` VALUES
(1,850,784,'Enrollment','2026-2027','1st',15615.00,0.00,2219.00,'Partial','2026-08-20 02:59:18','2026-09-24 11:16:52'),
(3,884,784,'Enrollment','2026-2027','1st',13254.00,0.00,13.99,'Partial','2026-08-24 10:35:47','2026-09-18 12:26:29'),
(4,909,784,'Assessment','2026-2027','1st',14138.00,0.00,27.00,'Partial','2026-08-28 14:27:20','2026-09-12 14:49:04');
/*!40000 ALTER TABLE `billing` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_uca1400_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`hf_gjsh2aqm50`@`%`*/ /*!50003 TRIGGER `before_billing_insert` BEFORE INSERT ON `billing` FOR EACH ROW BEGIN
    
    SET NEW.remaining_balance = GREATEST(0, NEW.total_amount - NEW.discount_amount);
    
    IF NEW.remaining_balance <= 0 THEN
        SET NEW.billing_status = 'Paid';
    ELSE
        SET NEW.billing_status = 'Unpaid';
    END IF;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `billing_items`
--

DROP TABLE IF EXISTS `billing_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_items` (
  `billing_item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `billing_id` int(10) unsigned NOT NULL,
  `fee_id` int(10) unsigned NOT NULL,
  `fee_version_id` bigint(20) unsigned DEFAULT NULL,
  `fee_name` varchar(100) NOT NULL,
  `source_context` varchar(50) NOT NULL,
  `added_by` int(10) unsigned DEFAULT NULL,
  `added_at` datetime DEFAULT current_timestamp(),
  `amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(10,2) NOT NULL,
  `status` enum('Unpaid','Partial','Paid') DEFAULT 'Unpaid',
  PRIMARY KEY (`billing_item_id`),
  UNIQUE KEY `uq_billing_items_billing_fee_version` (`billing_id`,`fee_version_id`),
  KEY `billing_id` (`billing_id`),
  KEY `fee_id` (`fee_id`),
  KEY `idx_billing_items_fee_version` (`fee_version_id`),
  CONSTRAINT `billing_items_ibfk_1` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`) ON DELETE CASCADE,
  CONSTRAINT `billing_items_ibfk_2` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`fee_id`),
  CONSTRAINT `fk_billing_items_fee_version` FOREIGN KEY (`fee_version_id`) REFERENCES `fee_versions` (`fee_version_id`)
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_items`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing_items` WRITE;
/*!40000 ALTER TABLE `billing_items` DISABLE KEYS */;
INSERT INTO `billing_items` VALUES
(1,1,31,NULL,'Registration','Enrollment Assessment',NULL,'2026-08-24 20:59:40',400.00,400.00,0.00,'Paid'),
(2,1,33,NULL,'Library','Enrollment Assessment',NULL,'2026-08-24 20:59:40',650.00,650.00,0.00,'Paid'),
(3,1,34,NULL,'Athletics & Sports Dev. Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',500.00,500.00,0.00,'Paid'),
(4,1,35,NULL,'Cultural Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',400.00,400.00,0.00,'Paid'),
(5,1,36,NULL,'Guidance & Counseling','Enrollment Assessment',NULL,'2026-08-24 20:59:40',400.00,400.00,0.00,'Paid'),
(6,1,37,NULL,'Energy Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',1000.00,1000.00,0.00,'Paid'),
(7,1,38,NULL,'Laboratory Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',600.00,600.00,0.00,'Paid'),
(8,1,39,NULL,'Community & Student Dev. Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',600.00,600.00,0.00,'Paid'),
(9,1,40,NULL,'Insurance','Enrollment Assessment',NULL,'2026-08-24 20:59:40',25.00,25.00,0.00,'Paid'),
(10,1,41,NULL,'Medical and Dental','Enrollment Assessment',NULL,'2026-08-24 20:59:40',400.00,400.00,0.00,'Paid'),
(11,1,42,NULL,'Student Handbook','Enrollment Assessment',NULL,'2026-08-24 20:59:40',250.00,250.00,0.00,'Paid'),
(12,1,43,NULL,'RFID','Enrollment Assessment',NULL,'2026-08-24 20:59:40',500.00,500.00,0.00,'Paid'),
(13,1,45,NULL,'Research Forum 2026','Enrollment Assessment',NULL,'2026-08-24 20:59:40',200.00,200.00,0.00,'Paid'),
(14,3,31,NULL,'Registration','Enrollment Assessment',NULL,'2026-08-24 20:59:40',400.00,400.00,0.00,'Paid'),
(15,3,33,NULL,'Library','Enrollment Assessment',NULL,'2026-08-24 20:59:40',650.00,650.00,0.00,'Paid'),
(16,3,34,NULL,'Athletics & Sports Dev. Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',500.00,500.00,0.00,'Paid'),
(17,3,35,NULL,'Cultural Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',400.00,400.00,0.00,'Paid'),
(18,3,36,NULL,'Guidance & Counseling','Enrollment Assessment',NULL,'2026-08-24 20:59:40',400.00,400.00,0.00,'Paid'),
(19,3,37,NULL,'Energy Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',1000.00,1000.00,0.00,'Paid'),
(20,3,38,NULL,'Laboratory Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',600.00,600.00,0.00,'Paid'),
(21,3,39,NULL,'Community & Student Dev. Fee','Enrollment Assessment',NULL,'2026-08-24 20:59:40',600.00,600.00,0.00,'Paid'),
(22,3,40,NULL,'Insurance','Enrollment Assessment',NULL,'2026-08-24 20:59:40',25.00,25.00,0.00,'Paid'),
(23,3,41,NULL,'Medical and Dental','Enrollment Assessment',NULL,'2026-08-24 20:59:40',400.00,400.00,0.00,'Paid'),
(24,3,42,NULL,'Student Handbook','Enrollment Assessment',NULL,'2026-08-24 20:59:40',250.00,250.00,0.00,'Paid'),
(25,3,43,NULL,'RFID','Enrollment Assessment',NULL,'2026-08-24 20:59:40',500.00,500.00,0.00,'Paid'),
(26,3,45,NULL,'Research Forum 2026','Enrollment Assessment',NULL,'2026-08-24 20:59:40',200.00,200.00,0.00,'Paid'),
(27,1,46,NULL,'Pre Oral Defense','Assessment',784,'2026-08-24 21:04:59',2100.00,2100.00,0.00,'Paid'),
(28,1,47,NULL,'Final Defense','Assessment',784,'2026-08-24 21:04:59',2100.00,2100.00,0.00,'Paid'),
(29,3,46,NULL,'Pre Oral Defense','Assessment',784,'2026-08-26 14:42:41',2100.00,2100.00,0.00,'Paid'),
(30,3,47,NULL,'Final Defense','Assessment',784,'2026-08-26 14:42:41',2100.00,2100.00,0.00,'Paid'),
(31,1,42,NULL,'Student Handbook','Assessment',784,'2026-08-26 20:08:19',250.00,250.00,0.00,'Paid'),
(32,1,48,NULL,'Basketball Share','Assessment',784,'2026-08-26 20:48:04',2500.00,2500.00,0.00,'Paid'),
(33,3,48,NULL,'Basketball Share','Enrollment',784,'2026-08-26 21:08:13',2500.00,2500.00,0.00,'Paid'),
(34,4,46,NULL,'Pre Oral Defense','Assessment',784,'2026-08-28 22:27:20',2100.00,2100.00,0.00,'Paid'),
(35,4,49,NULL,'adadadadada','Assessment',784,'2026-08-29 11:57:55',1000.00,1000.00,0.00,'Paid'),
(36,4,50,NULL,'test','Assessment',784,'2026-08-29 11:57:55',10.00,10.00,0.00,'Paid'),
(37,1,51,NULL,'test','Enrollment',784,'2026-08-29 12:38:52',10.00,10.00,0.00,'Paid'),
(38,3,51,NULL,'test','Assessment',784,'2026-08-29 12:40:13',10.00,10.00,0.00,'Paid'),
(39,3,52,NULL,'Stage Play Rizal','Assessment',784,'2026-09-04 21:00:06',500.00,500.00,0.00,'Paid'),
(40,3,53,NULL,'test','Assessment',784,'2026-09-09 14:13:44',1.00,1.00,0.00,'Paid'),
(41,1,52,NULL,'Stage Play Rizal','Assessment',784,'2026-09-10 12:16:13',500.00,500.00,0.00,'Paid'),
(42,1,53,NULL,'test','Assessment',784,'2026-09-10 12:16:13',1.00,1.00,0.00,'Paid'),
(43,1,54,NULL,'test','Assessment',784,'2026-09-10 12:16:13',1.00,1.00,0.00,'Paid'),
(44,4,31,NULL,'Registration','Enrollment Assessment',784,'2026-09-11 14:33:57',400.00,400.00,0.00,'Paid'),
(45,4,33,NULL,'Library','Enrollment Assessment',784,'2026-09-11 14:33:57',650.00,650.00,0.00,'Paid'),
(46,4,34,NULL,'Athletics & Sports Dev. Fee','Enrollment Assessment',784,'2026-09-11 14:33:57',500.00,500.00,0.00,'Paid'),
(47,4,35,NULL,'Cultural Fee','Enrollment Assessment',784,'2026-09-11 14:33:57',400.00,400.00,0.00,'Paid'),
(48,4,36,NULL,'Guidance & Counseling','Enrollment Assessment',784,'2026-09-11 14:33:57',400.00,400.00,0.00,'Paid'),
(49,4,37,NULL,'Energy Fee','Enrollment Assessment',784,'2026-09-11 14:33:57',1000.00,1000.00,0.00,'Paid'),
(50,4,38,NULL,'Laboratory Fee','Enrollment Assessment',784,'2026-09-11 14:33:57',600.00,600.00,0.00,'Paid'),
(51,4,39,NULL,'Community & Student Dev. Fee','Enrollment Assessment',784,'2026-09-11 14:33:57',600.00,600.00,0.00,'Paid'),
(52,4,40,NULL,'Insurance','Enrollment Assessment',784,'2026-09-11 14:33:57',25.00,25.00,0.00,'Paid'),
(53,4,41,NULL,'Medical and Dental','Enrollment Assessment',784,'2026-09-11 14:33:57',400.00,400.00,0.00,'Paid'),
(54,4,42,NULL,'Student Handbook','Enrollment Assessment',784,'2026-09-11 14:33:57',250.00,250.00,0.00,'Paid'),
(55,4,43,NULL,'RFID','Enrollment Assessment',784,'2026-09-11 14:33:57',500.00,500.00,0.00,'Paid'),
(56,4,45,NULL,'Research Forum 2026','Enrollment Assessment',784,'2026-09-11 14:33:57',200.00,200.00,0.00,'Paid'),
(57,4,47,NULL,'Final Defense','Enrollment Assessment',784,'2026-09-11 14:33:57',2100.00,2100.00,0.00,'Paid'),
(58,4,48,NULL,'Basketball Share','Enrollment Assessment',784,'2026-09-11 14:33:57',2500.00,2500.00,0.00,'Paid'),
(59,4,52,NULL,'Stage Play Rizal','Enrollment Assessment',784,'2026-09-11 14:33:57',500.00,475.00,25.00,'Partial'),
(60,4,53,NULL,'test','Enrollment Assessment',784,'2026-09-11 14:33:57',1.00,0.00,1.00,'Unpaid'),
(61,4,54,NULL,'test','Enrollment Assessment',784,'2026-09-11 14:33:57',1.00,0.00,1.00,'Unpaid'),
(62,1,55,NULL,'fafafaf','Assessment',784,'2026-09-12 10:50:01',1.00,1.00,0.00,'Paid'),
(63,3,54,NULL,'test','Assessment',784,'2026-09-12 10:50:21',1.00,1.00,0.00,'Paid'),
(64,3,55,NULL,'fafafaf','Assessment',784,'2026-09-12 10:50:21',1.00,1.00,0.00,'Paid'),
(65,4,55,NULL,'fafafaf','Assessment',784,'2026-09-12 10:50:46',1.00,1.00,0.00,'Paid'),
(66,1,56,NULL,'ASADAD','Assessment',784,'2026-09-12 22:14:54',1.00,1.00,0.00,'Paid'),
(67,1,57,NULL,'s230115570','Assessment',784,'2026-09-16 18:51:26',1.00,1.00,0.00,'Paid'),
(68,1,58,NULL,'s230115570','Assessment',784,'2026-09-16 18:51:26',1.00,0.00,1.00,'Unpaid'),
(69,1,59,NULL,'test101','Assessment',784,'2026-09-16 18:51:26',1.00,1.00,0.00,'Paid'),
(70,3,49,NULL,'Milo','Enrollment Assessment',784,'2026-09-17 03:19:35',100.00,100.00,0.00,'Paid'),
(71,3,50,NULL,'test','Enrollment Assessment',784,'2026-09-17 03:19:35',10.00,1.01,8.99,'Partial'),
(72,3,56,NULL,'ASADAD','Enrollment Assessment',784,'2026-09-17 03:19:35',1.00,0.00,1.00,'Unpaid'),
(73,3,57,NULL,'s230115570','Enrollment Assessment',784,'2026-09-17 03:19:36',1.00,0.00,1.00,'Unpaid'),
(74,3,58,NULL,'s230115570','Enrollment Assessment',784,'2026-09-17 03:19:36',1.00,0.00,1.00,'Unpaid'),
(75,3,59,NULL,'test101','Enrollment Assessment',784,'2026-09-17 03:19:36',1.00,0.00,1.00,'Unpaid'),
(76,1,49,NULL,'Milo','Enrollment Assessment',784,'2026-09-18 10:10:31',100.00,0.00,100.00,'Unpaid'),
(77,1,50,NULL,'test','Enrollment Assessment',784,'2026-09-18 10:10:31',10.00,0.00,10.00,'Unpaid'),
(78,1,61,NULL,'s230115569','Enrollment Assessment',784,'2026-09-18 10:10:31',1.00,0.00,1.00,'Unpaid'),
(79,1,62,NULL,'s230115570','Enrollment Assessment',784,'2026-09-18 10:10:31',1.00,0.00,1.00,'Unpaid'),
(80,1,63,NULL,'s230115569','Enrollment Assessment',784,'2026-09-18 10:10:31',1.00,0.00,1.00,'Unpaid'),
(81,1,64,NULL,'Insurance','Enrollment Assessment',784,'2026-09-18 10:10:31',2100.00,0.00,2100.00,'Unpaid'),
(82,1,65,NULL,'s230115570','Enrollment Assessment',784,'2026-09-18 10:10:31',1.00,0.00,1.00,'Unpaid'),
(83,1,66,NULL,'s230115570','Enrollment Assessment',784,'2026-09-18 10:10:31',1.00,0.00,1.00,'Unpaid'),
(84,1,67,NULL,'s230115570','Enrollment Assessment',784,'2026-09-18 10:10:31',1.00,0.00,1.00,'Unpaid'),
(85,1,68,NULL,'s230115570','Enrollment Assessment',784,'2026-09-18 10:10:31',1.00,0.00,1.00,'Unpaid'),
(86,1,69,NULL,'chair','Enrollment Assessment',784,'2026-09-18 10:10:32',1.00,0.00,1.00,'Unpaid'),
(87,3,74,NULL,'sample','Assessment',784,'2026-09-18 18:22:49',1.00,1.00,0.00,'Paid'),
(88,3,76,NULL,'Webinar','Assessment',784,'2026-09-18 20:26:29',1.00,0.00,1.00,'Unpaid'),
(89,1,71,NULL,'exam','Assessment',958,'2026-09-19 09:32:27',1.00,1.00,0.00,'Paid'),
(90,1,74,NULL,'sample','Assessment',958,'2026-09-19 09:32:27',1.00,1.00,0.00,'Paid'),
(91,1,75,NULL,'Webinar','Assessment',958,'2026-09-19 09:32:27',1.00,1.00,0.00,'Paid'),
(92,1,77,NULL,'Test','Assessment',958,'2026-09-19 09:32:27',1.00,1.00,0.00,'Paid'),
(93,1,79,NULL,'Graduation Form','Assessment',958,'2026-09-19 09:32:27',1.00,1.00,0.00,'Paid');
/*!40000 ALTER TABLE `billing_items` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_uca1400_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`hf_gjsh2aqm50`@`%`*/ /*!50003 TRIGGER `before_billing_items_insert` BEFORE INSERT ON `billing_items` FOR EACH ROW BEGIN
    
    IF NEW.paid_amount > NEW.amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid amount cannot exceed billing item amount.';
    END IF;

    
    SET NEW.remaining_amount = NEW.amount - NEW.paid_amount;

    
    IF NEW.remaining_amount <= 0 THEN
        SET NEW.status = 'Paid';
    ELSEIF NEW.paid_amount > 0 THEN
        SET NEW.status = 'Partial';
    ELSE
        SET NEW.status = 'Unpaid';
    END IF;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_uca1400_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`hf_gjsh2aqm50`@`%`*/ /*!50003 TRIGGER `before_billing_items_update` BEFORE UPDATE ON `billing_items` FOR EACH ROW BEGIN
    
    IF NEW.paid_amount > NEW.amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid amount cannot exceed billing item amount.';
    END IF;

    
    SET NEW.remaining_amount = NEW.amount - NEW.paid_amount;

    
    IF NEW.remaining_amount <= 0 THEN
        SET NEW.status = 'Paid';
    ELSEIF NEW.paid_amount > 0 THEN
        SET NEW.status = 'Partial';
    ELSE
        SET NEW.status = 'Unpaid';
    END IF;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `billing_notification_outbox`
--

DROP TABLE IF EXISTS `billing_notification_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_notification_outbox` (
  `outbox_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `billing_id` int(10) unsigned NOT NULL,
  `event_key` varchar(120) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `new_assessment_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `term_remaining_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `delivery_status` enum('Pending','Processing','PendingAccountLink','Sent','Failed') NOT NULL DEFAULT 'Pending',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`outbox_id`),
  UNIQUE KEY `uq_billing_notification_outbox_event` (`event_key`),
  UNIQUE KEY `uq_billing_notification_outbox_run_student` (`run_id`,`student_id`),
  KEY `idx_billing_notification_outbox_delivery` (`delivery_status`,`outbox_id`),
  KEY `idx_billing_notification_outbox_run_status` (`run_id`,`delivery_status`,`outbox_id`),
  KEY `idx_billing_notification_outbox_student` (`student_id`),
  KEY `fk_billing_notification_outbox_billing` (`billing_id`),
  CONSTRAINT `fk_billing_notification_outbox_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`),
  CONSTRAINT `fk_billing_notification_outbox_run` FOREIGN KEY (`run_id`) REFERENCES `billing_runs` (`run_id`),
  CONSTRAINT `fk_billing_notification_outbox_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_notification_outbox`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing_notification_outbox` WRITE;
/*!40000 ALTER TABLE `billing_notification_outbox` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_notification_outbox` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `billing_run_assignments`
--

DROP TABLE IF EXISTS `billing_run_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_run_assignments` (
  `assignment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `billing_id` int(10) unsigned DEFAULT NULL,
  `status` enum('Pending','Processing','Added','PartiallyAdded','AlreadyAssessed','NoApplicableFees','ReviewRequired','Failed') NOT NULL DEFAULT 'Pending',
  `added_item_count` int(10) unsigned NOT NULL DEFAULT 0,
  `added_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `duplicate_count` int(10) unsigned NOT NULL DEFAULT 0,
  `excluded_count` int(10) unsigned NOT NULL DEFAULT 0,
  `exception_code` varchar(100) DEFAULT NULL,
  `exception_message` varchar(500) DEFAULT NULL,
  `notification_state` enum('NotRequired','Pending','PendingAccountLink','Sent','Failed') NOT NULL DEFAULT 'NotRequired',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uq_billing_run_assignment_student` (`run_id`,`student_id`),
  KEY `idx_billing_run_assignments_student` (`student_id`),
  KEY `idx_billing_run_assignments_run_status` (`run_id`,`status`,`assignment_id`),
  KEY `idx_billing_run_assignments_billing` (`billing_id`),
  CONSTRAINT `fk_billing_run_assignments_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`),
  CONSTRAINT `fk_billing_run_assignments_run` FOREIGN KEY (`run_id`) REFERENCES `billing_runs` (`run_id`),
  CONSTRAINT `fk_billing_run_assignments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_run_assignments`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing_run_assignments` WRITE;
/*!40000 ALTER TABLE `billing_run_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_run_assignments` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `billing_run_fee_versions`
--

DROP TABLE IF EXISTS `billing_run_fee_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_run_fee_versions` (
  `run_fee_version_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `fee_version_id` bigint(20) unsigned NOT NULL,
  `behavior` enum('Standard','One-Time','Optional','Manual') NOT NULL,
  `is_required` tinyint(1) NOT NULL,
  `selection_state` enum('Selected','Excluded') NOT NULL,
  `preview_name_snapshot` varchar(100) NOT NULL,
  `preview_amount_snapshot` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`run_fee_version_id`),
  UNIQUE KEY `uq_billing_run_fee_version` (`run_id`,`fee_version_id`),
  KEY `idx_billing_run_fee_versions_version` (`fee_version_id`),
  CONSTRAINT `fk_billing_run_fee_versions_fee_version` FOREIGN KEY (`fee_version_id`) REFERENCES `fee_versions` (`fee_version_id`),
  CONSTRAINT `fk_billing_run_fee_versions_run` FOREIGN KEY (`run_id`) REFERENCES `billing_runs` (`run_id`),
  CONSTRAINT `chk_billing_run_fee_version_standard_required_selected` CHECK (`behavior` <> 'Standard' or `is_required` = 0 or `selection_state` = 'Selected')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_run_fee_versions`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing_run_fee_versions` WRITE;
/*!40000 ALTER TABLE `billing_run_fee_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_run_fee_versions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `billing_runs`
--

DROP TABLE IF EXISTS `billing_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_runs` (
  `run_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `status` enum('Draft','Approved','Running','Completed','CompletedWithExceptions','Failed') NOT NULL DEFAULT 'Draft',
  `initiated_by` int(10) unsigned NOT NULL,
  `initiated_by_name_snapshot` varchar(255) DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_by_name_snapshot` varchar(255) DEFAULT NULL,
  `projected_student_count` int(10) unsigned NOT NULL DEFAULT 0,
  `projected_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `actual_added_student_count` int(10) unsigned NOT NULL DEFAULT 0,
  `actual_partially_added_student_count` int(10) unsigned NOT NULL DEFAULT 0,
  `actual_already_assessed_student_count` int(10) unsigned NOT NULL DEFAULT 0,
  `actual_no_applicable_fee_student_count` int(10) unsigned NOT NULL DEFAULT 0,
  `actual_review_required_student_count` int(10) unsigned NOT NULL DEFAULT 0,
  `actual_failed_student_count` int(10) unsigned NOT NULL DEFAULT 0,
  `actual_added_item_count` int(10) unsigned NOT NULL DEFAULT 0,
  `actual_added_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `processing_cursor` int(10) unsigned NOT NULL DEFAULT 0,
  `runner_token` char(32) DEFAULT NULL,
  `lease_expires_at` timestamp NULL DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`run_id`),
  KEY `idx_billing_runs_status` (`status`,`run_id`),
  KEY `idx_billing_runs_scope` (`academic_year`,`semester`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_runs`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing_runs` WRITE;
/*!40000 ALTER TABLE `billing_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_runs` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `cash_sale_items`
--

DROP TABLE IF EXISTS `cash_sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_sale_items` (
  `cash_sale_line_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cash_sale_id` bigint(20) unsigned NOT NULL,
  `sale_item_id` int(10) unsigned DEFAULT NULL,
  `sale_category_id` int(10) unsigned DEFAULT NULL,
  `category_name_snapshot` varchar(100) NOT NULL,
  `item_name_snapshot` varchar(150) NOT NULL,
  `unit_price_snapshot` decimal(10,2) NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `line_total` decimal(10,2) NOT NULL,
  PRIMARY KEY (`cash_sale_line_id`),
  KEY `idx_cash_sale_line_sale` (`cash_sale_id`),
  CONSTRAINT `fk_cash_sale_line_sale` FOREIGN KEY (`cash_sale_id`) REFERENCES `cash_sales` (`cash_sale_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_sale_items`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `cash_sale_items` WRITE;
/*!40000 ALTER TABLE `cash_sale_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_sale_items` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `cash_sales`
--

DROP TABLE IF EXISTS `cash_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_sales` (
  `cash_sale_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `cashier_id` int(10) unsigned NOT NULL,
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
  KEY `idx_cash_sale_cashier_date` (`cashier_id`,`sold_at`),
  KEY `idx_cash_sale_student_date` (`student_id`,`sold_at`),
  CONSTRAINT `fk_cash_sale_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_sales`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `cash_sales` WRITE;
/*!40000 ALTER TABLE `cash_sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_sales` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `cashier_receipt_print_events`
--

DROP TABLE IF EXISTS `cashier_receipt_print_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cashier_receipt_print_events` (
  `receipt_print_event_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_type` enum('payment','cash_sale') NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL,
  `rendered_by` int(10) unsigned NOT NULL,
  `rendered_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`receipt_print_event_id`),
  KEY `idx_receipt_print_record` (`record_type`,`record_id`),
  KEY `idx_receipt_print_user` (`rendered_by`,`rendered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashier_receipt_print_events`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `cashier_receipt_print_events` WRITE;
/*!40000 ALTER TABLE `cashier_receipt_print_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `cashier_receipt_print_events` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fee_applicability`
--

DROP TABLE IF EXISTS `fee_applicability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_applicability` (
  `fee_applicability_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fee_version_id` bigint(20) unsigned NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `applies_to_all_courses` tinyint(1) NOT NULL,
  `applies_to_all_year_levels` tinyint(1) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `normalized_course_scope` varchar(100) GENERATED ALWAYS AS (case when `applies_to_all_courses` = 1 then '__ALL__' else trim(`course`) end) STORED,
  `normalized_year_scope` varchar(20) GENERATED ALWAYS AS (case when `applies_to_all_year_levels` = 1 then '__ALL__' else trim(`year_level`) end) STORED,
  PRIMARY KEY (`fee_applicability_id`),
  UNIQUE KEY `uq_fee_applicability_scope` (`fee_version_id`,`normalized_course_scope`,`normalized_year_scope`),
  KEY `idx_fee_applicability_version` (`fee_version_id`),
  CONSTRAINT `fk_fee_applicability_version` FOREIGN KEY (`fee_version_id`) REFERENCES `fee_versions` (`fee_version_id`),
  CONSTRAINT `chk_fee_applicability_course_flag` CHECK (`applies_to_all_courses` in (0,1)),
  CONSTRAINT `chk_fee_applicability_year_flag` CHECK (`applies_to_all_year_levels` in (0,1)),
  CONSTRAINT `chk_fee_applicability_course_scope` CHECK (`applies_to_all_courses` = 1 and `course` is null or `applies_to_all_courses` = 0 and `course` is not null and char_length(trim(`course`)) > 0),
  CONSTRAINT `chk_fee_applicability_year_scope` CHECK (`applies_to_all_year_levels` = 1 and `year_level` is null or `applies_to_all_year_levels` = 0 and `year_level` is not null and char_length(trim(`year_level`)) > 0)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_applicability`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fee_applicability` WRITE;
/*!40000 ALTER TABLE `fee_applicability` DISABLE KEYS */;
INSERT INTO `fee_applicability` VALUES
(1,1,NULL,'4',1,0,'2026-09-27 06:45:57','__ALL__','4'),
(2,2,NULL,NULL,1,1,'2026-09-28 02:46:32','__ALL__','__ALL__');
/*!40000 ALTER TABLE `fee_applicability` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fee_billing_assignments`
--

DROP TABLE IF EXISTS `fee_billing_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_billing_assignments` (
  `assignment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint(20) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `billing_item_id` int(10) unsigned DEFAULT NULL,
  `outcome` enum('Added','Existing','Skipped','Failed') NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uq_campaign_student` (`campaign_id`,`student_id`),
  KEY `idx_assignment_student` (`student_id`),
  KEY `fk_assignment_item` (`billing_item_id`),
  CONSTRAINT `fk_assignment_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `fee_billing_campaigns` (`campaign_id`),
  CONSTRAINT `fk_assignment_item` FOREIGN KEY (`billing_item_id`) REFERENCES `billing_items` (`billing_item_id`),
  CONSTRAINT `fk_assignment_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_billing_assignments`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fee_billing_assignments` WRITE;
/*!40000 ALTER TABLE `fee_billing_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_billing_assignments` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fee_billing_campaign_items`
--

DROP TABLE IF EXISTS `fee_billing_campaign_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_billing_campaign_items` (
  `campaign_item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint(20) unsigned NOT NULL,
  `fee_id` int(10) unsigned NOT NULL,
  `fee_name_snapshot` varchar(100) NOT NULL,
  `amount_snapshot` decimal(10,2) NOT NULL,
  PRIMARY KEY (`campaign_item_id`),
  UNIQUE KEY `uq_campaign_fee` (`campaign_id`,`fee_id`),
  KEY `fk_campaign_item_fee` (`fee_id`),
  CONSTRAINT `fk_campaign_item_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `fee_billing_campaigns` (`campaign_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_campaign_item_fee` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`fee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_billing_campaign_items`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fee_billing_campaign_items` WRITE;
/*!40000 ALTER TABLE `fee_billing_campaign_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_billing_campaign_items` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fee_billing_campaigns`
--

DROP TABLE IF EXISTS `fee_billing_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_billing_campaigns` (
  `campaign_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fee_id` int(10) unsigned DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `category_name_snapshot` varchar(100) DEFAULT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `fee_name_snapshot` varchar(100) NOT NULL,
  `amount_snapshot` decimal(10,2) NOT NULL,
  `status` enum('Submitted','Returned','Running','Completed') NOT NULL DEFAULT 'Submitted',
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `submitted_by` int(10) unsigned NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `runner_token` char(32) DEFAULT NULL,
  `lease_expires_at` timestamp NULL DEFAULT NULL,
  `cursor_student_id` int(10) unsigned NOT NULL DEFAULT 0,
  `added_count` int(10) unsigned NOT NULL DEFAULT 0,
  `existing_count` int(10) unsigned NOT NULL DEFAULT 0,
  `skipped_count` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`campaign_id`),
  UNIQUE KEY `uq_fee_term` (`fee_id`,`academic_year`,`semester`),
  UNIQUE KEY `uq_category_term_year_level` (`category_id`,`academic_year`,`semester`,`year_level`),
  KEY `idx_campaign_status` (`status`),
  KEY `idx_campaign_category` (`category_id`),
  CONSTRAINT `fk_campaign_category` FOREIGN KEY (`category_id`) REFERENCES `fee_categories` (`category_id`),
  CONSTRAINT `fk_campaign_fee` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`fee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_billing_campaigns`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fee_billing_campaigns` WRITE;
/*!40000 ALTER TABLE `fee_billing_campaigns` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_billing_campaigns` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fee_categories`
--

DROP TABLE IF EXISTS `fee_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_categories` (
  `category_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `priority_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_categories`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fee_categories` WRITE;
/*!40000 ALTER TABLE `fee_categories` DISABLE KEYS */;
INSERT INTO `fee_categories` VALUES
(1,'Tuition',6,'Active'),
(2,'Miscellaneous',1,'Active'),
(3,'Laboratory & Computer',3,'Active'),
(4,'Student Council & Organization',4,'Active'),
(5,'Supplementary Fees',2,'Active'),
(6,'Other',5,'Active');
/*!40000 ALTER TABLE `fee_categories` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fee_groups`
--

DROP TABLE IF EXISTS `fee_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_groups` (
  `fee_group_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_code` varchar(40) NOT NULL,
  `group_name` varchar(100) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fee_group_id`),
  UNIQUE KEY `uq_fee_groups_code` (`group_code`),
  UNIQUE KEY `uq_fee_groups_name` (`group_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_groups`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fee_groups` WRITE;
/*!40000 ALTER TABLE `fee_groups` DISABLE KEYS */;
INSERT INTO `fee_groups` VALUES
(1,'ACADEMIC','Academic Fees','Active',10,'2026-09-26 07:51:49','2026-09-26 07:51:49'),
(2,'NON_ACADEMIC','Non-Academic Fees','Active',20,'2026-09-26 07:51:49','2026-09-26 07:51:49');
/*!40000 ALTER TABLE `fee_groups` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fee_types`
--

DROP TABLE IF EXISTS `fee_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_types` (
  `fee_type_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fee_group_id` int(10) unsigned NOT NULL,
  `type_code` varchar(60) NOT NULL,
  `type_name` varchar(120) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fee_type_id`),
  UNIQUE KEY `uq_fee_types_code` (`type_code`),
  UNIQUE KEY `uq_fee_types_group_name` (`fee_group_id`,`type_name`),
  KEY `idx_fee_types_group` (`fee_group_id`,`status`,`sort_order`),
  CONSTRAINT `fk_fee_types_group` FOREIGN KEY (`fee_group_id`) REFERENCES `fee_groups` (`fee_group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_types`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fee_types` WRITE;
/*!40000 ALTER TABLE `fee_types` DISABLE KEYS */;
INSERT INTO `fee_types` VALUES
(1,1,'TUITION','Tuition Fees','Active',10,'2026-09-26 07:51:49','2026-09-26 07:51:49'),
(2,1,'LABORATORY','Laboratory Fees','Active',20,'2026-09-26 07:51:49','2026-09-26 07:51:49'),
(3,1,'SUPPLEMENTARY','Supplementary Fees','Active',30,'2026-09-26 07:51:49','2026-09-26 07:51:49'),
(4,1,'CURRICULUM_INSTRUCTIONAL','Curriculum and Instructional Systems Fees','Active',40,'2026-09-26 07:51:49','2026-09-26 07:51:49'),
(5,1,'CULINARY_HOSPITALITY','Culinary & Hospitality Workshop Fee','Active',50,'2026-09-26 07:51:49','2026-09-26 07:51:49'),
(6,2,'UNIFORM_GEAR','Uniform and Gear Fees','Active',10,'2026-09-26 07:51:49','2026-09-26 07:51:49'),
(7,2,'GRADUATION_REGALIA','Graduation Regalia Fees','Active',20,'2026-09-26 07:51:49','2026-09-26 07:51:49'),
(8,2,'MISCELLANEOUS','Miscellaneous Fees','Active',30,'2026-09-26 07:51:49','2026-09-26 07:51:49');
/*!40000 ALTER TABLE `fee_types` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fee_versions`
--

DROP TABLE IF EXISTS `fee_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_versions` (
  `fee_version_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fee_id` int(10) unsigned NOT NULL,
  `version_no` int(10) unsigned NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `behavior` enum('Standard','One-Time','Optional','Manual') NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `effective_status` enum('Draft','Active','Archived') NOT NULL DEFAULT 'Draft',
  `description` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `activated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `active_fee_id` int(10) unsigned GENERATED ALWAYS AS (case when `effective_status` = 'Active' then `fee_id` else NULL end) STORED,
  PRIMARY KEY (`fee_version_id`),
  UNIQUE KEY `uq_fee_versions_number` (`fee_id`,`version_no`),
  UNIQUE KEY `uq_fee_versions_term` (`fee_id`,`academic_year`,`semester`),
  UNIQUE KEY `uq_fee_versions_active_scope` (`active_fee_id`,`academic_year`,`semester`),
  KEY `idx_fee_versions_scope` (`fee_id`,`academic_year`,`semester`,`effective_status`),
  CONSTRAINT `fk_fee_versions_fee` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`fee_id`),
  CONSTRAINT `chk_fee_versions_amount` CHECK (`amount` >= 0),
  CONSTRAINT `chk_fee_versions_required` CHECK (`is_required` in (0,1))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_versions`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fee_versions` WRITE;
/*!40000 ALTER TABLE `fee_versions` DISABLE KEYS */;
INSERT INTO `fee_versions` VALUES
(1,79,1,'2026-2027','1st',100.00,'Standard',1,'Active',NULL,958,'2026-09-27 06:45:57',958,'2026-09-27 06:49:28','2026-09-27 14:49:28',NULL,79),
(2,81,1,'2026-2027','1st',500.00,'Standard',1,'Draft',NULL,958,'2026-09-28 02:46:32',958,'2026-09-28 02:46:32',NULL,NULL,NULL);
/*!40000 ALTER TABLE `fee_versions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fees`
--

DROP TABLE IF EXISTS `fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fees` (
  `fee_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fee_code` varchar(60) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `fee_type_id` int(10) unsigned DEFAULT NULL,
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
  KEY `idx_fees_fee_type` (`fee_type_id`),
  CONSTRAINT `fk_fees_category` FOREIGN KEY (`category_id`) REFERENCES `fee_categories` (`category_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fees_fee_type` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`fee_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fees`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fees` WRITE;
/*!40000 ALTER TABLE `fees` DISABLE KEYS */;
INSERT INTO `fees` VALUES
(31,NULL,2,NULL,'Registration',400.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(33,NULL,2,NULL,'Library',650.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(34,NULL,5,NULL,'Athletics & Sports Dev. Fee',100.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-09-16 19:00:57'),
(35,NULL,2,NULL,'Cultural Fee',400.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(36,NULL,2,NULL,'Guidance & Counseling',400.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(37,NULL,2,NULL,'Energy Fee',1000.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(38,NULL,2,NULL,'Laboratory Fee',600.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(39,NULL,2,NULL,'Community & Student Dev. Fee',600.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-09-16 19:02:28'),
(40,NULL,2,NULL,'Insurance',25.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(41,NULL,2,NULL,'Medical and Dental',400.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(42,NULL,5,NULL,'Student Handbook',250.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(43,NULL,5,NULL,'RFID',500.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(45,NULL,5,NULL,'Research Forum 2026',200.00,1,'Active',NULL,NULL,'2026-08-12 12:49:45','2026-08-12 12:49:45'),
(46,NULL,5,NULL,'Pre Oral Defense',2100.00,1,'Active',NULL,NULL,'2026-08-24 11:48:22','2026-08-24 11:48:22'),
(47,NULL,5,NULL,'Final Defense',2100.00,1,'Active',NULL,NULL,'2026-08-24 11:48:49','2026-08-24 11:48:49'),
(48,NULL,6,NULL,'Basketball Share',2500.00,1,'Inactive',NULL,NULL,'2026-08-26 12:46:59','2026-09-25 03:06:45'),
(49,NULL,2,NULL,'Milo',100.00,1,'Active',NULL,NULL,'2026-08-29 03:41:58','2026-09-16 19:01:45'),
(50,NULL,2,NULL,'test',10.00,1,'Inactive',NULL,NULL,'2026-08-29 03:51:58','2026-09-18 18:22:16'),
(51,NULL,2,NULL,'test',10.00,1,'Inactive',NULL,NULL,'2026-08-29 04:37:59','2026-09-18 18:22:42'),
(52,NULL,6,NULL,'Stage Play Rizal',500.00,1,'Inactive',NULL,NULL,'2026-09-04 12:59:24','2026-09-25 03:06:45'),
(53,NULL,6,NULL,'test',1.00,1,'Inactive',NULL,NULL,'2026-09-09 14:12:35','2026-09-25 03:06:45'),
(54,NULL,6,NULL,'test',1.00,1,'Inactive',NULL,NULL,'2026-09-10 12:05:41','2026-09-25 03:06:45'),
(55,NULL,6,NULL,'fafafaf',1.00,1,'Inactive',NULL,NULL,'2026-09-12 10:49:08','2026-09-25 03:06:45'),
(56,NULL,6,NULL,'ASADAD',1.00,1,'Inactive',NULL,NULL,'2026-09-12 14:13:57','2026-09-19 01:22:03'),
(57,NULL,2,NULL,'s230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-12 14:14:57','2026-09-18 18:16:32'),
(58,NULL,2,NULL,'s230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-12 14:15:00','2026-09-18 18:16:58'),
(59,NULL,6,NULL,'test101',1.00,1,'Inactive',NULL,NULL,'2026-09-16 10:48:03','2026-09-25 03:06:45'),
(60,NULL,2,NULL,'s230115570',1.00,0,'Inactive',NULL,NULL,'2026-09-17 14:51:52','2026-09-18 18:19:24'),
(61,NULL,2,NULL,'s230115569',1.00,1,'Inactive',NULL,NULL,'2026-09-17 15:53:36','2026-09-18 18:05:16'),
(62,NULL,2,NULL,'s230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-17 22:29:31','2026-09-18 18:19:41'),
(63,NULL,2,NULL,'s230115569',1.00,1,'Inactive',NULL,NULL,'2026-09-17 23:10:24','2026-09-18 18:16:22'),
(64,NULL,1,NULL,'Insurance',2100.00,1,'Inactive',NULL,NULL,'2026-09-18 01:44:49','2026-09-18 22:25:06'),
(65,NULL,2,NULL,'s230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-18 01:50:34','2026-09-18 18:20:12'),
(66,NULL,2,NULL,'s230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-18 01:54:24','2026-09-18 18:20:25'),
(67,NULL,2,NULL,'s230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-18 01:56:43','2026-09-18 18:20:36'),
(68,NULL,2,NULL,'s230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-18 01:58:55','2026-09-18 18:21:00'),
(69,NULL,2,NULL,'chair',1.00,1,'Inactive',NULL,NULL,'2026-09-18 02:04:06','2026-09-18 02:21:20'),
(70,NULL,2,NULL,'230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-18 02:16:29','2026-09-18 18:13:22'),
(71,NULL,6,NULL,'exam',1.00,1,'Inactive',NULL,NULL,'2026-09-18 02:23:08','2026-09-25 03:06:45'),
(72,NULL,2,NULL,'exam',1.00,1,'Inactive',NULL,NULL,'2026-09-18 02:26:39','2026-09-18 18:23:40'),
(73,NULL,2,NULL,'s230115570',1.00,1,'Inactive',NULL,NULL,'2026-09-18 10:02:49','2026-09-18 18:22:02'),
(74,NULL,6,NULL,'sample',1.00,1,'Inactive',NULL,NULL,'2026-09-18 10:21:42','2026-09-25 03:06:45'),
(75,NULL,6,NULL,'Webinar',1.00,1,'Inactive',NULL,NULL,'2026-09-18 12:24:24','2026-09-25 03:06:45'),
(76,NULL,6,NULL,'Webinar',1.00,1,'Inactive',NULL,NULL,'2026-09-18 12:24:28','2026-09-19 01:29:00'),
(77,NULL,6,NULL,'Test',1.00,1,'Inactive',NULL,NULL,'2026-09-18 22:34:00','2026-09-25 03:06:45'),
(78,NULL,2,NULL,'s230115570',120.00,1,'Inactive',NULL,NULL,'2026-09-18 23:33:43','2026-09-19 01:24:59'),
(79,'GRAD_FORM',6,7,'Graduation Form',1.00,1,'Inactive','Active',NULL,'2026-09-19 01:29:49','2026-09-27 06:45:57'),
(80,NULL,6,NULL,'request form',1.00,1,'Inactive',NULL,NULL,'2026-09-19 03:34:35','2026-09-25 03:06:45'),
(81,'COMPUTER_LAB',NULL,2,'Computer Laboratory',0.00,0,'Inactive','Active',NULL,'2026-09-28 02:44:46','2026-09-28 02:44:46');
/*!40000 ALTER TABLE `fees` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `managed_assessment_headers`
--

DROP TABLE IF EXISTS `managed_assessment_headers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `managed_assessment_headers` (
  `managed_header_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','Summer') NOT NULL,
  `billing_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`managed_header_id`),
  UNIQUE KEY `uq_managed_assessment_student_term` (`student_id`,`academic_year`,`semester`),
  UNIQUE KEY `uq_managed_assessment_billing` (`billing_id`),
  CONSTRAINT `fk_managed_assessment_header_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`),
  CONSTRAINT `fk_managed_assessment_header_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `managed_assessment_headers`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `managed_assessment_headers` WRITE;
/*!40000 ALTER TABLE `managed_assessment_headers` DISABLE KEYS */;
/*!40000 ALTER TABLE `managed_assessment_headers` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `ocr_results`
--

DROP TABLE IF EXISTS `ocr_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ocr_results` (
  `ocr_result_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `concern_id` int(10) unsigned NOT NULL,
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
  `scanned_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`ocr_result_id`),
  UNIQUE KEY `idx_concern_unique` (`concern_id`),
  KEY `concern_id` (`concern_id`),
  CONSTRAINT `ocr_results_ibfk_1` FOREIGN KEY (`concern_id`) REFERENCES `payment_concerns` (`concern_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ocr_results`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `ocr_results` WRITE;
/*!40000 ALTER TABLE `ocr_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `ocr_results` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `official_receipt_sequences`
--

DROP TABLE IF EXISTS `official_receipt_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `official_receipt_sequences` (
  `sequence_key` varchar(32) NOT NULL,
  `next_number` bigint(20) unsigned NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sequence_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `official_receipt_sequences`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `official_receipt_sequences` WRITE;
/*!40000 ALTER TABLE `official_receipt_sequences` DISABLE KEYS */;
INSERT INTO `official_receipt_sequences` VALUES
('OR-2026',2,'2026-09-24 11:16:52');
/*!40000 ALTER TABLE `official_receipt_sequences` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `payment_allocations`
--

DROP TABLE IF EXISTS `payment_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  CONSTRAINT `fk_allocations_billing_item` FOREIGN KEY (`billing_item_id`) REFERENCES `billing_items` (`billing_item_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_allocations_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_allocations`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `payment_allocations` WRITE;
/*!40000 ALTER TABLE `payment_allocations` DISABLE KEYS */;
INSERT INTO `payment_allocations` VALUES
(1,13,1,400.00,'2026-08-22 06:29:14'),
(2,13,2,650.00,'2026-08-22 06:29:14'),
(3,13,3,450.00,'2026-08-22 06:29:14'),
(4,14,3,50.00,'2026-08-22 07:11:04'),
(5,14,4,400.00,'2026-08-22 07:11:04'),
(6,14,5,400.00,'2026-08-22 07:11:04'),
(7,14,6,150.00,'2026-08-22 07:11:04'),
(16,17,25,500.00,'2026-08-24 10:54:53'),
(17,17,14,400.00,'2026-08-24 10:54:53'),
(18,17,15,650.00,'2026-08-24 10:54:53'),
(19,17,16,450.00,'2026-08-24 10:54:53'),
(20,18,24,250.00,'2026-08-26 11:13:31'),
(21,18,26,200.00,'2026-08-26 11:13:31'),
(22,18,29,1650.00,'2026-08-26 11:13:31'),
(23,19,29,450.00,'2026-08-26 11:31:44'),
(24,19,30,1650.00,'2026-08-26 11:31:44'),
(25,20,11,250.00,'2026-08-26 12:09:34'),
(26,20,12,500.00,'2026-08-26 12:09:34'),
(27,20,13,200.00,'2026-08-26 12:09:34'),
(28,21,30,450.00,'2026-08-26 12:46:08'),
(29,22,33,2500.00,'2026-08-26 13:17:23'),
(30,23,16,50.00,'2026-08-26 13:18:05'),
(31,23,17,400.00,'2026-08-26 13:18:05'),
(32,23,18,400.00,'2026-08-26 13:18:05'),
(33,23,19,1000.00,'2026-08-26 13:18:05'),
(34,23,20,150.00,'2026-08-26 13:18:05'),
(35,24,6,850.00,'2026-08-26 14:15:49'),
(36,24,7,150.00,'2026-08-26 14:15:49'),
(37,26,32,2500.00,'2026-08-27 15:59:57'),
(38,31,20,450.00,'2026-08-28 11:56:15'),
(39,31,21,600.00,'2026-08-28 11:56:15'),
(40,31,22,25.00,'2026-08-28 11:56:15'),
(41,31,23,400.00,'2026-08-28 11:56:15'),
(42,36,38,10.00,'2026-08-29 04:41:20'),
(43,37,27,2100.00,'2026-08-29 10:09:00'),
(48,42,31,250.00,'2026-08-29 10:36:49'),
(53,50,7,450.00,'2026-09-02 13:46:21'),
(54,50,8,600.00,'2026-09-02 13:46:21'),
(55,50,9,25.00,'2026-09-02 13:46:21'),
(56,50,10,400.00,'2026-09-02 13:46:21'),
(68,56,37,10.00,'2026-09-04 12:28:41'),
(69,57,35,1000.00,'2026-09-04 12:56:24'),
(70,58,36,10.00,'2026-09-04 12:57:39'),
(71,59,34,2100.00,'2026-09-06 11:56:33'),
(72,62,28,2100.00,'2026-09-10 13:15:05'),
(73,61,43,1.00,'2026-09-10 13:37:35'),
(74,60,42,1.00,'2026-09-10 13:38:09'),
(75,63,41,500.00,'2026-09-10 13:46:58'),
(76,64,39,500.00,'2026-09-10 13:51:46'),
(77,72,40,1.00,'2026-09-12 08:44:39'),
(78,73,62,1.00,'2026-09-12 10:58:33'),
(79,74,65,1.00,'2026-09-12 14:07:56'),
(80,75,63,1.00,'2026-09-12 14:17:25'),
(81,76,44,400.00,'2026-09-12 14:49:04'),
(82,76,45,650.00,'2026-09-12 14:49:04'),
(83,76,46,500.00,'2026-09-12 14:49:04'),
(84,76,47,400.00,'2026-09-12 14:49:04'),
(85,76,48,400.00,'2026-09-12 14:49:04'),
(86,76,49,1000.00,'2026-09-12 14:49:04'),
(87,76,50,600.00,'2026-09-12 14:49:04'),
(88,76,51,600.00,'2026-09-12 14:49:04'),
(89,76,52,25.00,'2026-09-12 14:49:04'),
(90,76,53,400.00,'2026-09-12 14:49:04'),
(91,76,54,250.00,'2026-09-12 14:49:04'),
(92,76,55,500.00,'2026-09-12 14:49:04'),
(93,76,56,200.00,'2026-09-12 14:49:04'),
(94,76,57,2100.00,'2026-09-12 14:49:04'),
(95,76,58,2500.00,'2026-09-12 14:49:04'),
(96,76,59,475.00,'2026-09-12 14:49:04'),
(97,77,66,1.00,'2026-09-16 10:57:16'),
(98,78,67,1.00,'2026-09-16 11:11:51'),
(99,80,70,100.00,'2026-09-16 19:29:45'),
(100,84,64,1.00,'2026-09-17 23:14:47'),
(101,85,71,1.01,'2026-09-17 23:18:06'),
(102,88,87,1.00,'2026-09-18 10:25:27'),
(103,91,69,1.00,'2026-09-18 16:01:17'),
(104,95,89,1.00,'2026-09-24 11:16:52'),
(105,95,90,1.00,'2026-09-24 11:16:52'),
(106,95,91,1.00,'2026-09-24 11:16:52'),
(107,95,92,1.00,'2026-09-24 11:16:52'),
(108,95,93,1.00,'2026-09-24 11:16:52');
/*!40000 ALTER TABLE `payment_allocations` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_uca1400_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`hf_gjsh2aqm50`@`%`*/ /*!50003 TRIGGER `after_payment_allocations_insert` AFTER INSERT ON `payment_allocations` FOR EACH ROW BEGIN
    
    
    UPDATE `billing_items`
    SET `paid_amount` = `paid_amount` + NEW.allocated_amount
    WHERE `billing_item_id` = NEW.billing_item_id;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `payment_audit_outbox`
--

DROP TABLE IF EXISTS `payment_audit_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_audit_outbox` (
  `audit_outbox_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `correlation_id` char(36) NOT NULL,
  `request_fingerprint` char(64) NOT NULL,
  `action` varchar(40) NOT NULL,
  `module_key` varchar(60) NOT NULL DEFAULT 'payment',
  `entity_type` varchar(60) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `detail` varchar(500) NOT NULL,
  `before_state` longtext DEFAULT NULL,
  `after_state` longtext DEFAULT NULL,
  `committed_result` longtext NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `actor_user_name` varchar(150) DEFAULT NULL,
  `actor_role_key` varchar(40) DEFAULT NULL,
  `actor_ip_address` varchar(45) DEFAULT NULL,
  `actor_user_agent` varchar(255) DEFAULT NULL,
  `delivery_status` enum('Pending','Processing','Delivered','Failed') NOT NULL DEFAULT 'Pending',
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `core_activity_log_id` bigint(20) unsigned DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`audit_outbox_id`),
  UNIQUE KEY `uq_payment_audit_outbox_correlation` (`correlation_id`),
  KEY `idx_payment_audit_outbox_fingerprint` (`request_fingerprint`),
  KEY `idx_payment_audit_outbox_delivery` (`delivery_status`,`next_attempt_at`),
  KEY `idx_payment_audit_outbox_entity` (`entity_type`,`entity_id`,`created_at`),
  CONSTRAINT `chk_payment_audit_outbox_correlation` CHECK (char_length(trim(`correlation_id`)) = 36),
  CONSTRAINT `chk_payment_audit_outbox_fingerprint` CHECK (char_length(`request_fingerprint`) = 64),
  CONSTRAINT `chk_payment_audit_outbox_before_json` CHECK (`before_state` is null or json_valid(`before_state`)),
  CONSTRAINT `chk_payment_audit_outbox_after_json` CHECK (`after_state` is null or json_valid(`after_state`)),
  CONSTRAINT `chk_payment_audit_outbox_result_json` CHECK (json_valid(`committed_result`)),
  CONSTRAINT `chk_payment_audit_outbox_delivery_state` CHECK (`delivery_status` = 'Delivered' and `delivered_at` is not null and `core_activity_log_id` is not null or `delivery_status` <> 'Delivered')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_audit_outbox`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `payment_audit_outbox` WRITE;
/*!40000 ALTER TABLE `payment_audit_outbox` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_audit_outbox` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `payment_concerns`
--

DROP TABLE IF EXISTS `payment_concerns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_concerns` (
  `concern_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `payment_id` int(10) unsigned DEFAULT NULL,
  `receipt_path` varchar(255) NOT NULL,
  `verification_status` enum('Pending','On Hold','Verified','Rejected') DEFAULT 'Pending',
  `ocr_status` enum('Processing','Completed','Failed') DEFAULT 'Processing',
  `remarks` text DEFAULT NULL,
  `hold_reason` text DEFAULT NULL,
  `held_by` int(10) unsigned DEFAULT NULL,
  `held_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`concern_id`),
  KEY `payment_id` (`payment_id`),
  KEY `idx_payment_concerns_student` (`student_id`),
  CONSTRAINT `fk_payment_concerns_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON UPDATE CASCADE,
  CONSTRAINT `payment_concerns_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_concerns`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `payment_concerns` WRITE;
/*!40000 ALTER TABLE `payment_concerns` DISABLE KEYS */;
INSERT INTO `payment_concerns` VALUES
(1,884,NULL,'uploads/receipts/7eefbe98fd3676412fb1d57c6dc63bc9.jpg','Pending','Processing','[wrong_amount] ',NULL,NULL,NULL,NULL,'2026-08-28 13:18:39',NULL),
(3,850,NULL,'uploads/receipts/a18f5060b412083c07e022bcef5e6ac1.jpg','Rejected','Processing','[missing_payment]',NULL,NULL,NULL,784,'2026-09-17 22:57:26','2026-09-17 23:01:47'),
(4,884,85,'uploads/receipts/3292a79fc64be6e18dbc69b312c15aee.jpg','Verified','Processing','[missing_payment]',NULL,NULL,NULL,784,'2026-09-17 23:16:03','2026-09-17 23:18:06');
/*!40000 ALTER TABLE `payment_concerns` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `payment_gateway_settings`
--

DROP TABLE IF EXISTS `payment_gateway_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_gateway_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_gateway_settings`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `payment_gateway_settings` WRITE;
/*!40000 ALTER TABLE `payment_gateway_settings` DISABLE KEYS */;
INSERT INTO `payment_gateway_settings` VALUES
('fee_policy','pass_to_student','pass_to_student or absorb_by_school','2026-09-12 14:38:38'),
('gateway_mode','live','Set to live or test mode','2026-09-24 01:21:18'),
('live_channel_card','0','1 to Enable, 0 to Disable Credit/Debit Cards (Live)','2026-08-21 03:51:32'),
('live_channel_gcash','0','1 to Enable, 0 to Disable GCash (Live)','2026-08-21 03:51:32'),
('live_channel_maya','0','1 to Enable, 0 to Disable Maya (Live)','2026-08-21 03:51:32'),
('live_channel_qrph','1','1 to Enable, 0 to Disable QR Ph (Live)','2026-08-21 03:57:32'),
('paymongo_public_key','','PayMongo Public Key (Stored in .env)','2026-08-12 12:49:45'),
('paymongo_secret_key','','PayMongo Secret Key (Stored in .env)','2026-08-12 12:49:45'),
('paymongo_webhook_secret','','Webhook Secret (Stored in .env)','2026-08-12 12:49:45'),
('test_channel_card','1','1 to Enable, 0 to Disable Credit/Debit Cards','2026-09-05 07:02:40'),
('test_channel_gcash','1','1 to Enable, 0 to Disable GCash','2026-08-22 04:39:18'),
('test_channel_maya','1','1 to Enable, 0 to Disable Maya','2026-08-21 03:51:32'),
('test_channel_qrph','1','1 to Enable, 0 to Disable QR Ph (Test)','2026-09-10 13:48:05'),
('webhook_secret_live','',NULL,'2026-08-21 06:05:13'),
('webhook_secret_test','whsk_o5adgJEYQ8HTZBYQftSVYay9',NULL,'2026-08-22 06:06:17');
/*!40000 ALTER TABLE `payment_gateway_settings` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `payment_notifications`
--

DROP TABLE IF EXISTS `payment_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_notifications` (
  `notification_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` int(10) unsigned NOT NULL,
  `event_key` varchar(120) NOT NULL,
  `title` varchar(150) NOT NULL,
  `body` varchar(500) NOT NULL,
  `target_url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`notification_id`),
  UNIQUE KEY `uq_payment_notification_event` (`recipient_user_id`,`event_key`),
  KEY `idx_payment_notification_recipient` (`recipient_user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_notifications`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `payment_notifications` WRITE;
/*!40000 ALTER TABLE `payment_notifications` DISABLE KEYS */;
INSERT INTO `payment_notifications` VALUES
(1,850,'payment-success:95','Payment Successful','Cashier walk-in payment of PHP 5.00 was successfully posted. Reference: OR-2026-000001.','/modules/student-portal/pages/statement-of-account.php','2026-09-24 11:16:52',NULL);
/*!40000 ALTER TABLE `payment_notifications` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `payment_settings_audit`
--

DROP TABLE IF EXISTS `payment_settings_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_settings_audit` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_settings_audit`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `payment_settings_audit` WRITE;
/*!40000 ALTER TABLE `payment_settings_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_settings_audit` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `category_id` int(11) DEFAULT NULL COMMENT 'ID of the designated fee category',
  `allocation_context` enum('ENROLLMENT_PRIORITY','SPECIFIC_ITEM') NOT NULL DEFAULT 'ENROLLMENT_PRIORITY',
  `billing_item_id` int(10) unsigned DEFAULT NULL,
  `checkout_session_id` varchar(255) DEFAULT NULL COMMENT 'PayMongo session ID',
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
  CONSTRAINT `fk_payments_billing_item` FOREIGN KEY (`billing_item_id`) REFERENCES `billing_items` (`billing_item_id`) ON DELETE SET NULL,
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`)
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES
(13,850,1,NULL,'Online','Online',1500.00,34.21,1534.21,2,'ENROLLMENT_PRIORITY',NULL,'cs_a8801b7a87c3b46b20d304a6',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787380122-7217','Verified','2026-08-22',NULL,NULL,'2026-08-22 06:29:14',NULL,'2026-08-22 06:28:43'),
(14,850,1,NULL,'Online','Online',1000.00,22.81,1022.81,2,'ENROLLMENT_PRIORITY',NULL,'cs_6862bbdfb3a3a850dfc354b1',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787382635-5135','Verified','2026-08-22',NULL,NULL,'2026-08-22 07:11:04',NULL,'2026-08-22 07:10:36'),
(17,884,3,784,'Walk-in','Walk-in',2000.00,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,2000.00,0.00,'Cash',NULL,'OR-20260824-2431','Verified','2026-08-24','','OR-20260824-2431','2026-08-24 10:54:53',NULL,'2026-08-24 10:54:53'),
(18,884,3,NULL,'Online','Online',2100.00,47.90,2147.90,5,'ENROLLMENT_PRIORITY',NULL,'cs_d5b79111b8663322c85e2043',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787742797-8197','Verified','2026-08-26',NULL,NULL,'2026-08-26 11:13:31',NULL,'2026-08-26 11:13:18'),
(19,884,3,784,'Walk-in','Walk-in',2100.00,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,2200.00,100.00,'Cash',NULL,'OR-20260826-5049','Verified','2026-08-26','','OR-20260826-5049','2026-08-26 11:31:44',NULL,'2026-08-26 11:31:44'),
(20,850,1,NULL,'Online','Online',950.00,21.67,971.67,5,'ENROLLMENT_PRIORITY',NULL,'cs_b34bfe73958fbe721d275021',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787746147-4817','Verified','2026-08-26',NULL,NULL,'2026-08-26 12:09:34',NULL,'2026-08-26 12:09:08'),
(21,884,3,NULL,'Online','Online',450.00,10.26,460.26,NULL,'SPECIFIC_ITEM',30,'cs_e8013d3c29e88c529ee9f34a',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787748358-9461','Verified','2026-08-26',NULL,NULL,'2026-08-26 12:46:08',NULL,'2026-08-26 12:45:58'),
(22,884,3,NULL,'Online','Online',2500.00,57.02,2557.02,NULL,'SPECIFIC_ITEM',33,'cs_c3bdb61dbe4845d381b42683',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787750224-3017','Verified','2026-08-26',NULL,NULL,'2026-08-26 13:17:23',NULL,'2026-08-26 13:17:05'),
(23,884,3,NULL,'Online','Online',2000.00,45.62,2045.62,NULL,'ENROLLMENT_PRIORITY',NULL,'cs_0a7a7e785cbb77de36b12132',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787750274-5598','Verified','2026-08-26',NULL,NULL,'2026-08-26 13:18:05',NULL,'2026-08-26 13:17:54'),
(24,850,1,NULL,'Online','Online',1000.00,0.00,1000.00,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,'pi_test_1787753749',NULL,NULL,NULL,'QRPh',NULL,'PM-TEST-1787753749','Verified','2026-08-26',NULL,NULL,'2026-08-26 14:15:49',NULL,'2026-08-26 14:15:49'),
(26,850,1,NULL,'Online','Online',2500.00,57.02,2557.02,NULL,'SPECIFIC_ITEM',32,'cs_e23c0b655152d53f478c530d',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787846235-4380','Verified','2026-08-27',NULL,NULL,'2026-08-27 15:59:57',NULL,'2026-08-27 15:57:16'),
(31,884,3,NULL,'Online','Online',1475.00,33.64,1508.64,NULL,'ENROLLMENT_PRIORITY',NULL,'cs_90db85d2948fe34dba458faa',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787917934-2146','Verified','2026-08-28',NULL,NULL,'2026-08-28 11:56:15',NULL,'2026-08-28 11:52:15'),
(36,884,3,NULL,'Online','Online',10.00,0.23,10.23,NULL,'SPECIFIC_ITEM',38,'cs_cde4b36d54b435032dddc139',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787978458-8882','Verified','2026-08-29',NULL,NULL,'2026-08-29 04:41:20',NULL,'2026-08-29 04:40:58'),
(37,850,1,NULL,'Online','Online',2100.00,47.90,2147.90,NULL,'SPECIFIC_ITEM',27,'cs_d8759420d532491738ebcb91',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787998130-7781','Verified','2026-08-29',NULL,NULL,'2026-08-29 10:09:00',NULL,'2026-08-29 10:08:51'),
(42,850,1,NULL,'Online','Online',250.00,5.70,255.70,NULL,'SPECIFIC_ITEM',31,'cs_d416d18ec2f0ed4dd58dcda9',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1787999799-1618','Verified','2026-08-29',NULL,NULL,'2026-08-29 10:36:49',NULL,'2026-08-29 10:36:40'),
(45,850,1,NULL,'Online','Online',1475.00,61.40,1536.40,NULL,'ENROLLMENT_PRIORITY',NULL,'cs_a3a48d55740458f694fcc8bc',NULL,NULL,NULL,NULL,'Visa',NULL,'PM-1788353225-4792','','2026-09-02',NULL,NULL,NULL,NULL,'2026-09-02 12:47:06'),
(46,850,1,NULL,'Online','Online',1475.00,20.03,1495.03,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,'pi_hAWeMk8oUtidZh6iFNxgEFb1',NULL,NULL,NULL,'QRPh',NULL,'PM-1788353245-2121','','2026-09-02',NULL,NULL,NULL,NULL,'2026-09-02 12:47:26'),
(47,850,1,NULL,'Online','Online',1475.00,33.64,1508.64,NULL,'ENROLLMENT_PRIORITY',NULL,'cs_0f34f6e3aaf15551f841f2b0',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1788353323-8552','','2026-09-02',NULL,NULL,NULL,NULL,'2026-09-02 12:48:43'),
(48,850,1,NULL,'Online','Online',1000.00,22.81,1022.81,NULL,'ENROLLMENT_PRIORITY',NULL,'cs_6858af4cca2018b3515a43c9',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1788353466-1311','','2026-09-02',NULL,NULL,NULL,NULL,'2026-09-02 12:51:06'),
(49,850,1,NULL,'Online','Online',1475.00,33.64,1508.64,NULL,'ENROLLMENT_PRIORITY',NULL,'cs_fe9e91c1469e08d9772cd7d8',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1788356244-3157','','2026-09-02',NULL,NULL,NULL,NULL,'2026-09-02 13:37:24'),
(50,850,1,NULL,'Online','Online',1475.00,33.64,1508.64,NULL,'ENROLLMENT_PRIORITY',NULL,'cs_63189fdc12823acffeb8008d',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1788356752-1211','Verified','2026-09-02',NULL,NULL,'2026-09-02 13:46:21',NULL,'2026-09-02 13:45:53'),
(56,850,1,784,'Walk-in','Walk-in',10.00,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,10.00,0.00,'Cash',NULL,'OR-20260904-1620','Verified','2026-09-04','','OR-20260904-1620','2026-09-04 12:28:41',NULL,'2026-09-04 12:28:41'),
(57,909,4,784,'Walk-in','Walk-in',1000.00,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,1500.00,500.00,'Cash',NULL,'OR-20260904-7348','Verified','2026-09-04','','OR-20260904-7348','2026-09-04 12:56:24',NULL,'2026-09-04 12:56:24'),
(58,909,4,NULL,'Online','Online',10.00,0.23,10.23,NULL,'SPECIFIC_ITEM',36,'cs_5dbed47b75eebe5065a94005',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1788526649-4169','Verified','2026-09-04',NULL,NULL,'2026-09-04 12:57:39',NULL,'2026-09-04 12:57:30'),
(59,909,4,NULL,'Online','Online',2100.00,47.90,2147.90,NULL,'SPECIFIC_ITEM',34,'cs_cded4f853a7fdd508ba04b85',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1788695613-6167','Verified','2026-09-06',NULL,NULL,'2026-09-06 11:56:33',NULL,'2026-09-06 11:53:34'),
(60,850,1,NULL,'Online','Online',1.00,0.02,1.02,NULL,'SPECIFIC_ITEM',42,'cs_55ebbad2dcd92b5291a21879',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1789042788-6336','Verified','2026-09-10',NULL,NULL,'2026-09-10 13:38:09',NULL,'2026-09-10 12:19:48'),
(61,850,1,NULL,'Online','Online',1.00,0.02,1.02,NULL,'SPECIFIC_ITEM',43,'cs_fa866f3f8250b57cc17ae4fb',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1789046064-2172','Verified','2026-09-10',NULL,NULL,'2026-09-10 13:37:35',NULL,'2026-09-10 13:14:26'),
(62,850,1,NULL,'Online','Online',2100.00,47.90,2147.90,NULL,'SPECIFIC_ITEM',28,'cs_2d80ebadb34d57647a7e33eb',NULL,NULL,NULL,NULL,'GCash',NULL,'PM-1789046086-8487','Verified','2026-09-10',NULL,NULL,'2026-09-10 13:15:05',NULL,'2026-09-10 13:14:47'),
(63,850,1,NULL,'Online','Online',500.00,9.11,509.11,NULL,'SPECIFIC_ITEM',41,'cs_a48b0dcc2c1d8f9f320cb561',NULL,NULL,NULL,NULL,'Maya',NULL,'PM-1789048007-3003','Verified','2026-09-10',NULL,NULL,'2026-09-10 13:46:58',NULL,'2026-09-10 13:46:48'),
(64,884,3,NULL,'Online','Online',500.00,29.95,529.95,NULL,'SPECIFIC_ITEM',39,'cs_651b95d97f3ca5621f6f895e',NULL,NULL,NULL,NULL,'Visa',NULL,'PM-1789048224-3718','Verified','2026-09-10',NULL,NULL,'2026-09-10 13:51:46',NULL,'2026-09-10 13:50:25'),
(65,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',40,NULL,'pi_jPVepUA2pg7BJ5bpPw8TvgdE',NULL,NULL,NULL,'QRPh',NULL,'PM-1789048361-7443','Pending','2026-09-10',' [QR Expired]',NULL,NULL,NULL,'2026-09-10 13:52:41'),
(66,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',40,NULL,'pi_FGmVxwtqTtQ9bndWCDmbzhnK',NULL,NULL,NULL,'QRPh',NULL,'PM-1789096877-7382','Pending','2026-09-11',' [QR Expired]',NULL,NULL,NULL,'2026-09-11 03:21:17'),
(67,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',40,NULL,'pi_gTBcK8RWCKcQ4ztZ85RRTtpY',NULL,NULL,NULL,'QRPh',NULL,'PM-1789106383-6859','Pending','2026-09-11',' [QR Expired]',NULL,NULL,NULL,'2026-09-11 05:59:43'),
(68,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',40,NULL,'pi_ztqqG8evP6ns3jNg8dF1kUH7',NULL,NULL,NULL,'QRPh',NULL,'PM-1789108700-3046','Pending','2026-09-11',' [QR Expired]',NULL,NULL,NULL,'2026-09-11 06:38:20'),
(69,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',40,NULL,'pi_fVPPPvXkhgr9ehjiLpi47eDp',NULL,NULL,NULL,'QRPh',NULL,'PM-1789110998-2680','Pending','2026-09-11',' [QR Expired]',NULL,NULL,NULL,'2026-09-11 07:16:38'),
(70,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',40,NULL,'pi_aHiud6jfpKxdHKMGnHmfT3xn',NULL,NULL,NULL,'QRPh',NULL,'PM-1789131803-9415','Pending','2026-09-11',' [QR Expired]',NULL,NULL,NULL,'2026-09-11 13:03:23'),
(71,884,3,NULL,'Online','Online',1.00,0.01,1.00,NULL,'SPECIFIC_ITEM',40,NULL,'pi_uCm7tkGzLbMs6d9f8mTHeUw5',NULL,NULL,NULL,'QRPh',NULL,'PM-1789138000-6001','Pending','2026-09-11',' [QR Expired] [QR Expired]',NULL,NULL,NULL,'2026-09-11 14:46:40'),
(72,884,3,NULL,'Online','Online',1.00,0.01,1.00,NULL,'SPECIFIC_ITEM',40,NULL,'pi_Y576tbQW6aZtMPxdnY7xxLKM',NULL,NULL,NULL,'QRPh',NULL,'PM-1789202605-1018','Verified','2026-09-12',NULL,NULL,'2026-09-12 08:44:39',NULL,'2026-09-12 08:43:25'),
(73,850,1,NULL,'Online','Online',1.00,0.01,1.00,NULL,'SPECIFIC_ITEM',62,NULL,'pi_t5Us9UdjA4aue4LXRKZaUYV9',NULL,NULL,NULL,'QRPh',NULL,'PM-1789210442-7133','Verified','2026-09-12',NULL,NULL,'2026-09-12 10:58:32',NULL,'2026-09-12 10:54:02'),
(74,909,4,NULL,'Online','Online',1.00,0.01,1.00,NULL,'SPECIFIC_ITEM',65,NULL,'pi_kMANcjBPkLbPVtT9Bj2ZuH6i','pm_Yd623zpKyhnpPCAsvXMtBB63',NULL,NULL,'QRPh','live','PM-1789221990-8093','Verified','2026-09-12',NULL,NULL,'2026-09-12 14:07:56','2026-09-12 22:16:30','2026-09-12 14:06:30'),
(75,884,3,NULL,'Online','Online',1.00,0.01,1.00,NULL,'SPECIFIC_ITEM',63,NULL,'pi_TWZtkdMZEmRn9gTyU4KoiKGt','pm_ZEGs4cwHrsxP46knWGXaxCBQ',NULL,NULL,'QRPh','live','PM-1789222578-2203','Verified','2026-09-12',NULL,NULL,'2026-09-12 14:17:25','2026-09-12 22:26:18','2026-09-12 14:16:18'),
(76,909,4,784,'Walk-in','Walk-in',11000.00,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,11000.00,0.00,'Cash',NULL,'OR-20260912-7471','Verified','2026-09-12','','OR-20260912-7471','2026-09-12 14:49:04',NULL,'2026-09-12 14:49:04'),
(77,850,1,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',66,NULL,'pi_1GqGzvH6vf9mN84sXRzKDReB','pm_GGQ36tjnCDjZHQUW9gJtment',NULL,NULL,'QRPh','live','PM-1789556023-9693','Verified','2026-09-16',NULL,NULL,'2026-09-16 10:57:16','2026-09-16 19:03:43','2026-09-16 10:53:43'),
(78,850,1,784,'Walk-in','Walk-in',1.00,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,1.00,0.00,'Cash',NULL,'OR-20260916-8895','Verified','2026-09-16','','OR-20260916-8895','2026-09-16 11:11:51',NULL,'2026-09-16 11:11:51'),
(79,850,1,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',68,NULL,'pi_cBNGT673AMtoc1JGhuVsi8QH','pm_LyxdmDq3g1xfCTaAhjJ9ufbJ',NULL,NULL,'QRPh','live','PM-1789557509-5622','Cancelled','2026-09-16',NULL,NULL,NULL,'2026-09-16 19:28:29','2026-09-16 11:18:29'),
(80,884,3,784,'Walk-in','Walk-in',100.00,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,100.00,0.00,'Cash',NULL,'OR-20260917-4359','Verified','2026-09-17','','OR-20260917-4359','2026-09-16 19:29:45',NULL,'2026-09-16 19:29:45'),
(81,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',64,NULL,'pi_R9xrt4q2njsNRMCGBimVkWeP','pm_CDMnR18qcYE5q7CNw8f6ZW8R',NULL,NULL,'QRPh','live','PM-1789587685-1670','Expired','2026-09-17',' [QR Expired]',NULL,NULL,'2026-09-17 03:51:25','2026-09-16 19:41:25'),
(82,850,1,NULL,'Online','Online',1.00,0.02,1.02,NULL,'SPECIFIC_ITEM',68,'cs_fc3f81818397fa44e5badd76',NULL,NULL,NULL,NULL,'GCash','test','PM-1789685479-9982','Verified','2026-09-18',NULL,NULL,'2026-09-17 22:52:49',NULL,'2026-09-17 22:51:21'),
(83,850,1,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',68,NULL,'pi_bgx5UTbWVgzqBGMntzVJB4XU','pm_kgSozJ53H1S6RswwUQk532EX',NULL,NULL,'QRPh','test','PM-1789686295-5615','Expired','2026-09-18',' [QR Expired]',NULL,NULL,'2026-09-18 07:14:55','2026-09-17 23:04:55'),
(84,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',64,NULL,'pi_sRCtb6Ji9VVrE6VP2kK75NJr','pm_wgFH4G3Jc6qkEcwBgfU5c55m',NULL,NULL,'QRPh','live','PM-1789686835-9710','Verified','2026-09-18',NULL,NULL,'2026-09-17 23:14:47','2026-09-18 07:23:55','2026-09-17 23:13:55'),
(85,884,3,784,'Payment Concern','Bank Transfer',1.01,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,NULL,NULL,'GCash',NULL,'997021335','Verified','2026-09-18',NULL,NULL,'2026-09-17 23:18:06',NULL,'2026-09-17 23:18:06'),
(86,884,3,NULL,'Online','Online',12.99,0.18,13.17,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,'pi_t2Y2C4N4UWFwfSz8gQDCGgzG','pm_KBAsHMCMisJyuh4p9CixF67F',NULL,NULL,'QRPh','live','PM-1789696331-1205','Cancelled','2026-09-18',NULL,NULL,NULL,'2026-09-18 10:02:11','2026-09-18 01:52:11'),
(87,884,3,NULL,'Online','Online',12.99,0.18,13.17,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,'pi_Mc61Dr29fWPQazD7hG3LXsW6','pm_t4JzdwCXTqpigypiE5P5yzZJ',NULL,NULL,'QRPh','live','PM-1789696675-2644','Expired','2026-09-18',' [QR Expired]',NULL,NULL,'2026-09-18 10:07:55','2026-09-18 01:57:55'),
(88,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',87,NULL,'pi_ava8fYHS6GeqB6rNLdafjajS','pm_N4B5sAVNyp6JHLUKW5dVGW3h',NULL,NULL,'QRPh','live','PM-1789727040-2877','Verified','2026-09-18',NULL,NULL,'2026-09-18 10:25:27','2026-09-18 18:34:00','2026-09-18 10:24:00'),
(89,850,1,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',69,NULL,'pi_dYo6FFtoTyRfSso2AW12sbfz','pm_fqccADG8J5nC846VumvJxo6p',NULL,NULL,'QRPh','live','PM-1789734219-6250','Expired','2026-09-18',' [QR Expired]',NULL,NULL,'2026-09-18 20:33:39','2026-09-18 12:23:39'),
(90,884,3,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',88,NULL,'pi_5ZBD7RANbqdKHm6MistmwTd3','pm_GT22qaz6tccoyzKvWbMNJuvK',NULL,NULL,'QRPh','live','PM-1789734499-3954','Expired','2026-09-18',' [QR Expired]',NULL,NULL,'2026-09-18 20:38:19','2026-09-18 12:28:19'),
(91,850,1,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',69,NULL,'pi_KyPTbKukLEoZDfTGGYt16KUt','pm_aACdWA9biqzAVe1zudPDFeoh',NULL,NULL,'QRPh','live','PM-1789747197-4365','Verified','2026-09-18',NULL,NULL,'2026-09-18 16:01:17','2026-09-19 00:09:57','2026-09-18 15:59:57'),
(92,850,1,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',68,NULL,'pi_HHtR9h4MFGpEJaMUsbTuLgAY','pm_ZDCvEwu11RhBdQXdqeKBwNvQ',NULL,NULL,'QRPh','live','PM-1789772139-8417','Cancelled','2026-09-19',NULL,NULL,NULL,'2026-09-19 07:05:39','2026-09-18 22:55:39'),
(93,850,1,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',93,NULL,'pi_q9r3vJF1bMpy8G3cCpoyCiPL','pm_VF8VywbyrgxoN6mt8NM2eSDN',NULL,NULL,'QRPh','live','PM-1789781606-6709','Cancelled','2026-09-19',NULL,NULL,NULL,'2026-09-19 09:43:26','2026-09-19 01:33:26'),
(94,850,1,NULL,'Online','Online',1.00,0.01,1.01,NULL,'SPECIFIC_ITEM',93,NULL,'pi_AkNB8oDmVU6QL2Kiv7iNC8Ac','pm_2LbVwcPoAzTgTAoLL9cuBSZn',NULL,NULL,'QRPh','live','PM-1789804774-7711','Cancelled','2026-09-19',NULL,NULL,NULL,'2026-09-19 16:09:34','2026-09-19 07:59:34'),
(95,850,1,784,'Walk-in','Walk-in',5.00,NULL,NULL,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,NULL,NULL,5.00,0.00,'Cash',NULL,'OR-2026-000001','Verified','2026-09-24','','OR-2026-000001','2026-09-24 11:16:52',NULL,'2026-09-24 11:16:52'),
(96,850,1,NULL,'Online','Online',118.00,1.60,119.60,NULL,'ENROLLMENT_PRIORITY',NULL,NULL,'pi_y2WGxVuxrEAAAHobwc6aUvUX','pm_hG2Th7v3GYAW1BJVTygyLggw',NULL,NULL,'QRPh','live','PM-1790259011-8992','Cancelled','2026-09-24',NULL,NULL,NULL,'2026-09-24 22:20:11','2026-09-24 14:10:11');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `paymongo_transactions`
--

DROP TABLE IF EXISTS `paymongo_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `paymongo_transactions` (
  `paymongo_transaction_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` int(10) unsigned DEFAULT NULL,
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
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`paymongo_transaction_id`),
  UNIQUE KEY `checkout_session_id` (`checkout_session_id`),
  UNIQUE KEY `payment_intent_id` (`payment_intent_id`),
  UNIQUE KEY `paymongo_payment_id` (`paymongo_payment_id`),
  UNIQUE KEY `webhook_event_id` (`webhook_event_id`),
  KEY `idx_paymongo_payment` (`payment_id`),
  KEY `idx_paymongo_status` (`processing_status`),
  CONSTRAINT `fk_paymongo_transaction_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `paymongo_transactions`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `paymongo_transactions` WRITE;
/*!40000 ALTER TABLE `paymongo_transactions` DISABLE KEYS */;
INSERT INTO `paymongo_transactions` VALUES
(1,62,'cs_2d80ebadb34d57647a7e33eb',NULL,NULL,'evt_Qk5gkMfqjFk7htpdWXJv8xLm','checkout_session.payment.paid',2100.00,47.90,2147.90,1,'Processed','2026-09-10 13:15:05','2026-09-10 13:15:05'),
(2,61,'cs_fa866f3f8250b57cc17ae4fb',NULL,NULL,'evt_gzhH8KJ2c4d5BzJHXnwNE95H','checkout_session.payment.paid',1.00,0.02,1.02,1,'Processed','2026-09-10 13:37:35','2026-09-10 13:37:35'),
(3,60,'cs_55ebbad2dcd92b5291a21879',NULL,NULL,'evt_Thq5iZqWnc13rvkRGaQJUbo2','checkout_session.payment.paid',1.00,0.02,1.02,1,'Processed','2026-09-10 13:38:09','2026-09-10 13:38:09'),
(4,63,'cs_a48b0dcc2c1d8f9f320cb561',NULL,NULL,'evt_PgWnq3UWBRg73DKE57qgakEh','checkout_session.payment.paid',500.00,9.11,509.11,1,'Processed','2026-09-10 13:46:58','2026-09-10 13:46:58'),
(5,64,'cs_651b95d97f3ca5621f6f895e',NULL,NULL,'evt_MCtby6DWcg2KkP8z4p6s5XAD','checkout_session.payment.paid',500.00,29.95,529.95,1,'Processed','2026-09-10 13:51:46','2026-09-10 13:51:46'),
(6,72,NULL,'pi_Y576tbQW6aZtMPxdnY7xxLKM',NULL,'evt_N1xiACn1i9QGhEdCyhmsy2Mj','payment.paid',1.00,0.01,1.00,1,'Processed','2026-09-12 08:44:39','2026-09-12 08:44:40'),
(7,73,NULL,'pi_t5Us9UdjA4aue4LXRKZaUYV9',NULL,'evt_NaqgWAodgbr3szD8SG8HJFR1','payment.paid',1.00,0.01,1.00,1,'Processed','2026-09-12 10:58:32','2026-09-12 10:58:33'),
(8,74,NULL,'pi_kMANcjBPkLbPVtT9Bj2ZuH6i',NULL,'evt_6EHuKVTZ3fYNXAMQsUzH8tjd','payment.paid',1.00,0.01,1.00,1,'Processed','2026-09-12 14:07:56','2026-09-12 14:07:56'),
(9,75,NULL,'pi_TWZtkdMZEmRn9gTyU4KoiKGt',NULL,'evt_7KZUU1tKjxdJctF9QU37XjzS','payment.paid',1.00,0.01,1.00,1,'Processed','2026-09-12 14:17:25','2026-09-12 14:17:25'),
(10,77,NULL,'pi_1GqGzvH6vf9mN84sXRzKDReB',NULL,'evt_nK9Svd1JpE8Eq8UExErjpeDU','payment.paid',1.00,0.01,1.01,1,'Processed','2026-09-16 10:57:16','2026-09-16 10:57:16'),
(11,82,'cs_fc3f81818397fa44e5badd76',NULL,NULL,'evt_eq6jMPdem2EVR7rYu7dAsXsM','checkout_session.payment.paid',1.00,0.02,1.02,1,'Processed','2026-09-17 22:52:49','2026-09-17 22:52:49'),
(12,84,NULL,'pi_sRCtb6Ji9VVrE6VP2kK75NJr',NULL,'evt_mqAd7m4UrVraSywBMf4isuQo','payment.paid',1.00,0.01,1.01,1,'Processed','2026-09-17 23:14:47','2026-09-17 23:14:47'),
(13,88,NULL,'pi_ava8fYHS6GeqB6rNLdafjajS',NULL,'evt_VP3XfCfCQJhfZxTisqKr4tT3','payment.paid',1.00,0.01,1.01,1,'Processed','2026-09-18 10:25:27','2026-09-18 10:25:28'),
(14,91,NULL,'pi_KyPTbKukLEoZDfTGGYt16KUt',NULL,'evt_nHp9MracEe8aB3CkSAqULaA5','payment.paid',1.00,0.01,1.01,1,'Processed','2026-09-18 16:01:17','2026-09-18 16:01:18');
/*!40000 ALTER TABLE `paymongo_transactions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `scholarships`
--

DROP TABLE IF EXISTS `scholarships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scholarships` (
  `scholarship_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `billing_id` int(10) unsigned DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `scholarship_name` varchar(100) NOT NULL,
  `discount_type` enum('Percentage','Fixed Amount') NOT NULL,
  `discount_percentage` decimal(5,2) DEFAULT NULL,
  `status` enum('Active','Revoked','Expired') DEFAULT 'Active',
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`scholarship_id`),
  KEY `student_id` (`student_id`),
  KEY `billing_id` (`billing_id`),
  CONSTRAINT `fk_scholarships_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `scholarships_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scholarships`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scholarships` WRITE;
/*!40000 ALTER TABLE `scholarships` DISABLE KEYS */;
INSERT INTO `scholarships` VALUES
(1,9,1,4,2962.50,'Academic Excellence','Percentage',50.00,'Active','2026-08-16 05:25:57');
/*!40000 ALTER TABLE `scholarships` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `school_sale_book_details`
--

DROP TABLE IF EXISTS `school_sale_book_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_sale_book_details` (
  `sale_book_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_item_id` int(10) unsigned NOT NULL,
  `book_title` varchar(255) NOT NULL,
  `author` varchar(255) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `edition` varchar(100) DEFAULT NULL,
  `isbn` varchar(32) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sale_book_detail_id`),
  UNIQUE KEY `uq_school_sale_book_item` (`sale_item_id`),
  KEY `idx_school_sale_book_isbn` (`isbn`),
  CONSTRAINT `fk_school_sale_book_item` FOREIGN KEY (`sale_item_id`) REFERENCES `school_sale_items` (`sale_item_id`),
  CONSTRAINT `chk_school_sale_book_title_nonblank` CHECK (char_length(trim(`book_title`)) > 0),
  CONSTRAINT `chk_school_sale_book_author_nonblank` CHECK (`author` is null or char_length(trim(`author`)) > 0),
  CONSTRAINT `chk_school_sale_book_publisher_nonblank` CHECK (`publisher` is null or char_length(trim(`publisher`)) > 0),
  CONSTRAINT `chk_school_sale_book_edition_nonblank` CHECK (`edition` is null or char_length(trim(`edition`)) > 0),
  CONSTRAINT `chk_school_sale_book_isbn_format` CHECK (`isbn` is null or char_length(trim(`isbn`)) between 10 and 32 and `isbn` regexp '^[0-9Xx -]+$'),
  CONSTRAINT `chk_school_sale_book_notes_nonblank` CHECK (`notes` is null or char_length(trim(`notes`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_sale_book_details`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `school_sale_book_details` WRITE;
/*!40000 ALTER TABLE `school_sale_book_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_sale_book_details` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `school_sale_categories`
--

DROP TABLE IF EXISTS `school_sale_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_sale_categories` (
  `sale_category_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_code` varchar(60) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('Draft','Active','Inactive','Archived') NOT NULL DEFAULT 'Draft',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sale_category_id`),
  UNIQUE KEY `uq_school_sale_category_code` (`category_code`),
  KEY `idx_school_sale_category_status_sort` (`status`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_sale_categories`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `school_sale_categories` WRITE;
/*!40000 ALTER TABLE `school_sale_categories` DISABLE KEYS */;
INSERT INTO `school_sale_categories` VALUES
(1,'UNIFORM','Uniform',10,'Active',NULL,NULL,'2026-09-22 05:06:49','2026-10-01 12:02:59'),
(2,'BOOKS','Books',20,'Active',NULL,NULL,'2026-09-22 05:06:49','2026-10-01 12:02:59'),
(3,'GRADUATION','Graduation',30,'Active',NULL,NULL,'2026-09-22 05:06:49','2026-10-01 12:02:59'),
(4,'MISCELLANEOUS','Miscellaneous',40,'Active',NULL,NULL,'2026-09-22 05:06:49','2026-10-01 12:02:59');
/*!40000 ALTER TABLE `school_sale_categories` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `school_sale_item_applicability`
--

DROP TABLE IF EXISTS `school_sale_item_applicability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_sale_item_applicability` (
  `sale_item_applicability_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_item_id` int(10) unsigned NOT NULL,
  `program_code` varchar(60) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `deactivated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deactivated_at` datetime DEFAULT NULL,
  `active_program_scope` varchar(60) GENERATED ALWAYS AS (case when `status` = 'Active' then coalesce(nullif(trim(`program_code`),''),'__ANY__') else NULL end) STORED,
  `active_year_scope` varchar(20) GENERATED ALWAYS AS (case when `status` = 'Active' then coalesce(nullif(trim(`year_level`),''),'__ANY__') else NULL end) STORED,
  PRIMARY KEY (`sale_item_applicability_id`),
  UNIQUE KEY `uq_school_sale_item_active_scope` (`sale_item_id`,`active_program_scope`,`active_year_scope`),
  KEY `idx_school_sale_applicability_item_status` (`sale_item_id`,`status`),
  KEY `idx_school_sale_applicability_student_scope` (`program_code`,`year_level`,`status`),
  CONSTRAINT `fk_school_sale_applicability_item` FOREIGN KEY (`sale_item_id`) REFERENCES `school_sale_items` (`sale_item_id`),
  CONSTRAINT `chk_school_sale_applicability_scope` CHECK (nullif(trim(`program_code`),'') is not null or nullif(trim(`year_level`),'') is not null),
  CONSTRAINT `chk_school_sale_applicability_deactivation` CHECK (`status` = 'Active' and `deactivated_at` is null and `deactivated_by` is null or `status` = 'Inactive')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_sale_item_applicability`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `school_sale_item_applicability` WRITE;
/*!40000 ALTER TABLE `school_sale_item_applicability` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_sale_item_applicability` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `school_sale_item_types`
--

DROP TABLE IF EXISTS `school_sale_item_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_sale_item_types` (
  `sale_item_type_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type_code` varchar(60) NOT NULL,
  `type_name` varchar(120) NOT NULL,
  `metadata_profile` varchar(60) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sale_item_type_id`),
  UNIQUE KEY `uq_school_sale_item_type_code` (`type_code`),
  UNIQUE KEY `uq_school_sale_item_type_name` (`type_name`),
  KEY `idx_school_sale_item_type_status_sort` (`status`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_sale_item_types`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `school_sale_item_types` WRITE;
/*!40000 ALTER TABLE `school_sale_item_types` DISABLE KEYS */;
INSERT INTO `school_sale_item_types` VALUES
(1,'UNIFORM','Uniform','UNIFORM_VARIANT','Active',10,NULL,NULL,'2026-10-02 00:34:07','2026-10-02 00:34:07'),
(2,'BOOK','Book','BOOK_REQUIRED','Active',20,NULL,NULL,'2026-10-02 00:34:07','2026-10-02 00:34:07'),
(3,'LEARNING_MATERIAL','Learning Material','STANDARD_PRODUCT','Active',30,NULL,NULL,'2026-10-02 00:34:07','2026-10-02 00:34:07'),
(4,'ACCESSORY','Accessory','STANDARD_PRODUCT','Active',40,NULL,NULL,'2026-10-02 00:34:07','2026-10-02 00:34:07'),
(5,'GRADUATION_ITEM','Graduation Item','STANDARD_PRODUCT','Active',50,NULL,NULL,'2026-10-02 00:34:07','2026-10-02 00:34:07'),
(6,'OTHER_MERCHANDISE','Other Merchandise','STANDARD_PRODUCT','Active',60,NULL,NULL,'2026-10-02 00:34:07','2026-10-02 00:34:07');
/*!40000 ALTER TABLE `school_sale_item_types` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `school_sale_item_variants`
--

DROP TABLE IF EXISTS `school_sale_item_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_sale_item_variants` (
  `sale_variant_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sale_item_id` int(10) unsigned NOT NULL,
  `variant_code` varchar(60) NOT NULL,
  `variant_name` varchar(120) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `size_label` varchar(60) DEFAULT NULL,
  `variant_metadata` longtext DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `status` enum('Draft','Active','Inactive','Archived') NOT NULL DEFAULT 'Draft',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sale_variant_id`),
  UNIQUE KEY `uq_school_sale_variant_code` (`sale_item_id`,`variant_code`),
  UNIQUE KEY `uq_school_sale_variant_sku` (`sku`),
  KEY `idx_school_sale_variant_item_status_sort` (`sale_item_id`,`status`,`sort_order`),
  CONSTRAINT `fk_school_sale_variant_item` FOREIGN KEY (`sale_item_id`) REFERENCES `school_sale_items` (`sale_item_id`),
  CONSTRAINT `chk_school_sale_variant_code_nonblank` CHECK (char_length(trim(`variant_code`)) > 0),
  CONSTRAINT `chk_school_sale_variant_name_nonblank` CHECK (char_length(trim(`variant_name`)) > 0),
  CONSTRAINT `chk_school_sale_variant_metadata_json` CHECK (`variant_metadata` is null or json_valid(`variant_metadata`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_sale_item_variants`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `school_sale_item_variants` WRITE;
/*!40000 ALTER TABLE `school_sale_item_variants` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_sale_item_variants` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `school_sale_items`
--

DROP TABLE IF EXISTS `school_sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_sale_items` (
  `sale_item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_code` varchar(60) NOT NULL,
  `sale_category_id` int(10) unsigned NOT NULL,
  `sale_item_type_id` int(10) unsigned NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `applicability_mode` enum('ALL','RESTRICTED') NOT NULL DEFAULT 'ALL',
  `status` enum('Draft','Active','Inactive','Archived') NOT NULL DEFAULT 'Draft',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sale_item_id`),
  UNIQUE KEY `uq_school_sale_item_code` (`item_code`),
  KEY `idx_school_sale_item_category_status` (`sale_category_id`,`status`),
  KEY `idx_school_sale_item_type_status` (`sale_item_type_id`,`status`),
  KEY `idx_school_sale_item_name` (`item_name`),
  CONSTRAINT `fk_school_sale_item_category` FOREIGN KEY (`sale_category_id`) REFERENCES `school_sale_categories` (`sale_category_id`),
  CONSTRAINT `fk_school_sale_item_type` FOREIGN KEY (`sale_item_type_id`) REFERENCES `school_sale_item_types` (`sale_item_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_sale_items`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `school_sale_items` WRITE;
/*!40000 ALTER TABLE `school_sale_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_sale_items` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `school_sale_variant_prices`
--

DROP TABLE IF EXISTS `school_sale_variant_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_sale_variant_prices` (
  `sale_variant_price_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_variant_id` int(10) unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'PHP',
  `effective_from` datetime NOT NULL,
  `effective_to` datetime DEFAULT NULL,
  `status` enum('Draft','Active','Retired','Cancelled') NOT NULL DEFAULT 'Draft',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `activated_by` int(10) unsigned DEFAULT NULL,
  `retired_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `activated_at` datetime DEFAULT NULL,
  `retired_at` datetime DEFAULT NULL,
  PRIMARY KEY (`sale_variant_price_id`),
  UNIQUE KEY `uq_school_sale_variant_price_start` (`sale_variant_id`,`effective_from`),
  KEY `idx_school_sale_variant_price_current` (`sale_variant_id`,`status`,`effective_from`,`effective_to`),
  CONSTRAINT `fk_school_sale_variant_price_variant` FOREIGN KEY (`sale_variant_id`) REFERENCES `school_sale_item_variants` (`sale_variant_id`),
  CONSTRAINT `chk_school_sale_variant_price_amount` CHECK (`amount` > 0),
  CONSTRAINT `chk_school_sale_variant_price_currency` CHECK (char_length(trim(`currency`)) = 3),
  CONSTRAINT `chk_school_sale_variant_price_window` CHECK (`effective_to` is null or `effective_to` > `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_sale_variant_prices`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `school_sale_variant_prices` WRITE;
/*!40000 ALTER TABLE `school_sale_variant_prices` DISABLE KEYS */;
/*!40000 ALTER TABLE `school_sale_variant_prices` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=910 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES
(9,9,'S230000001','Student User','Unknown','1',NULL,NULL,'','2026-08-18 09:57:26','2026-08-18 09:57:26'),
(850,850,'S230115569','Lebron James','Unknown','1',NULL,NULL,'Enrolled','2026-08-20 02:59:11','2026-09-26 04:59:19'),
(884,884,'s230115570','Kevin Durant','Unknown','1',NULL,NULL,'Enrolled','2026-08-24 10:35:37','2026-09-18 18:11:17'),
(909,909,'S230115571','Justine Bonifacio','Unknown','1',NULL,NULL,'Enrolled','2026-08-28 14:26:41','2026-09-16 11:32:04');
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-10-03  9:02:55
