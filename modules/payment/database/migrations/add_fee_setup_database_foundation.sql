-- Fee Setup database foundation (Phase 1 only).
-- Additive schema and approved taxonomy seeds only.
-- This migration intentionally creates no operational fees, fee versions,
-- applicability rows, billing records, payment records, or campaign records.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `fees`
  ADD COLUMN `fee_code` varchar(60) DEFAULT NULL AFTER `fee_id`,
  ADD COLUMN `fee_type_id` int(10) unsigned DEFAULT NULL AFTER `category_id`,
  ADD COLUMN `identity_status` enum('Active','Archived') DEFAULT NULL AFTER `status`,
  ADD UNIQUE KEY `uq_fees_fee_code` (`fee_code`),
  ADD KEY `idx_fees_fee_type` (`fee_type_id`),
  ADD CONSTRAINT `fk_fees_fee_type` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`fee_type_id`);

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
  `active_fee_id` int(10) unsigned GENERATED ALWAYS AS (
    CASE WHEN `effective_status` = 'Active' THEN `fee_id` ELSE NULL END
  ) PERSISTENT,
  PRIMARY KEY (`fee_version_id`),
  UNIQUE KEY `uq_fee_versions_number` (`fee_id`,`version_no`),
  UNIQUE KEY `uq_fee_versions_active_scope` (`active_fee_id`,`academic_year`,`semester`),
  KEY `idx_fee_versions_scope` (`fee_id`,`academic_year`,`semester`,`effective_status`),
  CONSTRAINT `fk_fee_versions_fee` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`fee_id`),
  CONSTRAINT `chk_fee_versions_amount` CHECK (`amount` >= 0),
  CONSTRAINT `chk_fee_versions_required` CHECK (`is_required` IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fee_applicability` (
  `fee_applicability_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fee_version_id` bigint(20) unsigned NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `applies_to_all_courses` tinyint(1) NOT NULL,
  `applies_to_all_year_levels` tinyint(1) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `normalized_course_scope` varchar(100) GENERATED ALWAYS AS (
    CASE WHEN `applies_to_all_courses` = 1 THEN '__ALL__' ELSE trim(`course`) END
  ) PERSISTENT,
  `normalized_year_scope` varchar(20) GENERATED ALWAYS AS (
    CASE WHEN `applies_to_all_year_levels` = 1 THEN '__ALL__' ELSE trim(`year_level`) END
  ) PERSISTENT,
  PRIMARY KEY (`fee_applicability_id`),
  UNIQUE KEY `uq_fee_applicability_scope` (`fee_version_id`,`normalized_course_scope`,`normalized_year_scope`),
  KEY `idx_fee_applicability_version` (`fee_version_id`),
  CONSTRAINT `fk_fee_applicability_version` FOREIGN KEY (`fee_version_id`) REFERENCES `fee_versions` (`fee_version_id`),
  CONSTRAINT `chk_fee_applicability_course_flag` CHECK (`applies_to_all_courses` IN (0,1)),
  CONSTRAINT `chk_fee_applicability_year_flag` CHECK (`applies_to_all_year_levels` IN (0,1)),
  CONSTRAINT `chk_fee_applicability_course_scope` CHECK (
    (`applies_to_all_courses` = 1 AND `course` IS NULL)
    OR
    (`applies_to_all_courses` = 0 AND `course` IS NOT NULL AND char_length(trim(`course`)) > 0)
  ),
  CONSTRAINT `chk_fee_applicability_year_scope` CHECK (
    (`applies_to_all_year_levels` = 1 AND `year_level` IS NULL)
    OR
    (`applies_to_all_year_levels` = 0 AND `year_level` IS NOT NULL AND char_length(trim(`year_level`)) > 0)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `fee_groups` (`group_code`,`group_name`,`status`,`sort_order`) VALUES
  ('ACADEMIC','Academic Fees','Active',10),
  ('NON_ACADEMIC','Non-Academic Fees','Active',20);

INSERT INTO `fee_types` (`fee_group_id`,`type_code`,`type_name`,`status`,`sort_order`)
SELECT `fee_group_id`,'TUITION','Tuition Fees','Active',10 FROM `fee_groups` WHERE `group_code`='ACADEMIC'
UNION ALL
SELECT `fee_group_id`,'LABORATORY','Laboratory Fees','Active',20 FROM `fee_groups` WHERE `group_code`='ACADEMIC'
UNION ALL
SELECT `fee_group_id`,'SUPPLEMENTARY','Supplementary Fees','Active',30 FROM `fee_groups` WHERE `group_code`='ACADEMIC'
UNION ALL
SELECT `fee_group_id`,'CURRICULUM_INSTRUCTIONAL','Curriculum and Instructional Systems Fees','Active',40 FROM `fee_groups` WHERE `group_code`='ACADEMIC'
UNION ALL
SELECT `fee_group_id`,'CULINARY_HOSPITALITY','Culinary & Hospitality Workshop Fee','Active',50 FROM `fee_groups` WHERE `group_code`='ACADEMIC'
UNION ALL
SELECT `fee_group_id`,'UNIFORM_GEAR','Uniform and Gear Fees','Active',10 FROM `fee_groups` WHERE `group_code`='NON_ACADEMIC'
UNION ALL
SELECT `fee_group_id`,'GRADUATION_REGALIA','Graduation Regalia Fees','Active',20 FROM `fee_groups` WHERE `group_code`='NON_ACADEMIC'
UNION ALL
SELECT `fee_group_id`,'MISCELLANEOUS','Miscellaneous Fees','Active',30 FROM `fee_groups` WHERE `group_code`='NON_ACADEMIC';
